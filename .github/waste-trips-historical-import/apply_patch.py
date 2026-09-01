from pathlib import Path


def replace_once(path: str, old: str, new: str) -> None:
    p = Path(path)
    text = p.read_text(encoding='utf-8')
    count = text.count(old)
    if count != 1:
        raise SystemExit(f'{path}: expected exactly one match, found {count}: {old[:100]!r}')
    p.write_text(text.replace(old, new, 1), encoding='utf-8')

# WasteTripService: historical legacy records may preserve a missing canonical executor,
# while regular live trips keep the strict invariant.
service = 'app/Service/WasteTripService.php'
replace_once(
    service,
    'public static function validateInput(array $data, bool $allowUnspecifiedPlan = false): array',
    'public static function validateInput(array $data, bool $allowUnspecifiedPlan = false, bool $allowHistoricalUnmappedExecutor = false): array'
)
replace_once(
    service,
    "if (in_array($status, [self::STATUS_ASSIGNED, self::STATUS_IN_PROGRESS, self::STATUS_COMPLETED], true) && $executorId <= 0) {",
    "if (in_array($status, [self::STATUS_ASSIGNED, self::STATUS_IN_PROGRESS, self::STATUS_COMPLETED], true) && $executorId <= 0 && !$allowHistoricalUnmappedExecutor) {"
)
replace_once(
    service,
    "$errors = self::validateInput($data, $legacyEvidence !== null);",
    "$allowHistoricalUnmappedExecutor = $legacyEvidence !== null && !empty($legacyEvidence['allow_unmapped_historical_executor']);\n        $errors = self::validateInput($data, $legacyEvidence !== null, $allowHistoricalUnmappedExecutor);"
)
replace_once(
    service,
    "$allowUnspecifiedPlan = ($trip['source_system'] ?? null) !== null && ($trip['planned_start_mode'] ?? '') === 'unspecified';\n            $errors = self::validateInput($data, $allowUnspecifiedPlan);",
    "$allowUnspecifiedPlan = ($trip['source_system'] ?? null) !== null && ($trip['planned_start_mode'] ?? '') === 'unspecified';\n            $allowHistoricalUnmappedExecutor = ($trip['source_system'] ?? null) === 'legacy_flights' && empty($trip['route_executor_id']);\n            $errors = self::validateInput($data, $allowUnspecifiedPlan, $allowHistoricalUnmappedExecutor);"
)
replace_once(
    service,
    "        $trips = $stmt->fetchAll(PDO::FETCH_ASSOC);\n        self::enrichExecutorDisplay($pdo, $trips);\n        return $trips;",
    "        $trips = $stmt->fetchAll(PDO::FETCH_ASSOC);\n        self::enrichExecutorDisplay($pdo, $trips);\n        self::enrichHistoricalExecutorDisplay($pdo, $trips);\n        return $trips;"
)
replace_once(
    service,
    "        $audit = $pdo->prepare(\n            'SELECT source_system, legacy_flight_id, mapping_json, snapshot_request_count, snapshot_total_mass_kg,\n                    mass_delta_kg, warnings_json, legacy_total_mass_kg, legacy_cost, legacy_payment_terms, created_at\n               FROM waste_trip_legacy_import_audits WHERE waste_trip_id=? LIMIT 1'\n        );\n        $audit->execute([$tripId]);\n        $trip['legacy_audit'] = $audit->fetch(PDO::FETCH_ASSOC) ?: null;\n        return $trip;",
    "        $audit = $pdo->prepare(\n            'SELECT source_system, legacy_flight_id, legacy_payload_json, mapping_json, snapshot_request_count, snapshot_total_mass_kg,\n                    mass_delta_kg, warnings_json, legacy_total_mass_kg, legacy_cost, legacy_payment_terms, created_at\n               FROM waste_trip_legacy_import_audits WHERE waste_trip_id=? LIMIT 1'\n        );\n        $audit->execute([$tripId]);\n        $trip['legacy_audit'] = $audit->fetch(PDO::FETCH_ASSOC) ?: null;\n        if ($trip['legacy_audit'] !== null) {\n            $payload = json_decode((string) ($trip['legacy_audit']['legacy_payload_json'] ?? ''), true);\n            $trip['legacy_payload'] = is_array($payload) ? $payload : [];\n            $trip['historical_executor_label'] = self::historicalExecutorLabel($trip['legacy_payload']);\n        }\n        return $trip;"
)
replace_once(
    service,
    "        $warnings = [];\n        if ($delta !== null && abs($delta) >= 0.001) {",
    "        $warnings = [];\n        if (!empty($evidence['allow_unmapped_historical_executor']) && empty($data['route_executor_id'])) {\n            $warnings[] = 'historical_executor_unmapped';\n        }\n        if ($delta !== null && abs($delta) >= 0.001) {"
)
insert_before = "    private static function normalizeDate(mixed $value): ?string\n"
historical_helpers = r'''    private static function enrichHistoricalExecutorDisplay(PDO $pdo, array &$trips): void
    {
        $tripIds = [];
        foreach ($trips as $trip) {
            if (($trip['source_system'] ?? null) === 'legacy_flights' && empty($trip['route_executor_id'])) {
                $tripIds[] = (int) ($trip['id'] ?? 0);
            }
        }
        $tripIds = array_values(array_unique(array_filter($tripIds)));
        if ($tripIds === []) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($tripIds), '?'));
        $stmt = $pdo->prepare("SELECT waste_trip_id, legacy_payload_json FROM waste_trip_legacy_import_audits WHERE waste_trip_id IN ({$placeholders})");
        $stmt->execute($tripIds);
        $labels = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $payload = json_decode((string) ($row['legacy_payload_json'] ?? ''), true);
            $labels[(int) $row['waste_trip_id']] = self::historicalExecutorLabel(is_array($payload) ? $payload : []);
        }
        foreach ($trips as &$trip) {
            $trip['historical_executor_label'] = $labels[(int) ($trip['id'] ?? 0)] ?? '';
        }
        unset($trip);
    }

    private static function historicalExecutorLabel(array $payload): string
    {
        $parts = array_values(array_filter([
            trim((string) ($payload['legacy_contractor_name'] ?? '')),
            trim((string) ($payload['legacy_driver_name'] ?? '')),
            trim((string) ($payload['legacy_vehicle_make_plate'] ?? '')),
        ], static fn(string $value): bool => $value !== ''));
        return implode(' · ', $parts);
    }

'''
replace_once(service, insert_before, historical_helpers + insert_before)

