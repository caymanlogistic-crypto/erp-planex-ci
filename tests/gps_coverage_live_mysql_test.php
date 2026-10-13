<?php

declare(strict_types=1);
require_once dirname(__DIR__).'/app/Service/GpsCoverageService.php';

use App\Service\GpsCoverageService;

$dbName=getenv('PLANEX_TEST_FIXED_DB')?:'';
$allow=array_filter(explode(',',getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST')?:''));
if($dbName===''||!in_array($dbName,$allow,true))throw new RuntimeException('Isolated runner database required');
$host=getenv('PLANEX_SCHEMA_DB_HOST')?:'127.0.0.1';$port=(int)(getenv('PLANEX_SCHEMA_DB_PORT')?:3306);
$user=getenv('PLANEX_SCHEMA_DB_USER')?:'';$password=getenv('PLANEX_SCHEMA_DB_PASSWORD')?:'';
$pdo=new PDO("mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4",$user,$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$pdo->exec("SET time_zone='+00:00'");
$pdo->exec('CREATE TABLE drivers(id INT PRIMARY KEY,full_name VARCHAR(255),vehicle_make_plate VARCHAR(255))');
$pdo->exec('CREATE TABLE flights(id INT PRIMARY KEY,status VARCHAR(30),driver_id INT,comment TEXT,actual_start_date DATE,actual_end_date DATE)');
$pdo->exec('CREATE TABLE tracker_log(id INT AUTO_INCREMENT PRIMARY KEY,uniqueid VARCHAR(64),name VARCHAR(255),lastupdate DATETIME,lat DECIMAL(10,6),lon DECIMAL(10,6),fetched_at DATETIME,KEY idx_fetched_at(fetched_at))');
$pdo->exec("INSERT INTO drivers VALUES(1,'Иванов Иван','КАМАЗ А123ВС77')");
$pdo->exec("INSERT INTO flights VALUES(244,'completed',1,'Проверочный рейс','2026-09-01','2026-09-01'),(245,'started',1,'В пути','2026-09-01',NULL)");
$pdo->exec("INSERT INTO tracker_log(uniqueid,name,lastupdate,lat,lon,fetched_at) VALUES
 ('guard','ДРУГОЙ (ПЕТРОВ)','2026-08-31 20:00:00',55.1,37.1,'2026-08-31 20:01:00'),
 ('device-1','А123ВС77 (ИВАНОВ)','2026-08-31 21:00:00',55.1,37.1,'2026-08-31 21:01:00'),
 ('device-1','А123ВС77 (ИВАНОВ)','2026-09-01 00:00:00',55.1,37.1,'2026-09-02 22:00:00')");
$page=GpsCoverageService::page($pdo);
if($page['total']!==2||count($page['rows'])!==2||$page['rows'][0]['id']!==245)throw new RuntimeException('Live flight page mismatch');
$first=GpsCoverageService::flight($pdo,244,15);
if($first['assessment']!=='calculated'||$first['covered_seconds']!==900||$first['gaps'][0]['kind']!=='trailing')throw new RuntimeException('Initial coverage mismatch '.json_encode($first));
// A new GPS row changes the service result without changing application files.
$pdo->exec("INSERT INTO tracker_log(uniqueid,name,lastupdate,lat,lon,fetched_at) VALUES('device-1','А123ВС77 (ИВАНОВ)','2026-08-31 21:10:00',55.1,37.1,'2026-08-31 21:11:00')");
$next=GpsCoverageService::flight($pdo,244,15);
if($next['covered_seconds']!==1500||$next['pct']<1.7||$next['pct']>1.8)throw new RuntimeException('Live GPS mutation did not change coverage '.json_encode($next));
$ongoing=GpsCoverageService::flight($pdo,245,15);
if(!in_array($ongoing['assessment'],['long_interval','calculated'],true))throw new RuntimeException('Ongoing flight was not assessed');
echo "GPS_LIVE_MYSQL_PASS\n";
