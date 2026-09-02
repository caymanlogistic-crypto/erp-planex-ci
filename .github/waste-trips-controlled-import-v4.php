<?php

declare(strict_types=1);

use App\Service\WasteTripLegacyImportService;
use App\Service\WasteTripService;

$root = rtrim((string)(getenv('PLANEX_ROOT') ?: ''), '/');
$expectedSha = trim((string)(getenv('EXPECTED_SHA') ?: ''));
$markerPath = (string)(getenv('DEPLOY_MARKER') ?: '');
$candidateList = (string)(getenv('CANDIDATE_LIST') ?: '');
$confirm = (string)(getenv('CONFIRM_IMPORT') ?: '');
$outJson = (string)(getenv('OUT_JSON') ?: '/tmp/waste_controlled_import_v4.json');
$outCsv = (string)(getenv('OUT_CSV') ?: '/tmp/waste_controlled_import_v4.csv');

if ($root === '' || !is_file($root . '/bootstrap/app.php') || !is_file($root . '/.env')) throw new RuntimeException('PLANEX_ROOT invalid');
if (!preg_match('/^[0-9a-f]{40}$/', $expectedSha)) throw new RuntimeException('EXPECTED_SHA invalid');
if ($markerPath === '' || !is_file($markerPath) || trim((string)file_get_contents($markerPath)) !== $expectedSha) throw new RuntimeException('production marker mismatch');
if ($candidateList === '' || !is_file($candidateList)) throw new RuntimeException('legacy candidate list unavailable');
if ($confirm !== 'IMPORT_CURRENT_WASTE_TRIPS_V4') throw new RuntimeException('controlled import confirmation mismatch');

require_once $root . '/app/Support/helpers.php';
require_once $root . '/app/Support/environment.php';
loadEnvFileNonOverwriting($root . '/.env');
$config = require $root . '/bootstrap/app.php';
require_once $root . '/app/Support/entrypoint_dependencies.php';
require_once $root . '/app/Service/WasteTripService.php';
require_once $root . '/app/Service/WasteTripLegacyImportService.php';

function failV4(string $message): never { throw new RuntimeException($message); }
function connectV4(array $c): PDO {
    return new PDO('mysql:host='.$c['host'].';port='.($c['port']??3306).';dbname='.$c['database'].';charset=utf8mb4',$c['username'],$c['password'],[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false,
    ]);
}
function connectLegacySnapshotV4(array $c): PDO {
    $pdo=connectV4($c);
    $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
    return $pdo;
}
function tableExistsV4(PDO $pdo,string $table): bool {
    $s=$pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $s->execute([$table]); return (int)$s->fetchColumn()===1;
}
function columnsV4(PDO $pdo,string $table): array {
    $s=$pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION');
    $s->execute([$table]); return array_column($s->fetchAll(),'COLUMN_NAME');
}
function literalV4(string $src,string $key): ?string {
    $p='~[\'\"]'.preg_quote($key,'~').'[\'\"]\s*=>\s*([\'\"])(.*?)\\1~s';
    return preg_match($p,$src,$m)?stripcslashes((string)$m[2]):null;
}
function configV4(string $src): ?array {
    $h=literalV4($src,'host'); $d=literalV4($src,'dbname'); $u=literalV4($src,'username'); $p=literalV4($src,'password');
    return ($h===null||$d===null||$u===null||$p===null)?null:['host'=>$h,'port'=>3306,'database'=>$d,'username'=>$u,'password'=>$p];
}
function legacyKgV4(mixed $tonnes): float {
    $v=str_replace(',','.',trim((string)$tonnes)); return is_numeric($v)?round(max(0.0,(float)$v)*1000,3):0.0;
}
function legacyDateV4(mixed $value): ?string {
    $v=trim((string)$value); return preg_match('/^\d{4}-\d{2}-\d{2}$/',$v)?$v:null;
}
function routeMappingV4(array $flight,int $utilizerId): array {
    return match(trim((string)($flight['route_type']??''))){
        'generator_to_utilizer','warehouse_to_utilizer'=>['destination_type'=>'utilizer','utilizer_id'=>$utilizerId],
        'generator_to_warehouse'=>['destination_type'=>'warehouse','utilizer_id'=>null],
        default=>failV4('unknown route_type for legacy flight #'.(int)$flight['id']),
    };
}
function historicalLabelV4(array $flight): string {
    return implode(' · ',array_values(array_filter([
        trim((string)($flight['legacy_contractor_name']??'')),
        trim((string)($flight['legacy_driver_name']??'')),
        trim((string)($flight['legacy_vehicle_make_plate']??'')),
    ],static fn(string $v):bool=>$v!=='')));
}
function snapshotRowsV4(PDO $legacy): array {
    $sql="SELECT f.*, c.name AS legacy_contractor_name, d.full_name AS legacy_driver_name, d.vehicle_make_plate AS legacy_vehicle_make_plate, CONCAT_WS(' ',u.`Фамилия`,u.`Имя`) AS legacy_manager_name FROM flights f LEFT JOIN contractors c ON c.id=f.contractor_id LEFT JOIN drivers d ON d.id=f.driver_id LEFT JOIN users u ON u.id=f.assigned_manager_id ORDER BY f.id";
    return $legacy->query($sql)->fetchAll();
}
function snapshotFingerprintV4(array $rows): string {
    $stable=[];
    foreach($rows as $row){ksort($row,SORT_STRING);$stable[]=$row;}
    return hash('sha256',json_encode($stable,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR));
}

