<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') throw new RuntimeException('CLI only');
$root = rtrim((string)getenv('PLANEX_ROOT'), '/');
$marker = (string)getenv('PLANEX_DEPLOY_MARKER');
$deployedSha = (string)getenv('PLANEX_DEPLOYED_SHA');
$expectedDatabase = (string)getenv('PLANEX_EXPECTED_LEGACY_DB');
if (!preg_match('/^[0-9a-f]{40}$/D', $deployedSha)
    || !preg_match('/^[A-Za-z0-9_]{1,64}$/D', $expectedDatabase)
    || !is_file($marker) || trim((string)file_get_contents($marker)) !== $deployedSha
    || !is_file($root.'/.env') || is_link($root.'/.env')) {
    throw new RuntimeException('Exact deployed read-only context required');
}

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
$legacy = App\Service\WasteTripLegacySourceService::connectionFromEnvironment();
if (!$legacy instanceof PDO || (string)$legacy->query('SELECT DATABASE()')->fetchColumn() !== $expectedDatabase) {
    throw new RuntimeException('Legacy warehouse reader target mismatch');
}
if ($writerDatabase !== $expectedDatabase || (string)$writer->query('SELECT DATABASE()')->fetchColumn() !== $expectedDatabase) {
    throw new RuntimeException('Legacy warehouse writer target mismatch');
}
$legacy->exec('SET SESSION TRANSACTION READ ONLY');
$legacy->beginTransaction();
try {
    $columns = $legacy->query("SELECT TABLE_NAME,COLUMN_NAME,DATA_TYPE,COLUMN_TYPE,IS_NULLABLE
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('warehouses','warehouse_contacts')
        ORDER BY TABLE_NAME,ORDINAL_POSITION")->fetchAll(PDO::FETCH_ASSOC);
    $warehouseColumns = array_values(array_filter($columns, static fn(array $row): bool => $row['TABLE_NAME'] === 'warehouses'));
    if ($warehouseColumns === []) throw new RuntimeException('Legacy warehouses table absent');
    $warehouseRows = (int)$legacy->query('SELECT COUNT(*) FROM warehouses')->fetchColumn();
    $coordinateCandidates = [];
    foreach ($warehouseColumns as $column) {
        $name = (string)$column['COLUMN_NAME'];
        if (!preg_match('/lat|lon|coord|geo|point|location|shir|dolg|шир|долг/iu', $name)) continue;
        if (!preg_match('/^[A-Za-z0-9_]{1,64}$/D', $name)) throw new RuntimeException('Unsupported coordinate column name');
        $nonNull = (int)$legacy->query('SELECT COUNT(*) FROM warehouses WHERE `'.$name.'` IS NOT NULL')->fetchColumn();
        $coordinateCandidates[] = ['column'=>$name, 'type'=>$column['DATA_TYPE'], 'non_null_rows'=>$nonNull];
    }
    $relatedCoordinateColumns = $legacy->query("SELECT TABLE_NAME,COLUMN_NAME,DATA_TYPE
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE '%warehouse%'
          AND (COLUMN_NAME LIKE '%lat%' OR COLUMN_NAME LIKE '%lon%'
            OR COLUMN_NAME LIKE '%coord%' OR COLUMN_NAME LIKE '%geo%'
            OR COLUMN_NAME LIKE '%point%' OR COLUMN_NAME LIKE '%location%')
        ORDER BY TABLE_NAME,ORDINAL_POSITION LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
    $writer->query('EXPLAIN UPDATE `'.$expectedDatabase.'`.`warehouses` SET full_address=full_address,latitude=latitude,longitude=longitude WHERE id=0');
    $legacy->commit();
} catch (Throwable $error) {
    if ($legacy->inTransaction()) $legacy->rollBack();
    throw $error;
}
echo 'WAREHOUSE_LEGACY_COORDINATES='.json_encode([
    'database'=>$expectedDatabase, 'warehouse_rows'=>$warehouseRows,
    'warehouse_columns'=>$warehouseColumns, 'coordinate_candidates'=>$coordinateCandidates,
    'related_coordinate_columns'=>$relatedCoordinateColumns,
    'contacts_columns'=>array_values(array_filter($columns, static fn(array $row): bool => $row['TABLE_NAME'] === 'warehouse_contacts')),
    'warehouse_writer_update_explain'=>'pass',
], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
echo "WAREHOUSE_LEGACY_COORDINATES_READ_ONLY_PASS\n";
