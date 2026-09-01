<?php

declare(strict_types=1);

use App\Service\WasteTripLegacyImportService;
use App\Service\WasteTripService;

$root = rtrim((string) getenv('LIVE_ROOT'), '/');
$expectedSha = trim((string) getenv('EXPECTED_SHA'));
$markerPath = (string) getenv('DEPLOY_MARKER');
$snapshotPath = (string) getenv('UI_SNAPSHOT_PATH');

if ($root === '' || !is_file($root . '/bootstrap/app.php')) {
    throw new RuntimeException('production root unavailable');
}
if (!preg_match('/^[0-9a-f]{40}$/', $expectedSha)) {
    throw new RuntimeException('invalid EXPECTED_SHA');
}
if (!is_file($markerPath) || trim((string) file_get_contents($markerPath)) !== $expectedSha) {
    throw new RuntimeException('deployed marker mismatch');
}

$config = require $root . '/bootstrap/app.php';
require_once $root . '/app/Support/crypto_helper.php';
require_once $root . '/app/Support/company_database.php';
require_once $root . '/app/Support/http_runtime.php';
require_once $root . '/app/Service/AccessControlService.php';
require_once $root . '/app/Service/LinearRouteService.php';
require_once $root . '/app/Service/WasteTripService.php';
require_once $root . '/app/Service/WasteTripLegacyImportService.php';

