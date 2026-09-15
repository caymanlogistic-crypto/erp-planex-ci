<?php
declare(strict_types=1);

namespace App\Service {
    final class AccessControlService {
        public static function effectiveMembershipId(): ?int { return 77; }
        public static function canSeeAllCompanyData(array $user): bool { return true; }
    }
    final class RegulatedDataScopeService {
        public static ?\Throwable $technicalFailure = null;
        public static function assertCanCreate(string $entityType): int {
            if (self::$technicalFailure !== null) throw self::$technicalFailure;
            return 77;
        }
        public static function condition(string $entityType,string $idExpression,string $creatorExpression=''): array { return ['sql'=>'1=1','params'=>[]]; }
        public static function canAccess(\PDO $pdo,string $entityType,string $table,int $entityId): bool { return true; }
    }
    final class LinearRouteService {
        public static bool $technicalFailure = false;
        public static function isVisibleEntity(\PDO $pdo,string $table,int $id,array $user,string $scope): bool {
            if (self::$technicalFailure) {
                $e=new \PDOException('synthetic entity visibility failure');
                $e->errorInfo=['HY000',1105,'synthetic'];
                throw $e;
            }
            return $table === 'clients' && $id === 1;
        }
        public static function fetchVisibleRouteExecutors(\PDO $pdo,array $user): array { return [['id'=>1]]; }
    }
    final class FinanceObligationService { public static function syncWasteTrip(\PDO $pdo,int $tripId):int{return 0;} }
}

