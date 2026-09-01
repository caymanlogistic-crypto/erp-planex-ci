<?php

declare(strict_types=1);

use App\Service\LinearRouteService;
use App\Service\WasteTripLegacyImportService;
use App\Service\WasteTripService;

$root = rtrim((string)(getenv('PLANEX_ROOT') ?: ''), '/');
$legacyRoot = rtrim((string)(getenv('LEGACY_ROOT') ?: dirname($root) . '/erp'), '/');
$jsonPath = (string)(getenv('OUT_JSON') ?: '/tmp/waste_legacy_reconciliation.json');
$csvPath = (string)(getenv('OUT_CSV') ?: '/tmp/waste_legacy_reconciliation.csv');
if ($root === '' || !is_file($root . '/.env') || !is_file($root . '/bootstrap/app.php')) throw new RuntimeException('PLANEX_ROOT invalid');
if (!is_file($legacyRoot . '/.env')) throw new RuntimeException('legacy .env unavailable');

require_once $root . '/app/Support/helpers.php';
require_once $root . '/app/Support/environment.php';
loadEnvFileNonOverwriting($root . '/.env');
$config = require $root . '/bootstrap/app.php';
require_once $root . '/app/Support/entrypoint_dependencies.php';
require_once $root . '/app/Service/WasteTripService.php';
require_once $root . '/app/Service/WasteTripLegacyImportService.php';

function parseDotEnv(string $path): array {
    $result = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        if (str_starts_with($line, 'export ')) $line = trim(substr($line, 7));
        $pos = strpos($line, '=');
        if ($pos === false) continue;
        $key = trim(substr($line, 0, $pos));
        $value = trim(substr($line, $pos + 1));
        if ($key === '' || !preg_match('/^[A-Z0-9_]+$/i', $key)) continue;
        if (strlen($value) >= 2 && (($value[0] === '"' && $value[-1] === '"') || ($value[0] === "'" && $value[-1] === "'"))) $value = substr($value, 1, -1);
        $result[$key] = $value;
    }
    return $result;
}
function firstEnv(array $env, array $keys, ?string $default = null): ?string { foreach ($keys as $key) if (isset($env[$key]) && trim((string)$env[$key]) !== '') return trim((string)$env[$key]); return $default; }
function pdoFromCfg(array $cfg): PDO {
    $pdo = new PDO('mysql:host=' . $cfg['host'] . ';port=' . ($cfg['port'] ?? 3306) . ';dbname=' . $cfg['database'] . ';charset=utf8mb4', $cfg['username'], $cfg['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec('SET SESSION TRANSACTION READ ONLY');
    $pdo->exec('START TRANSACTION READ ONLY');
    return $pdo;
}
function chunks(array $values, int $size = 500): array { return array_chunk(array_values($values), $size); }
function tableColumns(PDO $pdo, string $table): array { return array_values(array_map(static fn($r) => (string)$r['Field'], $pdo->query('SHOW COLUMNS FROM `' . str_replace('`','``',$table) . '`')->fetchAll())); }
function pickExisting(array $columns, array $wanted): array { return array_values(array_filter($wanted, static fn($c) => in_array($c, $columns, true))); }
function selectByIds(PDO $pdo, string $table, string $idColumn, array $ids, array $columns): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids)))); if (!$ids) return []; $out=[];
    foreach (chunks($ids) as $chunk) { $ph=implode(',',array_fill(0,count($chunk),'?')); $sql='SELECT '.implode(',',array_map(static fn($c)=>'`'.str_replace('`','``',$c).'`',$columns)).' FROM `'.$table.'` WHERE `'.$idColumn.'` IN ('.$ph.')'; $st=$pdo->prepare($sql); $st->execute($chunk); foreach($st->fetchAll() as $row)$out[(int)$row[$idColumn]]=$row; }
    return $out;
}
function massKg(mixed $tonnes): float { $v=str_replace(',','.',trim((string)$tonnes)); return is_numeric($v)?round(max(0,(float)$v)*1000,3):0.0; }

$central=pdoFromCfg($config['database']);
$company=$central->query("SELECT * FROM companies WHERE id=25 AND status='active' LIMIT 1")->fetch()?:null;
if(!$company)throw new RuntimeException('PLANEX tenant unavailable');
$tenantCfg=companyDatabaseConfig($config,$company); $tenant=pdoFromCfg($tenantCfg);

