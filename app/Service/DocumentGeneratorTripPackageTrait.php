<?php

namespace App\Service;

use PDO;
use RuntimeException;
use Throwable;

trait DocumentGeneratorTripPackageTrait
{
    private const CLIENT_PACKAGE_VARIANTS = [
        'act' => [
            'client_sublease_crew' => 'Договор субаренды транспортного средства с экипажем',
            'client_invoice' => 'Счёт клиенту',
            'client_act' => 'Акт клиенту',
        ],
        'upd' => [
            'client_upd_contract' => 'Договор субаренды транспортного средства с экипажем',
            'client_upd_invoice' => 'Счёт клиенту',
            'client_upd_status2' => 'УПД клиенту, статус 2',
        ],
    ];

    public static function canGenerateClientPackage(string $role): bool
    {
        return $role === 'company_owner' || AccessControlService::isEffectiveSystemOwner();
    }

    public static function listDocumentTrips(PDO $pdo, bool $includeFinance = false): array
    {
        $trips = WasteTripService::fetchTrips($pdo, [], true);
        $out = [];
        foreach ($trips as $trip) {
            if ((string)($trip['status'] ?? '') === WasteTripService::STATUS_CANCELLED) continue;
            $trip['_document_ready'] = (int)($trip['route_executor_id'] ?? 0) > 0
                && (int)($trip['request_count'] ?? 0) > 0;
            $trip['_document_period'] = self::tripPeriodLabel($trip);
            $trip['_carrier_contract_defaults'] = self::suggestCarrierContractDefaults($trip);
            if ($includeFinance) {
                $trip['_package_dates'] = self::suggestClientPackageDates($pdo, $trip);
            }
            $trip['_customer_amount'] = $includeFinance
                ? self::sumPaymentRows(WasteTripService::financialCustomerPayments($pdo, $trip))
                : null;
            $trip['_contract_number'] = ClientPackageInvoiceNumberPolicy::tripDocumentNumber((int)($trip['business_id'] ?? 0));
            $out[] = $trip;
        }
        return $out;
    }

    public static function suggestCarrierContractDefaults(array $trip): array
    {
        [$start] = self::tripDocumentDates($trip);
        $useSale = (string)($trip['legal_entity_code'] ?? '') === 'mg_logistic'
            || WasteTripService::hasNoMarkup($trip);
        $payments = $useSale ? (array)($trip['customer_payments'] ?? []) : (array)($trip['carrier_payments'] ?? []);
        $finish = $start === null ? '' : (new \DateTimeImmutable($start))->modify('+7 days')->format('Y-m-d');
        return [
            'contract_date' => $start ?? '',
            'start_date' => $start ?? '',
            'finish_date' => $finish,
            'price' => self::sumPaymentRows($payments) ?? '',
        ];
    }

    public static function listDocumentClients(PDO $pdo, int $userId, string $role): array
    {
        return LinearRouteService::fetchVisibleClients($pdo, ['user_id'=>$userId,'role_code'=>$role]);
    }

    public static function prepareFromTrip(
        PDO $pdo,
        array $company,
        int $companyId,
        int $userId,
        string $role,
        int $templateId,
        int $tripId,
        array $params,
        ?GoogleSheetsRequestContactService $contactService = null
    ): array {
        try {
            $trip = self::loadDocumentTrip($pdo, $tripId, false);
        } catch (RuntimeException $e) {
            return self::failed([$e->getMessage()]);
        }
        $requestIds = self::tripRequestIds($trip);
        if ($requestIds === []) return self::failed(['В выбранном рейсе нет заявок ФЭО.']);
        $crewId = (int)($trip['route_executor_id'] ?? 0);
        if ($crewId <= 0) return self::failed(['В выбранном рейсе не назначен Исполнитель рейса.']);

        $context = ['trip'=>self::tripSnapshot($trip)];
        $template = self::getTemplateVersion($pdo, $templateId, null, true);
        if (in_array((string)($template['document_type'] ?? ''), ['vehicle_lease_crew','carrier_sublease_crew'], true)) {
            try {
                [$warehouseContext, $requestIds] = self::warehouseDocumentContext($trip, $requestIds);
                $context += $warehouseContext;
            } catch (\Throwable $e) {
                return self::failed(['Не удалось проверить складские адреса: '.$e->getMessage()]);
            }
        }

        return self::prepare(
            $pdo,
            $company,
            $companyId,
            $userId,
            $role,
            $templateId,
            $crewId,
            implode("\n", $requestIds),
            $params,
            $contactService,
            $context
        );
    }

