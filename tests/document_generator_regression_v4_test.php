<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root=dirname(__DIR__);
$fail=static fn(string $m)=>throw new RuntimeException($m);
$ok=static function(bool $v,string $m)use($fail){if(!$v)$fail($m);};
$eq=static function($a,$b,string $m)use($fail){if($a!==$b)$fail($m.' expected='.var_export($b,true).' actual='.var_export($a,true));};
$has=static function(string $s,string $n,string $m)use($fail){if(!str_contains($s,$n))$fail($m);};
$src=static function(string $p)use($root,$fail){$v=@file_get_contents($root.'/'.$p);if(!is_string($v))$fail('read '.$p);return$v;};
$rm=function(string $p)use(&$rm){if(!file_exists($p))return;if(!is_dir($p)||is_link($p)){@unlink($p);return;}foreach(array_diff(scandir($p)?:[],['.','..'])as$n)$rm($p.DIRECTORY_SEPARATOR.$n);@rmdir($p);};

$wf=$src('app/Service/DocumentGeneratorWorkflowTrait.php');
$data=$src('app/Service/DocumentGeneratorDataTrait.php');
$access=$src('app/Service/DocumentGeneratorAccessTrait.php');
$controller=$src('app/Http/Controllers/Company/DocumentGeneratorController.php');
$view=$src('app/View/pages/company_document_generator.php');
$templateTrait=$src('app/Service/DocumentGeneratorTemplateTrait.php');
$sequence=$src('app/Service/DocumentNumberSequenceService.php');
foreach([['SELECT prefix,next_number FROM document_number_sequences','sequence read'],['FOR UPDATE','sequence lock'],['inTransaction()','transaction guard']]as[$n,$m])$has($sequence,$n,$m);
foreach([["'П-'.\$tripNumber",'trip number'],["number_mode'] = 'trip_based'",'trip snapshot'],['beginTransaction()','generation transaction'],['rollBack()','generation rollback'],["unset(\$params['contract_number']",'copy no reuse']]as[$n,$m])$has($wf,$n,$m);
$ok(!str_contains($access,'contractor_bank_inn'),'generator executor query must not depend on contractor bank INN');
$ok(!str_contains($access,"COLUMN_NAME='bank_inn'"),'runtime schema must not depend on contractor bank INN column');
foreach(["'contract.start_time'","'company.physical_address'","'company.contact_phone'","'company.contact_email'","'executor.bank_inn'"]as$n)$has($data,$n,'optional matrix '.$n);
$ok(!str_contains($data,'не заполнен ИНН банка'),'bank INN blocker removed');
$has($wf,'GoogleSheetsRequestContactService','Google contacts wiring');$ok(!str_contains($access,'kontakt_tel'),'FEO contact columns removed from generator query');
$ok(!str_contains($view,'name="contract_number"'),'manual contract number removed');
$ok(!str_contains($view,'name="executor_bank_inn"'),'manual bank INN removed');
$ok(!str_contains($view,'ИНН банка Арендодателя'),'bank INN removed from preview');
$has($controller,'AccessControlService::effectiveMembershipId()','controller must resolve current stable membership');
$has($controller,'UserSyncService','controller must use canonical tenant projection synchronizer');
$has($controller,'resolveMembershipTenantUserId','controller must self-heal current membership tenant projection');
$has($controller,'generateClientPackage($localPdo, $companyId, $userId, $role, $prepared, $overwriteExisting)','controller passes explicit package overwrite confirmation');
foreach(['document-generator-preview-modal','data-docgen-section="vehicle_lease_crew"','carrier_sublease_crew','data-docgen-section="waybill_driver_assignment"','data-docgen-carrier-trip','data-docgen-client-trip','Сформировать DOCX','Сформировать комплект','Данные исполнителя и заявки ФЭО берутся из выбранного рейса']as$n)$has($view,$n,'UI contract '.$n);
$ok(!str_contains($view,'name="crew_id"'),'manual executor selection removed from generator UI');
$ok(!str_contains($view,'name="request_input"'),'manual FEO request entry removed from generator UI');
$has($templateTrait,'contract_vehicle_lease_crew_v9.docx','built-in v9 seed');
$has($templateTrait,'87584e74a9cfd6bc150fc5ea2c4f7b7045e8ff4b2fba7a5fb80b03ccfc97dff2','built-in v3 SHA');
echo "DOCUMENT_GENERATOR_CANONICAL_WIRING=PASS\n";

require_once $root.'/bootstrap/app.php';
require_once $root.'/app/Support/company_database.php';
require_once $root.'/app/Security/EffectiveAccess.php';
require_once $root.'/app/Security/AccessRuntime.php';
require_once $root.'/app/Service/AccessControlService.php';
require_once $root.'/app/Service/DocumentService.php';
require_once $root.'/app/Service/DocumentTemplateService.php';
require_once $root.'/app/Service/CompanyRoleService.php';
require_once $root.'/app/Service/UserSyncService.php';
require_once $root.'/app/Service/FeoRequestInteractionService.php';
require_once $root.'/app/Service/ProductionCalendarService.php';
require_once $root.'/app/Service/DocumentGeneratorService.php';

$invoiceNumbers=App\Service\ClientPackageInvoiceNumberPolicy::class;
$eq($invoiceNumbers::number(244,'275000.00'),'26-106-1','historical invoice number for trip 244');
$eq($invoiceNumbers::number(246,'180000.00'),'26-105','historical invoice number for trip 246');
$eq($invoiceNumbers::number(255,'404827.00'),'26-106','historical invoice number for trip 255');
$eq($invoiceNumbers::number(256,'123000.00'),date('y').'-256','all other trips use YY-public-trip number');
$eq($invoiceNumbers::tripDocumentNumber(244),'26-244','historical trip document keeps invoice year');
$eq($invoiceNumbers::tripDocumentNumber(246),'26-246','second historical trip document keeps invoice year');
$eq($invoiceNumbers::tripDocumentNumber(255),'26-255','third historical trip document keeps invoice year');
$historicalAmountRejected=false;
try{$invoiceNumbers::number(244,'275001.00');}catch(RuntimeException){$historicalAmountRejected=true;}
$ok($historicalAmountRejected,'historical amount mismatch fails closed');
echo "DOCUMENT_GENERATOR_INVOICE_NUMBER_POLICY=PASS\n";

$p=new App\Service\FeoRequestInteractionService();
$eq($p->extractRequestIds('300001 300002 300001'),['300001','300002'],'parser dedupe');
$eq($p->extractRequestIds("| 300003 | 399999 |\n300004\t399998"),['300003','300004'],'structured parser');
echo "DOCUMENT_GENERATOR_FEO_PARSER=PASS\n";

$canon=[
 'contract_vehicle_lease_crew_v1.docx'=>'0e3e7a3ca4d81face23fa070bcabffacc86636d08affdda91faa3c725d4e8adc',
 'contract_vehicle_lease_crew_v2.docx'=>'7b79a9ac558af1633b1ed2d6414bef1efe0222c67a543d1b4a8e802e6267a1fa',
 'contract_vehicle_lease_crew_v3.docx'=>'87584e74a9cfd6bc150fc5ea2c4f7b7045e8ff4b2fba7a5fb80b03ccfc97dff2',
 'contract_vehicle_lease_crew_v4.docx'=>'11c72fa3206d86d490f71aa88c47fb79cde9e06e0774c12793c89f9948f33e43',
 'contract_vehicle_lease_crew_v5.docx'=>'464074c526ec0d7a387d46c017ad359a94771163cb7a7f076954fc529a673535',
 'contract_vehicle_lease_crew_v9.docx'=>'e83efd9117faf61357fb4dfb9e105c4a989f7bbed9fe062d84355c18dc0df79f',
 'contract_carrier_sublease_crew_v1.docx'=>'074d6a082e1a07b823bf156814842e3cb345f1724bca1656e13e8be0d053719e',
 'contract_carrier_sublease_crew_v2.docx'=>'b3f1068c42b9a9659f4774934eeb8924cf20c58e83e44a9298df98f3e35c0be7',
 'waybill_driver_assignment_v1.docx'=>'5a45e855c6fb47dbc96e6d8b9185d7032b5ca86a9baefbb78fdc6419749f73ab',
 'client_sublease_contract_v1.docx'=>'01ebdc8bb56beb11b20abc787bd6067c7ae3379274e1143cf3e4cf1258f7230c',
 'client_upd_contract_v9.docx'=>'c61123bfbd56bcd2c648f348c4dfebbfc10cb1e5af099b563af5a78e3cb4fc87',
 'client_invoice_v1.docx'=>'10b5f47f51e50d894475f2360dd921f3104ddd0ea048e4c64f27f93ff189e2dd',
 'client_upd_invoice_v4.docx'=>'6b82b0509cd621e591b818bc31931bc3c50885c0d4565b439ba98fb848e9b1b0',
 'client_upd_invoice_v5.docx'=>'4b0017c810e7fa10a6b8a1a9bcade9a2d67e5725f8a465ef7bccecf6e5c39935',
 'client_act_v1.docx'=>'246b3394254d58a22d6ea492f9db56b2efcabef56d43de495766c2c902c6a1f9',
 'client_upd_status2_v1.docx'=>'cfb1669b79400014c2a3244e0c6f7c942213105938dc5253972115fdc88b91c6',
 'client_upd_status2_v8.docx'=>'89666528c3b539faec1c5f7481f424138fb8362972e7edefdfe811e573a0b9f1',
 'client_upd_status2_v9.docx'=>'54b3e16b5300c70f02dc02f173215224e45e1d1978f417fc6e98731da30bf421'
];
$files=[];
foreach($canon as$n=>$sha){$ps=glob($root.'/resources/document-templates/'.$n.'.b64.part*')?:[];sort($ps,SORT_STRING);$ok($ps!==[],'parts '.$n);$b='';foreach($ps as$f){$e=preg_replace('/\s+/','',(string)file_get_contents($f))??'';$d=base64_decode($e,true);$ok(is_string($d)&&$d!=='','decode '.basename($f));$b.=$d;}$eq(hash('sha256',$b),$sha,'template SHA '.$n);$t=tempnam(sys_get_temp_dir(),'dg4_');$x=$t.'.docx';@unlink($t);file_put_contents($x,$b);$files[$n]=$x;}
foreach(['contract_vehicle_lease_crew_v5.docx'=>['Арендодатель','Арендатор','31.41.245.15:10404'],'contract_carrier_sublease_crew_v2.docx'=>['Субарендодатель','Субарендатор','31.41.245.15:10404']] as $name=>[$landlord,$tenant,$endpoint]){
    $validation=App\Service\DocumentTemplateService::validateDocxFile($files[$name]);$ok($validation['ok']??false,'GPS template validates '.$name);
    $z=new ZipArchive();$z->open($files[$name]);$text=App\Service\DocumentTemplateService::visibleText((string)$z->getFromName('word/document.xml'));$z->close();
    foreach([$endpoint,'механизм «чёрного ящика»','GPS-данные сохраняются в памяти устройства','Отключение приложения в указанный период','покрытие маршрута GPS-данными','составляет менее 90%','штраф в размере 20%'] as $phrase)$ok(str_contains($text,$phrase),'GPS contract text '.$phrase.' '.$name);
    $ok(str_contains($text,$landlord.' обязан обеспечить непрерывную работу приложения'),'GPS responsible party '.$name);
    $ok(str_contains($text,$tenant.' вправе удержать'),'GPS deduction party '.$name);
}
$v2=App\Service\DocumentTemplateService::validateDocxFile($files['contract_vehicle_lease_crew_v2.docx']);$ok($v2['ok']??false,'validate contract v2');$ok(!in_array('executor.bank_inn',$v2['placeholders']??[],true),'v2 bank placeholder absent');
foreach(['client_sublease_contract_v1.docx'=>1,'client_invoice_v1.docx'=>0,'client_act_v1.docx'=>0] as $name=>$repeat){$cv=App\Service\DocumentTemplateService::validateDocxFile($files[$name]);$ok($cv['ok']??false,'validate '.$name);$eq((int)($cv['repeat_rows']??-1),$repeat,'repeat '.$name);}$z=new ZipArchive();$z->open($files['contract_vehicle_lease_crew_v2.docx']);$visible=App\Service\DocumentTemplateService::visibleText((string)$z->getFromName('word/document.xml'));$z->close();$ok(preg_match('/ИНН\s+БАНКА/ui',$visible)!==1,'v2 bank label absent');
echo "DOCUMENT_GENERATOR_CANONICAL_TEMPLATE_SHA=PASS\n";
$updTemplate=App\Service\DocumentTemplateService::validateDocxFile($files['client_upd_status2_v1.docx']);$ok($updTemplate['ok']??false,'UPD status 2 template validates');$eq((int)($updTemplate['repeat_rows']??-1),0,'UPD has one service line');

