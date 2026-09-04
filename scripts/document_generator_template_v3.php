<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'cgi-fcgi') { fwrite(STDERR,"CLI/CGI only.\n"); exit(1); }
$root=rtrim((string)(getenv('PLANEX_ROOT')?:($argv[1]??'')),'/');
$action=(string)(getenv('PLANEX_TEMPLATE_ACTION')?:'snapshot');
if(!in_array($action,['snapshot','apply'],true))throw new RuntimeException('PLANEX_TEMPLATE_ACTION must be snapshot or apply');
if($root===''||!is_file($root.'/.env')||!is_file($root.'/bootstrap/app.php'))throw new RuntimeException('PLANEX_ROOT invalid');
$config=require $root.'/bootstrap/app.php';
require_once $root.'/app/Support/crypto_helper.php';
require_once $root.'/app/Support/company_database.php';
require_once $root.'/app/Service/AccessControlService.php';
require_once $root.'/app/Service/DocumentService.php';
require_once $root.'/app/Service/DocumentTemplateService.php';
require_once $root.'/app/Service/FeoRequestInteractionService.php';
require_once $root.'/app/Service/DocumentGeneratorService.php';

use App\Service\DocumentGeneratorService;
use App\Service\DocumentService;
use App\Service\DocumentTemplateService;

const DOCGEN_V2_SHA='7b79a9ac558af1633b1ed2d6414bef1efe0222c67a543d1b4a8e802e6267a1fa';
const DOCGEN_V3_SHA='87584e74a9cfd6bc150fc5ea2c4f7b7045e8ff4b2fba7a5fb80b03ccfc97dff2';

$materializeV3=static function()use($root):string{
    $parts=glob($root.'/resources/document-templates/contract_vehicle_lease_crew_v3.docx.b64.part*')?:[];sort($parts,SORT_STRING);
    if($parts===[])throw new RuntimeException('Built-in contract v3 parts missing');
    $bytes='';foreach($parts as$part){$encoded=preg_replace('/\s+/','',(string)file_get_contents($part))??'';$decoded=base64_decode($encoded,true);if(!is_string($decoded)||$decoded==='')throw new RuntimeException('Built-in contract v3 part invalid');$bytes.=$decoded;}
    if(!hash_equals(DOCGEN_V3_SHA,hash('sha256',$bytes)))throw new RuntimeException('Built-in contract v3 SHA mismatch');
    $tmp=tempnam(sys_get_temp_dir(),'docgen_v3_');if($tmp===false)throw new RuntimeException('Cannot allocate temp v3');$docx=$tmp.'.docx';@unlink($tmp);if(file_put_contents($docx,$bytes,LOCK_EX)!==strlen($bytes)){@unlink($docx);throw new RuntimeException('Cannot materialize v3');}
    $v=DocumentTemplateService::validateDocxFile($docx);if(!($v['ok']??false)){@unlink($docx);throw new RuntimeException('Built-in v3 invalid: '.implode('; ',$v['errors']??[]));}
    $ph=array_values(array_filter($v['placeholders']??[],'is_string'));
    foreach(['vehicle.model','vehicle.plate','vehicle.vin','trailer.display','request.contacts']as$required)if(!in_array($required,$ph,true)){@unlink($docx);throw new RuntimeException('Built-in v3 missing '.$required);}
    return $docx;
};

