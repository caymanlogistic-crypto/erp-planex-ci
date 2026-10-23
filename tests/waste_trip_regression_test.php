<?php
declare(strict_types=1);
namespace App\Service {
    final class AccessControlService { public static function canSeeAllCompanyData(array $u):bool{return true;} public static function effectiveMembershipId():?int{return null;} }
    final class RegulatedDataScopeService { public static function condition(string $entityType,string $idExpression,string $creatorExpression=''):array{return['sql'=>'1 = 1','params'=>[]];} public static function assertCanCreate(string $entityType):int{return 1;} public static function canAccess(\PDO $pdo,string $entityType,string $table,int $entityId):bool{return true;} }
    final class LinearRouteService {
        public static function isVisibleEntity(\PDO $p,string $t,int $id,array $u,string $k):bool{return$id>0;}
        public static function fetchVisibleRouteExecutors(\PDO $p,array $u):array{return[];}
    }
    final class FinanceObligationService { public static function syncWasteTrip(\PDO $pdo,int $tripId):int{return 0;} }
}
namespace {
use App\Service\WasteTripLegacySourceService;
use App\Service\WasteTripLegacySyncService;
use App\Service\WasteTripService;

$root=dirname(__DIR__);
require_once $root.'/app/Service/WasteTripLegacySourceService.php';
require_once $root.'/app/Service/WasteTripService.php';
require_once $root.'/app/Service/WasteTripLegacySyncService.php';
require_once $root.'/app/Support/company_database.php';
if (($argv[1]??'')==='--create-child') {
    $host=getenv('PLANEX_SCHEMA_DB_HOST')?:'127.0.0.1';$port=(int)(getenv('PLANEX_SCHEMA_DB_PORT')?:3306);
    $user=getenv('PLANEX_SCHEMA_DB_USER')?:'planex_ci';$pass=getenv('PLANEX_SCHEMA_DB_PASSWORD')?:'planex_ci';
    $db=(string)(getenv('PLANEX_TEST_FIXED_DB')?:'');
    if($db===''||!in_array($db,explode(',',(string)getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST')),true))throw new RuntimeException('Concurrent child requires the approved disposable schema.');
    $pdo=new PDO("mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    $base=['client_id'=>1,'responsible_user_id'=>1,'destination_type'=>'utilizer','utilizer_id'=>1,'status'=>'searching_executor','planned_start_mode'=>'exact','planned_start_date'=>'2026-09-01','comment'=>'independent'];
    WasteTripService::createIndependent($pdo,$pdo,$base,[(string)($argv[2]??'')],['user_id'=>1,'role_code'=>'company_owner']);
    exit(0);
}
$fail=static function(string$m):never{fwrite(STDERR,"FAIL: {$m}\n");exit(1);};
$ok=static function(bool$v,string$m)use($fail):void{if(!$v)$fail($m);};

$bad=false;try{WasteTripLegacySourceService::authoritativeState(['id'=>250,'zayavki_ids'=>'10,10','status'=>'started']);}catch(RuntimeException){$bad=true;}$ok($bad,'duplicate source request must fail');
$badDate=false;try{WasteTripLegacySourceService::authoritativeState(['id'=>355,'zayavki_ids'=>'1004','status'=>'completed','actual_start_date'=>'2026-10-22','actual_end_date'=>'2023-10-23']);}catch(RuntimeException $error){$badDate=$error->getMessage()==='Legacy факт содержит начало позже окончания.';}$ok($badDate,'trip 355 inverted source dates must still block sync');
$partial=WasteTripLegacySourceService::authoritativeState(['id'=>250,'zayavki_ids'=>'10','status'=>'started','planned_start_date_from'=>'2026-09-01']);$ok($partial['planned_start_mode']==='range'&&$partial['planned_start_date_to']===null,'partial source range must synchronize');
$ok(WasteTripService::ERP_BUSINESS_ID_START===400,'ERP range start must be 400');
$ok(WasteTripService::LEGACY_RANGE_WARNING_THRESHOLD===390,'legacy warning threshold must be 390');
$ok(WasteTripService::legacyRangeWarningForMax(389)===null,'warning must be absent at 389');
$ok(WasteTripService::legacyRangeWarningForMax(390)===WasteTripService::LEGACY_RANGE_WARNING,'warning must appear at 390');
$dateErrors=WasteTripService::validateInput(['client_id'=>1,'responsible_user_id'=>1,'destination_type'=>'warehouse','status'=>'searching_executor','planned_start_mode'=>'exact','planned_start_date'=>'2026-09-10','planned_finish_date'=>'2026-09-09']);$ok(in_array('Плановое окончание не может быть раньше планового начала.',$dateErrors,true),'planned finish before planned start must fail');
echo "WASTE_TRIP_DOMAIN=PASS\n";

$service=(string)file_get_contents($root.'/app/Service/WasteTripService.php');
$ok(str_contains($service,'assertLegacyOwnedMutationAbsent'),'readonly server guard missing');
$ok(str_contains($service,'FOR UPDATE'),'locking missing');
$ok(!str_contains($service,'mapLegacyStatus'),'legacy status mapping must be absent');
$ok(!str_contains($service,'CRITICAL LEGACY NAMESPACE VIOLATION'),'obsolete namespace violation remains');
$ok(!str_contains($service,'assertNamespaceSafe'),'obsolete namespace guard remains');
$ok(!str_contains($service,'assertBusinessIdWithinHighWater'),'obsolete high-water ceiling remains');
$ok(str_contains($service,'hasFlightId($legacyPdo,$next)'),'allocator must skip legacy collisions');
echo "WASTE_TRIP_STATIC_SECURITY=PASS\n";

if(!extension_loaded('pdo_mysql')){fwrite(STDERR,"pdo_mysql required for Waste Trip MySQL regression\n");exit(2);}
$host=getenv('PLANEX_SCHEMA_DB_HOST')?:'127.0.0.1';$port=(int)(getenv('PLANEX_SCHEMA_DB_PORT')?:3306);$user=getenv('PLANEX_SCHEMA_DB_USER')?:'planex_ci';$pass=getenv('PLANEX_SCHEMA_DB_PASSWORD')?:'planex_ci';
$fixedDb=(string)(getenv('PLANEX_TEST_FIXED_DB')?:'');$fixedMode=$fixedDb!=='';
if($fixedMode){$allow=array_values(array_filter(array_map('trim',explode(',',(string)(getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST')?:'')))));$ok((string)(getenv('APP_ENV')?:'')==='test','fixed DB requires APP_ENV=test');$ok(in_array($fixedDb,$allow,true),'fixed DB is not OWNER-approved');}
$admin=new PDO("mysql:host={$host};port={$port};charset=utf8mb4",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$db=$fixedMode?$fixedDb:'planex_waste_'.getmypid();$legacyDb=$fixedMode?$fixedDb:$db.'_legacy';
if($fixedMode){$fixedPdo=new PDO("mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);cleanCompanyPoolDatabase($fixedPdo);$fixedPdo=null;}else{$admin->exec("DROP DATABASE IF EXISTS `{$db}`;CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;DROP DATABASE IF EXISTS `{$legacyDb}`;CREATE DATABASE `{$legacyDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");}
try{
$pdo=new PDO("mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$legacy=new PDO("mysql:host={$host};port={$port};dbname={$legacyDb};charset=utf8mb4",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$pdo->exec("CREATE TABLE clients(id INT UNSIGNED PRIMARY KEY,name VARCHAR(50));INSERT INTO clients VALUES(1,'C');
CREATE TABLE users(id INT UNSIGNED PRIMARY KEY,full_name VARCHAR(50),role_code VARCHAR(20),status VARCHAR(20),deleted_at DATETIME NULL);INSERT INTO users VALUES(1,'U','company_owner','active',NULL);
CREATE TABLE feo_utilizers(id BIGINT UNSIGNED PRIMARY KEY,short_name VARCHAR(50),full_name VARCHAR(50),address VARCHAR(100),is_active TINYINT,sort_order INT);INSERT INTO feo_utilizers VALUES(1,'F','F','A',1,1);
CREATE TABLE contractors(id INT UNSIGNED PRIMARY KEY,name VARCHAR(50));CREATE TABLE crews(id INT UNSIGNED PRIMARY KEY,contractor_id INT UNSIGNED NULL,driver_vehicle_block_id INT UNSIGNED NULL);CREATE TABLE crew_drivers(crew_id INT UNSIGNED,driver_id INT UNSIGNED,position INT);CREATE TABLE drivers(id INT UNSIGNED PRIMARY KEY,full_name VARCHAR(50));CREATE TABLE driver_vehicle_blocks(id INT UNSIGNED PRIMARY KEY,vehicle_set_id INT UNSIGNED);CREATE TABLE vehicle_sets(id INT UNSIGNED PRIMARY KEY,primary_vehicle_unit_id INT UNSIGNED,secondary_vehicle_unit_id INT UNSIGNED);CREATE TABLE vehicle_units(id INT UNSIGNED PRIMARY KEY,brand VARCHAR(50),plate_number VARCHAR(20));
CREATE TABLE feo_requests(id BIGINT UNSIGNED PRIMARY KEY,zayavka_id VARCHAR(50) UNIQUE,mass_netto DECIMAL(12,3),naim_oo_gruzootpravitel VARCHAR(50),mno_adres_pogruzki VARCHAR(100),naim_otkhoda_fkko VARCHAR(100),nomer_opasnogo_gr VARCHAR(20));
CREATE TABLE feo_placement_current(zayavka_id VARCHAR(50) PRIMARY KEY);
INSERT INTO feo_requests VALUES(1,'1001',1.1,'S','A','W','1'),(2,'1002',1.2,'S','A','W','1'),(3,'1003',1.3,'S','A','W','1'),(4,'1004',1.4,'S','A','W','1'),(5,'1005',1.5,'S','A','W','1'),(6,'1006',1.6,'S','A','W','1'),(7,'1007',1.7,'S','A','W','1');
INSERT INTO feo_placement_current VALUES('1001'),('1002'),('1003'),('1004'),('1005'),('1006'),('1007');
CREATE TABLE schema_migrations(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,migration VARCHAR(255) UNIQUE,checksum VARCHAR(64));INSERT INTO schema_migrations(migration,checksum) VALUES('130_waste_trip_legacy_linked_cutover.sql','x');
CREATE TABLE waste_trips(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,business_id BIGINT UNSIGNED NOT NULL,client_id INT UNSIGNED NOT NULL,route_executor_id INT UNSIGNED NULL,responsible_user_id INT UNSIGNED NOT NULL,destination_type ENUM('utilizer','warehouse') NOT NULL,utilizer_id BIGINT UNSIGNED NULL,deleted_at DATETIME NULL,status ENUM('planned_route','searching_executor','executor_assigned','in_progress','completed','cancelled') NOT NULL,planned_start_mode ENUM('exact','range','unspecified') NOT NULL,planned_start_date DATE NULL,planned_start_date_from DATE NULL,planned_start_date_to DATE NULL,planned_finish_date DATE NULL,actual_start_date DATE NULL,actual_end_date DATE NULL,comment VARCHAR(500) NULL,legal_entity_code VARCHAR(32) NULL,no_markup TINYINT(1) NOT NULL DEFAULT 0,legacy_route_type VARCHAR(64) NULL,legacy_unload_type VARCHAR(32) NULL,legacy_source_warehouse_id BIGINT NULL,legacy_destination_warehouse_id BIGINT NULL,legacy_source_warehouse_name VARCHAR(255) NULL,legacy_destination_warehouse_name VARCHAR(255) NULL,cancellation_reason VARCHAR(500) NULL,cancelled_at DATETIME NULL,cancelled_by_user_id INT NULL,cancelled_by_role VARCHAR(20) NULL,source_system VARCHAR(50) NULL,legacy_flight_id BIGINT UNSIGNED NULL,legacy_sync_error VARCHAR(1000) NULL,legacy_last_checked_at DATETIME NULL,legacy_last_success_at DATETIME NULL,lock_version INT UNSIGNED NOT NULL DEFAULT 1,created_by_user_id INT NULL,created_by_membership_id BIGINT UNSIGNED NULL,created_by_role VARCHAR(20),updated_by_user_id INT NULL,updated_by_role VARCHAR(20),created_at DATETIME DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,UNIQUE KEY uk_waste_trip_business_id(business_id),UNIQUE KEY uk_waste_trip_legacy_identity(source_system,legacy_flight_id));
CREATE TABLE waste_trip_requests(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,waste_trip_id BIGINT UNSIGNED,feo_request_id BIGINT UNSIGNED,snapshot_mass_kg DECIMAL(18,3),position_no INT,removed_at DATETIME NULL,UNIQUE KEY uk_waste_trip_request(waste_trip_id,feo_request_id));
CREATE TABLE waste_trip_audit_log(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,waste_trip_id BIGINT UNSIGNED,action ENUM('create','update','composition_correction','cancel'),before_json LONGTEXT,after_json LONGTEXT,actor_user_id INT,actor_role VARCHAR(20),created_at DATETIME DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE waste_trip_namespace_state(singleton_id TINYINT UNSIGNED PRIMARY KEY,legacy_high_water_at_cutover BIGINT UNSIGNED,last_issued_business_id BIGINT UNSIGNED,cutover_started_at DATETIME,cutover_completed_at DATETIME,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP);
INSERT INTO waste_trip_namespace_state VALUES(1,261,261,NOW(),NOW(),NOW(),NOW());");
$legacy->exec("CREATE TABLE warehouses(id BIGINT UNSIGNED PRIMARY KEY,name VARCHAR(255)); INSERT INTO warehouses VALUES(1,'Склад Север'),(2,'Склад Юг'); CREATE TABLE flights(id BIGINT UNSIGNED PRIMARY KEY,legal_entity_code VARCHAR(32) NULL,route_type VARCHAR(64) NULL,unload_type VARCHAR(32) NULL,source_warehouse_id BIGINT NULL,destination_warehouse_id BIGINT NULL,zayavki_ids TEXT,status VARCHAR(32) NOT NULL DEFAULT 'started',comment VARCHAR(500),planned_start_date DATE NULL,planned_start_date_from DATE NULL,planned_start_date_to DATE NULL,actual_start_date DATE NULL,actual_end_date DATE NULL);
INSERT INTO flights(id,zayavki_ids,comment,planned_start_date,planned_start_date_from,planned_start_date_to,actual_start_date,actual_end_date) VALUES(240,'1001','too old',NULL,NULL,NULL,NULL,NULL),(262,'1003','legacy 262','2026-09-01',NULL,NULL,'2026-09-02',NULL)");
$userCtx=['user_id'=>1,'role_code'=>'company_owner'];
$base=['client_id'=>1,'responsible_user_id'=>1,'destination_type'=>'utilizer','utilizer_id'=>1,'status'=>'searching_executor','planned_start_mode'=>'exact','planned_start_date'=>'2026-09-01','comment'=>'independent'];

$diag262=WasteTripService::legacyRangeDiagnostic($legacy);$ok($diag262['legacy_max_id']===262&&$diag262['warning']===null,'legacy max 262 must be normal');
$id1=WasteTripService::createIndependent($pdo,$legacy,$base,['1001'],$userCtx);$id2=WasteTripService::createIndependent($pdo,$legacy,$base,['1002'],$userCtx);
$ok($id1===400&&$id2===401,'native ERP allocator must start 400,401');
$ok(WasteTripService::hasNoMarkup(['no_markup'=>1]) && !WasteTripService::hasNoMarkup(['no_markup'=>0]),'No markup state follows the saved choice');
$warehouseInput=$base;$warehouseInput['destination_type']='warehouse';$warehouseInput['utilizer_id']=null;
$warehouseCostBlocked=false;try{WasteTripService::createIndependent($pdo,$legacy,$warehouseInput,['1005'],$userCtx,null,null,[['amount'=>'100.00']]);}catch(RuntimeException $e){$warehouseCostBlocked=str_contains($e->getMessage(),'генерального заказчика');}$ok($warehouseCostBlocked,'Warehouse trip creation rejects submitted general customer cost');

$legacy->exec("INSERT INTO flights(id,zayavki_ids,comment,planned_start_date,planned_start_date_from,planned_start_date_to,actual_start_date,actual_end_date) VALUES(300,'1004','new legacy 300',NULL,NULL,NULL,NULL,NULL)");
$diag300=WasteTripService::legacyRangeDiagnostic($legacy);$ok($diag300['legacy_max_id']===300&&$diag300['warning']===null,'legacy max 300 must be normal');
$ok(WasteTripLegacySourceService::fetchFlight($legacy,300)!==null,'new legacy flight remains readable by scheduler');

$pdo=null;$legacy=null;$admin=null;
if(function_exists('pcntl_fork')) {
    $children=[];foreach(['1006','1007'] as$rid){$pid=pcntl_fork();if($pid===-1)$fail('pcntl_fork failed');if($pid===0){try{$cp=new \PDO("mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4",$user,$pass,[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION,\PDO::ATTR_EMULATE_PREPARES=>false]);$cl=new \PDO("mysql:host={$host};port={$port};dbname={$legacyDb};charset=utf8mb4",$user,$pass,[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION,\PDO::ATTR_EMULATE_PREPARES=>false]);WasteTripService::createIndependent($cp,$cl,$base,[$rid],$userCtx);exit(0);}catch(\Throwable$e){fwrite(STDERR,'child create failed: '.$e->getMessage().PHP_EOL);exit(1);}}$children[]=$pid;}
    foreach($children as$pid){pcntl_waitpid($pid,$status);if(!pcntl_wifexited($status)||pcntl_wexitstatus($status)!==0)$fail('concurrent create child failed');}
} else {
    $children=[];foreach(['1006','1007'] as$rid){$process=proc_open([PHP_BINARY,__FILE__,'--create-child',$rid],[1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($process))$fail('concurrent create child failed to start');$children[]=[$process,$pipes];}
    foreach($children as[$process,$pipes]){$output=stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($process)!==0)$fail('concurrent create child failed: '.$output);}
}
$pdo=new PDO("mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);$legacy=new PDO("mysql:host={$host};port={$port};dbname={$legacyDb};charset=utf8mb4",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);$admin=new PDO("mysql:host={$host};port={$port};charset=utf8mb4",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$concurrentIds=$pdo->query("SELECT business_id FROM waste_trips WHERE business_id>401 ORDER BY business_id")->fetchAll(PDO::FETCH_COLUMN);$ok(array_map('intval',$concurrentIds)===[402,403],'concurrent allocator must issue distinct sequential IDs');
echo "WASTE_TRIP_CONCURRENCY=PASS\n";

$business=WasteTripLegacySyncService::materialize($legacy,$pdo,262,['client_id'=>1,'responsible_user_id'=>1,'destination_type'=>'utilizer','utilizer_id'=>1,'status'=>'searching_executor'],$userCtx);$ok($business===262,'legacy-linked business_id must preserve flights.id=262');
$linked=(int)$pdo->query('SELECT id FROM waste_trips WHERE business_id=262')->fetchColumn();$ok($linked>0,'linked 262 missing');
$dup=false;try{WasteTripLegacySyncService::materialize($legacy,$pdo,262,['client_id'=>1,'responsible_user_id'=>1,'destination_type'=>'utilizer','utilizer_id'=>1,'status'=>'searching_executor'],$userCtx);}catch(RuntimeException){$dup=true;}$ok($dup,'duplicate materialization must fail');

$legacy->exec("UPDATE flights SET zayavki_ids='1004',comment='sync above 261',legal_entity_code='mg_logistic',route_type='warehouse_to_utilizer',source_warehouse_id=1 WHERE id=262");
$runAbove261=WasteTripLegacySyncService::syncAll($legacy,$pdo);$ok($runAbove261['success']===1&&$runAbove261['failed']===0,'scheduler sync must work with legacy max >261');
$ok((string)$pdo->query('SELECT comment FROM waste_trips WHERE business_id=262')->fetchColumn()==='sync above 261','linked 262 did not sync');
$autoMetadata=$pdo->query('SELECT * FROM waste_trips WHERE business_id=262')->fetch(PDO::FETCH_ASSOC);
$ok($autoMetadata['legal_entity_code']==='mg_logistic'&&\App\Service\WasteTripLegacyMetadataService::warehouseLabel($autoMetadata)==="Загрузка со склада:\nСклад Север",'Automatic sync refreshes legal entity and warehouse');
$mixedMetadata=$autoMetadata;
$mixedMetadata['legacy_route_type']='mixed_to_utilizer';
$ok(\App\Service\WasteTripLegacyMetadataService::warehouseLabel($mixedMetadata)==="Загрузка со склада:\nСклад Север",'Mixed route displays source warehouse');
$registeredLink=(int)$pdo->query("SELECT id FROM waste_trip_requests WHERE waste_trip_id={$linked} AND removed_at IS NULL")->fetchColumn();
$pdo->exec("CREATE TABLE waste_trip_registration_journal(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,waste_trip_request_id BIGINT UNSIGNED NOT NULL,CONSTRAINT fk_registration_journal_request FOREIGN KEY (waste_trip_request_id) REFERENCES waste_trip_requests(id) ON DELETE RESTRICT)");
$pdo->prepare('INSERT INTO waste_trip_registration_journal(waste_trip_request_id) VALUES(?)')->execute([$registeredLink]);

$legacy->exec("UPDATE flights SET zayavki_ids='1003',status='completed' WHERE id=262");
$changed=WasteTripLegacySyncService::syncAll($legacy,$pdo);
$ok($changed['success']===1&&$changed['failed']===0,'scheduler must replace a registered request without a foreign key error');
$ok((string)$pdo->query("SELECT status FROM waste_trips WHERE id={$linked}")->fetchColumn()==='completed','scheduler must synchronize completed status');
$ok((int)$pdo->query("SELECT COUNT(*) FROM waste_trip_requests WHERE waste_trip_id={$linked} AND removed_at IS NULL")->fetchColumn()===1,'only the current source request remains active');
$ok((string)$pdo->query("SELECT fr.zayavka_id FROM waste_trip_requests wtr JOIN feo_requests fr ON fr.id=wtr.feo_request_id WHERE wtr.waste_trip_id={$linked} AND wtr.removed_at IS NULL")->fetchColumn()==='1003','active request must match the source');
$ok((int)$pdo->query("SELECT COUNT(*) FROM waste_trip_requests WHERE id={$registeredLink} AND removed_at IS NOT NULL")->fetchColumn()===1,'registered request link must be archived');
$ok((int)$pdo->query('SELECT waste_trip_request_id FROM waste_trip_registration_journal')->fetchColumn()===$registeredLink,'registration journal must retain its link');
$legacy->exec("UPDATE flights SET zayavki_ids='1004',status='started' WHERE id=262");
$restored=WasteTripLegacySyncService::syncAll($legacy,$pdo);
$ok($restored['success']===1&&$restored['failed']===0,'scheduler must reactivate a previously archived request');
$ok((int)$pdo->query("SELECT id FROM waste_trip_requests WHERE waste_trip_id={$linked} AND removed_at IS NULL")->fetchColumn()===$registeredLink,'reactivation must reuse the original request link');
$ok((int)$pdo->query("SELECT COUNT(*) FROM waste_trip_requests WHERE waste_trip_id={$linked} AND removed_at IS NULL")->fetchColumn()===1,'reactivation must archive the removed replacement request');
$ok((string)$pdo->query("SELECT status FROM waste_trips WHERE id={$linked}")->fetchColumn()==='in_progress','scheduler must synchronize status reversal');

$legacy->exec("INSERT INTO flights(id,zayavki_ids,comment,planned_start_date,planned_start_date_from,planned_start_date_to,actual_start_date,actual_end_date) VALUES(389,'1004','legacy 389',NULL,NULL,NULL,NULL,NULL)");
$diag389=WasteTripService::legacyRangeDiagnostic($legacy);$ok($diag389['legacy_max_id']===389&&$diag389['warning']===null,'warning must be absent at max 389');
$legacy->exec("INSERT INTO flights(id,zayavki_ids,comment,planned_start_date,planned_start_date_from,planned_start_date_to,actual_start_date,actual_end_date) VALUES(390,'1004','legacy 390',NULL,NULL,NULL,NULL,NULL)");
$diag390=WasteTripService::legacyRangeDiagnostic($legacy);$ok($diag390['legacy_max_id']===390&&$diag390['warning']===WasteTripService::LEGACY_RANGE_WARNING,'warning must appear at max 390');

$legacy->exec("INSERT INTO flights(id,zayavki_ids,comment,planned_start_date,planned_start_date_from,planned_start_date_to,actual_start_date,actual_end_date) VALUES(404,'1004','legacy collision 404',NULL,NULL,NULL,NULL,NULL)");
$idAfterCollision=WasteTripService::createIndependent($pdo,$legacy,$base,['1005'],$userCtx);$ok($idAfterCollision===405,'allocator must skip legacy-occupied 404 and choose 405');
$ok((int)$pdo->query('SELECT COUNT(*) FROM waste_trips WHERE business_id=404')->fetchColumn()===0,'legacy collision ID must not be duplicated in ERP');
$ok((int)$pdo->query('SELECT COUNT(*) FROM waste_trips')->fetchColumn()===(int)$pdo->query('SELECT COUNT(DISTINCT business_id) FROM waste_trips')->fetchColumn(),'duplicate business_id detected');
$dbUnique=false;try{$pdo->exec("INSERT INTO waste_trips (business_id,client_id,responsible_user_id,destination_type,utilizer_id,status,planned_start_mode,planned_start_date,comment,created_by_role,updated_by_role) SELECT business_id,client_id,responsible_user_id,destination_type,utilizer_id,status,planned_start_mode,planned_start_date,'dup','company_owner','company_owner' FROM waste_trips WHERE business_id=400");}catch(PDOException){$dbUnique=true;}$ok($dbUnique,'DB UNIQUE business_id must remain final defense');

$ro=false;try{WasteTripService::update($pdo,$linked,['client_id'=>1,'responsible_user_id'=>1,'destination_type'=>'utilizer','utilizer_id'=>1,'status'=>'searching_executor','comment'=>'hack'],[],1,$userCtx);}catch(RuntimeException){$ro=true;}$ok($ro,'linked legacy-owned POST must be rejected');
$pdo->exec("UPDATE waste_trips SET legacy_unload_type='SKLAD' WHERE id={$linked}");$version=(int)$pdo->query("SELECT lock_version FROM waste_trips WHERE id={$linked}")->fetchColumn();
$tampered=false;try{WasteTripService::update($pdo,$linked,['client_id'=>1,'responsible_user_id'=>1,'destination_type'=>'utilizer'],[],$version,$userCtx,null,null,[['amount'=>'999.00']]);}catch(RuntimeException $e){$tampered=str_contains($e->getMessage(),'генерального заказчика');}$ok($tampered,'Warehouse trip update rejects tampered general customer cost');
$before=$pdo->query("SELECT comment,planned_start_date,status,responsible_user_id FROM waste_trips WHERE id={$linked}")->fetch(PDO::FETCH_ASSOC);$links=(string)$pdo->query("SELECT GROUP_CONCAT(fr.zayavka_id ORDER BY wtr.position_no) FROM waste_trip_requests wtr JOIN feo_requests fr ON fr.id=wtr.feo_request_id WHERE wtr.waste_trip_id={$linked}")->fetchColumn();
$state=WasteTripLegacySourceService::authoritativeState(['id'=>262,'zayavki_ids'=>'1004,9999','status'=>'completed','comment'=>'should rollback','planned_start_date'=>'2026-09-05']);$atomic=false;try{WasteTripService::syncLegacyOwnedState($pdo,$linked,$state);}catch(RuntimeException){$atomic=true;}$ok($atomic,'missing FEO sync must fail');$after=$pdo->query("SELECT comment,planned_start_date,status,responsible_user_id FROM waste_trips WHERE id={$linked}")->fetch(PDO::FETCH_ASSOC);$linksAfter=(string)$pdo->query("SELECT GROUP_CONCAT(fr.zayavka_id ORDER BY wtr.position_no) FROM waste_trip_requests wtr JOIN feo_requests fr ON fr.id=wtr.feo_request_id WHERE wtr.waste_trip_id={$linked}")->fetchColumn();$ok($before===$after&&$links===$linksAfter,'missing FEO must rollback comment/dates/composition');
$valid=WasteTripLegacySourceService::authoritativeState(['id'=>262,'zayavki_ids'=>'1004','status'=>'completed','comment'=>'legacy B','planned_start_date'=>'2026-09-05','actual_start_date'=>'2026-09-06','actual_end_date'=>'2026-09-07']);WasteTripService::syncLegacyOwnedState($pdo,$linked,$valid);$row=$pdo->query("SELECT comment,planned_start_date,actual_start_date,actual_end_date,planned_finish_date,status,responsible_user_id FROM waste_trips WHERE id={$linked}")->fetch(PDO::FETCH_ASSOC);$ok($row['comment']==='legacy B'&&$row['planned_start_date']==='2026-09-05'&&$row['actual_end_date']==='2026-09-07'&&$row['planned_finish_date']===null,'source whitelist sync failed');$ok($row['status']==='completed'&&(int)$row['responsible_user_id']===(int)$before['responsible_user_id'],'Legacy status sync must update completed while preserving ERP manager');
$ok((int)$pdo->query("SELECT id FROM waste_trip_requests WHERE waste_trip_id={$linked} AND removed_at IS NULL")->fetchColumn()===$registeredLink,'Sync must preserve registered request link identity');
$ok((int)$pdo->query('SELECT waste_trip_request_id FROM waste_trip_registration_journal')->fetchColumn()===$registeredLink,'Sync must preserve registration journal FK');
$occupied=WasteTripLegacySourceService::authoritativeState(['id'=>262,'zayavki_ids'=>'1005','status'=>'completed','comment'=>'occupied']);$blocked=false;try{WasteTripService::syncLegacyOwnedState($pdo,$linked,$occupied);}catch(RuntimeException){$blocked=true;}$ok($blocked,'occupied FEO must block sync');

$independentComment=(string)$pdo->query('SELECT comment FROM waste_trips WHERE business_id=400')->fetchColumn();$legacy->exec("UPDATE flights SET zayavki_ids='1004',comment='sync runner warning' WHERE id=262");$run=WasteTripLegacySyncService::syncAll($legacy,$pdo);$ok($run['success']===1&&$run['failed']===0,'linked sync runner should update one linked trip with warning state');$ok($run['warning']===WasteTripService::LEGACY_RANGE_WARNING,'scheduler must report non-blocking warning at >=390');$ok((string)$pdo->query('SELECT comment FROM waste_trips WHERE business_id=400')->fetchColumn()===$independentComment,'independent trip changed by sync');
foreach([300,389,390,404]as$legacyOnlyId)$ok((int)$pdo->query('SELECT COUNT(*) FROM waste_trips WHERE business_id='.$legacyOnlyId)->fetchColumn()===0,'sync must not auto-materialize legacy '.$legacyOnlyId);

$dbName=(string)$pdo->query('SELECT DATABASE()')->fetchColumn();$lockName='erp_waste_trip_legacy_sync_'.substr(hash('sha256',$dbName),0,24);$pdo2=new PDO("mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);$ls=$pdo2->prepare('SELECT GET_LOCK(?,0)');$ls->execute([$lockName]);$overlap=WasteTripLegacySyncService::syncAll($legacy,$pdo);$ok($overlap['skipped_overlap']===true,'overlapping sync not rejected');$ok($overlap['warning']===WasteTripService::LEGACY_RANGE_WARNING,'warning must remain non-blocking during overlap');$rel=$pdo2->prepare('SELECT RELEASE_LOCK(?)');$rel->execute([$lockName]);

$legacy->exec("INSERT INTO flights(id,zayavki_ids,comment,planned_start_date,planned_start_date_from,planned_start_date_to,actual_start_date,actual_end_date) VALUES(263,'1004','second legacy leg',NULL,NULL,NULL,NULL,NULL)");
$second=WasteTripLegacySyncService::materialize($legacy,$pdo,263,['client_id'=>1,'responsible_user_id'=>1,'destination_type'=>'utilizer','utilizer_id'=>1,'status'=>'searching_executor'],$userCtx);
$ok($second===263,'second linked legacy trip may carry an already linked request');
$secondState=WasteTripLegacySourceService::authoritativeState(['id'=>263,'zayavki_ids'=>'1004','status'=>'completed','comment'=>'second leg delivered']);
$secondId=(int)$pdo->query('SELECT id FROM waste_trips WHERE business_id=263')->fetchColumn();
WasteTripService::syncLegacyOwnedState($pdo,$secondId,$secondState);
$ok((string)$pdo->query("SELECT status FROM waste_trips WHERE id={$secondId}")->fetchColumn()==='completed','background sync must accept linked-to-linked request overlap');

echo "WASTE_TRIP_MYSQL=PASS\n";
}finally{if($fixedMode){$cleanup=new PDO("mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);cleanCompanyPoolDatabase($cleanup);}else{$admin->exec("DROP DATABASE IF EXISTS `{$db}`;DROP DATABASE IF EXISTS `{$legacyDb}`");}}
}