# Legacy importer: preserve route provenance and support truthful historical executor fallback.
legacy = 'app/Service/WasteTripLegacyImportService.php'
replace_once(
    legacy,
    "        if (in_array($status, [WasteTripService::STATUS_ASSIGNED, WasteTripService::STATUS_IN_PROGRESS, WasteTripService::STATUS_COMPLETED], true) && $executorId === null) {\n            throw new RuntimeException('Для этого legacy-статуса требуется вручную выбрать ERP-исполнителя.');\n        }",
    "        $allowHistoricalUnmappedExecutor = !empty($mapping['allow_unmapped_historical_executor']);\n        if (in_array($status, [WasteTripService::STATUS_ASSIGNED, WasteTripService::STATUS_IN_PROGRESS, WasteTripService::STATUS_COMPLETED], true) && $executorId === null && !$allowHistoricalUnmappedExecutor) {\n            throw new RuntimeException('Для этого legacy-статуса требуется вручную выбрать ERP-исполнителя либо явно разрешить сохранение исторического исполнителя без canonical crew.');\n        }"
)
replace_once(
    legacy,
    "            'payload' => self::sanitizeLegacyPayload($legacy),\n        ];",
    "            'payload' => self::sanitizeLegacyPayload($legacy),\n            'allow_unmapped_historical_executor' => $allowHistoricalUnmappedExecutor,\n        ];"
)
replace_once(
    legacy,
    "            'completed' => WasteTripService::STATUS_COMPLETED,\n            default => null,",
    "            'completed' => WasteTripService::STATUS_COMPLETED,\n            'planned_route' => WasteTripService::STATUS_SEARCHING,\n            default => null,"
)
replace_once(
    legacy,
    "            'assigned_manager_id', 'legacy_contractor_name', 'legacy_driver_name', 'legacy_vehicle_make_plate',\n            'legacy_manager_name',",
    "            'assigned_manager_id', 'route_type', 'source_warehouse_id', 'destination_warehouse_id',\n            'legacy_contractor_name', 'legacy_driver_name', 'legacy_vehicle_make_plate', 'legacy_manager_name',"
)

