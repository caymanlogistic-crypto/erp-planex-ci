<?php

namespace App\Service;

use PDO;
use RuntimeException;
use Throwable;

trait DocumentGeneratorTripPackageTrait
{
    private const CLIENT_PACKAGE_TYPES = [
        'client_sublease_crew' => 'Договор субаренды транспортного средства с экипажем',
        'client_invoice' => 'Счёт клиенту',
        'client_act' => 'Акт клиенту',
    ];

    public static function canGenerateClientPackage(string $role): bool
    {
        return $role === 'company_owner' || AccessControlService::isEffectiveSystemOwner();
    }

    public static function listDocumentTrips(PDO $pdo, bool $includeFinance = false): array
    {
        $trips = WasteTripService::fetchTrips($pdo, [], $includeFinance);
        $out = [];
        foreach ($trips as $trip) {
            if ((string)($trip['status'] ?? '') === WasteTripService::STATUS_CANCELLED) continue;
            $trip['_document_ready'] = (int)($trip['route_executor_id'] ?? 0) > 0
                && (int)($trip['request_count'] ?? 0) > 0;
            $trip['_document_period'] = self::tripPeriodLabel($trip);
            $trip['_customer_amount'] = $includeFinance
                ? self::sumPaymentRows(WasteTripService::financialCustomerPayments($pdo, $trip))
                : null;
            $out[] = $trip;
        }
        return $out;
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
            ['trip'=>self::tripSnapshot($trip)]
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
        string $packageDate,
        ?GoogleSheetsRequestContactService $contactService = null
    ): array {
        if (!self::canGenerateClientPackage($role)) {
            return self::failed(['Комплект документов клиенту доступен только руководителю компании.']);
        }
        if (!self::validIsoDate($packageDate)) {
            return self::failed(['Укажите корректную дату комплекта документов.']);
        }

        try {
            $trip = self::loadDocumentTrip($pdo, $tripId, true);
            if ((int)($trip['client_id'] ?? 0) !== $clientId || $clientId <= 0) {
                throw new RuntimeException('Выбранный клиент не соответствует заказчику выбранного рейса.');
            }
            $client = self::loadClientForDocument($pdo, $clientId, $userId, $role);
            $templates = [];
            foreach (array_keys(self::CLIENT_PACKAGE_TYPES) as $type) {
                $templates[$type] = self::getUniqueActiveTemplateByType($pdo, $type);
            }
        } catch (RuntimeException $e) {
            return self::failed([$e->getMessage()]);
        }

        $requestIds = self::tripRequestIds($trip);
        if ($requestIds === []) return self::failed(['В выбранном рейсе нет заявок ФЭО.']);
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

        $packageNumber = date('y') . '-' . (int)($trip['business_id'] ?? 0);
        $paymentTerms = self::paymentTermsText($payments);
        if ($paymentTerms === '') $paymentTerms = 'Согласно условиям выбранного рейса.';
        $context = [
            'client' => self::clientSnapshot($client),
            'trip' => self::tripSnapshot($trip),
            'package' => [
                'number' => $packageNumber,
                'date' => $packageDate,
                'amount' => $amount,
                'vat_mode' => 'Без НДС',
            ],
        ];
        $params = [
            'contract_number' => $packageNumber,
            'contract_date' => $packageDate,
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
            (int)$templates['client_sublease_crew']['template_id'],
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
            'description' => self::clientServiceDescription($snapshot, $packageNumber, $packageDate),
            'amount' => $amount,
            'vat_mode' => 'Без НДС',
        ];
        $prepared['snapshot'] = $snapshot;
        $prepared['values'] = self::buildFixedValues($snapshot);

        $extraErrors = [];
        foreach (['client_invoice','client_act'] as $type) {
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
        $prepared['package_date'] = $packageDate;
        $prepared['package_amount'] = $amount;
        $prepared['package_amount_formatted'] = self::formatMoneyText($amount);
        $prepared['package_templates'] = $templates;
        $prepared['client'] = $client;
        $prepared['trip'] = $trip;
        return $prepared;
    }

    /** @return array<string,int> */
    public static function generateClientPackage(PDO $pdo, int $companyId, int $userId, string $role, array $prepared): array
    {
        if (!($prepared['ok'] ?? false)) throw new RuntimeException('Комплект нельзя сформировать до устранения ошибок проверки.');
        if (!self::canGenerateClientPackage($role)) throw new RuntimeException('Комплект документов клиенту доступен только руководителю компании.');
        if (!self::runtimeSchemaReady($pdo)) throw new RuntimeException('Схема генератора документов не готова.');

        $number = trim((string)($prepared['package_number'] ?? ''));
        $date = trim((string)($prepared['package_date'] ?? ''));
        if (!preg_match('/^\d{2}-\d+$/', $number) || !self::validIsoDate($date)) {
            throw new RuntimeException('Номер или дата комплекта документов не определены.');
        }
        $templates = is_array($prepared['package_templates'] ?? null) ? $prepared['package_templates'] : [];
        foreach (array_keys(self::CLIENT_PACKAGE_TYPES) as $type) {
            if (!is_array($templates[$type] ?? null)) throw new RuntimeException('Шаблон комплекта не определён: ' . $type . '.');
        }
        $baseSnapshot = is_array($prepared['snapshot'] ?? null) ? $prepared['snapshot'] : null;
        if (!is_array($baseSnapshot)) throw new RuntimeException('Snapshot комплекта документов не определён.');

        $resolvedTemplates = [];
        foreach ($templates as $type => $template) {
            if (!isset(self::CLIENT_PACKAGE_TYPES[$type])) continue;
            $path = DocumentService::resolveStoredPath($companyId, (string)($template['stored_path'] ?? ''));
            if ($path === null) throw new RuntimeException('Файл шаблона недоступен: ' . self::CLIENT_PACKAGE_TYPES[$type] . '.');
            $actual = hash_file('sha256', $path) ?: '';
            if ($actual === '' || !hash_equals((string)($template['sha256'] ?? ''), $actual)) {
                throw new RuntimeException('Контрольная сумма шаблона не совпадает: ' . self::CLIENT_PACKAGE_TYPES[$type] . '.');
            }
            $resolvedTemplates[$type] = [$template, $path];
        }

        $createdFiles = [];
        $ids = [];
        $pdo->beginTransaction();
        try {
            foreach (array_keys(self::CLIENT_PACKAGE_TYPES) as $type) {
                $dup = $pdo->prepare('SELECT id FROM generated_documents WHERE document_type=? AND document_number=? LIMIT 1 FOR UPDATE');
                $dup->execute([$type, $number]);
                if ($dup->fetchColumn() !== false) {
                    throw new RuntimeException('Документ ' . self::CLIENT_PACKAGE_TYPES[$type] . ' № ' . $number . ' уже сформирован.');
                }
            }

            foreach (array_keys(self::CLIENT_PACKAGE_TYPES) as $type) {
                [$template, $templatePath] = $resolvedTemplates[$type];
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
                    'number'=>$number,
                    'number_mode'=>'trip_based',
                    'date'=>$date,
                    'type'=>$type,
                ];
                $values = self::buildFixedValues($snapshot);

                $baseName = match ($type) {
                    'client_sublease_crew' => 'Договор-субаренды',
                    'client_invoice' => 'Счет',
                    'client_act' => 'Акт',
                    default => 'Документ',
                };
                $downloadName = DocumentService::safeDownloadName($baseName . '_' . $number . '.docx');
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
                    $type,$number,$date,(int)$template['template_id'],(int)$template['version_id'],
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
            $pdo->commit();
            return $ids;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            foreach ($createdFiles as $file) @unlink($file);
            throw $e;
        }
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

    private static function loadClientForDocument(PDO $pdo, int $clientId, int $userId, string $role): array
    {
        if (!LinearRouteService::isVisibleEntity($pdo, 'clients', $clientId, ['user_id'=>$userId,'role_code'=>$role], 'client')) {
            throw new RuntimeException('Клиент недоступен или неактивен.');
        }
        $stmt = $pdo->prepare("SELECT id,name,inn,kpp,ogrn,legal_address,physical_address,contact_phone,contact_email,director_full_name,director_position,bank_account,bank_name,bank_bik,bank_corr_account FROM clients WHERE id=? AND status='active' AND deleted_at IS NULL LIMIT 1");
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
}
