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
$view=$src('app/View/pages/company_document_generator.php');
$templateTrait=$src('app/Service/DocumentGeneratorTemplateTrait.php');
$sequence=$src('app/Service/DocumentNumberSequenceService.php');
foreach([['SELECT prefix,next_number FROM document_number_sequences','sequence read'],['FOR UPDATE','sequence lock'],['inTransaction()','transaction guard']]as[$n,$m])$has($sequence,$n,$m);
foreach([['allocateVehicleLeaseContractNumber','allocator wiring'],["number_mode'] = 'automatic'",'automatic snapshot'],['beginTransaction()','generation transaction'],['rollBack()','generation rollback'],["unset(\$params['contract_number']",'copy no reuse']]as[$n,$m])$has($wf,$n,$m);
$ok(!str_contains($access,'contractor_bank_inn'),'generator executor query must not depend on contractor bank INN');
$ok(!str_contains($access,"COLUMN_NAME='bank_inn'"),'runtime schema must not depend on contractor bank INN column');
foreach(["'contract.start_time'","'company.physical_address'","'company.contact_phone'","'company.contact_email'","'executor.bank_inn'"]as$n)$has($data,$n,'optional matrix '.$n);
$ok(!str_contains($data,'не заполнен ИНН банка'),'bank INN blocker removed');
$has($wf,'GoogleSheetsRequestContactService','Google contacts wiring');$ok(!str_contains($access,'kontakt_tel'),'FEO contact columns removed from generator query');
$ok(!str_contains($view,'name="contract_number"'),'manual contract number removed');
$ok(!str_contains($view,'name="executor_bank_inn"'),'manual bank INN removed');
$ok(!str_contains($view,'ИНН банка Арендодателя'),'bank INN removed from preview');
foreach(['document-generator-preview-modal','data-docgen-section="vehicle_lease_crew"','data-docgen-section="waybill_driver_assignment"','Сформировать DOCX','Номер будет присвоен автоматически при формировании.']as$n)$has($view,$n,'UI contract '.$n);
$has($templateTrait,'contract_vehicle_lease_crew_v3.docx','built-in v3 seed');
$has($templateTrait,'87584e74a9cfd6bc150fc5ea2c4f7b7045e8ff4b2fba7a5fb80b03ccfc97dff2','built-in v3 SHA');
echo "DOCUMENT_GENERATOR_CANONICAL_WIRING=PASS\n";

require_once $root.'/bootstrap/app.php';
require_once $root.'/app/Support/company_database.php';
require_once $root.'/app/Security/EffectiveAccess.php';
require_once $root.'/app/Security/AccessRuntime.php';
require_once $root.'/app/Service/AccessControlService.php';
require_once $root.'/app/Service/DocumentService.php';
require_once $root.'/app/Service/DocumentTemplateService.php';
require_once $root.'/app/Service/FeoRequestInteractionService.php';
require_once $root.'/app/Service/DocumentGeneratorService.php';

$p=new App\Service\FeoRequestInteractionService();
$eq($p->extractRequestIds('300001 300002 300001'),['300001','300002'],'parser dedupe');
$eq($p->extractRequestIds("| 300003 | 399999 |\n300004\t399998"),['300003','300004'],'structured parser');
echo "DOCUMENT_GENERATOR_FEO_PARSER=PASS\n";

