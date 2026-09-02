<?php

declare(strict_types=1);

use App\Service\WasteTripLegacyImportService;
use App\Service\WasteTripService;

$root = rtrim((string)(getenv('PLANEX_ROOT') ?: ''), '/');
$expectedSha = trim((string)(getenv('EXPECTED_SHA') ?: ''));
$markerPath = (string)(getenv('DEPLOY_MARKER') ?: '');
$candidateList = (string)(getenv('CANDIDATE_LIST') ?: '');
$confirm = (string)(getenv('CONFIRM_IMPORT') ?: '');
$outJson = (string)(getenv('OUT_JSON') ?: '/tmp/waste_controlled_import_v3.json');
$outCsv = (string)(getenv('OUT_CSV') ?: '/tmp/waste_controlled_import_v3.csv');

if ($root === '' || !is_file($root . '/bootstrap/app.php') || !is_file($root . '/.env')) throw new RuntimeException('PLANEX_ROOT invalid');
if (!preg_match('/^[0-9a-f]{40}$/', $expectedSha)) throw new RuntimeException('EXPECTED_SHA invalid');
if ($markerPath === '' || !is_file($markerPath) || trim((string)file_get_contents($markerPath)) !== $expectedSha) throw new RuntimeException('production marker mismatch');
if ($candidateList === '' || !is_file($candidateList)) throw new RuntimeException('legacy candidate list unavailable');
if ($confirm !== 'IMPORT_75_WASTE_TRIPS_V3') throw new RuntimeException('controlled import confirmation mismatch');

require_once $root . '/app/Support/helpers.php';
require_once $root . '/app/Support/environment.php';
loadEnvFileNonOverwriting($root . '/.env');
$config = require $root . '/bootstrap/app.php';
require_once $root . '/app/Support/entrypoint_dependencies.php';
require_once $root . '/app/Service/WasteTripService.php';
require_once $root . '/app/Service/WasteTripLegacyImportService.php';

function failV3(string $message): never { throw new RuntimeException($message); }
function connectV3(array $c): PDO {
    return new PDO('mysql:host='.$c['host'].';port='.($c['port']??3306).';dbname='.$c['database'].';charset=utf8mb4',$c['username'],$c['password'],[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false,
    ]);
}
function connectLegacyV3(array $c): PDO {
    $pdo=connectV3($c);
    $pdo->exec('SET SESSION TRANSACTION READ ONLY');
    $pdo->exec('START TRANSACTION READ ONLY');
    return $pdo;
}
function tableExistsV3(PDO $pdo,string $table): bool {
    $s=$pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $s->execute([$table]); return (int)$s->fetchColumn()===1;
}
function columnsV3(PDO $pdo,string $table): array {
    $s=$pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION');
    $s->execute([$table]); return array_column($s->fetchAll(),'COLUMN_NAME');
}
function literalV3(string $src,string $key): ?string {
    $p='~[\'\"]'.preg_quote($key,'~').'[\'\"]\s*=>\s*([\'\"])(.*?)\\1~s';
    return preg_match($p,$src,$m)?stripcslashes((string)$m[2]):null;
}
function configV3(string $src): ?array {
    $h=literalV3($src,'host'); $d=literalV3($src,'dbname'); $u=literalV3($src,'username'); $p=literalV3($src,'password');
    return ($h===null||$d===null||$u===null||$p===null)?null:['host'=>$h,'port'=>3306,'database'=>$d,'username'=>$u,'password'=>$p];
}
function legacyKgV3(mixed $tonnes): float {
    $v=str_replace(',','.',trim((string)$tonnes)); return is_numeric($v)?round(max(0.0,(float)$v)*1000,3):0.0;
}
function routeMappingV3(array $flight,int $utilizerId): array {
    return match(trim((string)($flight['route_type']??''))){
        'generator_to_utilizer','warehouse_to_utilizer'=>['destination_type'=>'utilizer','utilizer_id'=>$utilizerId],
        'generator_to_warehouse'=>['destination_type'=>'warehouse','utilizer_id'=>null],
        default=>failV3('unknown route_type for legacy flight #'.(int)$flight['id']),
    };
}
function historicalLabelV3(array $flight): string {
    return implode(' · ',array_values(array_filter([
        trim((string)($flight['legacy_contractor_name']??'')),
        trim((string)($flight['legacy_driver_name']??'')),
        trim((string)($flight['legacy_vehicle_make_plate']??'')),
    ],static fn(string $v):bool=>$v!=='')));
}
function verifyPlanningV3(PDO $tenant): array {
    $rows=$tenant->query("SELECT wt.legacy_flight_id,wt.planned_start_mode,wt.planned_start_date,wt.planned_start_date_from,wt.planned_start_date_to,a.legacy_payload_json FROM waste_trips wt JOIN waste_trip_legacy_import_audits a ON a.waste_trip_id=wt.id WHERE wt.source_system='legacy_flights' ORDER BY wt.legacy_flight_id")->fetchAll();
    if(count($rows)!==75) failV3('planning audit rows='.count($rows));
    $range=0;$unspecified=0;$partial=0;
    foreach($rows as $row){
        $mode=(string)$row['planned_start_mode'];
        if($mode==='range')$range++;
        if($mode==='unspecified')$unspecified++;
        $payload=json_decode((string)$row['legacy_payload_json'],true,512,JSON_THROW_ON_ERROR);
        $normal=$payload['legacy_planning_normalization']??null;
        if(is_array($normal) && ($normal['reason']??null)==='partial_range_not_representable'){
            $partial++;
            if($mode!=='unspecified' || $row['planned_start_date']!==null || $row['planned_start_date_from']!==null || $row['planned_start_date_to']!==null) failV3('partial ERP plan not cleared for legacy #'.$row['legacy_flight_id']);
            if(($normal['erp_mode']??null)!=='unspecified') failV3('partial normalization mode mismatch for legacy #'.$row['legacy_flight_id']);
            if(empty($normal['source_from']) && empty($normal['source_to'])) failV3('partial normalization lost source boundary for legacy #'.$row['legacy_flight_id']);
        }
    }
    if($range!==6 || $unspecified!==69 || $partial!==15) failV3('planning counts mismatch range='.$range.' unspecified='.$unspecified.' partial='.$partial);
    return ['partial'=>$partial,'complete_range'=>$range,'unspecified'=>$unspecified];
}

