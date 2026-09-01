<?php

declare(strict_types=1);

use App\Service\WasteTripLegacyImportService;
use App\Service\WasteTripService;

$root = rtrim((string)(getenv('PLANEX_ROOT') ?: ''), '/');
$expectedSha = trim((string)(getenv('EXPECTED_SHA') ?: ''));
$markerPath = (string)(getenv('DEPLOY_MARKER') ?: '');
$candidateList = (string)(getenv('CANDIDATE_LIST') ?: '');
$confirm = (string)(getenv('CONFIRM_IMPORT') ?: '');
$outJson = (string)(getenv('OUT_JSON') ?: '/tmp/waste_controlled_import.json');
$outCsv = (string)(getenv('OUT_CSV') ?: '/tmp/waste_controlled_import.csv');

if ($root === '' || !is_file($root . '/bootstrap/app.php') || !is_file($root . '/.env')) throw new RuntimeException('PLANEX_ROOT invalid');
if (!preg_match('/^[0-9a-f]{40}$/', $expectedSha)) throw new RuntimeException('EXPECTED_SHA invalid');
if ($markerPath === '' || !is_file($markerPath) || trim((string)file_get_contents($markerPath)) !== $expectedSha) throw new RuntimeException('production marker mismatch');
if ($candidateList === '' || !is_file($candidateList)) throw new RuntimeException('legacy candidate list unavailable');
if ($confirm !== 'IMPORT_75_WASTE_TRIPS') throw new RuntimeException('controlled import confirmation mismatch');

require_once $root . '/app/Support/helpers.php';
require_once $root . '/app/Support/environment.php';
loadEnvFileNonOverwriting($root . '/.env');
$config = require $root . '/bootstrap/app.php';
require_once $root . '/app/Support/entrypoint_dependencies.php';
require_once $root . '/app/Service/WasteTripService.php';
require_once $root . '/app/Service/WasteTripLegacyImportService.php';

function failImport(string $message): never { throw new RuntimeException($message); }
function connectRw(array $c): PDO {
    return new PDO('mysql:host='.$c['host'].';port='.($c['port']??3306).';dbname='.$c['database'].';charset=utf8mb4',$c['username'],$c['password'],[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false,
    ]);
}
function connectRo(array $c): PDO {
    $pdo=connectRw($c);
    $pdo->exec('SET SESSION TRANSACTION READ ONLY');
    $pdo->exec('START TRANSACTION READ ONLY');
    return $pdo;
}
function tableExistsImport(PDO $pdo,string $table): bool {
    $s=$pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $s->execute([$table]); return (int)$s->fetchColumn()===1;
}
function columnsImport(PDO $pdo,string $table): array {
    $s=$pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION');
    $s->execute([$table]); return array_column($s->fetchAll(),'COLUMN_NAME');
}
function literalImport(string $src,string $key): ?string {
    $p='~[\'\"]'.preg_quote($key,'~').'[\'\"]\s*=>\s*([\'\"])(.*?)\\1~s';
    return preg_match($p,$src,$m)?stripcslashes((string)$m[2]):null;
}
function configImport(string $src): ?array {
    $h=literalImport($src,'host'); $d=literalImport($src,'dbname'); $u=literalImport($src,'username'); $p=literalImport($src,'password');
    return ($h===null||$d===null||$u===null||$p===null)?null:['host'=>$h,'port'=>3306,'database'=>$d,'username'=>$u,'password'=>$p];
}
function legacyKg(mixed $tonnes): float {
    $v=str_replace(',','.',trim((string)$tonnes)); return is_numeric($v)?round(max(0.0,(float)$v)*1000,3):0.0;
}
function legacyDateImport(mixed $value): ?string {
    $v=trim((string)$value); return preg_match('/^\d{4}-\d{2}-\d{2}$/',$v)?$v:null;
}
function routeMappingImport(array $flight,int $utilizerId): array {
    return match(trim((string)($flight['route_type']??''))){
        'generator_to_utilizer','warehouse_to_utilizer'=>['destination_type'=>'utilizer','utilizer_id'=>$utilizerId],
        'generator_to_warehouse'=>['destination_type'=>'warehouse','utilizer_id'=>null],
        default=>failImport('unknown route_type for legacy flight #'.(int)$flight['id']),
    };
}
function historicalLabelImport(array $flight): string {
    return implode(' · ',array_values(array_filter([
        trim((string)($flight['legacy_contractor_name']??'')),
        trim((string)($flight['legacy_driver_name']??'')),
        trim((string)($flight['legacy_vehicle_make_plate']??'')),
    ],static fn(string $v):bool=>$v!=='')));
}