$centralCfg=$config['database'];
$central=new PDO('mysql:host='.$centralCfg['host'].';port='.($centralCfg['port']??3306).';dbname='.$centralCfg['database'].';charset=utf8mb4',$centralCfg['username'],$centralCfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$companies=$central->query("SELECT id,name,status,db_identifier,db_host,db_port,db_username,db_password FROM companies WHERE status='active' AND db_identifier IS NOT NULL AND db_identifier<>'' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
if($companies===[])throw new RuntimeException('No active tenant databases found');
$result=['action'=>$action,'central_db'=>(string)$central->query('SELECT DATABASE()')->fetchColumn(),'tenants'=>[]];

foreach($companies as$company){
    $cfg=companyDatabaseConfig($config,$company);$pdo=new PDO('mysql:host='.$cfg['host'].';port='.($cfg['port']??3306).';dbname='.$cfg['database'].';charset=utf8mb4',$cfg['username'],$cfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    if((string)$pdo->query('SELECT DATABASE()')->fetchColumn()!==(string)$company['db_identifier'])throw new RuntimeException('Unexpected tenant DB for company '.$company['id']);
    $rows=$pdo->query("SELECT t.id template_id,t.name,t.active_version_id,v.version_no,v.original_name,v.stored_path,v.sha256,v.placeholders_json FROM document_templates t JOIN document_template_versions v ON v.id=t.active_version_id WHERE t.document_type='vehicle_lease_crew' AND t.status='active' ORDER BY t.id")->fetchAll(PDO::FETCH_ASSOC);
    if($rows===[])throw new RuntimeException('No active vehicle-lease contract template for company '.$company['id']);
    $tenant=['company_id'=>(int)$company['id'],'db'=>(string)$company['db_identifier'],'templates'=>[]];
    foreach($rows as$row){
        $path=DocumentService::resolveStoredPath((int)$company['id'],(string)$row['stored_path']);if($path===null)throw new RuntimeException('Active template file missing for company '.$company['id']);
        $actual=hash_file('sha256',$path)?:'';if($actual===''||!hash_equals((string)$row['sha256'],$actual))throw new RuntimeException('Active template SHA mismatch for company '.$company['id']);
        $state=hash_equals(DOCGEN_V3_SHA,$actual)?'v3':(hash_equals(DOCGEN_V2_SHA,$actual)?'v2':'unexpected');
        $ph=json_decode((string)$row['placeholders_json'],true);$ph=is_array($ph)?array_values(array_filter($ph,'is_string')):[];
        $entry=['template_id'=>(int)$row['template_id'],'version_id'=>(int)$row['active_version_id'],'version_no'=>(int)$row['version_no'],'sha256'=>$actual,'state'=>$state,'needs_update'=>$state==='v2'?1:0,'has_vehicle_vin'=>in_array('vehicle.vin',$ph,true)?1:0,'has_trailer_display'=>in_array('trailer.display',$ph,true)?1:0];
        if($state==='unexpected')throw new RuntimeException('Unexpected/custom active vehicle-lease template SHA for company '.$company['id'].' template '.$row['template_id']);
        if($action==='apply'&&$state==='v2'){
            $db=(string)$pdo->query('SELECT DATABASE()')->fetchColumn();$lockName='planex_docgen_tpl_v3_'.substr(hash('sha256',$db.':'.$row['template_id']),0,24);$lock=$pdo->prepare('SELECT GET_LOCK(?,10)');$lock->execute([$lockName]);if((int)$lock->fetchColumn()!==1)throw new RuntimeException('Cannot lock template '.$row['template_id']);
            $tmp=null;try{
                $fresh=DocumentGeneratorService::getTemplateVersion($pdo,(int)$row['template_id'],null,true);if(!$fresh)throw new RuntimeException('Template changed before apply');
                if((int)$fresh['version_id']!==(int)$row['active_version_id']||!hash_equals(DOCGEN_V2_SHA,(string)$fresh['sha256']))throw new RuntimeException('Active template changed concurrently');
                $tmp=$materializeV3();
                $newVersionId=DocumentGeneratorService::createTemplateVersionFromFile($pdo,(int)$company['id'],(int)$row['template_id'],'vehicle_lease_crew',(string)$row['name'],$tmp,'contract_vehicle_lease_crew_v3.docx',0,'system');
                $after=DocumentGeneratorService::getTemplateVersion($pdo,(int)$row['template_id'],null,true);if(!$after||(int)$after['version_id']!==$newVersionId)throw new RuntimeException('New v3 not active');
                if((int)$after['version_no']!==(int)$row['version_no']+1)throw new RuntimeException('Template version did not increment exactly once');
                if(!hash_equals(DOCGEN_V3_SHA,(string)$after['sha256']))throw new RuntimeException('Active v3 SHA mismatch after apply');
                $old=DocumentGeneratorService::getTemplateVersion($pdo,(int)$row['template_id'],(int)$row['active_version_id'],false);if(!$old||!hash_equals(DOCGEN_V2_SHA,(string)$old['sha256']))throw new RuntimeException('Old immutable v2 changed');
                $entry['updated']=1;$entry['new_version_id']=$newVersionId;$entry['new_version_no']=(int)$after['version_no'];$entry['new_sha256']=(string)$after['sha256'];
            }finally{if(is_string($tmp))@unlink($tmp);try{$rel=$pdo->prepare('SELECT RELEASE_LOCK(?)');$rel->execute([$lockName]);}catch(Throwable){}}
        }else{$entry['updated']=0;}
        $tenant['templates'][]=$entry;
    }
    $result['tenants'][]=$tenant;
}

echo 'DOCGEN_TEMPLATE_V3_SNAPSHOT='.json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
echo $action==='apply'?"DOCGEN_TEMPLATE_V3_APPLY_OK\n":"DOCGEN_TEMPLATE_V3_READ_ONLY_OK\n";
