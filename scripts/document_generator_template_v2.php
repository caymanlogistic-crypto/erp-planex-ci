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
require_once $root.'/app/Service/DocumentService.php';
require_once $root.'/app/Service/DocumentTemplateService.php';
require_once $root.'/app/Service/DocumentGeneratorService.php';

use App\Service\DocumentGeneratorService;
use App\Service\DocumentService;
use App\Service\DocumentTemplateService;

$centralCfg=$config['database'];
$central=new PDO('mysql:host='.$centralCfg['host'].';port='.($centralCfg['port']??3306).';dbname='.$centralCfg['database'].';charset=utf8mb4',$centralCfg['username'],$centralCfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$companies=$central->query("SELECT id,name,status,db_identifier,db_host,db_port,db_username,db_password FROM companies WHERE status='active' AND db_identifier IS NOT NULL AND db_identifier<>'' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
if($companies===[])throw new RuntimeException('No active tenant databases found');

$inspectDocx=static function(string $path):array{
    $validation=DocumentTemplateService::validateDocxFile($path);
    if(!($validation['ok']??false))throw new RuntimeException('Active template DOCX invalid: '.implode('; ',$validation['errors']??[]));
    $zip=new ZipArchive();if($zip->open($path,ZipArchive::RDONLY)!==true)throw new RuntimeException('Cannot open active template DOCX');
    $texts=[];try{
        for($i=0;$i<$zip->numFiles;$i++){
            $st=$zip->statIndex($i);$entry=is_array($st)?(string)($st['name']??''):'';
            if($entry==='word/document.xml'||preg_match('~^word/(?:header|footer)\d+\.xml$~',$entry)){$raw=$zip->getFromName($entry);if(is_string($raw))$texts[] = DocumentTemplateService::visibleText($raw);}
        }
    }finally{$zip->close();}
    $visible=implode("\n",$texts);$ph=array_values(array_filter($validation['placeholders']??[],'is_string'));
    return ['sha256'=>(string)($validation['sha256']??''),'placeholders'=>$ph,'has_bank_placeholder'=>in_array('executor.bank_inn',$ph,true),'has_bank_label'=>preg_match('/ИНН\s+БАНКА/ui',$visible)===1];
};
$transform=static function(string $source):string{
    $tmp=tempnam(sys_get_temp_dir(),'docgen_tpl_v2_');if($tmp===false)throw new RuntimeException('Cannot create temp template');$out=$tmp.'.docx';@unlink($tmp);if(!copy($source,$out))throw new RuntimeException('Cannot copy template');
    $zip=new ZipArchive();if($zip->open($out)!==true){@unlink($out);throw new RuntimeException('Cannot open temp DOCX');}
    $removed=0;try{
        $targets=['word/document.xml'];for($i=0;$i<$zip->numFiles;$i++){$st=$zip->statIndex($i);$entry=is_array($st)?(string)($st['name']??''):'';if(preg_match('~^word/(?:header|footer)\d+\.xml$~',$entry))$targets[]=$entry;}
        foreach(array_values(array_unique($targets))as$entry){$xml=$zip->getFromName($entry);if(!is_string($xml))continue;$xml=preg_replace_callback('~<w:p\b[^>]*>.*?</w:p>~s',static function(array$m)use(&$removed):string{$visible=DocumentTemplateService::visibleText($m[0]);if(str_contains($visible,'{{executor.bank_inn}}')||preg_match('/ИНН\s+БАНКА/ui',$visible)){++$removed;return '';}return$m[0];},$xml)??$xml;if(!$zip->addFromString($entry,$xml))throw new RuntimeException('Cannot write transformed DOCX');}
    }finally{$zip->close();}
    if($removed<1){@unlink($out);throw new RuntimeException('Bank INN paragraph not found for transformation');}
    $check=DocumentTemplateService::validateDocxFile($out);if(!($check['ok']??false)){@unlink($out);throw new RuntimeException('Transformed template invalid: '.implode('; ',$check['errors']??[]));}
    if(in_array('executor.bank_inn',$check['placeholders']??[],true)){@unlink($out);throw new RuntimeException('Transformed template still contains executor.bank_inn');}
    return$out;
};

$result=['action'=>$action,'central_db'=>(string)$central->query('SELECT DATABASE()')->fetchColumn(),'tenants'=>[]];
foreach($companies as$company){
    $cfg=companyDatabaseConfig($config,$company);$pdo=new PDO('mysql:host='.$cfg['host'].';port='.($cfg['port']??3306).';dbname='.$cfg['database'].';charset=utf8mb4',$cfg['username'],$cfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    if((string)$pdo->query('SELECT DATABASE()')->fetchColumn()!==(string)$company['db_identifier'])throw new RuntimeException('Unexpected tenant DB for company '.$company['id']);
    $rows=$pdo->query("SELECT t.id template_id,t.name,t.active_version_id,v.version_no,v.original_name,v.stored_path,v.sha256 FROM document_templates t JOIN document_template_versions v ON v.id=t.active_version_id WHERE t.document_type='vehicle_lease_crew' AND t.status='active' ORDER BY t.id")->fetchAll(PDO::FETCH_ASSOC);
    $tenant=['company_id'=>(int)$company['id'],'db'=>(string)$company['db_identifier'],'templates'=>[]];
    foreach($rows as$row){
        $path=DocumentService::resolveStoredPath((int)$company['id'],(string)$row['stored_path']);if($path===null)throw new RuntimeException('Active contract template file missing for company '.$company['id']);$actual=hash_file('sha256',$path)?:'';if($actual===''||!hash_equals((string)$row['sha256'],$actual))throw new RuntimeException('Active contract template SHA mismatch for company '.$company['id']);
        $before=$inspectDocx($path);$needs=(bool)($before['has_bank_placeholder']||$before['has_bank_label']);$entry=['template_id'=>(int)$row['template_id'],'version_no'=>(int)$row['version_no'],'version_id'=>(int)$row['active_version_id'],'sha256'=>$actual,'needs_update'=>$needs?1:0,'has_bank_placeholder'=>$before['has_bank_placeholder']?1:0,'has_bank_label'=>$before['has_bank_label']?1:0];
        if($action==='apply'&&$needs){
            $db=(string)$pdo->query('SELECT DATABASE()')->fetchColumn();$lockName='planex_docgen_tpl_v2_'.substr(hash('sha256',$db.':'.$row['template_id']),0,24);$lock=$pdo->prepare('SELECT GET_LOCK(?,10)');$lock->execute([$lockName]);if((int)$lock->fetchColumn()!==1)throw new RuntimeException('Cannot lock template '.$row['template_id']);
            $tmp=null;try{
                $fresh=DocumentGeneratorService::getTemplateVersion($pdo,(int)$row['template_id'],null,true);if(!$fresh)throw new RuntimeException('Template changed before apply');if((int)$fresh['version_id']!==(int)$row['active_version_id']||!hash_equals((string)$fresh['sha256'],$actual))throw new RuntimeException('Active template changed concurrently');
                $tmp=$transform($path);$newVersionId=DocumentGeneratorService::createTemplateVersionFromFile($pdo,(int)$company['id'],(int)$row['template_id'],'vehicle_lease_crew',(string)$row['name'],$tmp,'contract_vehicle_lease_crew_without_bank_inn.docx',0,'system');$after=DocumentGeneratorService::getTemplateVersion($pdo,(int)$row['template_id'],null,true);if(!$after||(int)$after['version_id']!==$newVersionId)throw new RuntimeException('New template version not active');if((int)$after['version_no']!==(int)$row['version_no']+1)throw new RuntimeException('Template version did not increment exactly once');$afterPath=DocumentService::resolveStoredPath((int)$company['id'],(string)$after['stored_path']);if($afterPath===null)throw new RuntimeException('New template file unavailable');$ai=$inspectDocx($afterPath);if($ai['has_bank_placeholder']||$ai['has_bank_label'])throw new RuntimeException('New active template still contains bank INN');$old=DocumentGeneratorService::getTemplateVersion($pdo,(int)$row['template_id'],(int)$row['active_version_id'],false);if(!$old||!hash_equals((string)$old['sha256'],$actual))throw new RuntimeException('Old immutable template version changed');$entry['new_version_id']=$newVersionId;$entry['new_version_no']=(int)$after['version_no'];$entry['new_sha256']=(string)$after['sha256'];$entry['updated']=1;
            }finally{if(is_string($tmp))@unlink($tmp);try{$rel=$pdo->prepare('SELECT RELEASE_LOCK(?)');$rel->execute([$lockName]);}catch(Throwable){}}
        }else{$entry['updated']=0;}
        $tenant['templates'][]=$entry;
    }
    $result['tenants'][]=$tenant;
}
echo 'DOCGEN_TEMPLATE_V2_SNAPSHOT='.json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
echo $action==='apply'?"DOCGEN_TEMPLATE_V2_APPLY_OK\n":"DOCGEN_TEMPLATE_V2_READ_ONLY_OK\n";