// Central and tenant production connections. Tenant remains write-capable only for the single guarded transaction below.
$central=connectRw($config['database']);
$company=$central->query("SELECT * FROM companies WHERE id=25 AND status='active' LIMIT 1")->fetch();
if(!$company) failImport('company 25 unavailable');
$tenant=connectRw(companyDatabaseConfig($config,$company));
if(!WasteTripService::schemaReady($tenant)) failImport('Waste Trips schema unavailable');
$m=$tenant->prepare('SELECT COUNT(*) FROM schema_migrations WHERE migration=?');$m->execute(['128_create_waste_trips.sql']);if((int)$m->fetchColumn()!==1)failImport('migration 128 journal mismatch');

$initial=[];
foreach(['waste_trips','waste_trip_requests','waste_trip_legacy_import_audits','waste_trip_audit_log'] as $t){$initial[$t]=(int)$tenant->query('SELECT COUNT(*) FROM `'.$t.'`')->fetchColumn();}
if(array_sum($initial)!==0) failImport('production Waste Trips not empty before import');

// Find exactly one legacy flights database without logging any credential material.
$paths=array_values(array_unique(array_filter(array_map('trim',file($candidateList,FILE_IGNORE_NEW_LINES)?:[]))));
$matches=[];
foreach($paths as $path){
    if(!is_file($path)||filesize($path)>524288)continue;
    $src=@file_get_contents($path); if(!is_string($src))continue; $c=configImport($src); if(!$c)continue;
    try{$x=connectRo($c);if(!tableExistsImport($x,'flights'))continue;$matches[hash('sha256',$c['host']."\0".$c['database']."\0".$c['username'])]=$c;}catch(Throwable){}
}
if(count($matches)!==1) failImport('legacy connection count='.count($matches));
$legacy=connectRo(array_values($matches)[0]);

$legacySql="SELECT f.*, c.name AS legacy_contractor_name, d.full_name AS legacy_driver_name, d.vehicle_make_plate AS legacy_vehicle_make_plate, CONCAT_WS(' ',u.`Фамилия`,u.`Имя`) AS legacy_manager_name FROM flights f LEFT JOIN contractors c ON c.id=f.contractor_id LEFT JOIN drivers d ON d.id=f.driver_id LEFT JOIN users u ON u.id=f.assigned_manager_id ORDER BY f.id";
$flights=$legacy->query($legacySql)->fetchAll();
if(count($flights)!==75) failImport('legacy flights count='.count($flights));

$routeCounts=[];$statusCounts=[];$allIds=[];$occurrences=[];$flightIds=[];$links=0;
foreach($flights as $flight){
    $fid=(int)$flight['id']; $flightIds[]=$fid;
    $route=trim((string)($flight['route_type']??'')); $routeCounts[$route]=($routeCounts[$route]??0)+1;
    $status=trim((string)($flight['status']??'')); $statusCounts[$status]=($statusCounts[$status]??0)+1;
    if(WasteTripLegacyImportService::mapLegacyStatus($status)===null) failImport('unknown status on legacy flight #'.$fid);
    if(!in_array(trim((string)($flight['unload_type']??'')),['OO','SKLAD'],true)) failImport('unknown unload_type on legacy flight #'.$fid);
    $ids=WasteTripLegacyImportService::parseLegacyRequestIds((string)($flight['zayavki_ids']??''));
    if($ids===[]) failImport('legacy flight without FEO requests #'.$fid);
    $links+=count($ids);
    foreach($ids as $id){$allIds[$id]=true;$occurrences[$id]=($occurrences[$id]??0)+1;}
}
ksort($routeCounts);ksort($statusCounts);
if($links!==945) failImport('legacy links count='.$links);
if(count($allIds)!==802) failImport('legacy unique FEO count='.count($allIds));
$repeated=array_filter($occurrences,static fn(int $n):bool=>$n>1);
if(count($repeated)!==143) failImport('legacy repeated FEO unique count='.count($repeated));
$expectedRoutes=['generator_to_utilizer'=>65,'generator_to_warehouse'=>7,'warehouse_to_utilizer'=>3];ksort($expectedRoutes);
if($routeCounts!==$expectedRoutes) failImport('route type distribution mismatch '.json_encode($routeCounts));
$expectedStatuses=['completed'=>65,'found'=>1,'planned_route'=>6,'started'=>3];ksort($expectedStatuses);
if($statusCounts!==$expectedStatuses) failImport('legacy status distribution mismatch '.json_encode($statusCounts));

