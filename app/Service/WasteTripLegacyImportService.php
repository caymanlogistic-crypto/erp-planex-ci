<?php

namespace App\Service;

use PDO;
use RuntimeException;

final class WasteTripLegacyImportService
{
    public const SOURCE_SYSTEM = 'legacy_flights';

    public static function connectionFromEnvironment(): ?PDO
    {
        $host = trim((string) env('LEGACY_FLIGHTS_DB_HOST', ''));
        $database = trim((string) env('LEGACY_FLIGHTS_DB_DATABASE', ''));
        $username = trim((string) env('LEGACY_FLIGHTS_DB_USERNAME', ''));
        $password = (string) env('LEGACY_FLIGHTS_DB_PASSWORD', '');
        if ($host === '' || $database === '' || $username === '') {
            return null;
        }
        $port = trim((string) env('LEGACY_FLIGHTS_DB_PORT', '3306')) ?: '3306';
        $charset = trim((string) env('LEGACY_FLIGHTS_DB_CHARSET', 'utf8mb4')) ?: 'utf8mb4';

        $pdo = new PDO(
            "mysql:host={$host};port={$port};dbname={$database};charset={$charset}",
            $username,
            $password,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
        $pdo->exec('SET SESSION TRANSACTION READ ONLY');
        return $pdo;
    }

    public static function listCandidates(PDO $legacyPdo, PDO $tenantPdo, int $limit = 200, bool $includeImported = false): array
    {
        $limit = max(1, min(500, $limit));
        $rows = $legacyPdo->query(self::legacyListSql($limit))->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            return [];
        }

        $legacyIds = array_values(array_unique(array_map(static fn(array $row): int => (int) $row['id'], $rows)));
        $importMap = self::importMap($tenantPdo, $legacyIds);
        $allBusinessIds = [];
        foreach ($rows as $row) {
            foreach (self::parseLegacyRequestIds((string) ($row['zayavki_ids'] ?? '')) as $businessId) {
                $allBusinessIds[$businessId] = true;
            }
        }
        $requestMap = self::requestResolutionMap($tenantPdo, array_keys($allBusinessIds));
        $claimMap = self::activeClaimMap($tenantPdo, array_column($requestMap, 'id'));

        $result = [];
        foreach ($rows as $row) {
            $legacyId = (int) $row['id'];
            $isImported = isset($importMap[$legacyId]);
            if ($isImported && !$includeImported) {
                continue;
            }
            $businessIds = self::parseLegacyRequestIds((string) ($row['zayavki_ids'] ?? ''));
            $found = [];
            $missing = [];
            $occupied = [];
            $currentMassKg = 0.0;
            $customerHints = [];
            foreach ($businessIds as $businessId) {
                $resolved = $requestMap[$businessId] ?? null;
                if ($resolved === null) {
                    $missing[] = $businessId;
                    continue;
                }
                $found[] = $businessId;
                $currentMassKg += WasteTripService::massKg($resolved['mass_netto'] ?? null);
                $customerName = trim((string) ($resolved['polnoe_naim_zakazchika'] ?? ''));
                $customerInn = trim((string) ($resolved['inn_zakazchika'] ?? ''));
                if ($customerName !== '' || $customerInn !== '') {
                    $customerHints[$customerName . '|' . $customerInn] = ['name' => $customerName, 'inn' => $customerInn];
                }
                $requestId = (int) $resolved['id'];
                if (isset($claimMap[$requestId])) {
                    $occupied[$businessId] = $claimMap[$requestId];
                }
            }
            $legacyMassKg = self::legacyMassKg($row['total_mass_tonn'] ?? null);
            $row['request_ids'] = $businessIds;
            $row['found_count'] = count($found);
            $row['missing_request_ids'] = $missing;
            $row['occupied_requests'] = $occupied;
            $row['current_mass_kg'] = round($currentMassKg, 3);
            $row['legacy_mass_kg'] = $legacyMassKg;
            $row['customer_hints'] = array_values($customerHints);
            $row['mass_delta_kg'] = round($currentMassKg - $legacyMassKg, 3);
            $row['is_imported'] = $isImported;
            $row['erp_waste_trip_id'] = $importMap[$legacyId] ?? null;
            $row['mapped_status'] = self::mapLegacyStatus((string) ($row['status'] ?? ''));
            $row['ready_for_mapping'] = !$isImported && $missing === [] && $occupied === [] && $row['mapped_status'] !== null;
            $result[] = $row;
        }
        return $result;
    }

