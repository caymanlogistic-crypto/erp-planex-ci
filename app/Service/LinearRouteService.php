<?php

namespace App\Service;

use PDO;

final class LinearRouteService
{
    public const ROUTE_TYPE_LINEAR = 'linear';
    public const ROUTE_TYPE_AGENCY = 'agency';

    public const PAYMENT_TYPES = [
        'Без НДС',
        'Нал',
        'НДС 0%',
        'НДС 5%',
        'НДС 7%',
        'НДС 20%',
        'НДС 22%',
    ];

    public const PAYMENT_METHODS = [
        'cashless' => 'Безнал',
        'cash' => 'Нал',
    ];

    public const VAT_RATES = [
        '' => 'Без НДС',
        '0' => 'НДС 0%',
        '5' => 'НДС 5%',
        '7' => 'НДС 7%',
        '20' => 'НДС 20%',
        '22' => 'НДС 22%',
    ];

    public const PAYMENT_DUE_TYPES = [
        'Предоплата на загрузке',
        'После загрузки',
        'До выгрузки',
        'После выгрузки',
    ];

    public const CONDITION_TYPES = [
        DateCalculationService::CONDITION_PREPAYMENT,
        DateCalculationService::CONDITION_START_DAY,
        DateCalculationService::CONDITION_AFTER_START,
        DateCalculationService::CONDITION_END_DAY,
        DateCalculationService::CONDITION_AFTER_END,
        DateCalculationService::CONDITION_AFTER_DOCUMENTS,
        DateCalculationService::CONDITION_SPECIFIC_DATE,
    ];

    public const PAYMENT_DUE_DAYS_KINDS = [
        'working' => 'Рабочие дни',
        'calendar' => 'Календарные дни',
    ];

    public const PARTY_LABELS = [
        'customer' => 'Заказчик',
        'carrier' => 'Перевозчик',
        'principal' => 'Принципал',
    ];

    private static array $tableExistsCache = [];

    public static function normalizeCargoTypeName(string $name): string
    {
        return trim(preg_replace('/\s+/u', ' ', $name));
    }

    public static function normalizeCargoTypeKey(string $name): string
    {
        return mb_strtolower(self::normalizeCargoTypeName($name), 'UTF-8');
    }

    public static function parseAmount(string $value): ?string
    {
        $value = trim(str_replace(["\xc2\xa0", ' '], '', $value));
        if ($value === '') {
            return null;
        }

        $clean = str_replace(',', '.', $value);
        if (!preg_match('/^\d+(\.\d{1,2})?$/', $clean)) {
            return null;
        }

        if (strpos($clean, '.') === false) {
            return $clean . '.00';
        }
        $parts = explode('.', $clean);
        if (strlen($parts[1]) === 1) {
            return $clean . '0';
        }
        return $clean;
    }

    public static function parseDecimal(string $value): ?string
    {
        return self::parseAmount($value);
    }

    public static function formatAmount(?string $value, int $decimals = 2): string
    {
        if ($value === null || $value === '' || $value === '0' || $value === '0.00') {
            return $decimals === 0 ? '0' : '0.00';
        }

        $clean = str_replace([' ', ','], ['', '.'], trim($value));
        if (!preg_match('/^\d+(\.\d{1,2})?$/', $clean)) {
            return $decimals === 0 ? '0' : '0.00';
        }

        $parts = explode('.', $clean);
        $intPart = $parts[0];
        $decPart = isset($parts[1]) ? str_pad($parts[1], 2, '0') : '00';
        if ($decimals === 0) {
            return $intPart;
        }

        $intPart = preg_replace('/(\d)(?=(\d{3})+(?!\d))/', '$1 ', $intPart);
        return $intPart . ',' . substr($decPart, 0, $decimals);
    }

    public static function shouldShowPlannedUnloading(?string $plannedLoadingDate, ?string $plannedUnloadingDate): bool
    {
        $plannedLoadingDate = trim((string) $plannedLoadingDate);
        $plannedUnloadingDate = trim((string) $plannedUnloadingDate);

        if ($plannedUnloadingDate === '') {
            return false;
        }

        return $plannedUnloadingDate !== $plannedLoadingDate;
    }

    public static function normalizeDate(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $value;
        }