$central=connectV4($config['database']);
$company=$central->query("SELECT * FROM companies WHERE id=25 AND status='active' LIMIT 1")->fetch();
if(!$company) failV4('company 25 unavailable');
$tenant=connectV4(companyDatabaseConfig($config,$company));
if(!WasteTripService::schemaReady($tenant)) failV4('Waste Trips schema unavailable');
$m=$tenant->prepare('SELECT COUNT(*) FROM schema_migrations WHERE migration=?');$m->execute(['128_create_waste_trips.sql']);if((int)$m->fetchColumn()!==1)failV4('migration 128 journal mismatch');
$initial=[];foreach(['waste_trips','waste_trip_requests','waste_trip_legacy_import_audits','waste_trip_audit_log'] as $t)$initial[$t]=(int)$tenant->query('SELECT COUNT(*) FROM `'.$t.'`')->fetchColumn();
if(array_sum($initial)!==0) failV4('production Waste Trips not empty before import');

$paths=array_values(array_unique(array_filter(array_map('trim',file($candidateList,FILE_IGNORE_NEW_LINES)?:[]))));$matches=[];
foreach($paths as $path){
    if(!is_file($path)||filesize($path)>524288)continue;
    $src=@file_get_contents($path); if(!is_string($src))continue; $c=configV4($src); if(!$c)continue;
    try{$x=connectLegacySnapshotV4($c);if(!tableExistsV4($x,'flights')){$x->rollBack();continue;}$matches[hash('sha256',$c['host']."\0".$c['database']."\0".$c['username'])]=$c;$x->rollBack();}catch(Throwable){}
}
if(count($matches)!==1) failV4('legacy connection count='.count($matches));
$legacyConfig=array_values($matches)[0];
$legacy=connectLegacySnapshotV4($legacyConfig);
$flights=snapshotRowsV4($legacy);
$snapshotHash=snapshotFingerprintV4($flights);
if(count($flights)!==75) failV4('legacy flights count='.count($flights));

$routeCounts=[];$statusCounts=[];$allIds=[];$occurrences=[];$links=0;$partialCount=0;$completeRangeCount=0;$planningModes=[];$dryRows=[];$maxMassDelta=0.0;
foreach($flights as $flight){
    $fid=(int)$flight['id'];
    $route=trim((string)($flight['route_type']??''));$routeCounts[$route]=($routeCounts[$route]??0)+1;
    $status=trim((string)($flight['status']??''));$statusCounts[$status]=($statusCounts[$status]??0)+1;
    if(WasteTripLegacyImportService::mapLegacyStatus($status)===null) failV4('unknown status on legacy flight #'.$fid);
    if(!in_array(trim((string)($flight['unload_type']??'')),['OO','SKLAD'],true)) failV4('unknown unload_type on legacy flight #'.$fid);
    $ids=WasteTripLegacyImportService::parseLegacyRequestIds((string)($flight['zayavki_ids']??'')); if($ids===[]) failV4('legacy flight without FEO requests #'.$fid);
    $links+=count($ids);foreach($ids as $id){$allIds[$id]=true;$occurrences[$id]=($occurrences[$id]??0)+1;}
    $planning=WasteTripLegacyImportService::resolveLegacyPlanning($flight);$planningModes[$planning['mode']]=($planningModes[$planning['mode']]??0)+1;if(!empty($planning['partial_range']))$partialCount++;if($planning['mode']==='range')$completeRangeCount++;
}
ksort($routeCounts);ksort($statusCounts);ksort($planningModes);
$expectedRoutes=['generator_to_utilizer'=>65,'generator_to_warehouse'=>7,'warehouse_to_utilizer'=>3];ksort($expectedRoutes);if($routeCounts!==$expectedRoutes)failV4('route distribution mismatch '.json_encode($routeCounts));
$expectedStatuses=['completed'=>65,'found'=>1,'planned_route'=>6,'started'=>3];ksort($expectedStatuses);if($statusCounts!==$expectedStatuses)failV4('status distribution mismatch '.json_encode($statusCounts));
if($partialCount!==15||$completeRangeCount!==6)failV4('planning distribution mismatch partial='.$partialCount.' range='.$completeRangeCount);
$repeated=array_filter($occurrences,static fn(int $n):bool=>$n>1);

