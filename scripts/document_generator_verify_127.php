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
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$tableExists = static function (PDO $pdo, string $table): int {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute([$table]);
    return (int) $stmt->fetchColumn();
};
$columnExists = static function (PDO $pdo, string $table, string $column): int {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute([$table, $column]);
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

$centralSnapshot = [
    'db' => (string) $central->query('SELECT DATABASE()')->fetchColumn(),
    'companies' => (int) $central->query('SELECT COUNT(*) FROM companies')->fetchColumn(),
    'bank_account' => $columnExists($central, 'companies', 'bank_account'),
    'bank_name' => $columnExists($central, 'companies', 'bank_name'),
    'bank_bik' => $columnExists($central, 'companies', 'bank_bik'),
    'bank_corr_account' => $columnExists($central, 'companies', 'bank_corr_account'),
];

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
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    if ((string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== (string) $company['db_identifier']) {
        throw new RuntimeException('Unexpected tenant DB for company ' . $company['id']);
    }

    $tenantSnapshot[] = [
        'company_id' => (int) $company['id'],
        'db' => (string) $company['db_identifier'],
        'contractors' => $tableExists($pdo, 'contractors') ? (int) $pdo->query('SELECT COUNT(*) FROM contractors')->fetchColumn() : 0,
        'drivers' => $tableExists($pdo, 'drivers') ? (int) $pdo->query('SELECT COUNT(*) FROM drivers')->fetchColumn() : 0,
        'vehicle_units' => $tableExists($pdo, 'vehicle_units') ? (int) $pdo->query('SELECT COUNT(*) FROM vehicle_units')->fetchColumn() : 0,
        'vehicle_sets' => $tableExists($pdo, 'vehicle_sets') ? (int) $pdo->query('SELECT COUNT(*) FROM vehicle_sets')->fetchColumn() : 0,
        'crews' => $tableExists($pdo, 'crews') ? (int) $pdo->query('SELECT COUNT(*) FROM crews')->fetchColumn() : 0,
        'feo_requests' => $tableExists($pdo, 'feo_requests') ? (int) $pdo->query('SELECT COUNT(*) FROM feo_requests')->fetchColumn() : 0,
        'document_templates_table' => $tableExists($pdo, 'document_templates'),
        'document_templates' => $tableExists($pdo, 'document_templates') ? (int) $pdo->query('SELECT COUNT(*) FROM document_templates')->fetchColumn() : 0,
        'document_template_versions_table' => $tableExists($pdo, 'document_template_versions'),
        'document_template_versions' => $tableExists($pdo, 'document_template_versions') ? (int) $pdo->query('SELECT COUNT(*) FROM document_template_versions')->fetchColumn() : 0,
        'generated_documents_table' => $tableExists($pdo, 'generated_documents'),
        'generated_documents' => $tableExists($pdo, 'generated_documents') ? (int) $pdo->query('SELECT COUNT(*) FROM generated_documents')->fetchColumn() : 0,
        'generated_document_requests_table' => $tableExists($pdo, 'generated_document_requests'),
        'generated_document_requests' => $tableExists($pdo, 'generated_document_requests') ? (int) $pdo->query('SELECT COUNT(*) FROM generated_document_requests')->fetchColumn() : 0,
        'migration_126' => $migrationExists($pdo, '126_create_feo_utilizer_aliases.sql'),
        'migration_127' => $migrationExists($pdo, '127_create_document_generator.sql'),
    ];
}

$snapshot = ['central' => $centralSnapshot, 'tenants' => $tenantSnapshot];
echo 'DOCGEN_127_SNAPSHOT=' . json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
echo 'DOCGEN_127_READ_ONLY_OK' . PHP_EOL;
