<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'cgi-fcgi') {
    fwrite(STDERR, "CLI/CGI only.\n");
    exit(1);
}

$root = rtrim((string) (getenv('PLANEX_ROOT') ?: ($argv[1] ?? '')), '/');
if ($root === '' || !is_file($root . '/.env') || !is_file($root . '/bootstrap/app.php')) {
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

$tableExists = static function (PDO $pdo, string $table): int {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute([$table]);
    return (int) $stmt->fetchColumn();
};
$migrationExists = static function (PDO $pdo, string $migration) use ($tableExists): int {
    if ($tableExists($pdo, 'schema_migrations') !== 1) {
        return 0;
    }
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM schema_migrations WHERE migration = ?');
    $stmt->execute([$migration]);
    return (int) $stmt->fetchColumn();
};
$countRows = static function (PDO $pdo, string $table) use ($tableExists): int {
    return $tableExists($pdo, $table) === 1 ? (int) $pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn() : 0;
};

$companies = $central->query(
    "SELECT id,name,status,db_identifier,db_host,db_port,db_username,db_password\n"
    . "FROM companies WHERE status='active' AND db_identifier IS NOT NULL AND db_identifier<>'' ORDER BY id"
)->fetchAll(PDO::FETCH_ASSOC);
if ($companies === []) {
    throw new RuntimeException('No active tenant databases found');
}

$tenantSnapshot = [];
foreach ($companies as $company) {
    $cfg = companyDatabaseConfig($config, $company);
    $pdo = new PDO(
        'mysql:host=' . $cfg['host'] . ';port=' . ($cfg['port'] ?? 3306) . ';dbname=' . $cfg['database'] . ';charset=utf8mb4',
        $cfg['username'],
        $cfg['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
    );
    if ((string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== (string) $company['db_identifier']) {
        throw new RuntimeException('Unexpected tenant DB for company ' . $company['id']);
    }

    $tenantSnapshot[] = [
        'company_id' => (int) $company['id'],
        'db' => (string) $company['db_identifier'],
        'clients' => $countRows($pdo, 'clients'),
        'users' => $countRows($pdo, 'users'),
        'crews' => $countRows($pdo, 'crews'),
        'feo_requests' => $countRows($pdo, 'feo_requests'),
        'feo_utilizers' => $countRows($pdo, 'feo_utilizers'),
        'waste_trips_table' => $tableExists($pdo, 'waste_trips'),
        'waste_trips' => $countRows($pdo, 'waste_trips'),
        'waste_trip_requests_table' => $tableExists($pdo, 'waste_trip_requests'),
        'waste_trip_requests' => $countRows($pdo, 'waste_trip_requests'),
        'waste_trip_legacy_import_audits_table' => $tableExists($pdo, 'waste_trip_legacy_import_audits'),
        'waste_trip_legacy_import_audits' => $countRows($pdo, 'waste_trip_legacy_import_audits'),
        'waste_trip_audit_log_table' => $tableExists($pdo, 'waste_trip_audit_log'),
        'waste_trip_audit_log' => $countRows($pdo, 'waste_trip_audit_log'),
        'migration_127' => $migrationExists($pdo, '127_create_document_generator.sql'),
        'migration_128' => $migrationExists($pdo, '128_create_waste_trips.sql'),
    ];
}

$snapshot = [
    'central' => [
        'db' => (string) $central->query('SELECT DATABASE()')->fetchColumn(),
        'companies' => (int) $central->query('SELECT COUNT(*) FROM companies')->fetchColumn(),
        'active_tenants' => count($companies),
    ],
    'tenants' => $tenantSnapshot,
];
echo 'WASTE_128_SNAPSHOT=' . json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
echo 'WASTE_128_READ_ONLY_OK' . PHP_EOL;
