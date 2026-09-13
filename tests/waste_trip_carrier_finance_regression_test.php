<?php
declare(strict_types=1);

use App\Service\FinanceDashboardService;
use App\Service\FinanceManagementBalanceService;
use App\Service\FinanceObligationService;
use App\Service\FinancePayablesReportService;
use App\Service\FinancePaymentCalendarService;
use App\Service\FinancePaymentPlanFactService;
use App\Service\FinanceUninvoicedObligationService;
use App\Service\LocalMigrationService;
use App\Service\WasteTripCarrierPaymentService;
use App\Service\WasteTripCustomerPaymentService;

$root=dirname(__DIR__);
require_once $root.'/bootstrap/app.php';
require_once $root.'/app/Support/company_database.php';
foreach (['LocalMigrationService','DateCalculationService','ProductionCalendarService','RoutePaymentStatusService','FinanceAuditLogService','FinanceInvoiceService','FinanceOperationService','FinanceBalanceService','LinearRouteService','LinearTripServiceFinanceService','FinanceObligationService','FinanceUninvoicedObligationService','FinancePayablesReportService','FinanceDashboardService','FinancePaymentCalendarService','FinancePaymentPlanFactService','FinanceManagementBalanceService','WasteTripCustomerPaymentService','WasteTripCarrierPaymentService'] as $class) require_once $root.'/app/Service/'.$class.'.php';

$fail=static function(string $m):never{fwrite(STDERR,"FAIL: {$m}\n");exit(1);};
$ok=static function(bool $v,string $m)use($fail):void{if(!$v)$fail($m);};
$migration=(string)file_get_contents($root.'/database/migrations-local/135_create_waste_trip_carrier_payments.sql');
foreach(['CREATE TABLE IF NOT EXISTS `waste_trip_carrier_payments`','`amount` DECIMAL(12,2) NOT NULL','fk_waste_trip_carrier_payments_trip','ON DELETE RESTRICT'] as $n)$ok(str_contains($migration,$n),'migration 135 missing '.$n);
$carrierPartial=(string)file_get_contents($root.'/app/View/partials/company_waste_trip_carrier_payments.php');
foreach(['Оплата перевозчику','data-payment-container="carrier_payments"','data-add-payment-row="carrier_payments"','Срок оплаты','НДС'] as$n)$ok(str_contains($carrierPartial,$n),'carrier UI missing '.$n);
$page=(string)file_get_contents($root.'/app/View/pages/company_waste_trips.php');
$ok(str_contains($page,'СТАВКА ПЕРЕВОЗЧИКА'),'registry carrier rate column missing');
$view=(string)file_get_contents($root.'/app/View/partials/company_waste_trip_modal_view.php');
$ok(str_contains($view,'Условия оплаты перевозчику'),'view carrier finance section missing');
$finance=(string)file_get_contents($root.'/app/Service/FinanceObligationService.php');
foreach(['WASTE_TRIP_CUSTOMER_PAYMENT','WASTE_TRIP_CARRIER_PAYMENT','syncAllOperationalObligations','syncWasteTrip','Перевозка отходов #'] as$n)$ok(str_contains($finance,$n),'finance projection missing '.$n);

$rows=WasteTripCarrierPaymentService::parseRows([['amount'=>'70 000','payment_method'=>'cashless','vat_rate'=>'','condition_type'=>'after_end','days_count'=>'10','days_kind'=>'calendar','specific_due_date'=>'','condition_comment'=>'После выгрузки']],false);
$ok(count($rows)===1&&$rows[0]['amount']==='70000.00','carrier payment parse failed');
$ok(WasteTripCarrierPaymentService::total($rows)==='70 000 ₽','carrier total format failed');
$ok(WasteTripCarrierPaymentService::parseRows([],false)===[],'empty carrier terms must stay backward-compatible');
echo "WASTE_TRIP_CARRIER_FINANCE_STATIC=PASS\n";