$canon=[
 'contract_vehicle_lease_crew_v1.docx'=>'0e3e7a3ca4d81face23fa070bcabffacc86636d08affdda91faa3c725d4e8adc',
 'contract_vehicle_lease_crew_v2.docx'=>'7b79a9ac558af1633b1ed2d6414bef1efe0222c67a543d1b4a8e802e6267a1fa',
 'contract_vehicle_lease_crew_v3.docx'=>'87584e74a9cfd6bc150fc5ea2c4f7b7045e8ff4b2fba7a5fb80b03ccfc97dff2',
 'waybill_driver_assignment_v1.docx'=>'5a45e855c6fb47dbc96e6d8b9185d7032b5ca86a9baefbb78fdc6419749f73ab'
];
$files=[];
foreach($canon as$n=>$sha){$ps=glob($root.'/resources/document-templates/'.$n.'.b64.part*')?:[];sort($ps,SORT_STRING);$ok($ps!==[],'parts '.$n);$b='';foreach($ps as$f){$e=preg_replace('/\s+/','',(string)file_get_contents($f))??'';$d=base64_decode($e,true);$ok(is_string($d)&&$d!=='','decode '.basename($f));$b.=$d;}$eq(hash('sha256',$b),$sha,'template SHA '.$n);$t=tempnam(sys_get_temp_dir(),'dg4_');$x=$t.'.docx';@unlink($t);file_put_contents($x,$b);$files[$n]=$x;}
$v2=App\Service\DocumentTemplateService::validateDocxFile($files['contract_vehicle_lease_crew_v2.docx']);$ok($v2['ok']??false,'validate contract v2');$ok(!in_array('executor.bank_inn',$v2['placeholders']??[],true),'v2 bank placeholder absent');$z=new ZipArchive();$z->open($files['contract_vehicle_lease_crew_v2.docx']);$visible=App\Service\DocumentTemplateService::visibleText((string)$z->getFromName('word/document.xml'));$z->close();$ok(preg_match('/ИНН\s+БАНКА/ui',$visible)!==1,'v2 bank label absent');
echo "DOCUMENT_GENERATOR_CANONICAL_TEMPLATE_SHA=PASS\n";

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
"CREATE TABLE contractors(id INT UNSIGNED PRIMARY KEY,name VARCHAR(255),inn VARCHAR(32),kpp VARCHAR(32),ogrn VARCHAR(32),contractor_type VARCHAR(32),legal_address TEXT,physical_address TEXT,bank_account VARCHAR(64),bank_name VARCHAR(255),bank_bik VARCHAR(32),bank_corr_account VARCHAR(64),director_full_name VARCHAR(255),director_position VARCHAR(255),created_by_user_id INT,status VARCHAR(32) DEFAULT 'active',deleted_at DATETIME NULL) ENGINE=InnoDB",
"CREATE TABLE drivers(id INT UNSIGNED PRIMARY KEY,full_name VARCHAR(255),passport_number VARCHAR(64),passport_issued_by VARCHAR(255),passport_issue_date DATE,license_number VARCHAR(64),license_issue_date DATE,created_by_user_id INT,status VARCHAR(32) DEFAULT 'active',deleted_at DATETIME NULL) ENGINE=InnoDB",
"CREATE TABLE vehicle_units(id INT UNSIGNED PRIMARY KEY,unit_type VARCHAR(32),brand VARCHAR(255),model VARCHAR(255),plate_number VARCHAR(64),vin VARCHAR(64),created_by_user_id INT,status VARCHAR(32) DEFAULT 'active',deleted_at DATETIME NULL) ENGINE=InnoDB",
"CREATE TABLE vehicle_sets(id INT UNSIGNED PRIMARY KEY,primary_vehicle_unit_id INT UNSIGNED,secondary_vehicle_unit_id INT UNSIGNED,set_type VARCHAR(32),created_by_user_id INT,status VARCHAR(32) DEFAULT 'active',deleted_at DATETIME NULL) ENGINE=InnoDB",
"CREATE TABLE driver_vehicle_blocks(id INT UNSIGNED PRIMARY KEY,driver_id INT UNSIGNED,vehicle_set_id INT UNSIGNED,created_by_user_id INT,status VARCHAR(32) DEFAULT 'active',deleted_at DATETIME NULL) ENGINE=InnoDB",
"CREATE TABLE crews(id INT UNSIGNED PRIMARY KEY,contractor_id INT UNSIGNED,driver_vehicle_block_id INT UNSIGNED,created_by_user_id INT,status VARCHAR(32) DEFAULT 'active',deleted_at DATETIME NULL) ENGINE=InnoDB",
"CREATE TABLE crew_drivers(crew_id INT UNSIGNED,driver_id INT UNSIGNED,PRIMARY KEY(crew_id,driver_id)) ENGINE=InnoDB",
"CREATE TABLE entity_access_grants(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,entity_type VARCHAR(64),entity_id INT UNSIGNED,granted_to_user_id INT UNSIGNED,access_level VARCHAR(16),revoked_at DATETIME NULL) ENGINE=InnoDB",
"CREATE TABLE feo_requests(zayavka_id VARCHAR(64) PRIMARY KEY,mass_netto DECIMAL(18,6),naim_oo_gruzootpravitel VARCHAR(255),mno_adres_pogruzki TEXT,kontakt_tel VARCHAR(64),tel_dopolnitelnyy VARCHAR(64),kontakt_email VARCHAR(255),adres_vygruzki_otkhodov TEXT) ENGINE=InnoDB"
]as$s)$pdo->exec($s);
$applySql=function(string $path)use($pdo){$sql=(string)file_get_contents($path);foreach(preg_split('/;\s*(?:\R|$)/',$sql)?:[]as$s){$s=trim($s);if($s!=='')$pdo->exec($s);}};
$applySql($root.'/database/migrations-local/127_create_document_generator.sql');$applySql($root.'/database/migrations-local/129_document_generator_auto_number_and_bank_inn.sql');$ok(App\Service\DocumentGeneratorService::runtimeSchemaReady($pdo),'runtime schema');
$pdo->exec("INSERT INTO contractors(id,name,inn,kpp,ogrn,contractor_type,legal_address,physical_address,bank_account,bank_name,bank_bik,bank_corr_account,bank_inn,director_full_name,director_position,created_by_user_id,status,deleted_at) VALUES(1,'ООО Исполнитель','7701234567','770101001','1027700000001','legal','Москва','Москва','40702810900000000001','Банк','044525225','30101810400000000225',NULL,'Петров Петр Петрович','Директор',101,'active',NULL),(2,'ООО Чужой','7801234567','780101001','1027800000001','legal','СПб','СПб','40702810900000000002','Банк2','044030653','30101810500000000653',NULL,'Сидоров Сидор Сидорович','Директор',202,'active',NULL)");
$pdo->exec("INSERT INTO drivers VALUES(1,'Иванов Иван Иванович','4510 123456','ОВД','2020-01-02','77 11 123456','2021-02-03',101,'active',NULL),(2,'Чужой Водитель','4510 654321','ОВД2','2020-03-04','78 22 654321','2021-04-05',202,'active',NULL)");
foreach(["INSERT INTO vehicle_units VALUES(1,'truck','КАМАЗ','5490','А123АА77','XTCVIN00000000001',101,'active',NULL),(2,'truck','МАЗ','5440','В456ВВ78','XTCVIN00000000002',202,'active',NULL)","INSERT INTO vehicle_sets VALUES(1,1,NULL,'single',101,'active',NULL),(2,2,NULL,'single',202,'active',NULL)","INSERT INTO driver_vehicle_blocks VALUES(1,1,1,101,'active',NULL),(2,2,2,202,'active',NULL)","INSERT INTO crews VALUES(1,1,1,101,'active',NULL),(2,2,2,202,'active',NULL)","INSERT INTO crew_drivers VALUES(1,1),(2,2)"]as$s)$pdo->exec($s);
$pdo->exec("INSERT INTO feo_requests VALUES('300001',1.0,'Отправитель 1','Погрузка 1','111',NULL,NULL,'Воскресенск'),('300002',0.5,'Отправитель 2','Погрузка 2','222',NULL,'two@test','Воскресенск'),('300003',0.25,'Отправитель 3','Погрузка 3',NULL,NULL,'three@test','Воскресенск'),('300004',0.25,'Отправитель 4','Погрузка 4',NULL,NULL,NULL,'Воскресенск'),('300005',0.1,'Отправитель 5','Погрузка 5','555',NULL,NULL,'Коломна')");
$googleFixture="header,c1,c2,c3,c4,c5,c6,c7,c8,contacts\n300001,,,,,,,,,111\n300002,,,,,,,,,\"222, two@test\"\n300003,,,,,,,,,three@test\n300004,,,,,,,,,\n300005,,,,,,,,,555\n";App\Service\GoogleSheetsRequestContactService::setDefaultFetcherForTests(static fn(string $url):string=>$googleFixture);
$company=['id'=>$cid,'name'=>'ООО «ПЛАНЭКС»','inn'=>'5005061234','kpp'=>'500501001','ogrn'=>'1205000000001','legal_address'=>'Воскресенск','physical_address'=>'','bank_name'=>'Банк ПЛАНЭКС','bank_account'=>'40702810000000000001','bank_corr_account'=>'30101810000000000001','bank_bik'=>'044525001','contact_phone'=>'','contact_email'=>'','director_full_name'=>'Гладских Карина Владимировна','director_position'=>'Генеральный директор'];
App\Service\DocumentGeneratorService::seedDefaultTemplates($pdo,$cid,1,'company_owner');$ts=App\Service\DocumentGeneratorService::listTemplates($pdo,true);$bt=[];foreach($ts as$t)$bt[$t['document_type']]=$t;$eq($bt['vehicle_lease_crew']['sha256'],$canon['contract_vehicle_lease_crew_v3.docx'],'contract seed v3');$ph=json_decode((string)$bt['vehicle_lease_crew']['placeholders_json'],true)?:[];$ok(!in_array('executor.bank_inn',$ph,true),'active v3 bank placeholder clean');$ok(in_array('vehicle.vin',$ph,true),'active v3 vehicle VIN placeholder');
$allScopes=['client'=>'all','contractor'=>'all','vehicle_unit'=>'all','driver'=>'all','linear_trip'=>'all','waste_trip'=>'all'];
$managerAccess=App\Security\EffectiveAccess::member(12,$cid,['company.route_executors'=>true,'company.documents.create'=>true,'company.documents.history'=>true],$allScopes,'custom_role');
App\Security\AccessRuntime::setCurrent($managerAccess);
$eq(array_map('intval',array_column(App\Service\DocumentGeneratorService::listAccessibleExecutors($pdo,101,'custom_role'),'crew_id')),[1,2],'dynamic manager must see same effective route executors as canonical Route Executors ACL');
$ok(App\Service\DocumentGeneratorService::loadAccessibleExecutor($pdo,2,101,'custom_role')!==null,'dynamic manager must load an executor allowed by effective scopes');
$templateTypes=array_keys($bt);sort($templateTypes);$eq($templateTypes,['vehicle_lease_crew','waybill_driver_assignment'],'dynamic manager create flow must receive all active company templates');
echo "DOCUMENT_GENERATOR_DYNAMIC_MANAGER_EXECUTORS=PASS\nDOCUMENT_GENERATOR_MANAGER_ALL_TEMPLATES=PASS\n";
$params=['contract_date'=>'2026-09-03','start_date'=>'2026-09-03','start_time'=>'','finish_date'=>'2026-09-10','price'=>'100000','vat_mode'=>'без НДС','waybill_number'=>'IGNORED','waybill_date'=>''];
$before=(int)$pdo->query("SELECT next_number FROM document_number_sequences WHERE sequence_key='vehicle_lease_crew_contract'")->fetchColumn();
$pr=App\Service\DocumentGeneratorService::prepare($pdo,$company,$cid,101,'company_owner',(int)$bt['vehicle_lease_crew']['id'],1,"300001\n300002\n300003\n300004",$params);$ok($pr['ok']??false,'optional contract prepare');$afterPreview=(int)$pdo->query("SELECT next_number FROM document_number_sequences WHERE sequence_key='vehicle_lease_crew_contract'")->fetchColumn();$eq($before,105,'initial sequence');$eq($afterPreview,105,'preview does not consume sequence');$eq($pr['snapshot']['document']['number'],'','preview number empty');$eq($pr['values']['contract.start_time'],'—','optional time dash');$eq($pr['values']['company.physical_address'],'—','optional physical address dash');$eq($pr['values']['company.contact_phone'],'—','optional phone dash');$eq($pr['values']['company.contact_email'],'—','optional email dash');$eq($pr['request_values'][0]['request.contacts'],'111','contacts phone only');$eq($pr['request_values'][1]['request.contacts'],'222, two@test','contacts phone email');$eq($pr['request_values'][2]['request.contacts'],'three@test','contacts email only');$eq($pr['request_values'][3]['request.contacts'],'—','contacts absent dash');$ok(!array_key_exists('bank_inn',$pr['snapshot']['executor']),'generator snapshot independent of bank INN');$ok(!array_key_exists('waybill_number',$pr['snapshot']['parameters']),'contract ignores waybill fields');
$mx=App\Service\DocumentGeneratorService::prepare($pdo,$company,$cid,101,'company_owner',(int)$bt['vehicle_lease_crew']['id'],1,"300001\n300005",$params);$ok(!($mx['ok']??true),'mixed unload fail closed');echo "DOCUMENT_GENERATOR_DATA_VALIDATION=PASS\n";
$id1=App\Service\DocumentGeneratorService::generate($pdo,$cid,101,'company_owner',$pr);$row1=App\Service\DocumentGeneratorService::getGenerated($pdo,$id1);$eq($row1['document_number'],'П 105','first auto number');$snap1=json_decode((string)$row1['snapshot_json'],true);$eq($snap1['document']['number'],'П 105','snapshot number');$ok(!array_key_exists('bank_inn',$snap1['executor']),'generated snapshot no bank INN dependency');$path1=App\Service\DocumentService::resolveStoredPath($cid,(string)$row1['stored_path']);$ok(is_string($path1)&&is_file($path1),'contract file');$v=App\Service\DocumentTemplateService::validateDocxFile($path1);$ok($v['ok']??false,'contract docx');$eq($v['placeholders']??[],[],'contract placeholders');
$pdo->beginTransaction();$n=App\Service\DocumentNumberSequenceService::allocateVehicleLeaseContractNumber($pdo);$eq($n,'П 106','rollback candidate');$pdo->rollBack();$eq((int)$pdo->query("SELECT next_number FROM document_number_sequences WHERE sequence_key='vehicle_lease_crew_contract'")->fetchColumn(),106,'rollback does not burn');$id2=App\Service\DocumentGeneratorService::generate($pdo,$cid,101,'company_owner',$pr);$row2=App\Service\DocumentGeneratorService::getGenerated($pdo,$id2);$eq($row2['document_number'],'П 106','second auto number');$seed=App\Service\DocumentGeneratorService::copySeedFromGenerated($row1);$ok(!array_key_exists('contract_number',$seed['params']),'copy no old number');$duplicateBlocked=false;try{$pdo->exec('INSERT INTO generated_documents(document_type,document_number,document_date,template_id,template_version_id,route_executor_id,request_ids_json,total_mass_kg,unload_address,parameters_json,snapshot_json,stored_path,original_name,sha256,created_by_user_id,created_by_role) SELECT document_type,document_number,document_date,template_id,template_version_id,route_executor_id,request_ids_json,total_mass_kg,unload_address,parameters_json,snapshot_json,stored_path,original_name,sha256,created_by_user_id,created_by_role FROM generated_documents WHERE id='.(int)$id1);}catch(PDOException){$duplicateBlocked=true;}$ok($duplicateBlocked,'database unique barrier');echo "DOCUMENT_GENERATOR_AUTO_NUMBERING=PASS\nDOCUMENT_GENERATOR_CONTRACT_DOCX=PASS\n";
$wp=App\Service\DocumentGeneratorService::prepare($pdo,$company,$cid,101,'company_owner',(int)$bt['waybill_driver_assignment']['id'],1,"300001\n300002",['waybill_number'=>'ПЛ-001','waybill_date'=>'2026-09-03','contract_date'=>'','start_date'=>'','finish_date'=>'','price'=>'']);$ok($wp['ok']??false,'waybill prepare ignores contract fields');$ok(!array_key_exists('contract_date',$wp['snapshot']['parameters']),'waybill ignores contract parameters');$wid=App\Service\DocumentGeneratorService::generate($pdo,$cid,101,'company_owner',$wp);$wr=App\Service\DocumentGeneratorService::getGenerated($pdo,$wid);$eq($wr['document_number'],'ПЛ-001','waybill manual unchanged');$wpath=App\Service\DocumentService::resolveStoredPath($cid,(string)$wr['stored_path']);$ok(is_string($wpath)&&is_file($wpath),'waybill file');echo "DOCUMENT_GENERATOR_WAYBILL_DOCX=PASS\n";
$foreignId=App\Service\DocumentGeneratorService::generate($pdo,$cid,202,'custom_role',$pr);
$managerHistory=App\Service\DocumentGeneratorService::listHistory($pdo,200,101,'custom_role');
$managerHistoryIds=array_map('intval',array_column($managerHistory,'id'));
$ok(in_array($id1,$managerHistoryIds,true)&&in_array($id2,$managerHistoryIds,true)&&in_array($wid,$managerHistoryIds,true),'manager history must contain own generated documents');
$ok(!in_array($foreignId,$managerHistoryIds,true),'manager history must exclude documents created by another user');
$ok(App\Service\DocumentGeneratorService::getAccessibleGenerated($pdo,$id1,101,'custom_role')!==null,'manager must download own generated document');
$ok(App\Service\DocumentGeneratorService::getAccessibleGenerated($pdo,$foreignId,101,'custom_role')===null,'direct download must not bypass creator isolation');
$ownerAccess=App\Security\EffectiveAccess::systemOwner(11,$cid,[]);App\Security\AccessRuntime::setCurrent($ownerAccess);
$ownerHistoryIds=array_map('intval',array_column(App\Service\DocumentGeneratorService::listHistory($pdo,200,101,'company_owner'),'id'));
$ok(in_array($foreignId,$ownerHistoryIds,true),'system owner retains company-wide document history');
$ok(App\Service\DocumentGeneratorService::getAccessibleGenerated($pdo,$foreignId,101,'company_owner')!==null,'system owner retains company-wide generated document access');
App\Security\AccessRuntime::setCurrent($managerAccess);
echo "DOCUMENT_GENERATOR_MANAGER_HISTORY_CREATOR_SCOPE=PASS\nDOCUMENT_GENERATOR_MANAGER_DIRECT_DOWNLOAD_SCOPE=PASS\n";
$beforeSnapshot=(string)$row1['snapshot_json'];$beforeSha=(string)$row1['sha256'];$pdo->exec("UPDATE contractors SET name='ООО После Изменения',bank_inn='7700000000' WHERE id=1");$pdo->exec("UPDATE feo_requests SET mass_netto=2.0 WHERE zayavka_id='300001'");$old=App\Service\DocumentGeneratorService::getGenerated($pdo,$id1);$eq((string)$old['snapshot_json'],$beforeSnapshot,'immutable snapshot');$eq((string)$old['sha256'],$beforeSha,'immutable SHA');echo "DOCUMENT_GENERATOR_HISTORY_SNAPSHOT=PASS\n";
$ad=trim((string)(getenv('PLANEX_DOCGEN_ARTIFACT_DIR')?:''));if($ad!==''){if(!str_starts_with($ad,DIRECTORY_SEPARATOR))$ad=$root.'/'.$ad;if(!is_dir($ad))mkdir($ad,0775,true);foreach(['contract_vehicle_lease_crew_regression.docx'=>$path1,'waybill_driver_assignment_regression.docx'=>$wpath]as$n=>$s){$t=rtrim($ad,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$n;$ok(copy($s,$t),'artifact '.$n);}}
echo "DOCUMENT_GENERATOR_REGRESSION=PASS\n";
}finally{App\Security\AccessRuntime::clear();foreach($files as$f)@unlink($f);$rm($store);try{if($ownsDatabase)$admin->exec("DROP DATABASE IF EXISTS $qd");elseif(isset($pdo)&&$pdo instanceof PDO)cleanCompanyPoolDatabase($pdo);}catch(Throwable){}}
