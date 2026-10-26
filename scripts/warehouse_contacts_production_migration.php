<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') throw new RuntimeException('CLI only');
$root = rtrim((string)getenv('PLANEX_ROOT'), '/');
$source = (string)getenv('PLANEX_SOURCE_SHA');
$marker = (string)getenv('PLANEX_DEPLOY_MARKER');
$expectedDatabase = (string)getenv('PLANEX_EXPECTED_LEGACY_DB');
$expectedSqlHash = (string)getenv('PLANEX_SQL_SHA256');
$mode = (string)getenv('WAREHOUSE_CONTACTS_MODE');
if (!in_array($mode, ['check','apply','verify'], true)
    || !preg_match('/^[0-9a-f]{40}$/D', $source)
    || !preg_match('/^[0-9a-f]{64}$/D', $expectedSqlHash)
    || !preg_match('/^[A-Za-z0-9_]{1,64}$/D', $expectedDatabase)
    || !is_file($marker) || trim((string)file_get_contents($marker)) !== $source
    || !is_file($root.'/.env') || is_link($root.'/.env')) throw new RuntimeException('Exact deployed migration context required');
if ($mode === 'apply' && getenv('WAREHOUSE_CONTACTS_CONFIRM') !== 'APPLY_WAREHOUSE_CONTACTS') {
    throw new RuntimeException('Explicit migration confirmation required');
}
$sqlPath = $root.'/database/migrations-legacy/001_warehouse_contacts.sql';
$sql = file_get_contents($sqlPath);
if ($sql === false || hash('sha256', $sql) !== $expectedSqlHash) throw new RuntimeException('Migration source differs from approved SHA');
$config = require $root.'/bootstrap/app.php';
require $root.'/app/Support/entrypoint_dependencies.php';
require_once $root.'/app/Service/WasteTripLegacySourceService.php';
require_once $root.'/app/Service/WarehouseTripCorrectionService.php';
require_once $root.'/app/Service/WarehouseDirectoryService.php';
if (trim((string)env('LEGACY_FLIGHTS_DB_DATABASE', '')) !== $expectedDatabase
    || trim((string)env('FEO_LEGACY_SYNC_DATABASE', '')) !== $expectedDatabase) {
    throw new RuntimeException('Legacy warehouse database identity mismatch');
}
$tenantName = trim((string)env('FEO_LEGACY_SYNC_TENANT_DATABASE', ''));
if (!preg_match('/^[A-Za-z0-9_]{1,64}$/D', $tenantName)) throw new RuntimeException('Tenant binding missing');
$central = (new App\Core\Database($config['database']))->connection();
$company = $central->prepare("SELECT * FROM companies WHERE db_identifier=? AND status='active'");
$company->execute([$tenantName]);
$companies = $company->fetchAll(PDO::FETCH_ASSOC);
if (count($companies) !== 1) throw new RuntimeException('Expected exactly one active tenant binding');
$tenant = (new App\Core\Database(companyDatabaseConfig($config, $companies[0])))->connection();
[$writer, $writerDatabase] = App\Service\WarehouseTripCorrectionService::writeConnection($tenant);
$reader = App\Service\WasteTripLegacySourceService::connectionFromEnvironment();
if (!$reader || $writerDatabase !== $expectedDatabase
    || (string)$writer->query('SELECT DATABASE()')->fetchColumn() !== $expectedDatabase
    || (string)$reader->query('SELECT DATABASE()')->fetchColumn() !== $expectedDatabase) {
    throw new RuntimeException('Warehouse reader/writer target mismatch');
}
$addressColumn = App\Service\WarehouseDirectoryService::addressColumn($reader);
$warehouseCount = (int)$reader->query('SELECT COUNT(*) FROM warehouses')->fetchColumn();
if ($warehouseCount < 1) throw new RuntimeException('Warehouse registry unexpectedly empty');
$exists = (int)$reader->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='warehouse_contacts'")->fetchColumn() === 1;
if ($mode === 'apply' && !$exists) {
    $writer->exec($sql);
    $exists = true;
}
if ($mode === 'verify' && !$exists) throw new RuntimeException('Warehouse contacts table missing');
if ($exists) {
    $columns = $reader->query('SHOW COLUMNS FROM warehouse_contacts')->fetchAll(PDO::FETCH_COLUMN);
    foreach (['warehouse_id','contacts_json','updated_by_user_id','updated_at'] as $column) {
        if (!in_array($column, $columns, true)) throw new RuntimeException('Warehouse contacts table shape mismatch');
    }
    if (count($columns) !== 4) throw new RuntimeException('Warehouse contacts table has unexpected fields');
    $rows = (int)$reader->query('SELECT COUNT(*) FROM warehouse_contacts')->fetchColumn();
    if ($rows !== 0) throw new RuntimeException('New contact table unexpectedly contains records');
    // EXPLAIN checks the effective INSERT/UPDATE grants without changing a business row.
    $writer->query("EXPLAIN INSERT INTO warehouse_contacts (warehouse_id,contacts_json,updated_by_user_id,updated_at) VALUES (0,JSON_ARRAY(),0,CURRENT_TIMESTAMP(6))");
    $writer->query("EXPLAIN UPDATE warehouse_contacts SET contacts_json=JSON_ARRAY() WHERE warehouse_id=0");
}
echo 'WAREHOUSE_CONTACTS_MIGRATION='.json_encode([
    'mode'=>$mode, 'source_sha'=>$source, 'database'=>$expectedDatabase,
    'warehouses'=>$warehouseCount, 'address_column'=>$addressColumn,
    'contacts_table'=>$exists ? 'present' : 'absent', 'contacts_rows'=>$exists ? 0 : null,
], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
echo 'WAREHOUSE_CONTACTS_'.strtoupper($mode).'_PASS'.PHP_EOL;
