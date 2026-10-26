<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root=dirname(__DIR__);
$workflowPath=$root.'/.github/workflows/erpv2_controlled_deploy.yml';
$workflow=@file_get_contents($workflowPath);
if(!is_string($workflow)||$workflow==='')throw new RuntimeException('Controlled deploy workflow is unavailable.');
$archiveNeedle='git archive --format=tar --output=erpv2_source.tar "$SOURCE_SHA" -- app bootstrap config database public scripts tools tests resources';
if(!str_contains($workflow,$archiveNeedle))throw new RuntimeException('Controlled deploy artifact roots must include canonical resources.');
if(!str_contains($workflow,'php tests/document_generator_deploy_artifact_test.php "$artifact"'))throw new RuntimeException('Controlled deploy must execute deploy artifact contract test.');

$archive=(string)($argv[1]??'');$owned=false;$tmpBase='';
$findExecutable=static function(string $name):string {
    $command=PHP_OS_FAMILY==='Windows'?'where '.$name.' 2>NUL':'command -v '.$name.' 2>/dev/null';
    return trim(explode("\n",(string)shell_exec($command))[0]);
};
if($archive===''){
    $git=$findExecutable('git');$gzip=$findExecutable('gzip');
    if($git===''||$gzip==='')throw new RuntimeException('git and gzip are required to build the production-like artifact contract.');
    $sha=trim((string)shell_exec('git -C '.escapeshellarg($root).' rev-parse HEAD 2>/dev/null'));
    if(!preg_match('/^[0-9a-f]{40}$/',$sha))throw new RuntimeException('Unable to resolve current git HEAD for artifact contract.');
    $tmpBase=sys_get_temp_dir().'/planex_erpv2_artifact_'.getmypid().'_'.bin2hex(random_bytes(4));if(!mkdir($tmpBase,0700,true)&&!is_dir($tmpBase))throw new RuntimeException('Unable to create artifact build directory.');
    $tarPath=$tmpBase.'/erpv2_source.tar';$cmd=escapeshellarg($git).' -C '.escapeshellarg($root).' archive --format=tar --output='.escapeshellarg($tarPath).' '.escapeshellarg($sha).' -- app bootstrap config database public scripts tools tests resources';exec($cmd,$ignored,$rc);if($rc!==0||!is_file($tarPath))throw new RuntimeException('Unable to build production-like deploy tar.');exec(escapeshellarg($gzip).' -n '.escapeshellarg($tarPath),$ignored,$rc);if($rc!==0)throw new RuntimeException('Unable to gzip production-like deploy tar.');$archive=$tarPath.'.gz';$owned=true;
}
if(!is_file($archive))throw new RuntimeException('Deploy artifact path is required.');$tar=PHP_OS_FAMILY==='Windows'?getenv('SystemRoot').'\\System32\\tar.exe':$findExecutable('tar');if($tar===''||!is_file($tar))throw new RuntimeException('tar executable is required.');$list=[];exec(escapeshellarg($tar).' -tzf '.escapeshellarg($archive),$list,$rc);if($rc!==0||$list===[])throw new RuntimeException('Unable to list deploy artifact.');
$forbidden='~(^|/)(?:\.git|\.env(?:[^/]*)?|storage|uploads|logs|tmp|screenshots|node_modules|vendor)(?:/|$)|(^|/)(?:cookies?|reports)(?:/|$)~';foreach($list as$entry){if(preg_match($forbidden,$entry))throw new RuntimeException('Forbidden runtime/secret path in deploy artifact: '.$entry);}
$required=[
'contract_vehicle_lease_crew_v1.docx'=>'0e3e7a3ca4d81face23fa070bcabffacc86636d08affdda91faa3c725d4e8adc',
'contract_vehicle_lease_crew_v2.docx'=>'7b79a9ac558af1633b1ed2d6414bef1efe0222c67a543d1b4a8e802e6267a1fa',
'contract_vehicle_lease_crew_v3.docx'=>'87584e74a9cfd6bc150fc5ea2c4f7b7045e8ff4b2fba7a5fb80b03ccfc97dff2',
'contract_vehicle_lease_crew_v4.docx'=>'11c72fa3206d86d490f71aa88c47fb79cde9e06e0774c12793c89f9948f33e43',
'contract_vehicle_lease_crew_v5.docx'=>'464074c526ec0d7a387d46c017ad359a94771163cb7a7f076954fc529a673535',
'contract_carrier_sublease_crew_v1.docx'=>'074d6a082e1a07b823bf156814842e3cb345f1724bca1656e13e8be0d053719e',
'contract_carrier_sublease_crew_v2.docx'=>'b3f1068c42b9a9659f4774934eeb8924cf20c58e83e44a9298df98f3e35c0be7',
'waybill_driver_assignment_v1.docx'=>'5a45e855c6fb47dbc96e6d8b9185d7032b5ca86a9baefbb78fdc6419749f73ab',
'client_sublease_contract_v1.docx'=>'01ebdc8bb56beb11b20abc787bd6067c7ae3379274e1143cf3e4cf1258f7230c',
'client_invoice_v1.docx'=>'10b5f47f51e50d894475f2360dd921f3104ddd0ea048e4c64f27f93ff189e2dd',
'client_act_v1.docx'=>'246b3394254d58a22d6ea492f9db56b2efcabef56d43de495766c2c902c6a1f9',
'client_upd_status2_v1.docx'=>'cfb1669b79400014c2a3244e0c6f7c942213105938dc5253972115fdc88b91c6',
];
$tmp=sys_get_temp_dir().'/planex_docgen_artifact_'.getmypid().'_'.bin2hex(random_bytes(4));if(!mkdir($tmp,0700,true)&&!is_dir($tmp))throw new RuntimeException('Unable to create artifact temp directory.');$rm=static function(string $path)use(&$rm):void{if(!file_exists($path))return;if(!is_dir($path)||is_link($path)){@unlink($path);return;}foreach(array_diff(scandir($path)?:[],['.','..'])as$name)$rm($path.DIRECTORY_SEPARATOR.$name);@rmdir($path);};
try{exec(escapeshellarg($tar).' -xzf '.escapeshellarg($archive).' -C '.escapeshellarg($tmp),$ignored,$rc);if($rc!==0)throw new RuntimeException('Unable to extract deploy artifact.');foreach($required as$name=>$expectedSha){$parts=glob($tmp.'/resources/document-templates/'.$name.'.b64.part*')?:[];sort($parts,SORT_STRING);if($parts===[])throw new RuntimeException('Missing built-in template parts in deploy artifact: '.$name);$bytes='';foreach($parts as$part){$encoded=preg_replace('/\s+/','',(string)file_get_contents($part))??'';$decoded=base64_decode($encoded,true);if(!is_string($decoded)||$decoded==='')throw new RuntimeException('Invalid base64 part in deploy artifact: '.basename($part));$bytes.=$decoded;}$actual=hash('sha256',$bytes);if(!hash_equals($expectedSha,$actual))throw new RuntimeException('Built-in template SHA mismatch in deploy artifact: '.$name);printf("DEPLOY_ARTIFACT_TEMPLATE=%s sha256=%s parts=%d\n",$name,$actual,count($parts));}echo "DOCUMENT_GENERATOR_DEPLOY_ARTIFACT=PASS\n";}finally{$rm($tmp);if($owned&&$tmpBase!=='')$rm($tmpBase);}
