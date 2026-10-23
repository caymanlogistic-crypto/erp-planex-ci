<?php
declare(strict_types=1);

namespace App\Service {
    // Isolate the authenticated scope; mappings, persistence and finance use real services.
    final class AccessControlService {
        public static function effectiveMembershipId(): ?int { return 1; }
    }
    final class RegulatedDataScopeService {
        public static bool $allowed = true;
        public static function assertCanCreate(string $type): int { if (!self::$allowed) throw new \RuntimeException('denied'); return 1; }
        public static function condition(string $type,string $id,string $creator=''): array { return ['sql'=>self::$allowed ? '1=1' : '1=0','params'=>[]]; }
        public static function canAccess(\PDO $pdo,string $type,string $table,int $id): bool { return self::$allowed; }
    }
}
namespace {
use App\Service\LocalMigrationService;
use App\Service\WasteTripSynchronizationService as Sync;
use App\Service\WasteTripService as Trips;
use App\Service\RegulatedDataScopeService as Scope;

$root=dirname(__DIR__);
require $root.'/bootstrap/app.php';
require_once $root.'/app/Support/company_database.php';
spl_autoload_register(static function(string $class) use($root): void {
    if (str_starts_with($class,'App\\')) { $path=$root.'/app/'.str_replace('\\','/',substr($class,4)).'.php'; if(is_file($path)) require_once $path; }
});
function check(bool $ok,string $message): void { if(!$ok) throw new RuntimeException($message); }
function reject(callable $call,string $message): void { try{$call();}catch(Throwable){return;}throw new RuntimeException($message); }

// One allowlisted disposable schema supports isolated legacy tables through a test adapter.
class LegacyFixturePDO extends PDO {
    public bool $failRead=false;
    public function prepare(string $query,array $options=[]): PDOStatement|false {
        return parent::prepare(str_replace('FROM feo WHERE','FROM feo_requests WHERE',$query),$options);
    }
    public function query(string $query,?int $fetchMode=null,mixed ...$args): PDOStatement|false {
        if($this->failRead)throw new PDOException('Synthetic source failure');
        $query=str_replace(['FROM flights f','FROM flights WHERE','JOIN users u','JOIN drivers d'],['FROM fixture_flights f','FROM fixture_flights WHERE','JOIN fixture_managers u','JOIN fixture_drivers d'],$query);
        return $fetchMode===null ? parent::query($query) : parent::query($query,$fetchMode,...$args);
    }
}
class FailingTenantPDO extends PDO {
    public ?string $failAt=null;
    public function prepare(string $query,array $options=[]): PDOStatement|false {
        if($this->failAt!==null&&str_contains($query,$this->failAt))throw new PDOException('Synthetic transaction failure');
        return parent::prepare($query,$options);
    }
}
$host=getenv('PLANEX_SCHEMA_DB_HOST')?:'127.0.0.1';$port=getenv('PLANEX_SCHEMA_DB_PORT')?:3306;
$user=getenv('PLANEX_SCHEMA_DB_USER')?:'planex_ci';$pass=getenv('PLANEX_SCHEMA_DB_PASSWORD')?:'planex_ci';
$fixed=(string)getenv('PLANEX_TEST_FIXED_DB');$db=$fixed?:'planex_trip_sync_'.getmypid();
if($fixed!=='')check(in_array($fixed,explode(',',(string)getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST')),true),'Unapproved fixed DB');
$options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC];
$admin=new PDO("mysql:host=$host;port=$port;charset=utf8mb4",$user,$pass,$options);
if($fixed==='')$admin->exec("CREATE DATABASE `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$dsn="mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4";
$pdo=new FailingTenantPDO($dsn,$user,$pass,$options);$central=new PDO($dsn,$user,$pass,$options);$legacy=new LegacyFixturePDO($dsn,$user,$pass,$options);
try {
    if($fixed!=='')cleanCompanyPoolDatabase($pdo);
    LocalMigrationService::apply($pdo);
    $pdo->exec("INSERT INTO waste_trip_namespace_state(singleton_id,legacy_high_water_at_cutover,last_issued_business_id,cutover_started_at,cutover_completed_at) VALUES(1,261,261,NOW(),NOW());
        INSERT INTO users(id,membership_id,full_name,login,password_hash,role_code,status) VALUES(1,1,'Owner','owner','hash','company_owner','active');
        INSERT INTO clients(id,name,inn,status) VALUES(13,'ООО «МГ ЛОГИСТИК»','7700000000','active');
        INSERT INTO contractors(id,name,inn,status) VALUES(1,'Carrier','7700000001','active');
        INSERT INTO drivers(id,full_name,phone,status) VALUES(1,'Семенов Иван','+70000000000','active');
        INSERT INTO vehicle_units(id,plate_number,brand,status) VALUES(1,'С894НО193','Fixture','active');
        INSERT INTO vehicle_sets(id,set_type,primary_vehicle_unit_id,status) VALUES(1,'single',1,'active');
        INSERT INTO driver_vehicle_blocks(id,driver_id,vehicle_set_id,status) VALUES(1,1,1,'active');
        INSERT INTO crews(id,contractor_id,vehicle_id,driver_id,driver_vehicle_block_id,status) VALUES(1,1,1,1,1,'active');
        INSERT INTO crew_drivers(crew_id,driver_id,position) VALUES(1,1,0);
        INSERT INTO feo_requests(zayavka_id,mass_netto,gruzopoluchatel) VALUES('700001','1.5000','Unknown'),('700002','2.5000','Unknown');
        CREATE TABLE user_accounts(id BIGINT UNSIGNED PRIMARY KEY,full_name VARCHAR(255),login VARCHAR(100),email VARCHAR(255) NULL,phone VARCHAR(50) NULL,password_hash VARCHAR(255),status VARCHAR(20));
        CREATE TABLE company_roles(id BIGINT UNSIGNED PRIMARY KEY,company_id INT UNSIGNED,system_key VARCHAR(50) NULL);
        CREATE TABLE company_memberships(id BIGINT UNSIGNED PRIMARY KEY,account_id BIGINT UNSIGNED,company_id INT UNSIGNED,role_id BIGINT UNSIGNED,status VARCHAR(20));
        INSERT INTO user_accounts VALUES(3,'Сидоров Михаил','sidorov',NULL,NULL,'hash','active'),(4,'Гладких Карина Артуровна','karina',NULL,NULL,'hash','active');
        INSERT INTO company_roles VALUES(1,25,NULL),(2,26,NULL);
        INSERT INTO company_memberships VALUES(10,3,25,1,'active'),(20,4,25,1,'active'),(30,3,26,2,'active');
        CREATE TABLE status_blocks(id INT PRIMARY KEY,status_type VARCHAR(40),zayavki_ids TEXT,cost DECIMAL(12,2));
        INSERT INTO status_blocks VALUES(1,'marshrut','700001,700002',4000);
        CREATE TABLE fixture_managers(id INT PRIMARY KEY,`Фамилия` VARCHAR(100),`Имя` VARCHAR(100));
        INSERT INTO fixture_managers VALUES(3,'Сидоров','Другой'),(4,'Гладских','Карина');
        CREATE TABLE fixture_drivers(id INT PRIMARY KEY,full_name VARCHAR(255),vehicle_make_plate VARCHAR(255));
        INSERT INTO fixture_drivers VALUES(80,'Семёнов Алексей','Hyundai С894НО193');
        CREATE TABLE warehouses(id INT PRIMARY KEY,name VARCHAR(255)); INSERT INTO warehouses VALUES(1,'Склад Север'),(2,'Склад Юг'); CREATE TABLE warehouse_movements(id BIGINT PRIMARY KEY,movement_type VARCHAR(32),warehouse_id INT,flight_id INT,zayavka_id VARCHAR(50),mass_netto DECIMAL(12,4),movement_date DATETIME,created_at DATETIME,updated_at DATETIME,status VARCHAR(20),comment TEXT); CREATE TABLE fixture_flights(id INT PRIMARY KEY,legal_entity_code VARCHAR(32) NULL,route_type VARCHAR(64) NULL,source_warehouse_id INT NULL,destination_warehouse_id INT NULL,zayavki_ids TEXT,direct_zayavki_ids TEXT NULL,comment VARCHAR(500),status VARCHAR(50),cost INT NULL,payment_terms VARCHAR(500) NULL,driver_id INT NULL,assigned_manager_id INT,unload_type VARCHAR(20),planned_start_date DATE NULL,planned_start_date_from DATE NULL,planned_start_date_to DATE NULL,actual_start_date DATE NULL,actual_end_date DATE NULL);
        INSERT INTO fixture_flights(id,zayavki_ids,comment,status,cost,driver_id,assigned_manager_id,unload_type,planned_start_date_from) VALUES
        (225,'700001','Excluded','planned_route',1,NULL,3,'SKLAD',NULL),
        (226,'700001','First','planned_route',1000,80,3,'SKLAD','2026-09-01'),
        (227,'700001,700002','Shared requests','found',NULL,NULL,4,'OO',NULL);");
    $pdo->exec("UPDATE fixture_flights SET legal_entity_code='mg_logistic',route_type='generator_to_warehouse',destination_warehouse_id=1 WHERE id=226");
    $legacy->exec('SET SESSION TRANSACTION READ ONLY');
    $actor=['user_id'=>1,'role_code'=>'company_owner'];
    $run=static fn()=>Sync::run($legacy,$pdo,$central,25,$actor);
    $first=$run();check($first['created']===2&&$first['failed']===0,'Create source rows: '.json_encode($first,JSON_UNESCAPED_UNICODE));
    check((int)$pdo->query('SELECT COUNT(*) FROM waste_trips WHERE business_id=225')->fetchColumn()===0,'Cutoff');
    $trip=$pdo->query('SELECT * FROM waste_trips WHERE business_id=226')->fetch();$id=(int)$trip['id'];
    reject(static fn()=>Trips::update($pdo,$id,[],[],(int)$trip['lock_version'],$actor,null,[]),'MG cost POST must be rejected server-side');
    check(in_array($id,array_map('intval',array_column(Trips::linkedTripsForSync($pdo),'id')),true),'Automatic scheduler includes manually synchronized flights from ID 226');
    check((int)$trip['client_id']===13&&(int)$trip['route_executor_id']===1&&$trip['status']==='planned_route','Mapped entities/status');
    check($trip['legal_entity_code']==='mg_logistic'&&$trip['legacy_destination_warehouse_name']==='Склад Север','Required legacy metadata synchronized');
    check($trip['planned_start_date_from']==='2026-09-01'&&$trip['planned_start_date_to']===null,'Open range');
    check((int)$pdo->query("SELECT membership_id FROM users WHERE id={$trip['responsible_user_id']}")->fetchColumn()===10,'Central membership projection, not legacy numeric ID');
    check((int)$pdo->query('SELECT COUNT(*) FROM waste_trip_requests')->fetchColumn()===3,'Duplicate membership retained');
    $pdo->exec("UPDATE fixture_flights SET status='completed' WHERE id=226");
    $withoutDates=$run();check($withoutDates['failed']===0,'Legacy completed status synchronizes even when factual dates are absent: '.json_encode($withoutDates,JSON_UNESCAPED_UNICODE));
    check((string)$pdo->query("SELECT status FROM waste_trips WHERE id=$id")->fetchColumn()==='completed','Source status remains authoritative without factual dates');
    $pdo->exec("UPDATE fixture_flights SET status='planned_route' WHERE id=226");
    check($run()['failed']===0,'Legacy status reversal synchronizes');
    $registeredLink=(int)$pdo->query("SELECT id FROM waste_trip_requests WHERE waste_trip_id=$id")->fetchColumn();
    $pdo->prepare("INSERT INTO waste_trip_registration_journal(waste_trip_id,waste_trip_request_id,event_kind,action_text,actor_login) VALUES(?,?,'initial','Fixture registration','owner')")
        ->execute([$id,$registeredLink]);
    $registeredRefresh=$run();
    check($registeredRefresh['failed']===0,'Existing registered request links synchronize: '.json_encode($registeredRefresh,JSON_UNESCAPED_UNICODE));
    check((int)$pdo->query("SELECT id FROM waste_trip_requests WHERE waste_trip_id=$id")->fetchColumn()===$registeredLink,'Registration journal keeps its request link identity');
    $pdo->exec("INSERT INTO feo_requests(zayavka_id,mass_netto,gruzopoluchatel) VALUES('700003','1.0000','Unknown'); UPDATE fixture_flights SET zayavki_ids='700003' WHERE id=226");
    $registeredRemoval=$run();
    check($registeredRemoval['failed']===0,'Registered request may leave the current composition without deleting its history');
    check((int)$pdo->query("SELECT COUNT(*) FROM waste_trip_requests WHERE id=$registeredLink AND removed_at IS NOT NULL")->fetchColumn()===1,'Registered request link is archived');
    check((int)$pdo->query("SELECT waste_trip_request_id FROM waste_trip_registration_journal WHERE waste_trip_id=$id")->fetchColumn()===$registeredLink,'Registration journal keeps the archived link');
    check((string)$pdo->query("SELECT request_ids FROM (SELECT GROUP_CONCAT(fr.zayavka_id) AS request_ids FROM waste_trip_requests wtr JOIN feo_requests fr ON fr.id=wtr.feo_request_id WHERE wtr.waste_trip_id=$id AND wtr.removed_at IS NULL) current_requests")->fetchColumn()==='700003','Current composition follows the source');
    $pdo->exec("UPDATE fixture_flights SET zayavki_ids='700001' WHERE id=226");
    check($run()['failed']===0,'Restored registered request links synchronize');
    check((int)$pdo->query("SELECT id FROM waste_trip_requests WHERE waste_trip_id=$id AND removed_at IS NULL")->fetchColumn()===$registeredLink,'Restored request reuses the registered link');
    check(count(Trips::fetchTrips($pdo,['company'=>'mg_logistic']))===1,'MG company filter');
    check(count(Trips::fetchTrips($pdo,['company'=>'planex']))===0,'Planex company filter excludes MG');
    check(count(Trips::fetchTrips($pdo,['company'=>'invalid']))===2,'Unknown company filter falls back to all trips');
    $pdo->exec("INSERT INTO fixture_drivers(id,full_name,vehicle_make_plate) VALUES(81,'Другой Алексей','Hyundai С894НО193'); UPDATE fixture_flights SET driver_id=81,legal_entity_code='planex' WHERE id=227");
    $unmatchedRefresh=$run();
    check($unmatchedRefresh['failed']===0 && $unmatchedRefresh['warnings']===[],'Unmatched executor does not create repetitive sync notices');
    check(count(Trips::fetchTrips($pdo,['company'=>'planex']))===1,'Planex company filter selects its flights');
    check(count(Trips::fetchTrips($pdo,['company'=>'mg_logistic']))===1,'MG company filter excludes Planex flights');
    $pdo->exec('UPDATE fixture_flights SET driver_id=NULL WHERE id=227');
    $filtered=Trips::fetchTrips($pdo,['q'=>'700002']);
    check(count($filtered)===1&&(int)$filtered[0]['request_count']===2&&(float)$filtered[0]['snapshot_total_mass_kg']===4000.0,'Search by one request keeps full trip count and mass');
    $pdo->exec("INSERT INTO contractors(id,name,inn,status) VALUES(2,'Manual carrier','7700000002','active');
        INSERT INTO drivers(id,full_name,phone,status) VALUES(2,'Петров Пётр','+70000000001','active');
        INSERT INTO vehicle_units(id,plate_number,brand,status) VALUES(2,'А123ВС77','Manual','active');
        INSERT INTO vehicle_sets(id,set_type,primary_vehicle_unit_id,status) VALUES(2,'single',2,'active');
        INSERT INTO driver_vehicle_blocks(id,driver_id,vehicle_set_id,status) VALUES(2,2,2,'active');
        INSERT INTO crews(id,contractor_id,vehicle_id,driver_id,driver_vehicle_block_id,status) VALUES(2,2,2,2,2,'active');
        INSERT INTO crew_drivers(crew_id,driver_id,position) VALUES(2,2,0)");
    $current=Trips::fetchTrip($pdo,$id);
    $manualInput=array_intersect_key($current,array_flip(['client_id','responsible_user_id','route_executor_id','destination_type','utilizer_id','status']));
    $manualInput['route_executor_id']=2;
    $pdo->failAt='INSERT INTO waste_trip_audit_log';
    reject(static fn()=>Trips::update($pdo,$id,$manualInput,[],(int)$current['lock_version'],$actor),'Failed manual executor save rolls back');
    $pdo->failAt=null;
    check((int)Trips::fetchTrip($pdo,$id)['route_executor_id']===1,'Failed save retains source executor');
    Trips::update($pdo,$id,$manualInput,[],(int)$current['lock_version'],$actor);
    $pdo->exec('UPDATE fixture_flights SET driver_id=NULL WHERE id=226');
    $manualRefresh=$run();check($manualRefresh['failed']===0,'Manual executor survives source refresh');
    check((int)Trips::fetchTrip($pdo,$id)['route_executor_id']===2,'Manual contractor and driver remain assigned when source becomes empty');
    $pdo->exec('UPDATE fixture_flights SET driver_id=80 WHERE id=226');
    $run();check((int)Trips::fetchTrip($pdo,$id)['route_executor_id']===2,'Later source match cannot replace manual contractor and driver');
    $historicalId=(int)$pdo->query('SELECT id FROM waste_trips WHERE business_id=227')->fetchColumn();
    $pdo->exec("UPDATE waste_trips SET route_executor_id=2 WHERE id=$historicalId");
    $pdo->prepare("INSERT INTO waste_trip_audit_log(waste_trip_id,action,before_json,after_json,actor_user_id,actor_role) VALUES(?,'update',?,?,1,'company_owner')")
        ->execute([$historicalId,'{"trip":{"route_executor_id":null}}','{"trip":{"route_executor_id":2}}']);
    $run();check((int)Trips::fetchTrip($pdo,$historicalId)['route_executor_id']===2,'Existing manual assignment without new marker remains protected');
    $pdo->exec("UPDATE waste_trips SET status='searching_executor' WHERE business_id=226");
    $planned=Trips::fetchTrips($pdo,['status'=>'planned_route']);
    check(count($planned)===1&&(int)$planned[0]['business_id']===226,'Planned filter includes historical searching alias');
    $pdo->exec("INSERT INTO waste_trips (business_id,client_id,responsible_user_id,destination_type,status,planned_start_mode,source_system)
        SELECT 900,client_id,responsible_user_id,destination_type,'completed',planned_start_mode,'erp' FROM waste_trips WHERE business_id=226;
        INSERT INTO waste_trips (business_id,client_id,responsible_user_id,destination_type,status,planned_start_mode,source_system)
        SELECT 901,client_id,responsible_user_id,destination_type,'in_progress',planned_start_mode,'erp' FROM waste_trips WHERE business_id=226;
        INSERT INTO waste_trips (business_id,client_id,responsible_user_id,destination_type,status,planned_start_mode,source_system)
        SELECT 902,client_id,responsible_user_id,destination_type,'planned_route',planned_start_mode,'erp' FROM waste_trips WHERE business_id=227;
        INSERT INTO waste_trips (business_id,client_id,responsible_user_id,destination_type,status,planned_start_mode,source_system)
        SELECT 903,client_id,responsible_user_id,destination_type,'completed',planned_start_mode,'erp' FROM waste_trips WHERE business_id=227");
    $ids=static fn(array $rows):array=>array_map(static fn(array $row):int=>(int)$row['business_id'],$rows);
    check($ids(Trips::fetchTrips($pdo))===[902,226,227,901,903,900],'Default status order');
    check($ids(Trips::fetchTrips($pdo,['sort'=>'status']))===[902,226,227,901,903,900],'Status order with ID tie break');
    check($ids(Trips::fetchTrips($pdo,['sort'=>'manager']))===[902,227,903,226,901,900],'Manager groups contain status order');
    $managerOptions=Trips::fetchRegistryManagers($pdo);
    check(count($managerOptions)===2,'Registry manager choices come from visible trips');
    $trip226=$pdo->query('SELECT responsible_user_id FROM waste_trips WHERE business_id=226')->fetch();
    $combined=Trips::fetchTrips($pdo,['status'=>'planned_route','company'=>'mg_logistic','manager'=>(int)$trip226['responsible_user_id'],'q'=>'226','sort'=>'status']);
    check($ids($combined)===[226],'Status, company, manager and search filter together');
    $otherManager=(int)array_values(array_filter($managerOptions,static fn(array $row):bool=>(int)$row['id']!==(int)$trip226['responsible_user_id']))[0]['id'];
    check(Trips::fetchTrips($pdo,['status'=>'planned_route','company'=>'mg_logistic','manager'=>$otherManager,'q'=>'226'])===[],'Manager filter excludes another manager without dropping other filters');
    check($ids(Trips::fetchTrips($pdo,['sort'=>'id']))===[903,902,901,900,227,226],'Explicit ID order');
    check($ids(Trips::fetchTrips($pdo,['sort'=>'unknown']))===[902,226,227,901,903,900],'Unknown sort falls back to status');
    $pdo->exec('DELETE FROM waste_trips WHERE business_id BETWEEN 900 AND 903');
    $payment=$pdo->query("SELECT * FROM waste_trip_customer_payments WHERE waste_trip_id=$id")->fetch();
    $obligation=$pdo->query("SELECT * FROM finance_obligations WHERE source_parent_type='WASTE_TRIP' AND source_parent_id=$id AND direction='RECEIVABLE'")->fetch();
    check($payment['amount']==='1000.00'&&$payment['payment_method']===''&&$obligation['due_date']===null,'Sale with default terms waits for factual unload');
    check($pdo->query("SELECT amount FROM waste_trip_general_payments WHERE waste_trip_id=$id")->fetchColumn()==='1500.00','General amount from weight share');
    check((int)$payment['days_count']===4 && $payment['days_kind']==='working' && $payment['condition_type']==='after_end' && $payment['specific_due_date']===null,'New synchronized sale defaults to four working days after unloading');
    $general=$pdo->query("SELECT condition_type,days_count,days_kind FROM waste_trip_general_payments WHERE waste_trip_id=$id")->fetch();
    check($general['condition_type']==='after_end' && (int)$general['days_count']===3 && $general['days_kind']==='calendar','General customer keeps its separate three-calendar-day default');
    check((int)$pdo->query("SELECT COUNT(*) FROM waste_trip_carrier_payments")->fetchColumn()===0,'Sync never creates carrier costs');
    $pdo->exec("INSERT INTO waste_trip_carrier_payments(waste_trip_id,sort_order,amount,payment_type,payment_method,condition_type,days_count,days_kind) VALUES($id,1,700,'cash','cash','after_end',7,'calendar');
        UPDATE waste_trip_customer_payments SET days_count=11,days_kind='calendar' WHERE waste_trip_id=$id;
        UPDATE waste_trip_general_payments SET days_count=5 WHERE waste_trip_id=$id");
    $pdo->exec("INSERT INTO finance_money_accounts(id,type,name) VALUES(1,'CASH','Synthetic cash');
        INSERT INTO finance_operations(id,operation_type,status,source,money_account_id,operation_date,amount) VALUES(1,'INCOME','POSTED','MANUAL',1,'2026-09-01',1);
        INSERT INTO finance_operation_allocations(operation_id,obligation_id,amount,allocation_date) VALUES(1,{$obligation['id']},1,'2026-09-01')");
    $repeat=$run();check($repeat['updated']===2&&$repeat['created']===0,'Repeat upsert');
    check((int)$pdo->query("SELECT COUNT(*) FROM waste_trips WHERE id=$id AND legal_entity_code='mg_logistic' AND legacy_destination_warehouse_name='Склад Север'")->fetchColumn()===1,'Repeated manual sync retains required metadata');
    $pdo->exec("UPDATE fixture_flights SET legal_entity_code='planex',route_type='warehouse_to_warehouse',source_warehouse_id=1,destination_warehouse_id=2 WHERE id=226");
    $metadataRefresh=$run();check($metadataRefresh['failed']===0,'Manual metadata refresh');
    $metadata=$pdo->query("SELECT * FROM waste_trips WHERE id=$id")->fetch();
    check($metadata['legal_entity_code']==='planex'&&\App\Service\WasteTripLegacyMetadataService::warehouseLabel($metadata)==="Загрузка со склада:\nСклад Север\nВыгрузка на склад:\nСклад Юг",'Manual sync refreshes legal entity and both warehouse names');
    check((int)$pdo->query("SELECT id FROM waste_trips WHERE business_id=226")->fetchColumn()===$id,'Stable trip identity');
    foreach (['legacy_flights','legacy_prefill'] as $provenance) {
        $pdo->prepare('UPDATE waste_trips SET source_system=? WHERE id=?')->execute([$provenance,$id]);
        $adopted=$run();
        check($adopted['created']===0&&$adopted['failed']===0,'Existing import adoption');
        check((int)$pdo->query('SELECT COUNT(*) FROM waste_trips')->fetchColumn()===2,'Adoption never duplicates trips');
        check($pdo->query("SELECT source_system FROM waste_trips WHERE id=$id")->fetchColumn()==='legacy_sync','Adopted synchronization provenance');
    }
    // Equal totals preserve all installments; changing a paid later installment fails atomically.
    $pdo->exec("UPDATE waste_trip_customer_payments SET amount=600 WHERE waste_trip_id=$id;
        INSERT INTO waste_trip_customer_payments(waste_trip_id,sort_order,amount,payment_type,payment_method,condition_type) VALUES($id,2,400,'cash','cash','')");
    $schedule=$run();check($schedule['failed']===0,'Equal installment total preserved');
    check((int)$pdo->query("SELECT COUNT(*) FROM waste_trip_customer_payments WHERE waste_trip_id=$id")->fetchColumn()===2,'Same total keeps installments');
    $secondObligation=(int)$pdo->query("SELECT o.id FROM finance_obligations o JOIN waste_trip_customer_payments p ON p.id=o.source_id WHERE o.source_type='WASTE_TRIP_CUSTOMER_PAYMENT' AND o.source_parent_id=$id AND p.sort_order=2 AND o.cancelled_at IS NULL")->fetchColumn();
    check($secondObligation>0,'Second installment obligation exists');
    $pdo->exec("INSERT INTO finance_operation_allocations(operation_id,obligation_id,amount,allocation_date) VALUES(1,$secondObligation,1,'2026-09-01'); UPDATE fixture_flights SET cost=900 WHERE id=226");
    $guarded=$run();check($guarded['failed']===1,'Paid installment prevents destructive consolidation');
    check((int)$pdo->query("SELECT SUM(amount) FROM waste_trip_customer_payments WHERE waste_trip_id=$id")->fetchColumn()===1000,'Failed consolidation preserves entire schedule');
    $pdo->exec("UPDATE finance_operation_allocations SET cancelled_at=NOW() WHERE obligation_id=$secondObligation");
    $consolidated=$run();check($consolidated['failed']===0,'Unpaid aggregate can synchronize');
    check((int)$pdo->query("SELECT COUNT(*) FROM waste_trip_customer_payments WHERE waste_trip_id=$id")->fetchColumn()===1,'Consolidated aggregate');
    $pdo->exec("UPDATE fixture_flights SET cost=1,comment='Changed',status='completed',actual_start_date='2026-09-01',actual_end_date='2026-09-02' WHERE id=226");
    $result=$run();check($result['failed']===0,'Update: '.json_encode($result));
    check($pdo->query("SELECT amount FROM waste_trip_customer_payments WHERE waste_trip_id=$id")->fetchColumn()==='1.00','Literal cost 1');
    check($pdo->query("SELECT amount FROM waste_trip_carrier_payments WHERE waste_trip_id=$id")->fetchColumn()==='700.00','Manual carrier cost never overwritten');
    check((int)$pdo->query("SELECT days_count FROM waste_trip_customer_payments WHERE waste_trip_id=$id")->fetchColumn()===11,'Manual sale terms survive source changes');
    check($pdo->query("SELECT days_kind FROM waste_trip_customer_payments WHERE waste_trip_id=$id")->fetchColumn()==='calendar','Manual calendar-day choice survives source changes');
    check((int)$pdo->query("SELECT days_count FROM waste_trip_general_payments WHERE waste_trip_id=$id")->fetchColumn()===5,'Manual general terms survive sync');
    check($pdo->query("SELECT due_date FROM finance_obligations WHERE id={$obligation['id']}")->fetchColumn()==='2026-09-13','Sale uses factual unloading plus manual offset');
    check((int)$pdo->query("SELECT id FROM finance_obligations WHERE source_parent_type='WASTE_TRIP' AND source_parent_id=$id AND direction='RECEIVABLE' AND cancelled_at IS NULL")->fetchColumn()===(int)$obligation['id'],'Stable payable identity');
    check($pdo->query("SELECT paid_amount FROM finance_obligations WHERE id={$obligation['id']}")->fetchColumn()==='1.00','Posted payment retained through rate update');
    $pdo->exec('UPDATE fixture_flights SET cost=2 WHERE id=226');
    foreach(['INSERT INTO waste_trip_requests','UPDATE waste_trip_customer_payments SET amount','INSERT INTO waste_trip_audit_log'] as $failure) {
        $pdo->failAt=$failure;
        $failed=$run();
        $pdo->failAt=null;
        check($failed['failed']>=1,'Injected stage failure expected: '.$failure);
        check($pdo->query("SELECT amount FROM waste_trip_customer_payments WHERE waste_trip_id=$id")->fetchColumn()==='1.00','Stage rollback kept prior rate');
        check((int)$pdo->query("SELECT COUNT(*) FROM waste_trip_requests WHERE waste_trip_id=$id AND removed_at IS NULL")->fetchColumn()===1,'Stage rollback kept composition');
    }
    $pdo->exec('UPDATE fixture_flights SET cost=1 WHERE id=226');
    // A bad source row does not silently trim composition or partially change its cost/status.
    $pdo->exec("UPDATE fixture_flights SET zayavki_ids='999999',cost=400 WHERE id=226");
    $bad=$run();check($bad['failed']===1,'Missing FEO must fail one row');
    check($pdo->query("SELECT amount FROM waste_trip_customer_payments WHERE waste_trip_id=$id")->fetchColumn()==='1.00','Row rollback');
    $pdo->exec("UPDATE fixture_flights SET zayavki_ids='700001' WHERE id=226");
    $legacy->failRead=true;reject($run,'Source failure expected');$legacy->failRead=false;
    check((int)$pdo->query('SELECT COUNT(*) FROM waste_trips WHERE deleted_at IS NOT NULL')->fetchColumn()===0,'Source failure must not delete');
    reject(static fn()=>Sync::run($legacy,$pdo,$central,25,['user_id'=>1,'role_code'=>'logist']),'Role denial');
    Scope::$allowed=false;reject($run,'Access denial');Scope::$allowed=true;
    $lockName='erp_waste_trip_legacy_sync_'.substr(hash('sha256',$db),0,24);
    $lock=$central->prepare('SELECT GET_LOCK(?,0)');$lock->execute([$lockName]);
    reject($run,'Concurrent lock denial');$central->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
    $pdo->exec('DELETE FROM fixture_flights WHERE id=226');
    $deleted=$run();check($deleted['deleted']===1,'Deleted source row');
    check(Trips::fetchTrip($pdo,$id)===null,'Deleted trip hidden');
    check($pdo->query("SELECT status FROM finance_obligations WHERE id={$obligation['id']}")->fetchColumn()==='cancelled','Deleted trip payable cancelled');
    check((int)$pdo->query('SELECT COUNT(*) FROM finance_operation_allocations WHERE operation_id=1 AND cancelled_at IS NULL')->fetchColumn()===1,'Source deletion retains posted allocation');
    $pdo->exec("INSERT INTO fixture_flights(id,zayavki_ids,comment,status,cost,driver_id,assigned_manager_id,unload_type) VALUES(226,'700001','Restored','planned_route',42,80,3,'SKLAD')");
    $restored=$run();check($restored['updated']===2&&$restored['failed']===0,'Restore source row');
    check((int)Trips::fetchTrip($pdo,$id)['business_id']===226,'Restore same identity');
    $pdo->exec("INSERT INTO waste_trips(business_id,client_id,responsible_user_id,destination_type,status) VALUES(500,13,1,'warehouse','searching_executor')");
    $pdo->exec('DELETE FROM fixture_flights WHERE id>225');
    $empty=$run();check($empty['deleted']===2,'Successful empty snapshot deletes managed trips');
    check((int)$pdo->query('SELECT COUNT(*) FROM waste_trips WHERE business_id=500 AND deleted_at IS NULL')->fetchColumn()===1,'Independent trip protected');
    $pdo->exec("INSERT INTO fixture_flights(id,zayavki_ids,status,assigned_manager_id,unload_type) VALUES(500,'700001','planned_route',3,'SKLAD'),(501,'700001','planned_route',3,'SKLAD')");
    $collision=$run();check($collision['failed']===1&&$collision['created']===1,'Independent ID collision blocks only that source row; no ceiling at 400');
    check($pdo->query('SELECT source_system FROM waste_trips WHERE business_id=500')->fetchColumn()===null,'Independent identity never adopted');
    // Source NULL is unknown, not zero; terms survive disappearance and return.
    $other=(int)$pdo->query('SELECT id FROM waste_trips WHERE business_id=501')->fetchColumn();
    $pdo->exec("UPDATE waste_trip_customer_payments SET days_count=9 WHERE waste_trip_id=$other; UPDATE fixture_flights SET cost=77 WHERE id=501");
    $run();
    $pdo->exec('UPDATE fixture_flights SET cost=NULL WHERE id=501');$run();
    check($pdo->query("SELECT amount FROM waste_trip_customer_payments WHERE waste_trip_id=$other")->fetchColumn()===null,'NULL source is not zero');
    $pdo->exec('UPDATE fixture_flights SET cost=0 WHERE id=501');$run();
    $zero=$pdo->query("SELECT amount,days_count FROM waste_trip_customer_payments WHERE waste_trip_id=$other")->fetch();
    check($zero['amount']==='0.00'&&(int)$zero['days_count']===9,'Zero source restores with manually changed terms');
    $pdo->exec('UPDATE status_blocks SET cost=8000');$run();
    check($pdo->query("SELECT amount FROM waste_trip_general_payments WHERE waste_trip_id=$other")->fetchColumn()==='3000.00','General amount continuously synchronized');
    // A real cutover preserves former sale/cost amounts, clears the new manual cost,
    // is idempotent, and preserves invoice/payment-linked historical settlements.
    $pdo->exec("INSERT INTO waste_trips(business_id,client_id,responsible_user_id,destination_type,status,financial_model_version) VALUES(900,13,1,'warehouse','planned_route',1)");
    $oldId=(int)$pdo->lastInsertId();
    $terms=['amount'=>'2000.00','payment_type'=>'cash','payment_method'=>'cash','vat_rate'=>null,'condition_type'=>'after_end','days_count'=>12,'days_kind'=>'calendar','specific_due_date'=>null,'condition_comment'=>null];
    \App\Service\WasteTripCustomerPaymentService::store($pdo,$oldId,[$terms],$actor);
    \App\Service\WasteTripCarrierPaymentService::store($pdo,$oldId,[array_replace($terms,['amount'=>'900.00','days_count'=>3])],$actor);
    check(\App\Service\WasteTripFinancialTransitionService::transition($pdo,$actor)===1,'Exactly old rows transition');
    check($pdo->query("SELECT amount FROM waste_trip_general_payments WHERE waste_trip_id=$oldId")->fetchColumn()==='2000.00','Old sale becomes general');
    check($pdo->query("SELECT amount FROM waste_trip_customer_payments WHERE waste_trip_id=$oldId")->fetchColumn()==='900.00','Old cost becomes sale');
    check((int)$pdo->query("SELECT COUNT(*) FROM waste_trip_carrier_payments WHERE waste_trip_id=$oldId")->fetchColumn()===0,'New manual cost blank');
    check(\App\Service\WasteTripFinancialTransitionService::transition($pdo,$actor)===0,'Cutover idempotent');
    $pdo->exec("UPDATE waste_trips SET financial_model_version=1 WHERE id=$id; DELETE FROM waste_trip_general_payments WHERE waste_trip_id=$id");
    $originalPayments=\App\Service\WasteTripCustomerPaymentService::fetchForTrip($pdo,$id);
    $financialRows=static fn()=>$pdo->query("SELECT * FROM finance_obligations WHERE source_parent_type='WASTE_TRIP' AND source_parent_id=$id ORDER BY id")->fetchAll();
    $originalObligations=$financialRows();
    check(\App\Service\WasteTripFinancialTransitionService::transition($pdo,$actor)===1,'Paid historical rows preserve settlements during cutover');
    check((int)$pdo->query("SELECT financial_model_version FROM waste_trips WHERE id=$id")->fetchColumn()===3,'Historical settlement mode');
    check($financialRows()===$originalObligations,'All original obligation fields preserved at cutover');
    $historical=['id'=>$id,'financial_model_version'=>3];
    check(Trips::financialCustomerPayments($pdo,$historical)===$originalPayments,'Original source IDs and terms retained for documents');
    $count=count($originalObligations);
    $pdo->exec("UPDATE waste_trips SET deleted_at=NULL,status='completed',actual_start_date='2026-09-01',actual_end_date='2026-09-02' WHERE id=$id");
    $pdo->beginTransaction();
    \App\Service\WasteTripCustomerPaymentService::synchronizeCost($pdo,$id,null,null,$actor);
    \App\Service\WasteTripGeneralPaymentService::synchronizeCost($pdo,$id,'999.00',$actor);
    $pdo->commit();
    \App\Service\FinanceObligationService::syncWasteTrip($pdo,$id);
    $afterDisplay=$financialRows();
    check(count($afterDisplay)===$count,'Informational changes create no duplicate debt');
    foreach($afterDisplay as $index=>$row)foreach(['id','source_id','source_key','direction','amount','condition_type','days_count','due_date','cancelled_at'] as $field)
        check($row[$field]===$originalObligations[$index][$field],'Historical obligation remains unchanged: '.$field);
    // Payment cascade still operates on preserved obligations, including cancellation.
    $activeObligation=(int)$obligation['id'];
    $pdo->exec("UPDATE finance_obligations SET cancelled_at=NULL WHERE id=$activeObligation;
        INSERT INTO finance_operations(id,operation_type,status,source,money_account_id,operation_date,amount) VALUES(2,'INCOME','POSTED','MANUAL',1,'2026-09-02',5);
        INSERT INTO finance_operation_allocations(operation_id,obligation_id,amount,allocation_date) VALUES(2,$activeObligation,5,'2026-09-02')");
    $newAllocation=(int)$pdo->lastInsertId();
    \App\Service\FinanceSettlementCascadeService::cascadeAfterAllocationCreate($pdo,$newAllocation);
    check($pdo->query("SELECT paid_amount FROM finance_obligations WHERE id=$activeObligation")->fetchColumn()==='6.00','Subsequent payment settles preserved obligation');
    $pdo->exec("UPDATE finance_operation_allocations SET cancelled_at=NOW() WHERE id=$newAllocation");
    \App\Service\FinanceSettlementCascadeService::cascadeAfterAllocationCancel($pdo,$newAllocation);
    check($pdo->query("SELECT paid_amount FROM finance_obligations WHERE id=$activeObligation")->fetchColumn()==='1.00','Cancellation recomputes preserved settlement');
    $pdo->exec("UPDATE waste_trips SET deleted_at=NOW(),status='cancelled' WHERE id=$id");
    \App\Service\FinanceObligationService::syncWasteTrip($pdo,$id);
    check($pdo->query("SELECT cancelled_at FROM finance_obligations WHERE id=$activeObligation")->fetchColumn()===null,'Source removal does not cancel real historical debt');
    $prices=\App\Service\WasteTripLegacyPriceService::calculate([['zayavki_ids'=>'1,2,3','cost'=>'100.50'],['zayavki_ids'=>'1','cost'=>'10.00']],['1'=>1,'2'=>3,'3'=>2]);
    check($prices===['1'=>2600,'3'=>3300,'2'=>5150],'Legacy whole-ruble shares, heaviest remainder and additive blocks');
    check(\App\Service\WasteTripLegacyPriceService::flightTotal(['1','999'],$prices)===null,'Incomplete pricing never implies a partial total');
    $guard=new \ReflectionMethod(Trips::class,'assertSourceAmountUnchanged');
    $guard->invoke(null,[['amount'=>null]],[]);
    $guard->invoke(null,[['amount'=>'42.00']],[['amount'=>'42.00']]);
    reject(static fn()=>$guard->invoke(null,[['amount'=>'43.00']],[['amount'=>'42.00']]),'Source amount cannot be changed manually');
    reject(static fn()=>$guard->invoke(null,[],[['amount'=>'42.00']]),'Source amount cannot be removed manually');
    // A successful manual sale edit transfers ownership of this amount only.
    $saveSale=static function(int $tripId, ?string $amount, int $days=9, ?int $version=null) use($pdo,$actor): void {
        $current=Trips::fetchTrip($pdo,$tripId);
        $input=array_intersect_key($current,array_flip(['client_id','responsible_user_id','route_executor_id','destination_type','utilizer_id','status']));
        $rows=\App\Service\WasteTripCustomerPaymentService::parseRows([['amount'=>$amount,'payment_method'=>'unknown','vat_rate'=>'unknown','days_count'=>$days]],true,true);
        Trips::update($pdo,$tripId,$input,[], $version??(int)$current['lock_version'],$actor,$rows);
    };
    $disabled=static fn(int $tripId): bool=>\App\Service\WasteTripCustomerPaymentService::amountSyncDisabled($pdo,$tripId);
    $sale=static fn(int $tripId)=>$pdo->query("SELECT amount FROM waste_trip_customer_payments WHERE waste_trip_id=$tripId")->fetchColumn();
    $saveSale($other,'0',12);
    check(!$disabled($other),'Equal normalized amount and changed terms keep synchronization enabled');
    $pdo->exec('UPDATE fixture_flights SET cost=80 WHERE id=501');$run();
    check($sale($other)==='80.00','Unchanged manual amount still follows source');
    $stale=(int)Trips::fetchTrip($pdo,$other)['lock_version'];
    $run();
    reject(static fn()=>$saveSale($other,'95',12,$stale),'Stale form must fail');
    check(!$disabled($other)&&$sale($other)==='80.00','Stale form does not disable sync');
    Scope::$allowed=false;
    reject(static fn()=>$saveSale($other,'95'),'Unauthorized edit must fail');
    Scope::$allowed=true;
    $pdo->failAt='INSERT INTO waste_trip_audit_log';
    reject(static fn()=>$saveSale($other,'95'),'Audit failure must roll back manual amount');
    $pdo->failAt=null;
    check(!$disabled($other)&&$sale($other)==='80.00','Failed save rolls back value and ownership');
    $saveSale($other,'95.50',12);
    check($disabled($other)&&Trips::fetchTrip($pdo,$other,true)['customer_amount_sync_disabled']===true,'Committed edit and reopened form retain manual ownership');
    $saveSale($other,'95.50',14);
    $pdo->exec("UPDATE fixture_flights SET cost=96,comment='Other fields still sync',status='completed',actual_start_date='2026-09-01',actual_end_date='2026-09-02' WHERE id=501; UPDATE status_blocks SET cost=12000");
    $run();
    check($sale($other)==='95.50','Source cannot overwrite manually saved sale');
    $updated=Trips::fetchTrip($pdo,$other,true);
    check($updated['comment']==='Other fields still sync'&&$updated['status']==='completed'&&$updated['actual_end_date']==='2026-09-02','Other fields still follow source');
    check($updated['general_payments'][0]['amount']==='4500.00','General amount still follows source');
    check((int)$updated['customer_payments'][0]['days_count']===14,'Later term edit does not reset manual ownership');
    check($pdo->query("SELECT amount FROM finance_obligations WHERE source_parent_type='WASTE_TRIP' AND source_parent_id=$other AND direction='RECEIVABLE' AND cancelled_at IS NULL")->fetchColumn()==='95.50','Model 2 obligation follows manual sale');
    foreach([null,'0','95.50','123'] as $sourceAmount) {
        $pdo->beginTransaction();
        \App\Service\WasteTripCustomerPaymentService::synchronizeCost($pdo,$other,$sourceAmount,null,$actor);
        $pdo->commit();
        check($sale($other)==='95.50','Unknown, zero, equal and changed source preserve override');
    }
    $saveSale($other,'0');$run();
    check($sale($other)==='0.00'&&$disabled($other),'Manual zero is valid and remains protected');
    $pdo->exec('DELETE FROM fixture_flights WHERE id=501');$run();
    $pdo->exec("INSERT INTO fixture_flights(id,zayavki_ids,status,cost,assigned_manager_id,unload_type) VALUES(501,'700001','completed',123,3,'SKLAD')");$run();
    check($sale($other)==='0.00'&&$disabled($other),'Source removal and restoration never reset override');
    check(!$disabled($id),'Override belongs only to the edited trip');
    $pdo->exec("UPDATE waste_trips SET deleted_at=NULL,status='completed' WHERE id=$id");
    $preserved=Trips::financialCustomerPayments($pdo,$historical);
    $saveSale($id,'321');
    check($disabled($id)&&Trips::financialCustomerPayments($pdo,$historical)===$preserved,'Model 3 manual display edit preserves historical customer schedule');
    check(count($financialRows())===$count,'Model 3 manual edit creates no extra obligations');
    $pdo->exec("UPDATE waste_trips SET deleted_at=NULL,status='completed' WHERE id=$other");
    $parity=\App\Service\WasteTripParityService::state($pdo,$legacy);
    check($parity['available']===true&&$parity['legacy_count']===2&&$parity['erp_linked_count']===2&&$parity['erp_independent_count']===2,'Parity separates linked and independent flights');
    check(!in_array(501,$parity['missing_ids'],true)&&in_array(500,$parity['missing_ids'],true),'Parity identifies source ID collision');
    check(\App\Service\WasteTripParityService::attentionCount($pdo,$legacy)===2,'Menu badge counts both missing and extra linked IDs');
    $pdo->exec("DELETE FROM fixture_flights WHERE id=500; INSERT INTO fixture_flights(id,zayavki_ids,status,assigned_manager_id,unload_type) VALUES(226,'700001','completed',3,'SKLAD')");
    check(\App\Service\WasteTripParityService::state($pdo,$legacy)['mismatch']===false,'Equal linked ID sets do not warn even with independent ERP trips');
    check(\App\Service\WasteTripParityService::attentionCount($pdo,$legacy)===0,'Menu badge is hidden when linked ID sets match');
    $pdo->exec("UPDATE waste_trips SET status='in_progress',legacy_sync_error='Legacy факт содержит начало позже окончания.' WHERE business_id=226");
    $parity=\App\Service\WasteTripParityService::state($pdo,$legacy);
    check($parity['mismatch']===true&&$parity['status_mismatch_ids']===[226]&&$parity['sync_error_ids']===[226],'Status drift and failed sync are visible even when ID sets match');
    check(\App\Service\WasteTripParityService::attentionCount($pdo,$legacy)===1,'One failed trip contributes one menu warning');
    $pdo->exec("UPDATE waste_trips SET status='completed',legacy_sync_error=NULL WHERE business_id=226");
    $otherVersion=(int)Trips::fetchTrip($pdo,$other)['lock_version'];
    reject(static fn()=>Trips::delete($pdo,$other,$otherVersion+1,$actor),'Stale delete must fail');
    reject(static fn()=>Trips::delete($pdo,$other,$otherVersion,['user_id'=>1,'role_code'=>'logist']),'Only owner can delete');
    Trips::delete($pdo,$other,$otherVersion,$actor);
    check(Trips::fetchTrip($pdo,$other)===null,'Deleted active trip disappears from registry');
    check((int)$pdo->query("SELECT COUNT(*) FROM waste_trip_audit_log WHERE waste_trip_id=$other AND action='cancel' AND after_json LIKE '%user_delete%'")->fetchColumn()===1,'Manual deletion is audited');
    $parity=\App\Service\WasteTripParityService::state($pdo,$legacy);
    check($parity['mismatch']===true&&in_array(501,$parity['missing_ids'],true),'Deleted linked trip is reported in parity details');
    check(\App\Service\WasteTripParityService::attentionCount($pdo,$legacy)===1,'Deleted linked trip adds one menu error');
    $legacy->failRead=true;
    check(\App\Service\WasteTripParityService::attentionCount($pdo,$legacy)===1,'Unavailable source leaves an attention marker');
    $legacy->failRead=false;
    check(Trips::reconcileLegacyTrip($pdo,['business_id'=>501],$actor)==='skipped'&&Trips::fetchTrip($pdo,$other)===null,'Manual synchronization does not restore deleted trip');
    $pdo->exec("INSERT INTO waste_trips (business_id,client_id,responsible_user_id,destination_type,status,planned_start_mode,source_system)
        SELECT 902,client_id,responsible_user_id,destination_type,'cancelled',planned_start_mode,'erp' FROM waste_trips WHERE id=$id");
    $cancelledId=(int)$pdo->lastInsertId();
    Trips::delete($pdo,$cancelledId,(int)Trips::fetchTrip($pdo,$cancelledId)['lock_version'],$actor);
    check(Trips::fetchTrip($pdo,$cancelledId)===null,'Cancelled independent trip can be deleted');
    echo "WASTE_TRIP_MANUAL_SALE_SYNC=PASS\n";
    echo "WASTE_TRIP_SYNCHRONIZATION_MYSQL=PASS\n";
} finally {
    if($pdo->inTransaction())$pdo->rollBack();
    if($fixed!=='')cleanCompanyPoolDatabase($pdo);else $admin->exec("DROP DATABASE `$db`");
}
}