$central=connectV3($config['database']);
$company=$central->query("SELECT * FROM companies WHERE id=25 AND status='active' LIMIT 1")->fetch();
if(!$company) failV3('company 25 unavailable');
$tenant=connectV3(companyDatabaseConfig($config,$company));
if(!WasteTripService::schemaReady($tenant)) failV3('Waste Trips schema unavailable');
$m=$tenant->prepare('SELECT COUNT(*) FROM schema_migrations WHERE migration=?');$m->execute(['128_create_waste_trips.sql']);if((int)$m->fetchColumn()!==1)failV3('migration 128 journal mismatch');
$initial=[];foreach(['waste_trips','waste_trip_requests','waste_trip_legacy_import_audits','waste_trip_audit_log'] as $t)$initial[$t]=(int)$tenant->query('SELECT COUNT(*) FROM `'.$t.'`')->fetchColumn();
if(array_sum($initial)!==0) failV3('production Waste Trips not empty before import '.json_encode($initial));

$paths=array_values(array_unique(array_filter(array_map('trim',file($candidateList,FILE_IGNORE_NEW_LINES)?:[]))));
$matches=[];
foreach($paths as $path){
    if(!is_file($path)||filesize($path)>524288)continue;
    $src=@file_get_contents($path);if(!is_string($src))continue;$c=configV3($src);if(!$c)continue;
    try{$x=connectLegacyV3($c);if(!tableExistsV3($x,'flights'))continue;$matches[hash('sha256',$c['host']."\0".$c['database']."\0".$c['username'])]=$c;}catch(Throwable){}
}
if(count($matches)!==1) failV3('legacy connection count='.count($matches));
$legacy=connectLegacyV3(array_values($matches)[0]);
$flights=$legacy->query("SELECT f.*,c.name AS legacy_contractor_name,d.full_name AS legacy_driver_name,d.vehicle_make_plate AS legacy_vehicle_make_plate,CONCAT_WS(' ',u.`Фамилия`,u.`Имя`) AS legacy_manager_name FROM flights f LEFT JOIN contractors c ON c.id=f.contractor_id LEFT JOIN drivers d ON d.id=f.driver_id LEFT JOIN users u ON u.id=f.assigned_manager_id ORDER BY f.id")->fetchAll();
if(count($flights)!==75) failV3('legacy flights count='.count($flights));