namespace {
use App\Service\AccessControlService;
use App\Service\LinearRouteService;
use App\Service\LocalMigrationService;
use App\Service\RegulatedDataScopeService;
use App\Service\WasteTripLegacyPrefillService;
use App\Service\WasteTripLegacySourceService;
use App\Service\WasteTripService;

final class StageFailPdo extends PDO {
    public ?string $failPrepareContains = null;
    public bool $failBegin = false;
    public bool $failCommit = false;
    private function synthetic(string $message,string $sqlState='HY000',int $driver=1105): PDOException {
        $e=new PDOException($message);$e->errorInfo=[$sqlState,$driver,'synthetic'];return $e;
    }
    public function beginTransaction(): bool {
        if($this->failBegin)throw $this->synthetic('synthetic begin failure');
        return parent::beginTransaction();
    }
    public function prepare(string $query,array $options=[]): PDOStatement|false {
        if($this->failPrepareContains!==null&&str_contains($query,$this->failPrepareContains))throw $this->synthetic('synthetic prepare failure');
        return parent::prepare($query,$options);
    }
    public function commit(): bool {
        if($this->failCommit)throw $this->synthetic('synthetic commit failure','40001',1213);
        return parent::commit();
    }
}

$root=dirname(__DIR__);
require_once $root.'/bootstrap/app.php';
require_once $root.'/app/Support/company_database.php';
require_once $root.'/app/Service/LocalMigrationService.php';
require_once $root.'/app/Service/WasteTripLegacySourceService.php';
require_once $root.'/app/Service/WasteTripIncidentService.php';
require_once $root.'/app/Service/WasteTripService.php';
require_once $root.'/app/Service/WasteTripLegacyPrefillService.php';

$fail=static function(string $message):never{fwrite(STDERR,"FAIL: {$message}\n");exit(1);};
$ok=static function(bool $value,string $message)use($fail):void{if(!$value)$fail($message);};
if(!extension_loaded('pdo_mysql')){fwrite(STDERR,"pdo_mysql required\n");exit(2);}
$host=(string)(getenv('PLANEX_SCHEMA_DB_HOST')?:'127.0.0.1');
$port=(int)(getenv('PLANEX_SCHEMA_DB_PORT')?:3306);
$user=(string)(getenv('PLANEX_SCHEMA_DB_USER')?:'planex_ci');
$pass=(string)(getenv('PLANEX_SCHEMA_DB_PASSWORD')?:'planex_ci');
$fixedDb=trim((string)(getenv('PLANEX_TEST_FIXED_DB')?:''));
$fixed=$fixedDb!=='';
if($fixed){
    $allow=array_values(array_filter(array_map('trim',explode(',',(string)(getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST')?:'')))));
    $ok((string)(getenv('APP_ENV')?:'')==='test','fixed DB requires APP_ENV=test');
    $ok(in_array($fixedDb,$allow,true),'fixed DB not allowlisted');
}
$options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false];
$admin=new PDO("mysql:host={$host};port={$port};charset=utf8mb4",$user,$pass,$options);
$db=$fixed?$fixedDb:'planex_waste_full_schema_'.getmypid();
if(!$fixed){$admin->exec("DROP DATABASE IF EXISTS `{$db}`;CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");}
$dsn="mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";
$pdo=new StageFailPdo($dsn,$user,$pass,$options);
$legacy=new PDO($dsn,$user,$pass,$options);
$incidentLog=sys_get_temp_dir().'/waste_trip_full_schema_incidents_'.getmypid().'.jsonl';
@unlink($incidentLog);putenv('PLANEX_WASTE_TRIP_INCIDENT_LOG='.$incidentLog);putenv('PLANEX_DEPLOYED_SHA='.str_repeat('b',40));

$counts=static fn(PDO $p):array=>[
    'trips'=>(int)$p->query('SELECT COUNT(*) FROM waste_trips')->fetchColumn(),
    'links'=>(int)$p->query('SELECT COUNT(*) FROM waste_trip_requests')->fetchColumn(),
    'audit'=>(int)$p->query('SELECT COUNT(*) FROM waste_trip_audit_log')->fetchColumn(),
];
$incidentRows=static function()use($incidentLog):array{
    $lines=is_file($incidentLog)?(file($incidentLog,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[]):[];
    return array_map(static fn(string $line):array=>json_decode($line,true,512,JSON_THROW_ON_ERROR),$lines);
};
$businessError=static function(callable $call,string $needle)use($ok,$incidentRows):void{
    $before=count($incidentRows());$message='';try{$call();}catch(RuntimeException $e){$message=$e->getMessage();}
    $ok($message!==''&&str_contains($message,$needle),'expected business error: '.$needle);
    $ok(count($incidentRows())===$before,'business error must not create WT incident: '.$needle);
};
$technical=static function(callable $call,string $stage,array $beforeCounts)use($pdo,$ok,$incidentRows,$counts):void{
    $beforeIncidents=count($incidentRows());$message='';try{$call();}catch(RuntimeException $e){$message=$e->getMessage();}
    $ok((bool)preg_match('/^Создание рейса не выполнено\. Код ошибки: WT-[0-9]{6}-[0-9]{6}-[A-F0-9]{6}\.$/',$message),'technical failure must expose safe WT incident at '.$stage);
    $rows=$incidentRows();$ok(count($rows)===$beforeIncidents+1,'technical failure must write one incident at '.$stage);$row=end($rows);
    $ok(($row['stage']??null)===$stage,'incident stage mismatch for '.$stage);
    $ok(($row['exception_class']??null)==='PDOException','incident exception class mismatch for '.$stage);
    $ok((string)($row['sqlstate']??'')!=='','incident SQLSTATE missing for '.$stage);
    $ok((string)($row['driver_code']??'')!=='','incident driver missing for '.$stage);
    $ok($counts($pdo)===$beforeCounts,'failed transaction left DB residue at '.$stage);
};
$input=static fn(array $overrides=[]):array=>array_replace([
    'client_id'=>1,'route_executor_id'=>null,'responsible_user_id'=>1,'destination_type'=>'warehouse','utilizer_id'=>null,
    'status'=>'searching_executor','planned_start_mode'=>'exact','planned_start_date'=>'2026-09-01','planned_start_date_from'=>null,'planned_start_date_to'=>null,
    'planned_finish_date'=>null,'actual_start_date'=>null,'actual_end_date'=>null,'comment'=>'synthetic parity fixture','cancellation_reason'=>null,
],$overrides);
$session=['user_id'=>1,'role_code'=>'company_owner'];
$addRequest=static function(PDO $p,int $id,string $business):void{$s=$p->prepare('INSERT INTO feo_requests (id,zayavka_id,mass_netto,naim_oo_gruzootpravitel,mno_adres_pogruzki,naim_otkhoda_fkko,nomer_opasnogo_gr) VALUES (?,?,?,?,?,?,?)');$s->execute([$id,$business,'1.2340','Synthetic Sender','Synthetic Address','Synthetic Waste','9']);};
$addFlight=static function(PDO $p,int $id,string $request):void{$s=$p->prepare('INSERT INTO flights (id,zayavki_ids,comment,planned_start_date) VALUES (?,?,?,?)');$s->execute([$id,$request,'Synthetic legacy '.$id,'2026-09-01']);};

try{
    cleanCompanyPoolDatabase($pdo);
    LocalMigrationService::apply($pdo);
    $latest=(string)$pdo->query('SELECT migration FROM schema_migrations ORDER BY id DESC LIMIT 1')->fetchColumn();
    $canonicalMigrations=glob($root.'/database/migrations-local/*.sql');
    sort($canonicalMigrations,SORT_STRING);
    $ok($latest===basename(end($canonicalMigrations)),'full canonical migration chain must reach its current head');
    foreach(['waste_trips','waste_trip_requests','waste_trip_customer_payments','waste_trip_audit_log','waste_trip_namespace_state','feo_requests','clients','users','crews','feo_utilizers']as$table){
        $s=$pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$s->execute([$table]);$ok((int)$s->fetchColumn()===1,'canonical table missing: '.$table);
    }
    $fk=(int)$pdo->query("SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME IN ('waste_trips','waste_trip_requests','waste_trip_customer_payments','waste_trip_audit_log')")->fetchColumn();
    $ok($fk===8,'expected canonical Waste Trips FK set');
    $unique=(int)$pdo->query("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='waste_trips' AND INDEX_NAME='uk_waste_trip_business_id' AND NON_UNIQUE=0")->fetchColumn();$ok($unique===1,'business id unique contract missing');
    $ok((int)$pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES)===0,'native prepares required');

    $pdo->exec("INSERT INTO clients (id,name,inn,status) VALUES (1,'Synthetic Client','7700000000','active')");
    $pdo->exec("INSERT INTO users (id,membership_id,full_name,login,password_hash,role_code,status) VALUES (1,77,'Synthetic Manager','synthetic.manager','x','membership_role','active'),(2,78,'Inactive Manager','inactive.manager','x','membership_role','inactive')");
    $pdo->exec("INSERT INTO feo_utilizers (id,full_name,short_name,address,is_active,sort_order) VALUES (1,'Synthetic Utilizer','SYN-U','Synthetic Address',1,1),(2,'Inactive Utilizer','SYN-X','Synthetic Address',0,2)");
    $pdo->exec("INSERT INTO waste_trip_namespace_state (singleton_id,legacy_high_water_at_cutover,last_issued_business_id,cutover_started_at,cutover_completed_at) VALUES (1,261,261,NOW(),NOW())");
    $pdo->exec("CREATE TABLE flights (id BIGINT UNSIGNED PRIMARY KEY,zayavki_ids TEXT,comment VARCHAR(500),planned_start_date DATE NULL,planned_start_date_from DATE NULL,planned_start_date_to DATE NULL,actual_start_date DATE NULL,actual_end_date DATE NULL)");
    for($id=246;$id<=270;$id++){$business=(string)(900000+$id);$addRequest($pdo,$id,$business);$addFlight($pdo,$id,$business);}
    $legacy->exec('SET SESSION TRANSACTION READ ONLY');

    $business=WasteTripLegacyPrefillService::createFromLegacyPrefill($legacy,$pdo,246,$input(),['900246'],$session);$ok($business===246,'successful Legacy business ID mismatch');
    $trip=$pdo->query('SELECT * FROM waste_trips WHERE business_id=246')->fetch(PDO::FETCH_ASSOC);$ok(is_array($trip),'successful trip missing');
    $ok((int)$trip['legacy_flight_id']===246&&$trip['source_system']==='legacy_prefill'&&(int)$trip['created_by_membership_id']===77,'legacy identity or creator membership mismatch');
    $tripId=(int)$trip['id'];$ok((int)$pdo->query("SELECT COUNT(*) FROM waste_trip_requests WHERE waste_trip_id={$tripId}")->fetchColumn()===1,'successful request link missing');$ok((int)$pdo->query("SELECT COUNT(*) FROM waste_trip_audit_log WHERE waste_trip_id={$tripId} AND action='create'")->fetchColumn()===1,'successful audit missing');

    $businessError(fn()=>WasteTripLegacyPrefillService::createFromLegacyPrefill($legacy,$pdo,246,$input(),['900246'],$session),'уже перенесён');
    $pdo->exec("INSERT INTO waste_trips (business_id,client_id,responsible_user_id,destination_type,status,planned_start_mode,planned_start_date,created_by_user_id,created_by_membership_id,created_by_role,updated_by_user_id,updated_by_role) VALUES (247,1,1,'warehouse','searching_executor','exact','2026-09-01',1,77,'company_owner',1,'company_owner')");
    $businessError(fn()=>WasteTripLegacyPrefillService::createFromLegacyPrefill($legacy,$pdo,247,$input(),['900247'],$session),'уже занят другим рейсом');
    $businessError(fn()=>WasteTripLegacyPrefillService::createFromLegacyPrefill($legacy,$pdo,248,$input(),['99999999'],$session),'отсутствуют в canonical FEO');
    $businessError(fn()=>WasteTripLegacyPrefillService::createFromLegacyPrefill($legacy,$pdo,249,$input(),['900246'],$session),'уже включены в активный рейс');
    $businessError(fn()=>WasteTripLegacyPrefillService::createFromLegacyPrefill($legacy,$pdo,250,$input(['responsible_user_id'=>2]),['900250'],$session),'Ответственный сотрудник недоступен или неактивен');
    $businessError(fn()=>WasteTripLegacyPrefillService::createFromLegacyPrefill($legacy,$pdo,251,$input(['client_id'=>999]),['900251'],$session),'Заказчик недоступен или неактивен');
    $businessError(fn()=>WasteTripLegacyPrefillService::createFromLegacyPrefill($legacy,$pdo,252,$input(['route_executor_id'=>999]),['900252'],$session),'Исполнитель рейса недоступен или неактивен');
    $businessError(fn()=>WasteTripLegacyPrefillService::createFromLegacyPrefill($legacy,$pdo,253,$input(['destination_type'=>'utilizer','utilizer_id'=>2]),['900253'],$session),'Утилизатор недоступен или неактивен');

    $before=$counts($pdo);RegulatedDataScopeService::$technicalFailure=(function(){ $e=new PDOException('synthetic scope failure');$e->errorInfo=['HY000',1105,'synthetic'];return $e;})();
    $technical(fn()=>WasteTripLegacyPrefillService::createFromLegacyPrefill($legacy,$pdo,254,$input(),['900254'],$session),'access_scope',$before);RegulatedDataScopeService::$technicalFailure=null;
    $before=$counts($pdo);LinearRouteService::$technicalFailure=true;$technical(fn()=>WasteTripLegacyPrefillService::createFromLegacyPrefill($legacy,$pdo,255,$input(),['900255'],$session),'entity_visibility',$before);LinearRouteService::$technicalFailure=false;
    $before=$counts($pdo);$pdo->failBegin=true;$technical(fn()=>WasteTripLegacyPrefillService::createFromLegacyPrefill($legacy,$pdo,256,$input(),['900256'],$session),'transaction_begin',$before);$pdo->failBegin=false;

    foreach([
        ['id'=>257,'needle'=>'FROM waste_trips WHERE business_id=?','stage'=>'duplicate_lock'],
        ['id'=>258,'needle'=>'FROM feo_requests fr','stage'=>'request_resolution'],
        ['id'=>259,'needle'=>'FROM waste_trip_requests wtr JOIN waste_trips wt','stage'=>'claim_check'],
        ['id'=>260,'needle'=>'INSERT INTO waste_trips','stage'=>'trip_insert'],
        ['id'=>261,'needle'=>'INSERT INTO waste_trip_requests','stage'=>'request_links'],
        ['id'=>262,'needle'=>'INSERT INTO waste_trip_audit_log','stage'=>'audit'],
    ]as$case){$before=$counts($pdo);$pdo->failPrepareContains=$case['needle'];$technical(fn()=>WasteTripLegacyPrefillService::createFromLegacyPrefill($legacy,$pdo,$case['id'],$input(),[(string)(900000+$case['id'])],$session),$case['stage'],$before);$pdo->failPrepareContains=null;}
    $before=$counts($pdo);$pdo->failCommit=true;$technical(fn()=>WasteTripLegacyPrefillService::createFromLegacyPrefill($legacy,$pdo,263,$input(),['900263'],$session),'commit',$before);$pdo->failCommit=false;

    $rows=$incidentRows();$stages=array_values(array_column($rows,'stage'));foreach(['access_scope','entity_visibility','transaction_begin','duplicate_lock','request_resolution','claim_check','trip_insert','request_links','audit','commit']as$stage)$ok(in_array($stage,$stages,true),'missing injected incident stage '.$stage);
    $ok($counts($pdo)['trips']===2,'unexpected trip residue/count after failure matrix');
    echo 'WASTE_TRIP_LEGACY_FULL_SCHEMA=PASS'.PHP_EOL;
    echo 'WASTE_TRIP_LEGACY_FULL_SCHEMA_LATEST='.$latest.PHP_EOL;
    echo 'WASTE_TRIP_LEGACY_FULL_SCHEMA_FK_COUNT='.$fk.PHP_EOL;
    echo 'WASTE_TRIP_LEGACY_FULL_SCHEMA_TECHNICAL_STAGES='.implode(',',$stages).PHP_EOL;
}finally{
    RegulatedDataScopeService::$technicalFailure=null;LinearRouteService::$technicalFailure=false;
    if($pdo->inTransaction())$pdo->rollBack();
    $pdo->failBegin=false;$pdo->failCommit=false;$pdo->failPrepareContains=null;
    if($fixed){cleanCompanyPoolDatabase($pdo);}else{$admin->exec("DROP DATABASE IF EXISTS `{$db}`");}
    @unlink($incidentLog);putenv('PLANEX_WASTE_TRIP_INCIDENT_LOG');putenv('PLANEX_DEPLOYED_SHA');
}
}
