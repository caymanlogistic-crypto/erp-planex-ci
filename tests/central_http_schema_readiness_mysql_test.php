<?php
declare(strict_types=1);

$schema = (string)getenv('PLANEX_TEST_FIXED_DB');
if (getenv('APP_ENV') !== 'test' || !preg_match('/^planex_t_[a-f0-9]{24}$/D', $schema)
    || getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST') !== $schema) throw new RuntimeException('Unique disposable test schema required');
define('PLANEX_CENTRAL_HTTP_SCHEMA_PROBE_LIBRARY_ONLY', true);
require dirname(__DIR__).'/scripts/central_http_schema_readiness_probe.php';
$pdo = new PDO('mysql:host='.getenv('PLANEX_SCHEMA_DB_HOST').';port='.getenv('PLANEX_SCHEMA_DB_PORT').';dbname='.$schema.';charset=utf8mb4',
    getenv('PLANEX_SCHEMA_DB_USER'), getenv('PLANEX_SCHEMA_DB_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);

$absent = CentralHttpSchemaReadinessProbe::inspect($pdo);
if ($absent['ready'] || count($absent['missing_columns']) !== 10) throw new RuntimeException('Absent central schema accepted');
$pdo->exec('CREATE TABLE schema_migrations (migration VARCHAR(255) PRIMARY KEY)');
$pdo->exec('CREATE TABLE companies (bank_account VARCHAR(32), bank_name VARCHAR(255), bank_bik VARCHAR(16), bank_corr_account VARCHAR(32))');
$pdo->exec('CREATE TABLE company_db_pool_usage (id INT, db_identifier VARCHAR(255), company_id INT, created_at DATETIME, released_at DATETIME, note VARCHAR(255))');
$pdo->exec("INSERT INTO schema_migrations VALUES ('012_add_company_bank_requisites.sql')");
$ready = CentralHttpSchemaReadinessProbe::inspect($pdo);
if (!$ready['ready']) throw new RuntimeException('Complete central schema rejected');
$pdo->exec('ALTER TABLE companies DROP COLUMN bank_bik');
$drift = CentralHttpSchemaReadinessProbe::inspect($pdo);
if ($drift['ready'] || !in_array('companies.bank_bik', $drift['missing_columns'], true)) throw new RuntimeException('Missing bank column accepted');
$pdo->exec('ALTER TABLE companies ADD COLUMN bank_bik VARCHAR(16)');
$pdo->exec("DELETE FROM schema_migrations WHERE migration='012_add_company_bank_requisites.sql'");
$unlogged = CentralHttpSchemaReadinessProbe::inspect($pdo);
if ($unlogged['ready'] || $unlogged['bank_migration']) throw new RuntimeException('Missing central migration accepted');
echo "CENTRAL_HTTP_SCHEMA_READINESS_MYSQL=PASS absent=REJECTED ready=PASS drifted=REJECTED unlogged=REJECTED\n";