$routeCounts=[];$statusCounts=[];$allIds=[];$occurrences=[];$links=0;$sourcePartial=0;$sourceRange=0;
foreach($flights as $flight){
    $fid=(int)$flight['id'];
    $route=trim((string)($flight['route_type']??''));$routeCounts[$route]=($routeCounts[$route]??0)+1;
    $status=trim((string)($flight['status']??''));$statusCounts[$status]=($statusCounts[$status]??0)+1;
    if(WasteTripLegacyImportService::mapLegacyStatus($status)===null)failV3('unknown status #'.$fid);
    if(!in_array(trim((string)($flight['unload_type']??'')),['OO','SKLAD'],true))failV3('unknown unload type #'.$fid);
    $planning=WasteTripLegacyImportService::resolveLegacyPlanning($flight);
    if($planning['partial_range'])$sourcePartial++;
    if($planning['mode']==='range')$sourceRange++;
    $ids=WasteTripLegacyImportService::parseLegacyRequestIds((string)($flight['zayavki_ids']??''));if($ids===[])failV3('flight without FEO #'.$fid);
    $links+=count($ids);foreach($ids as $id){$allIds[(string)$id]=true;$occurrences[(string)$id]=($occurrences[(string)$id]??0)+1;}
}
ksort($routeCounts);ksort($statusCounts);
$expectedRoutes=['generator_to_utilizer'=>65,'generator_to_warehouse'=>7,'warehouse_to_utilizer'=>3];ksort($expectedRoutes);
$expectedStatuses=['completed'=>65,'found'=>1,'planned_route'=>6,'started'=>3];ksort($expectedStatuses);
if($links!==945||count($allIds)!==802||count(array_filter($occurrences,static fn(int $n):bool=>$n>1))!==143)failV3('legacy cardinality mismatch');
if($routeCounts!==$expectedRoutes||$statusCounts!==$expectedStatuses)failV3('legacy distribution mismatch');
if($sourcePartial!==15||$sourceRange!==6)failV3('source planning distribution mismatch partial='.$sourcePartial.' range='.$sourceRange);

$requestMap=[];$businessIds=array_map('strval',array_keys($allIds));
foreach(array_chunk($businessIds,300) as $chunk){$ph=implode(',',array_fill(0,count($chunk),'?'));$s=$tenant->prepare("SELECT id,zayavka_id,mass_netto,polnoe_naim_zakazchika,inn_zakazchika FROM feo_requests WHERE zayavka_id IN ($ph)");$s->execute($chunk);foreach($s->fetchAll() as $r)$requestMap[(string)$r['zayavka_id']]=$r;}
$missing=array_values(array_diff($businessIds,array_keys($requestMap)));if($missing!==[])failV3('canonical FEO missing count='.count($missing));

$customerInns=[];$customerNames=[];
foreach($requestMap as $r){$inn=trim((string)($r['inn_zakazchika']??''));if($inn!=='')$customerInns[]=$inn;if($inn==='4714004270'){$name=trim((string)($r['polnoe_naim_zakazchika']??''));if($name!=='')$customerNames[$name]=true;}}
$customerInns=array_values(array_unique($customerInns));sort($customerInns,SORT_STRING);
if($customerInns!==['4714004270'])failV3('unexpected customer INN set '.json_encode($customerInns));
if(count($customerNames)!==1)failV3('ambiguous FEO customer name count='.count($customerNames));$feoClientName=(string)array_key_first($customerNames);

$requestDbIds=array_map(static fn(array $r):int=>(int)$r['id'],$requestMap);$operationalClaims=0;
foreach(array_chunk($requestDbIds,300) as $chunk){$ph=implode(',',array_fill(0,count($chunk),'?'));$s=$tenant->prepare("SELECT COUNT(*) FROM waste_trip_requests wtr JOIN waste_trips wt ON wt.id=wtr.waste_trip_id WHERE wtr.feo_request_id IN ($ph) AND wt.status<>'cancelled' AND COALESCE(wt.source_system,'')<>'legacy_flights'");$s->execute($chunk);$operationalClaims+=(int)$s->fetchColumn();}
if($operationalClaims!==0)failV3('unexpected operational claims='.$operationalClaims);

