<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli' || getenv('APP_ENV')!=='test') exit(2);
require_once dirname(__DIR__,2).'/bootstrap/app.php';
require_once BASE_PATH.'/app/Support/company_database.php';
require_once BASE_PATH.'/app/Service/LocalMigrationService.php';
require_once BASE_PATH.'/app/Service/EmployeeMailProfileService.php';
$name=(string)getenv('PLANEX_TEST_FIXED_DB');
if ($name==='' || !in_array($name,explode(',',(string)getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST')),true)) throw new RuntimeException('Guarded disposable schema required');
$pdo=new PDO('mysql:host='.getenv('PLANEX_SCHEMA_DB_HOST').';port='.getenv('PLANEX_SCHEMA_DB_PORT').';dbname='.$name.';charset=utf8mb4',getenv('PLANEX_SCHEMA_DB_USER'),getenv('PLANEX_SCHEMA_DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
cleanCompanyPoolDatabase($pdo);
if (($argv[1]??'')==='clean') {echo "LOCAL_TEST_SLOT_2_TABLES=0\n";exit;}
App\Service\LocalMigrationService::apply($pdo);
$pdo->exec('CREATE TABLE companies(id INT UNSIGNED PRIMARY KEY,name VARCHAR(255),status VARCHAR(20),inn VARCHAR(12)) ENGINE=InnoDB');
$pdo->exec(file_get_contents(BASE_PATH.'/database/migrations/013_create_roles_access.sql'));
$pdo->exec(file_get_contents(BASE_PATH.'/database/migrations/016_create_employee_mail_profiles.sql'));
$pdo->exec("INSERT INTO companies VALUES(1,'ООО Тестовая компания','active','1111111111')");
$pdo->exec("INSERT INTO company_roles(id,company_id,name,normalized_name,is_system,system_key) VALUES(1,1,'Руководитель','owner',1,'company_owner'),(2,1,'Логист (lite)','lite',0,NULL)");
$pdo->exec("INSERT INTO user_accounts(id,full_name,login,normalized_login,password_hash) VALUES(1,'Тестовый Логист','logist','logist','unused'),(2,'Тестовый Помощник','enshina','enshina','unused')");
$pdo->exec('INSERT INTO company_memberships(id,company_id,account_id,role_id) VALUES(11,1,1,1),(79,1,2,2)');
$pdo->exec("INSERT INTO users(id,full_name,login,password_hash,role_code,membership_id) VALUES(1,'Тестовый Логист','logist','unused','logist',11),(2,'Тестовый Помощник','enshina','unused','logist',79)");
$pdo->exec("INSERT INTO employee_mail_settings(company_id) VALUES(1)");
$pdo->exec("INSERT INTO employee_mail_legal_entities(id,company_id,code,name,email_domain) VALUES(1,1,'planex','ПЛАНЭКС','one.test'),(2,1,'mg_log','МГ-ЛОГ','two.test')");
$profiles=new App\Service\EmployeeMailProfileService($pdo,1);
foreach ([1=>'logist',2=>'assistant'] as $id=>$login) {$profiles->save(1,$id,1,['email'=>$login.'@one.test','password'=>'synthetic-mail-password']);$profiles->check(1,$id,1,static fn()=>['imap'=>true,'smtp'=>true]);}
require_once __DIR__.'/trip_document_synthetic_cards.php';
seedDocumentCards($pdo);
echo "DOCUMENT_BROWSER_FIXTURE_READY=YES\n";
