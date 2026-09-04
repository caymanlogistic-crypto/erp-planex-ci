<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'cgi-fcgi') { fwrite(STDERR,"CLI/CGI only.\n"); exit(1); }
$root=rtrim((string)(getenv('PLANEX_ROOT')?:($argv[1]??'')),'/');
$action=(string)(getenv('PLANEX_TEMPLATE_ACTION')?:'snapshot');
$expectedV3Sha=strtolower(trim((string)(getenv('PLANEX_EXPECTED_V3_SHA')?:'')));
if(!in_array($action,['snapshot','apply'],true))throw new RuntimeException('PLANEX_TEMPLATE_ACTION must be snapshot or apply');
if($action==='apply'&&!preg_match('/^[0-9a-f]{64}$/',$expectedV3Sha))throw new RuntimeException('PLANEX_EXPECTED_V3_SHA is required for apply');
if($expectedV3Sha!==''&&!preg_match('/^[0-9a-f]{64}$/',$expectedV3Sha))throw new RuntimeException('PLANEX_EXPECTED_V3_SHA invalid');
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

// Exact immutable production-v2 created by the guarded 2026-09-03 rollout (run 33749725857).
const DOCGEN_PRODUCTION_V2_SHA='7b3b86b00f49521a95a41a107dcdaf63e969c36264befcac372e83c2d53a8d56';
// Repo built-in v3 remains the canonical default for new tenants; production keeps its existing document styling.
const DOCGEN_BUILTIN_V3_SHA='87584e74a9cfd6bc150fc5ea2c4f7b7045e8ff4b2fba7a5fb80b03ccfc97dff2';

$inspectDocx=static function(string $path):array{
    $validation=DocumentTemplateService::validateDocxFile($path);
    if(!($validation['ok']??false))throw new RuntimeException('Contract DOCX invalid: '.implode('; ',$validation['errors']??[]));
    $ph=array_values(array_filter($validation['placeholders']??[],'is_string'));
    return [
        'sha256'=>(string)($validation['sha256']??''),
        'placeholders'=>$ph,
        'has_vehicle_vin'=>in_array('vehicle.vin',$ph,true),
        'has_trailer_display'=>in_array('trailer.display',$ph,true),
        'has_request_contacts'=>in_array('request.contacts',$ph,true),
        'has_bank_placeholder'=>in_array('executor.bank_inn',$ph,true),
    ];
};

// Transform a copy of the proven production-v2. Only the vehicle identity paragraph is rewritten;
// all other Word parts, paragraph properties and the first run formatting are preserved.
$transformProductionV2=static function(string $source)use($inspectDocx):string{
    $before=$inspectDocx($source);
    if(!hash_equals(DOCGEN_PRODUCTION_V2_SHA,(string)$before['sha256']))throw new RuntimeException('Production v2 source SHA mismatch');
    if($before['has_bank_placeholder'])throw new RuntimeException('Production v2 unexpectedly contains bank placeholder');
    if(!$before['has_request_contacts'])throw new RuntimeException('Production v2 missing request.contacts');

    $tmp=tempnam(sys_get_temp_dir(),'docgen_tpl_v3_');if($tmp===false)throw new RuntimeException('Cannot create temp template');
    $out=$tmp.'.docx';@unlink($tmp);if(!copy($source,$out))throw new RuntimeException('Cannot copy production v2 template');
    $zip=new ZipArchive();if($zip->open($out)!==true){@unlink($out);throw new RuntimeException('Cannot open production v2 copy');}
    $matched=0;
    try{
        $targets=['word/document.xml'];
        for($i=0;$i<$zip->numFiles;$i++){
            $st=$zip->statIndex($i);$entry=is_array($st)?(string)($st['name']??''):'';
            if(preg_match('~^word/(?:header|footer)\d+\.xml$~',$entry))$targets[]=$entry;
        }
        foreach(array_values(array_unique($targets))as$entry){
            $xml=$zip->getFromName($entry);if(!is_string($xml))continue;
            $xml=preg_replace_callback('~<w:p\b[^>]*>.*?</w:p>~s',static function(array$m)use(&$matched):string{
                $visible=DocumentTemplateService::visibleText($m[0]);
                if(!str_contains($visible,'{{vehicle.model}}')||!str_contains($visible,'{{vehicle.plate}}'))return$m[0];
                if(str_contains($visible,'{{vehicle.vin}}')||str_contains($visible,'{{trailer.display}}'))throw new RuntimeException('Vehicle paragraph already contains v3 placeholders');
                ++$matched;
                $replacement='{{vehicle.label}}: {{vehicle.model}}, гос. номер {{vehicle.plate}}, VIN {{vehicle.vin}}{{trailer.display}}';
                $escaped=htmlspecialchars($replacement,ENT_QUOTES|ENT_XML1,'UTF-8');
                $seen=false;
                $paragraph=preg_replace_callback('~(<w:t\b[^>]*>).*?(</w:t>)~s',static function(array$t)use(&$seen,$escaped):string{
                    if(!$seen){$seen=true;return$t[1].$escaped.$t[2];}
                    return$t[1].$t[2];
                },$m[0])??$m[0];
                if(!$seen)throw new RuntimeException('Vehicle paragraph has no w:t node');
                return$paragraph;
            },$xml)??$xml;
            if(!$zip->addFromString($entry,$xml))throw new RuntimeException('Cannot write transformed vehicle paragraph');
        }
    }finally{$zip->close();}
    if($matched!==1){@unlink($out);throw new RuntimeException('Expected exactly one vehicle paragraph, found '.$matched);}
    $after=$inspectDocx($out);
    foreach(['vehicle.vin','trailer.display','request.contacts']as$required){if(!in_array($required,$after['placeholders'],true)){@unlink($out);throw new RuntimeException('Transformed v3 missing '.$required);}}
    if($after['has_bank_placeholder']){@unlink($out);throw new RuntimeException('Transformed v3 reintroduced bank placeholder');}
    return$out;
};

