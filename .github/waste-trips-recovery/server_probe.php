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

function tableMeta(PDO $pdo, string $dbName, string $table): array {
    $cols = $pdo->prepare('SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_KEY, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? ORDER BY ORDINAL_POSITION');
    $cols->execute([$dbName, $table]);
    $idx = $pdo->prepare('SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? ORDER BY INDEX_NAME, SEQ_IN_INDEX');
    $idx->execute([$dbName, $table]);
    $fk = $pdo->prepare('SELECT CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY CONSTRAINT_NAME, ORDINAL_POSITION');
    $fk->execute([$dbName, $table]);
    return ['columns' => $cols->fetchAll(), 'indexes' => $idx->fetchAll(), 'foreign_keys' => $fk->fetchAll()];
}

$central = roPdo($config['database']);
$companies = $central->query("SELECT * FROM companies WHERE status='active' ORDER BY id")->fetchAll();
$result = [
    'expected_sha' => $expectedSha,
    'active_tenants' => [],
    'planex' => null,
    'legacy_discovery' => [],
    'php_error_log' => (string)ini_get('error_log'),
];

$tables = ['waste_trips', 'waste_trip_requests', 'waste_trip_legacy_import_audits', 'waste_trip_audit_log'];
foreach ($companies as $company) {
    $tenantCfg = companyDatabaseConfig($config, $company);
    $pdo = roPdo($tenantCfg);
    $dbName = (string)$tenantCfg['database'];
    $entry = [
        'company_id' => (int)$company['id'],
        'company_name' => (string)($company['name'] ?? ''),
        'database' => $dbName,
        'migration_128' => 0,
        'counts' => [],
    ];
    $m = $pdo->prepare('SELECT COUNT(*) FROM schema_migrations WHERE migration=?');
    $m->execute(['128_create_waste_trips.sql']);
    $entry['migration_128'] = (int)$m->fetchColumn();
    foreach ($tables as $table) {
        $entry['counts'][$table] = (int)$pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
    }
    $result['active_tenants'][] = $entry;

    if ((int)$company['id'] === 25) {
        $entry['reference_counts'] = [];
        foreach (['feo_requests', 'clients', 'users', 'crews'] as $table) {
            $entry['reference_counts'][$table] = (int)$pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
        }
        $entry['schema'] = [];
        foreach ($tables as $table) {
            $entry['schema'][$table] = tableMeta($pdo, $dbName, $table);
        }
        $result['planex'] = $entry;
    }
}

$legacyRoot = dirname($root) . '/erp';
$result['legacy_discovery']['root_exists'] = is_dir($legacyRoot);
$result['legacy_discovery']['root'] = $legacyRoot;
if (is_dir($legacyRoot)) {
    $candidates = [
        '.env', 'config.php', 'db.php', 'database.php',
        'config/config.php', 'config/database.php',
        'includes/config.php', 'includes/db.php', 'inc/config.php', 'inc/db.php',
        'application/config/database.php', 'app/config/database.php'
    ];
    $found = [];
    foreach ($candidates as $relative) {
        $path = $legacyRoot . '/' . $relative;
        if (is_file($path)) {
            $found[] = ['path' => $relative, 'size' => filesize($path) ?: 0, 'sha256' => hash_file('sha256', $path) ?: ''];
        }
    }
    $result['legacy_discovery']['candidate_config_files'] = $found;

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($legacyRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    $hits = [];
    foreach ($iterator as $file) {
        if (count($hits) >= 50) break;
        if (!$file->isFile() || $file->getSize() > 1024 * 1024) continue;
        if (!in_array(strtolower($file->getExtension()), ['php', 'env', 'ini'], true) && $file->getFilename() !== '.env') continue;
        $depthPath = substr($file->getPathname(), strlen($legacyRoot) + 1);
        if (substr_count(str_replace('\\', '/', $depthPath), '/') > 4) continue;
        $text = @file_get_contents($file->getPathname());
        if (!is_string($text)) continue;
        if (preg_match('/DB_(HOST|NAME|DATABASE|USER|USERNAME|PASS|PASSWORD)|mysqli_connect|new\\s+PDO|mysql:host|dbname/i', $text)) {
            $hits[] = ['path' => $depthPath, 'size' => $file->getSize(), 'sha256' => hash('sha256', $text)];
        }
    }
    $result['legacy_discovery']['db_reference_files'] = $hits;
}

foreach ([$root . '/storage/logs', $root . '/logs'] as $dir) {
    if (!is_dir($dir)) continue;
    $items = [];
    foreach (glob($dir . '/*') ?: [] as $file) {
        if (!is_file($file)) continue;
        $items[] = ['name' => basename($file), 'size' => filesize($file) ?: 0, 'mtime' => filemtime($file) ?: 0];
    }
    $result['log_dirs'][substr($dir, strlen($root))] = $items;
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
