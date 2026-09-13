<?php
declare(strict_types=1);

namespace App\Service {
    final class AccessControlService {
        public static function effectiveMembershipId(): ?int { return 2; }
        public static function canSeeAllCompanyData(array $user): bool { return true; }
    }
    final class RegulatedDataScopeService {
        public static function assertCanCreate(string $entityType): int { return 2; }
        public static function condition(string $entityType,string $idExpression,string $creatorExpression=''): array { return ['sql'=>'1=1','params'=>[]]; }
        public static function canAccess(\PDO $pdo,string $entityType,string $table,int $entityId): bool { return true; }
    }
    final class LinearRouteService {
        public static function isVisibleEntity(\PDO $pdo,string $table,int $id,array $user,string $scope): bool {
            return $table === 'clients' && $id === 1;
        }
        public static function fetchVisibleRouteExecutors(\PDO $pdo,array $user): array { return []; }
    }
    final class FinanceObligationService { public static function syncWasteTrip(\PDO $pdo,int $tripId):int{return 0;} }
}

namespace {
use App\Service\CompanyRoleService;
use App\Service\LocalMigrationService;
use App\Service\UserSyncService;
use App\Service\WasteTripLegacyPrefillService;
use App\Service\WasteTripService;

$root=dirname(__DIR__);
require_once $root.'/bootstrap/app.php';
require_once $root.'/app/Support/company_database.php';
require_once $root.'/app/Service/LocalMigrationService.php';
require_once $root.'/app/Service/IdentityNormalizer.php';
require_once $root.'/app/Service/CompanyRoleService.php';
require_once $root.'/app/Service/UserSyncService.php';
require_once $root.'/app/Service/WasteTripLegacySourceService.php';
require_once $root.'/app/Service/WasteTripIncidentService.php';
require_once $root.'/app/Service/WasteTripService.php';
require_once $root.'/app/Service/WasteTripLegacyPrefillService.php';

$fail=static function(string $m):never{fwrite(STDERR,"FAIL: {$m}\n");exit(1);};
$ok=static function(bool $v,string $m)use($fail):void{if(!$v)$fail($m);};
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
$db=$fixed?$fixedDb:'planex_waste_manager_e2e_'.getmypid();
if($fixed){
    $tenant=new PDO("mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4",$user,$pass,$options);
    cleanCompanyPoolDatabase($tenant);
}else{
    $admin->exec("DROP DATABASE IF EXISTS `{$db}`;CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $tenant=new PDO("mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4",$user,$pass,$options);
}
$central=new PDO("mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4",$user,$pass,$options);
$legacy=new PDO("mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4",$user,$pass,$options);
$incidentLog=sys_get_temp_dir().'/waste_trip_manager_e2e_'.getmypid().'.jsonl';
@unlink($incidentLog);putenv('PLANEX_WASTE_TRIP_INCIDENT_LOG='.$incidentLog);

try{
    LocalMigrationService::apply($tenant);
    $latest=(string)$tenant->query('SELECT migration FROM schema_migrations ORDER BY id DESC LIMIT 1')->fetchColumn();
    $ok($latest===WasteTripService::CARRIER_PAYMENTS_MIGRATION,'tenant must use full canonical schema');

    $central->exec("CREATE TABLE user_accounts(id BIGINT UNSIGNED PRIMARY KEY,full_name VARCHAR(255) NOT NULL,login VARCHAR(100) NOT NULL,email VARCHAR(255) NULL,phone VARCHAR(50) NULL,password_hash VARCHAR(255) NOT NULL,status VARCHAR(20) NOT NULL);
CREATE TABLE company_roles(id BIGINT UNSIGNED PRIMARY KEY,company_id INT UNSIGNED NOT NULL,system_key VARCHAR(50) NULL);
CREATE TABLE company_memberships(id BIGINT UNSIGNED PRIMARY KEY,account_id BIGINT UNSIGNED NOT NULL,company_id INT UNSIGNED NOT NULL,role_id BIGINT UNSIGNED NOT NULL,status VARCHAR(20) NOT NULL);
CREATE TABLE flights(id BIGINT UNSIGNED PRIMARY KEY,zayavki_ids TEXT,comment VARCHAR(500),planned_start_date DATE NULL,planned_start_date_from DATE NULL,planned_start_date_to DATE NULL,actual_start_date DATE NULL,actual_end_date DATE NULL);");

    $central->exec("INSERT INTO user_accounts VALUES
      (1,'Гладких Карина Артуровна','karina',NULL,NULL,'hash-karina','active'),
      (9,'Сидоров Михаил','sidorov',NULL,NULL,'hash-sidorov','active');
      INSERT INTO company_roles VALUES (11,25,NULL),(12,25,'company_owner');
      INSERT INTO company_memberships VALUES (2,1,25,12,'active'),(10,9,25,11,'active');");

    $tenant->exec("INSERT INTO users(id,membership_id,full_name,login,email,phone,password_hash,role_code,status,created_by_user_id,created_by_role)
      VALUES(1,2,'Гладких Карина Артуровна','karina',NULL,NULL,'hash-karina','membership_role','active',NULL,NULL);
      INSERT INTO clients(id,name,inn,status) VALUES(1,'Synthetic Client','7700000000','active');
      INSERT INTO waste_trip_namespace_state(singleton_id,legacy_high_water_at_cutover,last_issued_business_id,cutover_started_at,cutover_completed_at)
      VALUES(1,261,261,NOW(),NOW());");

    $requestIds=[];
    for($i=1;$i<=12;$i++){
        $business=(string)(700000+$i);$requestIds[]=$business;
        $stmt=$tenant->prepare('INSERT INTO feo_requests(id,zayavka_id,mass_netto,naim_oo_gruzootpravitel,mno_adres_pogruzki,naim_otkhoda_fkko,nomer_opasnogo_gr) VALUES(?,?,?,?,?,?,?)');
        $stmt->execute([$i,$business,'0.1000','Synthetic Sender','Synthetic Address','Synthetic Waste','9']);
    }
    $flightStmt=$central->prepare('INSERT INTO flights(id,zayavki_ids,comment,planned_start_date_from,planned_start_date_to,actual_start_date,actual_end_date) VALUES(?,?,?,?,?,?,?)');
    $flightStmt->execute([246,implode(',',$requestIds),'Synthetic Legacy 246','2026-08-31','2026-09-02','2026-08-31','2026-09-04']);

    $_SESSION['account_id']=1;
    $sync=new UserSyncService([]);
    $managerTenantId=$sync->resolveMembershipTenantUserId($central,$tenant,25,10);
    $ok($managerTenantId>1,'missing Sidorov tenant projection was not created');
    $manager=$tenant->query('SELECT id,membership_id,full_name,login,role_code,status,deleted_at FROM users WHERE id='.(int)$managerTenantId)->fetch();
    $ok(is_array($manager),'Sidorov tenant row missing');
    $ok((int)$manager['membership_id']===10&&$manager['full_name']==='Сидоров Михаил'&&$manager['login']==='sidorov'&&$manager['role_code']==='membership_role'&&$manager['status']==='active'&&$manager['deleted_at']===null,'Sidorov projection mismatch');

    $legacy->exec('SET SESSION TRANSACTION READ ONLY');
    $input=[
      'client_id'=>1,'route_executor_id'=>null,'responsible_user_id'=>$managerTenantId,
      'destination_type'=>'warehouse','utilizer_id'=>null,'status'=>'searching_executor',
      'planned_start_mode'=>'range','planned_start_date'=>null,'planned_start_date_from'=>'2026-08-31','planned_start_date_to'=>'2026-09-02',
      'planned_finish_date'=>null,'actual_start_date'=>'2026-08-31','actual_end_date'=>'2026-09-04','comment'=>'Synthetic Legacy 246','cancellation_reason'=>null,
    ];
    $businessId=WasteTripLegacyPrefillService::createFromLegacyPrefill($legacy,$tenant,246,$input,$requestIds,['user_id'=>1,'role_code'=>'company_owner']);
    $ok($businessId===246,'Legacy business id mismatch');
    $trip=$tenant->query('SELECT * FROM waste_trips WHERE business_id=246')->fetch();
    $ok(is_array($trip),'trip missing after manager projection + create');
    $ok((int)$trip['responsible_user_id']===$managerTenantId&&(int)$trip['legacy_flight_id']===246&&$trip['source_system']==='legacy_prefill','trip manager/legacy identity mismatch');
    $tripId=(int)$trip['id'];
    $ok((int)$tenant->query("SELECT COUNT(*) FROM waste_trip_requests WHERE waste_trip_id={$tripId}")->fetchColumn()===12,'request link count mismatch');
    $ok((int)$tenant->query("SELECT COUNT(*) FROM waste_trip_audit_log WHERE waste_trip_id={$tripId} AND action='create'")->fetchColumn()===1,'audit row missing');
    $ok(!is_file($incidentLog)||trim((string)file_get_contents($incidentLog))==='','successful E2E path must not emit technical incident');

    echo "WASTE_TRIP_LEGACY_MANAGER_E2E=PASS\n";
    echo "WASTE_TRIP_LEGACY_MANAGER_E2E_MANAGER_INSERT=PASS\n";
    echo "WASTE_TRIP_LEGACY_MANAGER_E2E_LINKS=12\n";
}finally{
    unset($_SESSION['account_id']);
    @unlink($incidentLog);putenv('PLANEX_WASTE_TRIP_INCIDENT_LOG');
    if($fixed){cleanCompanyPoolDatabase($tenant);}else{$admin->exec("DROP DATABASE IF EXISTS `{$db}`");}
}
}