$legacyEnv=parseDotEnv($legacyRoot.'/.env');
$legacyCfg=[
 'host'=>firstEnv($legacyEnv,['DB_HOST','MYSQL_HOST'],'localhost'),
 'port'=>(int)(firstEnv($legacyEnv,['DB_PORT','MYSQL_PORT'],'3306')?:3306),
 'database'=>firstEnv($legacyEnv,['DB_DATABASE','DB_NAME','MYSQL_DATABASE']),
 'username'=>firstEnv($legacyEnv,['DB_USERNAME','DB_USER','MYSQL_USER']),
 'password'=>firstEnv($legacyEnv,['DB_PASSWORD','DB_PASS','MYSQL_PASSWORD'],'')
];
$availableDbKeys=array_values(array_filter(array_keys($legacyEnv),static fn($k)=>str_starts_with(strtoupper($k),'DB_')||str_starts_with(strtoupper($k),'MYSQL_'))); sort($availableDbKeys);
if(!$legacyCfg['database']||!$legacyCfg['username'])throw new RuntimeException('legacy DB config keys not resolved; available keys='.implode(',',$availableDbKeys));
$legacy=pdoFromCfg($legacyCfg);
$readOnlyVar=null; foreach(['SELECT @@transaction_read_only','SELECT @@tx_read_only'] as $sql){try{$readOnlyVar=(int)$legacy->query($sql)->fetchColumn();break;}catch(Throwable){}}

$flightColumns=tableColumns($legacy,'flights');
$wantedFlightColumns=['id','block_date','zayavki_ids','zayavki_count','total_mass_tonn','unload_type','status','contractor_id','driver_id','assigned_manager_id','cost','payment_terms','comment','planned_start_date','planned_start_date_from','planned_start_date_to','actual_start_date','actual_end_date'];
$selectedFlightColumns=pickExisting($flightColumns,$wantedFlightColumns);
if(!in_array('id',$selectedFlightColumns,true)||!in_array('zayavki_ids',$selectedFlightColumns,true))throw new RuntimeException('legacy flights missing id/zayavki_ids');
$flights=$legacy->query('SELECT '.implode(',',array_map(static fn($c)=>'f.`'.$c.'`',$selectedFlightColumns)).' FROM flights f ORDER BY f.id')->fetchAll();
$legacyTotal=count($flights);

$contractorIds=$driverIds=$managerIds=[];$allRequestOccurrences=[];$allRequestSet=[];
foreach($flights as $flight){
 if(isset($flight['contractor_id']))$contractorIds[]=(int)$flight['contractor_id']; if(isset($flight['driver_id']))$driverIds[]=(int)$flight['driver_id']; if(isset($flight['assigned_manager_id']))$managerIds[]=(int)$flight['assigned_manager_id'];
 foreach(WasteTripLegacyImportService::parseLegacyRequestIds((string)($flight['zayavki_ids']??'')) as $rid){$allRequestOccurrences[]=$rid;$allRequestSet[$rid]=true;}
}
$contractors=[]; if($contractorIds){$cols=tableColumns($legacy,'contractors');$wanted=pickExisting($cols,['id','name','inn','ИНН','ogrn','ОГРН']);if(!in_array('id',$wanted,true))array_unshift($wanted,'id');$contractors=selectByIds($legacy,'contractors','id',$contractorIds,$wanted);}
$drivers=[]; if($driverIds){$cols=tableColumns($legacy,'drivers');$wanted=pickExisting($cols,['id','full_name','vehicle_make_plate','contractor_id']);if(!in_array('id',$wanted,true))array_unshift($wanted,'id');$drivers=selectByIds($legacy,'drivers','id',$driverIds,$wanted);}
$managers=[]; if($managerIds){$cols=tableColumns($legacy,'users');$wanted=pickExisting($cols,['id','Фамилия','Имя','Отчество','email','Email','E-mail','name','full_name']);if(!in_array('id',$wanted,true))array_unshift($wanted,'id');$managers=selectByIds($legacy,'users','id',$managerIds,$wanted);}