    public static function prepareClientPackage(
        PDO $pdo,
        array $company,
        int $companyId,
        int $userId,
        string $role,
        int $clientId,
        int $tripId,
        string $contractDate,
        ?string $invoiceDate = null,
        ?string $actDate = null,
        ?GoogleSheetsRequestContactService $contactService = null,
        string $variant = 'act',
        ?string $contractNumberInput = null
    ): array {
        if (!isset(self::CLIENT_PACKAGE_VARIANTS[$variant])) return self::failed(['Неизвестный вид комплекта документов.']);
        $types = self::CLIENT_PACKAGE_VARIANTS[$variant];
        $contractType = $variant === 'upd' ? 'client_upd_contract' : 'client_sublease_crew';
        $invoiceType = $variant === 'upd' ? 'client_upd_invoice' : 'client_invoice';
        $transferType = $variant === 'upd' ? 'client_upd_status2' : 'client_act';
        $invoiceDate ??= $contractDate;
        $actDate ??= $contractDate;
        if (!self::canGenerateClientPackage($role)) {
            return self::failed(['Комплект документов клиенту доступен только руководителю компании.']);
        }
        foreach (['договора' => $contractDate, 'счёта' => $invoiceDate, $variant === 'upd' ? 'УПД' : 'акта' => $actDate] as $label => $date) {
            if (!self::validIsoDate($date)) return self::failed(['Укажите корректную дату ' . $label . '.']);
        }

        try {
            $trip = self::loadDocumentTrip($pdo, $tripId, true);
            if ((int)($trip['client_id'] ?? 0) !== $clientId || $clientId <= 0) {
                throw new RuntimeException('Выбранный клиент не соответствует заказчику выбранного рейса.');
            }
            $client = self::loadClientForDocument($pdo, $clientId, $userId, $role);
            $templates = [];
            foreach (array_keys($types) as $type) {
                $templates[$type] = self::getUniqueActiveTemplateByType($pdo, $type);
            }
        } catch (RuntimeException $e) {
            return self::failed([$e->getMessage()]);
        }

        $requestIds = self::tripRequestIds($trip);
        if ($requestIds === []) return self::failed(['В выбранном рейсе нет заявок ФЭО.']);
        if ($variant === 'upd') {
            try {
                [$warehouseContext, $requestIds] = self::warehouseDocumentContext($trip, $requestIds);
            } catch (\Throwable $e) {
                return self::failed(['Не удалось проверить складские адреса: '.$e->getMessage()]);
            }
        } else {
            $warehouseContext = [];
        }
        $crewId = (int)($trip['route_executor_id'] ?? 0);
        if ($crewId <= 0) return self::failed(['В выбранном рейсе не назначен Исполнитель рейса.']);

        $payments = WasteTripService::financialCustomerPayments($pdo, $trip);
        $amount = self::sumPaymentRows($payments);
        if ($amount === null || (float)$amount <= 0) {
            return self::failed(['В выбранном рейсе не заполнена положительная ставка заказчика.']);
        }

        [$startDate, $finishDate] = self::tripDocumentDates($trip);
        if ($startDate === null) return self::failed(['В выбранном рейсе не указана дата начала для договора.']);
        if ($finishDate === null) return self::failed(['В выбранном рейсе не указана дата завершения для договора.']);

        try {
            $packageNumber = ClientPackageInvoiceNumberPolicy::number((int)($trip['business_id'] ?? 0), $amount);
        } catch (RuntimeException $e) {
            return self::failed([$e->getMessage()]);
        }
        $previousNumber = $pdo->prepare("SELECT DISTINCT document_number FROM generated_documents WHERE document_type IN ('client_invoice','client_upd_invoice') AND JSON_UNQUOTE(JSON_EXTRACT(snapshot_json,'$.trip.id'))=? ORDER BY document_number");
        $previousNumber->execute([(string)$tripId]);
        $previousNumbers = $previousNumber->fetchAll(PDO::FETCH_COLUMN);
        $historical = ClientPackageInvoiceNumberPolicy::historical((int)($trip['business_id'] ?? 0));
        $supersededNumber = ClientPackageInvoiceNumberPolicy::tripDocumentNumber((int)($trip['business_id'] ?? 0));
        if ($historical !== null) {
            foreach ($previousNumbers as $previous) {
                if (!in_array((string)$previous, [$packageNumber, $supersededNumber], true)) {
                    return self::failed(['Для рейса найден счёт с неожиданным номером ' . $previous . '. Уточните историю документов.']);
                }
            }
        } elseif (count($previousNumbers) > 1) {
            return self::failed(['Для рейса найдены счета с разными номерами. Уточните историю документов.']);
        }
        if ($previousNumbers !== [] && $historical === null) {
            $packageNumber = (string)$previousNumbers[0];
        }
        if ($historical !== null) {
            try {
                $historicalInvoice = ClientPackageInvoiceNumberPolicy::existingHistoricalInvoice(
                    $pdo, (int)$trip['business_id'], $tripId, $clientId
                );
            } catch (RuntimeException $e) {
                return self::failed([$e->getMessage()]);
            }
            if ((string)$historicalInvoice['invoice_date'] !== $invoiceDate) {
                return self::failed(['Для исторического счёта № ' . $packageNumber . ' укажите его исходную дату: ' . $historicalInvoice['invoice_date'] . '.']);
            }
        }
        $transferNumber = ClientPackageInvoiceNumberPolicy::tripDocumentNumber((int)($trip['business_id'] ?? 0));
        $contractNumber = trim((string)$contractNumberInput);
        if ($contractNumber === '') $contractNumber = $transferNumber;
        if (mb_strlen($contractNumber) > 50 || !preg_match('/^[\p{L}\p{N}][\p{L}\p{N} ._\/-]*$/u', $contractNumber)) {
            return self::failed(['Номер договора: до 50 символов; допустимы буквы, цифры, пробел, точка, дефис, подчёркивание и косая черта.']);
        }
        $paymentTerms = self::paymentTermsText($payments);
        if ($paymentTerms === '') $paymentTerms = 'Согласно условиям выбранного рейса.';
        $context = [
            'client' => self::clientSnapshot($client),
            'trip' => self::tripSnapshot($trip),
            'package' => [
                'number' => $packageNumber,
                'transfer_number' => $transferNumber,
                'contract_number' => $contractNumber,
                'variant' => $variant,
                'date' => $contractDate,
                'contract_date' => $contractDate,
                'invoice_date' => $invoiceDate,
                'act_date' => $actDate,
                'amount' => $amount,
                'vat_mode' => 'Без НДС',
            ],
        ];
        $context += $warehouseContext;
        $params = [
            'contract_number' => $contractNumber,
            'contract_date' => $contractDate,
            'invoice_date' => $invoiceDate,
            'act_date' => $actDate,
            'start_date' => $startDate,
            'start_time' => '',
            'finish_date' => $finishDate,
            'price' => $amount,
            'vat_mode' => 'Без НДС',
            'payment_terms' => $paymentTerms,
        ];

        $prepared = self::prepare(
            $pdo,
            $company,
            $companyId,
            $userId,
            $role,
            (int)$templates[$contractType]['template_id'],
            $crewId,
            implode("\n", $requestIds),
            $params,
            $contactService,
            $context
        );
        if (!($prepared['ok'] ?? false)) return $prepared;

        $snapshot = is_array($prepared['snapshot'] ?? null) ? $prepared['snapshot'] : [];
        $snapshot['package'] = $context['package'];
        $snapshot['service'] = [
            'description' => self::clientServiceDescription($snapshot, $contractNumber, $contractDate),
            'route' => $variant === 'upd' ? self::clientRouteDescription($snapshot) : '',
            'amount' => $amount,
            'vat_mode' => 'Без НДС',
        ];
        $prepared['snapshot'] = $snapshot;
        $prepared['values'] = self::buildFixedValues($snapshot);

        $extraErrors = [];
        foreach ([$invoiceType,$transferType] as $type) {
            $placeholders = json_decode((string)($templates[$type]['placeholders_json'] ?? '[]'), true);
            if (!is_array($placeholders)) $placeholders = [];
            array_push($extraErrors, ...self::validateFixedPlaceholders($placeholders, $prepared['values'], $snapshot));
        }
        $extraErrors = array_values(array_unique($extraErrors));
        if ($extraErrors !== []) {
            $prepared['ok'] = false;
            $prepared['errors'] = array_values(array_unique(array_merge((array)($prepared['errors'] ?? []), $extraErrors)));
            return $prepared;
        }

        $prepared['package_number'] = $packageNumber;
        $prepared['transfer_number'] = $transferNumber;
        $prepared['contract_number'] = $contractNumber;
        $prepared['package_dates'] = [$contractType=>$contractDate, $invoiceType=>$invoiceDate, $transferType=>$actDate];
        $prepared['package_variant'] = $variant;
        $prepared['package_amount'] = $amount;
        $prepared['package_amount_formatted'] = self::formatMoneyText($amount);
        $prepared['package_templates'] = $templates;
        $prepared['client'] = $client;
        $prepared['trip'] = $trip;
        $prepared['existing_package_documents'] = $historical !== null
            ? self::existingClientPackageTripDocuments($pdo, $tripId, array_keys($types))
            : array_merge(
                self::existingClientPackageContractDocuments($pdo, $packageNumber, $contractType),
                self::existingClientPackageDocuments($pdo, $packageNumber, [$invoiceType]),
                self::existingClientPackageDocuments($pdo, $transferNumber, [$transferType])
            );
        $contractIds = array_map(
            static fn(array $row): int => (int)$row['id'],
            array_filter($prepared['existing_package_documents'], static fn(array $row): bool => (string)$row['document_type'] === $contractType)
        );
        foreach (self::existingClientPackageDocuments($pdo, $contractNumber, [$contractType]) as $row) {
            if (!in_array((int)$row['id'], $contractIds, true)) {
                return self::failed(['Номер договора уже используется в другом комплекте. Укажите другой номер.']);
            }
        }
        $prepared['package_exists'] = $prepared['existing_package_documents'] !== [];
        return $prepared;
    }

