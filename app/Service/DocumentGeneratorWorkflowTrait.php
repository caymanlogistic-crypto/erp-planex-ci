<?php

namespace App\Service;

use PDO;
use RuntimeException;
use Throwable;

trait DocumentGeneratorWorkflowTrait
{
    public static function prepare(
        PDO $localPdo,
        array $company,
        int $companyId,
        int $userId,
        string $role,
        int $templateId,
        int $crewId,
        string $requestInput,
        array $params,
        ?GoogleSheetsRequestContactService $contactService = null,
        array $context = []
    ): array {
        if (!self::runtimeSchemaReady($localPdo)) {
            return self::failed(['Схема генератора договоров не обновлена. Требуется штатная tenant migration 129.']);
        }
        $template = self::getTemplateVersion($localPdo,$templateId,null,true);
        if (!$template) return self::failed(['Выбранный шаблон не найден, неактивен или не имеет активной версии.']);
        $executor = self::loadAccessibleExecutor($localPdo,$crewId,$userId,$role);
        if (!$executor) return self::failed(['Исполнитель рейса не найден или недоступен текущему пользователю.']);
        $driverCount = (int)($executor['driver_count'] ?? 0);
        if ($driverCount !== 1) {
            return self::failed(['Для формирования текущих документов исполнитель рейса должен содержать ровно одного водителя. Выбранный исполнитель содержит: ' . $driverCount . '.']);
        }

        $parser = new FeoRequestInteractionService();
        try { $ids = $parser->extractRequestIds($requestInput); }
        catch (Throwable $e) { return self::failed([$e->getMessage()]); }
        if ($ids === []) return self::failed(['Укажите хотя бы один шестизначный номер заявки ФЭО.']);

        $requestRows = self::loadFeoRequests($localPdo,$ids);
        $present = [];
        foreach ($requestRows as $row) $present[(string)$row['zayavka_id']] = $row;
        $missing = array_values(array_filter($ids, static fn(string $id): bool => !isset($present[$id])));
        $ordered = [];
        foreach ($ids as $id) if (isset($present[$id])) $ordered[] = $present[$id];

        $contactService ??= new GoogleSheetsRequestContactService();
        $googleContacts = $contactService->loadContacts($ids);
        foreach ($ordered as &$row) {
            $requestId = (string)($row['zayavka_id'] ?? '');
            $row['contacts'] = trim((string)($googleContacts['contacts'][$requestId] ?? '—')) ?: '—';
        }
        unset($row);
        if (is_array($context['warehouse_loading'] ?? null)) {
            $ordered = DocumentGeneratorWarehouseLoadingService::apply($ordered, $context['warehouse_loading']);
        }
        if (is_string($context['warehouse_unloading'] ?? null)) {
            $ordered = DocumentGeneratorWarehouseUnloadingService::apply($ordered, $context['warehouse_unloading']);
        }
        $warnings=[];
        if (trim((string)($googleContacts['warning'] ?? ''))!=='') $warnings[]=(string)$googleContacts['warning'];
        $ignored = (array)($context['warehouse_loading']['ignored_request_ids'] ?? []);
        if ($ignored !== []) {
            $warnings[] = 'В ERP у рейса есть заявки, отсутствующие в текущем составе исходного рейса: '
                .implode(', ', $ignored).'. В договор включён актуальный состав исходного рейса.';
        }

        $placeholders = json_decode((string)($template['placeholders_json'] ?? '[]'), true);
        if (!is_array($placeholders)) $placeholders = [];
        $groups = self::unloadGroups($ordered);
        $totalKg = 0.0;
        foreach ($ordered as $row) $totalKg += ((float)($row['mass_netto'] ?? 0)) * 1000;
        $totalKg = round($totalKg,3);
        $unload = count($groups)===1 ? (string)array_key_first($groups) : '';
        $snapshot = self::buildSnapshot($company,$executor,$ordered,$params,$template);
        foreach ($context as $key=>$value) {
            if (!is_string($key) || $key==='') continue;
            $snapshot[$key]=$value;
        }
        if (in_array((string)$template['document_type'], ['vehicle_lease_crew','carrier_sublease_crew'], true)) {
            $tripNumber = (int)($snapshot['trip']['business_id'] ?? 0);
            if ($tripNumber > 0) {
                $number = 'П-'.$tripNumber;
                $snapshot['document']['number'] = $number;
                $snapshot['parameters']['contract_number'] = $number;
            }
        }
        $snapshot['integrations']['google_sheets_contacts'] = [
            'available'=>(bool)($googleContacts['available'] ?? false),
            'warning'=>(string)($googleContacts['warning'] ?? ''),
            'source'=>is_array($googleContacts['source'] ?? null)?$googleContacts['source']:[],
        ];
        $snapshot['requests_summary'] = [
            'count'=>count($ordered),'total_mass_kg'=>$totalKg,'unload_address'=>$unload,
            'unload_groups'=>$groups,'missing_ids'=>$missing,'input_ids'=>$ids,
        ];
        $values = self::buildFixedValues($snapshot);
        $errors = [];
        $contractExists = false;
        if (in_array((string)$template['document_type'], ['vehicle_lease_crew','carrier_sublease_crew'], true)) {
            if ((int)($snapshot['trip']['business_id'] ?? 0) <= 0) $errors[] = 'Не найден номер выбранного рейса для договора.';
            if (self::driverSurname((string)($snapshot['driver']['full_name'] ?? '')) === '') $errors[] = 'Не указана фамилия водителя для имени файла договора.';
            if (($snapshot['document']['number'] ?? '') !== '') {
                $existing = $localPdo->prepare('SELECT 1 FROM generated_documents WHERE document_type=? AND document_number=? LIMIT 1');
                $existing->execute([(string)$template['document_type'], $snapshot['document']['number']]);
                $contractExists = $existing->fetchColumn() !== false;
            }
        }
        if ($missing !== []) $errors[] = 'Не найдены заявки: ' . implode(', ', $missing) . '.';
        array_push($errors,...self::validateParameters((string)$template['document_type'],$snapshot['parameters'] ?? []));
        array_push($errors,...self::validateFixedPlaceholders($placeholders,$values,$snapshot));
        $requestErrors = self::validateRequestRows($ordered,$placeholders);
        foreach ($requestErrors as $requestId => $items) $errors[] = $requestId . ' — ' . implode(', ', $items) . '.';
        if (in_array('requests.unload_address',$placeholders,true)) {
            if ($groups === []) $errors[] = 'У выбранных заявок отсутствует место выгрузки.';
            elseif (count($groups) > 1) $errors[] = 'Обнаружены разные места выгрузки; формирование заблокировано до выбора однородного набора заявок.';
        }
        $repeat = self::buildRequestPlaceholderRows($ordered);

        return [
            'ok'=>$errors===[], 'errors'=>$errors, 'warnings'=>$warnings, 'request_errors'=>$requestErrors,
            'missing_request_ids'=>$missing,'request_ids'=>$ids,'found_count'=>count($ordered),
            'total_mass_kg'=>$totalKg,'total_mass_kg_formatted'=>self::formatMassKg($totalKg),
            'unload_groups'=>$groups,'unload_address'=>$unload,'template'=>$template,'executor'=>$executor,
            'snapshot'=>$snapshot,'values'=>$values,'request_values'=>$repeat,'contract_exists'=>$contractExists,
        ];
    }