$requestMap=[];$requestIds=array_keys($allRequestSet);
foreach(chunks($requestIds) as $chunk){if(!$chunk)continue;$ph=implode(',',array_fill(0,count($chunk),'?'));$st=$tenant->prepare("SELECT id,zayavka_id,mass_netto,polnoe_naim_zakazchika,inn_zakazchika FROM feo_requests WHERE zayavka_id IN ($ph)");$st->execute($chunk);foreach($st->fetchAll() as $r)$requestMap[(string)$r['zayavka_id']]=$r;}
$claimMap=[];$requestPhysicalIds=array_values(array_map(static fn($r)=>(int)$r['id'],$requestMap));
foreach(chunks($requestPhysicalIds) as $chunk){if(!$chunk)continue;$ph=implode(',',array_fill(0,count($chunk),'?'));$st=$tenant->prepare("SELECT wtr.feo_request_id,wt.id waste_trip_id FROM waste_trip_requests wtr JOIN waste_trips wt ON wt.id=wtr.waste_trip_id WHERE wtr.feo_request_id IN ($ph) AND wt.status<>'cancelled'");$st->execute($chunk);foreach($st->fetchAll() as $r)$claimMap[(int)$r['feo_request_id']]=(int)$r['waste_trip_id'];}
$importMap=[];$legacyIds=array_values(array_map(static fn($f)=>(int)$f['id'],$flights));
foreach(chunks($legacyIds) as $chunk){if(!$chunk)continue;$ph=implode(',',array_fill(0,count($chunk),'?'));$params=array_merge(['legacy_flights'],$chunk);$st=$tenant->prepare("SELECT id,legacy_flight_id FROM waste_trips WHERE source_system=? AND legacy_flight_id IN ($ph)");$st->execute($params);foreach($st->fetchAll() as $r)$importMap[(int)$r['legacy_flight_id']]=(int)$r['id'];}

