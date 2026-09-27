<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/app.php';
require_once BASE_PATH.'/app/Support/company_database.php';
require_once BASE_PATH.'/app/Service/EmployeeMailProfileService.php';
require_once BASE_PATH.'/app/Service/EmployeeMailBootstrapService.php';
$name=(string)getenv('PLANEX_TEST_FIXED_DB');
if (getenv('APP_ENV')!=='test' || !in_array($name,explode(',',(string)getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST')),true) || $name==='') throw new RuntimeException('Guarded test DB required');
$pdo=new PDO('mysql:host='.getenv('PLANEX_SCHEMA_DB_HOST').';port='.(getenv('PLANEX_SCHEMA_DB_PORT')?:3306).';dbname='.$name.';charset=utf8mb4',getenv('PLANEX_SCHEMA_DB_USER'),getenv('PLANEX_SCHEMA_DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$ok=static function(bool $b,string $m):void {if(!$b)throw new RuntimeException($m);};
$fails=static function(callable $fn) use($ok):void {try{$fn();}catch(DomainException|InvalidArgumentException){return;}throw new RuntimeException('Expected closed failure');};
cleanCompanyPoolDatabase($pdo);
$oldKey=getenv('APP_ENCRYPTION_KEY');putenv('APP_ENCRYPTION_KEY=synthetic-mail-test-key');
try {
    $pdo->exec("CREATE TABLE companies(id INT UNSIGNED PRIMARY KEY,name VARCHAR(255),status VARCHAR(20),inn VARCHAR(12)) ENGINE=InnoDB");
    $pdo->exec(file_get_contents(BASE_PATH.'/database/migrations/013_create_roles_access.sql'));
    $pdo->exec(file_get_contents(BASE_PATH.'/database/migrations/016_create_employee_mail_profiles.sql'));
    $pdo->exec(file_get_contents(BASE_PATH.'/database/migrations/016_create_employee_mail_profiles.sql'));
    $pdo->exec(file_get_contents(BASE_PATH.'/database/migrations/019_employee_mail_default_sender.sql'));
    $pdo->exec("INSERT INTO companies VALUES(1,'Company A','active','1111111111'),(2,'Company B','active','2222222222'),(3,'Foreign company','active','3333333333')");
    $pdo->exec('INSERT INTO employee_mail_settings(company_id) VALUES(1)');
    $pdo->exec("INSERT INTO user_accounts(id,full_name,login,normalized_login,email,password_hash) VALUES(1,'Owner','owner','owner','original-owner@example.test','unused'),(2,'Logist','logist','logist','original@example.test','unused'),(3,'Assistant','assistant','assistant','assistant-original@example.test','unused'),(4,'Outsider','outsider','outsider',NULL,'unused')");
    foreach([1,2,3] as $c) {
        $pdo->exec("INSERT INTO company_roles(id,company_id,name,normalized_name,is_system,system_key) VALUES(".($c*10).",$c,'Owner','owner',1,'company_owner'),(".($c*10+1).",$c,'Logist','logist',0,NULL)");
        foreach([1,2,3] as $a) $pdo->exec("INSERT INTO company_memberships(account_id,company_id,role_id) VALUES($a,$c,".($c*10+($a===1?0:1)).")");
    }
    $pdo->exec("INSERT INTO company_memberships(account_id,company_id,role_id) VALUES(4,3,30)");
    $pdo->exec("INSERT INTO employee_mail_legal_entities(id,company_id,code,name,email_domain) VALUES(1,1,'planex','Company A','one.test'),(2,1,'mg_log','Company B','two.test'),(3,3,'planex','Foreign','foreign.test')");
    $s=new App\Service\EmployeeMailProfileService($pdo,1);
    $s->save(1,2,1,['email'=>'logist@one.test','password'=>'synthetic-mail-password']);
    $s->save(1,2,2,['email'=>'logist@two.test','password'=>'']);
    $ok($pdo->query('SELECT email FROM user_accounts WHERE id=2')->fetchColumn()==='original@example.test','Identity email changed');
    $stored=$pdo->query('SELECT password_encrypted FROM employee_mail_profiles WHERE account_id=2 AND company_id=1')->fetchColumn();
    $ok(str_starts_with($stored,'v2:') && !str_contains($stored,'synthetic-mail-password'),'Password not encrypted');
    $ok(cryptoDecrypt($stored)==='synthetic-mail-password','Encrypted password roundtrip');
    $public=$s->listForEmployee(1,2);
    $ok(!str_contains(json_encode($public),'password_encrypted') && !str_contains(json_encode($public),'synthetic-mail-password'),'Secret escaped public projection');
    $ok($s->publicProfile(2,2)['connection_status']==='incomplete','Missing password not incomplete');
    $fails(fn()=>$s->resolve(2,2));
    $fails(fn()=>$s->save(4,2,1,['email'=>'stolen@one.test','version'=>1]));
    $fails(fn()=>$s->save(2,2,1,['email'=>'stolen@one.test','version'=>1]));
    $fails(fn()=>$s->save(1,4,1,['email'=>'foreign@one.test']));
    $fails(fn()=>$s->save(1,2,1,['email'=>'logist@one.test','version'=>0]));
    $fails(fn()=>$s->save(1,3,1,['email'=>'logist@one.test','password'=>'synthetic']));
    $fails(fn()=>$s->save(1,2,2,['email'=>'logist@two.test','password'=>'ХХХХХХ','version'=>1]));
    $fails(fn()=>$s->listForEmployee(4,2));
    $fails(fn()=>$s->save(1,2,3,['email'=>'foreign@foreign.test']));
    $fails(fn()=>$s->save(1,2,1,['email'=>'wrong@two.test','version'=>1]));
    $p=$s->save(1,2,1,['email'=>'logist@one.test','password'=>'','version'=>1]);
    $ok($s->credentials(2,1)['password']==='synthetic-mail-password','Blank password destroyed credential');
    $s->check(1,2,1,static fn(array $c):array=>['imap'=>true,'smtp'=>true]);
    $ok($s->resolve(2,1)['email']==='logist@one.test','Resolver picked wrong company');
    $ok($s->defaultSender(2)['email']==='logist@one.test','A sole connected mailbox should send implicitly');
    $second=$s->publicProfile(2,2);
    $s->save(1,2,2,['email'=>'logist@two.test','password'=>'synthetic-two','version'=>$second['version']]);
    $s->check(1,2,2,static fn(array $c):array=>['imap'=>true,'smtp'=>true]);
    $fails(fn()=>$s->defaultSender(2));
    $one=$s->publicProfile(2,1);$two=$s->publicProfile(2,2);
    $s->saveBatch(1,2,[
        ['legal_entity_id'=>1,'email'=>'logist@one.test','version'=>$one['version'],'is_default'=>false],
        ['legal_entity_id'=>2,'email'=>'logist@two.test','version'=>$two['version'],'is_default'=>true],
    ]);
    $ok($s->defaultSender(2)['email']==='logist@two.test','Two-mailbox sender must use the actor default');
    $fails(fn()=>$s->saveBatch(1,2,[
        ['legal_entity_id'=>1,'email'=>'logist@one.test','version'=>$s->publicProfile(2,1)['version'],'is_default'=>true],
        ['legal_entity_id'=>2,'email'=>'logist@two.test','version'=>$s->publicProfile(2,2)['version'],'is_default'=>true],
    ]));
    $ok($s->defaultSender(2)['email']==='logist@two.test','Invalid second default changed the sender');
    $s->save(1,3,1,['email'=>'assistant@one.test','password'=>'synthetic-assistant']);
    $s->check(1,3,1,static fn(array $c):array=>['imap'=>true,'smtp'=>true]);
    $ok($s->defaultSender(3)['email']==='assistant@one.test','Actor sender leaked another employee mailbox');
    $envelope=$s->resolveEnvelope(2,1,3,[3]);
    $ok($envelope['cc']===['assistant@one.test'] && $envelope['employees']===['assistant@one.test'],'Company recipient resolution failed');
    $fails(fn()=>$s->resolveEnvelope(2,2,3));
    $r=$s->check(1,2,1,static fn(array $c):array=>['imap'=>true,'smtp'=>false]);
    $ok($r['profile']['connection_status']==='failed','Partial connection incorrectly succeeded');
    $fails(fn()=>$s->resolve(2,1));
    $p=$s->publicProfile(2,1);
    $s->save(1,2,1,['email'=>'new@one.test','version'=>$p['version']]);
    $ok(!$s->publicProfile(2,1)['has_password'],'Email change reused old password');
    $pdo->exec("UPDATE company_memberships SET status='blocked' WHERE account_id=3 AND company_id=1");
    $fails(fn()=>$s->resolve(3,1));
    // Stored settings drive credentials, and settings changes invalidate old/racing checks.
    $pdo->exec("UPDATE company_memberships SET status='active' WHERE account_id=3 AND company_id=1");
    $settingsService=new App\Service\EmployeeMailSettingsService($pdo,1);
    $fails(fn()=>$settingsService->save(2,App\Service\EmployeeMailSettingsService::DEFAULTS));
    foreach (['imap_host'=>'127.0.0.1','smtp_port'=>0,'encryption'=>'NONE'] as $key=>$value) {
        $fails(fn()=>$settingsService->save(1,array_replace(App\Service\EmployeeMailSettingsService::DEFAULTS,[$key=>$value])));
    }
    $savedSettings=$settingsService->save(1,array_replace(App\Service\EmployeeMailSettingsService::DEFAULTS,['smtp_host'=>'smtp.example.com','smtp_port'=>1465]));
    $ok($s->credentials(3,1)['settings']===$savedSettings,'Transport did not receive stored settings');
    $ok($s->publicProfile(3,1)['connection_status']==='unverified','Settings retained connected state');
    $fails(fn()=>$s->check(1,3,1,static function(array $credentials) use($settingsService):array {
        $settingsService->save(1,App\Service\EmployeeMailSettingsService::DEFAULTS);
        return ['imap'=>true,'smtp'=>true];
    }));
    // General employee save and both profiles share a rollback boundary.
    require_once BASE_PATH.'/app/Service/IdentityNormalizer.php';
    require_once BASE_PATH.'/app/Service/CompanyRoleService.php';
    require_once BASE_PATH.'/app/Service/CompanyMembershipService.php';
    $members=new App\Service\CompanyMembershipService($pdo);
    $pdo->beginTransaction();
    try {
        $created=$members->add(1,11,'New employee','new-mail-user','synthetic-account-password','identity@example.test');
        $s->saveBatch(1,$created['account_id'],[
            ['legal_entity_id'=>1,'email'=>'new-user@one.test','password'=>'synthetic-one'],
            ['legal_entity_id'=>2,'email'=>'wrong@one.test','password'=>'synthetic-two'],
        ]);
        throw new RuntimeException('Invalid second mailbox accepted');
    } catch (InvalidArgumentException) { $pdo->rollBack(); }
    $ok((int)$pdo->query("SELECT COUNT(*) FROM user_accounts WHERE login='new-mail-user'")->fetchColumn()===0,'Failed mail left a partial employee');
    $pdo->beginTransaction();
    $created=$members->add(1,11,'New employee','new-mail-user','synthetic-account-password','identity@example.test',null,'blocked');
    $s->saveBatch(1,$created['account_id'],[['legal_entity_id'=>1,'email'=>'new-user@one.test','password'=>'synthetic-one'],['legal_entity_id'=>2,'email'=>'new-user@two.test','password'=>'']]);
    $pdo->commit();
    $ok(count($s->listForEmployee(1,$created['account_id']))===2,'New/blocked employee mail missing');
    $q=$pdo->prepare('SELECT email FROM user_accounts WHERE id=?');$q->execute([$created['account_id']]);
    $ok($q->fetchColumn()==='identity@example.test','Main email overwritten by work mail');
    $members->remove(1,$created['membership_id']);
    $ok((int)$pdo->query('SELECT COUNT(*) FROM employee_mail_profiles WHERE account_id='.(int)$created['account_id'])->fetchColumn()===0,'Removing membership left mail credentials');
    $ok((int)$pdo->query('SELECT COUNT(*) FROM user_accounts WHERE id='.(int)$created['account_id'])->fetchColumn()===1,'Removing membership removed identity');
    $pdo->exec('DELETE FROM user_accounts WHERE id='.(int)$created['account_id']);
    // Setup matches existing records by INN and account identity, without creating users.
    $pdo->exec('DELETE FROM employee_mail_profiles');$pdo->exec('DELETE FROM employee_mail_legal_entities');
    $pdo->exec("UPDATE companies SET inn='5406854242' WHERE id=1");
    $pdo->exec("UPDATE user_accounts SET login='gladskih',normalized_login='gladskih',full_name='Гладских Карина' WHERE id=2");
    $pdo->exec("UPDATE user_accounts SET login='enshina',normalized_login='enshina' WHERE id=3");
    $pdo->exec("UPDATE company_memberships SET status='active' WHERE account_id=3 AND company_id=1");
    $pdo->exec("INSERT INTO user_accounts(id,full_name,login,normalized_login,password_hash) VALUES(5,'Sidorov','sidorov','sidorov','unused')");
    $pdo->exec("INSERT INTO company_memberships(account_id,company_id,role_id) VALUES(5,1,11)");
    $bootstrap=new App\Service\EmployeeMailBootstrapService($pdo,1);
    $before=$bootstrap->mapping();$passwords=[];
    foreach(['gladskih','enshina','sidorov'] as $login)foreach(['plan-ex.ru','mg-log.ru'] as $domain)if($login.'@'.$domain!=='gladskih@mg-log.ru')$passwords[$login.'@'.$domain]='synthetic-setup-only';
    $seeded=$bootstrap->seed($passwords);
    $ok(count($seeded)===6 && $bootstrap->mapping()===$before,'Bootstrap changed existing identity or omitted profiles');
    $ok((int)$pdo->query("SELECT COUNT(*) FROM employee_mail_profiles WHERE connection_status='incomplete' AND email='gladskih@mg-log.ru'")->fetchColumn()===1,'Placeholder was connected');
    $ok((int)$pdo->query("SELECT COUNT(*) FROM employee_mail_profiles WHERE password_encrypted<>''")->fetchColumn()===5,'Bootstrap secret count');
    $bootstrap->seed($passwords);
    $ok((int)$pdo->query('SELECT COUNT(*) FROM employee_mail_profiles')->fetchColumn()===6,'Bootstrap created duplicates');
    echo "EMPLOYEE_MAIL_PROFILES_MYSQL=PASS\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    cleanCompanyPoolDatabase($pdo);
    putenv($oldKey===false?'APP_ENCRYPTION_KEY':'APP_ENCRYPTION_KEY='.$oldKey);
}