    public static function generate(PDO $pdo, int $companyId, int $userId, string $role, array $prepared, bool $overwriteExisting = false): int
    {
        if (!($prepared['ok'] ?? false)) throw new RuntimeException('Документ нельзя сформировать до устранения ошибок проверки.');
        if (!self::runtimeSchemaReady($pdo)) throw new RuntimeException('Схема генератора договоров не обновлена. Требуется штатная tenant migration 129.');
        $template = $prepared['template'] ?? null;
        if (!is_array($template)) throw new RuntimeException('Версия шаблона не определена.');
        $templatePath = DocumentService::resolveStoredPath($companyId,(string)$template['stored_path']);
        if ($templatePath === null) throw new RuntimeException('Файл активной версии шаблона недоступен.');
        $templateHash = hash_file('sha256', $templatePath) ?: '';
        if ($templateHash === '' || !hash_equals((string)($template['sha256'] ?? ''), $templateHash)) {
            throw new RuntimeException('Контрольная сумма активной версии шаблона не совпадает. Формирование заблокировано.');
        }

        $type = (string)$template['document_type'];
        $snapshot = $prepared['snapshot'] ?? null;
        if (!is_array($snapshot)) throw new RuntimeException('Snapshot документа не определён.');
        $absolutePath = null;
        $replacedFile = null;

        $pdo->beginTransaction();
        try {
            $params = is_array($snapshot['parameters'] ?? null) ? $snapshot['parameters'] : [];
            if (in_array($type, ['vehicle_lease_crew','carrier_sublease_crew'], true)) {
                $tripNumber = (int)($snapshot['trip']['business_id'] ?? 0);
                if ($tripNumber <= 0) throw new RuntimeException('Не найден номер выбранного рейса для договора.');
                $number = 'П-'.$tripNumber;
                $snapshot['document']['number'] = $number;
                $snapshot['document']['number_mode'] = 'trip_based';
                $snapshot['parameters']['contract_number'] = $number;
                $params = $snapshot['parameters'];
                $existing = $pdo->prepare('SELECT * FROM generated_documents WHERE document_type=? AND document_number=? LIMIT 1 FOR UPDATE');
                $existing->execute([$type, $number]);
                $existingRow = $existing->fetch(PDO::FETCH_ASSOC) ?: null;
                if ($existingRow !== null && !$overwriteExisting) {
                    throw new RuntimeException('Договор с номером '.$number.' уже сформирован. Подтвердите перезапись договора.');
                }
                if ($existingRow !== null) {
                    $storedPath = (string)($existingRow['stored_path'] ?? '');
                    $expectedPrefix = 'companies/'.$companyId.'/document-generator/generated/';
                    if ($storedPath === '' || !str_starts_with($storedPath, $expectedPrefix) || str_contains($storedPath, '..') || str_contains($storedPath, '\\')) {
                        throw new RuntimeException('Путь ранее сформированного договора недопустим. Перезапись остановлена.');
                    }
                    $oldFile = DocumentService::resolveStoredPath($companyId, $storedPath);
                    if ($oldFile !== null) {
                        $shared = $pdo->prepare('SELECT id FROM generated_documents WHERE stored_path=? AND id<>? LIMIT 1');
                        $shared->execute([$storedPath, (int)$existingRow['id']]);
                        if ($shared->fetchColumn() === false) $replacedFile = $oldFile;
                    } elseif (file_exists(storage_path($storedPath)) || is_link(storage_path($storedPath))) {
                        throw new RuntimeException('Путь ранее сформированного договора недопустим. Перезапись остановлена.');
                    }
                    $pdo->prepare('DELETE FROM generated_documents WHERE id=?')->execute([(int)$existingRow['id']]);
                }
            } else {
                $number = trim((string)($params['waybill_number'] ?? ''));
                $snapshot['document']['number'] = $number;
                $snapshot['document']['number_mode'] = 'manual';
            }
            $date = (string)(in_array($type, ['vehicle_lease_crew','carrier_sublease_crew'], true) ? ($params['contract_date'] ?? '') : ($params['waybill_date'] ?? ''));
            $values = self::buildFixedValues($snapshot);

            if (in_array($type, ['vehicle_lease_crew','carrier_sublease_crew'], true)) {
                $surname = self::driverSurname((string)($snapshot['driver']['full_name'] ?? ''));
                if ($surname === '') throw new RuntimeException('Не указана фамилия водителя для имени файла договора.');
                $downloadName = DocumentService::safeDownloadName('Рейс '.$tripNumber.' '.$surname.($type === 'carrier_sublease_crew' ? ' Субаренда' : '').'.docx');
            } else {
                $downloadName = DocumentService::safeDownloadName('Приложение-к-путевому-листу'.($number!==''?'_'.$number:'').'.docx');
            }
            $relativeDir = sprintf('companies/%d/document-generator/generated/%s', $companyId, date('Y/m'));
            $absoluteDir = storage_path($relativeDir);
            if (!is_dir($absoluteDir) && !mkdir($absoluteDir,0775,true) && !is_dir($absoluteDir)) throw new RuntimeException('Не удалось создать каталог сформированных документов.');
            $storedName = sprintf('%s_%s.docx', date('Ymd_His'), bin2hex(random_bytes(8)));
            $relativePath = $relativeDir . '/' . $storedName;
            $absolutePath = storage_path($relativePath);
            DocumentTemplateService::render($templatePath,$absolutePath,$values,$prepared['request_values']);
            $sha = hash_file('sha256',$absolutePath) ?: '';
            if ($sha === '') throw new RuntimeException('Не удалось вычислить контрольную сумму сформированного документа.');

            $summary = $snapshot['requests_summary'];
            $stmt = $pdo->prepare(
                'INSERT INTO generated_documents(document_type,document_number,document_date,template_id,template_version_id,route_executor_id,request_ids_json,total_mass_kg,unload_address,parameters_json,snapshot_json,stored_path,original_name,sha256,created_by_user_id,created_by_role)
                 VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            $stmt->execute([
                $type,$number!==''?$number:null,$date!==''?$date:null,(int)$template['template_id'],(int)$template['version_id'],
                (int)($snapshot['executor']['crew_id'] ?? 0),self::json($summary['input_ids'] ?? []),(float)($summary['total_mass_kg'] ?? 0),
                (string)($summary['unload_address'] ?? ''),self::json($params),self::json($snapshot),$relativePath,$downloadName,$sha,$userId,$role,
            ]);
            $documentId = (int)$pdo->lastInsertId();
            $child = $pdo->prepare('INSERT INTO generated_document_requests(generated_document_id,request_id,position,snapshot_json) VALUES(?,?,?,?)');
            foreach (($snapshot['requests'] ?? []) as $pos=>$request) {
                $child->execute([$documentId,(string)($request['zayavka_id'] ?? ''),$pos+1,self::json($request)]);
            }
            $pdo->commit();
            if (is_string($replacedFile) && is_file($replacedFile) && !unlink($replacedFile)) {
                error_log('Document generator overwrite: cannot remove replaced file '.$replacedFile);
            }
            return $documentId;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if (is_string($absolutePath) && $absolutePath !== '') @unlink($absolutePath);
            throw $e;
        }
    }

    public static function listHistory(PDO $pdo, int $limit = 100, int $userId = 0, string $role = ''): array
    {
        $limit=max(1,min(500,$limit));
        $base="SELECT g.*,t.name template_name,v.version_no FROM generated_documents g LEFT JOIN document_templates t ON t.id=g.template_id LEFT JOIN document_template_versions v ON v.id=g.template_version_id";
        if (AccessControlService::isEffectiveSystemOwner()) {
            return $pdo->query($base . " ORDER BY g.id DESC LIMIT {$limit}")->fetchAll(PDO::FETCH_ASSOC);
        }
        if ($userId<=0) return [];
        $stmt=$pdo->prepare($base . " WHERE g.created_by_user_id=? ORDER BY g.id DESC LIMIT {$limit}");
        $stmt->execute([$userId]);
        $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_values(array_filter($rows, static fn(array $row): bool => self::loadExecutorForAccess($pdo,(int)($row['route_executor_id'] ?? 0),$userId,$role)!==null));
    }

    public static function getGenerated(PDO $pdo, int $id): ?array
    {
        $stmt=$pdo->prepare('SELECT * FROM generated_documents WHERE id=? LIMIT 1');$stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public static function getAccessibleGenerated(PDO $pdo, int $id, int $userId, string $role): ?array
    {
        $row=self::getGenerated($pdo,$id);
        if (!$row) return null;
        if (AccessControlService::isEffectiveSystemOwner()) return $row;
        if ($userId<=0 || (int)($row['created_by_user_id'] ?? 0)!==$userId) return null;
        return self::loadExecutorForAccess($pdo,(int)($row['route_executor_id'] ?? 0),$userId,$role)!==null ? $row : null;
    }

    public static function generatedDownloadName(array $row): string
    {
        $labels = [
            'client_sublease_crew' => 'Договор-субаренды',
            'client_invoice' => 'Счет',
            'client_act' => 'Акт',
            'client_upd_contract' => 'Договор-субаренды-УПД',
            'client_upd_invoice' => 'Счет-УПД',
            'client_upd_status2' => 'УПД-статус-2',
        ];
        $type = (string)($row['document_type'] ?? '');
        if (isset($labels[$type])) {
            $snapshot = json_decode((string)($row['snapshot_json'] ?? ''), true);
            $tripNumber = is_array($snapshot) ? (int)($snapshot['trip']['business_id'] ?? 0) : 0;
            if ($tripNumber > 0) {
                return DocumentService::safeDownloadName('#' . $tripNumber . '__' . $labels[$type] . '.docx');
            }
        }
        return DocumentService::safeDownloadName((string)($row['original_name'] ?? 'document.docx'));
    }

    /** @return array{path:string,count:int} Temporary ZIP owned by the caller, which must delete it. */
    public static function createHistoryArchive(PDO $pdo, int $companyId, int $userId, string $role, array $requestedIds): array
    {
        if (!class_exists(\ZipArchive::class)) throw new RuntimeException('На сервере недоступно создание ZIP-архивов.');
        if ($requestedIds === [] || count($requestedIds) > 200) throw new RuntimeException('Выберите от 1 до 200 документов.');
        $ids = [];
        foreach ($requestedIds as $value) {
            if (!is_scalar($value) || !preg_match('/^[1-9][0-9]*$/D', (string)$value)) {
                throw new RuntimeException('Список выбранных документов некорректен.');
            }
            $id = (int)$value;
            if ($id <= 0 || (string)$id !== (string)$value || isset($ids[$id])) {
                throw new RuntimeException('Список выбранных документов некорректен.');
            }
            $ids[$id] = true;
        }

        $files = [];
        foreach (array_keys($ids) as $id) {
            $row = self::getAccessibleGenerated($pdo, $id, $userId, $role);
            if ($row === null) throw new RuntimeException('Один из выбранных документов недоступен. Обновите историю.');
            $path = DocumentService::resolveStoredPath($companyId, (string)$row['stored_path']);
            if ($path === null || !is_readable($path)) throw new RuntimeException('Файл одного из выбранных документов недоступен.');
            $actual = hash_file('sha256', $path) ?: '';
            if ($actual === '' || !hash_equals((string)$row['sha256'], $actual)) {
                throw new RuntimeException('Контрольная сумма одного из документов не совпадает. Скачивание заблокировано.');
            }
            $files[] = ['path' => $path, 'name' => self::generatedDownloadName($row), 'id' => $id];
        }

        $temporaryPath = tempnam(sys_get_temp_dir(), 'planex_docgen_');
        if ($temporaryPath === false) throw new RuntimeException('Не удалось подготовить ZIP-архив.');
        $zip = new \ZipArchive();
        $opened = false;
        try {
            if ($zip->open($temporaryPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Не удалось создать ZIP-архив.');
            }
            $opened = true;
            $usedNames = [];
            foreach ($files as $file) {
                $name = $file['name'];
                if (isset($usedNames[$name])) {
                    $name = substr($name, 0, -5) . '__ID-' . $file['id'] . '.docx';
                }
                $usedNames[$name] = true;
                if (!$zip->addFile($file['path'], $name)) throw new RuntimeException('Не удалось добавить документ в ZIP-архив.');
            }
            $closed = $zip->close();
            $opened = false;
            if (!$closed || !is_file($temporaryPath) || filesize($temporaryPath) === 0) {
                throw new RuntimeException('Не удалось завершить ZIP-архив.');
            }
            return ['path' => $temporaryPath, 'count' => count($files)];
        } catch (\Throwable $e) {
            if ($opened) $zip->close();
            @unlink($temporaryPath);
            throw $e;
        }
    }

    public static function copySeedFromGenerated(array $generated): array
    {
        $snapshot=json_decode((string)($generated['snapshot_json'] ?? ''),true);
        if (!is_array($snapshot)) return [];
        $summary=$snapshot['requests_summary'] ?? [];
        $params=$snapshot['parameters'] ?? [];
        if (!is_array($params)) $params=[];
        unset($params['contract_number'],$params['executor_bank_inn']);
        return [
            'template_id'=>(int)($generated['template_id'] ?? 0),
            'crew_id'=>(int)($snapshot['executor']['crew_id'] ?? 0),
            'request_input'=>implode("\n",array_map('strval',$summary['input_ids'] ?? [])),
            'params'=>$params,
        ];
    }

    public static function formatMassKg(float $kg): string
    {
        $rounded=round($kg,3);
        $text=number_format($rounded,3,',',' ');
        $text=preg_replace('/,000$/','',$text) ?? $text;
        $text=preg_replace('/(,\d*[1-9])0+$/','$1',$text) ?? $text;
        return $text;
    }

    public static function requestContacts(array $row): string
    {
        $value=trim((string)($row['contacts'] ?? ''));
        return $value!=='' ? $value : '—';
    }

    private static function driverSurname(string $fullName): string
    {
        $parts = preg_split('/\s+/u', trim($fullName), 2);
        $first = (string)($parts[0] ?? '');
        return preg_match('/^[\p{L}][\p{L}-]*$/u', $first) === 1 ? $first : '';
    }
}
