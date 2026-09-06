<?php

declare(strict_types=1);
$root=dirname(__DIR__);require_once $root.'/bootstrap/app.php';require_once BASE_PATH.'/app/Support/company_database.php';
$host=getenv('PLANEX_SCHEMA_DB_HOST')?:'127.0.0.1';$port=(int)(getenv('PLANEX_SCHEMA_DB_PORT')?:3306);$user=getenv('PLANEX_SCHEMA_DB_USER')?:'planex_ci';$pass=getenv('PLANEX_SCHEMA_DB_PASSWORD')?:'planex_ci';$db=trim((string)getenv('PLANEX_TEST_FIXED_DB'));$allow=array_values(array_filter(array_map('trim',explode(',',(string)getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST')))));
if($db===''||!in_array($db,$allow,true)||(string)getenv('APP_ENV')!=='test')throw new RuntimeException('Guarded fixed test DB required');
$pdo=new PDO("mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);$ok=static function(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);};
cleanCompanyPoolDatabase($pdo);
try{
$pdo->exec("CREATE TABLE company_roles(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,company_id BIGINT UNSIGNED NOT NULL,name VARCHAR(120) NOT NULL,normalized_name VARCHAR(120) NOT NULL,system_key VARCHAR(64) NULL,is_system TINYINT(1) NOT NULL DEFAULT 0,created_at DATETIME NULL,updated_at DATETIME NULL,UNIQUE KEY uk_company_role_name(company_id,normalized_name)) ENGINE=InnoDB");
$pdo->exec("INSERT INTO company_roles(company_id,name,normalized_name,system_key,is_system) VALUES(25,'Руководитель','руководитель','company_owner',1),(25,'Логист','логист','logist',1),(25,'Кастом','кастом',NULL,0)");
$pdo->exec("CREATE TABLE entity_assignments(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,entity_type ENUM('client','contractor','vehicle_unit','driver') NOT NULL,entity_id BIGINT UNSIGNED NOT NULL,membership_id BIGINT UNSIGNED NOT NULL,assigned_by_membership_id BIGINT UNSIGNED NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,UNIQUE KEY uk_entity_assignment(entity_type,entity_id,membership_id),KEY idx_assignment_membership_entity(membership_id,entity_type,entity_id),KEY idx_assignment_entity(entity_type,entity_id)) ENGINE=InnoDB");
$pdo->exec("INSERT INTO entity_assignments(entity_type,entity_id,membership_id,assigned_by_membership_id) VALUES('contractor',10,101,101),('client',11,102,101)");
$before=$pdo->query("SELECT id,entity_type,entity_id,membership_id,assigned_by_membership_id FROM entity_assignments ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$pdo->exec((string)file_get_contents(BASE_PATH.'/database/migrations/014_add_role_default_page.sql'));
$pdo->exec((string)file_get_contents(BASE_PATH.'/database/migrations-local/132_split_assignment_and_responsibility.sql'));
$after=$pdo->query("SELECT id,entity_type,entity_id,membership_id,assigned_by_membership_id FROM entity_assignments ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);$ok($before===$after,'132 changed assignment core rows');
$ok((int)$pdo->query("SELECT COUNT(*) FROM entity_assignments WHERE assignment_kind='legacy_unclassified'")->fetchColumn()===2,'legacy rows not fail-closed');
$ok((int)$pdo->query("SELECT COUNT(*) FROM entity_responsibilities")->fetchColumn()===0,'132 created responsibility rows');
$ok((string)$pdo->query("SELECT default_page_key FROM company_roles WHERE system_key='company_owner'")->fetchColumn()==='company.dashboard','014 owner landing missing');
$ok($pdo->query("SELECT default_page_key FROM company_roles WHERE normalized_name='кастом'")->fetchColumn()===null,'014 guessed custom landing');
// Exact safe rollback shape used by production script while no new-runtime facts exist.
$pdo->exec('DROP TABLE entity_responsibilities');$pdo->exec('ALTER TABLE entity_assignments DROP INDEX idx_assignment_kind');$pdo->exec('ALTER TABLE entity_assignments DROP INDEX uk_entity_assignment_kind');$pdo->exec('ALTER TABLE entity_assignments DROP COLUMN assignment_kind');$pdo->exec('ALTER TABLE entity_assignments ADD UNIQUE KEY uk_entity_assignment(entity_type,entity_id,membership_id)');
$pdo->exec('ALTER TABLE company_roles DROP INDEX idx_company_roles_default_page');$pdo->exec('ALTER TABLE company_roles DROP COLUMN default_page_key');
$rolled=$pdo->query("SELECT id,entity_type,entity_id,membership_id,assigned_by_membership_id FROM entity_assignments ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);$ok($rolled===$before,'rollback changed assignment core rows');
$idx=(int)$pdo->query("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='entity_assignments' AND INDEX_NAME='uk_entity_assignment'")->fetchColumn();$ok($idx>0,'rollback old unique missing');
echo "ROLES_ACCESS_132_APPLY_ROLLBACK_MYSQL=PASS\n";echo "ROLES_ACCESS_132_PREDEPLOY_COMPATIBILITY=PASS\n";
}finally{cleanCompanyPoolDatabase($pdo);}
