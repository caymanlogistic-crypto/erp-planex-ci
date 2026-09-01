<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/Service/WasteTripService.php';
require_once __DIR__ . '/../app/Service/WasteTripLegacyImportService.php';

use App\Service\WasteTripLegacyImportService;
use App\Service\WasteTripService;

$fail = static function (string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
};

if (WasteTripLegacyImportService::mapLegacyStatus('planned_route') !== WasteTripService::STATUS_SEARCHING) {
    $fail('planned_route must map to searching_executor for historical migration');
}

$service = file_get_contents(__DIR__ . '/../app/Service/WasteTripService.php');
$legacy = file_get_contents(__DIR__ . '/../app/Service/WasteTripLegacyImportService.php');
if (!is_string($service) || !is_string($legacy)) {
    $fail('unable to read Waste Trips sources');
}

foreach ([
    'allow_unmapped_historical_executor',
    'historical_executor_unmapped',
    'historical_executor_label',
] as $needle) {
    if (!str_contains($service, $needle) && !str_contains($legacy, $needle)) {
        $fail('missing historical import contract token: ' . $needle);
    }
}

foreach (['route_type', 'source_warehouse_id', 'destination_warehouse_id'] as $needle) {
    if (!str_contains($legacy, "'{$needle}'")) {
        $fail('legacy route provenance is not retained: ' . $needle);
    }
}

$regular = WasteTripService::normalizeInput([
    'client_id' => 1,
    'responsible_user_id' => 1,
    'status' => WasteTripService::STATUS_COMPLETED,
    'destination_type' => 'warehouse',
    'planned_start_mode' => 'unspecified',
    'actual_start_date' => '2026-01-01',
    'actual_end_date' => '2026-01-02',
]);
$regularErrors = WasteTripService::validateInput($regular, true, false);
if (!in_array('Для выбранного статуса требуется исполнитель рейса.', $regularErrors, true)) {
    $fail('regular completed trip must still require canonical executor');
}
$legacyErrors = WasteTripService::validateInput($regular, true, true);
if (in_array('Для выбранного статуса требуется исполнитель рейса.', $legacyErrors, true)) {
    $fail('historical legacy trip must allow an unmapped executor');
}

echo "WASTE_TRIP_HISTORICAL_IMPORT=PASS\n";