$clients=$tenant->query("SELECT id,name,inn FROM clients WHERE status='active' AND deleted_at IS NULL ORDER BY id")->fetchAll();$clientsByInn=[];foreach($clients as $c){$inn=trim((string)($c['inn']??''));if($inn!=='')$clientsByInn[$inn][]=$c;}
$userId=(int)$tenant->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();$routeExecutors=LinearRouteService::fetchVisibleRouteExecutors($tenant,['user_id'=>$userId,'role_code'=>'company_owner']);$erpUsers=WasteTripService::fetchActiveUsers($tenant);
$summary=['legacy_flights_total'=>$legacyTotal,'already_imported'=>0,'missing_in_erp'=>0,'hard_blocked'=>0,'ready_for_manual_mapping'=>0,'blocked_missing_feo'=>0,'blocked_occupied'=>0,'blocked_unknown_status'=>0,'blocked_no_requests'=>0,'needs_client_mapping'=>0,'client_exact_inn_suggested'=>0,'needs_executor_mapping'=>0,'needs_responsible_mapping'=>0,'needs_destination_mapping'=>0];
$rows=[];$missingGlobal=[];$resolvedGlobal=[];$claimedGlobal=[];
foreach($flights as $flight){
 $id=(int)$flight['id'];$requestIdsForFlight=WasteTripLegacyImportService::parseLegacyRequestIds((string)($flight['zayavki_ids']??''));$missing=[];$occupied=[];$resolved=[];$currentMass=0.0;$hints=[];
 foreach($requestIdsForFlight as $rid){if(!isset($requestMap[$rid])){$missing[]=$rid;$missingGlobal[$rid]=true;continue;}$resolved[]=$rid;$resolvedGlobal[$rid]=true;$fr=$requestMap[$rid];$currentMass+=WasteTripService::massKg($fr['mass_netto']??null);$inn=trim((string)($fr['inn_zakazchika']??''));$name=trim((string)($fr['polnoe_naim_zakazchika']??''));if($inn!==''||$name!=='')$hints[$inn.'|'.$name]=['inn'=>$inn,'name'=>$name];$pid=(int)$fr['id'];if(isset($claimMap[$pid])){$occupied[$rid]=$claimMap[$pid];$claimedGlobal[$rid]=true;}}
 $mappedStatus=WasteTripLegacyImportService::mapLegacyStatus((string)($flight['status']??''));$imported=isset($importMap[$id]);if($imported)$summary['already_imported']++;else$summary['missing_in_erp']++;
 $hard=[];if(!$requestIdsForFlight){$hard[]='no_requests';$summary['blocked_no_requests']++;}if($missing){$hard[]='missing_feo';$summary['blocked_missing_feo']++;}if($occupied){$hard[]='occupied_feo';$summary['blocked_occupied']++;}if($mappedStatus===null){$hard[]='unknown_status';$summary['blocked_unknown_status']++;}if(!$imported&&$hard)$summary['hard_blocked']++;if(!$imported&&!$hard)$summary['ready_for_manual_mapping']++;
 $hintInns=array_values(array_unique(array_filter(array_map(static fn($h)=>trim((string)$h['inn']),array_values($hints)))));$clientCandidates=[];foreach($hintInns as $inn)foreach($clientsByInn[$inn]??[] as $c)$clientCandidates[(int)$c['id']]=$c;$clientExact=count($clientCandidates)===1&&count($hintInns)===1;
 if(!$imported){$summary['needs_client_mapping']++;if($clientExact)$summary['client_exact_inn_suggested']++;$summary['needs_responsible_mapping']++;$summary['needs_destination_mapping']++;if(in_array($mappedStatus,['executor_assigned','in_progress','completed'],true))$summary['needs_executor_mapping']++;}
 $contractor=$contractors[(int)($flight['contractor_id']??0)]??[];$driver=$drivers[(int)($flight['driver_id']??0)]??[];$manager=$managers[(int)($flight['assigned_manager_id']??0)]??[];$legacyManagerName=trim(implode(' ',array_filter([(string)($manager['Фамилия']??''),(string)($manager['Имя']??''),(string)($manager['Отчество']??'')])));if($legacyManagerName==='')$legacyManagerName=(string)($manager['full_name']??$manager['name']??'');$legacyContractorInn=(string)($contractor['inn']??$contractor['ИНН']??'');
 $rows[]=['legacy_flight_id'=>$id,'already_imported'=>$imported,'erp_waste_trip_id'=>$importMap[$id]??null,'block_date'=>$flight['block_date']??null,'status'=>$flight['status']??null,'mapped_status'=>$mappedStatus,'unload_type'=>$flight['unload_type']??null,'request_ids'=>$requestIdsForFlight,'request_count_declared'=>$flight['zayavki_count']??null,'request_count_parsed'=>count($requestIdsForFlight),'resolved_request_ids'=>$resolved,'missing_request_ids'=>$missing,'occupied_requests'=>$occupied,'legacy_mass_kg'=>massKg($flight['total_mass_tonn']??null),'current_feo_mass_kg'=>round($currentMass,3),'mass_delta_kg'=>round($currentMass-massKg($flight['total_mass_tonn']??null),3),'customer_hints'=>array_values($hints),'client_mapping'=>['exact_inn_candidate'=>$clientExact?array_values($clientCandidates)[0]:null,'candidate_count'=>count($clientCandidates),'manual_required'=>!$imported],'legacy_contractor'=>['id'=>$flight['contractor_id']??null,'name'=>$contractor['name']??null,'inn'=>$legacyContractorInn?:null],'legacy_driver'=>['id'=>$flight['driver_id']??null,'full_name'=>$driver['full_name']??null,'vehicle_make_plate'=>$driver['vehicle_make_plate']??null],'legacy_manager'=>['id'=>$flight['assigned_manager_id']??null,'name'=>$legacyManagerName?:null],'route_executor_mapping_required'=>!$imported&&in_array($mappedStatus,['executor_assigned','in_progress','completed'],true),'responsible_mapping_required'=>!$imported,'destination_mapping_required'=>!$imported,'hard_blockers'=>$hard,'ready_for_manual_mapping'=>!$imported&&!$hard,'planned_start_date'=>$flight['planned_start_date']??null,'planned_start_date_from'=>$flight['planned_start_date_from']??null,'planned_start_date_to'=>$flight['planned_start_date_to']??null,'actual_start_date'=>$flight['actual_start_date']??null,'actual_end_date'=>$flight['actual_end_date']??null,'cost'=>$flight['cost']??null,'payment_terms'=>$flight['payment_terms']??null,'comment'=>$flight['comment']??null];
}
$report=['generated_at'=>date(DATE_ATOM),'source_system'=>'legacy_flights','legacy_db'=>['database'=>$legacyCfg['database'],'env_keys'=>$availableDbKeys,'transaction_read_only'=>$readOnlyVar,'flights_columns'=>$flightColumns,'selected_columns'=>$selectedFlightColumns],'erp'=>['company_id'=>25,'company_name'=>$company['name']??'','database'=>$tenantCfg['database'],'waste_counts'=>['waste_trips'=>(int)$tenant->query('SELECT COUNT(*) FROM waste_trips')->fetchColumn(),'waste_trip_requests'=>(int)$tenant->query('SELECT COUNT(*) FROM waste_trip_requests')->fetchColumn(),'waste_trip_legacy_import_audits'=>(int)$tenant->query('SELECT COUNT(*) FROM waste_trip_legacy_import_audits')->fetchColumn(),'waste_trip_audit_log'=>(int)$tenant->query('SELECT COUNT(*) FROM waste_trip_audit_log')->fetchColumn()],'clients'=>array_map(static fn($c)=>['id'=>(int)$c['id'],'name'=>$c['name'],'inn'=>$c['inn']],$clients),'route_executor_count'=>count($routeExecutors),'active_user_count'=>count($erpUsers)],'request_resolution'=>['occurrences_total'=>count($allRequestOccurrences),'unique_total'=>count($allRequestSet),'resolved_unique'=>count($resolvedGlobal),'missing_unique'=>count($missingGlobal),'missing_ids'=>array_keys($missingGlobal),'claimed_unique'=>count($claimedGlobal),'claimed_ids'=>array_keys($claimedGlobal)],'summary'=>$summary,'flights'=>$rows];
file_put_contents($jsonPath,json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));$fh=fopen($csvPath,'wb');fputcsv($fh,['legacy_flight_id','already_imported','erp_waste_trip_id','status','mapped_status','unload_type','request_ids','missing_request_ids','occupied_requests','legacy_mass_kg','current_feo_mass_kg','mass_delta_kg','legacy_contractor','legacy_driver','legacy_manager','client_exact_inn_candidate','route_executor_mapping_required','responsible_mapping_required','destination_mapping_required','hard_blockers','ready_for_manual_mapping']);foreach($rows as $r){fputcsv($fh,[$r['legacy_flight_id'],$r['already_imported']?'1':'0',$r['erp_waste_trip_id'],$r['status'],$r['mapped_status'],$r['unload_type'],implode(';',$r['request_ids']),implode(';',$r['missing_request_ids']),json_encode($r['occupied_requests'],JSON_UNESCAPED_UNICODE),$r['legacy_mass_kg'],$r['current_feo_mass_kg'],$r['mass_delta_kg'],trim((string)($r['legacy_contractor']['name']??'')),trim((string)($r['legacy_driver']['full_name']??'')),trim((string)($r['legacy_manager']['name']??'')),$r['client_mapping']['exact_inn_candidate']['id']??'',$r['route_executor_mapping_required']?'1':'0',$r['responsible_mapping_required']?'1':'0',$r['destination_mapping_required']?'1':'0',implode(';',$r['hard_blockers']),$r['ready_for_manual_mapping']?'1':'0']);}fclose($fh);
echo 'LEGACY_DB_READ_ONLY='.(($readOnlyVar===1)?'PASS':'SESSION_READ_ONLY_REQUESTED').PHP_EOL;echo 'LEGACY_FLIGHTS_TOTAL='.$legacyTotal.PHP_EOL;echo 'ERP_ALREADY_IMPORTED='.$summary['already_imported'].PHP_EOL;echo 'ERP_MISSING='.$summary['missing_in_erp'].PHP_EOL;echo 'HARD_BLOCKED='.$summary['hard_blocked'].PHP_EOL;echo 'READY_FOR_MANUAL_MAPPING='.$summary['ready_for_manual_mapping'].PHP_EOL;echo 'FEO_REQUESTS_UNIQUE_TOTAL='.count($allRequestSet).PHP_EOL;echo 'FEO_RESOLVED_UNIQUE='.count($resolvedGlobal).PHP_EOL;echo 'FEO_MISSING_UNIQUE='.count($missingGlobal).PHP_EOL;echo 'FEO_CLAIMED_UNIQUE='.count($claimedGlobal).PHP_EOL;echo 'BLOCK_MISSING_FEO='.$summary['blocked_missing_feo'].PHP_EOL;echo 'BLOCK_OCCUPIED='.$summary['blocked_occupied'].PHP_EOL;echo 'BLOCK_UNKNOWN_STATUS='.$summary['blocked_unknown_status'].PHP_EOL;echo 'BLOCK_NO_REQUESTS='.$summary['blocked_no_requests'].PHP_EOL;echo 'CLIENT_EXACT_INN_SUGGESTED='.$summary['client_exact_inn_suggested'].PHP_EOL;
