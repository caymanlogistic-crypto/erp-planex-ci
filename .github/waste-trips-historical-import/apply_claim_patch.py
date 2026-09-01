from pathlib import Path


def replace_once(path: str, old: str, new: str) -> None:
    p = Path(path)
    text = p.read_text(encoding='utf-8')
    count = text.count(old)
    if count != 1:
        raise SystemExit(f'{path}: expected one match, got {count}: {old[:120]!r}')
    p.write_text(text.replace(old, new, 1), encoding='utf-8')

service='app/Service/WasteTripService.php'
replace_once(
    service,
    "            $resolved = $legacyEvidence === null\n                ? self::resolveCurrentRequestsForClaim($pdo, $requestBusinessIds)\n                : self::resolveRequestsForClaim($pdo, $requestBusinessIds, false);\n            self::assertNoActiveClaims($pdo, array_column($resolved, 'id'));",
    "            $resolved = $legacyEvidence === null\n                ? self::resolveCurrentRequestsForClaim($pdo, $requestBusinessIds)\n                : self::resolveRequestsForClaim($pdo, $requestBusinessIds, false);\n            $historicalClaimExempt = $legacyEvidence !== null && !empty($legacyEvidence['historical_claim_exempt']);\n            if (!$historicalClaimExempt) {\n                self::assertNoActiveClaims($pdo, array_column($resolved, 'id'));\n            }"
)
replace_once(
    service,
    "            if ($newBusinessIds !== []) {\n                $resolvedNew = self::resolveCurrentRequestsForClaim($pdo, $newBusinessIds);\n                self::assertNoActiveClaims($pdo, array_column($resolvedNew, 'id'), $tripId);\n                self::insertRequestLinks($pdo, $tripId, $resolvedNew, count($existingLinks));\n            }",
    "            if ($newBusinessIds !== []) {\n                $isHistoricalLegacy = ($trip['source_system'] ?? null) === 'legacy_flights';\n                $resolvedNew = $isHistoricalLegacy\n                    ? self::resolveRequestsForClaim($pdo, $newBusinessIds, false)\n                    : self::resolveCurrentRequestsForClaim($pdo, $newBusinessIds);\n                if (!$isHistoricalLegacy) {\n                    self::assertNoActiveClaims($pdo, array_column($resolvedNew, 'id'), $tripId);\n                }\n                self::insertRequestLinks($pdo, $tripId, $resolvedNew, count($existingLinks));\n            }"
)
replace_once(
    service,
    "                   AND wt.status <> 'cancelled'" + '";',
    "                   AND wt.status <> 'cancelled'\n                   AND COALESCE(wt.source_system,'') <> 'legacy_flights'" + '";'
)
replace_once(
    service,
    "                  WHERE wtr.feo_request_id IN ({$claimPlaceholders}) AND wt.status <> 'cancelled'" + '"',
    "                  WHERE wtr.feo_request_id IN ({$claimPlaceholders})\n                    AND wt.status <> 'cancelled'\n                    AND COALESCE(wt.source_system,'') <> 'legacy_flights'" + '"'
)

legacy='app/Service/WasteTripLegacyImportService.php'
replace_once(
    legacy,
    "            'allow_unmapped_historical_executor' => $allowHistoricalUnmappedExecutor,\n        ];",
    "            'allow_unmapped_historical_executor' => $allowHistoricalUnmappedExecutor,\n            'historical_claim_exempt' => true,\n        ];"
)
replace_once(
    legacy,
    "        $stmt = $tenantPdo->prepare(\"SELECT wtr.feo_request_id, wt.id AS waste_trip_id FROM waste_trip_requests wtr JOIN waste_trips wt ON wt.id=wtr.waste_trip_id WHERE wtr.feo_request_id IN ({$placeholders}) AND wt.status <> 'cancelled'\");",
    "        $stmt = $tenantPdo->prepare(\"SELECT wtr.feo_request_id, wt.id AS waste_trip_id FROM waste_trip_requests wtr JOIN waste_trips wt ON wt.id=wtr.waste_trip_id WHERE wtr.feo_request_id IN ({$placeholders}) AND wt.status <> 'cancelled' AND COALESCE(wt.source_system,'') <> 'legacy_flights'\");"
)

