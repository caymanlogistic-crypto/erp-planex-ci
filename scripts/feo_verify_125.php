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
$companies = $central->query(
    "SELECT id,name,status,db_identifier,db_host,db_port,db_username,db_password
       FROM companies
      WHERE status='active' AND db_identifier IS NOT NULL AND db_identifier<>''
      ORDER BY id"
)->fetchAll(PDO::FETCH_ASSOC);
if ($companies === []) {
    throw new RuntimeException('No active tenant databases found');
}

$snapshot = [];
foreach ($companies as $company) {
    $cfg = companyDatabaseConfig($config, $company);
    $pdo = new PDO(
        'mysql:host=' . $cfg['host'] . ';port=' . ($cfg['port'] ?? 3306) . ';dbname=' . $cfg['database'] . ';charset=utf8mb4',
        $cfg['username'],
        $cfg['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $actualDb = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    if ($actualDb !== (string) $company['db_identifier']) {
        throw new RuntimeException('Unexpected tenant DB for company ' . $company['id']);
    }

    $tableExists = static function (PDO $p, string $table): int {
        $q = $p->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $q->execute([$table]);
        return (int) $q->fetchColumn();
    };
    $countIf = static function (PDO $p, string $table) use ($tableExists): int {
        return $tableExists($p, $table) ? (int) $p->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn() : 0;
    };
    $migration = static function (PDO $p, string $name) use ($tableExists): int {
        if (!$tableExists($p, 'schema_migrations')) {
            return 0;
        }
        $q = $p->prepare('SELECT COUNT(*) FROM schema_migrations WHERE migration=?');
        $q->execute([$name]);
        return (int) $q->fetchColumn();
    };

    $snapshot[] = [
        'company_id' => (int) $company['id'],
        'db' => (string) $company['db_identifier'],
        'base_feo_tables' => $tableExists($pdo, 'feo_requests') + $tableExists($pdo, 'feo_imports') + $tableExists($pdo, 'feo_import_changes'),
        'feo_requests' => $countIf($pdo, 'feo_requests'),
        'feo_imports' => $countIf($pdo, 'feo_imports'),
        'feo_changes' => $countIf($pdo, 'feo_import_changes'),
        'placement_tables' => $tableExists($pdo, 'feo_placement_blocks') + $tableExists($pdo, 'feo_placement_block_requests') + $tableExists($pdo, 'feo_placement_current'),
        'placement_blocks' => $countIf($pdo, 'feo_placement_blocks'),
        'placement_members' => $countIf($pdo, 'feo_placement_block_requests'),
        'placement_current' => $countIf($pdo, 'feo_placement_current'),
        'cost_tables' => $tableExists($pdo, 'feo_request_cost_blocks') + $tableExists($pdo, 'feo_request_cost_block_requests'),
        'cost_blocks' => $countIf($pdo, 'feo_request_cost_blocks'),
        'cost_members' => $countIf($pdo, 'feo_request_cost_block_requests'),
        'utilizer_tables' => $tableExists($pdo, 'feo_utilizers') + $tableExists($pdo, 'feo_utilizer_match_rules') + $tableExists($pdo, 'feo_request_utilizer_overrides'),
        'utilizers' => $countIf($pdo, 'feo_utilizers'),
        'utilizer_rules' => $countIf($pdo, 'feo_utilizer_match_rules'),
        'utilizer_overrides' => $countIf($pdo, 'feo_request_utilizer_overrides'),
        'migration_122' => $migration($pdo, '122_create_feo_requests.sql'),
        'migration_123' => $migration($pdo, '123_create_feo_map_placement.sql'),
        'migration_124' => $migration($pdo, '124_create_feo_request_costs.sql'),
        'migration_125' => $migration($pdo, '125_create_feo_map_utilizers.sql'),
    ];
}

echo 'FEO_125_SNAPSHOT=' . json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
echo 'FEO_125_READ_ONLY_OK' . PHP_EOL;