$zip=class_exists(ZipArchive::class);$mysql=in_array('mysql',PDO::getAvailableDrivers(),true);$host=getenv('PLANEX_SCHEMA_DB_HOST')?:'127.0.0.1';$port=(int)(getenv('PLANEX_SCHEMA_DB_PORT')?:3306);$user=getenv('PLANEX_SCHEMA_DB_USER')?:'planex_ci';$pass=getenv('PLANEX_SCHEMA_DB_PASSWORD')?:'planex_ci';$admin=null;
if($mysql){try{$admin=new PDO("mysql:host=$host;port=$port;charset=utf8mb4",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);}catch(Throwable){$mysql=false;}}
$strict=getenv('GITHUB_ACTIONS')==='true'||getenv('PLANEX_DOCGEN_REQUIRE_RUNTIME')==='1';
if(!$zip||!$mysql){foreach($files as$f)@unlink($f);$m=implode(',',array_filter([$zip?null:'ZIPARCHIVE',$mysql?null:'MYSQL']));if($strict)$fail('runtime '.$m);echo "DOCUMENT_GENERATOR_REGRESSION=PASS_WITH_REMOTE_GATE\n";exit;}
$bad=$files['contract_vehicle_lease_crew_v2.docx'].'.bad';copy($files['contract_vehicle_lease_crew_v2.docx'],$bad);$z=new ZipArchive();$z->open($bad);$z->addFromString('../escape.xml','<x/>');$z->close();$ok(!(App\Service\DocumentTemplateService::validateDocxFile($bad)['ok']??true),'traversal');@unlink($bad);echo "DOCUMENT_GENERATOR_DOCX_SECURITY=PASS\n";

