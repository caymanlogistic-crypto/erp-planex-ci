<?php

declare(strict_types=1);

$candidateList=(string)(getenv('CANDIDATE_LIST')?:'');
if($candidateList===''||!is_file($candidateList))throw new RuntimeException('candidate list missing');
function litCard(string $src,string $key):?string{$p='~[\'\"]'.preg_quote($key,'~').'[\'\"]\s*=>\s*([\'\"])(.*?)\\1~s';return preg_match($p,$src,$m)?stripcslashes((string)$m[2]):null;}
function cfgCard(string $src):?array{$h=litCard($src,'host');$d=litCard($src,'dbname');$u=litCard($src,'username');$p=litCard($src,'password');return($h===null||$d===null||$u===null||$p===null)?null:['host'=>$h,'database'=>$d,'username'=>$u,'password'=>$p];}
function connCard(array $c):PDO{$p=new PDO('mysql:host='.$c['host'].';port=3306;dbname='.$c['database'].';charset=utf8mb4',$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);$p->exec('SET SESSION TRANSACTION READ ONLY');$p->exec('START TRANSACTION READ ONLY');return $p;}
function hasFlights(PDO $p):bool{$s=$p->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=\'flights\'');$s->execute();return(int)$s->fetchColumn()===1;}
function idsCard(string $raw):array{$parts=preg_split('/[\s,;]+/u',trim($raw),-1,PREG_SPLIT_NO_EMPTY)?:[];$out=[];$seen=[];foreach($parts as $part){$id=trim((string)$part);if($id===''||isset($seen[$id]))continue;$seen[$id]=true;$out[]=$id;}return$out;}
$paths=array_values(array_unique(array_filter(array_map('trim',file($candidateList,FILE_IGNORE_NEW_LINES)?:[]))));$matches=[];
foreach($paths as $path){if(!is_file($path)||filesize($path)>524288)continue;$src=@file_get_contents($path);if(!is_string($src))continue;$c=cfgCard($src);if(!$c)continue;try{$p=connCard($c);if(!hasFlights($p))continue;$matches[hash('sha256',$c['host']."\0".$c['database']."\0".$c['username'])]=$c;}catch(Throwable){}}
if(count($matches)!==1)throw new RuntimeException('legacy connection count='.count($matches));$pdo=connCard(array_values($matches)[0]);
$rows=$pdo->query('SELECT id,zayavki_ids,status,route_type,planned_start_date,planned_start_date_from,planned_start_date_to,block_date FROM flights ORDER BY id')->fetchAll();
$links=0;$unique=[];$occ=[];$empty=[];$route=[];$status=[];$latest=[];
foreach($rows as $r){$ids=idsCard((string)($r['zayavki_ids']??''));if($ids===[])$empty[]=(int)$r['id'];$links+=count($ids);foreach($ids as $id){$key='id:'.$id;$unique[$key]=true;$occ[$key]=($occ[$key]??0)+1;}$route[(string)$r['route_type']]=($route[(string)$r['route_type']]??0)+1;$status[(string)$r['status']]=($status[(string)$r['status']]??0)+1;}
$repeated=count(array_filter($occ,static fn(int $n):bool=>$n>1));ksort($route);ksort($status);
$last=array_slice($rows,-10);foreach($last as $r)$latest[]=['id'=>(int)$r['id'],'links'=>count(idsCard((string)$r['zayavki_ids']),'block_date'=>$r['block_date']??null];
$out=['flights'=>count($rows),'links'=>$links,'unique_feo'=>count($unique),'repeated_feo'=>$repeated,'empty_flights'=>$empty,'min_flight_id'=>$rows? (int)$rows[0]['id']:null,'max_flight_id'=>$rows? (int)$rows[count($rows)-1]['id']:null,'max_block_date'=>$rows?max(array_map(static fn(array $r):string=>(string)($r['block_date']??''),$rows)):null,'route_counts'=>$route,'status_counts'=>$status,'latest'=>$latest];
echo 'WASTE_CARDINALITY_FACTS='.json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
echo 'WASTE_CARDINALITY_FACTS=PASS'.PHP_EOL;
