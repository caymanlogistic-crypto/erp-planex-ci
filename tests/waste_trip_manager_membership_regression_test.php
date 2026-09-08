<?php
declare(strict_types=1);

use App\Service\UserSyncService;

$root=dirname(__DIR__);
require_once $root.'/app/Service/IdentityNormalizer.php';
require_once $root.'/app/Service/CompanyRoleService.php';
require_once $root.'/app/Service/UserSyncService.php';
require_once $root.'/app/Support/company_database.php';

$fail=static function(string $m):never{fwrite(STDERR,"FAIL: {$m}\n");exit(1);};
$ok=static function(bool $v,string $m)use($fail):void{if(!$v)$fail($m);};
if(!extension_loaded('pdo_mysql')){fwrite(STDERR,"pdo_mysql required\n");exit(2);}
$host=getenv('PLANEX_SCHEMA_DB_HOST')?:'127.0.0.1';$port=(int)(getenv('PLANEX_SCHEMA_DB_PORT')?:3306);$user=getenv('PLANEX_SCHEMA_DB_USER')?:'planex_ci';$pass=getenv('PLANEX_SCHEMA_DB_PASSWORD')?:'planex_ci';
$fixedDb=trim((string)(getenv('PLANEX_TEST_FIXED_DB')?:''));$fixed=$fixedDb!=='';
if($fixed){$allow=array_values(array_filter(array_map('trim',explode(',',(string)(getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST')?:'')))));$ok((string)(getenv('APP_ENV')?:'')==='test','fixed DB requires APP_ENV=test');$ok(in_array($fixedDb,$allow,true),'fixed DB not allowlisted');}
$admin=new PDO("mysql:host={$host};port={$port};charset=utf8mb4",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$db=$fixed?$fixedDb:'planex_waste_manager_'.getmypid();
if($fixed){$pdo=new PDO("mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);cleanCompanyPoolDatabase($pdo);}else{$admin->exec("DROP DATABASE IF EXISTS `{$db}`;CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");$pdo=new PDO("mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);}
try{
$pdo->exec("CREATE TABLE user_accounts(id BIGINT UNSIGNED PRIMARY KEY,full_name VARCHAR(255),login VARCHAR(255),email VARCHAR(255) NULL,phone VARCHAR(64) NULL,password_hash VARCHAR(255),status VARCHAR(20));
CREATE TABLE company_roles(id BIGINT UNSIGNED PRIMARY KEY,company_id BIGINT UNSIGNED NOT NULL,system_key VARCHAR(64) NULL);
CREATE TABLE company_memberships(id BIGINT UNSIGNED PRIMARY KEY,account_id BIGINT UNSIGNED NOT NULL,company_id BIGINT UNSIGNED NOT NULL,role_id BIGINT UNSIGNED NOT NULL,status VARCHAR(20));
CREATE TABLE users(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,membership_id BIGINT UNSIGNED NULL,full_name VARCHAR(255),login VARCHAR(255) NOT NULL,email VARCHAR(255) NULL,phone VARCHAR(64) NULL,password_hash VARCHAR(255) NOT NULL,role_code VARCHAR(64),status VARCHAR(20),created_by_user_id BIGINT NULL,created_by_role VARCHAR(64) NULL,updated_at DATETIME NULL,deleted_at DATETIME NULL,UNIQUE KEY uk_users_login(login),UNIQUE KEY uk_users_membership(membership_id));
INSERT INTO user_accounts VALUES(9001,'Сидоров Михаил','sidorov',NULL,NULL,'hash-sidorov','active'),(9002,'Гладких Карина Артуровна','karina',NULL,NULL,'hash-karina','active'),(9003,'Заблокированный','blocked',NULL,NULL,'hash-blocked','active'),(9004,'Чужая компания','foreign',NULL,NULL,'hash-foreign','active');
INSERT INTO company_roles VALUES(11,7,NULL),(12,7,'company_owner'),(21,8,NULL);
INSERT INTO company_memberships VALUES(77,9001,7,11,'active'),(78,9002,7,12,'active'),(79,9003,7,11,'blocked'),(80,9004,8,21,'active');
INSERT INTO users(id,membership_id,full_name,login,email,phone,password_hash,role_code,status,created_by_user_id,created_by_role,updated_at,deleted_at) VALUES(55,NULL,'Гладких Карина Артуровна','karina',NULL,NULL,'old-hash','logist','active',NULL,NULL,NOW(),NULL);");
$sync=new UserSyncService([]);
$sidorovId=$sync->resolveMembershipTenantUserId($pdo,$pdo,7,77);$ok($sidorovId>0,'missing tenant Sidorov projection not created');
$sidorov=$pdo->query("SELECT membership_id,full_name,login,role_code,status FROM users WHERE id={$sidorovId}")->fetch(PDO::FETCH_ASSOC);$ok((int)$sidorov['membership_id']===77&&$sidorov['full_name']==='Сидоров Михаил'&&$sidorov['role_code']==='membership_role'&&$sidorov['status']==='active','Sidorov projection mismatch');
$again=$sync->resolveMembershipTenantUserId($pdo,$pdo,7,77);$ok($again===$sidorovId,'membership projection must be idempotent');
$karinaId=$sync->resolveMembershipTenantUserId($pdo,$pdo,7,78);$ok($karinaId===55,'existing tenant user by login must be adopted rather than duplicated');
$karina=$pdo->query('SELECT membership_id,role_code,password_hash FROM users WHERE id=55')->fetch(PDO::FETCH_ASSOC);$ok((int)$karina['membership_id']===78&&$karina['role_code']==='company_owner'&&$karina['password_hash']==='hash-karina','adopted owner projection not refreshed');
$blocked=false;try{$sync->resolveMembershipTenantUserId($pdo,$pdo,7,79);}catch(RuntimeException $e){$blocked=str_contains($e->getMessage(),'недоступен');}$ok($blocked,'blocked membership must not resolve as manager');
$foreign=false;try{$sync->resolveMembershipTenantUserId($pdo,$pdo,7,80);}catch(RuntimeException $e){$foreign=str_contains($e->getMessage(),'недоступен');}$ok($foreign,'cross-company membership must not resolve as manager');
echo "WASTE_TRIP_MANAGER_MEMBERSHIP_MYSQL=PASS\n";
}finally{if($fixed){cleanCompanyPoolDatabase($pdo);}else{$admin->exec("DROP DATABASE IF EXISTS `{$db}`");}}