$requestMap=[];$businessIds=array_keys($allIds);
foreach(array_chunk($businessIds,300) as $chunk){$ph=implode(',',array_fill(0,count($chunk),'?'));$s=$tenant->prepare("SELECT id,zayavka_id,mass_netto,polnoe_naim_zakazchika,inn_zakazchika FROM feo_requests WHERE zayavka_id IN ($ph)");$s->execute($chunk);foreach($s->fetchAll() as $r)$requestMap[(string)$r['zayavka_id']]=$r;}
$missing=array_values(array_diff($businessIds,array_keys($requestMap)));if($missing!==[])failV4('canonical FEO missing count='.count($missing));
$customerInns=[];$customerNames=[];foreach($requestMap as $r){$inn=trim((string)($r['inn_zakazchika']??''));if($inn!=='')$customerInns[$inn]=true;if($inn==='4714004270'){$name=trim((string)($r['polnoe_naim_zakazchika']??''));if($name!=='')$customerNames[$name]=true;}}
if(array_keys($customerInns)!==['4714004270'])failV4('unexpected customer INN set');if(count($customerNames)!==1)failV4('ambiguous FEO customer name count='.count($customerNames));$feoClientName=(string)array_key_first($customerNames);

$requestDbIds=array_map(static fn(array $r):int=>(int)$r['id'],$requestMap);$operationalClaims=0;
foreach(array_chunk($requestDbIds,300) as $chunk){$ph=implode(',',array_fill(0,count($chunk),'?'));$s=$tenant->prepare("SELECT COUNT(*) FROM waste_trip_requests wtr JOIN waste_trips wt ON wt.id=wtr.waste_trip_id WHERE wtr.feo_request_id IN ($ph) AND wt.status<>'cancelled' AND COALESCE(wt.source_system,'')<>'legacy_flights'");$s->execute($chunk);$operationalClaims+=(int)$s->fetchColumn();}
if($operationalClaims!==0)failV4('unexpected operational claims='.$operationalClaims);
$users=$tenant->query("SELECT id,full_name,role_code FROM users WHERE status='active' AND deleted_at IS NULL ORDER BY id")->fetchAll();if(count($users)!==1||(int)$users[0]['id']!==1)failV4('responsible mapping mismatch');$responsible=$users[0];
$utilizerId=0;$utilCols=columnsV4($tenant,'feo_utilizers');if(in_array('inn',$utilCols,true)){$s=$tenant->prepare("SELECT id,short_name,full_name,inn FROM feo_utilizers WHERE is_active=1 AND inn=? ORDER BY id");$s->execute(['7703545931']);$rows=$s->fetchAll();}else{$rows=$tenant->query("SELECT id,short_name,full_name FROM feo_utilizers WHERE is_active=1 AND (UPPER(COALESCE(short_name,'')) LIKE '%ФРЕГАТ%' OR UPPER(COALESCE(full_name,'')) LIKE '%ФРЕГАТ%') ORDER BY id")->fetchAll();}
if(count($rows)!==1||(int)$rows[0]['id']!==8)failV4('FREGAT mapping mismatch');$utilizerId=8;

