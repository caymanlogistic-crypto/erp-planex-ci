<?php

declare(strict_types=1);

$candidateList=(string)(getenv('CANDIDATE_LIST')?:'');
if($candidateList===''||!is_file($candidateList))throw new RuntimeException('candidate list unavailable');
function litPP(string $src,string $key):?string{$p='~[\'\"]'.preg_quote($key,'~').'[\'\"]\s*=>\s*([\'\"])(.*?)\\1~s';return preg_match($p,$src,$m)?stripcslashes((string)$m[2]):null;}
function cfgPP(string $src):?array{$h=litPP($src,'host');$d=litPP($src,'dbname');$u=litPP($src,'username');$p=litPP($src,'password');return($h===null||$d===null||$u===null||$p===null)?null:['host'=>$h,'port'=>3306,'database'=>$d,'username'=>$u,'password'=>$p];}
function roPP(array $c):PDO{$pdo=new PDO('mysql:host='.$c['host'].';port='.($c['port']??3306).';dbname='.$c['database'].';charset=utf8mb4',$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);$pdo->exec('SET SESSION TRANSACTION READ ONLY');$pdo->exec('START TRANSACTION READ ONLY');return$pdo;}
function hasPP(PDO $pdo,string $t):bool{$s=$pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$s->execute([$t]);return(int)$s->fetchColumn()===1;}
$matches=[];foreach(array_values(array_unique(array_filter(array_map('trim',file($candidateList,FILE_IGNORE_NEW_LINES)?:[]))))as$p){if(!is_file($p)||filesize($p)>524288)continue;$src=@file_get_contents($p);if(!is_string($src))continue;$c=cfgPP($src);if(!$c)continue;try{$x=roPP($c);if(!hasPP($x,'flights'))continue;$matches[hash('sha256',$c['host']."\0".$c['database']."\0".$c['username'])]=$c;}catch(Throwable){}}
if(count($matches)!==1)throw new RuntimeException('legacy connection count='.count($matches));$pdo=roPP(array_values($matches)[0]);
$rows=$pdo->query("SELECT id,status,route_type,planned_start_date,planned_start_date_from,planned_start_date_to,block_date,actual_start_date,actual_end_date FROM flights ORDER BY id")->fetchAll();if(count($rows)!==75)throw new RuntimeException('flight count='.count($rows));
$summary=['exact'=>0,'complete_range'=>0,'partial_from_only'=>0,'partial_to_only'=>0,'none'=>0];$partial=[];
foreach($rows as$r){$exact=trim((string)$r['planned_start_date']);$from=trim((string)$r['planned_start_date_from']);$to=trim((string)$r['planned_start_date_to']);if($exact!==''){$summary['exact']++;continue;}if($from!==''&&$to!==''){$summary['complete_range']++;continue;}if($from!==''&&$to===''){$summary['partial_from_only']++;$partial[]=$r;continue;}if($from===''&&$to!==''){$summary['partial_to_only']++;$partial[]=$r;continue;}$summary['none']++;}
echo 'WASTE_PARTIAL_PLAN_SUMMARY='.json_encode($summary,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
echo 'WASTE_PARTIAL_PLAN_ROWS='.json_encode($partial,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
echo 'WASTE_PARTIAL_PLAN_FACTS=PASS'.PHP_EOL;
