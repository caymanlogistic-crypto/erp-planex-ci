<?php

require_once __DIR__ . '/../app/Service/LinearRoutePointService.php';

use App\Service\LinearRoutePointService;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$normalized = LinearRoutePointService::normalizeSubmitted([
    'route_points' => [
        'points' => [
            ['address' => '  Склад   А  ', 'loading' => '1'],
            ['address' => 'Терминал Б', 'unloading' => '1'],
            ['address' => 'Хаб В', 'loading' => '1', 'unloading' => '1'],
        ],
    ],
]);

$assert(count($normalized['points']) === 3, 'three logical points must remain three points');
$assert($normalized['points'][0]['address'] === 'Склад А', 'address whitespace must be normalized');
$assert($normalized['points'][0]['loading'] === true && $normalized['points'][0]['unloading'] === false, 'loading-only point');
$assert($normalized['points'][1]['loading'] === false && $normalized['points'][1]['unloading'] === true, 'unloading-only point');
$assert($normalized['points'][2]['loading'] === true && $normalized['points'][2]['unloading'] === true, 'dual-operation point');
$assert($normalized['loading'] === ['Склад А', '', 'Хаб В'], 'loading projection must preserve global positions');
$assert($normalized['unloading'] === ['', 'Терминал Б', 'Хаб В'], 'unloading projection must preserve global positions');

$errors = [];
LinearRoutePointService::validate($normalized, $errors);
$assert($errors === [], 'valid mixed route points must pass validation');

$invalid = LinearRoutePointService::normalizeSubmitted([
    'route_points' => [
        'points' => [
            ['address' => 'Точка без операции'],
            ['address' => '', 'loading' => '1'],
        ],
    ],
]);
$errors = [];
LinearRoutePointService::validate($invalid, $errors);
$assert(isset($errors['route_points.points.0.operation']), 'filled point without operation must fail');
$assert(isset($errors['route_points.points.1.address']), 'selected operation without address must fail');
$assert(isset($errors['route_points.unloading.0']), 'route must contain at least one unloading operation');

$legacy = LinearRoutePointService::normalizeSubmitted([
    'route_points' => [
        'loading' => ['A', '', 'C'],
        'unloading' => ['', 'B', 'C'],
    ],
]);
$assert(count($legacy['points']) === 3, 'aligned legacy projection must restore three points');
$assert($legacy['points'][2]['loading'] && $legacy['points'][2]['unloading'], 'same aligned legacy address must restore dual operation');

$migration = file_get_contents(__DIR__ . '/../database/migrations-local/058_create_linear_route_points.sql');
$assert($migration !== false, 'route point migration must exist');
$assert(strpos($migration, "ENUM('loading','unloading')") !== false, 'DB must support both physical operation row types');
$assert(strpos($migration, 'UNIQUE') === false, 'DB must allow two operation rows with same route/sort/address for a dual point');

$entrypointDependencies = file_get_contents(__DIR__ . '/../app/Support/entrypoint_dependencies.php');
$assert($entrypointDependencies !== false, 'public entrypoint dependency manifest must exist');
$financeServiceRequire = "require_once base_path('app/Service/LinearTripServiceFinanceService.php');";
$lineServiceRequire = "require_once base_path('app/Service/LinearTripServiceLineService.php');";
$financeServicePos = strpos($entrypointDependencies, $financeServiceRequire);
$lineServicePos = strpos($entrypointDependencies, $lineServiceRequire);
$assert($financeServicePos !== false, 'entrypoint must load LinearTripServiceFinanceService');
$assert($lineServicePos !== false, 'entrypoint must load LinearTripServiceLineService');
$assert($financeServicePos < $lineServicePos, 'finance service must load before line service');

fwrite(STDOUT, "PASS linear_route_point_dual_operation_test\n");
require __DIR__ . '/linear_trip_multi_services_contract_test.php';
require __DIR__ . '/linear_trip_registry_service_layout_contract_test.php';