$centralCfg=$config['database'];
$central=new PDO('mysql:host='.$centralCfg['host'].';port='.($centralCfg['port']??3306).';dbname='.$centralCfg['database'].';charset=utf8mb4',$centralCfg['username'],$centralCfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$companies=$central->query("SELECT id,name,status,db_identifier,db_host,db_port,db_username,db_password FROM companies WHERE status='active' AND db_identifier IS NOT NULL AND db_identifier<>'' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
if($companies===[])throw new RuntimeException('No active tenant databases found');
$result=['action'=>$action,'central_db'=>(string)$central->query('SELECT DATABASE()')->fetchColumn(),'tenants'=>[]];
$targets=[];

foreach($companies as$company){
    $cfg=companyDatabaseConfig($config,$company);$pdo=new PDO('mysql:host='.$cfg['host'].';port='.($cfg['port']??3306).';dbname='.$cfg['database'].';charset=utf8mb4',$cfg['username'],$cfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    if((string)$pdo->query('SELECT DATABASE()')->fetchColumn()!==(string)$company['db_identifier'])throw new RuntimeException('Unexpected tenant DB for company '.$company['id']);
    $rows=$pdo->query("SELECT t.id template_id,t.name,t.active_version_id,v.version_no,v.original_name,v.stored_path,v.sha256,v.placeholders_json FROM document_templates t JOIN document_template_versions v ON v.id=t.active_version_id WHERE t.document_type='vehicle_lease_crew' AND t.status='active' ORDER BY t.id")->fetchAll(PDO::FETCH_ASSOC);
    $tenantIndex=count($result['tenants']);
    $tenant=['company_id'=>(int)$company['id'],'db'=>(string)$company['db_identifier'],'templates'=>[]];
    foreach($rows as$row){
        $path=DocumentService::resolveStoredPath((int)$company['id'],(string)$row['stored_path']);if($path===null)throw new RuntimeException('Active template file missing for company '.$company['id']);
        $actual=hash_file('sha256',$path)?:'';if($actual===''||!hash_equals((string)$row['sha256'],$actual))throw new RuntimeException('Active template SHA mismatch for company '.$company['id']);
        $ph=json_decode((string)$row['placeholders_json'],true);$ph=is_array($ph)?array_values(array_filter($ph,'is_string')):[];
        $hasVin=in_array('vehicle.vin',$ph,true);$hasTrailer=in_array('trailer.display',$ph,true);$hasContacts=in_array('request.contacts',$ph,true);
        $state='unexpected';$plannedSha='';$candidate=null;
        if(hash_equals(DOCGEN_PRODUCTION_V2_SHA,$actual)){
            $state='production_v2';
            $candidate=$transformProductionV2($path);$plannedSha=hash_file('sha256',$candidate)?:'';
            if(!preg_match('/^[0-9a-f]{64}$/',$plannedSha)){@unlink($candidate);throw new RuntimeException('Cannot calculate planned production v3 SHA');}
        }elseif($hasVin&&$hasTrailer&&$hasContacts){
            $previous=$pdo->prepare('SELECT sha256 FROM document_template_versions WHERE template_id=? AND version_no=? LIMIT 1');
            $previous->execute([(int)$row['template_id'],(int)$row['version_no']-1]);
            $previousSha=(string)($previous->fetchColumn()?:'');
            if((int)$row['version_no']>=3&&hash_equals(DOCGEN_PRODUCTION_V2_SHA,$previousSha)&&($expectedV3Sha===''||hash_equals($expectedV3Sha,$actual)))$state='production_v3';
        }
        if($state==='unexpected'){if(is_string($candidate))@unlink($candidate);throw new RuntimeException('Unexpected/custom active vehicle-lease template SHA for company '.$company['id'].' template '.$row['template_id']);}
        $entry=['template_id'=>(int)$row['template_id'],'version_id'=>(int)$row['active_version_id'],'version_no'=>(int)$row['version_no'],'sha256'=>$actual,'state'=>$state,'needs_update'=>$state==='production_v2'?1:0,'planned_sha256'=>$plannedSha,'has_vehicle_vin'=>$hasVin?1:0,'has_trailer_display'=>$hasTrailer?1:0,'has_request_contacts'=>$hasContacts?1:0,'updated'=>0];
        $rowIndex=count($tenant['templates']);$tenant['templates'][]=$entry;
        if($state==='production_v2')$targets[]=['pdo'=>$pdo,'company'=>$company,'row'=>$row,'path'=>$path,'candidate'=>$candidate,'planned_sha'=>$plannedSha,'tenant_index'=>$tenantIndex,'row_index'=>$rowIndex];
        elseif(is_string($candidate))@unlink($candidate);
    }
    $result['tenants'][]=$tenant;
}

if($action==='apply'){
    if(count($targets)!==1){foreach($targets as$t)if(is_string($t['candidate']??null))@unlink($t['candidate']);throw new RuntimeException('Apply requires exactly one proven production-v2 template; found '.count($targets));}
    $target=$targets[0];
    if(!hash_equals($expectedV3Sha,(string)$target['planned_sha'])){@unlink((string)$target['candidate']);throw new RuntimeException('Planned production v3 SHA changed before apply');}
    /** @var PDO $pdo */$pdo=$target['pdo'];$row=$target['row'];$company=$target['company'];$candidate=(string)$target['candidate'];
    $db=(string)$pdo->query('SELECT DATABASE()')->fetchColumn();$lockName='planex_docgen_tpl_v3_'.substr(hash('sha256',$db.':'.$row['template_id']),0,24);$lock=$pdo->prepare('SELECT GET_LOCK(?,10)');$lock->execute([$lockName]);if((int)$lock->fetchColumn()!==1){@unlink($candidate);throw new RuntimeException('Cannot lock template '.$row['template_id']);}
    try{
        $fresh=DocumentGeneratorService::getTemplateVersion($pdo,(int)$row['template_id'],null,true);if(!$fresh)throw new RuntimeException('Template changed before apply');
        if((int)$fresh['version_id']!==(int)$row['active_version_id']||!hash_equals(DOCGEN_PRODUCTION_V2_SHA,(string)$fresh['sha256']))throw new RuntimeException('Active template changed concurrently');
        if(!hash_equals($expectedV3Sha,hash_file('sha256',$candidate)?:''))throw new RuntimeException('Candidate v3 SHA changed');
        $newVersionId=DocumentGeneratorService::createTemplateVersionFromFile($pdo,(int)$company['id'],(int)$row['template_id'],'vehicle_lease_crew',(string)$row['name'],$candidate,'contract_vehicle_lease_crew_v3.docx',0,'system');
        $after=DocumentGeneratorService::getTemplateVersion($pdo,(int)$row['template_id'],null,true);if(!$after||(int)$after['version_id']!==$newVersionId)throw new RuntimeException('New production v3 not active');
        if((int)$after['version_no']!==(int)$row['version_no']+1)throw new RuntimeException('Template version did not increment exactly once');
        if(!hash_equals($expectedV3Sha,(string)$after['sha256']))throw new RuntimeException('Active production v3 SHA mismatch after apply');
        $old=DocumentGeneratorService::getTemplateVersion($pdo,(int)$row['template_id'],(int)$row['active_version_id'],false);if(!$old||!hash_equals(DOCGEN_PRODUCTION_V2_SHA,(string)$old['sha256']))throw new RuntimeException('Old immutable production v2 changed');
        $ti=(int)$target['tenant_index'];$ri=(int)$target['row_index'];
        $result['tenants'][$ti]['templates'][$ri]['updated']=1;$result['tenants'][$ti]['templates'][$ri]['new_version_id']=$newVersionId;$result['tenants'][$ti]['templates'][$ri]['new_version_no']=(int)$after['version_no'];$result['tenants'][$ti]['templates'][$ri]['new_sha256']=(string)$after['sha256'];
    }finally{@unlink($candidate);try{$rel=$pdo->prepare('SELECT RELEASE_LOCK(?)');$rel->execute([$lockName]);}catch(Throwable){}}
}else{
    foreach($targets as$t)if(is_string($t['candidate']??null))@unlink($t['candidate']);
}

echo 'DOCGEN_TEMPLATE_V3_SNAPSHOT='.json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
echo $action==='apply'?"DOCGEN_TEMPLATE_V3_APPLY_OK\n":"DOCGEN_TEMPLATE_V3_READ_ONLY_OK\n";
