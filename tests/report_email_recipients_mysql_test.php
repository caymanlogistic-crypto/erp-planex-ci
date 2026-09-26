<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/app.php';
require_once BASE_PATH.'/app/Support/company_database.php';
require_once BASE_PATH.'/app/Service/EmployeeMailProfileService.php';
require_once BASE_PATH.'/app/Service/ReportEmailRecipientService.php';

$name=(string)getenv('PLANEX_TEST_FIXED_DB');
if (getenv('APP_ENV')!=='test' || $name==='' || !in_array($name,explode(',',(string)getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST')),true)) throw new RuntimeException('Guarded disposable schema required');
$pdo=new PDO('mysql:host='.getenv('PLANEX_SCHEMA_DB_HOST').';port='.(getenv('PLANEX_SCHEMA_DB_PORT')?:3306).';dbname='.$name.';charset=utf8mb4',getenv('PLANEX_SCHEMA_DB_USER'),getenv('PLANEX_SCHEMA_DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$ok=static function(bool $condition,string $message):void {if(!$condition) throw new RuntimeException($message);};
$fails=static function(callable $action):void {try{$action();}catch(DomainException|InvalidArgumentException){return;}throw new RuntimeException('Expected validation failure');};
cleanCompanyPoolDatabase($pdo);
try {
    $pdo->exec('CREATE TABLE companies(id INT UNSIGNED PRIMARY KEY,name VARCHAR(255),status VARCHAR(20),inn VARCHAR(12)) ENGINE=InnoDB');
    foreach (['013_create_roles_access.sql','016_create_employee_mail_profiles.sql','017_registration_journal_and_report_recipients.sql'] as $migration) $pdo->exec((string)file_get_contents(BASE_PATH.'/database/migrations/'.$migration));
    $pdo->exec("INSERT INTO companies VALUES(1,'A','active','1111111111'),(2,'B','active','2222222222');
      INSERT INTO user_accounts(id,full_name,login,normalized_login,email,password_hash) VALUES
        (1,'Руководитель','owner','owner','owner@example.test','unused'),
        (2,'Карина Гладкиx','karina','karina','karina@example.test','unused'),
        (3,'Другой сотрудник','other','other','other@example.test','unused'),
        (4,'Еншина','enshina','enshina','enshina@example.test','unused');
      INSERT INTO company_roles(id,company_id,name,normalized_name,is_system,system_key) VALUES
        (10,1,'Owner','owner',1,'company_owner'),(11,1,'Worker','worker',0,NULL),
        (20,2,'Owner','owner',1,'company_owner'),(21,2,'Worker','worker',0,NULL);
      INSERT INTO company_memberships(id,account_id,company_id,role_id) VALUES
        (101,1,1,10),(102,2,1,11),(103,4,1,11),(201,1,2,20),(202,3,2,21);
      INSERT INTO employee_mail_legal_entities(id,company_id,code,name,email_domain) VALUES(1,1,'planex','A','example.test');
      INSERT INTO employee_mail_profiles(account_id,company_id,legal_entity_id,email,password_encrypted) VALUES(2,1,1,'karina@company.test','synthetic')");
    $migration=(string)file_get_contents(BASE_PATH.'/database/migrations/017_registration_journal_and_report_recipients.sql');
    $pdo->exec($migration);
    $service=new App\Service\ReportEmailRecipientService($pdo,1);
    $ok($service->ready(),'Recipient tables missing');
    $suggestions=$service->suggest('Карина');
    $ok(count($suggestions)===1 && $suggestions[0]['email']==='karina@company.test','Surname autocomplete should use employee mailbox');
    $ok($service->suggest('Другой')===[],'Autocomplete leaked another company');
    $fails(fn()=>$service->save(2,102,[['account_id'=>2,'email'=>'karina@company.test']],false));
    $fails(fn()=>$service->save(1,101,[['account_id'=>3,'email'=>'other@example.test']],false));
    $service->save(1,101,[['account_id'=>2,'email'=>'karina@company.test'],['email'=>'report@outside.test']],true);
    $saved=$service->read();
    $ok(count($saved['recipients'])===2 && $saved['send_responsible_logist']===true,'Recipient settings did not persist');
    $ok((new App\Service\ReportEmailRecipientService($pdo,2))->read()['recipients']===[],'Recipient list leaked another company');
    $fails(fn()=>$service->save(1,101,[['email'=>'same@test.test'],['email'=>'same@test.test']],false));
    $ok(count($service->read()['recipients'])===2,'Invalid save modified previous list');
    $service->save(1,101,[['email'=>'new@outside.test']],false);
    $ok(count($service->read()['recipients'])===1 && !$service->read()['send_responsible_logist'],'Recipient removal and checkbox update failed');
    $grant=$pdo->query("SELECT effect FROM membership_page_overrides WHERE membership_id=103 AND page_key='company.registration_journal'")->fetchColumn();
    $ok($grant==='allow','enshina journal access missing');
    echo "REPORT_EMAIL_RECIPIENTS_MYSQL=PASS\n";
} finally { cleanCompanyPoolDatabase($pdo); }
