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
        array $params
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

        $placeholders = json_decode((string)($template['placeholders_json'] ?? '[]'), true);
        if (!is_array($placeholders)) $placeholders = [];
        $groups = self::unloadGroups($ordered);
        $totalKg = 0.0;
        foreach ($ordered as $row) $totalKg += ((float)($row['mass_netto'] ?? 0)) * 1000;
        $totalKg = round($totalKg,3);
        $unload = count($groups)===1 ? (string)array_key_first($groups) : '';
        $snapshot = self::buildSnapshot($company,$executor,$ordered,$params,$template);
        $snapshot['requests_summary'] = [
            'count'=>count($ordered),'total_mass_kg'=>$totalKg,'unload_address'=>$unload,
            'unload_groups'=>$groups,'missing_ids'=>$missing,'input_ids'=>$ids,
        ];
        $values = self::buildFixedValues($snapshot);
        $errors = [];
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
            'ok'=>$errors===[], 'errors'=>$errors, 'request_errors'=>$requestErrors,
            'missing_request_ids'=>$missing,'request_ids'=>$ids,'found_count'=>count($ordered),
            'total_mass_kg'=>$totalKg,'total_mass_kg_formatted'=>self::formatMassKg($totalKg),
            'unload_groups'=>$groups,'unload_address'=>$unload,'template'=>$template,'executor'=>$executor,
            'snapshot'=>$snapshot,'values'=>$values,'request_values'=>$repeat,
        ];
    }

    public static function generate(PDO $pdo, int $companyId, int $userId, string $role, array $prepared): int
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

        $pdo->beginTransaction();
        try {
            $params = is_array($snapshot['parameters'] ?? null) ? $snapshot['parameters'] : [];
            if ($type === 'vehicle_lease_crew') {
                $number = DocumentNumberSequenceService::allocateVehicleLeaseContractNumber($pdo);
                $snapshot['document']['number'] = $number;
                $snapshot['document']['number_mode'] = 'automatic';
                $snapshot['parameters']['contract_number'] = $number;
                $params = $snapshot['parameters'];
            } else {
                $number = trim((string)($params['waybill_number'] ?? ''));
                $snapshot['document']['number'] = $number;
                $snapshot['document']['number_mode'] = 'manual';
            }
            $date = (string)($type==='vehicle_lease_crew' ? ($params['contract_date'] ?? '') : ($params['waybill_date'] ?? ''));
            $values = self::buildFixedValues($snapshot);

            $downloadBase = $type==='vehicle_lease_crew' ? 'Договор-заявка' : 'Приложение-к-путевому-листу';
            $downloadName = DocumentService::safeDownloadName($downloadBase . ($number!=='' ? '_' . $number : '') . '.docx');
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
        $rows=$pdo->query("SELECT g.*,t.name template_name,v.version_no FROM generated_documents g LEFT JOIN document_templates t ON t.id=g.template_id LEFT JOIN document_template_versions v ON v.id=g.template_version_id ORDER BY g.id DESC LIMIT {$limit}")->fetchAll(PDO::FETCH_ASSOC);
        if (in_array($role,['company_owner','senior_logist'],true)) return $rows;
        if ($role!=='logist' || $userId<=0) return [];
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
        if (in_array($role,['company_owner','senior_logist'],true)) return $row;
        if ($role!=='logist' || $userId<=0) return null;
        return self::loadExecutorForAccess($pdo,(int)($row['route_executor_id'] ?? 0),$userId,$role)!==null ? $row : null;
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
        $parts=[];
        foreach (['kontakt_tel','tel_dopolnitelnyy','kontakt_email'] as $field) {
            $value=trim((string)($row[$field] ?? ''));
            if ($value!=='' && !in_array($value,$parts,true)) $parts[]=$value;
        }
        return implode(', ',$parts);
    }
}