    public static function import(PDO $legacyPdo, PDO $tenantPdo, int $legacyFlightId, array $mapping, array $sessionUser): int
    {
        if ($legacyFlightId <= 0) {
            throw new RuntimeException('Некорректный legacy ID рейса.');
        }
        $legacy = self::fetchLegacyFlight($legacyPdo, $legacyFlightId);
        if ($legacy === null) {
            throw new RuntimeException('Legacy-рейс не найден.');
        }
        $status = self::mapLegacyStatus((string) ($legacy['status'] ?? ''));
        if ($status === null) {
            throw new RuntimeException('Неизвестный legacy-статус. Импорт заблокирован.');
        }
        $requestIds = self::parseLegacyRequestIds((string) ($legacy['zayavki_ids'] ?? ''));
        if ($requestIds === []) {
            throw new RuntimeException('В legacy-рейсе нет заявок ФЭО.');
        }

        $destination = self::resolveDestinationMapping((string) ($legacy['unload_type'] ?? ''), $mapping);
        $executorId = (int) ($mapping['route_executor_id'] ?? 0) ?: null;
        $allowHistoricalUnmappedExecutor = !empty($mapping['allow_unmapped_historical_executor']);
        if (in_array($status, [WasteTripService::STATUS_ASSIGNED, WasteTripService::STATUS_IN_PROGRESS, WasteTripService::STATUS_COMPLETED], true) && $executorId === null && !$allowHistoricalUnmappedExecutor) {
            throw new RuntimeException('Для этого legacy-статуса требуется вручную выбрать ERP-исполнителя либо явно разрешить сохранение исторического исполнителя без canonical crew.');
        }

        $plannedExact = self::legacyDate($legacy['planned_start_date'] ?? null);
        $plannedFrom = self::legacyDate($legacy['planned_start_date_from'] ?? null);
        $plannedTo = self::legacyDate($legacy['planned_start_date_to'] ?? null);
        $plannedMode = $plannedExact !== null ? 'exact' : (($plannedFrom !== null && $plannedTo !== null) ? 'range' : 'unspecified');

        $input = [
            'client_id' => (int) ($mapping['client_id'] ?? 0),
            'route_executor_id' => $executorId,
            'responsible_user_id' => (int) ($mapping['responsible_user_id'] ?? 0),
            'destination_type' => $destination['destination_type'],
            'utilizer_id' => $destination['utilizer_id'],
            'status' => $status,
            'planned_start_mode' => $plannedMode,
            'planned_start_date' => $plannedExact,
            'planned_start_date_from' => $plannedFrom,
            'planned_start_date_to' => $plannedTo,
            'actual_start_date' => self::legacyDate($legacy['actual_start_date'] ?? null),
            'actual_end_date' => self::legacyDate($legacy['actual_end_date'] ?? null),
            'comment' => (string) ($legacy['comment'] ?? ''),
            'cancellation_reason' => null,
        ];

        $evidence = [
            'source_system' => self::SOURCE_SYSTEM,
            'legacy_flight_id' => $legacyFlightId,
            'legacy_total_mass_kg' => self::legacyMassKg($legacy['total_mass_tonn'] ?? null),
            'legacy_cost' => self::nullableMoney($legacy['cost'] ?? null),
            'legacy_payment_terms' => (string) ($legacy['payment_terms'] ?? ''),
            'payload' => self::sanitizeLegacyPayload($legacy),
            'allow_unmapped_historical_executor' => $allowHistoricalUnmappedExecutor,
            'historical_claim_exempt' => true,
        ];

        return WasteTripService::create($tenantPdo, $input, $requestIds, $sessionUser, $evidence);
    }

    public static function mapLegacyStatus(string $status): ?string
    {
        return match (trim($status)) {
            'search' => WasteTripService::STATUS_SEARCHING,
            'found' => WasteTripService::STATUS_ASSIGNED,
            'started' => WasteTripService::STATUS_IN_PROGRESS,
            'completed' => WasteTripService::STATUS_COMPLETED,
            'planned_route' => WasteTripService::STATUS_SEARCHING,
            default => null,
        };
    }

