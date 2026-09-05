<?php

declare(strict_types=1);

use App\Http\Router;
use App\Security\AccessRequestContext;
use App\Security\EffectiveAccessResolver;
use App\Security\PageRegistry;
use App\Security\PdoAccessStateProvider;
use App\Service\AccountAuthenticationService;
use App\Service\LegacySessionIdentityUpgradeService as Bridge;

$root=dirname(__DIR__);
foreach ([
    '/app/Security/AccessStateProvider.php','/app/Security/AccessRequestContext.php','/app/Security/MembershipAccessSnapshot.php',
    '/app/Security/EffectiveAccess.php','/app/Security/AccessRuntime.php','/app/Security/PageRegistry.php','/app/Security/EffectiveAccessResolver.php',
    '/app/Security/PdoAccessStateProvider.php','/app/Http/Router.php','/app/Service/IdentityNormalizer.php','/app/Service/AccountAuthenticationService.php',
    '/app/Service/LegacySessionIdentityUpgradeService.php',
] as $f) require_once $root.$f;

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
$pdo->exec("INSERT INTO companies(id,name,status) VALUES(7,'QA','active')");
$hash=str_repeat('a',64);
$st=$pdo->prepare("INSERT INTO company_access_readiness(company_id,state,before_sha256,after_sha256,comparison_sha256,verified_at,activated_at) VALUES(7,'ready',?,?,?,NOW(),NOW())");$st->execute([$hash,$hash,$hash]);
$pdo->exec("INSERT INTO company_roles(id,company_id,name,normalized_name,system_key,is_system) VALUES
 (1,7,'Руководитель','company_owner','company_owner',1),
 (2,7,'Логист+','senior_logist',NULL,0),
 (3,7,'Логист','logist',NULL,0)");
foreach([[101,'Owner','owner'],[102,'Senior','senior'],[103,'Logist','logist']] as [$id,$name,$login]) {
    $q=$pdo->prepare("INSERT INTO user_accounts(id,full_name,login,normalized_login,password_hash,status) VALUES(?,?,?,?,?,'active')");
    $q->execute([$id,$name,$login,$login,password_hash('Pass!'.$id,PASSWORD_DEFAULT)]);
}
$pdo->exec("INSERT INTO company_memberships(id,account_id,company_id,role_id,status,legacy_source,legacy_source_id) VALUES
 (11,101,7,1,'active','central_company_user',501),
 (12,102,7,2,'active','tenant_user',601),
 (13,103,7,3,'active','tenant_user',602)");
$pdo->exec("INSERT INTO role_page_permissions(role_id,page_key,is_allowed) VALUES
 (2,'company.dashboard',1),(2,'company.clients',1),(3,'company.dashboard',1),(3,'company.clients',1)");
foreach([1,2,3] as $r) foreach(['client','contractor','vehicle_unit','driver','linear_trip','waste_trip'] as $e) {
    $q=$pdo->prepare("INSERT INTO role_data_scopes(role_id,entity_type,scope) VALUES(?,?,?)");$q->execute([$r,$e,$r===3?'own':'all']);
}
$provider=new PdoAccessStateProvider($pdo);
$registry=new PageRegistry();
$resolver=new EffectiveAccessResolver($registry,$provider);
$bridge=new Bridge($pdo,$provider);
$ok=static function(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);};

// Legacy owner / senior_logist / logist sessions hydrate exact account identity.
foreach([
    ['user_id'=>501,'role_code'=>'company_owner','account'=>101],
    ['user_id'=>601,'role_code'=>'senior_logist','account'=>102],
    ['user_id'=>602,'role_code'=>'logist','account'=>103],
] as $c) {
    $session=['user_id'=>$c['user_id'],'role_code'=>$c['role_code'],'company_id'=>7];
    $ok($bridge->upgrade($session)===Bridge::UPGRADED,'legacy '.$c['role_code'].' upgrade failed');
    $access=$resolver->resolve(AccessRequestContext::fromSession($session));
    $ok($access->isActive() && $access->canAccessPage('company.dashboard'),'legacy '.$c['role_code'].' dashboard denied after upgrade');
}

// Normal new login for all three roles -> current account session -> dashboard + protected page.
$authService=new AccountAuthenticationService($pdo);
foreach([['owner','Pass!101'],['senior','Pass!102'],['logist','Pass!103']] as [$login,$pass]) {
    $auth=$authService->authenticate($login,$pass);
    $ok($auth!==null,'new login failed '.$login);
    $session=[];$authService->establishSession($session,$auth,7);
    $access=$resolver->resolve(AccessRequestContext::fromSession($session));
    $ok($access->isActive(),'new login ACL inactive '.$login);
    $ok($access->canAccessPage('company.dashboard'),'dashboard denied '.$login);
    $ok($access->canAccessPage('company.clients'),'protected clients denied '.$login);
}

// Invalid legacy identity gets no access.
$invalid=['user_id'=>999999,'role_code'=>'logist','company_id'=>7];
$ok($bridge->upgrade($invalid)===Bridge::INVALID,'invalid legacy identity accepted');

// After upgrade, legacy role metadata is irrelevant: current membership role is authority.
$session=['user_id'=>602,'role_code'=>'logist','company_id'=>7];
$ok($bridge->upgrade($session)===Bridge::UPGRADED,'authority test upgrade failed');
$ok($resolver->resolve(AccessRequestContext::fromSession($session))->dataScope('client')==='own','initial current logist scope mismatch');
$pdo->exec('UPDATE company_memberships SET role_id=2 WHERE id=13');
$after=$resolver->resolve(AccessRequestContext::fromSession($session));
$ok($after->dataScope('client')==='all' && $after->canAccessPage('company.clients'),'ACL did not observe current membership role change');

// Router proof for dashboard/protected page using upgraded/current authority.
$router=new Router($registry);
$router->get('/company/dashboard',static fn()=> 'dashboard','company.dashboard');
$router->get('/company/clients',static fn()=> 'clients','company.clients');
$router->enableCompanyAccess($resolver,AccessRequestContext::fromSession($session));
http_response_code(200);$ok($router->dispatch('GET','/company/dashboard')==='dashboard' && http_response_code()===200,'router dashboard 200 failed');
http_response_code(200);$ok($router->dispatch('GET','/company/clients')==='clients' && http_response_code()===200,'router protected page 200 failed');

echo "roles access session/auth mysql E2E: PASS\n";