// Resolve all canonical FEO requests and customer truth.
$requestMap=[];$businessIds=array_keys($allIds);
foreach(array_chunk($businessIds,300) as $chunk){
    $ph=implode(',',array_fill(0,count($chunk),'?'));
    $s=$tenant->prepare("SELECT id,zayavka_id,mass_netto,polnoe_naim_zakazchika,inn_zakazchika FROM feo_requests WHERE zayavka_id IN ($ph)");
    $s->execute($chunk); foreach($s->fetchAll() as $r)$requestMap[(string)$r['zayavka_id']]=$r;
}
$missing=array_values(array_diff($businessIds,array_keys($requestMap)));
if($missing!==[]) failImport('canonical FEO missing count='.count($missing));

$customerInns=[];$customerNames=[];
foreach($requestMap as $r){
    $inn=trim((string)($r['inn_zakazchika']??'')); if($inn!=='')$customerInns[$inn]=true;
    if($inn==='4714004270'){$name=trim((string)($r['polnoe_naim_zakazchika']??''));if($name!=='')$customerNames[$name]=true;}
}
if(array_keys($customerInns)!==['4714004270']) failImport('unexpected customer INN set '.json_encode(array_keys($customerInns),JSON_UNESCAPED_UNICODE));
if(count($customerNames)!==1) failImport('ambiguous FEO customer name count='.count($customerNames));
$feoClientName=(string)array_key_first($customerNames);

// Current operational claims must be absent before migration.
$requestDbIds=array_map(static fn(array $r):int=>(int)$r['id'],$requestMap);
$operationalClaims=0;
foreach(array_chunk($requestDbIds,300) as $chunk){
    $ph=implode(',',array_fill(0,count($chunk),'?'));
    $s=$tenant->prepare("SELECT COUNT(*) FROM waste_trip_requests wtr JOIN waste_trips wt ON wt.id=wtr.waste_trip_id WHERE wtr.feo_request_id IN ($ph) AND wt.status<>'cancelled' AND COALESCE(wt.source_system,'')<>'legacy_flights'");
    $s->execute($chunk);$operationalClaims+=(int)$s->fetchColumn();
}
if($operationalClaims!==0) failImport('unexpected operational claims='.$operationalClaims);

// Exact production mapping entities.
$users=$tenant->query("SELECT id,full_name,role_code FROM users WHERE status='active' AND deleted_at IS NULL ORDER BY id")->fetchAll();
if(count($users)!==1) failImport('active responsible user count='.count($users));
$responsible=$users[0];
$utilizerId=0;
$utilCols=columnsImport($tenant,'feo_utilizers');
if(in_array('inn',$utilCols,true)){
    $s=$tenant->prepare("SELECT id,short_name,full_name,inn FROM feo_utilizers WHERE is_active=1 AND inn=? ORDER BY id");$s->execute(['7703545931']);$rows=$s->fetchAll();
}else{
    $rows=$tenant->query("SELECT id,short_name,full_name FROM feo_utilizers WHERE is_active=1 AND (UPPER(COALESCE(short_name,'')) LIKE '%ФРЕГАТ%' OR UPPER(COALESCE(full_name,'')) LIKE '%ФРЕГАТ%') ORDER BY id")->fetchAll();
}
if(count($rows)!==1) failImport('FREGAT utilizer mapping count='.count($rows));
$utilizerId=(int)$rows[0]['id']; if($utilizerId!==8) failImport('unexpected FREGAT utilizer id='.$utilizerId);