if ((string)(getenv('PLANEX_SKIP_MYSQL')?:'')==='1') exit(0);
$host=getenv('PLANEX_SCHEMA_DB_HOST')?:'127.0.0.1';$port=(int)(getenv('PLANEX_SCHEMA_DB_PORT')?:3306);$user=getenv('PLANEX_SCHEMA_DB_USER')?:'planex_ci';$pass=getenv('PLANEX_SCHEMA_DB_PASSWORD')?:'planex_ci';$fixedDb=(string)(getenv('PLANEX_TEST_FIXED_DB')?:'');
$fixedMode=$fixedDb!=='';
if($fixedMode)$ok(in_array($fixedDb,array_filter(array_map('trim',explode(',',(string)getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST')))),true),'fixed guarded DB is not OWNER-approved');
$admin=new PDO("mysql:host={$host};port={$port};charset=utf8mb4",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$db=$fixedMode?$fixedDb:'planex_waste_carrier_finance_'.getmypid();
if($fixedMode){$pdo=new PDO("mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);cleanCompanyPoolDatabase($pdo);}else{$admin->exec("DROP DATABASE IF EXISTS `{$db}`; CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");$pdo=new PDO("mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);}
try{
    LocalMigrationService::apply($pdo);
    $latest=(string)$pdo->query('SELECT migration FROM schema_migrations ORDER BY migration DESC LIMIT 1')->fetchColumn();
    $ok($latest==='135_create_waste_trip_carrier_payments.sql','canonical migration chain must reach 135');
    $pdo->exec("INSERT INTO users(full_name,login,password_hash,role_code,status) VALUES ('Owner','owner-fixture','x','company_owner','active')");$userId=(int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO clients(name,inn,status) VALUES ('Waste Customer','7701000001','active')");$clientId=(int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO contractors(name,inn,status) VALUES ('Waste Carrier','7702000002','active')");$contractorId=(int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO drivers(full_name,phone,status) VALUES ('Driver Fixture','+70000000001','active')");$driverId=(int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO vehicle_units(plate_number,brand,status) VALUES ('A001AA77','Fixture','active')");$vehicleId=(int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO vehicle_sets(set_type,primary_vehicle_unit_id,status) VALUES ('single',{$vehicleId},'active')");$setId=(int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO driver_vehicle_blocks(driver_id,vehicle_set_id,status) VALUES ({$driverId},{$setId},'active')");$blockId=(int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO crews(contractor_id,vehicle_id,driver_id,driver_vehicle_block_id,status) VALUES ({$contractorId},{$vehicleId},{$driverId},{$blockId},'active')");$crewId=(int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO waste_trips(business_id,client_id,route_executor_id,responsible_user_id,destination_type,status,planned_start_mode,planned_start_date,planned_finish_date,actual_start_date,actual_end_date,created_by_user_id,created_by_role,updated_by_user_id,updated_by_role) VALUES (500,{$clientId},{$crewId},{$userId},'warehouse','completed','exact','2026-09-01','2026-09-03','2026-09-01','2026-09-03',{$userId},'company_owner',{$userId},'company_owner')");$tripId=(int)$pdo->lastInsertId();
    $actor=['user_id'=>$userId,'role_code'=>'company_owner'];
    $customer=WasteTripCustomerPaymentService::parseRows([['amount'=>'100000','payment_method'=>'cashless','vat_rate'=>'','condition_type'=>'after_end','days_count'=>'5','days_kind'=>'calendar','specific_due_date'=>'','condition_comment'=>'']],true);
    $carrier=WasteTripCarrierPaymentService::parseRows([['amount'=>'70000','payment_method'=>'cashless','vat_rate'=>'','condition_type'=>'after_end','days_count'=>'10','days_kind'=>'calendar','specific_due_date'=>'','condition_comment'=>'']],false);
    WasteTripCustomerPaymentService::store($pdo,$tripId,$customer,$actor);WasteTripCarrierPaymentService::store($pdo,$tripId,$carrier,$actor);
    $ok(FinanceObligationService::syncWasteTrip($pdo,$tripId)===2,'waste trip must project two obligations');
    $ob=$pdo->query("SELECT * FROM finance_obligations WHERE source_parent_type='WASTE_TRIP' AND source_parent_id={$tripId} AND cancelled_at IS NULL ORDER BY direction")->fetchAll(PDO::FETCH_ASSOC);
    $ok(count($ob)===2,'two active Waste obligations expected');$by=[];foreach($ob as$r)$by[(string)$r['direction']]=$r;
    $ok(($by['RECEIVABLE']['counterparty_name']??'')==='Waste Customer'&&(string)$by['RECEIVABLE']['amount']==='100000.00','customer receivable projection failed');
    $ok(($by['PAYABLE']['counterparty_name']??'')==='Waste Carrier'&&(string)$by['PAYABLE']['amount']==='70000.00','carrier payable projection failed');
    $ok((string)$by['RECEIVABLE']['due_date']==='2026-09-08'&&(string)$by['PAYABLE']['due_date']==='2026-09-13','Waste due-date projection failed');
    $oldObligationIds=array_map('intval',array_column($ob,'id'));$oldSourceIds=array_map('intval',array_column($ob,'source_id'));
    WasteTripCustomerPaymentService::store($pdo,$tripId,$customer,$actor);WasteTripCarrierPaymentService::store($pdo,$tripId,$carrier,$actor);FinanceObligationService::syncWasteTrip($pdo,$tripId);
    $resynced=$pdo->query("SELECT id,source_id FROM finance_obligations WHERE source_parent_type='WASTE_TRIP' AND source_parent_id={$tripId} AND cancelled_at IS NULL ORDER BY direction")->fetchAll(PDO::FETCH_ASSOC);
    $ok(array_map('intval',array_column($resynced,'id'))===$oldObligationIds,'Waste obligation identities must survive payment-row replacement');
    $ok(array_map('intval',array_column($resynced,'source_id'))!==$oldSourceIds,'Waste obligation source IDs must follow replaced payment rows');
    $recv=FinanceObligationService::receivablesReport($pdo)['rows'];$pay=FinancePayablesReportService::build($pdo)['rows'];
    $ok(count(array_filter($recv,fn($r)=>($r['source_parent_type']??'')==='WASTE_TRIP'&&($r['counterparty_name']??'')==='Waste Customer'))===1,'Waste customer must be visible in receivables');
    $ok(count(array_filter($pay,fn($r)=>($r['source_parent_type']??'')==='WASTE_TRIP'&&($r['counterparty_name']??'')==='Waste Carrier'))===1,'Waste carrier must be visible in payables');
    $un=FinanceUninvoicedObligationService::fetchOpen($pdo);$ok(count(array_filter($un,fn($r)=>($r['source_parent_type']??'')==='WASTE_TRIP'))===2,'Waste rates must be visible as uninvoiced obligations');
    $out=FinanceObligationService::fetchCompatibleForInvoice($pdo,'OUTGOING','client',$clientId);$in=FinanceObligationService::fetchCompatibleForInvoice($pdo,'INCOMING','contractor',$contractorId);
    $ok(count($out)===1&&str_contains((string)$out[0]['parent_label'],'Перевозка отходов #500'),'customer obligation must be selectable in outgoing invoice');
    $ok(count($in)===1&&str_contains((string)$in[0]['parent_label'],'Перевозка отходов #500'),'carrier obligation must be selectable in incoming invoice');
    $calendar=FinancePaymentCalendarService::fetchCalendarData($pdo,['role_code'=>'company_owner']);$ok(count(array_filter($calendar,fn($r)=>str_contains((string)($r['route_label']??''),'Перевозка отходов #500')))===2,'Waste rates must be visible in payment calendar');
    $plan=FinancePaymentPlanFactService::fetchPlanFactData($pdo);$ok(count(array_filter($plan,fn($r)=>str_contains((string)($r['source_label']??''),'Перевозка отходов #500')))===2,'Waste rates must be visible in plan-fact');
    $balance=FinanceManagementBalanceService::computeBalance($pdo,'2026-09-30');$ok(count(array_filter(array_merge($balance['receivables'],$balance['payables']),fn($r)=>str_contains((string)($r['name']??''),'Перевозка отходов #500')))===2,'Waste rates must be visible in management balance');
    $dash=FinanceDashboardService::computeDashboard($pdo);$ok((float)$dash['expected_inflow']>=100000.0&&(float)$dash['expected_outflow']>=70000.0,'Waste rates must feed finance dashboard expected flows');
    $pdo->exec("UPDATE waste_trips SET status='cancelled' WHERE id={$tripId}");FinanceObligationService::syncWasteTrip($pdo,$tripId);
    $ok((int)$pdo->query("SELECT COUNT(*) FROM finance_obligations WHERE source_parent_type='WASTE_TRIP' AND source_parent_id={$tripId} AND cancelled_at IS NULL")->fetchColumn()===0,'cancelled Waste Trip must expose no active finance obligations');
    $ok((int)$pdo->query("SELECT COUNT(*) FROM finance_obligations WHERE source_parent_type='WASTE_TRIP' AND source_parent_id={$tripId} AND cancelled_at IS NOT NULL")->fetchColumn()===2,'cancelled Waste Trip must retain two cancelled obligation audit rows');
    echo "WASTE_TRIP_CARRIER_FINANCE_MYSQL=PASS\n";
} finally { if($fixedMode){cleanCompanyPoolDatabase($pdo);}else{$pdo=null;$admin->exec("DROP DATABASE IF EXISTS `{$db}`");} }