# Registry: truthful historical executor fallback.
view = 'app/View/pages/company_waste_trips.php'
replace_once(
    view,
    "    return $parts !== [] ? implode(' · ', $parts) : '—';",
    "    if ($parts !== []) {\n        return implode(' · ', $parts);\n    }\n    $historical = trim((string) ($row['historical_executor_label'] ?? ''));\n    return $historical !== '' ? 'Исторический: ' . $historical : '—';"
)

# Modal: show historical executor and route provenance from immutable legacy payload.
modal = 'app/View/partials/company_waste_trip_modal_view.php'
replace_once(
    modal,
    "$executorParts = array_values(array_filter([\n    trim((string) ($trip['executor_contractor_name'] ?? '')),\n    trim((string) ($trip['executor_driver_names'] ?? '')),\n    trim((string) ($trip['executor_vehicle_label'] ?? '')),\n], static fn(string $v): bool => $v !== ''));",
    "$executorParts = array_values(array_filter([\n    trim((string) ($trip['executor_contractor_name'] ?? '')),\n    trim((string) ($trip['executor_driver_names'] ?? '')),\n    trim((string) ($trip['executor_vehicle_label'] ?? '')),\n], static fn(string $v): bool => $v !== ''));\n$historicalExecutor = trim((string) ($trip['historical_executor_label'] ?? ''));\n$executorDisplay = $executorParts !== [] ? implode(' · ', $executorParts) : ($historicalExecutor !== '' ? 'Исторический: ' . $historicalExecutor : '—');\n$legacyPayload = is_array($trip['legacy_payload'] ?? null) ? $trip['legacy_payload'] : [];"
)
replace_once(
    modal,
    "      <tr><td>Исполнитель</td><td><?= e($executorParts !== [] ? implode(' · ', $executorParts) : '—') ?></td></tr>",
    "      <tr><td>Исполнитель</td><td><?= e($executorDisplay) ?></td></tr>"
)
replace_once(
    modal,
    "        <tr><td>Расхождение массы</td><td><?= e(number_format((float) ($trip['legacy_audit']['mass_delta_kg'] ?? 0), 3, ',', ' ') . ' кг') ?></td></tr>",
    "        <tr><td>Расхождение массы</td><td><?= e(number_format((float) ($trip['legacy_audit']['mass_delta_kg'] ?? 0), 3, ',', ' ') . ' кг') ?></td></tr>\n        <?php if (trim((string) ($legacyPayload['route_type'] ?? '')) !== ''): ?><tr><td>Legacy маршрут</td><td><?= e((string) $legacyPayload['route_type']) ?><?php if (!empty($legacyPayload['source_warehouse_id'])): ?> · склад-источник #<?= (int) $legacyPayload['source_warehouse_id'] ?><?php endif; ?><?php if (!empty($legacyPayload['destination_warehouse_id'])): ?> · склад-назначение #<?= (int) $legacyPayload['destination_warehouse_id'] ?><?php endif; ?></td></tr><?php endif; ?>"
)

# Permanent CI gate.
workflow = '.github/workflows/waste_trips_regression.yml'
replace_once(
    workflow,
    "          php -l tests/waste_trip_owner_layout_contract_test.php",
    "          php -l tests/waste_trip_owner_layout_contract_test.php\n          php -l tests/waste_trip_historical_import_contract_test.php"
)
replace_once(
    workflow,
    "      - name: Start isolated MySQL",
    "      - name: Historical import contract\n        run: |\n          set -euo pipefail\n          php tests/waste_trip_historical_import_contract_test.php | tee /tmp/waste-trip-historical-import.out\n          grep -Fqx 'WASTE_TRIP_HISTORICAL_IMPORT=PASS' /tmp/waste-trip-historical-import.out\n\n      - name: Start isolated MySQL"
)

print('WASTE_HISTORICAL_IMPORT_PATCH=PASS')