foreach($flights as $flight){
    $fid=(int)$flight['id'];$ids=WasteTripLegacyImportService::parseLegacyRequestIds((string)$flight['zayavki_ids']);$current=0.0;foreach($ids as $id)$current+=WasteTripService::massKg($requestMap[$id]['mass_netto']??null);$legacyMass=legacyKgV4($flight['total_mass_tonn']??null);$delta=round($current-$legacyMass,3);$maxMassDelta=max($maxMassDelta,abs($delta));
    $dest=routeMappingV4($flight,$utilizerId);$status=WasteTripLegacyImportService::mapLegacyStatus((string)$flight['status']);$planning=WasteTripLegacyImportService::resolveLegacyPlanning($flight);
    $normalized=WasteTripService::normalizeInput(['client_id'=>1,'route_executor_id'=>null,'responsible_user_id'=>(int)$responsible['id'],'destination_type'=>$dest['destination_type'],'utilizer_id'=>$dest['utilizer_id'],'status'=>$status,'planned_start_mode'=>$planning['mode'],'planned_start_date'=>$planning['exact'],'planned_start_date_from'=>$planning['from'],'planned_start_date_to'=>$planning['to'],'actual_start_date'=>legacyDateV4($flight['actual_start_date']??null),'actual_end_date'=>legacyDateV4($flight['actual_end_date']??null),'comment'=>(string)($flight['comment']??''),'cancellation_reason'=>null]);
    $errors=WasteTripService::validateInput($normalized,true,true);if($errors!==[])failV4('semantic validation failed on legacy flight #'.$fid.': '.implode(' ',$errors));
    $dryRows[$fid]=['request_count'=>count($ids),'route_type'=>(string)$flight['route_type'],'destination_type'=>$dest['destination_type'],'mapped_status'=>$status,'planning_mode'=>$planning['mode'],'partial_range'=>(bool)$planning['partial_range'],'mass_delta_kg'=>$delta,'historical_executor_label'=>historicalLabelV4($flight)];
}

$clientStmt=$tenant->prepare('SELECT id,name,inn,status,deleted_at FROM clients WHERE inn=? ORDER BY id');$clientStmt->execute(['4714004270']);$existingClients=$clientStmt->fetchAll();if(count($existingClients)>1)failV4('ambiguous FEO client count='.count($existingClients));if(count($existingClients)===1&&((string)$existingClients[0]['status']!=='active'||$existingClients[0]['deleted_at']!==null))failV4('existing FEO client not active');

