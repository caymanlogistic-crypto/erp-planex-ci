<?php

declare(strict_types=1);

use App\Security\AccessRequestContext;
use App\Security\AccessRuntime;
use App\Security\EffectiveAccessResolver;
use App\Security\PageRegistry;
use App\Security\PdoAccessStateProvider;
use App\Service\CompanyRoleService;
use App\Service\RouteExecutorService;

$root=dirname(__DIR__);
require_once $root . '/bootstrap/app.php';
require_once BASE_PATH . '/app/Support/company_database.php';
foreach ([
    '/app/Security/AccessStateProvider.php','/app/Security/AccessRequestContext.php','/app/Security/MembershipAccessSnapshot.php',
    '/app/Security/EffectiveAccess.php','/app/Security/AccessRuntime.php','/app/Security/PageRegistry.php','/app/Security/EffectiveAccessResolver.php','/app/Security/PdoAccessStateProvider.php',
    '/app/Service/AccessControlService.php','/app/Service/CompanyRoleService.php','/app/Service/LegacyAccessProjectionService.php',
    '/app/Service/EntityResponsibilityService.php','/app/Service/RegulatedDataScopeService.php','/app/Service/RouteExecutorService.php',
] as $file) require_once BASE_PATH.$file;

$host=getenv('PLANEX_SCHEMA_DB_HOST')?:'127.0.0.1';
$port=(int)(getenv('PLANEX_SCHEMA_DB_PORT')?:3306);
$user=getenv('PLANEX_SCHEMA_DB_USER')?:'planex_ci';
$pass=getenv('PLANEX_SCHEMA_DB_PASSWORD')?:'planex_ci';
$dbName=trim((string)getenv('PLANEX_TEST_FIXED_DB'));
$allow=array_filter(array_map('trim',explode(',',(string)getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST'))));
if($dbName===''||!in_array($dbName,$allow,true)||(string)getenv('APP_ENV')!=='test')throw new RuntimeException('Guarded fixed runner DB is required.');
$pdo=new PDO("mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$ok=static function(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);};
$ids=static fn(array $rows):array=>array_map(static fn(array $r):int=>(int)($r['crew_id']??$r['id']??0),$rows);

cleanCompanyPoolDatabase($pdo);
try{
    $pdo->exec("CREATE TABLE companies(id INT UNSIGNED PRIMARY KEY,name VARCHAR(255),status VARCHAR(20)) ENGINE=InnoDB");
    $pdo->exec((string)file_get_contents(BASE_PATH.'/database/migrations/013_create_roles_access.sql'));
    $pdo->exec("INSERT INTO companies VALUES(7,'QA','active'),(8,'Foreign','active')");
    $hash=str_repeat('a',64);
    $ready=$pdo->prepare("INSERT INTO company_access_readiness(company_id,state,before_sha256,after_sha256,comparison_sha256,verified_at,activated_at) VALUES(?,'ready',?,?,?,NOW(),NOW())");
    foreach([7,8] as $cid)$ready->execute([$cid,$hash,$hash,$hash]);
    $pdo->exec("INSERT INTO company_roles(id,company_id,name,normalized_name,system_key,is_system) VALUES(1,7,'Руководитель','owner','company_owner',1),(2,7,'Dynamic Dispatch','dynamic_dispatch',NULL,0),(3,7,'Other','other',NULL,0)");
    $pdo->exec("INSERT INTO role_page_permissions(role_id,page_key,is_allowed) VALUES(2,'company.route_executors',1),(3,'company.route_executors',1)");
    foreach([1,2,3] as $role){foreach(CompanyRoleService::regulatedEntities() as $type){$scope=$role===1?'all':'own';$st=$pdo->prepare('INSERT INTO role_data_scopes(role_id,entity_type,scope) VALUES(?,?,?)');$st->execute([$role,$type,$scope]);}}
    foreach([[101,'Owner','owner'],[102,'Dynamic User','dynamic'],[103,'Other User','other']] as [$id,$name,$login]){
        $st=$pdo->prepare("INSERT INTO user_accounts(id,full_name,login,normalized_login,password_hash,status) VALUES(?,?,?,?,?,'active')");
        $st->execute([$id,$name,$login,$login,password_hash('QaPass'.$id,PASSWORD_DEFAULT)]);
    }
    $pdo->exec("INSERT INTO company_memberships(id,account_id,company_id,role_id,status) VALUES(11,101,7,1,'active'),(12,102,7,2,'active'),(13,103,7,3,'active')");

    $pdo->exec("CREATE TABLE users(id INT PRIMARY KEY,membership_id BIGINT NULL,status VARCHAR(20) DEFAULT 'active',deleted_at DATETIME NULL) ENGINE=InnoDB");
    $pdo->exec("INSERT INTO users(id,membership_id) VALUES(201,12),(202,13)");
    $pdo->exec("CREATE TABLE contractors(id BIGINT PRIMARY KEY,status VARCHAR(20),deleted_at DATETIME NULL,created_by_user_id INT NULL,created_by_membership_id BIGINT NULL) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE drivers(id BIGINT PRIMARY KEY,status VARCHAR(20),deleted_at DATETIME NULL,created_by_user_id INT NULL,created_by_membership_id BIGINT NULL) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE vehicle_units(id BIGINT PRIMARY KEY,status VARCHAR(20),deleted_at DATETIME NULL,created_by_user_id INT NULL,created_by_membership_id BIGINT NULL) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE vehicle_sets(id BIGINT PRIMARY KEY,status VARCHAR(20),deleted_at DATETIME NULL,primary_vehicle_unit_id BIGINT NULL,secondary_vehicle_unit_id BIGINT NULL,created_by_user_id INT NULL,created_by_membership_id BIGINT NULL) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE driver_vehicle_blocks(id BIGINT PRIMARY KEY,driver_id BIGINT NOT NULL,vehicle_set_id BIGINT NOT NULL,status VARCHAR(20)) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE crews(id BIGINT PRIMARY KEY,contractor_id BIGINT NOT NULL,driver_vehicle_block_id BIGINT NOT NULL,status VARCHAR(20),created_by_user_id INT NULL) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE crew_drivers(id BIGINT PRIMARY KEY,crew_id BIGINT NOT NULL,driver_id BIGINT NOT NULL,position INT NOT NULL) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE entity_responsibilities(entity_type VARCHAR(32),entity_id BIGINT,membership_id BIGINT,PRIMARY KEY(entity_type,entity_id)) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE entity_assignments(id BIGINT AUTO_INCREMENT PRIMARY KEY,entity_type VARCHAR(32),entity_id BIGINT,membership_id BIGINT,assignment_kind ENUM('creator_auto','manual','legacy_unclassified') NOT NULL) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE legacy_access_projections(legacy_grant_id BIGINT PRIMARY KEY,entity_type VARCHAR(32),entity_id BIGINT,membership_id BIGINT,access_level VARCHAR(16)) ENGINE=InnoDB");

    $pdo->exec("INSERT INTO contractors VALUES(1,'active',NULL,NULL,13),(2,'active',NULL,NULL,13)");
    $pdo->exec("INSERT INTO drivers VALUES(1,'active',NULL,NULL,12),(2,'active',NULL,NULL,13),(3,'active',NULL,NULL,13)");
    $pdo->exec("INSERT INTO vehicle_units VALUES(1,'active',NULL,NULL,12),(2,'active',NULL,NULL,12),(3,'active',NULL,NULL,13)");
    $pdo->exec("INSERT INTO vehicle_sets VALUES(1,'active',NULL,1,2,NULL,12),(2,'active',NULL,3,NULL,NULL,13)");
    $pdo->exec("INSERT INTO driver_vehicle_blocks VALUES(1,1,1,'active'),(2,3,2,'active')");
    $pdo->exec("INSERT INTO crews VALUES(1,1,1,'active',202),(2,2,2,'active',202),(3,2,2,'active',202)");
    $pdo->exec("INSERT INTO crew_drivers VALUES(1,1,1,1),(2,1,2,2),(3,2,3,1),(4,3,3,1)");
    $pdo->exec("INSERT INTO entity_responsibilities VALUES('contractor',1,12),('route_executor',3,12)");
    $pdo->exec("INSERT INTO entity_assignments(entity_type,entity_id,membership_id,assignment_kind) VALUES('driver',2,12,'manual')");

    $registry=new PageRegistry();
    $resolver=new EffectiveAccessResolver($registry,new PdoAccessStateProvider($pdo));
    $rows=[['crew_id'=>1],['crew_id'=>2],['crew_id'=>3]];

    foreach(['contractor','driver','vehicle_unit'] as $type)$pdo->prepare("UPDATE role_data_scopes SET scope='all' WHERE role_id=2 AND entity_type=?")->execute([$type]);
    $access=$resolver->resolve(new AccessRequestContext(102,7));AccessRuntime::setCurrent($access);
    $ok($access->compatibilityRoleCode()==='custom_role','dynamic role must not depend on display name');
    $ok($ids(RouteExecutorService::filterVisibleCrews($pdo,$rows))===[1,2,3],'ALL must expose all crews');
    echo "ROUTE_EXECUTOR_ALL=PASS\n";

    foreach(['contractor','driver','vehicle_unit'] as $type)$pdo->prepare("UPDATE role_data_scopes SET scope='own' WHERE role_id=2 AND entity_type=?")->execute([$type]);
    $access=$resolver->resolve(new AccessRequestContext(102,7));AccessRuntime::setCurrent($access);
    $ok($ids(RouteExecutorService::filterVisibleCrews($pdo,$rows))===[1,3],'OWN must expose component-access crew plus directly responsible crew');
    $pdo->exec("DELETE FROM entity_assignments WHERE entity_type='driver' AND entity_id=2 AND membership_id=12");
    $ok($ids(RouteExecutorService::filterVisibleCrews($pdo,$rows))===[3],'missing one crew driver must remove component-based access but keep direct route responsibility');
    $pdo->exec("INSERT INTO entity_assignments(entity_type,entity_id,membership_id,assignment_kind) VALUES('driver',2,12,'manual')");
    echo "ROUTE_EXECUTOR_ONLY_OWN=PASS\n";
    echo "ROUTE_EXECUTOR_MULTI_DRIVER_SCOPE=PASS\n";

    $pdo->exec("UPDATE role_data_scopes SET scope='none' WHERE role_id=2 AND entity_type='driver'");
    $access=$resolver->resolve(new AccessRequestContext(102,7));AccessRuntime::setCurrent($access);
    $ok($ids(RouteExecutorService::filterVisibleCrews($pdo,$rows))===[],'NONE must override even direct route responsibility and expose no crew');
    echo "ROUTE_EXECUTOR_NONE=PASS\n";

    $pdo->exec("INSERT INTO membership_data_scope_overrides(membership_id,entity_type,scope) VALUES(12,'driver','own')");
    $access=$resolver->resolve(new AccessRequestContext(102,7));AccessRuntime::setCurrent($access);
    $ok($ids(RouteExecutorService::filterVisibleCrews($pdo,$rows))===[1,3],'personal override must restore OWN semantics including route responsibility');
    echo "ROUTE_EXECUTOR_PERSONAL_OVERRIDE=PASS\n";
    echo "ROUTE_EXECUTOR_DYNAMIC_ROLE=PASS\n";

    $owner=$resolver->resolve(new AccessRequestContext(101,7));AccessRuntime::setCurrent($owner);
    $ok($ids(RouteExecutorService::filterVisibleCrews($pdo,$rows))===[1,2,3],'owner must keep full company access');
    echo "ROUTE_EXECUTOR_OWNER_ALL=PASS\n";

    $foreign=$resolver->resolve(new AccessRequestContext(102,8));
    $ok(!$foreign->isActive(),'foreign company must not resolve active authority');
    echo "ROUTE_EXECUTOR_COMPANY_ISOLATION=PASS\n";
} finally {
    AccessRuntime::clear();
    cleanCompanyPoolDatabase($pdo);
}