$users=$tenant->query("SELECT id,full_name,role_code FROM users WHERE status='active' AND deleted_at IS NULL ORDER BY id")->fetchAll();if(count($users)!==1||(int)$users[0]['id']!==1)failV3('responsible user mismatch');$responsible=$users[0];
$utilCols=columnsV3($tenant,'feo_utilizers');
if(in_array('inn',$utilCols,true)){$s=$tenant->prepare("SELECT id FROM feo_utilizers WHERE is_active=1 AND inn=? ORDER BY id");$s->execute(['7703545931']);$utilRows=$s->fetchAll();}
else{$utilRows=$tenant->query("SELECT id FROM feo_utilizers WHERE is_active=1 AND (UPPER(COALESCE(short_name,'')) LIKE '%ФРЕГАТ%' OR UPPER(COALESCE(full_name,'')) LIKE '%ФРЕГАТ%') ORDER BY id")->fetchAll();}
if(count($utilRows)!==1||(int)$utilRows[0]['id']!==8)failV3('FREGAT utilizer mismatch');$utilizerId=8;

$maxMassDelta=0.0;$dryRows=[];
foreach($flights as $flight){
    $fid=(int)$flight['id'];$ids=WasteTripLegacyImportService::parseLegacyRequestIds((string)$flight['zayavki_ids']);$current=0.0;foreach($ids as $id)$current+=WasteTripService::massKg($requestMap[(string)$id]['mass_netto']??null);
    $legacyMass=legacyKgV3($flight['total_mass_tonn']??null);$delta=round($current-$legacyMass,3);$maxMassDelta=max($maxMassDelta,abs($delta));if(abs($delta)>1.0)failV3('mass delta >1kg on #'.$fid.' delta='.$delta);
    $dest=routeMappingV3($flight,$utilizerId);$status=WasteTripLegacyImportService::mapLegacyStatus((string)$flight['status']);$planning=WasteTripLegacyImportService::resolveLegacyPlanning($flight);
    $normalized=WasteTripService::normalizeInput(['client_id'=>1,'route_executor_id'=>null,'responsible_user_id'=>1,'destination_type'=>$dest['destination_type'],'utilizer_id'=>$dest['utilizer_id'],'status'=>$status,'planned_start_mode'=>$planning['mode'],'planned_start_date'=>$planning['exact'],'planned_start_date_from'=>$planning['from'],'planned_start_date_to'=>$planning['to'],'actual_start_date'=>$flight['actual_start_date']??null,'actual_end_date'=>$flight['actual_end_date']??null,'comment'=>(string)($flight['comment']??''),'cancellation_reason'=>null]);
    $errors=WasteTripService::validateInput($normalized,true,true);if($errors!==[])failV3('semantic validation failed on #'.$fid.': '.implode(' ',$errors));
    $dryRows[$fid]=['request_count'=>count($ids),'route_type'=>(string)$flight['route_type'],'destination_type'=>$dest['destination_type'],'mapped_status'=>$status,'mass_delta_kg'=>$delta,'planning_mode'=>$planning['mode'],'partial_range'=>$planning['partial_range'],'source_from'=>$planning['source_from'],'source_to'=>$planning['source_to'],'historical_executor_label'=>historicalLabelV3($flight)];
}

$clientStmt=$tenant->prepare('SELECT id,name,inn,status,deleted_at FROM clients WHERE inn=? ORDER BY id');$clientStmt->execute(['4714004270']);$existingClients=$clientStmt->fetchAll();if(count($existingClients)>1)failV3('ambiguous FEO client');if(count($existingClients)===1&&((string)$existingClients[0]['status']!=='active'||$existingClients[0]['deleted_at']!==null))failV3('existing FEO client inactive');

echo 'WASTE_IMPORT_V3_DRY_RUN=PASS flights=75 unique_feo=802 links=945 repeated_feo=143 partial=15 complete_range=6 max_abs_mass_delta_kg='.number_format($maxMassDelta,3,'.','').PHP_EOL;

