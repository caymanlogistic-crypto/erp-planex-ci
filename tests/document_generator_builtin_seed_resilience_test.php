<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root=dirname(__DIR__);
require_once $root.'/bootstrap/app.php';
require_once $root.'/app/Support/company_database.php';
require_once $root.'/app/Service/DocumentService.php';
require_once $root.'/app/Service/DocumentTemplateService.php';
require_once $root.'/app/Service/DocumentGeneratorService.php';

$fail=static fn(string $m)=>throw new RuntimeException($m);
$ok=static fn(bool $v,string $m)=>$v?null:$fail($m);
$eq=static fn($a,$b,string $m)=>$a===$b?null:$fail($m.' expected='.var_export($b,true).' actual='.var_export($a,true));
if(!class_exists(ZipArchive::class)||!in_array('mysql',PDO::getAvailableDrivers(),true))$fail('ZIPARCHIVE and PDO MySQL required');

$host=getenv('PLANEX_SCHEMA_DB_HOST')?:'127.0.0.1';
$port=(int)(getenv('PLANEX_SCHEMA_DB_PORT')?:3306);
$user=getenv('PLANEX_SCHEMA_DB_USER')?:'planex_ci';
$pass=getenv('PLANEX_SCHEMA_DB_PASSWORD')?:'planex_ci';
$admin=new PDO("mysql:host=$host;port=$port;charset=utf8mb4",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$fixedDb=trim((string)getenv('PLANEX_TEST_FIXED_DB'));$ownsDatabase=$fixedDb==='';
$fixedAllow=array_filter(array_map('trim',explode(',',(string)getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST'))));
if(!$ownsDatabase&&(getenv('APP_ENV')!=='test'||!in_array($fixedDb,$fixedAllow,true)))$fail('guarded fixed DB required');
$db=$ownsDatabase?'planex_docgen_seed_'.getmypid().'_'.bin2hex(random_bytes(2)):$fixedDb;
$qd=companyDbQuoteIdentifier($db);
$cid=88901;
$store=storage_path("companies/$cid/document-generator");
if($ownsDatabase)$admin->exec("CREATE DATABASE $qd CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

$rm=function(string $p)use(&$rm){if(!file_exists($p))return;if(!is_dir($p)||is_link($p)){@unlink($p);return;}foreach(array_diff(scandir($p)?:[],['.','..'])as$n)$rm($p.DIRECTORY_SEPARATOR.$n);@rmdir($p);};
$parts=function(string $name)use($root,$fail){$p=glob($root.'/resources/document-templates/'.$name.'.b64.part*')?:[];sort($p,SORT_STRING);if($p===[])$fail('parts '.$name);return$p;};
$materialize=function(string $name)use($parts,$fail){$b='';foreach($parts($name)as$p){$e=preg_replace('/\s+/','',(string)file_get_contents($p))??'';$d=base64_decode($e,true);if(!is_string($d)||$d==='')$fail('decode '.$p);$b.=$d;}$t=tempnam(sys_get_temp_dir(),'dgs_');$f=$t.'.docx';@unlink($t);file_put_contents($f,$b);return$f;};
$stash=function(string $name)use($parts){$src=$parts($name);$dir=sys_get_temp_dir().'/dgs_parts_'.bin2hex(random_bytes(3));mkdir($dir,0700,true);foreach($src as$p)rename($p,$dir.'/'.basename($p));return[$dir,$src];};
$restore=function(array $s)use($rm){[$dir,$dst]=$s;foreach($dst as$p){$src=$dir.'/'.basename($p);if(is_file($src))rename($src,$p);}$rm($dir);};

try{
    $pdo=new PDO("mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    if(!$ownsDatabase)cleanCompanyPoolDatabase($pdo);
    $pdo->exec('CREATE TABLE crews(id INT UNSIGNED PRIMARY KEY) ENGINE=InnoDB');
    $pdo->exec('INSERT INTO crews VALUES(1)');
    foreach(preg_split('/;\s*(?:\R|$)/',(string)file_get_contents($root.'/database/migrations-local/127_create_document_generator.sql'))?:[]as$s){$s=trim($s);if($s!=='')$pdo->exec($s);}

    $reset=function()use($pdo,$rm,$store){
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach(['DELETE FROM generated_document_requests','DELETE FROM generated_documents','UPDATE document_templates SET active_version_id=NULL','DELETE FROM document_template_versions','DELETE FROM document_templates']as$sql)$pdo->exec($sql);
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        $rm($store);
    };
    $userTemplate=function(string $type,string $name,string $file)use($pdo,$cid,$materialize){$f=$materialize($file);try{App\Service\DocumentGeneratorService::createTemplateVersionFromFile($pdo,$cid,null,$type,$name,$f,'user_'.$file,42,'company_owner');}finally{@unlink($f);}};
    $rows=static fn()=>App\Service\DocumentGeneratorService::listTemplates($pdo,true);
    $warnings=static fn()=>App\Service\DocumentGeneratorService::builtInTemplateWarnings();
    $renderSaved=function(string $type)use($pdo,$cid,$ok){
        $t=null;foreach(App\Service\DocumentGeneratorService::listTemplates($pdo,true)as$r)if($r['document_type']===$type){$t=$r;break;}
        $ok(is_array($t),'saved '.$type.' visible');
        $src=App\Service\DocumentService::resolveStoredPath($cid,(string)$t['stored_path']);
        $ok(is_string($src)&&is_file($src),'saved file');
        $ph=json_decode((string)$t['placeholders_json'],true)?:[];$values=[];$row=[];
        foreach($ph as$p){if(str_starts_with($p,'request.'))$row[$p]='X';else$values[$p]='X';}
        $out=tempnam(sys_get_temp_dir(),'dgr_').'.docx';
        App\Service\DocumentTemplateService::render($src,$out,$values,$row===[]?[]:[$row]);
        $ok(is_file($out),'saved template renders');@unlink($out);
    };

    $reset();
    App\Service\DocumentGeneratorService::seedDefaultTemplates($pdo,$cid,0,'system');
    $eq(count($rows()),9,'A all built-ins seeded');$eq(count($warnings()),0,'A warnings');$renderSaved('carrier_sublease_crew');$renderSaved('client_upd_status2');
    echo "SEED_CASE_A=PASS\n";

    $reset();
    $userTemplate('vehicle_lease_crew','Пользовательский договор','contract_vehicle_lease_crew_v1.docx');
    $s=$stash('waybill_driver_assignment_v1.docx');
    try{App\Service\DocumentGeneratorService::seedDefaultTemplates($pdo,$cid,0,'system');$eq(count($rows()),8,'B all except missing waybill visible');$eq(count($warnings()),1,'B warning');$renderSaved('vehicle_lease_crew');$renderSaved('client_invoice');$renderSaved('client_act');}finally{$restore($s);}
    echo "SEED_CASE_B=PASS\n";

    $reset();
    $userTemplate('waybill_driver_assignment','Пользовательский waybill','waybill_driver_assignment_v1.docx');
    $s=$stash('contract_vehicle_lease_crew_v9.docx');
    try{App\Service\DocumentGeneratorService::seedDefaultTemplates($pdo,$cid,0,'system');$eq(count($rows()),8,'C all except missing carrier contract visible');$eq(count($warnings()),1,'C warning');$renderSaved('waybill_driver_assignment');$renderSaved('client_sublease_crew');$renderSaved('client_invoice');$renderSaved('client_act');}finally{$restore($s);}
    echo "SEED_CASE_C=PASS\n";

    $reset();
    $userTemplate('vehicle_lease_crew','Пользовательский договор','contract_vehicle_lease_crew_v1.docx');
    $s=$stash('waybill_driver_assignment_v1.docx');
    $fake=$root.'/resources/document-templates/waybill_driver_assignment_v1.docx.b64.part001';
    file_put_contents($fake,base64_encode('bad sha'));
    try{App\Service\DocumentGeneratorService::seedDefaultTemplates($pdo,$cid,0,'system');$eq(count($rows()),8,'D all except corrupt waybill visible');$eq(count($warnings()),1,'D warning');$ok(str_contains((string)$warnings()[0]['message'],'SHA-256 mismatch'),'D SHA fail closed');$renderSaved('vehicle_lease_crew');$renderSaved('client_invoice');$renderSaved('client_act');}finally{@unlink($fake);$restore($s);}
    echo "SEED_CASE_D=PASS\n";

    $reset();
    $userTemplate('vehicle_lease_crew','Пользовательский договор','contract_vehicle_lease_crew_v1.docx');
    App\Service\DocumentGeneratorService::seedDefaultTemplates($pdo,$cid,0,'system');
    $eq((int)$pdo->query("SELECT COUNT(*) FROM document_templates WHERE document_type='vehicle_lease_crew'")->fetchColumn(),1,'E no duplicate');
    echo "SEED_CASE_E=PASS\n";

    $reset();
    $s=$stash('waybill_driver_assignment_v1.docx');
    try{App\Service\DocumentGeneratorService::seedDefaultTemplates($pdo,$cid,0,'system');$eq((int)$pdo->query("SELECT COUNT(*) FROM document_templates WHERE document_type='waybill_driver_assignment'")->fetchColumn(),0,'F missing waybill');$eq(count($warnings()),1,'F warning while missing');}finally{$restore($s);}
    App\Service\DocumentGeneratorService::seedDefaultTemplates($pdo,$cid,0,'system');
    $eq((int)$pdo->query("SELECT COUNT(*) FROM document_templates WHERE document_type='waybill_driver_assignment'")->fetchColumn(),1,'F recovered');$eq(count($warnings()),0,'F warnings cleared');
    echo "SEED_CASE_F=PASS\n";

    $reset();
    $s=$stash('client_act_v1.docx');
    try{App\Service\DocumentGeneratorService::seedDefaultTemplates($pdo,$cid,0,'system');$eq((int)$pdo->query("SELECT COUNT(*) FROM document_templates WHERE document_type='client_act'")->fetchColumn(),0,'G missing client act');$eq(count($rows()),8,'G other built-ins survive');$eq(count($warnings()),1,'G warning while missing client act');}finally{$restore($s);}
    App\Service\DocumentGeneratorService::seedDefaultTemplates($pdo,$cid,0,'system');
    $eq((int)$pdo->query("SELECT COUNT(*) FROM document_templates WHERE document_type='client_act'")->fetchColumn(),1,'G client act recovered');$eq(count($rows()),9,'G all built-ins recovered');$eq(count($warnings()),0,'G warnings cleared');$renderSaved('client_act');
    echo "SEED_CASE_G=PASS\n";

    $reset();
    $userTemplate('vehicle_lease_crew','Договор аренды','contract_vehicle_lease_crew_v3.docx');
    $userTemplate('carrier_sublease_crew','Договор субаренды','contract_carrier_sublease_crew_v1.docx');
    App\Service\DocumentGeneratorService::seedDefaultTemplates($pdo,$cid,0,'system');
    $active=[];foreach($rows() as $row)$active[$row['document_type']]=$row;
    $eq((string)$active['vehicle_lease_crew']['sha256'],'e83efd9117faf61357fb4dfb9e105c4a989f7bbed9fe062d84355c18dc0df79f','H lease upgraded');
    $eq((string)$active['carrier_sublease_crew']['sha256'],'b3f1068c42b9a9659f4774934eeb8924cf20c58e83e44a9298df98f3e35c0be7','H sublease upgraded');
    $eq((int)$pdo->query("SELECT COUNT(*) FROM document_template_versions")->fetchColumn(),11,'H old versions retained');
    App\Service\DocumentGeneratorService::seedDefaultTemplates($pdo,$cid,0,'system');
    $eq((int)$pdo->query("SELECT COUNT(*) FROM document_template_versions")->fetchColumn(),11,'H idempotent');
    echo "SEED_CASE_H=PASS\n";

    $reset();
    $userTemplate('vehicle_lease_crew','Договор аренды','contract_vehicle_lease_crew_v4.docx');
    App\Service\DocumentGeneratorService::seedDefaultTemplates($pdo,$cid,0,'system');
    $active=[];foreach($rows() as $row)$active[$row['document_type']]=$row;
    $eq((string)$active['vehicle_lease_crew']['sha256'],'e83efd9117faf61357fb4dfb9e105c4a989f7bbed9fe062d84355c18dc0df79f','I v4 lease upgraded to v9');
    $eq((int)$pdo->query("SELECT COUNT(*) FROM document_template_versions WHERE template_id=".(int)$active['vehicle_lease_crew']['id'])->fetchColumn(),2,'I prior v4 retained');
    echo "SEED_CASE_I=PASS\nDOCUMENT_GENERATOR_BUILTIN_SEED_RESILIENCE=PASS\n";
}finally{$rm($store);try{if($ownsDatabase)$admin->exec("DROP DATABASE IF EXISTS $qd");elseif(isset($pdo))cleanCompanyPoolDatabase($pdo);}catch(Throwable){}}
