<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'cgi-fcgi') { fwrite(STDERR, "CLI/CGI only.\n"); exit(1); }

$root = rtrim((string)(getenv('PLANEX_ROOT') ?: ($argv[1] ?? '')), '/');
if ($root === '' || !is_file($root . '/.env') || !is_file($root . '/bootstrap/app.php')) throw new RuntimeException('PLANEX_ROOT invalid');
$migrationPath = (string)(getenv('PLANEX_MIGRATION_PATH') ?: ($argv[2] ?? ($root . '/database/migrations-local/122_create_feo_requests.sql')));
if (!is_file($migrationPath)) throw new RuntimeException('Migration 122 missing');
$sql = file_get_contents($migrationPath);
if (!is_string($sql) || trim($sql) === '') throw new RuntimeException('Migration 122 empty');
$checksum = hash('sha256', $sql);
$name = '122_create_feo_requests.sql';

$config = require $root . '/bootstrap/app.php';
require_once $root . '/app/Support/crypto_helper.php';
require_once $root . '/app/Support/company_database.php';

$centralCfg = $config['database'];
$central = new PDO(
    'mysql:host=' . $centralCfg['host'] . ';port=' . ($centralCfg['port'] ?? 3306) . ';dbname=' . $centralCfg['database'] . ';charset=utf8mb4',
    $centralCfg['username'], $centralCfg['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$companies = $central->query("SELECT id,name,status,db_identifier,db_host,db_port,db_username,db_password FROM companies WHERE status='active' AND db_identifier IS NOT NULL AND db_identifier<>'' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
if ($companies === []) throw new RuntimeException('No active tenant databases found');

$applied = 0; $existing = 0;
foreach ($companies as $company) {
    $cfg = companyDatabaseConfig($config, $company);
    $pdo = new PDO(
        'mysql:host=' . $cfg['host'] . ';port=' . ($cfg['port'] ?? 3306) . ';dbname=' . $cfg['database'] . ';charset=utf8mb4',
        $cfg['username'], $cfg['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    if ((string)$pdo->query('SELECT DATABASE()')->fetchColumn() !== (string)$company['db_identifier']) throw new RuntimeException('Unexpected tenant DB for company ' . $company['id']);
    $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,migration VARCHAR(255) NOT NULL UNIQUE,checksum VARCHAR(64) NOT NULL,executed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $lockName = 'planex_feo_122_' . substr(hash('sha256', (string)$company['db_identifier']), 0, 20);
    $lock = $pdo->prepare('SELECT GET_LOCK(?,10)'); $lock->execute([$lockName]);
    if ((int)$lock->fetchColumn() !== 1) throw new RuntimeException('Lock failed for company ' . $company['id']);
    $state = '';
    try {
        $q = $pdo->prepare('SELECT checksum FROM schema_migrations WHERE migration=?'); $q->execute([$name]); $stored = $q->fetchColumn();
        if ($stored !== false) {
            if (!hash_equals((string)$stored, $checksum)) throw new RuntimeException('Checksum mismatch for company ' . $company['id']);
            $state = 'existing'; $existing++;
        } else {
            $newer = (int)$pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE migration REGEXP '^[0-9]{3}_' AND CAST(LEFT(migration,3) AS UNSIGNED)>122")->fetchColumn();
            if ($newer > 0) throw new RuntimeException('Newer tenant migration exists for company ' . $company['id']);
            $pdo->exec($sql);
            $q = $pdo->prepare('INSERT INTO schema_migrations(migration,checksum) VALUES(?,?)'); $q->execute([$name,$checksum]);
            $state = 'applied'; $applied++;
        }
        $tables = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('feo_requests','feo_imports','feo_import_changes')")->fetchColumn();
        $cols = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='feo_requests' AND COLUMN_NAME IN ('zayavka_id','current_import_id','zakaz_s_op_stoimost')")->fetchColumn();
        if ($tables !== 3 || $cols !== 3) throw new RuntimeException('FEO 122 schema verification failed for company ' . $company['id']);
        printf("FEO_122_COMPANY_OK id=%d db=%s state=%s\n", (int)$company['id'], $company['db_identifier'], $state);
    } finally {
        $release = $pdo->prepare('SELECT RELEASE_LOCK(?)'); $release->execute([$lockName]);
    }
}
printf("FEO_122_OK tenants=%d applied=%d existing=%d checksum=%s\n", count($companies), $applied, $existing, $checksum);