    /** @return array<string,int> */
    public static function generateClientPackage(PDO $pdo, int $companyId, int $userId, string $role, array $prepared, bool $overwriteExisting = false): array
    {
        if (!($prepared['ok'] ?? false)) throw new RuntimeException('Комплект нельзя сформировать до устранения ошибок проверки.');
        if (!self::canGenerateClientPackage($role)) throw new RuntimeException('Комплект документов клиенту доступен только руководителю компании.');
        if (!self::runtimeSchemaReady($pdo)) throw new RuntimeException('Схема генератора документов не готова.');

        $number = trim((string)($prepared['package_number'] ?? ''));
        $contractNumber = trim((string)($prepared['contract_number'] ?? $number));
        $transferNumber = trim((string)($prepared['transfer_number'] ?? $number));
        $dates = (array)($prepared['package_dates'] ?? []);
        if (!preg_match('/^\d{2}-\d+(?:-\d+)?$/', $number)) {
            throw new RuntimeException('Номер комплекта документов не определён.');
        }
        if (!preg_match('/^\d{2}-\d+$/', $transferNumber)) {
            throw new RuntimeException('Номер УПД или акта не определён.');
        }
        $variant = (string)($prepared['package_variant'] ?? 'act');
        if (!isset(self::CLIENT_PACKAGE_VARIANTS[$variant])) throw new RuntimeException('Неизвестный вид комплекта документов.');
        $types = self::CLIENT_PACKAGE_VARIANTS[$variant];
        foreach (array_keys($types) as $type) {
            if (!self::validIsoDate((string)($dates[$type] ?? ''))) throw new RuntimeException('Дата документа не определена: ' . $type . '.');
        }
        $templates = is_array($prepared['package_templates'] ?? null) ? $prepared['package_templates'] : [];
        foreach (array_keys($types) as $type) {
            if (!is_array($templates[$type] ?? null)) throw new RuntimeException('Шаблон комплекта не определён: ' . $type . '.');
        }
        $baseSnapshot = is_array($prepared['snapshot'] ?? null) ? $prepared['snapshot'] : null;
        if (!is_array($baseSnapshot)) throw new RuntimeException('Snapshot комплекта документов не определён.');

        $resolvedTemplates = [];
        foreach ($templates as $type => $template) {
            if (!isset($types[$type])) continue;
            $path = DocumentService::resolveStoredPath($companyId, (string)($template['stored_path'] ?? ''));
            if ($path === null) throw new RuntimeException('Файл шаблона недоступен: ' . $types[$type] . '.');
            $actual = hash_file('sha256', $path) ?: '';
            if ($actual === '' || !hash_equals((string)($template['sha256'] ?? ''), $actual)) {
                throw new RuntimeException('Контрольная сумма шаблона не совпадает: ' . $types[$type] . '.');
            }
            $resolvedTemplates[$type] = [$template, $path];
        }

        $createdFiles = [];
        $replacedFiles = [];
        $ids = [];
        $lockName = 'invoice-import-' . substr(hash('sha256', $companyId . ':' . (string)($prepared['client']['inn'] ?? '') . ':' . $number), 0, 40);
        $lock = $pdo->prepare('SELECT GET_LOCK(?, 10)');
        $lock->execute([$lockName]);
        if ((int)$lock->fetchColumn() !== 1) throw new RuntimeException('Комплект этого рейса уже формируется. Повторите позже.');
        try {
            $pdo->beginTransaction();
            $existing = [];
            $businessId = (int)($prepared['trip']['business_id'] ?? 0);
            if (ClientPackageInvoiceNumberPolicy::historical($businessId) !== null) {
                $tripId = (int)($prepared['trip']['id'] ?? 0);
                $existing = self::existingClientPackageTripDocuments($pdo, $tripId, array_keys($types), true);
                $seenTypes = [];
                foreach ($existing as $row) {
                    $type = (string)$row['document_type'];
                    if (isset($seenTypes[$type])) {
                        throw new RuntimeException('Для рейса найдено несколько документов одного вида. Перезапись остановлена.');
                    }
                    $seenTypes[$type] = true;
                    if ($type === ($variant === 'upd' ? 'client_upd_invoice' : 'client_invoice')
                        && !in_array((string)$row['document_number'], [$number, $transferNumber], true)) {
                        throw new RuntimeException('Ранее сформированный счёт рейса имеет неожиданный номер. Перезапись остановлена.');
                    }
                }
                foreach (array_keys($types) as $type) {
                    $targetNumber = $type === ($variant === 'upd' ? 'client_upd_contract' : 'client_sublease_crew')
                        ? $contractNumber
                        : ($type === ($variant === 'upd' ? 'client_upd_invoice' : 'client_invoice') ? $number : $transferNumber);
                    $collision = $pdo->prepare('SELECT id FROM generated_documents WHERE document_type=? AND document_number=? LIMIT 1 FOR UPDATE');
                    $collision->execute([$type, $targetNumber]);
                    $collisionId = $collision->fetchColumn();
                    if ($collisionId !== false && !in_array((int)$collisionId, array_map('intval', array_column($existing, 'id')), true)) {
                        throw new RuntimeException('Номер документа № ' . $targetNumber . ' уже занят другим рейсом. Перезапись остановлена.');
                    }
                }
            } else {
                foreach (array_keys($types) as $type) {
                    if ($type === ($variant === 'upd' ? 'client_upd_contract' : 'client_sublease_crew')) {
                        $dup = $pdo->prepare("SELECT * FROM generated_documents WHERE document_type=? AND JSON_UNQUOTE(JSON_EXTRACT(snapshot_json,'$.package.number'))=? FOR UPDATE");
                        $dup->execute([$type, $number]);
                        array_push($existing, ...$dup->fetchAll(PDO::FETCH_ASSOC));
                    } else {
                        $dup = $pdo->prepare('SELECT * FROM generated_documents WHERE document_type=? AND document_number=? LIMIT 1 FOR UPDATE');
                        $dup->execute([$type, $type === ($variant === 'upd' ? 'client_upd_invoice' : 'client_invoice') ? $number : $transferNumber]);
                        $row = $dup->fetch(PDO::FETCH_ASSOC) ?: null;
                        if ($row !== null) $existing[] = $row;
                    }
                }
            }
            if ($existing !== [] && !$overwriteExisting) {
                throw new RuntimeException('Комплект № ' . $number . ' уже был создан. Подтвердите перезапись комплекта.');
            }
            if ($existing !== []) {
                foreach ($existing as $row) {
                    $storedPath = (string)($row['stored_path'] ?? '');
                    $expectedPrefix = 'companies/' . $companyId . '/document-generator/generated/';
                    if ($storedPath === '' || !str_starts_with($storedPath, $expectedPrefix) || str_contains($storedPath, '..') || str_contains($storedPath, '\\')) {
                        throw new RuntimeException('Путь ранее сформированного документа недопустим. Перезапись остановлена.');
                    }
                    $path = DocumentService::resolveStoredPath($companyId, $storedPath);
                    if ($path !== null) {
                        $shared = $pdo->prepare('SELECT id FROM generated_documents WHERE stored_path=? AND id<>? LIMIT 1');
                        $shared->execute([$storedPath, (int)$row['id']]);
                        if ($shared->fetchColumn() === false) $replacedFiles[] = $path;
                    } elseif (file_exists(storage_path($storedPath)) || is_link(storage_path($storedPath))) {
                        throw new RuntimeException('Путь ранее сформированного документа недопустим. Перезапись остановлена.');
                    }
                }
                $delete = $pdo->prepare('DELETE FROM generated_documents WHERE id=?');
                foreach ($existing as $row) $delete->execute([(int)$row['id']]);
            }

            foreach (array_keys($types) as $type) {
                [$template, $templatePath] = $resolvedTemplates[$type];
                $documentNumber = $type === ($variant === 'upd' ? 'client_upd_contract' : 'client_sublease_crew')
                    ? $contractNumber
                    : ($type === ($variant === 'upd' ? 'client_upd_invoice' : 'client_invoice') ? $number : $transferNumber);
                $date = (string)$dates[$type];
                $snapshot = $baseSnapshot;
                $snapshot['template'] = [
                    'id'=>(int)$template['template_id'],
                    'version_id'=>(int)$template['version_id'],
                    'version_no'=>(int)$template['version_no'],
                    'name'=>(string)$template['template_name'],
                    'document_type'=>$type,
                    'sha256'=>(string)$template['sha256'],
                ];
                $snapshot['document'] = [
                    'number'=>$documentNumber,
                    'number_mode'=>'trip_based',
                    'date'=>$date,
                    'type'=>$type,
                ];
                $values = self::buildFixedValues($snapshot);

                $baseName = match ($type) {
                    'client_sublease_crew' => 'Договор-субаренды',
                    'client_invoice' => 'Счет',
                    'client_act' => 'Акт',
                    'client_upd_contract' => 'Договор-субаренды-УПД',
                    'client_upd_invoice' => 'Счет-УПД',
                    'client_upd_status2' => 'УПД-статус-2',
                    default => 'Документ',
                };
                $downloadName = DocumentService::safeDownloadName($baseName . '_' . $documentNumber . '.docx');
                $relativeDir = sprintf('companies/%d/document-generator/generated/%s', $companyId, date('Y/m'));
                $absoluteDir = storage_path($relativeDir);
                if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0775, true) && !is_dir($absoluteDir)) {
                    throw new RuntimeException('Не удалось создать каталог сформированных документов.');
                }
                $storedName = sprintf('%s_%s_%s.docx', date('Ymd_His'), $type, bin2hex(random_bytes(6)));
                $relativePath = $relativeDir . '/' . $storedName;
                $absolutePath = storage_path($relativePath);
                DocumentTemplateService::render($templatePath, $absolutePath, $values, (array)($prepared['request_values'] ?? []));
                $createdFiles[] = $absolutePath;
                $sha = hash_file('sha256', $absolutePath) ?: '';
                if ($sha === '') throw new RuntimeException('Не удалось вычислить контрольную сумму сформированного документа.');

                $summary = is_array($snapshot['requests_summary'] ?? null) ? $snapshot['requests_summary'] : [];
                $params = is_array($snapshot['parameters'] ?? null) ? $snapshot['parameters'] : [];
                $stmt = $pdo->prepare(
                    'INSERT INTO generated_documents(document_type,document_number,document_date,template_id,template_version_id,route_executor_id,request_ids_json,total_mass_kg,unload_address,parameters_json,snapshot_json,stored_path,original_name,sha256,created_by_user_id,created_by_role) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
                );
                $stmt->execute([
                    $type,$documentNumber,$date,(int)$template['template_id'],(int)$template['version_id'],
                    (int)($snapshot['executor']['crew_id'] ?? 0),self::json($summary['input_ids'] ?? []),
                    (float)($summary['total_mass_kg'] ?? 0),(string)($summary['unload_address'] ?? ''),
                    self::json($params),self::json($snapshot),$relativePath,$downloadName,$sha,$userId,$role,
                ]);
                $documentId = (int)$pdo->lastInsertId();
                $ids[$type] = $documentId;
                $child = $pdo->prepare('INSERT INTO generated_document_requests(generated_document_id,request_id,position,snapshot_json) VALUES(?,?,?,?)');
                foreach (($snapshot['requests'] ?? []) as $pos=>$request) {
                    $child->execute([$documentId,(string)($request['zayavka_id'] ?? ''),$pos+1,self::json($request)]);
                }
            }
            ClientPackageInvoiceSyncService::synchronize($pdo, $prepared, $userId, $role);
            $pdo->commit();
            foreach (array_unique($replacedFiles) as $file) {
                if (is_file($file) && !unlink($file)) {
                    error_log('Document generator overwrite: cannot remove replaced file ' . $file);
                }
            }
            return $ids;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            foreach ($createdFiles as $file) @unlink($file);
            throw $e;
        } finally {
            $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([$lockName]);
        }
    }

    private static function existingClientPackageDocuments(PDO $pdo, string $number, array $types): array
    {
        $placeholders = implode(',', array_fill(0, count($types), '?'));
        $stmt = $pdo->prepare(
            "SELECT id,document_type,document_number,document_date,created_at FROM generated_documents WHERE document_type IN ({$placeholders}) AND document_number=? ORDER BY id"
        );
        $stmt->execute([...$types, $number]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function existingClientPackageTripDocuments(PDO $pdo, int $tripId, array $types, bool $lock = false): array
    {
        $placeholders = implode(',', array_fill(0, count($types), '?'));
        $stmt = $pdo->prepare(
            "SELECT id,document_type,document_number,document_date,created_at,stored_path FROM generated_documents
              WHERE document_type IN ({$placeholders})
                AND JSON_UNQUOTE(JSON_EXTRACT(snapshot_json,'$.trip.id'))=?
              ORDER BY id" . ($lock ? ' FOR UPDATE' : '')
        );
        $stmt->execute([...$types, (string)$tripId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function existingClientPackageContractDocuments(PDO $pdo, string $packageNumber, string $contractType): array
    {
        $stmt = $pdo->prepare(
            "SELECT id,document_type,document_number,document_date,created_at FROM generated_documents WHERE document_type=? AND JSON_UNQUOTE(JSON_EXTRACT(snapshot_json,'$.package.number'))=? ORDER BY id"
        );
        $stmt->execute([$contractType, $packageNumber]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function loadDocumentTrip(PDO $pdo, int $tripId, bool $includeFinance): array
    {
        if ($tripId <= 0) throw new RuntimeException('Выберите рейс.');
        $trip = WasteTripService::fetchTrip($pdo, $tripId, $includeFinance);
        if (!is_array($trip)) throw new RuntimeException('Выбранный рейс не найден или недоступен.');
        if ((string)($trip['status'] ?? '') === WasteTripService::STATUS_CANCELLED) {
            throw new RuntimeException('По отменённому рейсу документы не формируются.');
        }
        return $trip;
    }

    /** @return array{0:array<string,mixed>,1:list<string>} */
    private static function warehouseDocumentContext(array $trip, array $requestIds): array
    {
        $hasSource = (int)($trip['legacy_source_warehouse_id'] ?? 0) > 0;
        $hasDestination = in_array((string)($trip['legacy_route_type'] ?? ''), ['generator_to_warehouse', 'warehouse_to_warehouse'], true)
            || (string)($trip['legacy_unload_type'] ?? '') === 'SKLAD';
        if (!$hasSource && !$hasDestination) return [[], $requestIds];
        $legacy = WasteTripLegacySourceService::connectionFromEnvironment();
        if (!$legacy instanceof PDO) throw new RuntimeException('Старая база складов недоступна.');
        $context = [];
        if ($hasSource) {
            $context['warehouse_loading'] = DocumentGeneratorWarehouseLoadingService::resolve($legacy, $trip, $requestIds);
            $requestIds = $context['warehouse_loading']['trip_request_ids'] ?? $requestIds;
        }
        if ($hasDestination) {
            $context['warehouse_unloading'] = DocumentGeneratorWarehouseUnloadingService::resolve($legacy, $trip);
        }
        return [$context, $requestIds];
    }

    private static function loadClientForDocument(PDO $pdo, int $clientId, int $userId, string $role): array
    {
        if (!LinearRouteService::isVisibleEntity($pdo, 'clients', $clientId, ['user_id'=>$userId,'role_code'=>$role], 'client')) {
            throw new RuntimeException('Клиент недоступен или неактивен.');
        }
        // Migration 033 removed legacy client contact columns. Contacts are optional for documents.
        $stmt = $pdo->prepare("SELECT id,name,inn,kpp,ogrn,legal_address,physical_address,director_full_name,director_position,bank_account,bank_name,bank_bik,bank_corr_account FROM clients WHERE id=? AND status='active' AND deleted_at IS NULL LIMIT 1");
        $stmt->execute([$clientId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$row) throw new RuntimeException('Клиент не найден.');
        return $row;
    }

    private static function clientSnapshot(array $client): array
    {
        $director = trim((string)($client['director_full_name'] ?? ''));
        return [
            'id'=>(int)($client['id'] ?? 0),
            'name'=>(string)($client['name'] ?? ''),
            'inn'=>(string)($client['inn'] ?? ''),
            'kpp'=>(string)($client['kpp'] ?? ''),
            'ogrn'=>(string)($client['ogrn'] ?? ''),
            'legal_address'=>(string)($client['legal_address'] ?? ''),
            'physical_address'=>(string)($client['physical_address'] ?? ''),
            'contact_phone'=>(string)($client['contact_phone'] ?? ''),
            'contact_email'=>(string)($client['contact_email'] ?? ''),
            'director_full_name'=>$director,
            'director_position'=>(string)($client['director_position'] ?? ''),
            'signature_name'=>self::signatureName($director !== '' ? $director : (string)($client['name'] ?? '')),
            'bank_account'=>(string)($client['bank_account'] ?? ''),
            'bank_name'=>(string)($client['bank_name'] ?? ''),
            'bank_bik'=>(string)($client['bank_bik'] ?? ''),
            'bank_corr_account'=>(string)($client['bank_corr_account'] ?? ''),
        ];
    }

    private static function tripSnapshot(array $trip): array
    {
        return [
            'id'=>(int)($trip['id'] ?? 0),
            'business_id'=>(int)($trip['business_id'] ?? 0),
            'client_id'=>(int)($trip['client_id'] ?? 0),
            'client_name'=>(string)($trip['client_name'] ?? ''),
            'route_executor_id'=>(int)($trip['route_executor_id'] ?? 0),
            'status'=>(string)($trip['status'] ?? ''),
            'planned_start_mode'=>(string)($trip['planned_start_mode'] ?? ''),
            'planned_start_date'=>(string)($trip['planned_start_date'] ?? ''),
            'planned_start_date_from'=>(string)($trip['planned_start_date_from'] ?? ''),
            'planned_start_date_to'=>(string)($trip['planned_start_date_to'] ?? ''),
            'planned_finish_date'=>(string)($trip['planned_finish_date'] ?? ''),
            'actual_start_date'=>(string)($trip['actual_start_date'] ?? ''),
            'actual_end_date'=>(string)($trip['actual_end_date'] ?? ''),
            'comment'=>(string)($trip['comment'] ?? ''),
        ];
    }

    private static function tripRequestIds(array $trip): array
    {
        $ids = [];
        foreach ((array)($trip['requests'] ?? []) as $row) {
            $id = trim((string)($row['zayavka_id'] ?? ''));
            if ($id !== '') $ids[] = $id;
        }
        return array_values(array_unique($ids));
    }

    private static function tripDocumentDates(array $trip): array
    {
        $start = self::firstDate([
            $trip['actual_start_date'] ?? null,
            $trip['planned_start_date'] ?? null,
            $trip['planned_start_date_from'] ?? null,
        ]);
        $finish = self::firstDate([
            $trip['actual_end_date'] ?? null,
            $trip['planned_finish_date'] ?? null,
            $trip['planned_start_date_to'] ?? null,
        ]);
        return [$start, $finish];
    }

    public static function suggestClientPackageDates(PDO $pdo, array $trip): array
    {
        [$start, $finish] = self::tripDocumentDates($trip);
        $invoiceDate = $finish ?? '';
        $businessId = (int)($trip['business_id'] ?? 0);
        try {
            $amount = self::sumPaymentRows(WasteTripService::financialCustomerPayments($pdo, $trip));
            if ($amount !== null) {
                $number = ClientPackageInvoiceNumberPolicy::number($businessId, $amount);
                $invoice = ClientPackageInvoiceNumberPolicy::linkedInvoice(
                    $pdo, $number, $amount, (int)($trip['id'] ?? 0), (int)($trip['client_id'] ?? 0)
                );
                if ($invoice !== null) $invoiceDate = (string)$invoice['invoice_date'];
            }
        } catch (RuntimeException) {
            // The preview reports missing historical links or amount conflicts explicitly.
        }
        return ['contract_date'=>$start ?? '', 'invoice_date'=>$invoiceDate, 'act_date'=>$finish ?? ''];
    }

    private static function firstDate(array $values): ?string
    {
        foreach ($values as $value) {
            $date = trim((string)$value);
            if (self::validIsoDate($date)) return $date;
        }
        return null;
    }

    private static function validIsoDate(string $date): bool
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m)) return false;
        return checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
    }

    private static function tripPeriodLabel(array $trip): string
    {
        [$start, $finish] = self::tripDocumentDates($trip);
        if ($start === null && $finish === null) return 'даты не заданы';
        if ($start !== null && $finish !== null) return self::formatDateSimple($start) . '–' . self::formatDateSimple($finish);
        return self::formatDateSimple($start ?? $finish ?? '');
    }

    private static function sumPaymentRows(array $payments): ?string
    {
        $cents = 0;
        $seen = false;
        foreach ($payments as $payment) {
            $amount = LinearRouteService::parseAmount((string)($payment['amount'] ?? ''));
            if ($amount === null) continue;
            $seen = true;
            $cents += self::docgenAmountToCents($amount);
        }
        if (!$seen) return null;
        return intdiv($cents,100) . '.' . str_pad((string)($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    private static function docgenAmountToCents(string $amount): int
    {
        [$whole,$fraction] = array_pad(explode('.', str_replace(',','.',$amount), 2), 2, '00');
        return ((int)$whole * 100) + (int)str_pad(substr($fraction,0,2),2,'0');
    }

    private static function paymentTermsText(array $payments): string
    {
        $parts = [];
        foreach ($payments as $payment) {
            $condition = trim((string)($payment['condition_type'] ?? ''));
            $days = (int)($payment['days_count'] ?? 0);
            $kind = (string)($payment['days_kind'] ?? 'calendar');
            $specific = trim((string)($payment['specific_due_date'] ?? ''));
            $text = match ($condition) {
                DateCalculationService::CONDITION_PREPAYMENT => $specific !== '' ? 'Предоплата до ' . self::formatDateSimple($specific) : 'Предоплата',
                DateCalculationService::CONDITION_START_DAY => 'Оплата на загрузке',
                DateCalculationService::CONDITION_AFTER_START => 'Оплата в течение ' . $days . ' ' . ($kind === 'working' ? 'рабочих' : 'календарных') . ' дней после загрузки',
                DateCalculationService::CONDITION_END_DAY => 'Оплата на выгрузке',
                DateCalculationService::CONDITION_AFTER_END => 'Оплата в течение ' . $days . ' ' . ($kind === 'working' ? 'рабочих' : 'календарных') . ' дней после выгрузки',
                DateCalculationService::CONDITION_AFTER_DOCUMENTS => 'Оплата в течение ' . $days . ' ' . ($kind === 'working' ? 'рабочих' : 'календарных') . ' дней после получения закрывающих документов',
                DateCalculationService::CONDITION_SPECIFIC_DATE => $specific !== '' ? 'Оплата до ' . self::formatDateSimple($specific) : 'Оплата в согласованную дату',
                default => '',
            };
            $comment = trim((string)($payment['condition_comment'] ?? ''));
            if ($text !== '' && $comment !== '') $text .= ' (' . $comment . ')';
            if ($text !== '') $parts[] = $text;
        }
        return implode('; ', $parts);
    }

    private static function clientServiceDescription(array $snapshot, string $contractNumber, string $contractDate): string
    {
        $start = self::formatDateSimple((string)($snapshot['parameters']['start_date'] ?? ''));
        $finish = self::formatDateSimple((string)($snapshot['parameters']['finish_date'] ?? ''));
        $vehicle = is_array($snapshot['vehicle'] ?? null) ? $snapshot['vehicle'] : [];
        $trailer = is_array($snapshot['trailer'] ?? null) ? $snapshot['trailer'] : [];
        $driver = trim((string)($snapshot['driver']['full_name'] ?? ''));
        $parts = [];
        $primary = trim((string)($vehicle['model'] ?? ''));
        $plate = trim((string)($vehicle['plate'] ?? ''));
        if ($primary !== '') $parts[] = mb_strtolower((string)($vehicle['label'] ?? 'ТС'), 'UTF-8') . ' ' . $primary . ($plate !== '' ? ', гос. № ' . $plate : '');
        if ((int)($trailer['id'] ?? 0) > 0) {
            $secondary = trim((string)($trailer['model'] ?? ''));
            $secondaryPlate = trim((string)($trailer['plate'] ?? ''));
            if ($secondary !== '') $parts[] = mb_strtolower((string)($trailer['label'] ?? 'прицеп'), 'UTF-8') . ' ' . $secondary . ($secondaryPlate !== '' ? ', гос. № ' . $secondaryPlate : '');
        }
        if ($driver !== '') $parts[] = 'водитель ' . $driver;
        $period = $start !== '' && $finish !== '' ? $start . '–' . $finish : trim($start . $finish);
        return 'Субаренда транспортного средства с экипажем'
            . ($period !== '' ? ' за период ' . $period : '')
            . ($parts !== [] ? ': ' . implode('; ', $parts) : '')
            . '; по договору субаренды № ' . $contractNumber . ' от ' . self::formatDateSimple($contractDate);
    }

    private static function clientRouteDescription(array $snapshot): string
    {
        $loading = [];
        foreach ((array)($snapshot['requests'] ?? []) as $request) {
            $address = trim((string)($request['document_loading_address'] ?? $request['mno_adres_pogruzki'] ?? ''));
            if ($address !== '') $loading[$address] = true;
        }
        $unloading = trim((string)($snapshot['requests_summary']['unload_address'] ?? ''));
        return 'Место загрузки: '.implode('; ', array_keys($loading)).'. Место выгрузки: '.$unloading.'.';
    }
}
