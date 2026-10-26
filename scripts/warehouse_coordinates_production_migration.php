<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') throw new RuntimeException('CLI only');
$root = rtrim((string)getenv('PLANEX_ROOT'), '/');
$source = (string)getenv('PLANEX_SOURCE_SHA');
$marker = (string)getenv('PLANEX_DEPLOY_MARKER');
$expectedDatabase = (string)getenv('PLANEX_EXPECTED_LEGACY_DB');
$expectedSqlHash = (string)getenv('PLANEX_SQL_SHA256');
$mode = (string)getenv('WAREHOUSE_COORDINATES_MODE');
if (!in_array($mode, ['check','apply','verify'], true)
    || !preg_match('/^[0-9a-f]{40}$/D', $source)
    || !preg_match('/^[0-9a-f]{64}$/D', $expectedSqlHash)
    || !preg_match('/^[A-Za-z0-9_]{1,64}$/D', $expectedDatabase)
    || !is_file($marker) || trim((string)file_get_contents($marker)) !== $source
    || !is_file($root.'/.env') || is_link($root.'/.env')) throw new RuntimeException('Exact deployed migration context required');
if ($mode === 'apply' && getenv('WAREHOUSE_COORDINATES_CONFIRM') !== 'APPLY_WAREHOUSE_COORDINATES') {
    throw new RuntimeException('Explicit migration confirmation required');
}
$sqlPath = $root.'/database/migrations-legacy/002_warehouse_coordinates.sql';
$sql = file_get_contents($sqlPath);
if ($sql === false || hash('sha256', $sql) !== $expectedSqlHash) throw new RuntimeException('Migration source differs from approved SHA');
$config = require $root.'/bootstrap/app.php';
require $root.'/app/Support/entrypoint_dependencies.php';
require_once $root.'/app/Service/WasteTripLegacySourceService.php';
require_once $root.'/app/Service/WarehouseTripCorrectionService.php';
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
$warehouseCount = (int)$reader->query('SELECT COUNT(*) FROM warehouses')->fetchColumn();
if ($warehouseCount < 1) throw new RuntimeException('Warehouse registry unexpectedly empty');
$columns = $reader->query('SHOW COLUMNS FROM warehouse_contacts')->fetchAll(PDO::FETCH_COLUMN);
foreach (['warehouse_id','contacts_json','updated_by_user_id','updated_at'] as $column) {
    if (!in_array($column, $columns, true)) throw new RuntimeException('Warehouse contacts table shape mismatch');
}
$latitude = in_array('latitude', $columns, true);
$longitude = in_array('longitude', $columns, true);
if ($latitude !== $longitude || count($columns) !== ($latitude ? 6 : 4)) {
    throw new RuntimeException('Warehouse coordinate columns are partial or unexpected');
}
if ($mode === 'apply' && !$latitude) {
    $writer->exec($sql);
    $latitude = true;
}
if ($mode === 'verify' && !$latitude) throw new RuntimeException('Warehouse coordinate columns missing');
if ($latitude) {
    $inspection = $mode === 'apply' ? $writer : $reader;
    $columns = $inspection->query('SHOW COLUMNS FROM warehouse_contacts')->fetchAll(PDO::FETCH_COLUMN);
    if (count($columns) !== 6 || !in_array('latitude', $columns, true) || !in_array('longitude', $columns, true)) {
        throw new RuntimeException('Warehouse coordinate columns failed verification');
    }
    $invalid = (int)$inspection->query('SELECT COUNT(*) FROM warehouse_contacts WHERE (latitude IS NULL) <> (longitude IS NULL) OR latitude NOT BETWEEN -90 AND 90 OR longitude NOT BETWEEN -180 AND 180')->fetchColumn();
    if ($invalid !== 0) throw new RuntimeException('Existing warehouse coordinates invalid');
    $writer->query("EXPLAIN INSERT INTO warehouse_contacts (warehouse_id,contacts_json,updated_by_user_id,updated_at,latitude,longitude) VALUES (0,JSON_ARRAY(),0,CURRENT_TIMESTAMP(6),NULL,NULL)");
    $writer->query("EXPLAIN UPDATE warehouse_contacts SET latitude=NULL,longitude=NULL WHERE warehouse_id=0");
}
echo 'WAREHOUSE_COORDINATES_MIGRATION='.json_encode([
    'mode'=>$mode, 'source_sha'=>$source, 'database'=>$expectedDatabase,
    'warehouses'=>$warehouseCount, 'coordinates_columns'=>$latitude ? 'present' : 'absent',
], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
echo 'WAREHOUSE_COORDINATES_'.strtoupper($mode).'_PASS'.PHP_EOL;