contract='tests/waste_trip_historical_import_contract_test.php'
replace_once(
    contract,
    "    'historical_executor_label',\n] as $needle) {",
    "    'historical_executor_label',\n    'historical_claim_exempt',\n] as $needle) {"
)
replace_once(
    contract,
    "echo \"WASTE_TRIP_HISTORICAL_IMPORT=PASS\\n\";",
    "if (!str_contains($service, \"COALESCE(wt.source_system,'') <> 'legacy_flights'\")) {\n    $fail('operational claim queries must ignore immutable legacy historical links');\n}\n\necho \"WASTE_TRIP_HISTORICAL_IMPORT=PASS\\n\";"
)

reg='tests/waste_trip_regression_test.php'
replace_once(
    reg,
    "    sameValue(WasteTripLegacyImportService::mapLegacyStatus('completed'), WasteTripService::STATUS_COMPLETED, 'legacy completed mapping');\n    sameValue(WasteTripLegacyImportService::mapLegacyStatus('mystery'), null, 'unknown legacy status blocks');",
    "    sameValue(WasteTripLegacyImportService::mapLegacyStatus('completed'), WasteTripService::STATUS_COMPLETED, 'legacy completed mapping');\n    sameValue(WasteTripLegacyImportService::mapLegacyStatus('planned_route'), WasteTripService::STATUS_SEARCHING, 'legacy planned route mapping');\n    sameValue(WasteTripLegacyImportService::mapLegacyStatus('mystery'), null, 'unknown legacy status blocks');"
)
anchor="        sameValue((int) $pdo->query(\"SELECT COUNT(*) FROM waste_trip_requests wtr JOIN feo_requests fr ON fr.id=wtr.feo_request_id WHERE fr.zayavka_id='R104'\")->fetchColumn(), 0, 'failed duplicate legacy create must not claim second request');\n\n"
insert=anchor + r'''        $historicalA = [
            'source_system' => WasteTripLegacyImportService::SOURCE_SYSTEM,
            'legacy_flight_id' => 7101,
            'legacy_total_mass_kg' => 1000.0,
            'legacy_cost' => null,
            'legacy_payment_terms' => '',
            'payload' => ['route_type' => 'generator_to_warehouse'],
            'historical_claim_exempt' => true,
        ];
        $historicalB = $historicalA;
        $historicalB['legacy_flight_id'] = 7102;
        $historicalB['payload'] = ['route_type' => 'warehouse_to_utilizer'];
        $historicalTripA = WasteTripService::create($pdo, baseInput(), ['R109'], ownerUser(), $historicalA);
        $historicalTripB = WasteTripService::create($pdo, baseInput(), ['R109'], ownerUser(), $historicalB);
        must($historicalTripB > $historicalTripA, 'same FEO request must be preservable across historical transport legs');
        sameValue((int) $pdo->query("SELECT COUNT(*) FROM waste_trip_requests wtr JOIN feo_requests fr ON fr.id=wtr.feo_request_id WHERE fr.zayavka_id='R109'")->fetchColumn(), 2, 'two historical links must be preserved');
        $operationalAfterHistory = WasteTripService::create($pdo, baseInput(), ['R109'], ownerUser());
        must($operationalAfterHistory > $historicalTripB, 'historical links must not reserve the operational claim');
        mustThrow(static function () use ($pdo): void {
            WasteTripService::create($pdo, baseInput(), ['R109'], ownerUser());
        }, 'second operational claim must still fail after historical links');
        sameValue((int) $pdo->query("SELECT COUNT(*) FROM waste_trip_requests wtr JOIN waste_trips wt ON wt.id=wtr.waste_trip_id JOIN feo_requests fr ON fr.id=wtr.feo_request_id WHERE fr.zayavka_id='R109' AND wt.status<>'cancelled' AND COALESCE(wt.source_system,'')<>'legacy_flights'")->fetchColumn(), 1, 'only one operational active claim allowed with historical duplicates');

'''
replace_once(reg, anchor, insert)
print('WASTE_HISTORICAL_CLAIM_PATCH=PASS')