$centralCfg = $config['database'];
$central = new PDO(
    'mysql:host=' . $centralCfg['host'] . ';port=' . ($centralCfg['port'] ?? 3306) . ';dbname=' . $centralCfg['database'] . ';charset=utf8mb4',
    $centralCfg['username'],
    $centralCfg['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
);
$central->exec('SET SESSION TRANSACTION READ ONLY');
$central->exec('START TRANSACTION READ ONLY');

$stmt = $central->query("SELECT * FROM companies WHERE id=25 AND status='active' LIMIT 1");
$company = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
if ($company === null) {
    throw new RuntimeException('company 25 unavailable');
}

$tenantCfg = companyDatabaseConfig($config, $company);
$pdo = new PDO(
    'mysql:host=' . $tenantCfg['host'] . ';port=' . ($tenantCfg['port'] ?? 3306) . ';dbname=' . $tenantCfg['database'] . ';charset=utf8mb4',
    $tenantCfg['username'],
    $tenantCfg['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
);
$pdo->exec('SET SESSION TRANSACTION READ ONLY');
$pdo->exec('START TRANSACTION READ ONLY');

if (!WasteTripService::schemaReady($pdo)) {
    throw new RuntimeException('Waste Trips schemaReady=false');
}
$migration = $pdo->prepare('SELECT COUNT(*) FROM schema_migrations WHERE migration=?');
$migration->execute(['128_create_waste_trips.sql']);
if ((int) $migration->fetchColumn() !== 1) {
    throw new RuntimeException('migration 128 journal missing');
}

$countTables = [
    'clients', 'users', 'crews', 'feo_requests', 'feo_utilizers',
    'waste_trips', 'waste_trip_requests', 'waste_trip_legacy_import_audits', 'waste_trip_audit_log',
];
$countsBefore = [];
foreach ($countTables as $table) {
    $countsBefore[$table] = (int) $pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
}
foreach (['clients', 'users', 'crews', 'feo_requests', 'feo_utilizers'] as $required) {
    if ($countsBefore[$required] <= 0) {
        throw new RuntimeException($required . ' unexpectedly empty for company 25');
    }
}
foreach (['waste_trips', 'waste_trip_requests', 'waste_trip_legacy_import_audits', 'waste_trip_audit_log'] as $businessTable) {
    if ($countsBefore[$businessTable] !== 0) {
        throw new RuntimeException($businessTable . ' must remain empty during zero-row production acceptance');
    }
}

$controllerSource = (string) file_get_contents($root . '/app/Http/Controllers/Company/WasteTripController.php');
$requiredMethods = [
    'index', 'createSubmit', 'previewSelection', 'modalView', 'modalEditForm',
    'modalEditSubmit', 'cancelSubmit', 'importIndex', 'importSubmit',
];
foreach ($requiredMethods as $method) {
    $pattern = '/public\s+function\s+' . preg_quote($method, '/') . '\s*\([^)]*\)\s*:\s*void\s*\{\s*requireRole\(\[\s*[\'\"]company_owner[\'\"]\s*,\s*[\'\"]senior_logist[\'\"]\s*,\s*[\'\"]logist[\'\"]\s*\]\);/s';
    if (!preg_match($pattern, $controllerSource)) {
        throw new RuntimeException('runtime role gate missing for ' . $method);
    }
}

$routeSource = (string) file_get_contents($root . '/app/Http/Routes/company_linear_trips.php');
$requiredRouteFragments = [
    "get('/company/trips/departures'",
    "post('/company/trips/departures/preview'",
    "post('/company/trips/departures/create'",
    "get('/company/trips/departures/import'",
    "post('/company/trips/departures/import/{legacyId}'",
    "get('/company/trips/departures/{id}/modal-view'",
    "get('/company/trips/departures/{id}/modal-edit'",
    "post('/company/trips/departures/{id}/modal-edit'",
    "post('/company/trips/departures/{id}/cancel'",
];
foreach ($requiredRouteFragments as $fragment) {
    if (!str_contains($routeSource, $fragment)) {
        throw new RuntimeException('deployed route missing: ' . $fragment);
    }
}

echo 'WASTE_TRIPS_RUNTIME_ROUTES_RBAC=PASS' . PHP_EOL;

$trips = WasteTripService::fetchTrips($pdo, []);
if (!is_array($trips) || $trips !== []) {
    throw new RuntimeException('expected zero Waste Trips rows for read-only acceptance');
}

$placement = $pdo->query("SELECT zayavka_id FROM feo_placement_current WHERE zayavka_id IS NOT NULL AND zayavka_id<>'' ORDER BY zayavka_id LIMIT 1")->fetchColumn();
$previewState = 'SKIP_NO_CURRENT_PLACEMENT';
if (is_string($placement) && $placement !== '') {
    $preview = WasteTripService::previewSelection($pdo, [$placement]);
    if (!is_array($preview) || (int) ($preview['found_count'] ?? 0) !== 1 || ($preview['missing'] ?? []) !== [] || ($preview['occupied'] ?? []) !== []) {
        throw new RuntimeException('previewSelection failed on canonical current FEO request');
    }
    $previewState = 'PASS';
}
echo 'WASTE_TRIPS_RUNTIME_PREVIEW=' . $previewState . PHP_EOL;

$viewPath = $root . '/app/View/pages/company_waste_trips.php';
$importViewPath = $root . '/app/View/pages/company_waste_trip_import.php';
$modalViewPartial = (string) file_get_contents($root . '/app/View/partials/company_waste_trip_modal_view.php');
$modalEditPartial = (string) file_get_contents($root . '/app/View/partials/company_waste_trip_modal_edit.php');
foreach ([$modalViewPartial, $modalEditPartial] as $partial) {
    if (str_contains($partial, 'modal-overlay')) {
        throw new RuntimeException('AJAX modal partial contains nested modal-overlay');
    }
    if (!str_contains($partial, 'data-waste-modal-title')) {
        throw new RuntimeException('AJAX modal partial title contract missing');
    }
}
echo 'WASTE_TRIPS_MODAL_PARTIALS_SINGLE_SHELL=PASS' . PHP_EOL;

$firstUserId = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
if ($firstUserId <= 0) {
    throw new RuntimeException('tenant user unavailable');
}

$renderRegistry = static function (string $role) use ($viewPath, $company, $trips, $firstUserId): string {
    $_SESSION = [
        'user_id' => $firstUserId,
        'role_code' => $role,
        'company_id' => 25,
        'user_name' => 'Runtime Probe',
    ];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/erpv2/company/trips/departures';
    $_GET = [];
    $pageTitle = 'Перевозка отходов';
    $pageContext = 'Рейсы › Перевозка отходов';
    $dbError = null;
    $clients = [];
    $routeExecutors = [];
    $responsibleUsers = [];
    $utilizers = [];
    $formError = null;
    $formSuccess = null;
    $old = [];
    ob_start();
    require $viewPath;
    return (string) ob_get_clean();
};

$roleHtml = [];
foreach (['company_owner', 'senior_logist', 'logist'] as $role) {
    $html = $renderRegistry($role);
    if (!str_contains($html, 'Перевозка отходов') || !str_contains($html, 'Создать рейс') || !str_contains($html, 'Импорт из старой системы')) {
        throw new RuntimeException('registry render failed for role ' . $role);
    }
    if (!str_contains($html, 'data-waste-create-submit disabled')) {
        throw new RuntimeException('create submit must be disabled before preview for role ' . $role);
    }
    if (substr_count($html, 'class="modal-overlay"') !== 3) {
        throw new RuntimeException('registry must render exactly three top-level modal shells for role ' . $role);
    }
    $roleHtml[$role] = $html;
    echo 'WASTE_TRIPS_RUNTIME_ROLE_' . strtoupper($role) . '=PASS' . PHP_EOL;
}

$syntheticCandidate = [
    'id' => 990000001,
    'request_ids' => ['285664'],
    'missing_request_ids' => [],
    'occupied_requests' => [],
    'mapped_status' => WasteTripService::STATUS_SEARCHING,
    'status' => 'search',
    'unload_type' => 'OO',
    'block_date' => '2026-09-01',
    'legacy_mass_kg' => 1000.0,
    'current_mass_kg' => 1000.0,
    'mass_delta_kg' => 0.0,
    'found_count' => 1,
    'planned_start_date' => '2026-09-02',
    'planned_start_date_from' => null,
    'planned_start_date_to' => null,
    'actual_start_date' => null,
    'actual_end_date' => null,
    'legacy_contractor_name' => 'Legacy contractor',
    'legacy_driver_name' => 'Legacy driver',
    'legacy_vehicle_make_plate' => 'Legacy vehicle',
    'customer_hints' => [],
    'legacy_manager_name' => 'Legacy manager',
    'comment' => 'runtime synthetic preview only',
    'cost' => 123.45,
    'payment_terms' => 'runtime evidence',
    'is_imported' => false,
    'ready_for_mapping' => true,
    'erp_waste_trip_id' => null,
];

$renderImport = static function (string $role) use ($importViewPath, $syntheticCandidate, $firstUserId): string {
    $_SESSION = [
        'user_id' => $firstUserId,
        'role_code' => $role,
        'company_id' => 25,
        'user_name' => 'Runtime Probe',
    ];
    $legacyCandidates = [$syntheticCandidate];
    $legacyError = null;
    $showImported = false;
    $formError = null;
    $formSuccess = null;
    $clients = [];
    $routeExecutors = [];
    $responsibleUsers = [];
    $utilizers = [];
    ob_start();
    require $importViewPath;
    return (string) ob_get_clean();
};

foreach (['company_owner', 'senior_logist', 'logist'] as $role) {
    $html = $renderImport($role);
    if (!str_contains($html, 'Импорт старых рейсов') || !str_contains($html, 'не определяет ERP назначение автоматически')) {
        throw new RuntimeException('import read-only render failed for role ' . $role);
    }
    if (!preg_match('/<select name="destination_type"[^>]*>\s*<option value="">— Выберите вручную —<\/option>/u', $html)) {
        throw new RuntimeException('manual destination mapping is not blank-by-default for role ' . $role);
    }
    $hasFinance = str_contains($html, 'Legacy стоимость') || str_contains($html, 'Legacy условия оплаты');
    if ($role === 'company_owner' && !$hasFinance) {
        throw new RuntimeException('owner finance evidence missing');
    }
    if ($role !== 'company_owner' && $hasFinance) {
        throw new RuntimeException('legacy finance evidence leaked to ' . $role);
    }
}
echo 'WASTE_TRIPS_RUNTIME_IMPORT_MANUAL_DESTINATION=PASS' . PHP_EOL;
echo 'WASTE_TRIPS_RUNTIME_FINANCE_VISIBILITY=PASS' . PHP_EOL;

$legacyState = 'SKIP_ENV_NOT_CONFIGURED';
try {
    $legacyPdo = WasteTripLegacyImportService::connectionFromEnvironment();
    if ($legacyPdo instanceof PDO) {
        $candidates = WasteTripLegacyImportService::listCandidates($legacyPdo, $pdo, 5, false);
        if (!is_array($candidates)) {
            throw new RuntimeException('legacy candidate preview did not return array');
        }
        $legacyState = 'PASS candidates=' . count($candidates);
    }
} catch (Throwable $e) {
    throw new RuntimeException('legacy read-only preview failed: ' . $e->getMessage(), 0, $e);
}
echo 'WASTE_TRIPS_RUNTIME_LEGACY_PREVIEW=' . $legacyState . PHP_EOL;

if ($snapshotPath === '') {
    throw new RuntimeException('UI_SNAPSHOT_PATH missing');
}
$_SESSION = [
    'user_id' => $firstUserId,
    'role_code' => 'senior_logist',
    'company_id' => 25,
    'user_name' => 'Runtime Probe',
];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/erpv2/company/trips/departures';
$_GET = [];
$pageTitle = 'Перевозка отходов';
$pageContext = 'Рейсы › Перевозка отходов › Компания: ' . (string) ($company['name'] ?? '');
$content = $roleHtml['senior_logist'];
$db = null;
ob_start();
require $root . '/app/View/layouts/main.php';
$fullHtml = (string) ob_get_clean();
if (!str_contains($fullHtml, '<!doctype html>') || !str_contains($fullHtml, 'waste-trip-create-modal')) {
    throw new RuntimeException('full UI snapshot render failed');
}
if (file_put_contents($snapshotPath, $fullHtml) === false) {
    throw new RuntimeException('cannot write temporary UI snapshot');
}

echo 'WASTE_TRIPS_RUNTIME_FULL_LAYOUT=PASS' . PHP_EOL;

$countsAfter = [];
foreach ($countTables as $table) {
    $countsAfter[$table] = (int) $pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
}
if ($countsAfter !== $countsBefore) {
    throw new RuntimeException('production counts changed during read-only probe');
}
foreach (['waste_trips', 'waste_trip_requests', 'waste_trip_legacy_import_audits', 'waste_trip_audit_log'] as $businessTable) {
    if ($countsAfter[$businessTable] !== 0) {
        throw new RuntimeException($businessTable . ' changed during read-only acceptance');
    }
}

$pdo->rollBack();
$central->rollBack();

echo 'WASTE_TRIPS_RUNTIME_COUNTS=' . json_encode($countsAfter, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
echo 'WASTE_TRIPS_RUNTIME_READ_ONLY=PASS' . PHP_EOL;
echo 'WASTE_TRIPS_RUNTIME_ACCEPTANCE_SERVER=PASS' . PHP_EOL;
