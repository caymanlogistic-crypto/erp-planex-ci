<?php

/**
 * CS-01 entrypoint service load / dependency-order regression test.
 *
 * Verifies that public/index.php registers all foundational services that have
 * web consumers, in the correct dependency order, with no duplicates, and that
 * each foundational service class is loadable in an isolated subprocess.
 *
 * Usage:
 *   php tests/entrypoint_service_load_test.php            — run all checks
 *   php tests/entrypoint_service_load_test.php --subprocess — subprocess proof
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

if (($argv[1] ?? '') === '--registry-scope-subprocess') {
    require __DIR__ . '/../bootstrap/app.php';
    require BASE_PATH . '/app/Support/entrypoint_dependencies.php';
    \App\Security\AccessRuntime::setCurrent(\App\Security\EffectiveAccess::systemOwner(
        11, 4, array_column(\App\Security\PageRegistry::companyPages(), 'key')
    ));
    $_SESSION = ['account_id' => 7, 'user_id' => 11, 'company_id' => 4];
    require BASE_PATH . '/app/View/partials/registry_ui_state_scope.php';
    if (!isset($registryUiStateScope) || !preg_match('/^[0-9a-f]{64}$/', $registryUiStateScope)) {
        echo "REGISTRY_SCOPE_ENTRYPOINT_FAILED\n";
        exit(1);
    }
    echo "REGISTRY_SCOPE_ENTRYPOINT_PASS\n";
    exit(0);
}

if (($argv[1] ?? '') === '--feo-transfer-subprocess') {
    require __DIR__ . '/../bootstrap/app.php';
    require BASE_PATH . '/app/Support/entrypoint_dependencies.php';
    if (!class_exists(\App\Service\FeoTransferTripWarningService::class, false)) {
        echo "FEO_TRANSFER_TRIP_WARNING_ENTRYPOINT_FAILED\n";
        exit(1);
    }
    $service = new \App\Service\FeoTransferTripWarningService();
    if (!($service instanceof \App\Service\FeoTransferTripWarningService)) {
        echo "FEO_TRANSFER_TRIP_WARNING_ENTRYPOINT_FAILED\n";
        exit(1);
    }
    echo "FEO_TRANSFER_TRIP_WARNING_ENTRYPOINT_PASS\n";
    exit(0);
}

// ---------------------------------------------------------------------------
// Subprocess mode: load CS-01 classes and prove class_exists().
// ---------------------------------------------------------------------------
if (($argv[1] ?? '') === '--subprocess') {
    require_once __DIR__ . '/../app/Service/DateCalculationService.php';
    require_once __DIR__ . '/../app/Service/RoutePaymentStatusService.php';
    require_once __DIR__ . '/../app/Service/FinanceAuditLogService.php';
    require_once __DIR__ . '/../app/Service/FinanceSettlementCascadeService.php';
    require_once __DIR__ . '/../app/Service/FinanceAdjustmentService.php';
    require_once __DIR__ . '/../app/Security/EffectiveAccess.php';
    require_once __DIR__ . '/../app/Security/AccessRuntime.php';
    require_once __DIR__ . '/../app/Service/AccessControlService.php';
    require_once __DIR__ . '/../app/Service/EntityAssignmentService.php';

    $classes = [
        'App\Service\DateCalculationService',
        'App\Service\RoutePaymentStatusService',
        'App\Service\FinanceAuditLogService',
        'App\Service\FinanceSettlementCascadeService',
        'App\Service\FinanceAdjustmentService',
        'App\Service\EntityAssignmentService',
    ];
    $missing = [];
    foreach ($classes as $class) {
        if (!class_exists($class)) {
            $missing[] = $class;
        }
    }
    if ($missing !== []) {
        echo "SUBPROCESS_CLASS_NOT_FOUND: " . implode(', ', $missing) . "\n";
        exit(1);
    }
    echo "SUBPROCESS_CLASSES_OK\n";
    exit(0);
}

// ---------------------------------------------------------------------------
// Manifest mode.
// ---------------------------------------------------------------------------
$fail = 0;
$pass = 0;

function check(string $label, bool $ok): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [PASS] $label\n"; }
    else { $fail++; echo "  [FAIL] $label\n"; }
}

$indexPath = __DIR__ . '/../public/index.php';
$indexContent = file_get_contents($indexPath);
$dependencyPath = __DIR__ . '/../app/Support/entrypoint_dependencies.php';
$dependencyContent = file_get_contents($dependencyPath);
$manifestContent = $indexContent . "\n" . $dependencyContent;

// 1. Extract require_once entries from public/index.php.
$requires = [];
preg_match_all(
    '/require_once\s+base_path\(\s*[\'"]([^\'"]+)[\'"]\s*\)/',
    $manifestContent,
    $matches
);
foreach ($matches[1] as $rel) {
    $rel = str_replace('\\', '/', $rel);
    $requires[] = $rel;
}

echo "=== CS-01 ENTRYPOINT SERVICE LOAD TEST ===\n";

// 2. Verify target service files exist on disk.
$serviceFiles = [
    'app/Service/DateCalculationService.php',
    'app/Service/RoutePaymentStatusService.php',
    'app/Service/FinanceAuditLogService.php',
    'app/Service/FinanceSettlementCascadeService.php',
    'app/Service/FinanceAdjustmentService.php',
    'app/Service/EntityAssignmentService.php',
];
$missingFiles = [];
foreach ($serviceFiles as $sf) {
    if (!is_file(__DIR__ . '/../' . $sf)) {
        $missingFiles[] = $sf;
    }
}
check('all 6 CS-01 service files exist', $missingFiles === []);

// 3. Check no duplicate require entries in public/index.php.
$dupes = array_filter(array_count_values($requires), fn($c) => $c > 1);
check('no duplicate require entries in entrypoint manifest', $dupes === []);

// 4. Foundational services with web consumers must be required in index.php.
$foundational = [
    'app/Service/DateCalculationService.php',
    'app/Service/RoutePaymentStatusService.php',
    'app/Service/FinanceAuditLogService.php',
    'app/Service/FinanceSettlementCascadeService.php',
    'app/Service/EntityAssignmentService.php',
];
$missingRequires = [];
foreach ($foundational as $f) {
    if (!in_array($f, $requires, true)) {
        $missingRequires[] = $f;
    }
}
check('all 5 web-consumer foundational services required by entrypoint', $missingRequires === []);
check(
    'registry state scope is loaded before production views without an autoloader',
    in_array('app/Security/RegistryUiStateScope.php', $requires, true)
        && positionIn($requires, 'app/Security/EffectiveAccess.php')
            < positionIn($requires, 'app/Security/RegistryUiStateScope.php')
        && positionIn($requires, 'app/Security/PageRegistry.php')
            < positionIn($requires, 'app/Security/RegistryUiStateScope.php')
);

// 5. Dependency order: foundational must appear before every direct consumer.
$consumers = [
    'app/Service/DateCalculationService.php' => [
        'app/Service/LinearRouteService.php',
        'app/Service/FinanceSettlementCascadeService.php',
        'app/Service/RoutePaymentStatusService.php',
    ],
    'app/Service/RoutePaymentStatusService.php' => [
        'app/Service/LinearRouteService.php',
        'app/Service/FinanceSettlementCascadeService.php',
    ],
    'app/Service/FinanceAuditLogService.php' => [
        'app/Service/LinearRouteService.php',
        'app/Service/FinanceAllocationService.php',
        'app/Service/FinanceInvoiceService.php',
        'app/Service/FinanceOperationService.php',
        'app/Service/FinanceSettlementCascadeService.php',
        'app/Service/FinanceCashService.php',
        'app/Service/FinanceMatchingRuleService.php',
    ],
    'app/Service/FinanceSettlementCascadeService.php' => [
        'app/Service/FinanceAllocationService.php',
        'app/Service/FinanceInvoiceService.php',
    ],
    'app/Service/EntityAssignmentService.php' => [
        'app/Service/ResponsibleAssignmentService.php',
    ],
];

function positionIn(array $requires, string $rel): int {
    foreach ($requires as $i => $r) {
        if ($r === $rel) return $i;
    }
    return -1;
}

$orderOk = true;
foreach ($consumers as $foundation => $deps) {
    $fPos = positionIn($requires, $foundation);
    if ($fPos < 0) {
        $orderOk = false; // already flagged as missing require
        continue;
    }
    foreach ($deps as $dep) {
        $dPos = positionIn($requires, $dep);
        if ($dPos >= 0 && $dPos <= $fPos) {
            $orderOk = false;
            echo "    (order violation: $foundation after $dep)\n";
        }
    }
}
check('foundational services precede direct consumers', $orderOk);

check(
    'public entrypoint delegates ordered dependencies to one manifest',
    str_contains($indexContent, "app/Support/entrypoint_dependencies.php")
        && is_file($dependencyPath)
);

// 6. Isolated subprocess: prove class_exists() for all CS-01 classes.
$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --subprocess';
$out = [];
$code = 0;
exec($cmd . ' 2>&1', $out, $code);
$subOk = $code === 0;
check('subprocess class_exists for all 6 CS-01 classes', $subOk);
if (!$subOk) {
    echo "  subprocess output: " . trim(implode("\n", $out)) . "\n";
}

$scopeCmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --registry-scope-subprocess';
$scopeOut = [];
$scopeCode = 0;
exec($scopeCmd . ' 2>&1', $scopeOut, $scopeCode);
check('registry scope partial works with production entrypoint and no autoloader', $scopeCode === 0 && in_array('REGISTRY_SCOPE_ENTRYPOINT_PASS', $scopeOut, true));
if ($scopeCode !== 0) echo "  scope subprocess output: " . trim(implode("\n", $scopeOut)) . "\n";

$transferCmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --feo-transfer-subprocess';
$transferOut = [];
$transferCode = 0;
exec($transferCmd . ' 2>&1', $transferOut, $transferCode);
check('transfer trip warning service loads in production entrypoint without an autoloader',
    $transferCode === 0 && in_array('FEO_TRANSFER_TRIP_WARNING_ENTRYPOINT_PASS', $transferOut, true));
if ($transferCode !== 0) echo "  transfer subprocess output: " . trim(implode("\n", $transferOut)) . "\n";

// 7. No persistent artifacts created by this test.
$artifacts = glob(sys_get_temp_dir() . '/cs01_*');
check('no persistent artifacts', $artifacts === [] || count($artifacts) === 0);

echo "\n=== RESULT: " . ($fail === 0 ? 'ALL TESTS PASSED' : "$fail FAILURES") . " ($pass passed, $fail failed) ===\n";
exit($fail === 0 ? 0 : 1);