$tenant->beginTransaction();$createdClient=false;$clientId=0;$imported=[];
try{
    $clientStmt=$tenant->prepare('SELECT id,name,inn,status,deleted_at FROM clients WHERE inn=? FOR UPDATE');$clientStmt->execute(['4714004270']);$clients=$clientStmt->fetchAll();if(count($clients)>1)failV4('FEO client race ambiguity');
    if(count($clients)===1)$clientId=(int)$clients[0]['id'];else{$insert=$tenant->prepare('INSERT INTO clients (name,inn,kpp,ogrn,entity_type,legal_address,physical_address,bank_account,bank_name,bank_bik,bank_corr_account,director_full_name,director_position,status,comments,created_by_user_id,created_by_role,updated_by_user_id,updated_by_role) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');$insert->execute([$feoClientName,'4714004270',null,null,null,null,null,null,null,null,null,null,null,'active','Создан автоматически при контролируемом импорте legacy Waste Trips; source customer INN 4714004270',(int)$responsible['id'],(string)$responsible['role_code'],(int)$responsible['id'],(string)$responsible['role_code']]);$clientId=(int)$tenant->lastInsertId();$createdClient=true;}
    if($clientId<=0)failV4('FEO client id unavailable');$sessionUser=['user_id'=>(int)$responsible['id'],'role_code'=>(string)$responsible['role_code']];
    foreach($flights as $flight){$fid=(int)$flight['id'];$dest=routeMappingV4($flight,$utilizerId);$mapping=['client_id'=>$clientId,'route_executor_id'=>null,'responsible_user_id'=>(int)$responsible['id'],'destination_type'=>$dest['destination_type'],'utilizer_id'=>$dest['utilizer_id'],'allow_unmapped_historical_executor'=>true];$imported[$fid]=WasteTripLegacyImportService::import($legacy,$tenant,$fid,$mapping,$sessionUser);}

    $stats=[];foreach(['waste_trips','waste_trip_requests','waste_trip_legacy_import_audits','waste_trip_audit_log'] as $t)$stats[$t]=(int)$tenant->query('SELECT COUNT(*) FROM `'.$t.'`')->fetchColumn();
    if($stats['waste_trips']!==75||$stats['waste_trip_requests']!==$links||$stats['waste_trip_legacy_import_audits']!==75||$stats['waste_trip_audit_log']!==75)failV4('precommit row counts mismatch '.json_encode($stats));
    $distinctFeo=(int)$tenant->query("SELECT COUNT(DISTINCT wtr.feo_request_id) FROM waste_trip_requests wtr JOIN waste_trips wt ON wt.id=wtr.waste_trip_id WHERE wt.source_system='legacy_flights'")->fetchColumn();if($distinctFeo!==count($allIds))failV4('precommit distinct FEO='.$distinctFeo);
    $legacyUnique=(int)$tenant->query("SELECT COUNT(DISTINCT legacy_flight_id) FROM waste_trips WHERE source_system='legacy_flights'")->fetchColumn();if($legacyUnique!==75)failV4('precommit legacy unique='.$legacyUnique);
    $destRows=$tenant->query("SELECT destination_type,COUNT(*) n FROM waste_trips WHERE source_system='legacy_flights' GROUP BY destination_type ORDER BY destination_type")->fetchAll();$destCounts=[];foreach($destRows as $r)$destCounts[$r['destination_type']]=(int)$r['n'];ksort($destCounts);if($destCounts!==['utilizer'=>68,'warehouse'=>7])failV4('precommit destination counts '.json_encode($destCounts));
    $newStatusRows=$tenant->query("SELECT status,COUNT(*) n FROM waste_trips WHERE source_system='legacy_flights' GROUP BY status ORDER BY status")->fetchAll();$newStatus=[];foreach($newStatusRows as $r)$newStatus[$r['status']]=(int)$r['n'];ksort($newStatus);$expectNew=['completed'=>65,'executor_assigned'=>1,'in_progress'=>3,'searching_executor'=>6];ksort($expectNew);if($newStatus!==$expectNew)failV4('precommit status counts '.json_encode($newStatus));
    $planRows=$tenant->query("SELECT planned_start_mode,COUNT(*) n FROM waste_trips WHERE source_system='legacy_flights' GROUP BY planned_start_mode ORDER BY planned_start_mode")->fetchAll();$planCounts=[];foreach($planRows as $r)$planCounts[$r['planned_start_mode']]=(int)$r['n'];ksort($planCounts);$expectPlan=['range'=>6,'unspecified'=>69];ksort($expectPlan);if($planCounts!==$expectPlan)failV4('precommit planning counts '.json_encode($planCounts));
    $normalizedAudit=(int)$tenant->query("SELECT COUNT(*) FROM waste_trip_legacy_import_audits WHERE source_system='legacy_flights' AND JSON_UNQUOTE(JSON_EXTRACT(legacy_payload_json,'$.legacy_planning_normalization.reason'))='partial_range_not_representable'")->fetchColumn();if($normalizedAudit!==15)failV4('partial planning audit count='.$normalizedAudit);
    if(trim((string)file_get_contents($markerPath))!==$expectedSha)failV4('production marker changed during transaction');

    $freshLegacy=connectLegacySnapshotV4($legacyConfig);$freshRows=snapshotRowsV4($freshLegacy);$freshHash=snapshotFingerprintV4($freshRows);$freshLegacy->rollBack();
    if($freshHash!==$snapshotHash)failV4('legacy snapshot changed before commit old='.$snapshotHash.' new='.$freshHash);

    $tenant->commit();
}catch(Throwable $e){if($tenant->inTransaction())$tenant->rollBack();throw $e;}

$post=[];foreach(['waste_trips','waste_trip_requests','waste_trip_legacy_import_audits','waste_trip_audit_log'] as $t)$post[$t]=(int)$tenant->query('SELECT COUNT(*) FROM `'.$t.'`')->fetchColumn();if($post['waste_trips']!==75||$post['waste_trip_requests']!==$links||$post['waste_trip_legacy_import_audits']!==75||$post['waste_trip_audit_log']!==75)failV4('postcommit row counts mismatch '.json_encode($post));
if(trim((string)file_get_contents($markerPath))!==$expectedSha)failV4('production marker changed after commit');
$trips=WasteTripService::fetchTrips($tenant,[]);if(count($trips)!==75)failV4('fetchTrips count='.count($trips));
$pending=WasteTripLegacyImportService::listCandidates($legacy,$tenant,500,false);if(count($pending)!==0)failV4('pending legacy candidates after import='.count($pending));$allCandidates=WasteTripLegacyImportService::listCandidates($legacy,$tenant,500,true);if(count($allCandidates)!==75)failV4('all legacy candidates after import='.count($allCandidates));
$repeatId=(string)array_key_first($repeated);$s=$tenant->prepare("SELECT COUNT(DISTINCT wtr.waste_trip_id) FROM waste_trip_requests wtr JOIN waste_trips wt ON wt.id=wtr.waste_trip_id JOIN feo_requests fr ON fr.id=wtr.feo_request_id WHERE wt.source_system='legacy_flights' AND fr.zayavka_id=?");$s->execute([$repeatId]);$repeatTrips=(int)$s->fetchColumn();if($repeatTrips<2)failV4('multi-leg preservation failed for '.$repeatId);
$currentHist=$tenant->query("SELECT fr.zayavka_id FROM feo_placement_current pc JOIN feo_requests fr ON fr.zayavka_id=pc.zayavka_id JOIN waste_trip_requests wtr ON wtr.feo_request_id=fr.id JOIN waste_trips wt ON wt.id=wtr.waste_trip_id WHERE wt.source_system='legacy_flights' ORDER BY fr.zayavka_id LIMIT 1")->fetchColumn();if(is_string($currentHist)&&$currentHist!==''){$preview=WasteTripService::previewSelection($tenant,[$currentHist]);if(($preview['occupied']??[])!==[])failV4('historical-only link incorrectly occupies operational preview');}

