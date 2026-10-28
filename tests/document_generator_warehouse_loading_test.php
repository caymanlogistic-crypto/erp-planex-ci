<?php
if (PHP_SAPI !== 'cli') exit(1);
require_once dirname(__DIR__).'/bootstrap/app.php';
require_once dirname(__DIR__).'/app/Service/DocumentGeneratorWarehouseLoadingService.php';
require_once dirname(__DIR__).'/app/Service/DocumentGeneratorWarehouseUnloadingService.php';

use App\Service\DocumentGeneratorWarehouseLoadingService as Loading;
use App\Service\DocumentGeneratorWarehouseUnloadingService as Unloading;

$check = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
$host = getenv('PLANEX_SCHEMA_DB_HOST') ?: '127.0.0.1';
$port = getenv('PLANEX_SCHEMA_DB_PORT') ?: '3306';
$schema = getenv('PLANEX_TEST_FIXED_DB') ?: '';
if ($schema === '' || !in_array($schema, explode(',', getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST') ?: ''), true)) {
    throw new RuntimeException('Run through the isolated local test wrapper.');
}
$pdo = new PDO("mysql:host=$host;port=$port;dbname=$schema;charset=utf8mb4", getenv('PLANEX_SCHEMA_DB_USER') ?: '', getenv('PLANEX_SCHEMA_DB_PASSWORD') ?: '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE warehouses(id INT PRIMARY KEY,name VARCHAR(255) NOT NULL,full_address VARCHAR(255) NULL)');
$pdo->exec('CREATE TABLE flights(id INT PRIMARY KEY,route_type VARCHAR(64),source_warehouse_id INT,destination_warehouse_id INT,zayavki_ids TEXT,direct_zayavki_ids TEXT)');
$pdo->exec('CREATE TABLE warehouse_movements(id INT PRIMARY KEY,flight_id INT,warehouse_id INT,zayavka_id INT,movement_type VARCHAR(32),status VARCHAR(20),mass_netto DECIMAL(12,4))');
$pdo->exec("INSERT INTO warehouses VALUES(1,'Склад Север','Москва, Складская, 1'),(2,'Склад Юг','Москва, Южная, 2')");
$pdo->exec("INSERT INTO flights VALUES(77,'warehouse_to_utilizer',1,NULL,'300001,300002,300003,300004,300005,300006',NULL),
    (351,'mixed_to_utilizer',1,NULL,'291382,300001,300002','291382'),
    (353,'mixed_to_utilizer',1,NULL,'312015,310867,322245,321828,321391','312015'),
    (401,'generator_to_warehouse',NULL,2,'300001',NULL)");
$pdo->exec("INSERT INTO warehouse_movements VALUES
    (1,76,1,300001,'receipt','active',1),
    (2,77,1,300001,'issue','active',1),
    (3,76,1,300002,'receipt','active',1),
    (4,77,1,300002,'issue','cancelled',1),
    (5,76,2,300003,'receipt','active',1),
    (6,76,1,300004,'receipt','active',1),
    (7,78,1,300004,'issue','active',1),
    (8,76,1,300005,'transfer_in','active',1),
    (9,77,1,300005,'transfer_out','active',1),
    (10,76,1,300006,'receipt','active',2),
    (11,78,1,300006,'issue','active',1),
    (12,351,1,291382,'receipt','active',1)");
$loading = Loading::resolve($pdo, ['legacy_source_warehouse_id'=>1,'legacy_flight_id'=>77], ['300001','300002','300003','300004','300005','300006']);
$check($loading['address'] === 'Москва, Складская, 1', 'Warehouse address');
$check($loading['request_ids'] === ['300001','300002','300005','300006'], 'Current stock and withdrawals for this trip only');
$rows = Loading::apply([
    ['zayavka_id'=>'300001','mno_adres_pogruzki'=>'ФЭО 1','contacts'=>'Телефон 1'],
    ['zayavka_id'=>'300002','mno_adres_pogruzki'=>'ФЭО 2','contacts'=>'Телефон 2'],
    ['zayavka_id'=>'300004','mno_adres_pogruzki'=>'ФЭО 4','contacts'=>'Телефон 4'],
], $loading);
$check($rows[0]['document_loading_address'] === $loading['address'] && $rows[0]['contacts'] === '—', 'Warehouse row substitution');
$check($rows[1]['document_loading_address'] === $loading['address'] && $rows[1]['contacts'] === '—', 'Stock before withdrawal');
$check(!isset($rows[2]['document_loading_address']) && $rows[2]['contacts'] === 'Телефон 4', 'Request withdrawn by another trip unchanged');
$mixed351 = Loading::resolve($pdo, ['legacy_source_warehouse_id'=>1,'legacy_flight_id'=>351], ['291382']);
$check($mixed351['trip_request_ids'] === ['291382','300001','300002'], 'Mixed trip includes warehouse requests absent from ERP links');
$check($mixed351['request_ids'] === ['300001','300002'], 'Mixed warehouse group excludes direct request even with warehouse stock');
$mixedRows = Loading::apply([
    ['zayavka_id'=>'291382','mno_adres_pogruzki'=>'Адрес ОО','contacts'=>'Контакт ОО'],
    ['zayavka_id'=>'300001','mno_adres_pogruzki'=>'Адрес ОО 2','contacts'=>'Контакт ОО 2'],
], $mixed351);
$check(!isset($mixedRows[0]['document_loading_address']) && $mixedRows[0]['contacts'] === 'Контакт ОО', 'Direct request keeps original loading and contact');
$check($mixedRows[1]['document_loading_address'] === 'Москва, Складская, 1' && $mixedRows[1]['contacts'] === '—', 'Mixed warehouse request uses warehouse loading');
$mixed353 = Loading::resolve($pdo, ['legacy_source_warehouse_id'=>1,'legacy_flight_id'=>353], ['312015','310867']);
$check($mixed353['request_ids'] === ['310867','322245','321828','321391'], 'Mixed trip 353 warehouse request group');
$check($mixed353['trip_request_ids'] === ['312015','310867','322245','321828','321391'], 'Mixed trip 353 complete contract request list');
$stale351 = Loading::resolve($pdo, ['legacy_source_warehouse_id'=>1,'legacy_flight_id'=>351], ['312015','291382','310867','300001']);
$check($stale351['trip_request_ids'] === ['291382','300001','300002'], 'Mixed contract uses current source composition');
$check($stale351['ignored_request_ids'] === ['312015','310867'], 'Stale ERP requests are identified for warning');
$unload = Unloading::resolve($pdo, ['legacy_destination_warehouse_id'=>2,'legacy_route_type'=>'generator_to_warehouse','legacy_flight_id'=>401]);
$check($unload === 'Москва, Южная, 2', 'Warehouse destination address');
$unloadedRows = Unloading::apply([['zayavka_id'=>'300001','adres_vygruzki_otkhodov'=>'Старый адрес']], $unload);
$check($unloadedRows[0]['document_unloading_address'] === $unload, 'Warehouse destination substitution');
$mismatch = false;
try { Unloading::resolve($pdo, ['legacy_destination_warehouse_id'=>1,'legacy_route_type'=>'generator_to_warehouse','legacy_flight_id'=>401]); }
catch (RuntimeException) { $mismatch = true; }
$check($mismatch, 'Warehouse destination mismatch blocks generation');
echo "DOCUMENT_GENERATOR_WAREHOUSE_LOADING=PASS\n";
