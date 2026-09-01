<?php

namespace App\Service {
    final class AccessControlService
    {
        public static function canSeeAllCompanyData(array $user): bool { return ($user['role_code'] ?? '') === 'company_owner'; }
    }
    final class LinearRouteService
    {
        public static function isVisibleEntity(\PDO $pdo, string $table, int $entityId, array $user, string $entityType): bool
        {
            if ($table !== 'clients' || $entityId <= 0) return false;
            $stmt=$pdo->prepare("SELECT COUNT(*) FROM clients WHERE id=? AND status='active' AND deleted_at IS NULL");
            $stmt->execute([$entityId]);
            return (int)$stmt->fetchColumn()===1;
        }
        public static function fetchVisibleRouteExecutors(\PDO $pdo, array $user): array
        {
            return [['id'=>7,'contractor_name'=>'Executor']];
        }
    }
}

namespace {
$root=dirname(__DIR__);
require_once $root.'/app/Service/WasteTripService.php';
require_once $root.'/app/Service/WasteTripLegacyImportService.php';

use App\Service\WasteTripLegacyImportService;
use App\Service\WasteTripService;

$fail=static function(string $message): never { fwrite(STDERR,"FAIL: {$message}\n"); exit(1); };
$must=static function(bool $ok,string $message) use($fail): void { if(!$ok)$fail($message); };
$same=static function(mixed $actual,mixed $expected,string $message) use($fail): void { if($actual!==$expected)$fail($message.' expected='.var_export($expected,true).' actual='.var_export($actual,true)); };
$near=static function(float $actual,float $expected,float $epsilon,string $message) use($fail): void { if(abs($actual-$expected)>$epsilon)$fail($message." expected={$expected} actual={$actual}"); };
$read=static function(string $relative) use($root,$fail): string { $path=$root.'/'.ltrim($relative,'/'); $text=is_file($path)?file_get_contents($path):false; if(!is_string($text))$fail('cannot read '.$relative); return $text; };

$parsed=WasteTripService::parseRequestIds("285664, 281861; 257387\n285664");
$same($parsed['ids'],['285664','281861','257387'],'request parser order');
$same($parsed['duplicates'],['285664'],'request parser duplicate');
$near(WasteTripService::massKg('1.2345'),1234.5,0.0005,'mass kg normalization');
$same(WasteTripLegacyImportService::mapLegacyStatus('search'),WasteTripService::STATUS_SEARCHING,'legacy search mapping');
$same(WasteTripLegacyImportService::mapLegacyStatus('found'),WasteTripService::STATUS_ASSIGNED,'legacy found mapping');
$same(WasteTripLegacyImportService::mapLegacyStatus('started'),WasteTripService::STATUS_IN_PROGRESS,'legacy started mapping');
$same(WasteTripLegacyImportService::mapLegacyStatus('completed'),WasteTripService::STATUS_COMPLETED,'legacy completed mapping');
$same(WasteTripLegacyImportService::mapLegacyStatus('mystery'),null,'unknown legacy status must block');

$base=[
 'client_id'=>1,'route_executor_id'=>null,'responsible_user_id'=>1,'destination_type'=>'warehouse','utilizer_id'=>null,
 'status'=>WasteTripService::STATUS_SEARCHING,'planned_start_mode'=>'exact','planned_start_date'=>'2026-09-10','planned_start_date_from'=>null,'planned_start_date_to'=>null,
 'actual_start_date'=>null,'actual_end_date'=>null,'comment'=>null,'cancellation_reason'=>null,
];
$same(WasteTripService::validateInput($base),[],'searching trip without executor');
$assigned=$base; $assigned['status']=WasteTripService::STATUS_ASSIGNED;
$must(WasteTripService::validateInput($assigned)!==[],'assigned without executor must fail');
$inProgress=$base; $inProgress['status']=WasteTripService::STATUS_IN_PROGRESS; $inProgress['route_executor_id']=7;
$must(WasteTripService::validateInput($inProgress)!==[],'in progress without actual start must fail');
$completed=$base; $completed['status']=WasteTripService::STATUS_COMPLETED; $completed['route_executor_id']=7;
$must(WasteTripService::validateInput($completed)!==[],'completed without dates must fail');
$cancelled=$base; $cancelled['status']=WasteTripService::STATUS_CANCELLED;
$must(WasteTripService::validateInput($cancelled)!==[],'cancelled without reason must fail');
$range=$base; $range['planned_start_mode']='range'; $range['planned_start_date']=null; $range['planned_start_date_from']='2026-09-10'; $range['planned_start_date_to']='2026-09-09';
$must(WasteTripService::validateInput($range)!==[],'backwards planned range must fail');
WasteTripService::assertTransition(WasteTripService::STATUS_SEARCHING,WasteTripService::STATUS_ASSIGNED);
try { WasteTripService::assertTransition(WasteTripService::STATUS_COMPLETED,WasteTripService::STATUS_IN_PROGRESS); $fail('backwards transition accepted'); } catch (\RuntimeException $e) {}
echo "WASTE_TRIP_DOMAIN=PASS\n";

$routes=$read('app/Http/Routes/company_linear_trips.php');
$controller=$read('app/Http/Controllers/Company/WasteTripController.php');
$serviceText=$read('app/Service/WasteTripService.php');
$legacyText=$read('app/Service/WasteTripLegacyImportService.php');
$migration=$read('database/migrations-local/128_create_waste_trips.sql');
$view=$read('app/View/pages/company_waste_trips.php');
$importView=$read('app/View/pages/company_waste_trip_import.php');
$entry=$read('public/index.php');
foreach ([
 "'/company/trips/departures'","'/company/trips/departures/preview'","'/company/trips/departures/create'","'/company/trips/departures/import'",
 "'/company/trips/departures/import/{legacyId}'","'/company/trips/departures/{id}/modal-view'",
 "'/company/trips/departures/{id}/modal-edit'","'/company/trips/departures/{id}/cancel'"
] as $needle) $must(str_contains($routes,$needle),'missing waste route '.$needle);
$must(substr_count($controller,"requireRole(['company_owner', 'senior_logist', 'logist'])")>=8,'all waste endpoints must keep operational role gate');
$must(str_contains($entry,'verifyCsrfRequest();'),'global CSRF verification must remain enabled');
$must(str_contains($migration,'UNIQUE KEY `uk_waste_trip_legacy_identity` (`source_system`,`legacy_flight_id`)'),'immutable legacy identity DB guard');
$must(str_contains($migration,'`feo_request_id` BIGINT UNSIGNED NOT NULL'),'normalized FEO relation must use canonical id');
$must(str_contains($migration,"`planned_start_mode` ENUM('exact','range','unspecified')"),'planning mode must be stored explicitly');
$must(str_contains($migration,'fk_waste_trip_requests_feo'),'waste request relation must have physical FEO FK');
$must(str_contains($migration,'CREATE TABLE IF NOT EXISTS `waste_trip_audit_log`'),'waste trip audit log required');
$must(!str_contains($migration,'zayavki_ids'),'new schema must not store comma-separated FEO ids');
$must(str_contains($serviceText,'FOR UPDATE'),'request claims must use row locks');
$must(str_contains($serviceText,'composition_correction')&&str_contains($serviceText,'confirm_composition_correction'),'in-progress composition correction must be explicit and audited');
$must(str_contains($serviceText,'previewSelection'),'manual request selection must have a server-side preview');
$must(!preg_match('/DELETE\s+FROM\s+waste_trips\b/i',$serviceText),'operational trip hard delete forbidden');
$must(!preg_match('/\b(?:INSERT|UPDATE|DELETE|REPLACE|ALTER|CREATE|DROP|TRUNCATE)\b\s+(?:INTO\s+|TABLE\s+)?(?:flights|feo|contractors|drivers|users)\b/i',$legacyText),'legacy adapter must be read-only');
$must(str_contains($legacyText,"SET SESSION TRANSACTION READ ONLY"),'legacy connection must be session read-only in addition to SELECT-only credentials');
$must(str_contains($legacyText,'customer_hints')&&str_contains($legacyText,'inn_zakazchika'),'legacy preview must expose FEO customer hints for manual mapping');
$must(str_contains($view,'modal-overlay')&&str_contains($view,'table-card table-card--standard'),'waste page must use canonical ERP UI primitives');
$must(str_contains($importView, "$isFinanceRealm = (string) ($_SESSION['role_code'] ?? '') === 'company_owner';"),'legacy finance evidence must be owner-gated');
$must(str_contains($legacyText,"env('LEGACY_FLIGHTS_DB_HOST'")&&str_contains($legacyText,"env('LEGACY_FLIGHTS_DB_PASSWORD'"),'legacy credentials must come from environment');
echo "WASTE_TRIP_STATIC_SECURITY=PASS\n";

if (($argv[1]??'')==='race-worker') {
    $cfg=json_decode(base64_decode((string)($argv[2]??''),true)?:'',true);
    if(!is_array($cfg)){fwrite(STDOUT,json_encode(['success'=>false,'message'=>'invalid config']));exit(0);}
    $pdo=new \PDO("mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['db']};charset=utf8mb4",$cfg['user'],$cfg['pass'],[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION,\PDO::ATTR_EMULATE_PREPARES=>false]);
    while(microtime(true)<(float)$cfg['start_at'])usleep(1000);
    try {
        $input=$base;
        $legacy=null;
        if(($cfg['mode']??'')==='legacy'){
            $legacy=['source_system'=>WasteTripLegacyImportService::SOURCE_SYSTEM,'legacy_flight_id'=>(int)$cfg['legacy_id'],'legacy_total_mass_kg'=>1000,'legacy_cost'=>null,'legacy_payment_terms'=>null,'payload'=>['id'=>(int)$cfg['legacy_id']]];
        }
        $id=WasteTripService::create($pdo,$input,[(string)$cfg['request_id']],['user_id'=>1,'role_code'=>'company_owner'],$legacy);
        fwrite(STDOUT,json_encode(['success'=>true,'id'=>$id]));
    } catch(\Throwable $e){fwrite(STDOUT,json_encode(['success'=>false,'message'=>$e->getMessage()]));}
    exit(0);
}

if(!in_array('mysql',\PDO::getAvailableDrivers(),true)){
    echo "WASTE_TRIP_MYSQL=REMOTE_REQUIRED\n";
    echo "WASTE_TRIP_REGRESSION=PASS_WITH_REMOTE_GATE\n";
    exit(0);
}

$host=getenv('PLANEX_SCHEMA_DB_HOST')?:'127.0.0.1';
$port=(int)(getenv('PLANEX_SCHEMA_DB_PORT')?:3306);
$user=getenv('PLANEX_SCHEMA_DB_USER')?:'planex_ci';
$pass=getenv('PLANEX_SCHEMA_DB_PASSWORD')?:'planex_ci';
$admin=new \PDO("mysql:host={$host};port={$port};charset=utf8mb4",$user,$pass,[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION,\PDO::ATTR_EMULATE_PREPARES=>false]);
$dbName='planex_waste_'.getmypid().'_'.bin2hex(random_bytes(3));
$quoted='`'.str_replace('`','``',$dbName).'`';
$admin->exec("CREATE DATABASE {$quoted} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

$spawnWorkers=static function(array $configs) use($root): array {
    $workers=[];
    foreach($configs as $cfg){
        $arg=base64_encode(json_encode($cfg,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        $cmd=[PHP_BINARY,$root.'/tests/waste_trip_regression_test.php','race-worker',$arg];
        $proc=proc_open($cmd,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$root);
        if(!is_resource($proc))throw new \RuntimeException('cannot spawn race worker');
        fclose($pipes[0]);$workers[]=[$proc,$pipes];
    }
    $results=[];
    foreach($workers as [$proc,$pipes]){
        $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($proc);
        if($code!==0)throw new \RuntimeException('race worker exit '.$code.' '.$err);
        $decoded=json_decode((string)$out,true);if(!is_array($decoded))throw new \RuntimeException('invalid worker output '.$out.' '.$err);$results[]=$decoded;
    }
    return $results;
};

try {
    $pdo=new \PDO("mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4",$user,$pass,[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION,\PDO::ATTR_EMULATE_PREPARES=>false]);
    $pdo->exec("CREATE TABLE clients(id INT UNSIGNED PRIMARY KEY,name VARCHAR(255),status VARCHAR(20),deleted_at DATETIME NULL) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE users(id INT UNSIGNED PRIMARY KEY,full_name VARCHAR(255),role_code VARCHAR(50),status VARCHAR(20),deleted_at DATETIME NULL) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE crews(id INT UNSIGNED PRIMARY KEY,status VARCHAR(20) NOT NULL DEFAULT 'active') ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE feo_utilizers(id BIGINT UNSIGNED PRIMARY KEY,short_name VARCHAR(80),full_name VARCHAR(255),address VARCHAR(500),is_active TINYINT,sort_order INT) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE feo_requests(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,zayavka_id VARCHAR(64) UNIQUE,mass_netto DECIMAL(14,4),polnoe_naim_zakazchika TEXT,inn_zakazchika VARCHAR(32),naim_oo_gruzootpravitel TEXT,mno_adres_pogruzki TEXT,naim_otkhoda_fkko TEXT,nomer_opasnogo_gr VARCHAR(64)) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE feo_placement_current(zayavka_id VARCHAR(64) PRIMARY KEY,source_block_id BIGINT UNSIGNED,created_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB");
    $pdo->exec("INSERT INTO clients VALUES(1,'Client','active',NULL)");
    $pdo->exec("INSERT INTO users VALUES(1,'Owner','company_owner','active',NULL)");
    $pdo->exec("INSERT INTO crews(id,status) VALUES(7,'active')");
    $pdo->exec("INSERT INTO feo_utilizers VALUES(1,'UTIL','Utilizer',NULL,1,100)");
    for($i=1;$i<=8;$i++){
        $id=strval(100000+$i);$mass=number_format($i/10,4,'.','');
        $stmt=$pdo->prepare("INSERT INTO feo_requests(zayavka_id,mass_netto,polnoe_naim_zakazchika,inn_zakazchika,naim_oo_gruzootpravitel,mno_adres_pogruzki,naim_otkhoda_fkko) VALUES(?,?,?,?,?,?,?)");$stmt->execute([$id,$mass,'Customer '.$i,'770000000'.$i,'Sender '.$i,'Address '.$i,'Waste '.$i]);
        $stmt=$pdo->prepare("INSERT INTO feo_placement_current(zayavka_id,source_block_id) VALUES(?,1)");$stmt->execute([$id]);
    }
    $migration=$read('database/migrations-local/128_create_waste_trips.sql');
    foreach(preg_split('/;\s*(?:\R|$)/',$migration)?:[] as $statement){$statement=trim($statement);if($statement!=='')$pdo->exec($statement);}
    $must(WasteTripService::schemaReady($pdo),'schema ready after migration 128');
    $session=['user_id'=>1,'role_code'=>'company_owner'];

    $trip1=WasteTripService::create($pdo,$base,['100001'],$session);
    $same($trip1,1,'first waste trip id');
    $row=$pdo->query("SELECT snapshot_mass_kg FROM waste_trip_requests WHERE waste_trip_id=1")->fetchColumn();
    $near((float)$row,100.0,0.0005,'snapshot mass at claim');
    try{WasteTripService::create($pdo,$base,['100001'],$session);$fail('active duplicate claim accepted');}catch(\RuntimeException $e){$must(str_contains($e->getMessage(),'уже включены'),'duplicate active claim error');}
    WasteTripService::cancel($pdo,1,1,'test cancellation',$session);
    $trip2=WasteTripService::create($pdo,$base,['100001'],$session);
    $same($trip2,2,'cancelled trip must release request');

    $trip3=WasteTripService::create($pdo,$base,['100002'],$session);
    $pdo->exec("UPDATE feo_requests SET mass_netto=9.9999 WHERE zayavka_id='100002'");
    WasteTripService::update($pdo,$trip3,$base,['100002'],1,$session);
    $snapshot=$pdo->query("SELECT snapshot_mass_kg FROM waste_trip_requests WHERE waste_trip_id={$trip3}")->fetchColumn();
    $near((float)$snapshot,200.0,0.0005,'linked request snapshot must remain immutable');

    $start=microtime(true)+0.7;
    $raceBase=['host'=>$host,'port'=>$port,'db'=>$dbName,'user'=>$user,'pass'=>$pass,'mode'=>'claim','request_id'=>'100003','start_at'=>$start];
    $claimResults=$spawnWorkers([$raceBase,$raceBase]);
    $same(count(array_filter($claimResults,static fn($r)=>!empty($r['success']))),1,'concurrent claim must have exactly one winner');

    $legacyEvidence=['source_system'=>WasteTripLegacyImportService::SOURCE_SYSTEM,'legacy_flight_id'=>501,'legacy_total_mass_kg'=>400,'legacy_cost'=>'123.00','legacy_payment_terms'=>'legacy','payload'=>['id'=>501]];
    WasteTripService::create($pdo,$base,['100004'],$session,$legacyEvidence);
    try{WasteTripService::create($pdo,$base,['100005'],$session,$legacyEvidence);$fail('sequential duplicate legacy identity accepted');}catch(\RuntimeException $e){$must(str_contains($e->getMessage(),'уже импортирован'),'duplicate legacy identity error');}

    $start=microtime(true)+0.7;
    $legacyA=['host'=>$host,'port'=>$port,'db'=>$dbName,'user'=>$user,'pass'=>$pass,'mode'=>'legacy','request_id'=>'100006','legacy_id'=>777,'start_at'=>$start];
    $legacyB=$legacyA;$legacyB['request_id']='100007';
    $legacyResults=$spawnWorkers([$legacyA,$legacyB]);
    $same(count(array_filter($legacyResults,static fn($r)=>!empty($r['success']))),1,'concurrent legacy import identity must have exactly one winner');

    $same((int)$pdo->query("SELECT COUNT(*) FROM waste_trips WHERE source_system='legacy_flights' AND legacy_flight_id=777")->fetchColumn(),1,'legacy identity unique in DB');
    echo "WASTE_TRIP_MYSQL=PASS\n";
    echo "WASTE_TRIP_CONCURRENCY=PASS\n";
} finally {
    $admin->exec("DROP DATABASE IF EXISTS {$quoted}");
}

echo "WASTE_TRIP_REGRESSION=PASS\n";
}