$evidence=[];foreach($flights as $flight){$fid=(int)$flight['id'];$e=$dryRows[$fid];$e['legacy_flight_id']=$fid;$e['waste_trip_id']=$imported[$fid];$evidence[]=$e;}
file_put_contents($outJson,json_encode(['expected_sha'=>$expectedSha,'legacy_snapshot_sha256'=>$snapshotHash,'client_id'=>$clientId,'client_name'=>$feoClientName,'client_created'=>$createdClient,'utilizer_id'=>$utilizerId,'responsible_user_id'=>(int)$responsible['id'],'responsible_role'=>(string)$responsible['role_code'],'counts'=>$post,'unique_feo_requests'=>count($allIds),'trip_request_links'=>$links,'repeated_feo_requests'=>count($repeated),'partial_planning_count'=>$partialCount,'complete_range_count'=>$completeRangeCount,'route_type_counts'=>$routeCounts,'legacy_status_counts'=>$statusCounts,'planning_mode_counts'=>$planningModes,'destination_counts'=>['utilizer'=>68,'warehouse'=>7],'max_abs_mass_delta_kg'=>$maxMassDelta,'multi_leg_example_request'=>$repeatId,'multi_leg_example_trip_count'=>$repeatTrips,'rows'=>$evidence],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
$fh=fopen($outCsv,'wb');fputcsv($fh,['legacy_flight_id','waste_trip_id','request_count','route_type','destination_type','mapped_status','planning_mode','partial_range','mass_delta_kg','historical_executor_label']);foreach($evidence as $r)fputcsv($fh,[$r['legacy_flight_id'],$r['waste_trip_id'],$r['request_count'],$r['route_type'],$r['destination_type'],$r['mapped_status'],$r['planning_mode'],$r['partial_range']?'1':'0',$r['mass_delta_kg'],$r['historical_executor_label']]);fclose($fh);

echo 'WASTE_IMPORT_V4_SNAPSHOT=PASS sha256='.$snapshotHash.' flights=75 links='.$links.' unique_feo='.count($allIds).' repeated_feo='.count($repeated).PHP_EOL;
echo 'WASTE_IMPORT_V4_PREFLIGHT=PASS partial=15 complete_range=6 max_abs_mass_delta_kg='.number_format($maxMassDelta,3,'.','').PHP_EOL;
echo 'WASTE_IMPORT_V4_MAPPING=PASS client_id='.$clientId.' client_created='.($createdClient?'1':'0').' utilizer_id=8 responsible_user_id=1'.PHP_EOL;
echo 'WASTE_IMPORT_V4_ATOMIC_COMMIT=PASS trips=75 links='.$links.' audits=75'.PHP_EOL;
echo 'WASTE_IMPORT_V4_PARTIAL_PLAN=PASS partial=15 complete_range=6 unspecified=69'.PHP_EOL;
echo 'WASTE_IMPORT_V4_MULTILEG=PASS request='.$repeatId.' historical_trip_count='.$repeatTrips.PHP_EOL;
echo 'WASTE_IMPORT_V4_OPERATIONAL_CLAIM_ISOLATION=PASS'.PHP_EOL;
echo 'WASTE_IMPORT_V4_PENDING=PASS pending=0 all=75'.PHP_EOL;
echo 'WASTE_IMPORT_V4_CONTROLLED=PASS'.PHP_EOL;
