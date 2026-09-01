<?php

declare(strict_types=1);

$candidateList = (string)(getenv('CANDIDATE_LIST') ?: '');
if ($candidateList === '' || !is_file($candidateList)) throw new RuntimeException('candidate list unavailable');

function literalValue2(string $src,string $key): ?string {
    $p='~[\'\"]'.preg_quote($key,'~').'[\'\"]\s*=>\s*([\'\"])(.*?)\\1~s';
    return preg_match($p,$src,$m)?stripcslashes((string)$m[2]):null;
}
function cfgFrom2(string $src): ?array {
    $h=literalValue2($src,'host'); $d=literalValue2($src,'dbname'); $u=literalValue2($src,'username'); $p=literalValue2($src,'password');
    return ($h===null||$d===null||$u===null||$p===null)?null:['host'=>$h,'port'=>3306,'database'=>$d,'username'=>$u,'password'=>$p];
}
function ro2(array $c): PDO {
    $pdo=new PDO('mysql:host='.$c['host'].';port='.($c['port']??3306).';dbname='.$c['database'].';charset=utf8mb4',$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
    $pdo->exec('SET SESSION TRANSACTION READ ONLY'); $pdo->exec('START TRANSACTION READ ONLY'); return $pdo;
}
function has2(PDO $pdo,string $t): bool {$s=$pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$s->execute([$t]);return (int)$s->fetchColumn()===1;}

$matches=[];
foreach(array_values(array_unique(array_filter(array_map('trim',file($candidateList,FILE_IGNORE_NEW_LINES)?:[])))) as $p){
    if(!is_file($p)||filesize($p)>524288)continue; $src=@file_get_contents($p); if(!is_string($src))continue; $c=cfgFrom2($src); if(!$c)continue;
    try{$x=ro2($c);if(!has2($x,'flights'))continue;$matches[hash('sha256',$c['host']."\0".$c['database']."\0".$c['username'])]=$c;}catch(Throwable){}
}
if(count($matches)!==1)throw new RuntimeException('legacy connection count='.count($matches));
$pdo=ro2(array_values($matches)[0]);
$rows=$pdo->query("SELECT id,status,block_date,planned_start_date,planned_start_date_from,planned_start_date_to,actual_start_date,actual_end_date FROM flights ORDER BY id")->fetchAll();
if(count($rows)!==75)throw new RuntimeException('legacy flight count='.count($rows));
$stats=[];$examples=[];
foreach($rows as $r){
    $st=(string)$r['status'];
    $stats[$st]??=['count'=>0,'actual_start'=>0,'actual_end'=>0,'block_date'=>0,'planned_exact'=>0,'planned_range'=>0];
    $stats[$st]['count']++;
    if(trim((string)$r['actual_start_date'])!=='')$stats[$st]['actual_start']++;
    if(trim((string)$r['actual_end_date'])!=='')$stats[$st]['actual_end']++;
    if(trim((string)$r['block_date'])!=='')$stats[$st]['block_date']++;
    if(trim((string)$r['planned_start_date'])!=='')$stats[$st]['planned_exact']++;
    if(trim((string)$r['planned_start_date_from'])!==''&&trim((string)$r['planned_start_date_to'])!=='')$stats[$st]['planned_range']++;
    if(count($examples)<20 && in_array($st,['completed','started'],true) && (trim((string)$r['actual_start_date'])===''||($st==='completed'&&trim((string)$r['actual_end_date'])===''))){
        $examples[]=['id'=>(int)$r['id'],'status'=>$st,'block_date'=>$r['block_date'],'planned_start_date'=>$r['planned_start_date'],'planned_from'=>$r['planned_start_date_from'],'planned_to'=>$r['planned_start_date_to'],'actual_start'=>$r['actual_start_date'],'actual_end'=>$r['actual_end_date']];
    }
}
echo 'WASTE_LEGACY_DATE_FACTS='.json_encode($stats,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
echo 'WASTE_LEGACY_DATE_GAPS='.json_encode($examples,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
echo 'WASTE_LEGACY_DATE_FACTS=PASS'.PHP_EOL;