// Full non-writing semantic validation before transaction.
$maxMassDelta=0.0;$dryRows=[];
foreach($flights as $flight){
    $fid=(int)$flight['id'];$ids=WasteTripLegacyImportService::parseLegacyRequestIds((string)$flight['zayavki_ids']);
    $current=0.0;foreach($ids as $id)$current+=WasteTripService::massKg($requestMap[$id]['mass_netto']??null);
    $legacyMass=legacyKg($flight['total_mass_tonn']??null);$delta=round($current-$legacyMass,3);$maxMassDelta=max($maxMassDelta,abs($delta));
    if(abs($delta)>1.0) failImport('mass delta >1kg on legacy flight #'.$fid.' delta='.$delta);
    $dest=routeMappingImport($flight,$utilizerId);
    $status=WasteTripLegacyImportService::mapLegacyStatus((string)$flight['status']);
    $pe=legacyDateImport($flight['planned_start_date']??null);$pf=legacyDateImport($flight['planned_start_date_from']??null);$pt=legacyDateImport($flight['planned_start_date_to']??null);
    $mode=$pe!==null?'exact':(($pf!==null&&$pt!==null)?'range':'unspecified');
    $normalized=WasteTripService::normalizeInput([
        'client_id'=>1,'route_executor_id'=>null,'responsible_user_id'=>(int)$responsible['id'],
        'destination_type'=>$dest['destination_type'],'utilizer_id'=>$dest['utilizer_id'],'status'=>$status,
        'planned_start_mode'=>$mode,'planned_start_date'=>$pe,'planned_start_date_from'=>$pf,'planned_start_date_to'=>$pt,
        'actual_start_date'=>legacyDateImport($flight['actual_start_date']??null),'actual_end_date'=>legacyDateImport($flight['actual_end_date']??null),
        'comment'=>(string)($flight['comment']??''),'cancellation_reason'=>null,
    ]);
    $errors=WasteTripService::validateInput($normalized,true,true);if($errors!==[])failImport('semantic validation failed on legacy flight #'.$fid.': '.implode(' ',$errors));
    $dryRows[$fid]=['request_count'=>count($ids),'route_type'=>(string)$flight['route_type'],'destination_type'=>$dest['destination_type'],'mapped_status'=>$status,'mass_delta_kg'=>$delta,'historical_executor_label'=>historicalLabelImport($flight)];
}

$clientStmt=$tenant->prepare('SELECT id,name,inn,status,deleted_at FROM clients WHERE inn=? ORDER BY id');$clientStmt->execute(['4714004270']);$existingClients=$clientStmt->fetchAll();
if(count($existingClients)>1) failImport('ambiguous FEO client count='.count($existingClients));
if(count($existingClients)===1 && ((string)$existingClients[0]['status']!=='active' || $existingClients[0]['deleted_at']!==null)) failImport('existing FEO client not active');

