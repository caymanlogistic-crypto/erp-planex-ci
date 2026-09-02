<?php

declare(strict_types=1);

use App\Service\WasteTripLegacyImportService;
use App\Service\WasteTripService;

$root = rtrim((string) getenv('PLANEX_ROOT'), '/');
$expected = trim((string) getenv('EXPECTED_SHA'));
$markerPath = (string) getenv('DEPLOY_MARKER');
$marker = $markerPath !== '' && is_file($markerPath) ? trim((string) file_get_contents($markerPath)) : '';
if ($root === '' || $expected === '' || $marker !== $expected) throw new RuntimeException('production marker mismatch');

require_once $root . '/app/Support/helpers.php';
require_once $root . '/app/Support/environment.php';
loadEnvFileNonOverwriting($root . '/.env');
$config = require $root . '/bootstrap/app.php';
require_once $root . '/app/Support/crypto_helper.php';
require_once $root . '/app/Support/company_database.php';
require_once $root . '/app/Service/AccessControlService.php';
require_once $root . '/app/Service/LinearRouteService.php';
require_once $root . '/app/Service/WasteTripService.php';
require_once $root . '/app/Service/WasteTripLegacyImportService.php';

$connect = static function (array $c): PDO {
    $pdo = new PDO('mysql:host='.$c['host'].';port='.($c['port'] ?? 3306).';dbname='.$c['database'].';charset=utf8mb4',$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
    $pdo->exec('SET SESSION TRANSACTION READ ONLY');
    $pdo->exec('START TRANSACTION READ ONLY');
    return $pdo;
};

$central = $connect($config['database']);
$company = $central->query("SELECT * FROM companies WHERE id=25 AND status='active' LIMIT 1")->fetch();
if (!$company) throw new RuntimeException('company 25 unavailable');
$tenant = $connect(companyDatabaseConfig($config, $company));
if (!WasteTripService::schemaReady($tenant)) throw new RuntimeException('Waste Trips schema unavailable');
$m = $tenant->prepare('SELECT COUNT(*) FROM schema_migrations WHERE migration=?'); $m->execute(['128_create_waste_trips.sql']);
if ((int)$m->fetchColumn() !== 1) throw new RuntimeException('migration 128 missing');

$counts=[]; foreach(['waste_trips','waste_trip_requests','waste_trip_legacy_import_audits','waste_trip_audit_log'] as $table){$counts[$table]=(int)$tenant->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn();}
if (array_sum($counts)!==0) throw new RuntimeException('Waste Trips must be empty before controlled import: '.json_encode($counts));

$client=$tenant->prepare('SELECT id,name,inn,status,deleted_at FROM clients WHERE inn=? ORDER BY id'); $client->execute(['4714004270']); $feoClients=$client->fetchAll();
if(count($feoClients)>1) throw new RuntimeException('ambiguous FEO client INN');

$utilCols=array_column($tenant->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='feo_utilizers'")->fetchAll(),'COLUMN_NAME');
if(in_array('inn',$utilCols,true)){$u=$tenant->prepare("SELECT id FROM feo_utilizers WHERE inn=? AND is_active=1 ORDER BY id");$u->execute(['7703545931']);$rows=$u->fetchAll();}
else{$rows=$tenant->query("SELECT id FROM feo_utilizers WHERE is_active=1 AND (UPPER(COALESCE(short_name,'')) LIKE '%ФРЕГАТ%' OR UPPER(COALESCE(full_name,'')) LIKE '%ФРЕГАТ%') ORDER BY id")->fetchAll();}
if(count($rows)!==1 || (int)$rows[0]['id']!==8) throw new RuntimeException('FREGAT mapping mismatch');
$users=$tenant->query("SELECT id,full_name,role_code FROM users WHERE status='active' AND deleted_at IS NULL ORDER BY id")->fetchAll();
if(count($users)!==1 || (int)$users[0]['id']!==1) throw new RuntimeException('responsible mapping mismatch');

$partial=WasteTripLegacyImportService::resolveLegacyPlanning(['planned_start_date'=>null,'planned_start_date_from'=>'2026-05-26','planned_start_date_to'=>null]);
foreach(['mode','exact','from','to','partial_range','source_from','source_to'] as $key){if(!array_key_exists($key,$partial))throw new RuntimeException('deployed partial planning missing key '.$key);}
if($partial['mode']!=='unspecified' || $partial['exact']!==null || $partial['from']!==null || $partial['to']!==null || $partial['partial_range']!==true || $partial['source_from']!=='2026-05-26' || $partial['source_to']!==null) throw new RuntimeException('deployed partial planning contract mismatch');

$service=(string)file_get_contents($root.'/app/Service/WasteTripService.php'); $legacy=(string)file_get_contents($root.'/app/Service/WasteTripLegacyImportService.php');
foreach(["COALESCE(wt.source_system,'') <> 'legacy_flights'",'historical_claim_exempt','historical_executor_label','legacy_planning_normalization'] as $token){if(!str_contains($service.$legacy,$token))throw new RuntimeException('deployed historical contract missing: '.$token);}

$placement=$tenant->query("SELECT zayavka_id FROM feo_placement_current WHERE zayavka_id IS NOT NULL AND zayavka_id<>'' ORDER BY zayavka_id LIMIT 1")->fetchColumn();
if(is_string($placement)&&$placement!==''){$preview=WasteTripService::previewSelection($tenant,[$placement]);if((int)($preview['found_count']??0)!==1 || ($preview['occupied']??[])!==[])throw new RuntimeException('operational preview pre-import failed');}

echo 'WASTE_PREIMPORT_MARKER=PASS sha='.$expected.PHP_EOL;
echo 'WASTE_PREIMPORT_ZERO_ROWS=PASS '.json_encode($counts,JSON_UNESCAPED_SLASHES).PHP_EOL;
echo 'WASTE_PREIMPORT_FEO_CLIENT_MATCHES=PASS count='.count($feoClients).PHP_EOL;
echo 'WASTE_PREIMPORT_FREGAT=PASS id=8'.PHP_EOL;
echo 'WASTE_PREIMPORT_RESPONSIBLE=PASS id=1'.PHP_EOL;
echo 'WASTE_PREIMPORT_PARTIAL_PLAN=PASS'.PHP_EOL;
echo 'WASTE_PREIMPORT_HISTORICAL_CONTRACT=PASS'.PHP_EOL;
echo 'WASTE_PREIMPORT_RUNTIME=PASS'.PHP_EOL;
