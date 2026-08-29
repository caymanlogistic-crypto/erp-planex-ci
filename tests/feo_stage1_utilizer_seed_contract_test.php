<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/Service/FeoUtilizerService.php';
use App\Service\FeoUtilizerService;

$pass=0;$fail=0;
$ok=static function(string $name,bool $value)use(&$pass,&$fail):void{if($value){$pass++;return;}$fail++;fwrite(STDERR,"FAIL: {$name}\n");};
$root=dirname(__DIR__);
$seed=require $root.'/scripts/feo_stage1_utilizer_seed_data.php';
$utilizers=is_array($seed['utilizers']??null)?$seed['utilizers']:[];
$aliases=is_array($seed['aliases']??null)?$seed['aliases']:[];
$ok('canonical seed has exactly 8 utilizers',count($utilizers)===8);
$ok('canonical seed no longer fabricates rule engine',!array_key_exists('rules',$seed));
$ok('canonical seed starts with no synthetic aliases',count($aliases)===0);
$expected=['КУБАНЬЦВЕТМЕТ','ЛИМПЭК','МЕРКОМ','НЭК','ЭКО+','ПРОМХИМЛАБ','ЛИПЕЦКЭКОХИМ','ФРЕГАТ'];
$actual=array_map(static fn(array $u):string=>(string)($u['short_name']??''),$utilizers);
$ok('exact eight legacy master-data utilizers preserved',$actual===$expected);
foreach($utilizers as $u){
    $short=(string)($u['short_name']??'');
    $ok("{$short}: full name present",trim((string)($u['full_name']??''))!=='');
    $ok("{$short}: address preserved",trim((string)($u['address']??''))!=='');
    $ok("{$short}: FKKO preserved",trim((string)($u['fkko_classification']??''))!=='');
    $ok("{$short}: valid color",preg_match('/^#[0-9A-F]{6}$/D',(string)($u['color_hex']??''))===1);
    $ok("{$short}: normalized canonical name non-empty",FeoUtilizerService::normalizeText((string)($u['full_name']??''))!=='');
}
$writer=(string)(file_get_contents($root.'/scripts/feo_seed_stage1_utilizers.php')?:'');
$smoke=(string)(file_get_contents($root.'/scripts/feo_smoke_stage1_map.php')?:'');
$ok('seed writer requires migration 126',str_contains($writer,'126_create_feo_utilizer_aliases.sql'));
$ok('seed writer writes alias table',str_contains($writer,'feo_utilizer_aliases'));
$ok('seed writer does not insert legacy rules',!str_contains($writer,'INSERT INTO feo_utilizer_match_rules'));
$ok('seed writer remains exact PLANEX targeted',str_contains($writer,'company 25 / spugovxsim_1'));
$ok('smoke reports aliases',str_contains($smoke,"'utilizer_aliases'"));
$ok('smoke no longer requires 38 rules',!str_contains($smoke,'Expected at least 38 explicit seeded rules'));
$ok('smoke reports unresolved problem count',str_contains($smoke,'unmatched_problem_count'));
printf("PASS=%d FAIL=%d\n",$pass,$fail);
exit($fail===0?0:1);
