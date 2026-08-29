<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/Service/FeoUtilizerService.php';

use App\Service\FeoUtilizerService;

$pass=0;$fail=0;
$ok=static function(string $name,bool $value)use(&$pass,&$fail):void{if($value){$pass++;return;}$fail++;fwrite(STDERR,"FAIL: {$name}\n");};
$root=dirname(__DIR__);
$read=static fn(string $rel):string=>(string)(file_get_contents($root.'/'.$rel)?:'');

$ok('normalization is case-insensitive', FeoUtilizerService::normalizeText('ООО "ФРЕГАТ"') === FeoUtilizerService::normalizeText('ооо фрегат'));
$ok('normalization folds yo/e', FeoUtilizerService::normalizeText('ЁЛКА') === FeoUtilizerService::normalizeText('елка'));
$ok('normalization removes punctuation differences', FeoUtilizerService::normalizeText('ООО «НЭК - ИННОВАЦИИ»') === FeoUtilizerService::normalizeText('ООО НЭК ИННОВАЦИИ'));

$service=new FeoUtilizerService();
$method=new ReflectionMethod(FeoUtilizerService::class,'identityIndex');
$method->setAccessible(true);
$utilizers=[
    1=>['id'=>1,'full_name'=>'ООО "ФРЕГАТ"','short_name'=>'ФРЕГАТ','aliases'=>[['alias'=>'ФРЕГАТ ВОСКРЕСЕНСК']]],
    2=>['id'=>2,'full_name'=>'ООО "ЛИМПЭК"','short_name'=>'ЛИМПЭК','aliases'=>[['alias'=>'ЛИМПЭК ЯРОСЛАВЛЬ']]],
];
$index=$method->invoke($service,$utilizers);
$ok('canonical full name is indexed', (int)($index[FeoUtilizerService::normalizeText('ООО "ФРЕГАТ"')]['utilizer_id']??0)===1);
$ok('canonical short name is indexed', (int)($index[FeoUtilizerService::normalizeText('ФРЕГАТ')]['utilizer_id']??0)===1);
$ok('alias is indexed', (int)($index[FeoUtilizerService::normalizeText('ФРЕГАТ ВОСКРЕСЕНСК')]['utilizer_id']??0)===1);
$ok('alias source is explicit', ($index[FeoUtilizerService::normalizeText('ФРЕГАТ ВОСКРЕСЕНСК')]['source']??'')==='alias');

$ambiguous=[
    1=>['id'=>1,'full_name'=>'Первый','short_name'=>'ONE','aliases'=>[['alias'=>'ОБЩИЙ']]],
    2=>['id'=>2,'full_name'=>'Второй','short_name'=>'TWO','aliases'=>[['alias'=>'ОБЩИЙ']]],
];
$ambiguousIndex=$method->invoke($service,$ambiguous);
$key=FeoUtilizerService::normalizeText('ОБЩИЙ');
$ok('ambiguous identity never chooses arbitrary utilizer', array_key_exists($key,$ambiguousIndex) && $ambiguousIndex[$key]===false);

$source=$read('app/Service/FeoUtilizerService.php');
$start=strpos($source,'public function classifyRequests');
$end=strpos($source,'public function currentPlacementProblemSummary',$start?:0);
$classify=($start!==false&&$end!==false)?substr($source,$start,$end-$start):'';
$ok('classification uses recipient name', str_contains($classify,'gruzopoluchatel'));
$ok('classification does not use delivery address', !str_contains($classify,'adres_dostavki_otkhodov'));
$ok('classification has no contains matcher', !str_contains($classify,'str_contains('));
$ok('classification has no priority engine', !str_contains($classify,'priority'));
$ok('classification has no per-request override', !str_contains(strtolower($classify),'override'));
$ok('classification reports unmatched explicitly', str_contains($classify,"'status'=>'unmatched'"));
$ok('classification preserves map-compatible null rule id', str_contains($classify,"'rule_id'=>null"));
$ok('problem summary uses canonical placement-current pool', str_contains($source,'FROM feo_placement_current c') && str_contains($source,'JOIN feo_requests r ON r.zayavka_id=c.zayavka_id'));
$ok('problem summary groups raw recipient names', str_contains($source,'$row[\'gruzopoluchatel\']'));

$migration=$read('database/migrations-local/126_create_feo_utilizer_aliases.sql');
$ok('alias table is tenant-local migration schema', str_contains($migration,'CREATE TABLE `feo_utilizer_aliases`'));
$ok('normalized alias is globally unique', str_contains($migration,'UNIQUE KEY `uk_feo_utilizer_alias_normalized` (`normalized_alias`)'));
$ok('alias belongs to one utilizer', str_contains($migration,'FOREIGN KEY (`utilizer_id`) REFERENCES `feo_utilizers` (`id`) ON DELETE CASCADE'));
$ok('migration does not inherit legacy rule engine into aliases', !str_contains($migration,'INSERT INTO `feo_utilizer_aliases`') && !str_contains($migration,'feo_utilizer_match_rules'));
$ok('migration does not drop legacy tables', !preg_match('/DROP\s+TABLE/i',$migration));

printf("PASS=%d FAIL=%d\n",$pass,$fail);
exit($fail===0?0:1);
