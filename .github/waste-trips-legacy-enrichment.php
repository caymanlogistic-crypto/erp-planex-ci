<?php

declare(strict_types=1);

use App\Service\WasteTripLegacyImportService;

$root = rtrim((string)(getenv('PLANEX_ROOT') ?: ''), '/');
$candidateList = (string)(getenv('CANDIDATE_LIST') ?: '');
$out = (string)(getenv('OUT_JSON') ?: '/tmp/waste_legacy_enrichment.json');
if ($root === '' || !is_file($root . '/.env') || !is_file($root . '/bootstrap/app.php')) throw new RuntimeException('PLANEX_ROOT invalid');
if ($candidateList === '' || !is_file($candidateList)) throw new RuntimeException('candidate list unavailable');

require_once $root . '/app/Support/helpers.php';
require_once $root . '/app/Support/environment.php';
loadEnvFileNonOverwriting($root . '/.env');
$config = require $root . '/bootstrap/app.php';
require_once $root . '/app/Support/entrypoint_dependencies.php';
require_once $root . '/app/Service/WasteTripLegacyImportService.php';

function roPdo(array $cfg): PDO {
    $pdo = new PDO('mysql:host='.$cfg['host'].';port='.($cfg['port']??3306).';dbname='.$cfg['database'].';charset=utf8mb4',$cfg['username'],$cfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $pdo->exec('SET SESSION TRANSACTION READ ONLY');
    $pdo->exec('START TRANSACTION READ ONLY');
    return $pdo;
}
function cols(PDO $pdo,string $table): array {
    $s=$pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION');$s->execute([$table]);return array_column($s->fetchAll(),'COLUMN_NAME');
}
function has(PDO $pdo,string $table): bool {$s=$pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$s->execute([$table]);return (int)$s->fetchColumn()===1;}
function literalValue(string $src,string $key): ?string {$p='~[\'\"]'.preg_quote($key,'~').'[\'\"]\s*=>\s*([\'\"])(.*?)\\1~s';return preg_match($p,$src,$m)?stripcslashes((string)$m[2]):null;}
function cfgFrom(string $src): ?array {$h=literalValue($src,'host');$d=literalValue($src,'dbname');$u=literalValue($src,'username');$p=literalValue($src,'password');return ($h===null||$d===null||$u===null||$p===null)?null:['host'=>$h,'port'=>3306,'database'=>$d,'username'=>$u,'password'=>$p];}
function normPlate(?string $s): string {return preg_replace('/[^A-Za-zА-Яа-яЁё0-9]/u','',mb_strtoupper((string)$s,'UTF-8'))??'';}
function normName(?string $s): string {return preg_replace('/[^A-Za-zА-Яа-яЁё0-9]/u','',mb_strtoupper(trim((string)$s),'UTF-8'))??'';}

$central=roPdo($config['database']);
$company=$central->query("SELECT * FROM companies WHERE id=25 AND status='active' LIMIT 1")->fetch()?:null;if(!$company)throw new RuntimeException('company unavailable');
$tenantCfg=companyDatabaseConfig($config,$company);$tenant=roPdo($tenantCfg);

$paths=array_values(array_unique(array_filter(array_map('trim',file($candidateList,FILE_IGNORE_NEW_LINES)?:[]))));$matches=[];
foreach($paths as $p){if(!is_file($p)||filesize($p)>524288)continue;$src=@file_get_contents($p);if(!is_string($src))continue;$c=cfgFrom($src);if(!$c)continue;try{$x=roPdo($c);if(!has($x,'flights'))continue;$id=hash('sha256',$c['host']."\0".$c['database']."\0".$c['username']);$matches[$id]=$c;}catch(Throwable){}}
if(count($matches)!==1)throw new RuntimeException('legacy connection count='.count($matches));$legacyCfg=array_values($matches)[0];$legacy=roPdo($legacyCfg);

$legacyFlightCols=cols($legacy,'flights');
$routeCols=array_values(array_filter($legacyFlightCols,static fn($c)=>preg_match('/route|warehouse|util|destination|source|unload|status|contractor|driver|zayavki/i',$c)));
$select=array_values(array_unique(array_merge(['id','zayavki_ids'],array_filter($routeCols,static fn($c)=>$c!=='id'&&$c!=='zayavki_ids'))));
$legacyFlights=$legacy->query('SELECT '.implode(',',array_map(static fn($c)=>'`'.str_replace('`','``',$c).'`',$select)).' FROM flights ORDER BY id')->fetchAll();

$legacyContractorCols=has($legacy,'contractors')?cols($legacy,'contractors'):[];
$legacyDriverCols=has($legacy,'drivers')?cols($legacy,'drivers'):[];
$legacyWarehouseCols=has($legacy,'warehouses')?cols($legacy,'warehouses'):[];
$legacyWarehouses=has($legacy,'warehouses')?$legacy->query('SELECT * FROM warehouses ORDER BY id')->fetchAll():[];

// Capture legacy contractor/driver identity fields only; no contact/passport/bank fields.
$legacyContractors=[];
if(has($legacy,'contractors')){
    $wanted=array_values(array_filter(['id','name','inn','ИНН','ogrn','ОГРН'],static fn($c)=>in_array($c,$legacyContractorCols,true)));
    if($wanted)$legacyContractors=$legacy->query('SELECT '.implode(',',array_map(static fn($c)=>'`'.$c.'`',$wanted)).' FROM contractors ORDER BY id')->fetchAll();
}
$legacyDrivers=[];
if(has($legacy,'drivers')){
    $wanted=array_values(array_filter(['id','full_name','vehicle_make_plate','contractor_id'],static fn($c)=>in_array($c,$legacyDriverCols,true)));
    if($wanted)$legacyDrivers=$legacy->query('SELECT '.implode(',',array_map(static fn($c)=>'`'.$c.'`',$wanted)).' FROM drivers ORDER BY id')->fetchAll();
}

// All ERP crews, including inactive/deleted if schema keeps such states.
$crewCols=cols($tenant,'crews');$contractorCols=cols($tenant,'contractors');$driverCols=cols($tenant,'drivers');
$erpCrews=[];
$sql="SELECT cr.id crew_id,cr.contractor_id,c.name contractor_name,d.id driver_id,d.full_name driver_name,vu1.plate_number primary_plate,vu2.plate_number secondary_plate";
foreach(['status','is_active','deleted_at','archived_at'] as $c){if(in_array($c,$crewCols,true))$sql.=',cr.`'.$c.'` crew_'.$c;}
$sql.=" FROM crews cr LEFT JOIN contractors c ON c.id=cr.contractor_id LEFT JOIN crew_drivers cd ON cd.crew_id=cr.id LEFT JOIN drivers d ON d.id=cd.driver_id LEFT JOIN driver_vehicle_blocks dvb ON dvb.id=cr.driver_vehicle_block_id LEFT JOIN vehicle_sets vs ON vs.id=dvb.vehicle_set_id LEFT JOIN vehicle_units vu1 ON vu1.id=vs.primary_vehicle_unit_id LEFT JOIN vehicle_units vu2 ON vu2.id=vs.secondary_vehicle_unit_id ORDER BY cr.id,d.id";
if(has($tenant,'crews'))$erpCrews=$tenant->query($sql)->fetchAll();

// Referenced request ids and candidate destination fields in canonical FEO.
$requestSet=[];foreach($legacyFlights as $f){foreach(WasteTripLegacyImportService::parseLegacyRequestIds((string)$f['zayavki_ids']) as $rid)$requestSet[$rid]=true;}
$feoCols=cols($tenant,'feo_requests');
$feoDestinationCols=array_values(array_filter($feoCols,static fn($c)=>preg_match('/утилиз|util|обезв|обработ|размещ|placement|poluch|получ|конеч|destination|operator|оо_/iu',$c)));
$feoSample=[];$ids=array_keys($requestSet);
foreach(array_chunk($ids,300) as $chunk){if(!$chunk)continue;$ph=implode(',',array_fill(0,count($chunk),'?'));$sel=array_values(array_unique(array_merge(['zayavka_id'],array_slice($feoDestinationCols,0,40))));$st=$tenant->prepare('SELECT '.implode(',',array_map(static fn($c)=>'`'.str_replace('`','``',$c).'`',$sel)).' FROM feo_requests WHERE zayavka_id IN ('.$ph.')');$st->execute($chunk);foreach($st->fetchAll() as $row)$feoSample[(string)$row['zayavka_id']]=$row;}

$placementCols=has($tenant,'feo_placement_current')?cols($tenant,'feo_placement_current'):[];$placementRows=[];
if($placementCols&&in_array('zayavka_id',$placementCols,true)){
    $sel=array_values(array_filter($placementCols,static fn($c)=>preg_match('/id|zayavka|util|layer|placement|source|target|dest|type|name/i',$c)));
    foreach(array_chunk($ids,300) as $chunk){$ph=implode(',',array_fill(0,count($chunk),'?'));$st=$tenant->prepare('SELECT '.implode(',',array_map(static fn($c)=>'`'.str_replace('`','``',$c).'`',$sel)).' FROM feo_placement_current WHERE zayavka_id IN ('.$ph.')');$st->execute($chunk);foreach($st->fetchAll() as $row)$placementRows[(string)$row['zayavka_id']]=$row;}
}

// Exact driver+plate matches from legacy into any ERP crew.
$driverById=[];foreach($legacyDrivers as $d)$driverById[(int)$d['id']]=$d;
$exactMatches=[];
foreach($legacyFlights as $f){$did=(int)($f['driver_id']??0);$ld=$driverById[$did]??null;if(!$ld)continue;$n=normName($ld['full_name']??'');$p=normPlate($ld['vehicle_make_plate']??'');foreach($erpCrews as $ec){$nameOk=$n!==''&&$n===normName($ec['driver_name']??'');$plateOk=$p!==''&&(str_contains($p,normPlate($ec['primary_plate']??''))||(($ec['secondary_plate']??'')!==null&&str_contains($p,normPlate($ec['secondary_plate']))));if($nameOk&&$plateOk)$exactMatches[]=['legacy_flight_id'=>(int)$f['id'],'legacy_driver_id'=>$did,'crew_id'=>(int)$ec['crew_id'],'legacy_name'=>$ld['full_name']??null,'legacy_vehicle'=>$ld['vehicle_make_plate']??null,'erp_driver'=>$ec['driver_name']??null,'erp_primary_plate'=>$ec['primary_plate']??null,'erp_contractor'=>$ec['contractor_name']??null];}}

$result=['legacy'=>['flight_columns'=>$legacyFlightCols,'route_columns'=>$routeCols,'contractor_columns'=>$legacyContractorCols,'driver_columns'=>$legacyDriverCols,'warehouse_columns'=>$legacyWarehouseCols,'warehouses'=>$legacyWarehouses,'flights'=>$legacyFlights,'contractors'=>$legacyContractors,'drivers'=>$legacyDrivers],'erp'=>['crew_columns'=>$crewCols,'contractor_columns'=>$contractorCols,'driver_columns'=>$driverCols,'all_crews'=>$erpCrews,'feo_destination_columns'=>$feoDestinationCols,'feo_request_rows'=>$feoSample,'placement_columns'=>$placementCols,'placement_rows'=>$placementRows],'exact_executor_matches'=>$exactMatches];
file_put_contents($out,json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
printf("WASTE_ENRICH_LEGACY_FLIGHT_COLS=%d\n",count($legacyFlightCols));printf("WASTE_ENRICH_ERP_CREW_ROWS=%d\n",count($erpCrews));printf("WASTE_ENRICH_EXACT_EXECUTOR_MATCHES=%d\n",count($exactMatches));printf("WASTE_ENRICH_FEO_DEST_COLS=%d\n",count($feoDestinationCols));echo "WASTE_LEGACY_ENRICHMENT=PASS\n";