        if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $value, $matches)) {
            return $matches[3] . '-' . $matches[2] . '-' . $matches[1];
        }

        return null;
    }

    public static function routeTypeLabel(string $routeType): string
    {
        return $routeType === self::ROUTE_TYPE_AGENCY ? 'Агентский договор' : 'Линейная перевозка';
    }

    public static function partyLabel(string $partyRole): string
    {
        return self::PARTY_LABELS[$partyRole] ?? $partyRole;
    }

    public static function legacyPaymentTypeFromMethodAndVat(?string $paymentMethod, ?string $vatRate): string
    {
        if ($paymentMethod === 'cash') {
            return 'Нал';
        }

        $rate = trim((string) $vatRate);
        if ($rate === '') {
            return 'Без НДС';
        }

        if (in_array($rate, ['0', '5', '7', '20', '22'], true)) {
            return 'НДС ' . ((int) $rate) . '%';
        }

        return 'Без НДС';
    }

    public static function parsePaymentMethodFromLegacyType(string $paymentType): string
    {
        $paymentType = trim($paymentType);
        if ($paymentType === 'Нал') {
            return 'cash';
        }

        return 'cashless';
    }

    public static function parseVatRateFromLegacyType(string $paymentType): ?string
    {
        $paymentType = trim($paymentType);
        if ($paymentType === 'Нал' || $paymentType === 'Без НДС' || $paymentType === '') {
            return null;
        }

        if (preg_match('/^НДС\s+(\d+(?:\.\d+)?)%$/', $paymentType, $m)) {
            return $m[1];
        }

        return null;
    }

    public static function paymentMethodLabel(?string $paymentMethod): string
    {
        return self::PAYMENT_METHODS[$paymentMethod ?? ''] ?? '—';
    }

    public static function vatRateLabel(?string $vatRate): string
    {
        $rate = trim((string) $vatRate);
        if ($rate === '') {
            return 'Без НДС';
        }

        if (in_array($rate, ['0', '5', '7', '20', '22'], true)) {
            return 'НДС ' . ((int) $rate) . '%';
        }

        return 'Без НДС';
    }

    public static function computeCalculatedDueDate(
        ?string $paymentDueType,
        ?int $paymentDueDays,
        ?string $plannedLoadingDate,
        ?string $plannedUnloadingDate
    ): ?string {
        $dueType = trim((string) $paymentDueType);
        $baseDate = null;

        if ($dueType === 'Предоплата на загрузке') {
            $baseDate = $plannedLoadingDate;
        } elseif ($dueType === 'После загрузки') {
            $baseDate = $plannedLoadingDate;
        } elseif ($dueType === 'До выгрузки') {
            $baseDate = $plannedUnloadingDate;
        } elseif ($dueType === 'После выгрузки') {
            $baseDate = $plannedUnloadingDate;
        }

        if ($baseDate === null) {
            return null;
        }

        $days = $paymentDueDays ?? 0;
        if ($days === 0) {
            return $baseDate;
        }

        $ts = strtotime($baseDate);
        if ($ts === false) {
            return null;
        }

        if ($dueType === 'Предоплата на загрузке' || $dueType === 'До выгрузки') {
            $ts = strtotime("-{$days} days", $ts);
        } else {
            $ts = strtotime("+{$days} days", $ts);
        }

        return $ts !== false ? date('Y-m-d', $ts) : null;
    }

    public static function computeCalculatedDueDateNew(
        ?string $conditionType,
        ?int $daysCount,
        string $daysKind,
        ?string $plannedStartDate,
        ?string $plannedEndDate,
        ?string $actualStartDate,
        ?string $actualEndDate,
        ?string $closingDocumentsReceivedDate,
        ?string $specificDueDate
    ): ?string {
        $forecast = DateCalculationService::calculateForecastDueDate(
            $conditionType,
            $plannedStartDate,
            $plannedEndDate,
            $daysCount,
            $daysKind,
            $specificDueDate
        );

        $final = DateCalculationService::calculateFinalDueDate(
            $conditionType,
            $actualStartDate,
            $actualEndDate,
            $closingDocumentsReceivedDate,
            $daysCount,
            $daysKind,
            $specificDueDate
        );

        return $final ?? $forecast;
    }

    public static function computeForecastDueDate(
        ?string $conditionType,
        ?int $daysCount,
        string $daysKind,
        ?string $plannedStartDate,
        ?string $plannedEndDate,
        ?string $specificDueDate
    ): ?string {
        return DateCalculationService::calculateForecastDueDate(
            $conditionType,
            $plannedStartDate,
            $plannedEndDate,
            $daysCount,
            $daysKind,
            $specificDueDate
        );
    }

    public static function computePaymentStatus(
        ?string $amount,
        ?string $paidAmount,
        ?string $calculatedDueDate
    ): string {
        $amountCents = self::amountToCents($amount ?? '0');
        $paidCents = self::amountToCents($paidAmount ?? '0');

        if ($amountCents > 0 && $paidCents >= $amountCents) {
            return 'paid';
        }

        if ($paidCents > 0 && $paidCents < $amountCents) {
            $today = date('Y-m-d');
            if ($calculatedDueDate !== null && $calculatedDueDate < $today) {
                return 'overdue';
            }
            return 'partial';
        }

        $today = date('Y-m-d');
        if ($calculatedDueDate !== null && $calculatedDueDate < $today) {
            return 'overdue';
        }

        return 'unpaid';
    }

    public static function computePaymentStatusNew(
        ?string $amount,
        ?string $paidAmount,
        ?string $calculatedDueDate,
        ?string $cancelledAt = null,
        ?string $closingDocumentsReceivedDate = null,
        ?string $conditionType = null
    ): string {
        return RoutePaymentStatusService::computeStatus(
            $amount,
            $paidAmount,
            $calculatedDueDate,
            $cancelledAt,
            $closingDocumentsReceivedDate,
            $conditionType
        );
    }

    private static function amountToCents(string $value): int
    {
        if ($value === '' || $value === '0' || $value === '0.00') {
            return 0;
        }
        $parts = explode('.', $value, 2);
        $intPart = ltrim($parts[0], '0') ?: '0';
        $decPart = isset($parts[1]) ? str_pad(substr($parts[1] . '00', 0, 2), 2, '0') : '00';
        return (int) $intPart * 100 + (int) $decPart;
    }

    public static function paymentStatusLabel(string $status): string
    {
        $labels = [
            'paid' => 'Оплачен',
            'partial' => 'Частично',
            'overdue' => 'Просрочен',
            'unpaid' => 'Не оплачен',
            RoutePaymentStatusService::STATUS_PAID => 'Оплачен',
            RoutePaymentStatusService::STATUS_PARTIALLY_PAID => 'Частично оплачен',
            RoutePaymentStatusService::STATUS_OVERDUE => 'Просрочен',
            RoutePaymentStatusService::STATUS_PLANNED => 'Запланирован',
            RoutePaymentStatusService::STATUS_WAITING_EVENT => 'Ожидается событие',
            RoutePaymentStatusService::STATUS_CANCELLED => 'Отменён',
        ];

        return $labels[$status] ?? $status;
    }

    public static function paymentStatusBadgeClass(string $status): string
    {
        $classes = [
            'paid' => 'badge badge-ok',
            'partial' => 'badge badge-warning',
            'overdue' => 'badge badge-danger',
            'unpaid' => 'badge badge-neutral',
            RoutePaymentStatusService::STATUS_PAID => 'badge badge-ok',
            RoutePaymentStatusService::STATUS_PARTIALLY_PAID => 'badge badge-warning',
            RoutePaymentStatusService::STATUS_OVERDUE => 'badge badge-danger',
            RoutePaymentStatusService::STATUS_PLANNED => 'badge badge-neutral',
            RoutePaymentStatusService::STATUS_WAITING_EVENT => 'badge badge-neutral',
            RoutePaymentStatusService::STATUS_CANCELLED => 'badge badge-neutral',
        ];

        return $classes[$status] ?? 'badge badge-neutral';
    }

    public static function legacyPaymentDueTypeToConditionType(?string $paymentDueType): ?string
    {
        $map = [
            'После загрузки' => DateCalculationService::CONDITION_AFTER_START,
            'После выгрузки' => DateCalculationService::CONDITION_AFTER_END,
        ];

        return $map[trim((string) $paymentDueType)] ?? null;
    }

    public static function conditionTypeLabel(?string $conditionType): string
    {
        return DateCalculationService::CONDITION_LABELS[$conditionType ?? ''] ?? '—';
    }

    public static function logFinanceAudit(
        PDO $localPdo,
        string $entityType,
        int $entityId,
        string $action,
        ?array $oldValues,
        ?array $newValues,
        int $userId,
        string $roleCode
    ): void {
        if (!self::tableExists($localPdo, 'finance_audit_log')) {
            return;
        }

        FinanceAuditLogService::log($localPdo, $entityType, $entityId, $action, $oldValues, $newValues, $userId, $roleCode);
    }

    public static function normalizePrincipalType(?string $value): ?string
    {
        $value = trim((string) $value);

        return in_array($value, ['client', 'contractor'], true) ? $value : null;
    }

    public static function normalizeAgencyContractWith(?string $value): ?string
    {
        $value = trim((string) $value);

        return in_array($value, ['client', 'carrier'], true) ? $value : null;
    }

    public static function paymentDueTypeRequiresDays(string $paymentDueType): bool
    {
        return in_array($paymentDueType, ['После загрузки', 'После выгрузки'], true);
    }

    public static function conditionTypeRequiresDays(?string $conditionType): bool
    {
        return in_array($conditionType, [
            DateCalculationService::CONDITION_AFTER_START,
            DateCalculationService::CONDITION_AFTER_END,
            DateCalculationService::CONDITION_AFTER_DOCUMENTS,
        ], true);
    }

    public static function conditionTypeRequiresSpecificDate(?string $conditionType): bool
    {
        return in_array($conditionType, [
            DateCalculationService::CONDITION_PREPAYMENT,
            DateCalculationService::CONDITION_SPECIFIC_DATE,
        ], true);
    }

    public static function conditionTypeIsEventDay(?string $conditionType): bool
    {
        return in_array($conditionType, [
            DateCalculationService::CONDITION_START_DAY,
            DateCalculationService::CONDITION_END_DAY,
            DateCalculationService::CONDITION_PREPAYMENT,
        ], true);
    }

    public static function conditionTypeShowDays(?string $conditionType): bool
    {
        return self::conditionTypeRequiresDays($conditionType);
    }

    public static function conditionTypeShowSpecificDate(?string $conditionType): bool
    {
        return self::conditionTypeRequiresSpecificDate($conditionType);
    }

    public static function parsePrincipalEntityKey(?string $value): ?array
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        if (!preg_match('/^(client|contractor):(\d+)$/', $value, $matches)) {
            return null;
        }

        return [
            'principal_type' => $matches[1],
            'principal_id' => (int) $matches[2],
        ];
    }

    public static function principalEntityKey(string $principalType, int $principalId): string
    {
        return $principalType . ':' . $principalId;
    }

    public static function principalContractSide(string $principalType, int $principalId, int $clientId, int $carrierId): ?string
    {
        if ($principalType === 'client' && $principalId === $clientId) {
            return 'client';
        }
        if ($principalType === 'contractor' && $principalId === $carrierId) {
            return 'carrier';
        }

        return null;
    }

    public static function buildPrincipalEntityOptions(array $clients, array $contractors, int $clientId, int $carrierId): array
    {
        $options = [];

        foreach ($clients as $client) {
            if ((int) ($client['id'] ?? 0) !== $clientId) {
                continue;
            }
            $options[] = [
                'key' => self::principalEntityKey('client', $clientId),
                'label' => 'Заказчик: ' . (string) ($client['name'] ?? '—'),
                'principal_type' => 'client',
                'principal_id' => $clientId,
                'contract_side' => 'client',
            ];
            break;
        }

        foreach ($contractors as $contractor) {
            if ((int) ($contractor['id'] ?? 0) !== $carrierId) {
                continue;
            }
            $options[] = [
                'key' => self::principalEntityKey('contractor', $carrierId),
                'label' => 'Перевозчик: ' . (string) ($contractor['name'] ?? '—'),
                'principal_type' => 'contractor',
                'principal_id' => $carrierId,
                'contract_side' => 'carrier',
            ];
            break;
        }

        return $options;
    }

    public static function isVisibleEntity(PDO $localPdo, string $table, int $entityId, array $user, string $entityType): bool
    {
        if ($entityId <= 0) {
            return false;
        }

        $activeClause = "status = 'active'";
        if ($table === 'clients') {
            $activeClause .= ' AND deleted_at IS NULL';
        }

        $access = RegulatedDataScopeService::condition($entityType, "{$table}.id");
        $stmt = $localPdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE id = ? AND {$activeClause} AND {$access['sql']}");
        $stmt->execute(array_merge([$entityId], $access['params']));

        return (int) $stmt->fetchColumn() > 0;
    }

    public static function fetchVisibleClients(PDO $localPdo, array $user): array
    {
        $access = RegulatedDataScopeService::condition('client', 'clients.id');
        $stmt = $localPdo->prepare("SELECT id, name, inn FROM clients WHERE status = 'active' AND deleted_at IS NULL AND {$access['sql']} ORDER BY name");
        $stmt->execute($access['params']);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function fetchVisibleContractors(PDO $localPdo, array $user): array
    {
        $access = RegulatedDataScopeService::condition('contractor', 'contractors.id');
        $stmt = $localPdo->prepare("SELECT id, name, inn FROM contractors WHERE status = 'active' AND {$access['sql']} ORDER BY name");
        $stmt->execute($access['params']);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function fetchVisibleRouteExecutors(PDO $localPdo, array $user): array
    {
        $baseSql = "SELECT c.id,
                           c.created_by_user_id,
                           ct.id AS contractor_id,
                           ct.created_by_user_id AS contractor_created_by_user_id,
                           ct.name AS contractor_name,
                           d.id AS driver_id,
                           d.created_by_user_id AS driver_created_by_user_id,
                           d.full_name AS driver_name,
                           dvb.id AS driver_vehicle_block_id,
                           dvb.created_by_user_id AS dvb_created_by_user_id,
                           vs.id AS vehicle_set_id,
                           vs.created_by_user_id AS vehicle_set_created_by_user_id,
                           vs.set_type,
                           vu1.plate_number AS primary_plate,
                           vu2.plate_number AS secondary_plate
                      FROM crews c
                      JOIN contractors ct ON c.contractor_id = ct.id
                      JOIN driver_vehicle_blocks dvb ON c.driver_vehicle_block_id = dvb.id
                      JOIN drivers d ON dvb.driver_id = d.id
                      JOIN vehicle_sets vs ON vs.id = dvb.vehicle_set_id
                 LEFT JOIN vehicle_units vu1 ON vu1.id = vs.primary_vehicle_unit_id
                 LEFT JOIN vehicle_units vu2 ON vu2.id = vs.secondary_vehicle_unit_id
                     WHERE c.status = 'active'";

        $contractor = RegulatedDataScopeService::condition('contractor', 'ct.id');
        $driver = RegulatedDataScopeService::condition('driver', 'd.id');
        $primaryVehicle = RegulatedDataScopeService::condition('vehicle_unit', 'vu1.id');
        $secondaryVehicle = RegulatedDataScopeService::condition('vehicle_unit', 'vu2.id');
        $stmt = $localPdo->prepare($baseSql
            . " AND {$contractor['sql']} AND {$driver['sql']}"
            . " AND (vu1.id IS NULL OR {$primaryVehicle['sql']})"
            . " AND (vu2.id IS NULL OR {$secondaryVehicle['sql']})"
            . ' ORDER BY c.created_at DESC');
        $stmt->execute(array_merge($contractor['params'], $driver['params'], $primaryVehicle['params'], $secondaryVehicle['params']));

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function createOrFindCargoType(PDO $localPdo, string $name, int $userId, string $roleCode): int
    {
        $name = self::normalizeCargoTypeName($name);
        $normalized = self::normalizeCargoTypeKey($name);

        $lookup = $localPdo->prepare('SELECT id FROM cargo_types WHERE normalized_name = ? LIMIT 1');
        $lookup->execute([$normalized]);
        $existingId = (int) $lookup->fetchColumn();

        if ($existingId > 0) {
            $localPdo->prepare('UPDATE cargo_types SET usage_count = usage_count + 1, updated_by_user_id = ?, updated_by_role = ? WHERE id = ?')
                ->execute([$userId, $roleCode, $existingId]);

            return $existingId;
        }

        $insert = $localPdo->prepare(
            "INSERT INTO cargo_types (
                name,
                normalized_name,
                usage_count,
                status,
                created_by_user_id,
                created_by_role,
                updated_by_user_id,
                updated_by_role
            ) VALUES (?, ?, 1, 'active', ?, ?, ?, ?)"
        );
        $insert->execute([$name, $normalized, $userId, $roleCode, $userId, $roleCode]);

        return (int) $localPdo->lastInsertId();
    }

    public static function cargoTypeSuggestions(PDO $localPdo, string $query): array
    {
        $query = self::normalizeCargoTypeName($query);
        if ($query === '') {
            return $localPdo->query("SELECT id, name FROM cargo_types WHERE status = 'active' ORDER BY usage_count DESC, name ASC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
        }

        $stmt = $localPdo->prepare(
            "SELECT id, name
               FROM cargo_types
              WHERE status = 'active'
                AND (
                    name LIKE :name_query
                    OR normalized_name LIKE :normalized_query
                )
              ORDER BY usage_count DESC, name ASC
              LIMIT 20"
        );
        $stmt->execute([
            ':name_query' => '%' . $query . '%',
            ':normalized_query' => '%' . self::normalizeCargoTypeKey($query) . '%',
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function routeDocumentDefinitions(string $routeType): array
    {
        $documents = [
            'customer_document' => [
                'name' => 'Договор/заявка с заказчиком',
                'code' => 'customer_contract_request',
            ],
            'carrier_document' => [
                'name' => 'Договор/заявка с перевозчиком',
                'code' => 'carrier_contract_request',
            ],
        ];

        if ($routeType === self::ROUTE_TYPE_AGENCY) {
            $documents['principal_document'] = [
                'name' => 'Договор/заявка с принципалом',
                'code' => 'principal_contract_request',
            ];
        }

        return $documents;
    }

    public static function fetchRouteById(PDO $localPdo, int $routeId, bool $includeFinance = true): ?array
    {
        if ($routeId <= 0) {
            return null;
        }

        $access = RegulatedDataScopeService::condition('linear_trip', 'lr.id', 'lr.created_by_membership_id');
        $stmt = $localPdo->prepare(
            "SELECT lr.*,
                    ct.name AS client_name,
                    ct.inn AS client_inn,
                    carrier.name AS carrier_name,
                    carrier.inn AS carrier_inn,
                    cargo.name AS cargo_type_name,
                    exec_c.id AS executor_id,
                    exec_ct.name AS executor_contractor_name,
                    exec_driver.full_name AS executor_driver_name,
                    exec_vs.set_type AS executor_set_type,
                    exec_vu1.plate_number AS executor_primary_plate,
                    exec_vu2.plate_number AS executor_secondary_plate
               FROM linear_routes lr
               JOIN clients ct ON ct.id = lr.client_id
               JOIN contractors carrier ON carrier.id = lr.carrier_contractor_id
               JOIN cargo_types cargo ON cargo.id = lr.cargo_type_id
          LEFT JOIN crews exec_c ON exec_c.id = lr.route_executor_id
          LEFT JOIN contractors exec_ct ON exec_ct.id = exec_c.contractor_id
          LEFT JOIN driver_vehicle_blocks exec_dvb ON exec_dvb.id = exec_c.driver_vehicle_block_id
          LEFT JOIN drivers exec_driver ON exec_driver.id = exec_dvb.driver_id
          LEFT JOIN vehicle_sets exec_vs ON exec_vs.id = exec_dvb.vehicle_set_id
          LEFT JOIN vehicle_units exec_vu1 ON exec_vu1.id = exec_vs.primary_vehicle_unit_id
          LEFT JOIN vehicle_units exec_vu2 ON exec_vu2.id = exec_vs.secondary_vehicle_unit_id
              WHERE lr.id = ?
                AND lr.deleted_at IS NULL
                AND {$access['sql']}
              LIMIT 1"
        );
        $stmt->execute(array_merge([$routeId], $access['params']));
        $route = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($route === null) {
            return null;
        }

        return self::decorateRoute($localPdo, $route, $includeFinance);
    }

    public static function fetchRoutePrincipals(PDO $localPdo, int $routeId): array
    {
        if (!self::tableExists($localPdo, 'linear_route_principals')) {
            return [];
        }

        $stmt = $localPdo->prepare(
            "SELECT lrp.*,
                    client.name AS client_name,
                    contractor.name AS contractor_name
               FROM linear_route_principals lrp
          LEFT JOIN clients client ON lrp.principal_type = 'client' AND client.id = lrp.principal_id
          LEFT JOIN contractors contractor ON lrp.principal_type = 'contractor' AND contractor.id = lrp.principal_id
              WHERE lrp.linear_route_id = ?
                AND lrp.deleted_at IS NULL
              ORDER BY lrp.sort_order ASC, lrp.id ASC"
        );
        $stmt->execute([$routeId]);
        $principals = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($principals as &$principal) {
            $principal['entity_key'] = self::principalEntityKey((string) $principal['principal_type'], (int) $principal['principal_id']);
            $principal['display_name'] = $principal['principal_type'] === 'client'
                ? (string) ($principal['client_name'] ?? '—')
                : (string) ($principal['contractor_name'] ?? '—');
        }
        unset($principal);

        return $principals;
    }

    public static function fetchRoutePayments(PDO $localPdo, int $routeId, ?array $routeDates = null): array
    {
        if (self::tableExists($localPdo, 'linear_route_payments')) {
            self::reconcileLegacyPaymentRows($localPdo, $routeId);

            $stmt = $localPdo->prepare(
                "SELECT *
                   FROM linear_route_payments
                  WHERE linear_route_id = ?
                    AND deleted_at IS NULL
                  ORDER BY
                        CASE party_role
                            WHEN 'customer' THEN 1
                            WHEN 'carrier' THEN 2
                            ELSE 3
                        END,
                        linear_route_principal_id ASC,
                        sort_order ASC,
                        id ASC"
            );
            $stmt->execute([$routeId]);

            $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $plannedLoadingDate = $routeDates['planned_loading_date'] ?? null;
            $plannedUnloadingDate = $routeDates['planned_unloading_date'] ?? null;
            $plannedStartDate = $routeDates['planned_start_date'] ?? $plannedLoadingDate;
            $plannedEndDate = $routeDates['planned_end_date'] ?? $plannedUnloadingDate;
            $actualStartDate = $routeDates['actual_start_date'] ?? $routeDates['actual_loading_date'] ?? null;
            $actualEndDate = $routeDates['actual_end_date'] ?? $routeDates['actual_unloading_date'] ?? null;
            $closingDocumentsReceivedDate = $routeDates['closing_documents_received_date'] ?? null;

            foreach ($payments as &$payment) {
                $payment['calculated_due_date'] = self::computeCalculatedDueDate(
                    $payment['payment_due_type'] ?? null,
                    isset($payment['payment_due_days']) ? (int) $payment['payment_due_days'] : null,
                    $plannedLoadingDate,
                    $plannedUnloadingDate
                );

                $conditionType = $payment['condition_type'] ?? self::legacyPaymentDueTypeToConditionType($payment['payment_due_type'] ?? null);

                $payment['condition_type'] = $conditionType;
                $payment['forecast_due_date'] = self::computeForecastDueDate(
                    $conditionType,
                    isset($payment['days_count']) ? (int) $payment['days_count'] : (isset($payment['payment_due_days']) ? (int) $payment['payment_due_days'] : null),
                    $payment['days_kind'] ?? 'calendar',
                    $plannedStartDate,
                    $plannedEndDate,
                    $payment['specific_due_date'] ?? null
                );

                $newCalculatedDueDate = self::computeCalculatedDueDateNew(
                    $conditionType,
                    isset($payment['days_count']) ? (int) $payment['days_count'] : (isset($payment['payment_due_days']) ? (int) $payment['payment_due_days'] : null),
                    $payment['days_kind'] ?? 'calendar',
                    $plannedStartDate,
                    $plannedEndDate,
                    $actualStartDate,
                    $actualEndDate,
                    $closingDocumentsReceivedDate,
                    $payment['specific_due_date'] ?? null
                );

                if ($newCalculatedDueDate !== null) {
                    $payment['calculated_due_date'] = $newCalculatedDueDate;
                }

                $payment['payment_status'] = self::computePaymentStatusNew(
                    $payment['amount'] ?? null,
                    $payment['paid_amount'] ?? null,
                    $payment['calculated_due_date'],
                    $payment['cancelled_at'] ?? null,
                    $closingDocumentsReceivedDate,
                    $conditionType
                );

                if ($payment['payment_status'] === RoutePaymentStatusService::STATUS_PLANNED) {
                    $legacyStatus = self::computePaymentStatus(
                        $payment['amount'] ?? null,
                        $payment['paid_amount'] ?? null,
                        $payment['calculated_due_date']
                    );
                    if ($legacyStatus === 'paid') {
                        $payment['payment_status'] = RoutePaymentStatusService::STATUS_PAID;
                    }
                }
            }
            unset($payment);

            return $payments;
        }

        $stmt = $localPdo->prepare(
            "SELECT *,
                    NULL AS linear_route_principal_id,
                    1 AS sort_order
               FROM linear_route_financial_terms
              WHERE linear_route_id = ?
                AND deleted_at IS NULL
              ORDER BY id ASC"
        );
        $stmt->execute([$routeId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function groupRoutePayments(array $payments): array
    {
        $grouped = [
            'customer' => [],
            'carrier' => [],
            'principals' => [],
        ];

        foreach ($payments as $payment) {
            $partyRole = (string) ($payment['party_role'] ?? '');
            if ($partyRole === 'customer' || $partyRole === 'carrier') {
                $grouped[$partyRole][] = $payment;
                continue;
            }

            $principalRowId = (int) ($payment['linear_route_principal_id'] ?? 0);
            if (!isset($grouped['principals'][$principalRowId])) {
                $grouped['principals'][$principalRowId] = [];
            }
            $grouped['principals'][$principalRowId][] = $payment;
        }

        return $grouped;
    }

    public static function fetchRouteTerms(PDO $localPdo, int $routeId): array
    {
        $payments = self::groupRoutePayments(self::fetchRoutePayments($localPdo, $routeId));

        $terms = [];
        if (!empty($payments['customer'][0])) {
            $terms['customer'] = $payments['customer'][0];
        }
        if (!empty($payments['carrier'][0])) {
            $terms['carrier'] = $payments['carrier'][0];
        }

        foreach ($payments['principals'] as $principalPayments) {
            if (!empty($principalPayments[0])) {
                $terms['principal'] = $principalPayments[0];
                break;
            }
        }

        return $terms;
    }

    public static function fetchRouteDocuments(PDO $localPdo, int $routeId): array
    {
        $stmt = $localPdo->prepare(
            "SELECT d.*, dt.name AS type_name, dt.code AS type_code
               FROM documents d
          LEFT JOIN document_types dt ON dt.id = d.document_type_id
              WHERE d.entity_type = 'linear_route'
                AND d.entity_id = ?
                AND d.deleted_at IS NULL
              ORDER BY d.id DESC"
        );
        $stmt->execute([$routeId]);
        $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $grouped = [
            'customer_document' => [],
            'carrier_document' => [],
            'principal_document' => [],
            'other' => [],
        ];

        foreach ($documents as $document) {
            $code = (string) ($document['type_code'] ?? '');
            if ($code === 'customer_contract_request') {
                $grouped['customer_document'][] = $document;
            } elseif ($code === 'carrier_contract_request') {
                $grouped['carrier_document'][] = $document;
            } elseif ($code === 'principal_contract_request') {
                $grouped['principal_document'][] = $document;
            } else {
                $grouped['other'][] = $document;
            }
        }

        return $grouped;
    }

    public static function decorateRoute(PDO $localPdo, array $route, bool $includeFinance = true): array
    {
        $routeId = (int) ($route['id'] ?? 0);
        $route['principal_items'] = self::fetchRoutePrincipals($localPdo, $routeId);

        if ($includeFinance) {
            $routeDates = [
                'planned_loading_date' => $route['planned_loading_date'] ?? null,
                'planned_unloading_date' => $route['planned_unloading_date'] ?? null,
                'planned_start_date' => $route['planned_loading_date'] ?? null,
                'planned_end_date' => $route['planned_unloading_date'] ?? null,
                'actual_start_date' => $route['actual_loading_date'] ?? $route['actual_start_date'] ?? null,
                'actual_end_date' => $route['actual_unloading_date'] ?? $route['actual_end_date'] ?? null,
                'closing_documents_received_date' => $route['closing_documents_received_date'] ?? null,
            ];
            $route['payments'] = self::groupRoutePayments(self::fetchRoutePayments($localPdo, $routeId, $routeDates));
        } else {
            $route['payments'] = [];
        }

        $route['principal_summary'] = array_map(
            static fn(array $principal): string => (string) ($principal['display_name'] ?? '—'),
            $route['principal_items']
        );

        return $route;
    }

    public static function fetchRoutesForList(PDO $localPdo, array $user, bool $includeFinance = true): array
    {
        $baseSql = "SELECT lr.*,
                           ct.name AS client_name,
                           carrier.name AS carrier_name,
                           cargo.name AS cargo_type_name,
                           exec_ct.name AS executor_contractor_name,
                           exec_driver.full_name AS executor_driver_name,
                           exec_vs.set_type AS executor_set_type,
                           exec_vu1.plate_number AS executor_primary_plate,
                           exec_vu2.plate_number AS executor_secondary_plate
                      FROM linear_routes lr
                      JOIN clients ct ON ct.id = lr.client_id
                      JOIN contractors carrier ON carrier.id = lr.carrier_contractor_id
                      JOIN cargo_types cargo ON cargo.id = lr.cargo_type_id
                 LEFT JOIN crews exec_c ON exec_c.id = lr.route_executor_id
                 LEFT JOIN contractors exec_ct ON exec_ct.id = exec_c.contractor_id
                 LEFT JOIN driver_vehicle_blocks exec_dvb ON exec_dvb.id = exec_c.driver_vehicle_block_id
                 LEFT JOIN drivers exec_driver ON exec_driver.id = exec_dvb.driver_id
                 LEFT JOIN vehicle_sets exec_vs ON exec_vs.id = exec_dvb.vehicle_set_id
                 LEFT JOIN vehicle_units exec_vu1 ON exec_vu1.id = exec_vs.primary_vehicle_unit_id
                 LEFT JOIN vehicle_units exec_vu2 ON exec_vu2.id = exec_vs.secondary_vehicle_unit_id
                     WHERE lr.deleted_at IS NULL";

        $access = RegulatedDataScopeService::condition('linear_trip', 'lr.id', 'lr.created_by_membership_id');
        $stmt = $localPdo->prepare($baseSql . " AND {$access['sql']} ORDER BY lr.created_at DESC");
        $stmt->execute($access['params']);
        $routes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($routes as &$route) {
            $route = self::decorateRoute($localPdo, $route, $includeFinance);
        }
        unset($route);

        return $routes;
    }

    public static function storeRoutePrincipals(
        PDO $localPdo,
        int $routeId,
        array $principalRows,
        int $clientId,
        int $carrierId,
        int $userId,
        string $roleCode
    ): array {
        if (!self::tableExists($localPdo, 'linear_route_principals')) {
            return [];
        }

        $localPdo->prepare(
            "UPDATE linear_route_principals
                SET deleted_at = NOW(),
                    deleted_by_user_id = ?,
                    deleted_by_role = ?
              WHERE linear_route_id = ?
                AND deleted_at IS NULL"
        )->execute([$userId, $roleCode, $routeId]);

        $insert = $localPdo->prepare(
            "INSERT INTO linear_route_principals (
                linear_route_id,
                principal_type,
                principal_id,
                sort_order,
                status,
                created_by_user_id,
                created_by_role,
                updated_by_user_id,
                updated_by_role
            ) VALUES (?, ?, ?, ?, 'active', ?, ?, ?, ?)"
        );

        $stored = [];
        foreach (array_values($principalRows) as $index => $principalRow) {
            $insert->execute([
                $routeId,
                $principalRow['principal_type'],
                (int) $principalRow['principal_id'],
                $index + 1,
                $userId,
                $roleCode,
                $userId,
                $roleCode,
            ]);

            $stored[] = [
                'id' => (int) $localPdo->lastInsertId(),
                'principal_type' => $principalRow['principal_type'],
                'principal_id' => (int) $principalRow['principal_id'],
                'entity_key' => self::principalEntityKey($principalRow['principal_type'], (int) $principalRow['principal_id']),
                'contract_side' => self::principalContractSide($principalRow['principal_type'], (int) $principalRow['principal_id'], $clientId, $carrierId),
            ];
        }

        return $stored;
    }

    public static function syncLegacyRoutePrincipalFields(
        PDO $localPdo,
        int $routeId,
        array $storedPrincipals,
        int $clientId,
        int $carrierId,
        int $userId,
        string $roleCode
    ): void {
        $firstPrincipal = $storedPrincipals[0] ?? null;
        $principalType = $firstPrincipal['principal_type'] ?? null;
        $principalId = isset($firstPrincipal['principal_id']) ? (int) $firstPrincipal['principal_id'] : null;
        $agencyContractWith = null;

        if ($principalType !== null && $principalId !== null) {
            $agencyContractWith = self::principalContractSide($principalType, $principalId, $clientId, $carrierId);
        }

        $localPdo->prepare(
            "UPDATE linear_routes
                SET principal_type = ?,
                    principal_id = ?,
                    agency_contract_with = ?,
                    updated_by_user_id = ?,
                    updated_by_role = ?
              WHERE id = ?"
        )->execute([
            $principalType,
            $principalId,
            $agencyContractWith,
            $userId,
            $roleCode,
            $routeId,
        ]);
    }

    public static function storeRoutePayments(
        PDO $localPdo,
        int $routeId,
        array $customerPayments,
        array $carrierPayments,
        array $principalPaymentsByKey,
        array $storedPrincipals,
        int $userId,
        string $roleCode,
        ?array $routeDates = null
    ): void {
        if (!self::tableExists($localPdo, 'linear_route_payments')) {
            return;
        }

        $localPdo->prepare(
            "UPDATE linear_route_payments
                SET deleted_at = NOW(),
                    deleted_by_user_id = ?,
                    deleted_by_role = ?
              WHERE linear_route_id = ?
                AND deleted_at IS NULL"
        )->execute([$userId, $roleCode, $routeId]);

        $plannedLoadingDate = $routeDates['planned_loading_date'] ?? null;
        $plannedUnloadingDate = $routeDates['planned_unloading_date'] ?? null;
        $plannedStartDate = $routeDates['planned_start_date'] ?? $plannedLoadingDate;
        $plannedEndDate = $routeDates['planned_end_date'] ?? $plannedUnloadingDate;

        $insert = $localPdo->prepare(
            "INSERT INTO linear_route_payments (
                linear_route_id,
                party_role,
                linear_route_principal_id,
                sort_order,
                amount,
                payment_type,
                payment_method,
                vat_rate,
                payment_due_type,
                payment_due_days,
                payment_due_days_kind,
                condition_type,
                days_count,
                days_kind,
                specific_due_date,
                condition_comment,
                side,
                forecast_due_date,
                calculated_due_date,
                payment_status,
                status_updated_at,
                created_by_user_id,
                created_by_role,
                updated_by_user_id,
                updated_by_role
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );

        $buildPaymentRow = static function (string $partyRole, ?int $principalId, int $sortOrder, array $payment) use ($plannedLoadingDate, $plannedUnloadingDate, $plannedStartDate, $plannedEndDate): array {
            $paymentMethod = $payment['payment_method'] ?? self::parsePaymentMethodFromLegacyType($payment['payment_type'] ?? '');
            $vatRate = array_key_exists('vat_rate', $payment) ? $payment['vat_rate'] : self::parseVatRateFromLegacyType($payment['payment_type'] ?? '');

            $calculatedDueDate = self::computeCalculatedDueDate(
                $payment['payment_due_type'] ?? null,
                isset($payment['payment_due_days']) ? (int) $payment['payment_due_days'] : null,
                $plannedLoadingDate,
                $plannedUnloadingDate
            );

            $conditionType = $payment['condition_type'] ?? self::legacyPaymentDueTypeToConditionType($payment['payment_due_type'] ?? null);
            $daysCount = array_key_exists('days_count', $payment) ? $payment['days_count'] : (isset($payment['payment_due_days']) ? (int) $payment['payment_due_days'] : null);
            $daysKind = $payment['days_kind'] ?? $payment['payment_due_days_kind'] ?? 'calendar';
            $specificDueDate = $payment['specific_due_date'] ?? null;
            $conditionComment = $payment['condition_comment'] ?? null;
            $side = $payment['side'] ?? null;

            $forecastDueDate = DateCalculationService::calculateForecastDueDate(
                $conditionType,
                $plannedStartDate,
                $plannedEndDate,
                $daysCount,
                $daysKind,
                $specificDueDate
            );

            if ($forecastDueDate !== null) {
                $calculatedDueDate = $forecastDueDate;
            }

            $paymentStatus = self::computePaymentStatusNew(
                $payment['amount'] ?? null,
                '0.00',
                $calculatedDueDate,
                null,
                null,
                $conditionType
            );

            $legacyPaymentStatus = self::computePaymentStatus(
                $payment['amount'] ?? null,
                '0.00',
                $calculatedDueDate
            );

            if ($legacyPaymentStatus === 'paid') {
                $paymentStatus = RoutePaymentStatusService::STATUS_PAID;
            }

            $statusUpdatedAt = $paymentStatus !== RoutePaymentStatusService::STATUS_PLANNED ? date('Y-m-d H:i:s') : null;

            return [$paymentMethod, $vatRate, $conditionType, $daysCount, $daysKind, $specificDueDate, $conditionComment, $side, $forecastDueDate, $calculatedDueDate, $paymentStatus, $statusUpdatedAt];
        };

        $paymentParams = static function (array $payment, array $computed, string $partyRole, ?int $principalId, int $sortOrder, int $userId, string $roleCode) use ($routeId): array {
            return [
                $routeId,
                $partyRole,
                $principalId,
                $sortOrder,
                $payment['amount'],
                $payment['payment_type'],
                $computed[0], $computed[1],
                $payment['payment_due_type'],
                $payment['payment_due_days'],
                $payment['payment_due_days_kind'],
                $computed[2], $computed[3], $computed[4], $computed[5], $computed[6], $computed[7],
                $computed[8], $computed[9],
                $computed[10], $computed[11],
                $userId,
                $roleCode,
                $userId,
                $roleCode,
            ];
        };

        foreach (array_values($customerPayments) as $index => $payment) {
            $computed = $buildPaymentRow('customer', null, $index + 1, $payment);
            $params = $paymentParams($payment, $computed, 'customer', null, $index + 1, $userId, $roleCode);
            $insert->execute($params);
        }

        foreach (array_values($carrierPayments) as $index => $payment) {
            $computed = $buildPaymentRow('carrier', null, $index + 1, $payment);
            $params = $paymentParams($payment, $computed, 'carrier', null, $index + 1, $userId, $roleCode);
            $insert->execute($params);
        }

        foreach ($storedPrincipals as $principal) {
            $principalKey = (string) ($principal['entity_key'] ?? '');
            $principalPayments = $principalPaymentsByKey[$principalKey] ?? [];
            foreach (array_values($principalPayments) as $index => $payment) {
                $computed = $buildPaymentRow('principal', (int) $principal['id'], $index + 1, $payment);
                $params = $paymentParams($payment, $computed, 'principal', (int) $principal['id'], $index + 1, $userId, $roleCode);
                $insert->execute($params);
            }
        }
    }

    public static function reconcileLegacyPaymentRows(PDO $localPdo, int $routeId): void
    {
        if (
            !self::tableExists($localPdo, 'linear_route_payments')
            || !self::tableExists($localPdo, 'linear_route_financial_terms')
        ) {
            return;
        }

        $termsStmt = $localPdo->prepare(
            "SELECT id, party_role
               FROM linear_route_financial_terms
              WHERE linear_route_id = ?
                AND deleted_at IS NULL
              ORDER BY
                    CASE party_role
                        WHEN 'customer' THEN 1
                        WHEN 'carrier' THEN 2
                        ELSE 3
                    END,
                    id ASC"
        );
        $termsStmt->execute([$routeId]);
        $terms = $termsStmt->fetchAll(PDO::FETCH_ASSOC);
        if ($terms === []) {
            return;
        }

        $paymentsStmt = $localPdo->prepare(
            "SELECT id, party_role, linear_route_principal_id, sort_order, legacy_financial_term_id
               FROM linear_route_payments
              WHERE linear_route_id = ?
                AND deleted_at IS NULL
              ORDER BY
                    CASE party_role
                        WHEN 'customer' THEN 1
                        WHEN 'carrier' THEN 2
                        ELSE 3
                    END,
                    linear_route_principal_id ASC,
                    sort_order ASC,
                    id ASC"
        );
        $paymentsStmt->execute([$routeId]);
        $payments = $paymentsStmt->fetchAll(PDO::FETCH_ASSOC);
        if ($payments === []) {
            return;
        }

        $paymentsByRole = [];
        foreach ($payments as $payment) {
            $paymentsByRole[(string) ($payment['party_role'] ?? '')][] = $payment;
        }

        $updateLegacyLink = $localPdo->prepare(
            'UPDATE linear_route_payments SET legacy_financial_term_id = ? WHERE id = ?'
        );
        $clearLegacyLink = $localPdo->prepare(
            'UPDATE linear_route_payments SET legacy_financial_term_id = NULL WHERE id = ?'
        );
        $releaseLegacyTerm = $localPdo->prepare(
            'UPDATE linear_route_payments SET legacy_financial_term_id = NULL WHERE legacy_financial_term_id = ? AND id <> ?'
        );
        $softDeleteDuplicate = $localPdo->prepare(
            "UPDATE linear_route_payments
                SET deleted_at = NOW()
              WHERE id = ?
                AND deleted_at IS NULL"
        );

        foreach ($terms as $term) {
            $partyRole = (string) ($term['party_role'] ?? '');
            $termId = (int) ($term['id'] ?? 0);
            if ($termId <= 0 || empty($paymentsByRole[$partyRole])) {
                continue;
            }

            $primaryPayment = $paymentsByRole[$partyRole][0];
            $primaryPaymentId = (int) ($primaryPayment['id'] ?? 0);
            if ($primaryPaymentId <= 0) {
                continue;
            }

            foreach ($paymentsByRole[$partyRole] as $payment) {
                $paymentId = (int) ($payment['id'] ?? 0);
                $legacyId = isset($payment['legacy_financial_term_id']) ? (int) $payment['legacy_financial_term_id'] : 0;
                if ($paymentId <= 0 || $paymentId === $primaryPaymentId) {
                    continue;
                }

                if ($legacyId === $termId) {
                    $clearLegacyLink->execute([$paymentId]);
                    $softDeleteDuplicate->execute([$paymentId]);
                }
            }

            $primaryLegacyId = isset($primaryPayment['legacy_financial_term_id']) ? (int) $primaryPayment['legacy_financial_term_id'] : 0;
            if ($primaryLegacyId !== $termId) {
                $releaseLegacyTerm->execute([$termId, $primaryPaymentId]);
                if ($primaryLegacyId > 0) {
                    $clearLegacyLink->execute([$primaryPaymentId]);
                }
                $updateLegacyLink->execute([$termId, $primaryPaymentId]);
            }
        }
    }

    public static function routeGrantLevel(PDO $localPdo, int $routeId, int $userId): ?string
    {
        $stmt = $localPdo->prepare(
            "SELECT access_level
               FROM entity_access_grants
              WHERE entity_type = 'linear_route'
                AND entity_id = ?
                AND granted_to_user_id = ?
                AND access_level IN ('view', 'edit')
                AND revoked_at IS NULL
              LIMIT 1"
        );
        $stmt->execute([$routeId, $userId]);

        $level = $stmt->fetchColumn();

        return is_string($level) && $level !== '' ? $level : null;
    }

    public static function canViewRoute(array $route, PDO $localPdo, array $user): bool
    {
        return RegulatedDataScopeService::canAccess($localPdo, 'linear_trip', 'linear_routes', (int) ($route['id'] ?? 0));
    }

    public static function canEditRoute(array $route, PDO $localPdo, array $user): bool
    {
        return RegulatedDataScopeService::canEditTrip($localPdo, 'linear_trip', 'linear_routes', (int) ($route['id'] ?? 0));
    }

    public static function canDeleteRoute(array $route, PDO $localPdo, array $user): bool
    {
        return self::canEditRoute($route, $localPdo, $user);
    }

    private static function tableExists(PDO $localPdo, string $tableName): bool
    {
        $cacheKey = spl_object_id($localPdo) . ':' . $tableName;
        if (array_key_exists($cacheKey, self::$tableExistsCache)) {
            return self::$tableExistsCache[$cacheKey];
        }

        $stmt = $localPdo->prepare(
            "SELECT 1
               FROM information_schema.tables
              WHERE table_schema = DATABASE()
                AND table_name = ?
              LIMIT 1"
        );
        $stmt->execute([$tableName]);
        $exists = $stmt->fetchColumn() !== false;
        self::$tableExistsCache[$cacheKey] = $exists;

        return $exists;
    }
}