// -------- ATOMIC WRITE BOUNDARY --------
$tenant->beginTransaction();
$createdClient=false;$clientId=0;$imported=[];
try{
    $clientStmt=$tenant->prepare('SELECT id,name,inn,status,deleted_at FROM clients WHERE inn=? FOR UPDATE');$clientStmt->execute(['4714004270']);$clients=$clientStmt->fetchAll();
    if(count($clients)>1)failImport('FEO client race ambiguity');
    if(count($clients)===1){$clientId=(int)$clients[0]['id'];}
    else{
        $insert=$tenant->prepare('INSERT INTO clients (name,inn,kpp,ogrn,entity_type,legal_address,physical_address,bank_account,bank_name,bank_bik,bank_corr_account,director_full_name,director_position,status,comments,created_by_user_id,created_by_role,updated_by_user_id,updated_by_role) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $insert->execute([$feoClientName,'4714004270',null,null,null,null,null,null,null,null,null,null,null,'active','Создан автоматически при контролируемом импорте legacy Waste Trips; source customer INN 4714004270',(int)$responsible['id'],(string)$responsible['role_code'],(int)$responsible['id'],(string)$responsible['role_code']]);
        $clientId=(int)$tenant->lastInsertId();$createdClient=true;
    }
    if($clientId<=0)failImport('FEO client id unavailable');

    $sessionUser=['user_id'=>(int)$responsible['id'],'role_code'=>(string)$responsible['role_code']];
    foreach($flights as $flight){
        $fid=(int)$flight['id'];$dest=routeMappingImport($flight,$utilizerId);
        $mapping=['client_id'=>$clientId,'route_executor_id'=>null,'responsible_user_id'=>(int)$responsible['id'],'destination_type'=>$dest['destination_type'],'utilizer_id'=>$dest['utilizer_id'],'allow_unmapped_historical_executor'=>true];
        $tripId=WasteTripLegacyImportService::import($legacy,$tenant,$fid,$mapping,$sessionUser);
        $imported[$fid]=$tripId;
    }

    // Pre-commit assertions inside the same transaction.
    $stats=[];
    foreach(['waste_trips','waste_trip_requests','waste_trip_legacy_import_audits','waste_trip_audit_log'] as $t)$stats[$t]=(int)$tenant->query('SELECT COUNT(*) FROM `'.$t.'`')->fetchColumn();
    if($stats!==['waste_trips'=>75,'waste_trip_requests'=>945,'waste_trip_legacy_import_audits'=>75,'waste_trip_audit_log'=>75])failImport('precommit row counts mismatch '.json_encode($stats));
    $distinctFeo=(int)$tenant->query("SELECT COUNT(DISTINCT wtr.feo_request_id) FROM waste_trip_requests wtr JOIN waste_trips wt ON wt.id=wtr.waste_trip_id WHERE wt.source_system='legacy_flights'")->fetchColumn();if($distinctFeo!==802)failImport('precommit distinct FEO='.$distinctFeo);
    $legacyUnique=(int)$tenant->query("SELECT COUNT(DISTINCT legacy_flight_id) FROM waste_trips WHERE source_system='legacy_flights'")->fetchColumn();if($legacyUnique!==75)failImport('precommit legacy unique='.$legacyUnique);
    $destRows=$tenant->query("SELECT destination_type,COUNT(*) n FROM waste_trips WHERE source_system='legacy_flights' GROUP BY destination_type ORDER BY destination_type")->fetchAll();$destCounts=[];foreach($destRows as $r)$destCounts[$r['destination_type']]=(int)$r['n'];ksort($destCounts);if($destCounts!==['utilizer'=>68,'warehouse'=>7])failImport('precommit destination counts '.json_encode($destCounts));
    $newStatusRows=$tenant->query("SELECT status,COUNT(*) n FROM waste_trips WHERE source_system='legacy_flights' GROUP BY status ORDER BY status")->fetchAll();$newStatus=[];foreach($newStatusRows as $r)$newStatus[$r['status']]=(int)$r['n'];ksort($newStatus);$expectNew=['completed'=>65,'executor_assigned'=>1,'in_progress'=>3,'searching_executor'=>6];ksort($expectNew);if($newStatus!==$expectNew)failImport('precommit status counts '.json_encode($newStatus));
    $auditPayloads=(int)$tenant->query("SELECT COUNT(*) FROM waste_trip_legacy_import_audits WHERE source_system='legacy_flights' AND legacy_payload_json IS NOT NULL AND legacy_payload_json<>'' AND mapping_json IS NOT NULL AND mapping_json<>''")->fetchColumn();if($auditPayloads!==75)failImport('precommit audit payload count='.$auditPayloads);
    $clientCheck=$tenant->prepare('SELECT COUNT(*) FROM clients WHERE inn=? AND status=\'active\' AND deleted_at IS NULL');$clientCheck->execute(['4714004270']);if((int)$clientCheck->fetchColumn()!==1)failImport('precommit FEO client exact count mismatch');
    if(trim((string)file_get_contents($markerPath))!==$expectedSha)failImport('production marker changed during transaction');

    $tenant->commit();
}catch(Throwable $e){
    if($tenant->inTransaction())$tenant->rollBack();
    throw $e;
}

// Post-commit verification, still no further writes.
$post=[];foreach(['waste_trips','waste_trip_requests','waste_trip_legacy_import_audits','waste_trip_audit_log'] as $t)$post[$t]=(int)$tenant->query('SELECT COUNT(*) FROM `'.$t.'`')->fetchColumn();
if($post!==['waste_trips'=>75,'waste_trip_requests'=>945,'waste_trip_legacy_import_audits'=>75,'waste_trip_audit_log'=>75])failImport('postcommit row counts mismatch '.json_encode($post));
if(trim((string)file_get_contents($markerPath))!==$expectedSha)failImport('production marker changed after commit');
$trips=WasteTripService::fetchTrips($tenant,[]);if(count($trips)!==75)failImport('fetchTrips count='.count($trips));
$pending=WasteTripLegacyImportService::listCandidates($legacy,$tenant,500,false);if(count($pending)!==0)failImport('pending legacy candidates after import='.count($pending));
$allCandidates=WasteTripLegacyImportService::listCandidates($legacy,$tenant,500,true);if(count($allCandidates)!==75)failImport('all legacy candidates after import='.count($allCandidates));

$repeatId=(string)array_key_first($repeated);$s=$tenant->prepare("SELECT COUNT(DISTINCT wtr.waste_trip_id) FROM waste_trip_requests wtr JOIN waste_trips wt ON wt.id=wtr.waste_trip_id JOIN feo_requests fr ON fr.id=wtr.feo_request_id WHERE wt.source_system='legacy_flights' AND fr.zayavka_id=?");$s->execute([$repeatId]);$repeatTrips=(int)$s->fetchColumn();if($repeatTrips<2)failImport('multi-leg preservation failed for '.$repeatId);
$currentHist=$tenant->query("SELECT fr.zayavka_id FROM feo_placement_current pc JOIN feo_requests fr ON fr.zayavka_id=pc.zayavka_id JOIN waste_trip_requests wtr ON wtr.feo_request_id=fr.id JOIN waste_trips wt ON wt.id=wtr.waste_trip_id WHERE wt.source_system='legacy_flights' ORDER BY fr.zayavka_id LIMIT 1")->fetchColumn();
if(is_string($currentHist)&&$currentHist!==''){$preview=WasteTripService::previewSelection($tenant,[$currentHist]);if(($preview['occupied']??[])!==[])failImport('historical-only link incorrectly occupies operational preview');}

$evidence=[];
foreach($flights as $flight){$fid=(int)$flight['id'];$e=$dryRows[$fid];$e['legacy_flight_id']=$fid;$e['waste_trip_id']=$imported[$fid];$evidence[]=$e;}
file_put_contents($outJson,json_encode([
    'expected_sha'=>$expectedSha,'client_id'=>$clientId,'client_name'=>$feoClientName,'client_created'=>$createdClient,'utilizer_id'=>$utilizerId,
    'responsible_user_id'=>(int)$responsible['id'],'responsible_role'=>(string)$responsible['role_code'],'counts'=>$post,
    'unique_feo_requests'=>802,'trip_request_links'=>945,'repeated_feo_requests'=>143,'route_type_counts'=>$routeCounts,'legacy_status_counts'=>$statusCounts,
    'destination_counts'=>['utilizer'=>68,'warehouse'=>7],'max_abs_mass_delta_kg'=>$maxMassDelta,'multi_leg_example_request'=>$repeatId,'multi_leg_example_trip_count'=>$repeatTrips,
    'rows'=>$evidence,
],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
$fh=fopen($outCsv,'wb');fputcsv($fh,['legacy_flight_id','waste_trip_id','request_count','route_type','destination_type','mapped_status','mass_delta_kg','historical_executor_label']);foreach($evidence as $r)fputcsv($fh,[$r['legacy_flight_id'],$r['waste_trip_id'],$r['request_count'],$r['route_type'],$r['destination_type'],$r['mapped_status'],$r['mass_delta_kg'],$r['historical_executor_label']]);fclose($fh);

echo 'WASTE_IMPORT_DRY_RUN=PASS flights=75 unique_feo=802 links=945 repeated_feo=143 max_abs_mass_delta_kg='.number_format($maxMassDelta,3,'.','').PHP_EOL;
echo 'WASTE_IMPORT_MAPPING=PASS client_id='.$clientId.' client_created='.($createdClient?'1':'0').' utilizer_id='.$utilizerId.' responsible_user_id='.(int)$responsible['id'].PHP_EOL;
echo 'WASTE_IMPORT_ATOMIC_COMMIT=PASS trips=75 links=945 audits=75'.PHP_EOL;
echo 'WASTE_IMPORT_MULTILEG=PASS request='.$repeatId.' historical_trip_count='.$repeatTrips.PHP_EOL;
echo 'WASTE_IMPORT_OPERATIONAL_CLAIM_ISOLATION=PASS'.PHP_EOL;
echo 'WASTE_IMPORT_PENDING=PASS pending=0 all=75'.PHP_EOL;
echo 'WASTE_IMPORT_CONTROLLED=PASS'.PHP_EOL;