$tenant->beginTransaction();$createdClient=false;$clientId=0;$imported=[];
try{
    $clientStmt=$tenant->prepare('SELECT id,name,inn,status,deleted_at FROM clients WHERE inn=? FOR UPDATE');$clientStmt->execute(['4714004270']);$clients=$clientStmt->fetchAll();if(count($clients)>1)failV3('FEO client race ambiguity');
    if(count($clients)===1){$clientId=(int)$clients[0]['id'];}else{$insert=$tenant->prepare('INSERT INTO clients (name,inn,status,comments,created_by_user_id,created_by_role,updated_by_user_id,updated_by_role) VALUES (?,?,?,?,?,?,?,?)');$insert->execute([$feoClientName,'4714004270','active','Создан автоматически при контролируемом импорте legacy Waste Trips; source customer INN 4714004270',1,(string)$responsible['role_code'],1,(string)$responsible['role_code']]);$clientId=(int)$tenant->lastInsertId();$createdClient=true;}
    if($clientId<=0)failV3('FEO client id unavailable');
    $sessionUser=['user_id'=>1,'role_code'=>(string)$responsible['role_code']];
    foreach($flights as $flight){$fid=(int)$flight['id'];$dest=routeMappingV3($flight,$utilizerId);$mapping=['client_id'=>$clientId,'route_executor_id'=>null,'responsible_user_id'=>1,'destination_type'=>$dest['destination_type'],'utilizer_id'=>$dest['utilizer_id'],'allow_unmapped_historical_executor'=>true];$imported[$fid]=WasteTripLegacyImportService::import($legacy,$tenant,$fid,$mapping,$sessionUser);}

    $stats=[];foreach(['waste_trips','waste_trip_requests','waste_trip_legacy_import_audits','waste_trip_audit_log'] as $t)$stats[$t]=(int)$tenant->query('SELECT COUNT(*) FROM `'.$t.'`')->fetchColumn();
    if($stats!==['waste_trips'=>75,'waste_trip_requests'=>945,'waste_trip_legacy_import_audits'=>75,'waste_trip_audit_log'=>75])failV3('precommit counts '.json_encode($stats));
    if((int)$tenant->query("SELECT COUNT(DISTINCT wtr.feo_request_id) FROM waste_trip_requests wtr JOIN waste_trips wt ON wt.id=wtr.waste_trip_id WHERE wt.source_system='legacy_flights'")->fetchColumn()!==802)failV3('precommit distinct FEO mismatch');
    if((int)$tenant->query("SELECT COUNT(DISTINCT legacy_flight_id) FROM waste_trips WHERE source_system='legacy_flights'")->fetchColumn()!==75)failV3('precommit legacy identity mismatch');
    $destRows=$tenant->query("SELECT destination_type,COUNT(*) n FROM waste_trips WHERE source_system='legacy_flights' GROUP BY destination_type ORDER BY destination_type")->fetchAll();$destCounts=[];foreach($destRows as $r)$destCounts[$r['destination_type']]=(int)$r['n'];ksort($destCounts);if($destCounts!==['utilizer'=>68,'warehouse'=>7])failV3('destination counts '.json_encode($destCounts));
    $statusRows=$tenant->query("SELECT status,COUNT(*) n FROM waste_trips WHERE source_system='legacy_flights' GROUP BY status ORDER BY status")->fetchAll();$newStatus=[];foreach($statusRows as $r)$newStatus[$r['status']]=(int)$r['n'];ksort($newStatus);$expectNew=['completed'=>65,'executor_assigned'=>1,'in_progress'=>3,'searching_executor'=>6];ksort($expectNew);if($newStatus!==$expectNew)failV3('status counts '.json_encode($newStatus));
    $planningStats=verifyPlanningV3($tenant);
    $clientCheck=$tenant->prepare("SELECT COUNT(*) FROM clients WHERE inn=? AND status='active' AND deleted_at IS NULL");$clientCheck->execute(['4714004270']);if((int)$clientCheck->fetchColumn()!==1)failV3('FEO client exact count mismatch');
    if(trim((string)file_get_contents($markerPath))!==$expectedSha)failV3('production marker changed during transaction');
    $tenant->commit();
}catch(Throwable $e){if($tenant->inTransaction())$tenant->rollBack();throw $e;}