$fixedDb=trim((string)getenv('PLANEX_TEST_FIXED_DB'));$fixedAllow=array_filter(array_map('trim',explode(',',(string)getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST'))));$ownsDatabase=$fixedDb==='';
if($ownsDatabase){$db='planex_docgen4_'.getmypid().'_'.bin2hex(random_bytes(3));$qd=companyDbQuoteIdentifier($db);$admin->exec("CREATE DATABASE $qd CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");}
else{if((string)getenv('APP_ENV')!=='test'||!in_array($fixedDb,$fixedAllow,true))$fail('guarded fixed DB required');$db=$fixedDb;$qd=companyDbQuoteIdentifier($db);}
$cid=77001;$store=storage_path("companies/$cid/document-generator");
try{
$pdo=new PDO("mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
if(!$ownsDatabase)cleanCompanyPoolDatabase($pdo);
foreach([
"CREATE TABLE user_accounts(id BIGINT UNSIGNED PRIMARY KEY,full_name VARCHAR(255),login VARCHAR(255),email VARCHAR(255) NULL,phone VARCHAR(64) NULL,password_hash VARCHAR(255),status VARCHAR(20)) ENGINE=InnoDB",
"CREATE TABLE company_roles(id BIGINT UNSIGNED PRIMARY KEY,company_id BIGINT UNSIGNED NOT NULL,system_key VARCHAR(64) NULL) ENGINE=InnoDB",
"CREATE TABLE company_memberships(id BIGINT UNSIGNED PRIMARY KEY,account_id BIGINT UNSIGNED NOT NULL,company_id BIGINT UNSIGNED NOT NULL,role_id BIGINT UNSIGNED NOT NULL,status VARCHAR(20)) ENGINE=InnoDB",
"CREATE TABLE users(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,membership_id BIGINT UNSIGNED NULL UNIQUE,full_name VARCHAR(255),login VARCHAR(255) NOT NULL,email VARCHAR(255) NULL,phone VARCHAR(64) NULL,password_hash VARCHAR(255) NOT NULL,role_code VARCHAR(64),status VARCHAR(32) DEFAULT 'active',created_by_user_id BIGINT NULL,created_by_role VARCHAR(64) NULL,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NULL,deleted_at DATETIME NULL,deleted_by_user_id BIGINT NULL,deleted_by_role VARCHAR(64) NULL,UNIQUE KEY uk_users_login(login)) ENGINE=InnoDB",
"CREATE TABLE contractors(id INT UNSIGNED PRIMARY KEY,name VARCHAR(255),inn VARCHAR(32),kpp VARCHAR(32),ogrn VARCHAR(32),contractor_type VARCHAR(32),legal_address TEXT,physical_address TEXT,bank_account VARCHAR(64),bank_name VARCHAR(255),bank_bik VARCHAR(32),bank_corr_account VARCHAR(64),director_full_name VARCHAR(255),director_position VARCHAR(255),created_by_user_id INT,status VARCHAR(32) DEFAULT 'active',deleted_at DATETIME NULL) ENGINE=InnoDB",
"CREATE TABLE drivers(id INT UNSIGNED PRIMARY KEY,full_name VARCHAR(255),passport_number VARCHAR(64),passport_issued_by VARCHAR(255),passport_issue_date DATE,license_number VARCHAR(64),license_issue_date DATE,created_by_user_id INT,status VARCHAR(32) DEFAULT 'active',deleted_at DATETIME NULL) ENGINE=InnoDB",
"CREATE TABLE vehicle_units(id INT UNSIGNED PRIMARY KEY,unit_type VARCHAR(32),brand VARCHAR(255),model VARCHAR(255),plate_number VARCHAR(64),vin VARCHAR(64),vin_absent TINYINT(1) NOT NULL DEFAULT 0,created_by_user_id INT,status VARCHAR(32) DEFAULT 'active',deleted_at DATETIME NULL) ENGINE=InnoDB",
"CREATE TABLE vehicle_sets(id INT UNSIGNED PRIMARY KEY,primary_vehicle_unit_id INT UNSIGNED,secondary_vehicle_unit_id INT UNSIGNED,set_type VARCHAR(32),created_by_user_id INT,status VARCHAR(32) DEFAULT 'active',deleted_at DATETIME NULL) ENGINE=InnoDB",
"CREATE TABLE driver_vehicle_blocks(id INT UNSIGNED PRIMARY KEY,driver_id INT UNSIGNED,vehicle_set_id INT UNSIGNED,created_by_user_id INT,status VARCHAR(32) DEFAULT 'active',deleted_at DATETIME NULL) ENGINE=InnoDB",
"CREATE TABLE crews(id INT UNSIGNED PRIMARY KEY,contractor_id INT UNSIGNED,driver_vehicle_block_id INT UNSIGNED,created_by_user_id INT,status VARCHAR(32) DEFAULT 'active',deleted_at DATETIME NULL) ENGINE=InnoDB",
"CREATE TABLE crew_drivers(crew_id INT UNSIGNED,driver_id INT UNSIGNED,position INT UNSIGNED NOT NULL DEFAULT 0,PRIMARY KEY(crew_id,driver_id)) ENGINE=InnoDB",
"CREATE TABLE entity_access_grants(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,entity_type VARCHAR(64),entity_id INT UNSIGNED,granted_to_user_id INT UNSIGNED,access_level VARCHAR(16),revoked_at DATETIME NULL) ENGINE=InnoDB",
"CREATE TABLE feo_requests(zayavka_id VARCHAR(64) PRIMARY KEY,mass_netto DECIMAL(18,6),naim_oo_gruzootpravitel VARCHAR(255),mno_adres_pogruzki TEXT,kontakt_tel VARCHAR(64),tel_dopolnitelnyy VARCHAR(64),kontakt_email VARCHAR(255),adres_vygruzki_otkhodov TEXT) ENGINE=InnoDB"
]as$s)$pdo->exec($s);
$applySql=function(string $path)use($pdo){$sql=(string)file_get_contents($path);foreach(preg_split('/;\s*(?:\R|$)/',$sql)?:[]as$s){$s=trim($s);if($s!=='')$pdo->exec($s);}};
$applySql($root.'/database/migrations-local/127_create_document_generator.sql');$applySql($root.'/database/migrations-local/129_document_generator_auto_number_and_bank_inn.sql');$ok(App\Service\DocumentGeneratorService::runtimeSchemaReady($pdo),'runtime schema');
$pdo->exec("INSERT INTO contractors(id,name,inn,kpp,ogrn,contractor_type,legal_address,physical_address,bank_account,bank_name,bank_bik,bank_corr_account,bank_inn,director_full_name,director_position,created_by_user_id,status,deleted_at) VALUES(1,'ООО Исполнитель','7701234567','770101001','1027700000001','legal','Москва','Москва','40702810900000000001','Банк','044525225','30101810400000000225',NULL,'Петров Петр Петрович','Директор',101,'active',NULL),(2,'ООО Чужой','7801234567','780101001','1027800000001','legal','СПб','СПб','40702810900000000002','Банк2','044030653','30101810500000000653',NULL,'Сидоров Сидор Сидорович','Директор',202,'active',NULL)");
$pdo->exec("INSERT INTO drivers VALUES(1,'Иванов Иван Иванович','4510 123456','ОВД','2020-01-02','77 11 123456','2021-02-03',101,'active',NULL),(2,'Чужой Водитель','4510 654321','ОВД2','2020-03-04','78 22 654321','2021-04-05',202,'active',NULL)");
foreach(["INSERT INTO vehicle_units VALUES(1,'truck','КАМАЗ','5490','А123АА77','XTCVIN00000000001',0,101,'active',NULL),(2,'truck','МАЗ','5440','В456ВВ78','XTCVIN00000000002',0,202,'active',NULL)","INSERT INTO vehicle_sets VALUES(1,1,NULL,'single',101,'active',NULL),(2,2,NULL,'single',202,'active',NULL)","INSERT INTO driver_vehicle_blocks VALUES(1,1,1,101,'active',NULL),(2,2,2,202,'active',NULL)","INSERT INTO crews VALUES(1,1,1,101,'active',NULL),(2,2,2,202,'active',NULL)","INSERT INTO crew_drivers VALUES(1,1,1),(2,2,1)"]as$s)$pdo->exec($s);
$pdo->exec("INSERT INTO feo_requests VALUES('300001',1.0,'Отправитель 1','Погрузка 1','111',NULL,NULL,'Воскресенск'),('300002',0.5,'Отправитель 2','Погрузка 2','222',NULL,'two@test','Воскресенск'),('300003',0.25,'Отправитель 3','Погрузка 3',NULL,NULL,'three@test','Воскресенск'),('300004',0.25,'Отправитель 4','Погрузка 4',NULL,NULL,NULL,'Воскресенск'),('300005',0.1,'Отправитель 5','Погрузка 5','555',NULL,NULL,'Коломна')");
$googleFixture="header,c1,c2,c3,c4,c5,c6,c7,c8,contacts\n300001,,,,,,,,,111\n300002,,,,,,,,,\"222, two@test\"\n300003,,,,,,,,,three@test\n300004,,,,,,,,,\n300005,,,,,,,,,555\n";App\Service\GoogleSheetsRequestContactService::setDefaultFetcherForTests(static fn(string $url):string=>$googleFixture);
$company=['id'=>$cid,'name'=>'ООО «ПЛАНЭКС»','inn'=>'5005061234','kpp'=>'500501001','ogrn'=>'1205000000001','legal_address'=>'Воскресенск','physical_address'=>'','bank_name'=>'Банк ПЛАНЭКС','bank_account'=>'40702810000000000001','bank_corr_account'=>'30101810000000000001','bank_bik'=>'044525001','contact_phone'=>'','contact_email'=>'','director_full_name'=>'Гладских Карина Владимировна','director_position'=>'Генеральный директор'];
$pdo->exec("INSERT INTO user_accounts VALUES(900001,'Текущий менеджер','doc.manager',NULL,NULL,'hash-manager','active'),(900002,'Другой пользователь','other.user',NULL,NULL,'hash-other','active'); INSERT INTO company_roles VALUES(51,$cid,NULL),(52,$cid,NULL); INSERT INTO company_memberships VALUES(12,900001,$cid,51,'active'),(13,900002,$cid,52,'active'); INSERT INTO users(id,membership_id,full_name,login,password_hash,role_code,status,deleted_at) VALUES(202,13,'Другой пользователь','other.user','hash-other','membership_role','active',NULL)");
App\Service\DocumentGeneratorService::seedDefaultTemplates($pdo,$cid,1,'company_owner');$ts=App\Service\DocumentGeneratorService::listTemplates($pdo,true);$bt=[];foreach($ts as$t)$bt[$t['document_type']]=$t;$eq($bt['vehicle_lease_crew']['sha256'],$canon['contract_vehicle_lease_crew_v9.docx'],'contract seed v9');$eq($bt['carrier_sublease_crew']['sha256'],$canon['contract_carrier_sublease_crew_v2.docx'],'sublease seed v2');$ph=json_decode((string)$bt['vehicle_lease_crew']['placeholders_json'],true)?:[];$ok(!in_array('executor.bank_inn',$ph,true),'active v9 bank placeholder clean');$ok(in_array('vehicle.vin',$ph,true),'active v9 vehicle VIN placeholder');
$allScopes=['client'=>'all','contractor'=>'all','vehicle_unit'=>'all','driver'=>'all','linear_trip'=>'all','waste_trip'=>'all'];
$managerAccess=App\Security\EffectiveAccess::member(12,$cid,['company.route_executors'=>true,'company.documents.create'=>true,'company.documents.history'=>true],$allScopes,'custom_role');
App\Security\AccessRuntime::setCurrent($managerAccess);
$eq((int)$pdo->query('SELECT COUNT(*) FROM users WHERE membership_id=12')->fetchColumn(),0,'fixture must start without current membership tenant projection');$managerUserId=(new App\Service\UserSyncService([]))->resolveMembershipTenantUserId($pdo,$pdo,$cid,12);$ok($managerUserId>0,'current membership tenant projection must be created automatically');$managerProjection=$pdo->query('SELECT membership_id,full_name,login,role_code,status FROM users WHERE id='.(int)$managerUserId)->fetch(PDO::FETCH_ASSOC);$eq((int)$managerProjection['membership_id'],12,'self-healed projection membership');$eq((string)$managerProjection['login'],'doc.manager','self-healed projection login');$eq((string)$managerProjection['role_code'],'membership_role','self-healed projection role');$eq(App\Service\DocumentGeneratorService::resolveActorTenantUserId($pdo,null,$managerUserId),$managerUserId,'legacy session fallback keeps tenant user id');
$eq(array_map('intval',array_column(App\Service\DocumentGeneratorService::listAccessibleExecutors($pdo,$managerUserId,'custom_role'),'crew_id')),[1,2],'dynamic manager must see same effective route executors as canonical Route Executors ACL');
$ok(App\Service\DocumentGeneratorService::loadAccessibleExecutor($pdo,2,$managerUserId,'custom_role')!==null,'dynamic manager must load an executor allowed by effective scopes');
$templateTypes=array_keys($bt);sort($templateTypes);$eq($templateTypes,['carrier_sublease_crew','client_act','client_invoice','client_sublease_crew','client_upd_contract','client_upd_invoice','client_upd_status2','vehicle_lease_crew','waybill_driver_assignment'],'built-in seed must contain carrier sublease and both client package variants');
echo "DOCUMENT_GENERATOR_DYNAMIC_MANAGER_EXECUTORS=PASS\nDOCUMENT_GENERATOR_MANAGER_ALL_TEMPLATES=PASS\n";
$params=['contract_date'=>'2026-09-03','start_date'=>'2026-09-03','start_time'=>'','finish_date'=>'2026-09-10','price'=>'100000','vat_mode'=>'без НДС','waybill_number'=>'IGNORED','waybill_date'=>''];
$pdo->exec("UPDATE contractors SET name='ИП Шидловский Дмитрий Викторович',inn='123456789012',kpp=NULL,ogrn='123456789012345',contractor_type='individual',director_full_name=NULL,director_position=NULL WHERE id=1");
$ipPrepared=App\Service\DocumentGeneratorService::prepare($pdo,$company,$cid,$managerUserId,'custom_role',(int)$bt['vehicle_lease_crew']['id'],1,"300001\n300002",$params,null,['trip'=>['business_id'=>244]]);$ok($ipPrepared['ok']??false,'IP contractor must not require synthetic director position');$eq($ipPrepared['snapshot']['executor']['director_position'],'Индивидуальный предприниматель','IP director position fallback');$eq($ipPrepared['snapshot']['executor']['director_full_name'],'Шидловский Дмитрий Викторович','IP signatory full-name fallback');$eq($ipPrepared['snapshot']['executor']['signature_name'],'Шидловский Д.В.','IP signature fallback');
$pdo->exec("UPDATE contractors SET bank_bik=NULL WHERE id=1");$missingExecutor=App\Service\DocumentGeneratorService::prepare($pdo,$company,$cid,$managerUserId,'custom_role',(int)$bt['vehicle_lease_crew']['id'],1,"300001\n300002",$params,null,['trip'=>['business_id'=>244]]);$ok(!($missingExecutor['ok']??true),'missing executor bank BIK must fail');$missingText=implode(' | ',array_map('strval',$missingExecutor['errors']??[]));$has($missingText,'У перевозчика «ИП Шидловский Дмитрий Викторович»: не заполнено поле «БИК банка».','clear contractor missing-field error');$ok(!str_contains($missingText,'Не заполнен обязательный реквизит Подрядчика'),'legacy generic contractor error removed');
$pdo->exec("UPDATE contractors SET name='ООО Исполнитель',inn='7701234567',kpp='770101001',ogrn='1027700000001',contractor_type='legal',bank_bik='044525225',director_full_name='Петров Петр Петрович',director_position='Директор' WHERE id=1");echo "DOCUMENT_GENERATOR_CLEAR_ERRORS_IP=PASS\n";
$pdo->exec('UPDATE vehicle_units SET vin=NULL,vin_absent=1 WHERE id=1');
try {
    $absentPreview=App\Service\DocumentGeneratorService::prepare($pdo,$company,$cid,$managerUserId,'custom_role',(int)$bt['vehicle_lease_crew']['id'],1,"300001\n300002",$params,null,['trip'=>['business_id'=>244]]);
    $ok($absentPreview['ok']??false,'flagged absent VIN passes real DB preview');
    $eq($absentPreview['snapshot']['vehicle']['vin_absent'],true,'DB flag persists in document snapshot');
    $eq($absentPreview['values']['vehicle.vin'],'отсутствует','DB flag renders explicit VIN absence');
    $pdo->exec('UPDATE vehicle_units SET vin_absent=0 WHERE id=1');
    $missingVinPreview=App\Service\DocumentGeneratorService::prepare($pdo,$company,$cid,$managerUserId,'custom_role',(int)$bt['vehicle_lease_crew']['id'],1,"300001\n300002",$params,null,['trip'=>['business_id'=>244]]);
    $ok(!($missingVinPreview['ok']??true),'unflagged missing VIN fails real DB preview');
} finally {
    $pdo->exec("UPDATE vehicle_units SET vin='XTCVIN00000000001',vin_absent=0 WHERE id=1");
}
echo "DOCUMENT_GENERATOR_VIN_ABSENT_DB=PASS\n";
$before=(int)$pdo->query("SELECT next_number FROM document_number_sequences WHERE sequence_key='vehicle_lease_crew_contract'")->fetchColumn();
$pr=App\Service\DocumentGeneratorService::prepare($pdo,$company,$cid,$managerUserId,'custom_role',(int)$bt['vehicle_lease_crew']['id'],1,"300001\n300002\n300003\n300004",$params,null,['trip'=>['business_id'=>244]]);$ok($pr['ok']??false,'optional contract prepare');$afterPreview=(int)$pdo->query("SELECT next_number FROM document_number_sequences WHERE sequence_key='vehicle_lease_crew_contract'")->fetchColumn();$eq($before,105,'initial sequence');$eq($afterPreview,105,'trip preview does not consume sequence');$eq($pr['snapshot']['document']['number'],'П-244','preview uses trip number');$eq($pr['values']['contract.number'],'П-244','preview DOCX number');$eq($pr['values']['contract.start_time'],'—','optional time dash');$eq($pr['values']['company.physical_address'],'—','optional physical address dash');$eq($pr['values']['company.contact_phone'],'—','optional phone dash');$eq($pr['values']['company.contact_email'],'—','optional email dash');$eq($pr['request_values'][0]['request.contacts'],'111','contacts phone only');$eq($pr['request_values'][1]['request.contacts'],'222, two@test','contacts phone email');$eq($pr['request_values'][2]['request.contacts'],'three@test','contacts email only');$eq($pr['request_values'][3]['request.contacts'],'—','contacts absent dash');$ok(!array_key_exists('bank_inn',$pr['snapshot']['executor']),'generator snapshot independent of bank INN');$ok(!array_key_exists('waybill_number',$pr['snapshot']['parameters']),'contract ignores waybill fields');
$mx=App\Service\DocumentGeneratorService::prepare($pdo,$company,$cid,$managerUserId,'custom_role',(int)$bt['vehicle_lease_crew']['id'],1,"300001\n300005",$params,null,['trip'=>['business_id'=>244]]);$ok(!($mx['ok']??true),'mixed unload fail closed');echo "DOCUMENT_GENERATOR_DATA_VALIDATION=PASS\n";
$id1=App\Service\DocumentGeneratorService::generate($pdo,$cid,$managerUserId,'custom_role',$pr);$row1=App\Service\DocumentGeneratorService::getGenerated($pdo,$id1);$eq($row1['document_number'],'П-244','first trip number');$eq($row1['original_name'],'Рейс 244 Иванов.docx','contract filename from trip and driver surname');$snap1=json_decode((string)$row1['snapshot_json'],true);$eq($snap1['document']['number'],'П-244','snapshot number');$ok(!array_key_exists('bank_inn',$snap1['executor']),'generated snapshot no bank INN dependency');$path1=App\Service\DocumentService::resolveStoredPath($cid,(string)$row1['stored_path']);$ok(is_string($path1)&&is_file($path1),'contract file');$v=App\Service\DocumentTemplateService::validateDocxFile($path1);$ok($v['ok']??false,'contract docx');$eq($v['placeholders']??[],[],'contract placeholders');
$contractZip = new ZipArchive();$ok($contractZip->open($path1)===true,'open generated contract');$contractText=App\Service\DocumentTemplateService::visibleText((string)$contractZip->getFromName('word/document.xml'));$contractZip->close();$has($contractText,'П-244','contract DOCX contains trip-based number');
$sameTrip=App\Service\DocumentGeneratorService::prepare($pdo,$company,$cid,$managerUserId,'custom_role',(int)$bt['vehicle_lease_crew']['id'],1,"300001\n300002",$params,null,['trip'=>['business_id'=>244]]);$ok($sameTrip['ok']??false,'existing trip contract preview remains valid');$ok($sameTrip['contract_exists']??false,'existing contract is disclosed for confirmation');
$pr2=App\Service\DocumentGeneratorService::prepare($pdo,$company,$cid,$managerUserId,'custom_role',(int)$bt['vehicle_lease_crew']['id'],1,"300001\n300002\n300003\n300004",$params,null,['trip'=>['business_id'=>245]]);$ok($pr2['ok']??false,'second trip prepares');$id2=App\Service\DocumentGeneratorService::generate($pdo,$cid,$managerUserId,'custom_role',$pr2);$row2=App\Service\DocumentGeneratorService::getGenerated($pdo,$id2);$eq($row2['document_number'],'П-245','second trip number');$eq((int)$pdo->query("SELECT next_number FROM document_number_sequences WHERE sequence_key='vehicle_lease_crew_contract'")->fetchColumn(),105,'trip contracts leave legacy sequence unchanged');$seed=App\Service\DocumentGeneratorService::copySeedFromGenerated($row1);$ok(!array_key_exists('contract_number',$seed['params']),'copy no old number');$duplicateBlocked=false;try{$pdo->exec('INSERT INTO generated_documents(document_type,document_number,document_date,template_id,template_version_id,route_executor_id,request_ids_json,total_mass_kg,unload_address,parameters_json,snapshot_json,stored_path,original_name,sha256,created_by_user_id,created_by_role) SELECT document_type,document_number,document_date,template_id,template_version_id,route_executor_id,request_ids_json,total_mass_kg,unload_address,parameters_json,snapshot_json,stored_path,original_name,sha256,created_by_user_id,created_by_role FROM generated_documents WHERE id='.(int)$id1);}catch(PDOException){$duplicateBlocked=true;}$ok($duplicateBlocked,'database unique barrier');echo "DOCUMENT_GENERATOR_AUTO_NUMBERING=PASS\nDOCUMENT_GENERATOR_CONTRACT_DOCX=PASS\n";
$wp=App\Service\DocumentGeneratorService::prepare($pdo,$company,$cid,$managerUserId,'custom_role',(int)$bt['waybill_driver_assignment']['id'],1,"300001\n300002",['waybill_number'=>'ПЛ-001','waybill_date'=>'2026-09-03','contract_date'=>'','start_date'=>'','finish_date'=>'','price'=>'']);$ok($wp['ok']??false,'waybill prepare ignores contract fields');$ok(!array_key_exists('contract_date',$wp['snapshot']['parameters']),'waybill ignores contract parameters');$wid=App\Service\DocumentGeneratorService::generate($pdo,$cid,$managerUserId,'custom_role',$wp);$wr=App\Service\DocumentGeneratorService::getGenerated($pdo,$wid);$eq($wr['document_number'],'ПЛ-001','waybill manual unchanged');$wpath=App\Service\DocumentService::resolveStoredPath($cid,(string)$wr['stored_path']);$ok(is_string($wpath)&&is_file($wpath),'waybill file');echo "DOCUMENT_GENERATOR_WAYBILL_DOCX=PASS\n";
$sublease=App\Service\DocumentGeneratorService::prepare($pdo,$company,$cid,$managerUserId,'custom_role',(int)$bt['carrier_sublease_crew']['id'],1,"300001\n300002",$params,null,['trip'=>['business_id'=>244]]);$ok($sublease['ok']??false,'carrier sublease prepares with lease parameters');$eq($sublease['values']['contract.number'],'П-244','carrier sublease uses trip number');$subleaseId=App\Service\DocumentGeneratorService::generate($pdo,$cid,$managerUserId,'custom_role',$sublease);$subleaseRow=App\Service\DocumentGeneratorService::getGenerated($pdo,$subleaseId);$eq($subleaseRow['document_number'],'П-244','carrier sublease and lease keep separate document types');$eq($subleaseRow['original_name'],'Рейс 244 Иванов Субаренда.docx','carrier sublease download name');$subleasePath=App\Service\DocumentService::resolveStoredPath($cid,(string)$subleaseRow['stored_path']);$ok(is_string($subleasePath)&&is_file($subleasePath),'carrier sublease file');$subleaseValidation=App\Service\DocumentTemplateService::validateDocxFile($subleasePath);$ok($subleaseValidation['ok']??false,'carrier sublease DOCX valid');$eq($subleaseValidation['placeholders']??[],[],'carrier sublease placeholders rendered');$subleaseZip=new ZipArchive();$ok($subleaseZip->open($subleasePath)===true,'open carrier sublease DOCX');$subleaseText=App\Service\DocumentTemplateService::visibleText((string)$subleaseZip->getFromName('word/document.xml'));$subleaseZip->close();$has($subleaseText,'Субарендодатель','carrier sublease role');$has($subleaseText,'Субарендатор','carrier sublease counterparty');$pdo->prepare('DELETE FROM generated_documents WHERE id=?')->execute([$subleaseId]);@unlink($subleasePath);echo "DOCUMENT_GENERATOR_CARRIER_SUBLEASE_DOCX=PASS\n";
$foreignPr=App\Service\DocumentGeneratorService::prepare($pdo,$company,$cid,$managerUserId,'custom_role',(int)$bt['vehicle_lease_crew']['id'],1,"300001\n300002",$params,null,['trip'=>['business_id'=>246]]);$ok($foreignPr['ok']??false,'foreign creator trip prepares');$foreignId=App\Service\DocumentGeneratorService::generate($pdo,$cid,202,'custom_role',$foreignPr);
$managerHistory=App\Service\DocumentGeneratorService::listHistory($pdo,200,$managerUserId,'custom_role');
$managerHistoryIds=array_map('intval',array_column($managerHistory,'id'));
$ok(in_array($id1,$managerHistoryIds,true)&&in_array($id2,$managerHistoryIds,true)&&in_array($wid,$managerHistoryIds,true),'manager history must contain own generated documents');
$ok(!in_array($foreignId,$managerHistoryIds,true),'manager history must exclude documents created by another user');
$ok(App\Service\DocumentGeneratorService::getAccessibleGenerated($pdo,$id1,$managerUserId,'custom_role')!==null,'manager must download own generated document');
$ok(App\Service\DocumentGeneratorService::getAccessibleGenerated($pdo,$foreignId,$managerUserId,'custom_role')===null,'direct download must not bypass creator isolation');
$ownerAccess=App\Security\EffectiveAccess::systemOwner(11,$cid,[]);App\Security\AccessRuntime::setCurrent($ownerAccess);
$ownerHistoryIds=array_map('intval',array_column(App\Service\DocumentGeneratorService::listHistory($pdo,200,101,'company_owner'),'id'));
$ok(in_array($foreignId,$ownerHistoryIds,true),'system owner retains company-wide document history');
$ok(App\Service\DocumentGeneratorService::getAccessibleGenerated($pdo,$foreignId,101,'company_owner')!==null,'system owner retains company-wide generated document access');
$archive=App\Service\DocumentGeneratorService::createHistoryArchive($pdo,$cid,101,'company_owner',[$id1,$foreignId]);
$archiveZip=new ZipArchive();$ok($archiveZip->open($archive['path'])===true,'bulk history ZIP opens');$eq($archiveZip->numFiles,2,'bulk history ZIP contains both documents');
$ok($archiveZip->locateName(App\Service\DocumentGeneratorService::generatedDownloadName((array)App\Service\DocumentGeneratorService::getGenerated($pdo,$id1)))!==false,'bulk ZIP uses download name for first document');
$ok($archiveZip->locateName(App\Service\DocumentGeneratorService::generatedDownloadName((array)App\Service\DocumentGeneratorService::getGenerated($pdo,$foreignId)))!==false,'bulk ZIP uses download name for second document');
$archiveZip->close();unlink($archive['path']);
$archiveDenied=false;App\Security\AccessRuntime::setCurrent($managerAccess);try{App\Service\DocumentGeneratorService::createHistoryArchive($pdo,$cid,$managerUserId,'custom_role',[$id1,$foreignId]);}catch(RuntimeException){$archiveDenied=true;}$ok($archiveDenied,'bulk ZIP rejects one inaccessible document without partial archive');App\Security\AccessRuntime::setCurrent($ownerAccess);
$oldSha=(string)$pdo->query("SELECT sha256 FROM generated_documents WHERE id=$id1")->fetchColumn();$pdo->exec("UPDATE generated_documents SET sha256='incorrect' WHERE id=$id1");
$archiveCorrupt=false;try{App\Service\DocumentGeneratorService::createHistoryArchive($pdo,$cid,101,'company_owner',[$id1,$foreignId]);}catch(RuntimeException $e){$archiveCorrupt=str_contains($e->getMessage(),'Контрольная сумма');}$ok($archiveCorrupt,'bulk ZIP rejects mismatched document checksum');$pdo->prepare('UPDATE generated_documents SET sha256=? WHERE id=?')->execute([$oldSha,$id1]);
App\Security\AccessRuntime::setCurrent($managerAccess);
echo "DOCUMENT_GENERATOR_MANAGER_HISTORY_CREATOR_SCOPE=PASS\nDOCUMENT_GENERATOR_MANAGER_DIRECT_DOWNLOAD_SCOPE=PASS\n";
$beforeSnapshot=(string)$row1['snapshot_json'];$beforeSha=(string)$row1['sha256'];$pdo->exec("UPDATE contractors SET name='ООО После Изменения',bank_inn='7700000000' WHERE id=1");$pdo->exec("UPDATE feo_requests SET mass_netto=2.0 WHERE zayavka_id='300001'");$old=App\Service\DocumentGeneratorService::getGenerated($pdo,$id1);$eq((string)$old['snapshot_json'],$beforeSnapshot,'immutable snapshot');$eq((string)$old['sha256'],$beforeSha,'immutable SHA');echo "DOCUMENT_GENERATOR_HISTORY_SNAPSHOT=PASS\n";
$replacementParams=$params;$replacementParams['contract_date']='2026-09-10';
$replacementContract=App\Service\DocumentGeneratorService::prepare($pdo,$company,$cid,$managerUserId,'custom_role',(int)$bt['vehicle_lease_crew']['id'],1,"300001\n300002",$replacementParams,null,['trip'=>['business_id'=>244]]);
$ok($replacementContract['ok']??false,'existing contract can be checked again');$ok($replacementContract['contract_exists']??false,'existing contract warning is set');
$confirmationRequired=false;try{App\Service\DocumentGeneratorService::generate($pdo,$cid,$managerUserId,'custom_role',$replacementContract);}catch(RuntimeException $e){$confirmationRequired=str_contains($e->getMessage(),'Подтвердите перезапись');}
$ok($confirmationRequired,'overwrite requires explicit confirmation');$ok(App\Service\DocumentGeneratorService::getGenerated($pdo,$id1)!==null && is_file($path1),'unconfirmed overwrite preserves old contract');
$pdo->exec("CREATE TRIGGER docgen_carrier_overwrite_failure BEFORE DELETE ON generated_documents FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='carrier overwrite rollback test'");
$rollback=false;try{App\Service\DocumentGeneratorService::generate($pdo,$cid,$managerUserId,'custom_role',$replacementContract,true);}catch(Throwable){$rollback=true;}finally{$pdo->exec('DROP TRIGGER docgen_carrier_overwrite_failure');}
$ok($rollback,'failed overwrite rolls back');$ok(App\Service\DocumentGeneratorService::getGenerated($pdo,$id1)!==null && is_file($path1),'failed overwrite preserves old contract and file');
$newContractId=App\Service\DocumentGeneratorService::generate($pdo,$cid,$managerUserId,'custom_role',$replacementContract,true);
$newContract=App\Service\DocumentGeneratorService::getGenerated($pdo,$newContractId);$newContractPath=App\Service\DocumentService::resolveStoredPath($cid,(string)$newContract['stored_path']);
$ok($newContractId!==$id1 && is_string($newContractPath) && is_file($newContractPath),'confirmed overwrite creates new contract and file');
$eq((string)$newContract['document_date'],'2026-09-10','confirmed overwrite uses refreshed date');$eq((string)$newContract['original_name'],'Рейс 244 Иванов.docx','contract filename retained');
$eq((int)$pdo->query("SELECT COUNT(*) FROM generated_documents WHERE document_type='vehicle_lease_crew' AND document_number='П-244'")->fetchColumn(),1,'one contract remains for trip number');
$eq(App\Service\DocumentGeneratorService::getGenerated($pdo,$id1),null,'old contract row removed');$ok(!is_file($path1),'old contract file removed after commit');
$path1=$newContractPath;
echo "DOCUMENT_GENERATOR_CARRIER_OVERWRITE=PASS\n";

// Waste Trip is now the sole operational source for executor + FEO composition in the generator.
$pdo->exec("CREATE TABLE clients(id INT UNSIGNED PRIMARY KEY,name VARCHAR(255),inn VARCHAR(32),kpp VARCHAR(32),ogrn VARCHAR(32),legal_address TEXT,physical_address TEXT,contact_phone VARCHAR(64),contact_email VARCHAR(255),director_full_name VARCHAR(255),director_position VARCHAR(255),bank_account VARCHAR(64),bank_name VARCHAR(255),bank_bik VARCHAR(32),bank_corr_account VARCHAR(64),status VARCHAR(32) DEFAULT 'active',deleted_at DATETIME NULL) ENGINE=InnoDB");
$pdo->exec("INSERT INTO clients VALUES(1,'ООО МГ ЛОГИСТИК','6685231046','668501001','1269600001120','Екатеринбург, ул. Куйбышева, д. 159а, пом. 137','Екатеринбург','+7 000 000-00-00','client@example.test','Иванов Иван Иванович','Генеральный директор','40702810900000000144','Банк клиента','044525411','30101810145250000411','active',NULL),(2,'ООО Другой клиент','7700000002','770001002','1027700000002','Москва','','','','Петров Петр Петрович','Директор','40702810900000000222','Другой банк','044525222','30101810000000000222','active',NULL)");
// Match the deployed client schema: contacts moved out of clients in migration 033.
$pdo->exec('ALTER TABLE clients DROP COLUMN contact_phone, DROP COLUMN contact_email');
$clientColumns=$pdo->query('SHOW COLUMNS FROM clients')->fetchAll(PDO::FETCH_COLUMN);
$ok(!in_array('contact_phone',$clientColumns,true)&&!in_array('contact_email',$clientColumns,true),'client schema has no legacy contact columns');
$pdo->exec("ALTER TABLE feo_requests ADD COLUMN id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT UNIQUE FIRST, ADD COLUMN naim_otkhoda_fkko VARCHAR(255) NULL, ADD COLUMN nomer_opasnogo_gr VARCHAR(64) NULL");
$pdo->exec("CREATE TABLE feo_utilizers(id BIGINT UNSIGNED PRIMARY KEY,short_name VARCHAR(255),full_name VARCHAR(255),address TEXT,is_active TINYINT DEFAULT 1) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE waste_trips(id BIGINT UNSIGNED PRIMARY KEY,deleted_at DATETIME NULL,business_id BIGINT UNSIGNED NOT NULL UNIQUE,client_id INT UNSIGNED NOT NULL,route_executor_id INT UNSIGNED NULL,responsible_user_id INT UNSIGNED NOT NULL,destination_type VARCHAR(32) NOT NULL,utilizer_id BIGINT UNSIGNED NULL,status VARCHAR(32) NOT NULL,planned_start_mode VARCHAR(32) NULL,planned_start_date DATE NULL,planned_start_date_from DATE NULL,planned_start_date_to DATE NULL,planned_finish_date DATE NULL,actual_start_date DATE NULL,actual_end_date DATE NULL,comment VARCHAR(500) NULL,cancellation_reason VARCHAR(500) NULL,source_system VARCHAR(50) NULL,legacy_flight_id BIGINT UNSIGNED NULL,lock_version INT UNSIGNED DEFAULT 1,created_by_user_id INT UNSIGNED NULL,created_by_membership_id BIGINT UNSIGNED NULL,created_by_role VARCHAR(64) NULL,updated_by_user_id INT UNSIGNED NULL,updated_by_role VARCHAR(64) NULL,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE waste_trip_requests(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,waste_trip_id BIGINT UNSIGNED NOT NULL,feo_request_id BIGINT UNSIGNED NOT NULL,snapshot_mass_kg DECIMAL(18,3) NOT NULL,position_no INT UNSIGNED NOT NULL DEFAULT 0,removed_at DATETIME NULL) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE waste_trip_audit_log(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,waste_trip_id BIGINT UNSIGNED,action VARCHAR(32),before_json JSON,after_json JSON)");
$pdo->exec("CREATE TABLE waste_trip_customer_payments(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,waste_trip_id BIGINT UNSIGNED NOT NULL,sort_order INT UNSIGNED NOT NULL DEFAULT 1,amount DECIMAL(18,2) NOT NULL,payment_type VARCHAR(64) NULL,payment_method VARCHAR(32) NULL,vat_rate VARCHAR(16) NULL,condition_type VARCHAR(64) NULL,days_count INT NULL,days_kind VARCHAR(32) NULL,specific_due_date DATE NULL,condition_comment VARCHAR(500) NULL) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE waste_trip_carrier_payments(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,waste_trip_id BIGINT UNSIGNED NOT NULL,sort_order INT UNSIGNED NOT NULL DEFAULT 1,amount DECIMAL(18,2) NOT NULL,payment_type VARCHAR(64) NULL,payment_method VARCHAR(32) NULL,vat_rate VARCHAR(16) NULL,condition_type VARCHAR(64) NULL,days_count INT NULL,days_kind VARCHAR(32) NULL,specific_due_date DATE NULL,condition_comment VARCHAR(500) NULL) ENGINE=InnoDB");
$applySql($root.'/database/migrations-local/138_waste_trip_general_payments.sql');
$pdo->exec("INSERT INTO waste_trips(id,business_id,client_id,route_executor_id,responsible_user_id,destination_type,status,planned_start_mode,planned_start_date,planned_finish_date,actual_start_date,actual_end_date,comment,created_by_user_id,created_by_membership_id,created_by_role) VALUES(77,144,1,1,$managerUserId,'warehouse','completed','exact','2026-08-31','2026-09-05','2026-08-31','2026-09-05','Клиентский рейс', $managerUserId,12,'custom_role')");
$pdo->exec("INSERT INTO waste_trip_requests(waste_trip_id,feo_request_id,snapshot_mass_kg,position_no) SELECT 77,id,ROUND(mass_netto*1000,3),CASE zayavka_id WHEN '300001' THEN 1 ELSE 2 END FROM feo_requests WHERE zayavka_id IN ('300001','300002')");
$pdo->exec("INSERT INTO waste_trip_customer_payments(waste_trip_id,sort_order,amount,payment_type,payment_method,vat_rate,condition_type,days_count,days_kind,condition_comment) VALUES(77,1,230000.00,'Без НДС','cashless',NULL,'after_documents',7,'calendar','после получения оригиналов')");
$pdo->exec("INSERT INTO waste_trip_general_payments(waste_trip_id,amount,payment_type,condition_type) VALUES(77,900000,'','after_end')");
$applySql($root.'/database/migrations-local/070_production_calendar.sql');
$applySql($root.'/database/migrations-local/046_create_finance_invoices.sql');
$pdo->exec('ALTER TABLE finance_invoices ADD first_paid_at DATE NULL, ADD fully_paid_at DATE NULL, ADD cancelled_at DATETIME NULL');
$pdo->exec("CREATE TABLE finance_operations(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,status VARCHAR(30) NOT NULL) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE finance_operation_allocations(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,operation_id INT UNSIGNED NOT NULL,invoice_id INT UNSIGNED NULL,obligation_id INT UNSIGNED NULL,linear_route_payment_id INT UNSIGNED NULL,amount DECIMAL(12,2) NOT NULL,allocation_date DATE NULL,method VARCHAR(30) NULL,cancelled_at DATETIME NULL) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE finance_audit_log(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,entity_type VARCHAR(50),entity_id INT UNSIGNED,action VARCHAR(50),old_values JSON NULL,new_values JSON NULL,created_by_user_id INT UNSIGNED,created_by_role VARCHAR(30),created_at DATETIME) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE linear_routes(id INT UNSIGNED PRIMARY KEY,deleted_at DATETIME NULL) ENGINE=InnoDB");
$applySql($root.'/database/migrations-local/071_finance_obligations.sql');
$eq(App\Service\ProductionCalendarService::seedOfficial2026($pdo),'seeded','official production calendar seeded');
$suggested=App\Service\DocumentGeneratorService::suggestClientPackageDates($pdo,['actual_start_date'=>'2026-03-09','actual_end_date'=>'2026-05-11']);
$eq($suggested,['contract_date'=>'2026-03-09','invoice_date'=>'2026-05-11','act_date'=>'2026-05-11'],'client dates use exact trip start and finish even on holidays');
$suggestedWeekend=App\Service\DocumentGeneratorService::suggestClientPackageDates($pdo,['actual_start_date'=>'2026-09-05','actual_end_date'=>'2026-09-06']);
$eq($suggestedWeekend,['contract_date'=>'2026-09-05','invoice_date'=>'2026-09-06','act_date'=>'2026-09-06'],'client dates preserve weekend trip dates');
$suggestedPlanned=App\Service\DocumentGeneratorService::suggestClientPackageDates($pdo,['planned_start_date'=>'2026-10-10','planned_finish_date'=>'2026-10-12']);
$eq($suggestedPlanned,['contract_date'=>'2026-10-10','invoice_date'=>'2026-10-12','act_date'=>'2026-10-12'],'planned dates are used when actual trip dates are absent');

App\Security\AccessRuntime::setCurrent($ownerAccess);
$docTrips=App\Service\DocumentGeneratorService::listDocumentTrips($pdo,true);$trip144=null;foreach($docTrips as$t)if((int)$t['business_id']===144)$trip144=$t;$ok(is_array($trip144),'document trip 144 visible');$eq((string)$trip144['_customer_amount'],'230000.00','customer amount from Waste Trip');
$pdo->exec("UPDATE waste_trips SET deleted_at=NOW() WHERE id=77");
$deletedTrips=App\Service\DocumentGeneratorService::listDocumentTrips($pdo,true);
$ok(!in_array(77,array_map(static fn(array $t):int=>(int)$t['id'],$deletedTrips),true),'deleted trip hidden from document selection');
$deletedPackage=App\Service\DocumentGeneratorService::prepareClientPackage($pdo,$company,$cid,101,'company_owner',1,77,'2026-09-09');
$ok(!($deletedPackage['ok']??true),'deleted trip cannot prepare client package');
$pdo->exec("UPDATE waste_trips SET deleted_at=NULL WHERE id=77");
echo "DOCUMENT_GENERATOR_DELETED_TRIP=PASS\n";
$carrierByTrip=App\Service\DocumentGeneratorService::prepareFromTrip($pdo,$company,$cid,101,'company_owner',(int)$bt['vehicle_lease_crew']['id'],77,$params);$ok($carrierByTrip['ok']??false,'carrier document prepares from selected trip');$eq((int)$carrierByTrip['snapshot']['trip']['business_id'],144,'carrier snapshot trip');$eq($carrierByTrip['values']['contract.number'],'П-144','carrier document uses public trip ID, not database row ID');$eq((int)$carrierByTrip['snapshot']['executor']['crew_id'],1,'carrier executor from trip');$eq($carrierByTrip['request_ids'],['300001','300002'],'carrier FEO list from trip');
$package=App\Service\DocumentGeneratorService::prepareClientPackage($pdo,$company,$cid,101,'company_owner',1,77,'2026-09-09','2026-09-10','2026-09-11');$ok($package['ok']??false,'client package prepare');$eq((string)$package['package_number'],date('y').'-144','package number YY-trip business id');$eq((string)$package['package_amount'],'230000.00','package amount');$eq((int)$package['found_count'],2,'package request count');$has((string)$package['snapshot']['parameters']['payment_terms'],'7 календарных','package payment terms');$has((string)$package['snapshot']['parameters']['payment_terms'],'после получения закрывающих документов','package payment event');
$eq($package['values']['contract.date_plain'],'9 сентября 2026','contract date in template values');
$eq($package['values']['invoice.date'],'10 сентября 2026','invoice date in template values');
$eq($package['values']['act.date'],'11 сентября 2026','act date in template values');
$invalidAct=App\Service\DocumentGeneratorService::prepareClientPackage($pdo,$company,$cid,101,'company_owner',1,77,'2026-09-09','2026-09-10','2026-02-30');
$ok(!($invalidAct['ok']??true),'invalid act date is rejected server-side');
$eq($package['snapshot']['client']['contact_phone'],'','client phone is optional');
$eq($package['snapshot']['client']['contact_email'],'','client email is optional');
$eq($package['values']['company.contact_phone'],'—','package accepts absent company phone');
$eq($package['values']['company.contact_email'],'—','package accepts absent company email');
$mismatch=App\Service\DocumentGeneratorService::prepareClientPackage($pdo,$company,$cid,101,'company_owner',2,77,'2026-09-09');$ok(!($mismatch['ok']??true),'client mismatch fail closed');
App\Security\AccessRuntime::setCurrent($managerAccess);$denied=App\Service\DocumentGeneratorService::prepareClientPackage($pdo,$company,$cid,$managerUserId,'custom_role',1,77,'2026-09-09');$ok(!($denied['ok']??true),'non-owner client package denied');App\Security\AccessRuntime::setCurrent($ownerAccess);
$packageIds=App\Service\DocumentGeneratorService::generateClientPackage($pdo,$cid,101,'company_owner',$package);$eq(count($packageIds),3,'package creates three documents');$expectedPackageTypes=['client_act','client_invoice','client_sublease_crew'];$actualPackageTypes=array_keys($packageIds);sort($actualPackageTypes);$eq($actualPackageTypes,$expectedPackageTypes,'package document types');
$invoice=$pdo->query("SELECT * FROM finance_invoices WHERE number='".date('y')."-144'")->fetch(PDO::FETCH_ASSOC);$ok(is_array($invoice),'package creates electronic invoice');$invoiceId=(int)$invoice['id'];$eq((string)$invoice['amount'],'230000.00','electronic invoice amount');$eq((string)$invoice['invoice_date'],'2026-09-10','electronic invoice date');$eq((int)$invoice['counterparty_entity_id'],1,'electronic invoice client');$eq((int)$pdo->query("SELECT COUNT(*) FROM finance_invoice_links WHERE invoice_id=$invoiceId AND obligation_id IS NOT NULL")->fetchColumn(),1,'electronic invoice links trip obligation');
$packageRows=[];$packagePaths=[];foreach($packageIds as$type=>$docId){$row=App\Service\DocumentGeneratorService::getGenerated($pdo,(int)$docId);$ok(is_array($row),'generated package row '.$type);$eq((string)$row['document_number'],date('y').'-144','generated package number '.$type);$eq(json_decode((string)$row['request_ids_json'],true),['300001','300002'],'generated package requests '.$type);$path=App\Service\DocumentService::resolveStoredPath($cid,(string)$row['stored_path']);$ok(is_string($path)&&is_file($path),'generated package file '.$type);$vv=App\Service\DocumentTemplateService::validateDocxFile($path);$ok($vv['ok']??false,'generated package DOCX '.$type);$eq($vv['placeholders']??[],[],'generated package placeholders '.$type);$z=new ZipArchive();$z->open($path);$text=App\Service\DocumentTemplateService::visibleText((string)$z->getFromName('word/document.xml'));$media=(string)$z->getFromName('word/media/image1.png');$z->close();if($type==='client_invoice'){$has($text,'Счёт на оплату № '.date('y').'-144','invoice number visible');$has($text,'230 000.00','invoice amount visible');$ok($media!=='','invoice PLANEX logo media');}elseif($type==='client_act'){$has($text,'Акт № '.date('y').'-144','act number visible');$has($text,'Исполнитель выполнил все обязательства','act quality clause');$has($text,'Заказчик не имеет претензий','act no-claims clause');$ok($media!=='','act PLANEX logo media');}else{$has($text,'ДОГОВОР СУБАРЕНДЫ ТРАНСПОРТНОГО СРЕДСТВА С ЭКИПАЖЕМ','sublease contract title');$has($text,'Субарендодатель','PLANEX sublessor contract side');$has($text,'Субарендатор','client sublessee contract side');}$packageRows[$type]=$row;$packagePaths[$type]=$path;}
foreach (['client_sublease_crew'=>['2026-09-09','сентября 2026'],'client_invoice'=>['2026-09-10','10 сентября 2026'],'client_act'=>['2026-09-11','11 сентября 2026']] as $type=>$expected) {
    $eq((string)$packageRows[$type]['document_date'],$expected[0],'stored date '.$type);
    $zip=new ZipArchive();$zip->open($packagePaths[$type]);$visible=App\Service\DocumentTemplateService::visibleText((string)$zip->getFromName('word/document.xml'));$zip->close();
    $has($visible,$expected[1],'DOCX date '.$type);
}
$replacement=App\Service\DocumentGeneratorService::prepareClientPackage($pdo,$company,$cid,101,'company_owner',1,77,'2026-09-10');$ok($replacement['ok']??false,'existing package remains valid for preview');$ok($replacement['package_exists']??false,'preview identifies existing package');$eq(count($replacement['existing_package_documents']??[]),3,'preview lists existing package documents');
$duplicate=false;try{App\Service\DocumentGeneratorService::generateClientPackage($pdo,$cid,101,'company_owner',$replacement);}catch(RuntimeException $e){$duplicate=str_contains($e->getMessage(),'Подтвердите перезапись');}$ok($duplicate,'existing package requires explicit overwrite confirmation');
foreach($packageIds as$type=>$docId){$ok(App\Service\DocumentGeneratorService::getGenerated($pdo,(int)$docId)!==null,'unconfirmed overwrite preserves row '.$type);$ok(is_file($packagePaths[$type]),'unconfirmed overwrite preserves file '.$type);}
$pdo->exec("CREATE TRIGGER docgen_overwrite_failure BEFORE DELETE ON generated_documents FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='overwrite rollback test'");$rollback=false;try{App\Service\DocumentGeneratorService::generateClientPackage($pdo,$cid,101,'company_owner',$replacement,true);}catch(Throwable){$rollback=true;}finally{$pdo->exec('DROP TRIGGER docgen_overwrite_failure');}$ok($rollback,'overwrite SQL failure propagates');foreach($packageIds as$type=>$docId){$ok(App\Service\DocumentGeneratorService::getGenerated($pdo,(int)$docId)!==null,'failed overwrite preserves row '.$type);$ok(is_file($packagePaths[$type]),'failed overwrite preserves file '.$type);}
$obligationId=(int)$pdo->query("SELECT obligation_id FROM finance_invoice_links WHERE invoice_id=$invoiceId LIMIT 1")->fetchColumn();
$pdo->exec("INSERT INTO finance_invoices(direction,number,invoice_date,counterparty_entity_type,counterparty_entity_id,amount,status) VALUES('OUTGOING','OTHER-144','2026-09-10','client',1,1.00,'issued')");
$otherInvoiceId=(int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO finance_invoice_links(invoice_id,obligation_id,amount,side) VALUES($otherInvoiceId,$obligationId,1.00,'customer')");
$oldPackageIds=$packageIds;$packageIds=App\Service\DocumentGeneratorService::generateClientPackage($pdo,$cid,101,'company_owner',$replacement,true);$eq(count($packageIds),3,'confirmed overwrite creates complete package despite another later link');$packageRows=[];foreach($packageIds as$type=>$docId){$ok(!in_array($docId,$oldPackageIds,true),'overwrite creates a new row '.$type);$row=App\Service\DocumentGeneratorService::getGenerated($pdo,(int)$docId);$eq((string)$row['document_date'],'2026-09-10','overwrite applies refreshed package date '.$type);$path=App\Service\DocumentService::resolveStoredPath($cid,(string)$row['stored_path']);$ok(is_string($path)&&is_file($path),'overwrite creates DOCX '.$type);$packageRows[$type]=$row;}$eq((int)$pdo->query("SELECT COUNT(*) FROM generated_documents WHERE document_number='".date('y')."-144' AND document_type IN ('client_sublease_crew','client_invoice','client_act')")->fetchColumn(),3,'overwrite leaves one complete package');foreach($oldPackageIds as$type=>$docId){$eq(App\Service\DocumentGeneratorService::getGenerated($pdo,(int)$docId),null,'overwrite removes old row '.$type);$ok(!is_file($packagePaths[$type]),'overwrite removes old DOCX '.$type);}
$eq((int)$pdo->query("SELECT COUNT(*) FROM finance_invoice_links WHERE invoice_id=$otherInvoiceId AND obligation_id=$obligationId")->fetchColumn(),1,'overwrite leaves other invoice link untouched');
$pdo->exec("DELETE FROM finance_invoices WHERE id=$otherInvoiceId");
$eq((int)$pdo->query("SELECT COUNT(*) FROM finance_invoices WHERE number='".date('y')."-144'")->fetchColumn(),1,'overwrite does not duplicate electronic invoice');$eq((int)$pdo->query("SELECT id FROM finance_invoices WHERE number='".date('y')."-144'")->fetchColumn(),$invoiceId,'overwrite keeps electronic invoice identity');
$updPackage=App\Service\DocumentGeneratorService::prepareClientPackage($pdo,$company,$cid,101,'company_owner',1,77,'2026-09-09','2026-09-10','2026-09-11',null,'upd');
$ok($updPackage['ok']??false,'UPD client package prepares');$ok(!($updPackage['package_exists']??true),'act package does not occupy UPD variant');$eq($updPackage['values']['upd.date'],'11 сентября 2026','UPD date in template values');
$updIds=App\Service\DocumentGeneratorService::generateClientPackage($pdo,$cid,101,'company_owner',$updPackage);$eq(array_keys($updIds),['client_upd_contract','client_upd_invoice','client_upd_status2'],'UPD package has three independent types');
$eq((int)$pdo->query("SELECT COUNT(*) FROM finance_invoices WHERE number='".date('y')."-144'")->fetchColumn(),1,'UPD variant does not duplicate electronic invoice');
foreach($updIds as $type=>$docId){$row=App\Service\DocumentGeneratorService::getGenerated($pdo,(int)$docId);$ok(is_array($row),'UPD package row '.$type);$eq((string)$row['document_number'],date('y').'-144','UPD package number '.$type);$path=App\Service\DocumentService::resolveStoredPath($cid,(string)$row['stored_path']);$ok(is_string($path)&&is_file($path),'UPD package file '.$type);$vv=App\Service\DocumentTemplateService::validateDocxFile($path);$ok($vv['ok']??false,'UPD package DOCX '.$type);$eq($vv['placeholders']??[],[],'UPD package placeholders '.$type);$z=new ZipArchive();$z->open($path);$text=App\Service\DocumentTemplateService::visibleText((string)$z->getFromName('word/document.xml'));$z->close();if(in_array($type,['client_upd_invoice','client_upd_status2'],true)){$ok(!str_contains($text,'Место загрузки:'),'UPD invoice and transfer exclude loading route '.$type);$ok(!str_contains($text,'Место выгрузки:'),'UPD invoice and transfer exclude unloading route '.$type);}if($type==='client_upd_contract'){$has($text,'Место выгрузки','contract keeps unloading point');}if($type==='client_upd_status2'){$eq(App\Service\DocumentGeneratorService::generatedDownloadName($row),'#144__УПД-статус-2.docx','UPD download name uses trip number');$updPath=$path;foreach(['Универсальный','передаточный','документ','Счет-фактура №','11 сентября 2026','ООО «ПЛАНЭКС»','230 000,00','Договор субаренды транспортного средства с экипажем № '.date('y').'-144'] as $needle)$has($text,$needle,'UPD content '.$needle);}}
$eq((int)$pdo->query("SELECT COUNT(*) FROM generated_documents WHERE document_number='".date('y')."-144' AND document_type IN ('client_sublease_crew','client_invoice','client_act','client_upd_contract','client_upd_invoice','client_upd_status2')")->fetchColumn(),6,'both client package variants coexist');
$exampleRow=App\Service\DocumentGeneratorService::getGenerated($pdo,(int)$updIds['client_upd_status2']);$exampleRow['snapshot_json']=json_encode(['trip'=>['business_id'=>256]],JSON_THROW_ON_ERROR);$eq(App\Service\DocumentGeneratorService::generatedDownloadName($exampleRow),'#256__УПД-статус-2.docx','historical UPD download name ignores stored file number');
$updArchive=App\Service\DocumentGeneratorService::createHistoryArchive($pdo,$cid,101,'company_owner',array_values($updIds));$updArchiveZip=new ZipArchive();$ok($updArchiveZip->open($updArchive['path'])===true,'UPD package ZIP opens');foreach(['#144__Договор-субаренды-УПД.docx','#144__Счет-УПД.docx','#144__УПД-статус-2.docx'] as $name)$ok($updArchiveZip->locateName($name)!==false,'UPD ZIP contains '.$name);$eq($updArchiveZip->numFiles,3,'UPD ZIP contains exactly three documents');$updArchiveZip->close();unlink($updArchive['path']);
$updAgain=App\Service\DocumentGeneratorService::prepareClientPackage($pdo,$company,$cid,101,'company_owner',1,77,'2026-09-09','2026-09-10','2026-09-11',null,'upd');$ok($updAgain['package_exists']??false,'UPD variant detects its own existing package');
$custom=App\Service\DocumentGeneratorService::prepareClientPackage($pdo,$company,$cid,101,'company_owner',1,77,'2026-09-09','2026-09-10','2026-09-11',null,'upd','КЛ-2026/144');
$ok($custom['ok']??false,'custom contract number prepares');$eq($custom['contract_number'],'КЛ-2026/144','custom contract number preserved');$eq($custom['package_number'],date('y').'-144','invoice and UPD retain package number');$eq($custom['values']['contract.number'],'КЛ-2026/144','contract placeholder uses custom number');$ok($custom['package_exists']??false,'existing invoice and UPD still detected with custom contract number');
$customIds=App\Service\DocumentGeneratorService::generateClientPackage($pdo,$cid,101,'company_owner',$custom,true);
$eq(App\Service\DocumentGeneratorService::getGenerated($pdo,(int)$updIds['client_upd_contract']),null,'renumbering removes superseded contract');
$customContract=App\Service\DocumentGeneratorService::getGenerated($pdo,(int)$customIds['client_upd_contract']);$eq($customContract['document_number'],'КЛ-2026/144','contract document stores custom number');
$customUpd=App\Service\DocumentGeneratorService::getGenerated($pdo,(int)$customIds['client_upd_status2']);$eq($customUpd['document_number'],date('y').'-144','UPD stores package number');
$customUpdPath=App\Service\DocumentService::resolveStoredPath($cid,(string)$customUpd['stored_path']);$customZip=new ZipArchive();$customZip->open($customUpdPath);$customText=App\Service\DocumentTemplateService::visibleText((string)$customZip->getFromName('word/document.xml'));$customZip->close();$has($customText,'№ КЛ-2026/144','UPD basis uses custom contract number');
$updPath=$customUpdPath;
$dated=App\Service\DocumentGeneratorService::prepareClientPackage($pdo,$company,$cid,101,'company_owner',1,77,'2026-09-09','2026-09-12','2026-09-13');$ok($dated['ok']??false,'changed invoice date prepares');
$datedIds=App\Service\DocumentGeneratorService::generateClientPackage($pdo,$cid,101,'company_owner',$dated,true);$eq((int)$pdo->query("SELECT id FROM finance_invoices WHERE number='".date('y')."-144'")->fetchColumn(),$invoiceId,'regeneration updates same electronic invoice');$eq((string)$pdo->query("SELECT invoice_date FROM finance_invoices WHERE id=$invoiceId")->fetchColumn(),'2026-09-12','regeneration updates electronic invoice date');
$pdo->exec("INSERT INTO finance_operations(status) VALUES('POSTED')");$paymentOperationId=(int)$pdo->lastInsertId();$pdo->exec("INSERT INTO finance_operation_allocations(operation_id,invoice_id,amount,allocation_date) VALUES($paymentOperationId,$invoiceId,100.00,'2026-09-12')");
$pdo->exec("UPDATE finance_invoices SET basis='Историческое основание оплаченного счёта' WHERE id=$invoiceId");
$suggestedPaid=App\Service\DocumentGeneratorService::suggestClientPackageDates($pdo,App\Service\WasteTripService::fetchTrip($pdo,77,true));$eq($suggestedPaid['invoice_date'],'2026-09-12','existing paid invoice date suggested instead of trip finish');
$paidSame=App\Service\DocumentGeneratorService::prepareClientPackage($pdo,$company,$cid,101,'company_owner',1,77,'2026-09-09','2026-09-12','2026-09-15');$ok($paidSame['ok']??false,'paid invoice package prepares');
$paidSameIds=App\Service\DocumentGeneratorService::generateClientPackage($pdo,$cid,101,'company_owner',$paidSame,true);$eq(count($paidSameIds),3,'paid invoice permits printed document regeneration');
$paidInvoice=$pdo->query("SELECT invoice_date,amount,basis FROM finance_invoices WHERE id=$invoiceId")->fetch(PDO::FETCH_ASSOC);$eq($paidInvoice['invoice_date'],'2026-09-12','paid invoice date preserved');$eq($paidInvoice['amount'],'230000.00','paid invoice amount preserved');$eq($paidInvoice['basis'],'Историческое основание оплаченного счёта','paid invoice basis preserved');$eq((int)$pdo->query("SELECT COUNT(*) FROM finance_invoice_links WHERE invoice_id=$invoiceId")->fetchColumn(),1,'paid invoice links preserved');$eq((int)$pdo->query("SELECT COUNT(*) FROM finance_operation_allocations WHERE invoice_id=$invoiceId")->fetchColumn(),1,'paid allocation preserved');$datedIds=$paidSameIds;
$pdo->exec("UPDATE finance_invoice_links SET amount=229999.00 WHERE invoice_id=$invoiceId");$paidBadLink=false;try{App\Service\DocumentGeneratorService::generateClientPackage($pdo,$cid,101,'company_owner',$paidSame,true);}catch(RuntimeException $e){$paidBadLink=str_contains($e->getMessage(),'Связи счёта');}$ok($paidBadLink,'paid invoice with mismatched obligation link blocks replacement');$pdo->exec("UPDATE finance_invoice_links SET amount=230000.00 WHERE invoice_id=$invoiceId");
$paidChange=App\Service\DocumentGeneratorService::prepareClientPackage($pdo,$company,$cid,101,'company_owner',1,77,'2026-09-09','2026-09-14','2026-09-15');$paidBlocked=false;try{App\Service\DocumentGeneratorService::generateClientPackage($pdo,$cid,101,'company_owner',$paidChange,true);}catch(RuntimeException $e){$paidBlocked=str_contains($e->getMessage(),'уже есть оплата');}$ok($paidBlocked,'paid invoice blocks changed package');$eq((string)$pdo->query("SELECT invoice_date FROM finance_invoices WHERE id=$invoiceId")->fetchColumn(),'2026-09-12','paid rejection preserves invoice');$ok(App\Service\DocumentGeneratorService::getGenerated($pdo,(int)$datedIds['client_invoice'])!==null,'paid rejection preserves printed invoice');$pdo->exec("DELETE FROM finance_operation_allocations WHERE operation_id=$paymentOperationId");$pdo->exec("DELETE FROM finance_operations WHERE id=$paymentOperationId");
$frozen=(string)App\Service\DocumentGeneratorService::getGenerated($pdo,(int)$datedIds['client_invoice'])['snapshot_json'];$pdo->exec("UPDATE clients SET name='ИЗМЕНЁННЫЙ КЛИЕНТ' WHERE id=1");$oldPackage=App\Service\DocumentGeneratorService::getGenerated($pdo,(int)$datedIds['client_invoice']);$eq((string)$oldPackage['snapshot_json'],$frozen,'client package immutable snapshot');
echo "DOCUMENT_GENERATOR_TRIP_SOURCE=PASS\nDOCUMENT_GENERATOR_CLIENT_PACKAGE=PASS\nDOCUMENT_GENERATOR_CLIENT_PACKAGE_DOCX=PASS\nDOCUMENT_GENERATOR_CLIENT_PACKAGE_IMMUTABLE=PASS\nDOCUMENT_GENERATOR_CLIENT_PACKAGE_OVERWRITE=PASS\n";

// Preserved settlements must use the original contract, not the current display amounts.
$historical=$pdo->query('SELECT * FROM waste_trip_customer_payments WHERE waste_trip_id=77 ORDER BY sort_order,id')->fetchAll(PDO::FETCH_ASSOC);
$historical[0]['amount']='275000.00';$historical[0]['days_count']=11;
$pdo->prepare("INSERT INTO waste_trip_audit_log(waste_trip_id,action,before_json,after_json) VALUES(77,'update',?,?)")->execute([json_encode(['financial_model_version'=>1,'customer_payments'=>$historical],JSON_THROW_ON_ERROR),json_encode(['financial_model_version'=>3,'settlements_preserved'=>true],JSON_THROW_ON_ERROR)]);
$pdo->exec('UPDATE waste_trips SET financial_model_version=3 WHERE id=77');
$preserved=App\Service\DocumentGeneratorService::prepareClientPackage($pdo,$company,$cid,101,'company_owner',1,77,'2026-09-09');
$ok($preserved['ok']??false,'preserved contract package prepares');
$eq((string)$preserved['package_amount'],'275000.00','preserved contract amount independent of current sale 230000 and general 900000');
$has((string)$preserved['snapshot']['parameters']['payment_terms'],'11 календарных','preserved contract terms');
$preservedTrips=App\Service\DocumentGeneratorService::listDocumentTrips($pdo,true);
$preservedTrip=array_values(array_filter($preservedTrips,static fn(array $t):bool=>(int)$t['id']===77))[0];
$eq((string)$preservedTrip['_customer_amount'],'275000.00','document selector uses same historical contract');
$pdo->exec('DELETE FROM waste_trip_audit_log WHERE waste_trip_id=77');
$missingAudit=false;try{App\Service\DocumentGeneratorService::prepareClientPackage($pdo,$company,$cid,101,'company_owner',1,77,'2026-09-09');}catch(RuntimeException $e){$missingAudit=str_contains($e->getMessage(),'сохранённые финансовые условия');}
$ok($missingAudit,'missing historical contract fails closed');
echo "DOCUMENT_GENERATOR_PRESERVED_SETTLEMENTS=PASS\n";

foreach ([[78,244,'26-106-1','275000.00'],[79,246,'26-105','180000.00'],[80,255,'26-106','404827.00']] as [$tripId,$publicId,$number,$amount]) {
    $pdo->exec("INSERT INTO waste_trips(id,business_id,client_id,route_executor_id,responsible_user_id,destination_type,status,planned_start_mode,planned_start_date,planned_finish_date,actual_start_date,actual_end_date,comment,created_by_user_id,created_by_membership_id,created_by_role) VALUES($tripId,$publicId,1,1,$managerUserId,'warehouse','completed','exact','2026-08-31','2026-09-05','2026-08-31','2026-09-09','Исторический счёт',$managerUserId,12,'custom_role')");
    $pdo->exec("INSERT INTO waste_trip_requests(waste_trip_id,feo_request_id,snapshot_mass_kg,position_no) SELECT $tripId,feo_request_id,snapshot_mass_kg,position_no FROM waste_trip_requests WHERE waste_trip_id=77");
    $pdo->exec("INSERT INTO waste_trip_customer_payments(waste_trip_id,sort_order,amount,payment_type,payment_method,condition_type,days_count,days_kind) VALUES($tripId,1,$amount,'Без НДС','cashless','after_documents',7,'calendar')");
    App\Service\FinanceObligationService::syncWasteTrip($pdo,$tripId);
    $obligation=(int)$pdo->query("SELECT id FROM finance_obligations WHERE source_parent_type='WASTE_TRIP' AND source_parent_id=$tripId AND direction='RECEIVABLE' AND party_role='customer' AND cancelled_at IS NULL LIMIT 1")->fetchColumn();
    $ok($obligation>0,'historical obligation '.$publicId);
    $pdo->exec("INSERT INTO finance_invoices(direction,number,invoice_date,counterparty_entity_type,counterparty_entity_id,amount,paid_amount,status) VALUES('OUTGOING','$number','2026-09-11','client',1,$amount,$amount,'paid')");
    $historicalId=(int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO finance_invoice_links(invoice_id,obligation_id,amount,side) VALUES($historicalId,$obligation,$amount,'customer')");
    {
        foreach (['client_sublease_crew','client_invoice','client_act','client_upd_contract','client_upd_invoice','client_upd_status2'] as $oldType) {
            $source=$pdo->prepare("SELECT * FROM generated_documents WHERE document_type=? AND JSON_UNQUOTE(JSON_EXTRACT(snapshot_json,'$.trip.id'))='77' ORDER BY id DESC LIMIT 1");
            $source->execute([$oldType]);$oldDoc=$source->fetch(PDO::FETCH_ASSOC);
            $ok(is_array($oldDoc),'prior generated document source '.$oldType);
            $oldSnapshot=json_decode((string)$oldDoc['snapshot_json'],true,512,JSON_THROW_ON_ERROR);
            $oldSnapshot['trip']['id']=$tripId;
            $oldSnapshot['trip']['business_id']=$publicId;
            $oldSnapshot['package']['number']='26-'.$publicId;
            $oldSnapshot['package']['contract_number']='26-'.$publicId;
            $oldSnapshot['package']['transfer_number']='26-'.$publicId;
            $oldSnapshot['document']['number']='26-'.$publicId;
            $insertOld=$pdo->prepare('INSERT INTO generated_documents(document_type,document_number,document_date,template_id,template_version_id,route_executor_id,request_ids_json,total_mass_kg,unload_address,parameters_json,snapshot_json,stored_path,original_name,sha256,created_by_user_id,created_by_role) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $insertOld->execute([$oldType,'26-'.$publicId,$oldDoc['document_date'],$oldDoc['template_id'],$oldDoc['template_version_id'],$oldDoc['route_executor_id'],$oldDoc['request_ids_json'],$oldDoc['total_mass_kg'],$oldDoc['unload_address'],$oldDoc['parameters_json'],json_encode($oldSnapshot,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),$oldDoc['stored_path'],$oldDoc['original_name'],$oldDoc['sha256'],$oldDoc['created_by_user_id'],$oldDoc['created_by_role']]);
        }
    }
    $suggested=App\Service\DocumentGeneratorService::suggestClientPackageDates($pdo,App\Service\WasteTripService::fetchTrip($pdo,$tripId,true));
    $eq($suggested['invoice_date'],'2026-09-11','historical invoice date is suggested '.$publicId);
    $wrongDate=App\Service\DocumentGeneratorService::prepareClientPackage($pdo,$company,$cid,101,'company_owner',1,$tripId,'2026-08-31','2026-09-05','2026-09-05');
    $ok(!($wrongDate['ok']??true),'historical invoice date mismatch is rejected '.$publicId);
    $preparedHistorical=App\Service\DocumentGeneratorService::prepareClientPackage($pdo,$company,$cid,101,'company_owner',1,$tripId,'2026-08-31','2026-09-11','2026-09-05');
    $ok($preparedHistorical['ok']??false,'historical package prepares '.$publicId);
    $ok($preparedHistorical['package_exists']??false,'old numbered package requests confirmation '.$publicId);$eq(count($preparedHistorical['existing_package_documents']),3,'old act package found by trip '.$publicId);
    $eq($preparedHistorical['package_number'],$number,'historical package number '.$publicId);
    $eq($preparedHistorical['contract_number'],'26-'.$publicId,'historical contract follows trip '.$publicId);
    $eq($preparedHistorical['transfer_number'],'26-'.$publicId,'historical transfer follows trip '.$publicId);
    {
        $oldRequiresConfirmation=false;
        try{App\Service\DocumentGeneratorService::generateClientPackage($pdo,$cid,101,'company_owner',$preparedHistorical);}
        catch(RuntimeException $e){$oldRequiresConfirmation=str_contains($e->getMessage(),'Подтвердите перезапись');}
        $ok($oldRequiresConfirmation,'old numbered package cannot overwrite silently '.$publicId);
        $pdo->exec("UPDATE finance_invoice_links SET amount=274999.00 WHERE invoice_id=$historicalId");
        $badLinkBlocked=false;
        try{App\Service\DocumentGeneratorService::generateClientPackage($pdo,$cid,101,'company_owner',$preparedHistorical,true);}
        catch(RuntimeException $e){$badLinkBlocked=str_contains($e->getMessage(),'Связи исторического счёта');}
        $ok($badLinkBlocked,'mismatched historical link blocks replacement '.$publicId);
        $eq((int)$pdo->query("SELECT COUNT(*) FROM generated_documents WHERE document_type IN ('client_sublease_crew','client_invoice','client_act') AND document_number='26-$publicId'")->fetchColumn(),3,'failed replacement preserves old documents '.$publicId);
        $pdo->exec("UPDATE finance_invoice_links SET amount=$amount WHERE invoice_id=$historicalId");
    }
    $generatedHistorical=App\Service\DocumentGeneratorService::generateClientPackage($pdo,$cid,101,'company_owner',$preparedHistorical,true);
    $eq(count($generatedHistorical),3,'historical package generates three documents '.$publicId);
    $eq((int)$pdo->query("SELECT COUNT(*) FROM finance_invoices WHERE direction='OUTGOING' AND number='$number' AND amount=$amount")->fetchColumn(),1,'historical electronic invoice is reused '.$publicId);
    $eq((string)$pdo->query("SELECT invoice_date FROM finance_invoices WHERE id=$historicalId")->fetchColumn(),'2026-09-11','historical invoice date is preserved '.$publicId);
    $invoiceDoc=App\Service\DocumentGeneratorService::getGenerated($pdo,(int)$generatedHistorical['client_invoice']);
    $eq((string)$invoiceDoc['document_number'],$number,'printed historical invoice number '.$publicId);
    $eq((int)$pdo->query("SELECT COUNT(*) FROM generated_documents WHERE document_type='client_invoice' AND document_number='26-$publicId'")->fetchColumn(),0,'old printed invoice removed after confirmed overwrite '.$publicId);
    $contractDoc=App\Service\DocumentGeneratorService::getGenerated($pdo,(int)$generatedHistorical['client_sublease_crew']);
    $actDoc=App\Service\DocumentGeneratorService::getGenerated($pdo,(int)$generatedHistorical['client_act']);
    $eq((string)$contractDoc['document_number'],'26-'.$publicId,'printed contract follows trip '.$publicId);
    $eq((string)$actDoc['document_number'],'26-'.$publicId,'printed act follows trip '.$publicId);
    foreach ([['row'=>$invoiceDoc,'needle'=>'Счёт на оплату № '.$number],['row'=>$actDoc,'needle'=>'Акт № 26-'.$publicId]] as $check) {
        $docPath=App\Service\DocumentService::resolveStoredPath($cid,(string)$check['row']['stored_path']);
        $docZip=new ZipArchive();$ok($docZip->open($docPath)===true,'historical DOCX opens '.$publicId);
        $visible=App\Service\DocumentTemplateService::visibleText((string)$docZip->getFromName('word/document.xml'));$docZip->close();
        $has($visible,$check['needle'],'historical DOCX visible number '.$publicId);
    }
    $repeat=App\Service\DocumentGeneratorService::prepareClientPackage($pdo,$company,$cid,101,'company_owner',1,$tripId,'2026-08-31','2026-09-11','2026-09-05');
    $ok($repeat['package_exists']??false,'historical package is detected for overwrite '.$publicId);
    $repeatIds=App\Service\DocumentGeneratorService::generateClientPackage($pdo,$cid,101,'company_owner',$repeat,true);
    $eq(count($repeatIds),3,'historical package repeats '.$publicId);
    $eq((int)$pdo->query("SELECT COUNT(*) FROM finance_invoices WHERE direction='OUTGOING' AND number='$number' AND amount=$amount")->fetchColumn(),1,'repeat preserves one electronic invoice '.$publicId);
    $eq((int)$pdo->query("SELECT COUNT(*) FROM finance_invoice_links WHERE invoice_id=$historicalId AND obligation_id=$obligation")->fetchColumn(),1,'repeat preserves historical obligation link '.$publicId);
    $updHistorical=App\Service\DocumentGeneratorService::prepareClientPackage($pdo,$company,$cid,101,'company_owner',1,$tripId,'2026-08-31','2026-09-11','2026-09-05',null,'upd');
    $ok($updHistorical['ok']??false,'historical UPD package prepares '.$publicId);
    $ok($updHistorical['package_exists']??false,'old UPD package requests confirmation '.$publicId);$eq(count($updHistorical['existing_package_documents']),3,'old UPD package found by trip '.$publicId);
    $updHistoricalIds=App\Service\DocumentGeneratorService::generateClientPackage($pdo,$cid,101,'company_owner',$updHistorical,true);
    $eq(count($updHistoricalIds),3,'historical UPD package generates three documents '.$publicId);
    $updInvoice=App\Service\DocumentGeneratorService::getGenerated($pdo,(int)$updHistoricalIds['client_upd_invoice']);
    $updTransfer=App\Service\DocumentGeneratorService::getGenerated($pdo,(int)$updHistoricalIds['client_upd_status2']);
    $eq((string)$updInvoice['document_number'],$number,'historical UPD package invoice keeps old number '.$publicId);
    $eq((int)$pdo->query("SELECT COUNT(*) FROM generated_documents WHERE document_type='client_upd_invoice' AND document_number='26-$publicId'")->fetchColumn(),0,'old printed UPD invoice removed after confirmed overwrite '.$publicId);
    $eq((string)$updTransfer['document_number'],'26-'.$publicId,'historical UPD follows trip '.$publicId);
    $eq((int)$pdo->query("SELECT COUNT(*) FROM finance_invoices WHERE direction='OUTGOING' AND number='$number' AND amount=$amount")->fetchColumn(),1,'UPD variant reuses historical electronic invoice '.$publicId);
}
echo "DOCUMENT_GENERATOR_HISTORICAL_INVOICES=PASS\n";

$pdo->exec("INSERT INTO waste_trips(id,business_id,client_id,route_executor_id,responsible_user_id,destination_type,status,planned_start_mode,planned_start_date,planned_finish_date,actual_start_date,actual_end_date,comment,created_by_user_id,created_by_membership_id,created_by_role) VALUES(81,300,1,1,$managerUserId,'warehouse','completed','exact','2026-08-31','2026-09-05','2026-08-31','2026-09-09','Неучтённый старый номер',$managerUserId,12,'custom_role')");
$pdo->exec("INSERT INTO waste_trip_requests(waste_trip_id,feo_request_id,snapshot_mass_kg,position_no) SELECT 81,feo_request_id,snapshot_mass_kg,position_no FROM waste_trip_requests WHERE waste_trip_id=77");
$pdo->exec("INSERT INTO waste_trip_customer_payments(waste_trip_id,sort_order,amount,payment_type,payment_method,condition_type,days_count,days_kind) VALUES(81,1,123000.00,'Без НДС','cashless','after_documents',7,'calendar')");
App\Service\FinanceObligationService::syncWasteTrip($pdo,81);
$unknownObligation=(int)$pdo->query("SELECT id FROM finance_obligations WHERE source_parent_type='WASTE_TRIP' AND source_parent_id=81 AND direction='RECEIVABLE' AND party_role='customer' AND cancelled_at IS NULL LIMIT 1")->fetchColumn();
$pdo->exec("INSERT INTO finance_invoices(direction,number,invoice_date,counterparty_entity_type,counterparty_entity_id,amount,status) VALUES('OUTGOING','OLD-300','2026-09-11','client',1,123000.00,'issued')");
$unknownInvoiceId=(int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO finance_invoice_links(invoice_id,obligation_id,amount,side) VALUES($unknownInvoiceId,$unknownObligation,123000.00,'customer')");
$unknown=App\Service\DocumentGeneratorService::prepareClientPackage($pdo,$company,$cid,101,'company_owner',1,81,'2026-08-31','2026-09-11','2026-09-05');
$ok($unknown['ok']??false,'unlisted exception can be previewed');
$eq($unknown['package_number'],date('y').'-300','unlisted exception defaults to YY-trip');
$unknownBlocked=false;try{App\Service\DocumentGeneratorService::generateClientPackage($pdo,$cid,101,'company_owner',$unknown);}catch(RuntimeException $e){$unknownBlocked=str_contains($e->getMessage(),'не пропущено ли исключение');}
$ok($unknownBlocked,'unlisted historical invoice is identified without duplicate');
$eq((int)$pdo->query("SELECT COUNT(*) FROM generated_documents WHERE document_type='client_invoice' AND document_number='".date('y')."-300'")->fetchColumn(),0,'unlisted exception leaves no printed invoice');
echo "DOCUMENT_GENERATOR_UNLISTED_EXCEPTION_GUARD=PASS\n";

$ad=trim((string)(getenv('PLANEX_DOCGEN_ARTIFACT_DIR')?:''));if($ad!==''){if(!str_starts_with($ad,DIRECTORY_SEPARATOR))$ad=$root.'/'.$ad;if(!is_dir($ad))mkdir($ad,0775,true);foreach(['contract_vehicle_lease_crew_regression.docx'=>$path1,'waybill_driver_assignment_regression.docx'=>$wpath,'client_upd_status2_regression.docx'=>$updPath]as$n=>$s){$t=rtrim($ad,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$n;$ok(copy($s,$t),'artifact '.$n);}}
echo "DOCUMENT_GENERATOR_REGRESSION=PASS\n";
}finally{App\Security\AccessRuntime::clear();foreach($files as$f)@unlink($f);$rm($store);try{if($ownsDatabase)$admin->exec("DROP DATABASE IF EXISTS $qd");elseif(isset($pdo)&&$pdo instanceof PDO)cleanCompanyPoolDatabase($pdo);}catch(Throwable){}}
