<?php

declare(strict_types=1);

use App\Security\AccessRequestContext;
use App\Security\EffectiveAccessResolver;
use App\Security\PageRegistry;
use App\Security\PdoAccessStateProvider;
use App\Service\AccountAuthenticationService;
use App\Service\CompanyLandingPageService;
use App\Service\CompanyMembershipService;
use App\Service\CompanySwitchService;
use App\Service\LegacySessionIdentityUpgradeService as SessionBridge;

$root = dirname(__DIR__);
foreach ([
    '/app/Security/AccessStateProvider.php','/app/Security/AccessRequestContext.php','/app/Security/MembershipAccessSnapshot.php',
    '/app/Security/EffectiveAccess.php','/app/Security/PageRegistry.php','/app/Security/EffectiveAccessResolver.php','/app/Security/PdoAccessStateProvider.php',
    '/app/Service/IdentityNormalizer.php','/app/Service/AccountAuthenticationService.php','/app/Service/CompanyMembershipService.php',
    '/app/Service/CompanySwitchService.php','/app/Service/CompanyLandingPageService.php','/app/Service/LegacySessionIdentityUpgradeService.php',
] as $file) require_once $root . $file;

$host=getenv('PLANEX_SCHEMA_DB_HOST')?:'127.0.0.1';
$port=(int)(getenv('PLANEX_SCHEMA_DB_PORT')?:3306);
$user=getenv('PLANEX_SCHEMA_DB_USER')?:'planex_ci';
$password=getenv('PLANEX_SCHEMA_DB_PASSWORD')?:'planex_ci';
$dbName=getenv('PLANEX_TEST_FIXED_DB')?:'';
$allow=array_filter(explode(',',getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST')?:''));
if($dbName===''||!in_array($dbName,$allow,true)) throw new RuntimeException('guarded fixed runner DB required');
$pdo=new PDO("mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4",$user,$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach(['membership_data_scope_overrides','membership_page_overrides','role_data_scopes','role_page_permissions','company_memberships','company_access_readiness','company_roles','user_accounts','companies'] as $t) $pdo->exec('DROP TABLE IF EXISTS '.$t);
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
$pdo->exec("CREATE TABLE companies (id INT UNSIGNED NOT NULL PRIMARY KEY,name VARCHAR(255) NOT NULL,status VARCHAR(20) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec((string)file_get_contents($root.'/database/migrations/013_create_roles_access.sql'));
$pdo->exec("INSERT INTO companies(id,name,status) VALUES(10,'Alpha QA','active'),(20,'Beta QA','active')");
$hash=str_repeat('b',64);
$ready=$pdo->prepare("INSERT INTO company_access_readiness(company_id,state,before_sha256,after_sha256,comparison_sha256,verified_at,activated_at) VALUES(?,'ready',?,?,?,NOW(),NOW())");
foreach([10,20] as $cid) $ready->execute([$cid,$hash,$hash,$hash]);
$pdo->exec("INSERT INTO company_roles(id,company_id,name,normalized_name,system_key,is_system) VALUES
 (1,10,'Руководитель','company_owner','company_owner',1),
 (2,20,'QA Switch Role','qa switch role',NULL,0)");
$pdo->exec((string)file_get_contents($root.'/database/migrations/014_add_role_default_page.sql'));
$pdo->exec("UPDATE company_roles SET default_page_key='company.clients' WHERE id=2");
$pdo->exec("INSERT INTO role_page_permissions(role_id,page_key,is_allowed) VALUES
 (2,'company.dashboard',1),(2,'company.clients',1),(2,'company.contractors',0),(2,'company.finance.dashboard',0)");
foreach(['client','contractor','vehicle_unit','driver','linear_trip','waste_trip'] as $entity) {
    $q=$pdo->prepare('INSERT INTO role_data_scopes(role_id,entity_type,scope) VALUES(2,?,?)');
    $q->execute([$entity,$entity==='client'?'all':'none']);
}
$pass='MultiCompany!123';
$q=$pdo->prepare("INSERT INTO user_accounts(id,full_name,login,normalized_login,password_hash,status) VALUES(100,'QA Multi Account','qa-multi','qa-multi',?,'active')");
$q->execute([password_hash($pass,PASSWORD_DEFAULT)]);
$pdo->exec("INSERT INTO company_memberships(id,account_id,company_id,role_id,status) VALUES
 (11,100,10,1,'active'),(12,100,20,2,'active')");
$pdo->exec("INSERT INTO membership_page_overrides(membership_id,page_key,effect) VALUES(12,'company.contractors','allow')");
$pdo->exec("INSERT INTO membership_data_scope_overrides(membership_id,entity_type,scope) VALUES(12,'contractor','own')");

$ok=static function(bool $value,string $message):void{if(!$value)throw new RuntimeException($message);};
$registry=new PageRegistry();
$provider=new PdoAccessStateProvider($pdo);
$resolver=new EffectiveAccessResolver($registry,$provider);
$authService=new AccountAuthenticationService($pdo);
$switchService=new CompanySwitchService($pdo);
$landing=new CompanyLandingPageService($pdo,$registry);
$membershipService=new CompanyMembershipService($pdo);
$sessionBridge=new SessionBridge($pdo,$provider);

$auth=$authService->authenticate('qa-multi',$pass);
$ok($auth!==null && count($auth['memberships'])===2,'one global account did not authenticate with two memberships');
$ok((int)$auth['memberships'][0]['company_id']===10 && (int)$auth['memberships'][1]['company_id']===20,'membership login order is not deterministic A then B');
$session=[];
$authService->establishSession($session,$auth,null);
$ok((int)$session['company_id']===10 && (int)($session['user']['company_id']??0)===10,'login did not start in a coherent company A session');
$accessA=$resolver->resolve(AccessRequestContext::fromSession($session));
$ok($accessA->isActive() && $accessA->isSystemOwner(),'company A owner access missing');
$ok($landing->pathForAccount(100,10)==='/company/dashboard','company A owner landing mismatch');
$ok($accessA->canAccessPage('company.roles_access'),'company A owner-only page unavailable');
$companies=$membershipService->activeCompaniesForAccount(100);
$ok(count($companies)===2 && (int)$companies[0]['id']===10 && (int)$companies[1]['id']===20,'company switch options are not exactly the two active memberships');

$session['role_code']='company_owner'; $session['membership_id']=11; $session['role_id']=1; $session['permissions']=['legacy']; $session['data_scopes']=['legacy'];
$session['user']['role_code']='company_owner'; $session['user']['membership_id']=11; $session['user']['role_id']=1; $session['user']['permissions']=['legacy']; $session['user']['data_scopes']=['legacy'];
$switchService->switch($session,20);
$ok((int)$session['company_id']===20 && (int)($session['user']['company_id']??0)===20 && (int)($session['user']['account_id']??0)===100 && (int)($session['user']['user_id']??0)===100,'switch A->B did not synchronize nested global identity');
$ok(!isset($session['role_code'],$session['membership_id'],$session['role_id'],$session['permissions'],$session['data_scopes'],$session['user']['role_code'],$session['user']['membership_id'],$session['user']['role_id'],$session['user']['permissions'],$session['user']['data_scopes']),'switch A->B did not clear stale authority');
$ok($sessionBridge->upgrade($session)===SessionBridge::CURRENT,'next request rejected coherent A->B switched session');
$accessB=$resolver->resolve(AccessRequestContext::fromSession($session));
$ok($accessB->isActive() && !$accessB->isSystemOwner(),'company B resolved wrong membership role');
$ok($landing->pathForAccount(100,20)==='/company/clients','company B default landing mismatch');
$ok($accessB->canAccessPage('company.dashboard') && $accessB->canAccessPage('company.clients') && $accessB->canAccessPage('company.contractors'),'company B effective page permissions mismatch');
$ok(!$accessB->canAccessPage('company.finance.dashboard') && !$accessB->canAccessPage('company.roles_access'),'company A/owner pages leaked into company B');
$ok($accessB->dataScope('client')==='all' && $accessB->dataScope('contractor')==='own','company B role/data override resolution mismatch');
$ok((int)$pdo->query("SELECT COUNT(*) FROM membership_page_overrides WHERE membership_id=11")->fetchColumn()===0,'company B page override leaked into A membership');
$ok((int)$pdo->query("SELECT COUNT(*) FROM membership_data_scope_overrides WHERE membership_id=11")->fetchColumn()===0,'company B data override leaked into A membership');

$switchService->switch($session,10);
$accessARestored=$resolver->resolve(AccessRequestContext::fromSession($session));
$ok((int)$session['company_id']===10 && (int)($session['user']['company_id']??0)===10 && $accessARestored->isSystemOwner(),'switch B->A did not restore coherent company A membership authority');
$ok($sessionBridge->upgrade($session)===SessionBridge::CURRENT,'next request rejected coherent B->A switched session');
$ok($landing->pathForAccount(100,10)==='/company/dashboard' && $accessARestored->canAccessPage('company.roles_access'),'company A landing/menu state did not restore');
$ok((int)$pdo->query("SELECT COUNT(*) FROM membership_page_overrides WHERE membership_id=12 AND page_key='company.contractors' AND effect='allow'")->fetchColumn()===1,'company B page override was mutated by switch');
$ok((int)$pdo->query("SELECT COUNT(*) FROM membership_data_scope_overrides WHERE membership_id=12 AND entity_type='contractor' AND scope='own'")->fetchColumn()===1,'company B data override was mutated by switch');

echo "MULTI_COMPANY_ACCOUNT_MYSQL=PASS\n";
echo "COMPANY_SWITCH_A_TO_B_MYSQL=PASS\n";
echo "COMPANY_SWITCH_B_TO_A_MYSQL=PASS\n";
echo "MEMBERSHIP_OVERRIDES_ISOLATED_MYSQL=PASS\n";