$post=[];foreach(['waste_trips','waste_trip_requests','waste_trip_legacy_import_audits','waste_trip_audit_log'] as $t)$post[$t]=(int)$tenant->query('SELECT COUNT(*) FROM `'.$t.'`')->fetchColumn();if($post!==['waste_trips'=>75,'waste_trip_requests'=>945,'waste_trip_legacy_import_audits'=>75,'waste_trip_audit_log'=>75])failV3('postcommit counts '.json_encode($post));
if(trim((string)file_get_contents($markerPath))!==$expectedSha)failV3('production marker changed after commit');
$postPlanning=verifyPlanningV3($tenant);if($postPlanning!==$planningStats)failV3('postcommit planning stats drift');
$pending=WasteTripLegacyImportService::listCandidates($legacy,$tenant,500,false);if(count($pending)!==0)failV3('pending candidates='.count($pending));$allCandidates=WasteTripLegacyImportService::listCandidates($legacy,$tenant,500,true);if(count($allCandidates)!==75)failV3('all candidates='.count($allCandidates));
$repeated=array_filter($occurrences,static fn(int $n):bool=>$n>1);$repeatId=(string)array_key_first($repeated);$s=$tenant->prepare("SELECT COUNT(DISTINCT wtr.waste_trip_id) FROM waste_trip_requests wtr JOIN waste_trips wt ON wt.id=wtr.waste_trip_id JOIN feo_requests fr ON fr.id=wtr.feo_request_id WHERE wt.source_system='legacy_flights' AND fr.zayavka_id=?");$s->execute([$repeatId]);$repeatTrips=(int)$s->fetchColumn();if($repeatTrips<2)failV3('multi-leg preservation failed');
$currentHist=$tenant->query("SELECT fr.zayavka_id FROM feo_placement_current pc JOIN feo_requests fr ON fr.zayavka_id=pc.zayavka_id JOIN waste_trip_requests wtr ON wtr.feo_request_id=fr.id JOIN waste_trips wt ON wt.id=wtr.waste_trip_id WHERE wt.source_system='legacy_flights' ORDER BY fr.zayavka_id LIMIT 1")->fetchColumn();if(is_string($currentHist)&&$currentHist!==''){$preview=WasteTripService::previewSelection($tenant,[$currentHist]);if(($preview['occupied']??[])!==[])failV3('historical link occupies operational preview');}

$evidence=[];foreach($flights as $flight){$fid=(int)$flight['id'];$e=$dryRows[$fid];$e['legacy_flight_id']=$fid;$e['waste_trip_id']=$imported[$fid];$evidence[]=$e;}
file_put_contents($outJson,json_encode(['expected_sha'=>$expectedSha,'client_id'=>$clientId,'client_name'=>$feoClientName,'client_created'=>$createdClient,'utilizer_id'=>8,'responsible_user_id'=>1,'counts'=>$post,'unique_feo_requests'=>802,'trip_request_links'=>945,'repeated_feo_requests'=>143,'route_type_counts'=>$routeCounts,'legacy_status_counts'=>$statusCounts,'planning'=>$postPlanning,'destination_counts'=>['utilizer'=>68,'warehouse'=>7],'max_abs_mass_delta_kg'=>$maxMassDelta,'multi_leg_example_request'=>$repeatId,'multi_leg_example_trip_count'=>$repeatTrips,'rows'=>$evidence],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
$fh=fopen($outCsv,'wb');fputcsv($fh,['legacy_flight_id','waste_trip_id','request_count','route_type','destination_type','mapped_status','planning_mode','partial_range','source_from','source_to','mass_delta_kg','historical_executor_label']);foreach($evidence as $r)fputcsv($fh,[$r['legacy_flight_id'],$r['waste_trip_id'],$r['request_count'],$r['route_type'],$r['destination_type'],$r['mapped_status'],$r['planning_mode'],$r['partial_range']?'1':'0',$r['source_from'],$r['source_to'],$r['mass_delta_kg'],$r['historical_executor_label']]);fclose($fh);

echo 'WASTE_IMPORT_V3_MAPPING=PASS client_id='.$clientId.' client_created='.($createdClient?'1':'0').' utilizer_id=8 responsible_user_id=1'.PHP_EOL;
echo 'WASTE_IMPORT_V3_ATOMIC_COMMIT=PASS trips=75 links=945 audits=75'.PHP_EOL;
echo 'WASTE_IMPORT_V3_PARTIAL_PLAN=PASS partial=15 complete_range=6 unspecified=69'.PHP_EOL;
echo 'WASTE_IMPORT_V3_MULTILEG=PASS request='.$repeatId.' historical_trip_count='.$repeatTrips.PHP_EOL;
echo 'WASTE_IMPORT_V3_OPERATIONAL_CLAIM_ISOLATION=PASS'.PHP_EOL;
echo 'WASTE_IMPORT_V3_PENDING=PASS pending=0 all=75'.PHP_EOL;
echo 'WASTE_IMPORT_V3_CONTROLLED=PASS'.PHP_EOL;
