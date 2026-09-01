<?php

declare(strict_types=1);

$root = rtrim((string)(getenv('PLANEX_ROOT') ?: ''), '/');
$expectedSha = trim((string)(getenv('EXPECTED_SHA') ?: ''));
$markerPath = trim((string)(getenv('DEPLOY_MARKER') ?: ''));
if ($root === '' || !is_file($root . '/bootstrap/app.php') || !is_file($root . '/.env')) {
    throw new RuntimeException('PLANEX_ROOT invalid');
}
if (!preg_match('/^[0-9a-f]{40}$/D', $expectedSha)) {
    throw new RuntimeException('EXPECTED_SHA invalid');
}
if ($markerPath === '' || !is_file($markerPath) || trim((string)file_get_contents($markerPath)) !== $expectedSha) {
    throw new RuntimeException('deployed marker mismatch');
}

require_once $root . '/app/Support/helpers.php';
require_once $root . '/app/Support/environment.php';
loadEnvFileNonOverwriting($root . '/.env');
$config = require $root . '/bootstrap/app.php';
require_once $root . '/app/Support/crypto_helper.php';
require_once $root . '/app/Support/company_database.php';

function roPdo(array $cfg): PDO {
    $pdo = new PDO(
        'mysql:host=' . $cfg['host'] . ';port=' . ($cfg['port'] ?? 3306) . ';dbname=' . $cfg['database'] . ';charset=utf8mb4',
        $cfg['username'],
        $cfg['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    $pdo->exec('SET SESSION TRANSACTION READ ONLY');
    $pdo->exec('START TRANSACTION READ ONLY');
    return $pdo;
}

$central = roPdo($config['database']);
$companies = $central->query("SELECT * FROM companies WHERE status='active' ORDER BY id")->fetchAll();
$result = [
    'expected_sha' => $expectedSha,
    'active_tenants' => [],
    'planex' => null,
];

$tables = ['waste_trips', 'waste_trip_requests', 'waste_trip_legacy_import_audits', 'waste_trip_audit_log'];
foreach ($companies as $company) {
    $tenantCfg = companyDatabaseConfig($config, $company);
    $pdo = roPdo($tenantCfg);
    $entry = [
        'company_id' => (int)$company['id'],
        'company_name' => (string)($company['name'] ?? ''),
        'migration_128' => 0,
        'counts' => [],
    ];
    $m = $pdo->prepare('SELECT COUNT(*) FROM schema_migrations WHERE migration=?');
    $m->execute(['128_create_waste_trips.sql']);
    $entry['migration_128'] = (int)$m->fetchColumn();
    foreach ($tables as $table) {
        $entry['counts'][$table] = (int)$pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
    }
    if ((int)$company['id'] === 25) {
        $entry['reference_counts'] = [];
        foreach (['feo_requests', 'clients', 'users', 'crews'] as $table) {
            $entry['reference_counts'][$table] = (int)$pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
        }
        $result['planex'] = $entry;
    }
    $result['active_tenants'][] = $entry;
}

if ($result['planex'] === null) {
    throw new RuntimeException('PLANEX tenant not found');
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
