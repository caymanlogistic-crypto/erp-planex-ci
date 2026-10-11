<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/bootstrap/app.php';
require_once BASE_PATH.'/app/Support/company_database.php';
require_once BASE_PATH.'/app/Security/EffectiveAccess.php';
require_once BASE_PATH.'/app/Security/AccessRuntime.php';
foreach (['LocalMigrationService','DateCalculationService','ProductionCalendarService','RegulatedDataScopeService',
    'LinearRouteService','FinanceAuditLogService','FinanceInvoiceService','FinanceInvoicePaymentTimelineService','FinanceSettlementCascadeService','FinanceObligationService',
    'WasteTripCarrierPaymentService','WasteTripClosingService','WasteTripClosingInvoiceService'] as $class) {
    require_once BASE_PATH.'/app/Service/'.$class.'.php';
}

use App\Service\FinanceObligationService;
use App\Service\LocalMigrationService;
use App\Service\WasteTripCarrierPaymentService;
use App\Service\WasteTripClosingInvoiceService;

$database=(string)getenv('PLANEX_TEST_FIXED_DB');
if($database==='' || !in_array($database,explode(',',(string)getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST')),true)) {
    throw new RuntimeException('Guarded test database required');
}
$pdo=new PDO('mysql:host='.getenv('PLANEX_SCHEMA_DB_HOST').';port='.(getenv('PLANEX_SCHEMA_DB_PORT')?:3306).';dbname='.$database.';charset=utf8mb4',
    getenv('PLANEX_SCHEMA_DB_USER'),getenv('PLANEX_SCHEMA_DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
cleanCompanyPoolDatabase($pdo);
$assert=static function(bool $condition,string $message):void {if(!$condition) throw new RuntimeException($message);};
try {
    LocalMigrationService::apply($pdo);
    $_SESSION['role_code']='company_owner';$_SESSION['user_id']=1;
    $pdo->exec("INSERT INTO users(full_name,login,password_hash,role_code,status) VALUES ('Owner','owner-paid-invoice','x','company_owner','active')");
    $userId=(int)$pdo->lastInsertId();$_SESSION['user_id']=$userId;
    $pdo->exec("INSERT INTO clients(name,inn,status) VALUES ('Customer','7701000011','active')");$clientId=(int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO contractors(name,inn,status) VALUES ('Carrier','7702000022','active')");$carrierId=(int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO drivers(full_name,phone,status) VALUES ('Driver','+70000000002','active')");$driverId=(int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO vehicle_units(plate_number,brand,status) VALUES ('A002AA77','Fixture','active')");$vehicleId=(int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO vehicle_sets(set_type,primary_vehicle_unit_id,status) VALUES ('single',{$vehicleId},'active')");$setId=(int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO driver_vehicle_blocks(driver_id,vehicle_set_id,status) VALUES ({$driverId},{$setId},'active')");$blockId=(int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO crews(contractor_id,vehicle_id,driver_id,driver_vehicle_block_id,status) VALUES ({$carrierId},{$vehicleId},{$driverId},{$blockId},'active')");$crewId=(int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO waste_trips(business_id,client_id,route_executor_id,responsible_user_id,legal_entity_code,destination_type,status,planned_start_mode,actual_end_date,created_by_user_id,created_by_role) VALUES (901,{$clientId},{$crewId},{$userId},'planex','warehouse','completed','unspecified','2026-10-10',{$userId},'company_owner')");
    $tripId=(int)$pdo->lastInsertId();$actor=['user_id'=>$userId,'role_code'=>'company_owner'];
    $rows=WasteTripCarrierPaymentService::parseRows([['amount'=>'60.00'],['amount'=>'40.00']],true);
    WasteTripCarrierPaymentService::store($pdo,$tripId,$rows,$actor);
    FinanceObligationService::syncWasteTrip($pdo,$tripId);
    $obligations=$pdo->query("SELECT id,amount FROM finance_obligations WHERE source_parent_type='WASTE_TRIP' AND source_parent_id={$tripId} AND party_role='carrier' AND cancelled_at IS NULL ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    $assert(count($obligations)===2,'Two carrier obligations required');
    $pdo->exec("INSERT INTO finance_invoices(direction,number,invoice_date,counterparty_entity_type,counterparty_entity_id,counterparty_name,amount,status,paid_amount)
        VALUES ('INCOMING','PAID-901','2026-10-10','contractor',{$carrierId},'Carrier',100.00,'paid',100.00)");
    $invoiceId=(int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO finance_money_accounts(type,name) VALUES ('CASH','Test cash')");$accountId=(int)$pdo->lastInsertId();
    foreach ([70,30] as $amount) {
        $pdo->exec("INSERT INTO finance_operations(operation_type,status,source,money_account_id,operation_date,amount,counterparty_entity_type,counterparty_entity_id)
            VALUES ('EXPENSE','POSTED','MANUAL',{$accountId},'2026-10-10',{$amount},'contractor',{$carrierId})");
        $operationId=(int)$pdo->lastInsertId();
        $pdo->exec("INSERT INTO finance_operation_allocations(operation_id,invoice_id,amount,allocation_date,method,created_by_user_id,created_by_role)
            VALUES ({$operationId},{$invoiceId},{$amount},'2026-10-10','manual',{$userId},'company_owner')");
    }
    $candidates=WasteTripClosingInvoiceService::candidates($pdo,$tripId);
    $assert(count($candidates)===1 && (int)$candidates[0]['id']===$invoiceId,'Paid standalone invoice must be selectable');
    WasteTripClosingInvoiceService::linkExisting($pdo,$tripId,$invoiceId,$actor);
    $links=$pdo->query("SELECT obligation_id,amount FROM finance_invoice_links WHERE invoice_id={$invoiceId} ORDER BY obligation_id")->fetchAll(PDO::FETCH_ASSOC);
    $assert(count($links)===2 && (string)$links[0]['amount']==='60.00' && (string)$links[1]['amount']==='40.00','Invoice linked across both obligations');
    $active=$pdo->query("SELECT operation_id,obligation_id,amount,allocation_date FROM finance_operation_allocations WHERE invoice_id={$invoiceId} AND cancelled_at IS NULL ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    $assert(count($active)===3,'Cross-obligation payment must split safely');
    $assert(count(array_filter($active,static fn(array $a):bool=>$a['allocation_date']!=='2026-10-10'))===0,'Payment dates remain unchanged');
    $operationTotals=$pdo->query("SELECT operation_id,SUM(amount) total FROM finance_operation_allocations WHERE invoice_id={$invoiceId} AND cancelled_at IS NULL GROUP BY operation_id ORDER BY operation_id")->fetchAll(PDO::FETCH_COLUMN|PDO::FETCH_GROUP);
    $assert(array_values(array_map(static fn(array $v):string=>(string)$v[0],$operationTotals))===['70.00','30.00'],'Each original operation keeps its paid amount');
    $assert((int)$pdo->query("SELECT COUNT(*) FROM finance_operation_allocations WHERE invoice_id={$invoiceId} AND cancelled_at IS NOT NULL")->fetchColumn()===2,'Old standalone allocations retained as cancelled audit history');
    $assert((string)$pdo->query("SELECT paid_amount FROM finance_invoices WHERE id={$invoiceId}")->fetchColumn()==='100.00','Invoice remains paid');
    $paid=$pdo->query("SELECT paid_amount FROM finance_obligations WHERE source_parent_type='WASTE_TRIP' AND source_parent_id={$tripId} AND party_role='carrier' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    $assert($paid===['60.00','40.00'],'Payment moved to trip obligations');
    $assert(WasteTripClosingInvoiceService::summaries($pdo,[$tripId])[$tripId]['status']==='paid','Closing page shows paid invoice');
    $assert(WasteTripClosingInvoiceService::candidates($pdo,$tripId)===[],'Linked invoice no longer selectable');
    try {WasteTripClosingInvoiceService::linkExisting($pdo,$tripId,$invoiceId,$actor);throw new RuntimeException('Duplicate link must fail');}
    catch (DomainException $error) {$assert(str_contains($error->getMessage(),'уже связан'),'Duplicate link error');}
    $pdo->exec("INSERT INTO finance_invoices(direction,number,invoice_date,counterparty_entity_type,counterparty_entity_id,amount,status,paid_amount)
        VALUES ('INCOMING','TOO-LARGE-901','2026-10-10','contractor',{$carrierId},120.00,'paid',120.00)");
    $tooLargeId=(int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO finance_operations(operation_type,status,source,money_account_id,operation_date,amount,counterparty_entity_type,counterparty_entity_id)
        VALUES ('EXPENSE','POSTED','MANUAL',{$accountId},'2026-10-10',120.00,'contractor',{$carrierId})");
    $tooLargeOperationId=(int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO finance_operation_allocations(operation_id,invoice_id,amount,allocation_date,method)
        VALUES ({$tooLargeOperationId},{$tooLargeId},120.00,'2026-10-10','manual')");
    try {WasteTripClosingInvoiceService::linkExisting($pdo,$tripId,$tooLargeId,$actor);throw new RuntimeException('Insufficient trip capacity must fail');}
    catch (DomainException $error) {$assert(str_contains($error->getMessage(),'остаток'),'Capacity error');}
    $assert((int)$pdo->query("SELECT COUNT(*) FROM finance_invoice_links WHERE invoice_id={$tooLargeId}")->fetchColumn()===0,'Failed link rolls back');
    $assert((int)$pdo->query("SELECT COUNT(*) FROM finance_operation_allocations WHERE invoice_id={$tooLargeId} AND cancelled_at IS NULL AND obligation_id IS NULL")->fetchColumn()===1,'Failed link preserves standalone payment');
    $pdo->exec("INSERT INTO waste_trips(business_id,client_id,route_executor_id,responsible_user_id,legal_entity_code,destination_type,status,planned_start_mode,actual_end_date,created_by_user_id,created_by_role)
        VALUES (902,{$clientId},{$crewId},{$userId},'planex','warehouse','completed','unspecified','2026-10-10',{$userId},'company_owner')");
    $partialTripId=(int)$pdo->lastInsertId();
    WasteTripCarrierPaymentService::store($pdo,$partialTripId,WasteTripCarrierPaymentService::parseRows([['amount'=>'100.00']],true),$actor);
    FinanceObligationService::syncWasteTrip($pdo,$partialTripId);
    $pdo->exec("INSERT INTO finance_invoices(direction,number,invoice_date,counterparty_entity_type,counterparty_entity_id,amount,status,paid_amount)
        VALUES ('INCOMING','PARTIAL-902','2026-10-10','contractor',{$carrierId},100.00,'partially_paid',40.00)");
    $partialInvoiceId=(int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO finance_operations(operation_type,status,source,money_account_id,operation_date,amount,counterparty_entity_type,counterparty_entity_id)
        VALUES ('EXPENSE','POSTED','MANUAL',{$accountId},'2026-10-09',40.00,'contractor',{$carrierId})");
    $partialOperationId=(int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO finance_operation_allocations(operation_id,invoice_id,amount,allocation_date,method)
        VALUES ({$partialOperationId},{$partialInvoiceId},40.00,'2026-10-09','manual')");
    $assert(count(WasteTripClosingInvoiceService::candidates($pdo,$partialTripId))===2,'Paid and partially paid invoices appear in picker');
    WasteTripClosingInvoiceService::linkExisting($pdo,$partialTripId,$partialInvoiceId,$actor);
    $partialAllocation=$pdo->query("SELECT obligation_id,amount,allocation_date FROM finance_operation_allocations WHERE invoice_id={$partialInvoiceId} AND cancelled_at IS NULL")->fetch(PDO::FETCH_ASSOC);
    $assert($partialAllocation && $partialAllocation['obligation_id']!==null && $partialAllocation['amount']==='40.00' && $partialAllocation['allocation_date']==='2026-10-09','Partial payment moved with its date');
    $assert((string)$pdo->query("SELECT paid_amount FROM finance_invoices WHERE id={$partialInvoiceId}")->fetchColumn()==='40.00','Invoice stays partly paid');
    $assert((string)$pdo->query("SELECT paid_amount FROM finance_obligations WHERE source_parent_type='WASTE_TRIP' AND source_parent_id={$partialTripId} AND party_role='carrier'")->fetchColumn()==='40.00','Partial payment credited to trip');
    echo "WASTE_TRIP_CLOSING_PAID_INVOICE=PASS\n";
} finally {cleanCompanyPoolDatabase($pdo);}
