<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root=dirname(__DIR__);
$workflowPath=$root.'/.github/workflows/erpv2_controlled_deploy.yml';
$workflow=@file_get_contents($workflowPath);
if(!is_string($workflow)||$workflow==='')throw new RuntimeException('Controlled deploy workflow is unavailable.');
$archiveNeedle='git archive --format=tar --output=erpv2_source.tar "$SOURCE_SHA" -- app bootstrap config database public scripts tools tests resources';
if(!str_contains($workflow,$archiveNeedle))throw new RuntimeException('Controlled deploy artifact roots must include canonical resources.');
if(!str_contains($workflow,'php tests/document_generator_deploy_artifact_test.php "$artifact"'))throw new RuntimeException('Controlled deploy must execute deploy artifact contract test.');

$archive=(string)($argv[1]??'');
$owned=false;
$tmpBase='';
if($archive===''){
    $git=trim((string)shell_exec('command -v git 2>/dev/null'));
    $gzip=trim((string)shell_exec('command -v gzip 2>/dev/null'));
    if($git===''||$gzip==='')throw new RuntimeException('git and gzip are required to build the production-like artifact contract.');
    $sha=trim((string)shell_exec('git -C '.escapeshellarg($root).' rev-parse HEAD 2>/dev/null'));
    if(!preg_match('/^[0-9a-f]{40}$/',$sha))throw new RuntimeException('Unable to resolve current git HEAD for artifact contract.');
    $tmpBase=sys_get_temp_dir().'/planex_erpv2_artifact_'.getmypid().'_'.bin2hex(random_bytes(4));
    if(!mkdir($tmpBase,0700,true)&&!is_dir($tmpBase))throw new RuntimeException('Unable to create artifact build directory.');
    $tarPath=$tmpBase.'/erpv2_source.tar';
    $cmd='git -C '.escapeshellarg($root).' archive --format=tar --output='.escapeshellarg($tarPath).' '.escapeshellarg($sha).' -- app bootstrap config database public scripts tools tests resources';
    exec($cmd,$ignored,$rc);if($rc!==0||!is_file($tarPath))throw new RuntimeException('Unable to build production-like deploy tar.');
    exec($gzip.' -n '.escapeshellarg($tarPath),$ignored,$rc);if($rc!==0)throw new RuntimeException('Unable to gzip production-like deploy tar.');
    $archive=$tarPath.'.gz';$owned=true;
}
if(!is_file($archive))throw new RuntimeException('Deploy artifact path is required.');
$tar=trim((string)shell_exec('command -v tar 2>/dev/null'));
if($tar==='')throw new RuntimeException('tar executable is required.');
$list=[];exec($tar.' -tzf '.escapeshellarg($archive),$list,$rc);
if($rc!==0||$list===[])throw new RuntimeException('Unable to list deploy artifact.');
$forbidden='~(^|/)(?:\.git|\.env(?:[^/]*)?|storage|uploads|logs|tmp|screenshots|node_modules|vendor)(?:/|$)|(^|/)(?:cookies?|reports)(?:/|$)~';
foreach($list as$entry){if(preg_match($forbidden,$entry))throw new RuntimeException('Forbidden runtime/secret path in deploy artifact: '.$entry);}
$required=[
'contract_vehicle_lease_crew_v1.docx'=>'0e3e7a3ca4d81face23fa070bcabffacc86636d08affdda91faa3c725d4e8adc',
'waybill_driver_assignment_v1.docx'=>'5a45e855c6fb47dbc96e6d8b9185d7032b5ca86a9baefbb78fdc6419749f73ab',
];
$tmp=sys_get_temp_dir().'/planex_docgen_artifact_'.getmypid().'_'.bin2hex(random_bytes(4));
if(!mkdir($tmp,0700,true)&&!is_dir($tmp))throw new RuntimeException('Unable to create artifact temp directory.');
$rm=static function(string $path)use(&$rm):void{if(!file_exists($path))return;if(!is_dir($path)||is_link($path)){@unlink($path);return;}foreach(array_diff(scandir($path)?:[],['.','..'])as$name)$rm($path.DIRECTORY_SEPARATOR.$name);@rmdir($path);};
try{
    exec($tar.' -xzf '.escapeshellarg($archive).' -C '.escapeshellarg($tmp),$ignored,$rc);if($rc!==0)throw new RuntimeException('Unable to extract deploy artifact.');
    foreach($required as$name=>$expectedSha){
        $parts=glob($tmp.'/resources/document-templates/'.$name.'.b64.part*')?:[];sort($parts,SORT_STRING);
        if($parts===[])throw new RuntimeException('Missing built-in template parts in deploy artifact: '.$name);
        $bytes='';foreach($parts as$part){$encoded=preg_replace('/\s+/','',(string)file_get_contents($part))??'';$decoded=base64_decode($encoded,true);if(!is_string($decoded)||$decoded==='')throw new RuntimeException('Invalid base64 part in deploy artifact: '.basename($part));$bytes.=$decoded;}
        $actual=hash('sha256',$bytes);if(!hash_equals($expectedSha,$actual))throw new RuntimeException('Built-in template SHA mismatch in deploy artifact: '.$name);
        printf("DEPLOY_ARTIFACT_TEMPLATE=%s sha256=%s parts=%d\n",$name,$actual,count($parts));
    }
    echo "DOCUMENT_GENERATOR_DEPLOY_ARTIFACT=PASS\n";
}finally{$rm($tmp);if($owned&&$tmpBase!=='')$rm($tmpBase);}
