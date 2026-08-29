<?php

declare(strict_types=1);

$root=dirname(__DIR__);
$pass=0;$fail=0;
$ok=static function(string $name,bool $value)use(&$pass,&$fail):void{if($value){$pass++;return;}$fail++;fwrite(STDERR,"FAIL: {$name}\n");};
$read=static fn(string $rel):string=>(string)(file_get_contents($root.'/'.$rel)?:'');
$sql=$read('database/migrations-local/126_create_feo_utilizer_aliases.sql');
$apply=$read('scripts/feo_apply_126.php');
$verify=$read('scripts/feo_verify_126.php');
$workflow=$read('.github/workflows/feo_126_production_migration.yml');
$manifest=json_decode($read('.github/agent_db_migration_request.json'),true);
$ok('migration creates alias table',str_contains($sql,'CREATE TABLE `feo_utilizer_aliases`'));
$ok('migration leaves old matching tables intact',!preg_match('/DROP\s+TABLE\s+`?feo_utilizer_match_rules/i',$sql) && !preg_match('/DROP\s+TABLE\s+`?feo_request_utilizer_overrides/i',$sql));
$ok('migration does not import the legacy rule engine',!str_contains($sql,'feo_utilizer_match_rules') && !str_contains($sql,'feo_request_utilizer_overrides'));
$ok('migration starts owner-managed aliases empty',!str_contains($sql,'INSERT INTO `feo_utilizer_aliases`'));
$ok('apply script journals 126',str_contains($apply,'126_create_feo_utilizer_aliases.sql'));
$ok('apply script requires 125',str_contains($apply,'125_create_feo_map_utilizers.sql'));
$ok('apply script checks alias unique index',str_contains($apply,'uk_feo_utilizer_alias_normalized'));
$ok('apply script checks CASCADE FK',str_contains($apply,"DELETE_RULE='CASCADE'"));
$ok('verify snapshot protects legacy rule/override counts',str_contains($verify,"'legacy_rules'") && str_contains($verify,"'legacy_overrides'"));
$ok('workflow is manual-only',str_contains($workflow,'workflow_dispatch:') && !preg_match('/^\s{2}(push|pull_request|schedule|workflow_run):/m',$workflow));
$ok('workflow checks stable existing business rows',str_contains($workflow,"'legacy_rules','legacy_overrides'") && str_contains($workflow,"'feo_requests','feo_imports','feo_changes'"));
$ok('workflow never deploys application',!str_contains($workflow,'P07_deploy_erpv2.sh'));
$ok('agent migration manifest points to 126',is_array($manifest) && ($manifest['migration_id']??null)==='126' && ($manifest['workflow']??null)==='feo_126_production_migration.yml' && ($manifest['confirmation_value']??null)==='APPLY_FEO_126');
printf("PASS=%d FAIL=%d\n",$pass,$fail);
exit($fail===0?0:1);
