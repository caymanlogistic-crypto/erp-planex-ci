<?php
declare(strict_types=1);
namespace App\Service {
    final class AccessControlService { public static function canSeeAllCompanyData(array $u):bool{return true;} public static function effectiveMembershipId():?int{return null;} }
    final class RegulatedDataScopeService { public static function condition(string $entityType,string $idExpression,string $creatorExpression=''):array{return['sql'=>'1 = 1','params'=>[]];} public static function assertCanCreate(string $entityType):int{return 1;} public static function canAccess(\PDO $pdo,string $entityType,string $table,int $entityId):bool{return true;} }
    final class LinearRouteService {
        public static function isVisibleEntity(\PDO $p,string $t,int $id,array $u,string $k):bool{return$id>0;}
        public static function fetchVisibleRouteExecutors(\PDO $p,array $u):array{return[];}
    }
}
namespace {
use App\Service\WasteTripLegacyPrefillService;
use App\Service\WasteTripLegacySourceService;
use App\Service\WasteTripLegacySyncService;
use App\Service\WasteTripService;

$root=dirname(__DIR__);
require_once $root.'/app/Service/WasteTripLegacySourceService.php';
require_once $root.'/app/Service/WasteTripService.php';
require_once $root.'/app/Service/WasteTripLegacySyncService.php';
require_once $root.'/app/Service/WasteTripLegacyPrefillService.php';
require_once $root.'/app/Support/company_database.php';
$fail=static function(string$m):never{fwrite(STDERR,"FAIL: {$m}\n");exit(1);};
$ok=static function(bool$v,string$m)use($fail):void{if(!$v)$fail($m);};
if(!extension_loaded('pdo_mysql')){fwrite(STDERR,"pdo_mysql required for legacy prefill regression\n");exit(2);}
$host=getenv('PLANEX_SCHEMA_DB_HOST')?:'127.0.0.1';$port=(int)(getenv('PLANEX_SCHEMA_DB_PORT')?:3306);$user=getenv('PLANEX_SCHEMA_DB_USER')?:'planex_ci';$pass=getenv('PLANEX_SCHEMA_DB_PASSWORD')?:'planex_ci';
$fixedDb=(string)(getenv('PLANEX_TEST_FIXED_DB')?:'');$fixedMode=$fixedDb!=='';
if($fixedMode){$allow=array_values(array_filter(array_map('trim',explode(',',(string)(getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST')?:'')))));$ok((string)(getenv('APP_ENV')?:'')==='test','fixed DB requires APP_ENV=test');$ok(in_array($fixedDb,$allow,true),'fixed DB is not OWNER-approved');}
$admin=new PDO("mysql:host={$host};port={$port};charset=utf8mb4",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$db=$fixedMode?$fixedDb:'planex_waste_prefill_'.getmypid();$legacyDb=$fixedMode?$fixedDb:$db.'_legacy';
if($fixedMode){$fixedPdo=new PDO("mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);cleanCompanyPoolDatabase($fixedPdo);$fixedPdo=null;}else{$admin->exec("DROP DATABASE IF EXISTS `{$db}`;CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;DROP DATABASE IF EXISTS `{$legacyDb}`;CREATE DATABASE `{$legacyDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");}
try{
$pdo=new PDO("mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$legacy=new PDO("mysql:host={$host};port={$port};dbname={$legacyDb};charset=utf8mb4",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$pdo->exec("CREATE TABLE clients(id INT UNSIGNED PRIMARY KEY,name VARCHAR(50));INSERT INTO clients VALUES(1,'C');
CREATE TABLE users(id INT UNSIGNED PRIMARY KEY,full_name VARCHAR(50),role_code VARCHAR(20),status VARCHAR(20),deleted_at DATETIME NULL);INSERT INTO users VALUES(1,'U','company_owner','active',NULL);
CREATE TABLE feo_utilizers(id BIGINT UNSIGNED PRIMARY KEY,short_name VARCHAR(50),full_name VARCHAR(50),address VARCHAR(100),is_active TINYINT,sort_order INT);INSERT INTO feo_utilizers VALUES(1,'F','F','A',1,1);
CREATE TABLE contractors(id INT UNSIGNED PRIMARY KEY,name VARCHAR(50));CREATE TABLE crews(id INT UNSIGNED PRIMARY KEY,contractor_id INT UNSIGNED NULL,driver_vehicle_block_id INT UNSIGNED NULL);CREATE TABLE crew_drivers(crew_id INT UNSIGNED,driver_id INT UNSIGNED,position INT);CREATE TABLE drivers(id INT UNSIGNED PRIMARY KEY,full_name VARCHAR(50));CREATE TABLE driver_vehicle_blocks(id INT UNSIGNED PRIMARY KEY,vehicle_set_id INT UNSIGNED);CREATE TABLE vehicle_sets(id INT UNSIGNED PRIMARY KEY,primary_vehicle_unit_id INT UNSIGNED,secondary_vehicle_unit_id INT UNSIGNED);CREATE TABLE vehicle_units(id INT UNSIGNED PRIMARY KEY,brand VARCHAR(50),plate_number VARCHAR(20));
CREATE TABLE feo_requests(id BIGINT UNSIGNED PRIMARY KEY,zayavka_id VARCHAR(50) UNIQUE,mass_netto DECIMAL(12,3),naim_oo_gruzootpravitel VARCHAR(50),mno_adres_pogruzki VARCHAR(100),naim_otkhoda_fkko VARCHAR(100),nomer_opasnogo_gr VARCHAR(20));INSERT INTO feo_requests VALUES(1,'1001',1.1,'S','A','W','1'),(2,'1002',1.2,'S','A','W','1'),(3,'1003',1.3,'S','A','W','1');
CREATE TABLE feo_placement_current(zayavka_id VARCHAR(50) PRIMARY KEY);INSERT INTO feo_placement_current VALUES('1001'),('1002'),('1003');
CREATE TABLE waste_trips(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,business_id BIGINT UNSIGNED NOT NULL,client_id INT UNSIGNED NOT NULL,route_executor_id INT UNSIGNED NULL,responsible_user_id INT UNSIGNED NOT NULL,destination_type ENUM('utilizer','warehouse') NOT NULL,utilizer_id BIGINT UNSIGNED NULL,status ENUM('searching_executor','executor_assigned','in_progress','completed','cancelled') NOT NULL,planned_start_mode ENUM('exact','range','unspecified') NOT NULL,planned_start_date DATE NULL,planned_start_date_from DATE NULL,planned_start_date_to DATE NULL,planned_finish_date DATE NULL,actual_start_date DATE NULL,actual_end_date DATE NULL,comment VARCHAR(500) NULL,cancellation_reason VARCHAR(500) NULL,cancelled_at DATETIME NULL,cancelled_by_user_id INT NULL,cancelled_by_role VARCHAR(20) NULL,source_system VARCHAR(50) NULL,legacy_flight_id BIGINT UNSIGNED NULL,legacy_sync_error VARCHAR(1000) NULL,legacy_last_checked_at DATETIME NULL,legacy_last_success_at DATETIME NULL,lock_version INT UNSIGNED NOT NULL DEFAULT 1,created_by_user_id INT NULL,created_by_membership_id BIGINT UNSIGNED NULL,created_by_role VARCHAR(20),updated_by_user_id INT NULL,updated_by_role VARCHAR(20),created_at DATETIME DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,UNIQUE KEY uk_waste_trip_business_id(business_id),UNIQUE KEY uk_waste_trip_legacy_identity(source_system,legacy_flight_id));
CREATE TABLE waste_trip_requests(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,waste_trip_id BIGINT UNSIGNED,feo_request_id BIGINT UNSIGNED,snapshot_mass_kg DECIMAL(18,3),position_no INT,UNIQUE KEY uk_waste_trip_request(waste_trip_id,feo_request_id));
CREATE TABLE waste_trip_audit_log(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,waste_trip_id BIGINT UNSIGNED,action ENUM('create','update','composition_correction','cancel'),before_json LONGTEXT,after_json LONGTEXT,actor_user_id INT,actor_role VARCHAR(20),created_at DATETIME DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE waste_trip_namespace_state(singleton_id TINYINT UNSIGNED PRIMARY KEY,legacy_high_water_at_cutover BIGINT UNSIGNED,last_issued_business_id BIGINT UNSIGNED,cutover_started_at DATETIME,cutover_completed_at DATETIME,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP);INSERT INTO waste_trip_namespace_state VALUES(1,261,261,NOW(),NOW(),NOW(),NOW());");
$legacy->exec("CREATE TABLE flights(id BIGINT UNSIGNED PRIMARY KEY,zayavki_ids TEXT,comment VARCHAR(500),planned_start_date DATE NULL,planned_start_date_from DATE NULL,planned_start_date_to DATE NULL,actual_start_date DATE NULL,actual_end_date DATE NULL);INSERT INTO flights VALUES(262,'1001','historical A','2026-09-01',NULL,NULL,NULL,NULL),(300,'1002,9999','legacy prefill A','2026-09-03',NULL,NULL,'2026-09-04',NULL);");
$userCtx=['user_id'=>1,'role_code'=>'company_owner'];$ops=['client_id'=>1,'responsible_user_id'=>1,'destination_type'=>'utilizer','utilizer_id'=>1,'status'=>'searching_executor'];

$flight300=WasteTripLegacySourceService::fetchFlight($legacy,300);$ok(is_array($flight300),'legacy 300 missing');$prefill=WasteTripLegacySourceService::prefillState($pdo,$flight300);$ok($prefill['found_request_ids']===['1002'],'found FEO prefill mismatch');$ok($prefill['missing_request_ids']===['9999'],'missing FEO reference mismatch');$ok(in_array('Заявка 9999 из старой базы не найдена в ERP',$prefill['warnings'],true),'missing FEO warning missing');$ok($prefill['planned_finish_date']===null,'planned finish must stay blank');

$historicalBusiness=WasteTripLegacySyncService::materialize($legacy,$pdo,262,$ops,$userCtx);$ok($historicalBusiness===262,'historical linked creation failed');
$historicalId=(int)$pdo->query('SELECT id FROM waste_trips WHERE business_id=262')->fetchColumn();
$prefillInput=$ops+['planned_start_mode'=>'exact','planned_start_date'=>'2026-09-10','planned_finish_date'=>'2026-09-20','actual_start_date'=>'2026-09-11','comment'=>'ERP user value'];
$prefillBusiness=WasteTripLegacyPrefillService::createFromLegacyPrefill($legacy,$pdo,300,$prefillInput,['1002'],$userCtx);$ok($prefillBusiness===300,'prefill business ID must equal flights.id');
$prefillRow=$pdo->query('SELECT * FROM waste_trips WHERE business_id=300')->fetch(PDO::FETCH_ASSOC);$ok(is_array($prefillRow),'prefill trip missing');$prefillId=(int)$prefillRow['id'];$ok($prefillRow['source_system']==='legacy_prefill'&&(int)$prefillRow['legacy_flight_id']===300,'prefill provenance mismatch');$ok($prefillRow['comment']==='ERP user value'&&$prefillRow['planned_start_date']==='2026-09-10'&&$prefillRow['planned_finish_date']==='2026-09-20','user values not persisted');
$dup=false;try{WasteTripLegacyPrefillService::createFromLegacyPrefill($legacy,$pdo,300,$prefillInput,['1002'],$userCtx);}catch(RuntimeException){$dup=true;}$ok($dup,'duplicate legacy_prefill transfer must fail by business identity');

$catalog=WasteTripLegacySourceService::listCatalog($legacy,$pdo,20);$catalog300=null;foreach($catalog as$r)if((int)$r['id']===300)$catalog300=$r;$ok(is_array($catalog300)&&$catalog300['materialized']===true&&(int)$catalog300['erp_waste_trip_id']===$prefillId,'catalog must expose transferred state/open target');
$linked=WasteTripService::linkedTripsForSync($pdo);$linkedBusiness=array_map('intval',array_column($linked,'business_id'));$ok(in_array(262,$linkedBusiness,true),'historical legacy_flights must be scheduled');$ok(!in_array(300,$linkedBusiness,true),'legacy_prefill must not be scheduled');$ok(count($linkedBusiness)===1,'only historical linked trip may enter scheduler in this fixture');

$edit=$ops+['planned_start_mode'=>'exact','planned_start_date'=>'2026-09-12','planned_finish_date'=>'2026-09-22','actual_start_date'=>'2026-09-13','comment'=>'ERP edited after save'];WasteTripService::update($pdo,$prefillId,$edit,['1003'],1,$userCtx);$edited=$pdo->query('SELECT comment,planned_start_date,planned_finish_date,source_system FROM waste_trips WHERE business_id=300')->fetch(PDO::FETCH_ASSOC);$ok($edited['comment']==='ERP edited after save'&&$edited['planned_start_date']==='2026-09-12'&&$edited['planned_finish_date']==='2026-09-22'&&$edited['source_system']==='legacy_prefill','legacy_prefill must remain editable ERP-owned');$editedLinks=(string)$pdo->query("SELECT GROUP_CONCAT(fr.zayavka_id ORDER BY wtr.position_no) FROM waste_trip_requests wtr JOIN feo_requests fr ON fr.id=wtr.feo_request_id WHERE wtr.waste_trip_id={$prefillId}")->fetchColumn();$ok($editedLinks==='1003','legacy_prefill FEO composition must be editable');

$legacy->exec("UPDATE flights SET zayavki_ids='1001',comment='historical B',planned_start_date='2026-09-05' WHERE id=262;UPDATE flights SET zayavki_ids='1002',comment='legacy tries overwrite',planned_start_date='2026-09-30' WHERE id=300;");
$run=WasteTripLegacySyncService::syncAll($legacy,$pdo);$ok($run['success']===1&&$run['failed']===0,'scheduler should process exactly historical linked trip');
$historicalAfter=$pdo->query('SELECT comment,planned_start_date FROM waste_trips WHERE business_id=262')->fetch(PDO::FETCH_ASSOC);$ok($historicalAfter['comment']==='historical B'&&$historicalAfter['planned_start_date']==='2026-09-05','historical legacy_flights no longer synchronizes');
$prefillAfter=$pdo->query('SELECT comment,planned_start_date,planned_finish_date FROM waste_trips WHERE business_id=300')->fetch(PDO::FETCH_ASSOC);$ok($prefillAfter['comment']==='ERP edited after save'&&$prefillAfter['planned_start_date']==='2026-09-12'&&$prefillAfter['planned_finish_date']==='2026-09-22','syncAll mutated legacy_prefill ERP-owned fields');$prefillLinksAfter=(string)$pdo->query("SELECT GROUP_CONCAT(fr.zayavka_id ORDER BY wtr.position_no) FROM waste_trip_requests wtr JOIN feo_requests fr ON fr.id=wtr.feo_request_id WHERE wtr.waste_trip_id={$prefillId}")->fetchColumn();$ok($prefillLinksAfter==='1003','syncAll mutated legacy_prefill composition');

echo "WASTE_TRIP_LEGACY_PREFILL_MYSQL=PASS\n";
}finally{if($fixedMode){$cleanup=new PDO("mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);cleanCompanyPoolDatabase($cleanup);}else{$admin->exec("DROP DATABASE IF EXISTS `{$db}`;DROP DATABASE IF EXISTS `{$legacyDb}`");}}
}
