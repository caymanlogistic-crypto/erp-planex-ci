<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'cgi-fcgi') {
    fwrite(STDERR, "CLI/CGI only.\n");
    exit(1);
}

$root = rtrim((string) (getenv('PLANEX_ROOT') ?: ($argv[1] ?? '')), '/');
if ($root === '' || !is_file($root . '/bootstrap/app.php')) {
    throw new RuntimeException('PLANEX_ROOT invalid');
}

$config = require $root . '/bootstrap/app.php';
require_once $root . '/app/Support/crypto_helper.php';
require_once $root . '/app/Support/company_database.php';

$centralCfg = $config['database'];
$central = new PDO(
    'mysql:host=' . $centralCfg['host'] . ';port=' . ($centralCfg['port'] ?? 3306) . ';dbname=' . $centralCfg['database'] . ';charset=utf8mb4',
    $centralCfg['username'],
    $centralCfg['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
);

$tableExists = static function (PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $stmt->execute([$table]);
    return (int) $stmt->fetchColumn() === 1;
};
$columnMeta = static function (PDO $pdo, string $table, string $column): ?array {
    $stmt = $pdo->prepare('SELECT IS_NULLABLE,COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
    $stmt->execute([$table, $column]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
};
$migrationExists = static function (PDO $pdo, string $migration) use ($tableExists): int {
    if (!$tableExists($pdo, 'schema_migrations')) {
        return 0;
    }
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM schema_migrations WHERE migration=?');
    $stmt->execute([$migration]);
    return (int) $stmt->fetchColumn();
};

$companies = $central->query(
    "SELECT id,name,status,db_identifier,db_host,db_port,db_username,db_password\n"
    . "FROM companies WHERE status='active' AND db_identifier IS NOT NULL AND db_identifier<>'' ORDER BY id"
)->fetchAll(PDO::FETCH_ASSOC);
if ($companies === []) {
    throw new RuntimeException('No active tenant databases found');
}

$tenants = [];
foreach ($companies as $company) {
    $cfg = companyDatabaseConfig($config, $company);
    $pdo = new PDO(
        'mysql:host=' . $cfg['host'] . ';port=' . ($cfg['port'] ?? 3306) . ';dbname=' . $cfg['database'] . ';charset=utf8mb4',
        $cfg['username'],
        $cfg['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
    );
    $dbName = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    if ($dbName !== (string) $company['db_identifier']) {
        throw new RuntimeException('Unexpected tenant DB for company ' . (int) $company['id']);
    }

    $businessMeta = $columnMeta($pdo, 'waste_trips', 'business_id');
    $state = $tableExists($pdo, 'waste_trip_namespace_state')
        ? ($pdo->query('SELECT legacy_high_water_at_cutover,last_issued_business_id,cutover_started_at,cutover_completed_at FROM waste_trip_namespace_state WHERE singleton_id=1')->fetch(PDO::FETCH_ASSOC) ?: null)
        : null;

    $tenants[] = [
        'company_id' => (int) $company['id'],
        'db' => $dbName,
        'migration_128' => $migrationExists($pdo, '128_create_waste_trips.sql'),
        'migration_129' => $migrationExists($pdo, '129_document_generator_auto_number_and_bank_inn.sql'),
        'migration_130' => $migrationExists($pdo, '130_waste_trip_legacy_linked_cutover.sql'),
        'waste_trips' => $tableExists($pdo, 'waste_trips') ? (int) $pdo->query('SELECT COUNT(*) FROM waste_trips')->fetchColumn() : -1,
        'legacy_snapshot_rows' => $tableExists($pdo, 'waste_trips') ? (int) $pdo->query("SELECT COUNT(*) FROM waste_trips WHERE source_system='legacy_flights'")->fetchColumn() : -1,
        'business_id_column' => $businessMeta !== null ? 1 : 0,
        'business_id_null' => $businessMeta !== null ? (int) $pdo->query('SELECT COUNT(*) FROM waste_trips WHERE business_id IS NULL')->fetchColumn() : -1,
        'business_id_nullable' => $businessMeta['IS_NULLABLE'] ?? null,
        'business_id_unique' => $businessMeta !== null ? (int) $pdo->query("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='waste_trips' AND INDEX_NAME='uk_waste_trip_business_id' AND NON_UNIQUE=0")->fetchColumn() : 0,
        'namespace_state_table' => $tableExists($pdo, 'waste_trip_namespace_state') ? 1 : 0,
        'import_audit_table' => $tableExists($pdo, 'waste_trip_legacy_import_audits') ? 1 : 0,
        'operational_audit_table' => $tableExists($pdo, 'waste_trip_audit_log') ? 1 : 0,
        'state' => $state,
    ];
}

$snapshot = [
    'central' => [
        'db' => (string) $central->query('SELECT DATABASE()')->fetchColumn(),
        'active_tenants' => count($companies),
    ],
    'tenants' => $tenants,
];

echo 'WASTE_130_SNAPSHOT=' . json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
echo "WASTE_130_READ_ONLY_OK\n";