    public static function parseLegacyRequestIds(string $raw): array
    {
        $parts = preg_split('/[\s,;]+/u', trim($raw), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $seen = [];
        $ids = [];
        foreach ($parts as $part) {
            $id = trim((string) $part);
            if ($id === '' || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $ids[] = $id;
        }
        return $ids;
    }

    public static function resolveDestinationMapping(string $legacyUnloadType, array $mapping): array
    {
        $legacyUnloadType = trim($legacyUnloadType);
        if (!in_array($legacyUnloadType, ['OO', 'SKLAD'], true)) {
            throw new RuntimeException('Неизвестный legacy-тип выгрузки. Импорт заблокирован.');
        }

        $destinationType = trim((string) ($mapping['destination_type'] ?? ''));
        if (!in_array($destinationType, ['utilizer', 'warehouse'], true)) {
            throw new RuntimeException('Legacy-тип выгрузки «' . $legacyUnloadType . '» не маппится автоматически. Выберите ERP назначение вручную.');
        }

        $utilizerId = (int) ($mapping['utilizer_id'] ?? 0) ?: null;
        if ($destinationType === 'utilizer' && $utilizerId === null) {
            throw new RuntimeException('Для назначения «утилизатор» выберите ERP утилизатора вручную.');
        }
        if ($destinationType === 'warehouse' && $utilizerId !== null) {
            throw new RuntimeException('Для назначения «склад» ERP утилизатор не указывается.');
        }

        return ['destination_type' => $destinationType, 'utilizer_id' => $utilizerId];
    }

    private static function fetchLegacyFlight(PDO $legacyPdo, int $legacyFlightId): ?array
    {
        $stmt = $legacyPdo->prepare(self::legacyBaseSql() . ' WHERE f.id = ? LIMIT 1');
        $stmt->execute([$legacyFlightId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private static function legacyBaseSql(): string
    {
        return "SELECT f.*,
                       c.name AS legacy_contractor_name,
                       d.full_name AS legacy_driver_name,
                       d.vehicle_make_plate AS legacy_vehicle_make_plate,
                       CONCAT_WS(' ', u.`Фамилия`, u.`Имя`) AS legacy_manager_name
                  FROM flights f
             LEFT JOIN contractors c ON c.id=f.contractor_id
             LEFT JOIN drivers d ON d.id=f.driver_id
             LEFT JOIN users u ON u.id=f.assigned_manager_id";
    }

    private static function legacyListSql(int $limit): string
    {
        return self::legacyBaseSql() . ' ORDER BY f.id DESC LIMIT ' . $limit;
    }

    private static function importMap(PDO $tenantPdo, array $legacyIds): array
    {
        if ($legacyIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($legacyIds), '?'));
        $params = array_merge([self::SOURCE_SYSTEM], $legacyIds);
        $stmt = $tenantPdo->prepare("SELECT legacy_flight_id, id FROM waste_trips WHERE source_system=? AND legacy_flight_id IN ({$placeholders})");
        $stmt->execute($params);
        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $map[(int) $row['legacy_flight_id']] = (int) $row['id'];
        }
        return $map;
    }

    private static function requestResolutionMap(PDO $tenantPdo, array $businessIds): array
    {
        if ($businessIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($businessIds), '?'));
        $stmt = $tenantPdo->prepare("SELECT id, zayavka_id, mass_netto, polnoe_naim_zakazchika, inn_zakazchika, naim_oo_gruzootpravitel, mno_adres_pogruzki FROM feo_requests WHERE zayavka_id IN ({$placeholders})");
        $stmt->execute($businessIds);
        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $map[(string) $row['zayavka_id']] = $row;
        }
        return $map;
    }

    private static function activeClaimMap(PDO $tenantPdo, array $requestIds): array
    {
        $requestIds = array_values(array_unique(array_filter(array_map('intval', $requestIds))));
        if ($requestIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($requestIds), '?'));
        $stmt = $tenantPdo->prepare("SELECT wtr.feo_request_id, wt.id AS waste_trip_id FROM waste_trip_requests wtr JOIN waste_trips wt ON wt.id=wtr.waste_trip_id WHERE wtr.feo_request_id IN ({$placeholders}) AND wt.status <> 'cancelled' AND COALESCE(wt.source_system,'') <> 'legacy_flights'");
        $stmt->execute($requestIds);
        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $map[(int) $row['feo_request_id']] = (int) $row['waste_trip_id'];
        }
        return $map;
    }

    private static function legacyMassKg(mixed $tonnes): float
    {
        $value = str_replace(',', '.', trim((string) $tonnes));
        return is_numeric($value) ? round(max(0.0, (float) $value) * 1000, 3) : 0.0;
    }

    private static function legacyDate(mixed $value): ?string
    {
        $value = trim((string) $value);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
    }

    private static function nullableMoney(mixed $value): ?string
    {
        $value = str_replace(',', '.', trim((string) $value));
        if ($value === '' || !is_numeric($value)) {
            return null;
        }
        return number_format((float) $value, 2, '.', '');
    }

    private static function sanitizeLegacyPayload(array $legacy): array
    {
        $allowed = [
            'id', 'block_date', 'zayavki_ids', 'zayavki_count', 'total_mass_tonn', 'unload_type', 'status',
            'contractor_id', 'driver_id', 'cost', 'payment_terms', 'comment', 'planned_start_date',
            'planned_start_date_from', 'planned_start_date_to', 'actual_start_date', 'actual_end_date',
            'assigned_manager_id', 'route_type', 'source_warehouse_id', 'destination_warehouse_id',
            'legacy_contractor_name', 'legacy_driver_name', 'legacy_vehicle_make_plate', 'legacy_manager_name',
        ];
        $payload = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $legacy)) {
                $payload[$key] = $legacy[$key];
            }
        }
        return $payload;
    }
}
