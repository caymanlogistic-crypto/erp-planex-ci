<?php

declare(strict_types=1);

require_once __DIR__.'/../app/Service/DateCalculationService.php';
require_once __DIR__.'/../app/Service/ProductionCalendarService.php';
require_once __DIR__.'/../app/Service/RoutePaymentStatusService.php';
require_once __DIR__.'/../app/Service/FinanceAuditLogService.php';
require_once __DIR__.'/../app/Service/FinanceOperationService.php';
require_once __DIR__.'/../app/Service/FinanceInvoiceService.php';
require_once __DIR__.'/../app/Service/LinearTripServiceLineService.php';
require_once __DIR__.'/../app/Service/LinearTripServiceFinanceService.php';
require_once __DIR__.'/../app/Service/FinanceSettlementCascadeService.php';
require_once __DIR__.'/../app/Service/FinanceObligationService.php';
require_once __DIR__.'/../app/Service/FinanceUninvoicedObligationService.php';
require_once __DIR__.'/../app/Service/FinanceInvoicePrepaymentService.php';
require_once __DIR__.'/../app/Service/FinanceManualAllocationService.php';
require_once __DIR__.'/../app/Service/FinanceAllocationService.php';
require_once __DIR__.'/../app/Service/FinancePayablesReportService.php';
require_once __DIR__.'/../app/Service/FinancePaymentCalendarService.php';
require_once __DIR__.'/../app/Service/FinancePaymentPlanFactService.php';
require_once __DIR__.'/../app/Service/FinanceBalanceService.php';
require_once __DIR__.'/../app/Service/FinanceDashboardService.php';

use App\Service\FinanceAllocationService;
use App\Service\FinanceDashboardService;
use App\Service\FinanceInvoicePrepaymentService;
use App\Service\FinanceObligationService;
use App\Service\FinancePayablesReportService;
use App\Service\FinancePaymentCalendarService;
use App\Service\FinancePaymentPlanFactService;
use App\Service\FinanceUninvoicedObligationService;

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAIL: '.$message);
}

function m(mixed $value): string
{
    $value = trim(str_replace(',', '.', (string)$value));
    if ($value === '') return '0.00';
    [$whole,$fraction] = array_pad(explode('.', $value, 2),2,'');
    return ((int)$whole).'.'.str_pad(substr($fraction,0,2),2,'0');
}

function rowById(array $rows, int $id): ?array
{
    foreach ($rows as $row) if ((int)($row['id'] ?? $row['source_id'] ?? 0) === $id) return $row;
    return null;
}

function expectFailure(callable $fn, string $message): void
{
    try { $fn(); } catch (Throwable) { return; }
    throw new RuntimeException('FAIL: '.$message);
}

$dsn = getenv('PLANEX_FINANCE_TEST_DSN') ?: 'mysql:host=127.0.0.1;port=3306;dbname=erp_obligations_test;charset=utf8mb4';
$dbUser = getenv('PLANEX_FINANCE_TEST_USER') ?: 'root';
$dbPass = getenv('PLANEX_FINANCE_TEST_PASSWORD') ?: 'root';
$pdo = new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec('SET SESSION innodb_lock_wait_timeout=1');

$tables = [
    'entity_access_grants','finance_employee_invoice_payments','finance_money_accounts',
    'finance_operation_allocations','finance_invoice_links','finance_obligations','finance_operations','finance_invoices','finance_audit_log',
    'linear_route_service_payments','linear_route_services','linear_route_payments','linear_route_principals','linear_routes','clients','contractors'
];
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach ($tables as $table) $pdo->exec("DROP TABLE IF EXISTS `$table`");
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');

$pdo->exec("CREATE TABLE clients(id INT UNSIGNED PRIMARY KEY,name VARCHAR(255),inn VARCHAR(20)) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE contractors(id INT UNSIGNED PRIMARY KEY,name VARCHAR(255),inn VARCHAR(20)) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE linear_routes(id INT UNSIGNED PRIMARY KEY,client_id INT UNSIGNED,carrier_contractor_id INT UNSIGNED,route_type VARCHAR(50) DEFAULT 'linear',planned_loading_date DATE,planned_unloading_date DATE,actual_loading_date DATE NULL,actual_unloading_date DATE NULL,closing_documents_received_date DATE NULL,created_by_user_id INT UNSIGNED NULL,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,deleted_at DATETIME NULL) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE entity_access_grants(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,entity_type VARCHAR(50),entity_id INT UNSIGNED,granted_to_user_id INT UNSIGNED,access_level VARCHAR(20),revoked_at DATETIME NULL) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE linear_route_principals(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,linear_route_id INT UNSIGNED,principal_type VARCHAR(20),principal_id INT UNSIGNED,sort_order INT,deleted_at DATETIME NULL) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE linear_route_payments(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,linear_route_id INT UNSIGNED,party_role VARCHAR(20),linear_route_principal_id INT UNSIGNED NULL,sort_order INT,amount DECIMAL(12,2),condition_type VARCHAR(50) NULL,payment_due_type VARCHAR(100) NULL,payment_due_days INT NULL,payment_due_days_kind VARCHAR(20) NULL,days_count INT NULL,days_kind VARCHAR(20) NULL,specific_due_date DATE NULL,side VARCHAR(10) NULL,paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0,payment_status VARCHAR(30) NOT NULL DEFAULT 'planned',forecast_due_date DATE NULL,calculated_due_date DATE NULL,paid_at DATE NULL,cancelled_at DATETIME NULL,status_updated_at DATETIME NULL,created_by_user_id INT NULL,created_by_role VARCHAR(20) NULL,updated_by_user_id INT NULL,updated_by_role VARCHAR(20) NULL,deleted_at DATETIME NULL) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE linear_route_services(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,linear_route_id INT UNSIGNED NOT NULL,contractor_id INT UNSIGNED NOT NULL,service_name VARCHAR(190) NOT NULL,description TEXT NULL,sort_order INT UNSIGNED NOT NULL DEFAULT 0,created_by_user_id INT UNSIGNED NULL,created_by_role VARCHAR(40) NULL,updated_by_user_id INT UNSIGNED NULL,updated_by_role VARCHAR(40) NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,deleted_at DATETIME NULL,deleted_by_user_id INT UNSIGNED NULL,deleted_by_role VARCHAR(40) NULL) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE linear_route_service_payments(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,linear_route_service_id INT UNSIGNED NOT NULL,amount DECIMAL(15,2) NOT NULL,payment_type VARCHAR(100) NULL,payment_method VARCHAR(20) NOT NULL DEFAULT 'cashless',vat_rate VARCHAR(10) NULL,condition_type VARCHAR(100) NOT NULL,days_count INT UNSIGNED NULL,days_kind VARCHAR(20) NULL,specific_due_date DATE NULL,condition_comment VARCHAR(500) NULL,calculated_due_date DATE NULL,paid_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,status VARCHAR(40) NOT NULL DEFAULT 'planned',sort_order INT UNSIGNED NOT NULL DEFAULT 0,created_by_user_id INT UNSIGNED NULL,created_by_role VARCHAR(40) NULL,updated_by_user_id INT UNSIGNED NULL,updated_by_role VARCHAR(40) NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,deleted_at DATETIME NULL,deleted_by_user_id INT UNSIGNED NULL,deleted_by_role VARCHAR(40) NULL) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE finance_invoices(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,direction VARCHAR(20),number VARCHAR(100),invoice_date DATE,counterparty_entity_type VARCHAR(20) NULL,counterparty_entity_id INT NULL,counterparty_name VARCHAR(500) NULL,counterparty_inn VARCHAR(20) NULL,amount DECIMAL(12,2),planned_payment_date DATE NULL,status VARCHAR(30),paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0,cancelled_at DATETIME NULL,first_paid_at DATE NULL,fully_paid_at DATE NULL,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE finance_invoice_links(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,invoice_id INT UNSIGNED,obligation_id INT UNSIGNED NULL,linear_route_id INT UNSIGNED NULL,linear_route_payment_id INT UNSIGNED NULL,amount DECIMAL(12,2) NULL,side VARCHAR(20) NULL,created_by_user_id INT NULL,created_by_role VARCHAR(50) NULL,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,KEY idx_fil_invoice(invoice_id),KEY idx_fil_obligation(obligation_id)) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE finance_operations(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,money_account_id INT UNSIGNED NULL,bank_transaction_id BIGINT UNSIGNED NULL,operation_date DATE,operation_type VARCHAR(20),transfer_direction VARCHAR(10) NULL,counterparty_entity_type VARCHAR(20) NULL,counterparty_entity_id INT UNSIGNED NULL,counterparty_name VARCHAR(500) NULL,counterparty_inn VARCHAR(20) NULL,purpose TEXT NULL,amount DECIMAL(12,2),status VARCHAR(30),cancelled_at DATETIME NULL) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE finance_operation_allocations(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,operation_id INT UNSIGNED,invoice_id INT UNSIGNED NULL,obligation_id INT UNSIGNED NULL,linear_route_id INT UNSIGNED NULL,linear_route_payment_id INT UNSIGNED NULL,amount DECIMAL(12,2),allocation_date DATE,method VARCHAR(30),comment TEXT NULL,created_by_user_id INT NULL,created_by_role VARCHAR(50) NULL,cancelled_at DATETIME NULL,cancelled_by_user_id INT NULL,cancelled_by_role VARCHAR(50) NULL,cancel_reason TEXT NULL,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,KEY idx_foa_operation(operation_id),KEY idx_foa_obligation(obligation_id),KEY idx_foa_invoice(invoice_id)) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE finance_audit_log(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,entity_type VARCHAR(64),entity_id INT UNSIGNED,action VARCHAR(64),old_values TEXT NULL,new_values TEXT NULL,created_by_user_id INT NULL,created_by_role VARCHAR(64) NULL,created_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE finance_obligations(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,source_type VARCHAR(50),source_key VARCHAR(255) UNIQUE,source_id INT UNSIGNED NULL,source_parent_type VARCHAR(50),source_parent_id INT UNSIGNED,direction VARCHAR(20),party_role VARCHAR(20) NULL,counterparty_entity_type VARCHAR(20) NULL,counterparty_entity_id INT NULL,counterparty_name VARCHAR(500) NULL,counterparty_inn VARCHAR(20) NULL,amount DECIMAL(12,2),condition_type VARCHAR(50) NULL,days_count INT NULL,days_kind VARCHAR(20) NULL,specific_due_date DATE NULL,event_date DATE NULL,forecast_due_date DATE NULL,due_date DATE NULL,paid_amount DECIMAL(12,2) DEFAULT 0,status VARCHAR(30),cancelled_at DATETIME NULL,created_by_user_id INT NULL,created_by_role VARCHAR(20) NULL,updated_by_user_id INT NULL,updated_by_role VARCHAR(20) NULL,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,KEY idx_fo_parent(source_parent_type,source_parent_id)) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE finance_money_accounts(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,type VARCHAR(20),name VARCHAR(255),bank_account_id INT NULL,opening_balance DECIMAL(12,2) DEFAULT 0,is_active TINYINT NOT NULL DEFAULT 0) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE finance_employee_invoice_payments(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,expense_finance_operation_id INT UNSIGNED NULL,status VARCHAR(30) NOT NULL DEFAULT 'POSTED') ENGINE=InnoDB");

$pdo->exec("INSERT INTO clients VALUES(1,'Заказчик','7701000001')");
$pdo->exec("INSERT INTO contractors VALUES(2,'Основной перевозчик','7702000002'),(3,'Кран и такелаж','7703000003'),(4,'Грузчики Сервис','7704000004')");
$pdo->exec("INSERT INTO linear_routes(id,client_id,carrier_contractor_id,planned_loading_date,planned_unloading_date,actual_loading_date,actual_unloading_date,created_by_user_id) VALUES(10,1,2,'2026-08-25','2026-08-26','2026-08-25','2026-08-26',1)");
$pdo->exec("INSERT INTO linear_route_payments(linear_route_id,party_role,sort_order,amount,condition_type,specific_due_date,side,created_by_role,updated_by_role) VALUES(10,'carrier',1,1000,'specific_date','2026-09-05','expense','company_owner','company_owner')");
$pdo->exec("INSERT INTO linear_route_services(linear_route_id,contractor_id,service_name,sort_order) VALUES(10,3,'Кран',1),(10,4,'Грузчики',2),(10,3,'Такелаж',3)");
$pdo->exec("INSERT INTO linear_route_service_payments(linear_route_service_id,amount,condition_type,specific_due_date,calculated_due_date,sort_order) VALUES(1,100,'specific_date','2026-09-01','2026-09-01',1),(2,200,'specific_date','2026-09-02','2026-09-02',1),(3,150,'specific_date','2026-09-03','2026-09-03',1)");

FinanceObligationService::syncAllLinearRoutes($pdo);
$carrierId = (int)$pdo->query("SELECT id FROM finance_obligations WHERE source_type='LINEAR_ROUTE_PAYMENT' AND party_role='carrier'")->fetchColumn();
$a = $pdo->query("SELECT * FROM finance_obligations WHERE source_type='LINEAR_ROUTE_SERVICE_PAYMENT' AND source_id=1")->fetch();
$b = $pdo->query("SELECT * FROM finance_obligations WHERE source_type='LINEAR_ROUTE_SERVICE_PAYMENT' AND source_id=2")->fetch();
$c = $pdo->query("SELECT * FROM finance_obligations WHERE source_type='LINEAR_ROUTE_SERVICE_PAYMENT' AND source_id=3")->fetch();
check($carrierId > 0 && $a && $b && $c, 'route and all service obligations were created');
check((int)$a['counterparty_entity_id']===3 && (int)$b['counterparty_entity_id']===4 && (int)$c['counterparty_entity_id']===3, 'services preserve different contractors');
$userCtx=['user_id'=>1,'role_code'=>'company_owner'];

// Scenario 1: production manual allocation to an additional-service obligation BEFORE invoice.
$pdo->exec("INSERT INTO finance_operations(operation_date,operation_type,counterparty_entity_type,counterparty_entity_id,counterparty_name,counterparty_inn,purpose,amount,status) VALUES('2026-08-27','EXPENSE','contractor',4,'Грузчики Сервис','7704000004','Предоплата грузчиков',120,'POSTED')");
$picker = FinanceAllocationService::fetchObligationsForAllocation($pdo,1,10,$userCtx);
check(count($picker)===1 && (int)$picker[0]['id']===(int)$b['id'], 'manual picker exposes matching service obligation, not carrier-only rows');
$allocationB = FinanceAllocationService::createAllocation($pdo,1,['amount'=>'120.00','obligation_id'=>(int)$b['id'],'linear_route_id'=>10,'allocation_date'=>'2026-08-27'], $userCtx);
$allocB = $pdo->query("SELECT * FROM finance_operation_allocations WHERE id={$allocationB}")->fetch();
check((int)$allocB['obligation_id']===(int)$b['id'], 'direct service manual allocation stores obligation_id');
check($allocB['linear_route_payment_id']===null, 'service allocation leaves legacy linear_route_payment_id NULL');
check(m($pdo->query("SELECT paid_amount FROM linear_route_service_payments WHERE id=2")->fetchColumn())==='120.00', 'service payment paid_amount updated through production cascade');
check((string)$pdo->query("SELECT status FROM linear_route_service_payments WHERE id=2")->fetchColumn()==='overdue_partial', 'overdue service payment status becomes overdue partial');
check(m($pdo->query("SELECT paid_amount FROM finance_obligations WHERE id=".(int)$b['id'])->fetchColumn())==='120.00', 'canonical obligation paid_amount updated');

// Validation: counterparty, direction, overpayment, route access.
$pdo->exec("INSERT INTO finance_operations(operation_date,operation_type,counterparty_entity_type,counterparty_entity_id,counterparty_name,counterparty_inn,amount,status) VALUES('2026-08-27','EXPENSE','contractor',3,'Кран и такелаж','7703000003',10,'POSTED'),('2026-08-27','INCOME','contractor',4,'Грузчики Сервис','7704000004',10,'POSTED'),('2026-08-27','EXPENSE','contractor',4,'Грузчики Сервис','7704000004',100,'POSTED')");
expectFailure(fn()=>FinanceAllocationService::createAllocation($pdo,2,['amount'=>'10','obligation_id'=>(int)$b['id']],$userCtx),'counterparty mismatch is rejected');
expectFailure(fn()=>FinanceAllocationService::createAllocation($pdo,3,['amount'=>'10','obligation_id'=>(int)$b['id']],$userCtx),'direction mismatch is rejected');
expectFailure(fn()=>FinanceAllocationService::createAllocation($pdo,4,['amount'=>'100','obligation_id'=>(int)$b['id']],$userCtx),'obligation overpayment is rejected');
expectFailure(fn()=>FinanceAllocationService::fetchObligationsForAllocation($pdo,1,10,['user_id'=>99,'role_code'=>'logist']),'route access is enforced for logist');

// Late invoice inherits the production-created direct service allocation without changing fact total.
$pdo->exec("INSERT INTO finance_invoices(direction,number,invoice_date,counterparty_entity_type,counterparty_entity_id,counterparty_name,counterparty_inn,amount,planned_payment_date,status) VALUES('INCOMING','B-LATE-80','2026-08-27','contractor',4,'Грузчики Сервис','7704000004',80,'2026-09-02','received')");
FinanceObligationService::replaceInvoiceLinks($pdo,1,[['obligation_id'=>(int)$b['id'],'amount'=>'80.00']],$userCtx);
$inheritB = FinanceInvoicePrepaymentService::reconcileInvoice($pdo,1,$userCtx);
check((string)$inheritB['amount']==='80.00','late invoice inherits 80 from manual direct settlement');
check(m($pdo->query("SELECT SUM(amount) FROM finance_operation_allocations WHERE operation_id=1 AND cancelled_at IS NULL")->fetchColumn())==='120.00','late invoice inheritance preserves total factual payment');
check(m($pdo->query("SELECT paid_amount FROM finance_invoices WHERE id=1")->fetchColumn())==='80.00' && (string)$pdo->query("SELECT status FROM finance_invoices WHERE id=1")->fetchColumn()==='paid','late invoice is immediately paid');
check(m($pdo->query("SELECT paid_amount FROM finance_obligations WHERE id=".(int)$b['id'])->fetchColumn())==='120.00','inheritance does not duplicate obligation payment');

// Scenario 2: invoice first, then invoice-only production allocation across TWO linked service obligations.
$pdo->exec("INSERT INTO finance_invoices(direction,number,invoice_date,counterparty_entity_type,counterparty_entity_id,counterparty_name,counterparty_inn,amount,planned_payment_date,status) VALUES('INCOMING','AC-250','2026-08-27','contractor',3,'Кран и такелаж','7703000003',250,'2026-09-03','received')");
FinanceObligationService::replaceInvoiceLinks($pdo,2,[['obligation_id'=>(int)$a['id'],'amount'=>'100.00'],['obligation_id'=>(int)$c['id'],'amount'=>'150.00']],$userCtx);
$pdo->exec("INSERT INTO finance_operations(operation_date,operation_type,counterparty_entity_type,counterparty_entity_id,counterparty_name,counterparty_inn,amount,status) VALUES('2026-08-27','EXPENSE','contractor',3,'Кран и такелаж','7703000003',130,'POSTED'),('2026-08-27','EXPENSE','contractor',3,'Кран и такелаж','7703000003',120,'POSTED')");
$firstAcAllocation = FinanceAllocationService::createAllocation($pdo,5,['amount'=>'130','invoice_id'=>2,'allocation_date'=>'2026-08-27'],$userCtx);
$acRows = $pdo->query("SELECT * FROM finance_operation_allocations WHERE operation_id=5 AND cancelled_at IS NULL ORDER BY id")->fetchAll();
check(count($acRows)===2,'invoice-only amount is split across linked obligations');
check(array_reduce($acRows,fn(bool $ok,array $r):bool=>$ok && (int)$r['obligation_id']>0,true),'invoice-only path never writes obligation_id NULL');
check((int)$acRows[0]['obligation_id']===(int)$a['id'] && m($acRows[0]['amount'])==='100.00','first linked obligation consumes exact 100');
check((int)$acRows[1]['obligation_id']===(int)$c['id'] && m($acRows[1]['amount'])==='30.00','second linked obligation receives residual 30');
check($acRows[0]['linear_route_payment_id']===null && $acRows[1]['linear_route_payment_id']===null,'service invoice allocations never fake legacy route payment id');
check(m($pdo->query("SELECT paid_amount FROM finance_invoices WHERE id=2")->fetchColumn())==='130.00' && (string)$pdo->query("SELECT status FROM finance_invoices WHERE id=2")->fetchColumn()==='overdue_partial','invoice reflects overdue partial production payment');
check(m($pdo->query("SELECT paid_amount FROM finance_obligations WHERE id=".(int)$a['id'])->fetchColumn())==='100.00','service A obligation fully paid');
check(m($pdo->query("SELECT paid_amount FROM finance_obligations WHERE id=".(int)$c['id'])->fetchColumn())==='30.00','service C obligation partially paid');
check((string)$pdo->query("SELECT status FROM linear_route_service_payments WHERE id=1")->fetchColumn()==='paid','service A source status paid');
check((string)$pdo->query("SELECT status FROM linear_route_service_payments WHERE id=3")->fetchColumn()==='overdue_partial','service C source status overdue partial');

// Cross-report end-to-end semantics after partial invoice payment.
$payables = FinancePayablesReportService::build($pdo);
$payC = rowById($payables['rows'],(int)$c['id']);
$payB = rowById($payables['rows'],(int)$b['id']);
check($payC && m($payC['remaining_amount'])==='120.00','Payables sees exact service C remainder');
check($payB && m($payB['remaining_amount'])==='80.00','Payables sees exact service B economic remainder');
$calendar = FinancePaymentCalendarService::fetchCalendarData($pdo);
check(rowById($calendar,(int)$a['id'])===null,'Payment Calendar removes fully-paid service A');
check(m((rowById($calendar,(int)$c['id'])['remaining'] ?? '0'))==='120.00','Payment Calendar sees service C remainder');
$plan = FinancePaymentPlanFactService::getTotals($pdo);
check($plan['remaining_expense']==='1200.00','Plan/Fact has invoice 120 + service B 80 + carrier 1000 without double-counting');
$dashboard = FinanceDashboardService::computeDashboard($pdo);
check($dashboard['expected_outflow']==='1200.00','Dashboard expected outflow matches canonical remaining debt without double-counting');

// Complete the same invoice through production path.
$completeAc = FinanceAllocationService::createAllocation($pdo,6,['amount'=>'120','invoice_id'=>2,'allocation_date'=>'2026-08-27'],$userCtx);
check(m($pdo->query("SELECT paid_amount FROM finance_invoices WHERE id=2")->fetchColumn())==='250.00' && (string)$pdo->query("SELECT status FROM finance_invoices WHERE id=2")->fetchColumn()==='paid','invoice becomes fully paid');
check(m($pdo->query("SELECT paid_amount FROM finance_obligations WHERE id=".(int)$c['id'])->fetchColumn())==='150.00','service C obligation becomes fully paid');
check(m($pdo->query("SELECT paid_amount FROM linear_route_service_payments WHERE id=3")->fetchColumn())==='150.00' && (string)$pdo->query("SELECT status FROM linear_route_service_payments WHERE id=3")->fetchColumn()==='paid','service C source becomes paid');
$planAfter = FinancePaymentPlanFactService::getTotals($pdo);
$dashboardAfter = FinanceDashboardService::computeDashboard($pdo);
check($planAfter['remaining_expense']==='1080.00','Plan/Fact leaves only service B 80 + carrier 1000 after invoice completion');
check($dashboardAfter['expected_outflow']==='1080.00','Dashboard leaves only real remaining debt after invoice completion');

// Cancellation must reopen invoice, obligation and service; retry on the SAME operation must be possible after cancellation.
FinanceAllocationService::cancelAllocation($pdo,$completeAc,$userCtx,'Проверка отмены');
check(m($pdo->query("SELECT paid_amount FROM finance_invoices WHERE id=2")->fetchColumn())==='130.00','allocation cancellation reopens invoice');
check(m($pdo->query("SELECT paid_amount FROM finance_obligations WHERE id=".(int)$c['id'])->fetchColumn())==='30.00','allocation cancellation reopens obligation');
check(m($pdo->query("SELECT paid_amount FROM linear_route_service_payments WHERE id=3")->fetchColumn())==='30.00','allocation cancellation reopens service payment');
$retryAc = FinanceAllocationService::createAllocation($pdo,6,['amount'=>'120','invoice_id'=>2,'allocation_date'=>'2026-08-27'],$userCtx);
check($retryAc!==$completeAc && (string)$pdo->query("SELECT status FROM finance_invoices WHERE id=2")->fetchColumn()==='paid','cancelled allocation can be retried cleanly');
expectFailure(fn()=>FinanceAllocationService::createAllocation($pdo,6,['amount'=>'1','invoice_id'=>2],$userCtx),'repeated allocation cannot exceed operation/invoice remainder');

// Multiple invoices on one obligation: second late invoice inherits remaining direct settlement, then production write-path closes it.
$pdo->exec("INSERT INTO finance_invoices(direction,number,invoice_date,counterparty_entity_type,counterparty_entity_id,counterparty_name,counterparty_inn,amount,planned_payment_date,status) VALUES('INCOMING','B-SECOND-50','2026-08-27','contractor',4,'Грузчики Сервис','7704000004',50,'2026-09-04','received')");
FinanceObligationService::replaceInvoiceLinks($pdo,3,[['obligation_id'=>(int)$b['id'],'amount'=>'50.00']],$userCtx);
$inheritB2=FinanceInvoicePrepaymentService::reconcileInvoice($pdo,3,$userCtx);
check((string)$inheritB2['amount']==='40.00','second invoice on same obligation inherits remaining direct 40');
check(m($pdo->query("SELECT paid_amount FROM finance_invoices WHERE id=3")->fetchColumn())==='40.00','second invoice is partially paid from earlier direct settlement');
$pdo->exec("INSERT INTO finance_operations(operation_date,operation_type,counterparty_entity_type,counterparty_entity_id,counterparty_name,counterparty_inn,amount,status) VALUES('2026-08-27','EXPENSE','contractor',4,'Грузчики Сервис','7704000004',10,'POSTED')");
FinanceAllocationService::createAllocation($pdo,7,['amount'=>'10','invoice_id'=>3],$userCtx);
check((string)$pdo->query("SELECT status FROM finance_invoices WHERE id=3")->fetchColumn()==='paid','production invoice-only allocation closes second invoice');
check(m($pdo->query("SELECT paid_amount FROM finance_obligations WHERE id=".(int)$b['id'])->fetchColumn())==='130.00','one obligation across multiple invoices keeps exact paid total');

// Cancelled invoice must not accept manual settlement and must not reserve obligation capacity.
$pdo->exec("INSERT INTO finance_invoices(direction,number,invoice_date,counterparty_entity_type,counterparty_entity_id,counterparty_name,counterparty_inn,amount,status,cancelled_at) VALUES('INCOMING','B-CANCELLED-30','2026-08-27','contractor',4,'Грузчики Сервис','7704000004',30,'cancelled',NOW())");
FinanceObligationService::replaceInvoiceLinks($pdo,4,[['obligation_id'=>(int)$b['id'],'amount'=>'30.00']],$userCtx);
$pdo->exec("INSERT INTO finance_operations(operation_date,operation_type,counterparty_entity_type,counterparty_entity_id,counterparty_name,counterparty_inn,amount,status) VALUES('2026-08-27','EXPENSE','contractor',4,'Грузчики Сервис','7704000004',30,'POSTED')");
expectFailure(fn()=>FinanceAllocationService::createAllocation($pdo,8,['amount'=>'30','invoice_id'=>4],$userCtx),'cancelled invoice rejects production allocation');
$openB = array_values(array_filter(FinanceUninvoicedObligationService::fetchOpen($pdo,false),fn(array $r):bool=>(int)$r['id']===(int)$b['id']));
check(count($openB)===1 && $openB[0]['uninvoiced_remaining_amount']==='70.00','cancelled invoice link does not hide real obligation remainder');

// Legacy LINEAR_ROUTE_PAYMENT remains compatible but also receives canonical obligation_id.
$pdo->exec("INSERT INTO finance_operations(operation_date,operation_type,counterparty_entity_type,counterparty_entity_id,counterparty_name,counterparty_inn,amount,status) VALUES('2026-08-27','EXPENSE','contractor',2,'Основной перевозчик','7702000002',10,'POSTED')");
$legacyAllocation=FinanceAllocationService::createAllocation($pdo,9,['amount'=>'10','linear_route_payment_id'=>1,'linear_route_id'=>10],$userCtx);
$legacy=$pdo->query("SELECT * FROM finance_operation_allocations WHERE id={$legacyAllocation}")->fetch();
check((int)$legacy['obligation_id']===$carrierId && (int)$legacy['linear_route_payment_id']===1,'legacy route payment stores both canonical obligation_id and compatibility payment id');
FinanceAllocationService::cancelAllocation($pdo,$legacyAllocation,$userCtx,'Вернуть baseline');

// Lock serialization: second production writer cannot pass an already locked operation row.
$pdo->exec("INSERT INTO finance_operations(operation_date,operation_type,counterparty_entity_type,counterparty_entity_id,counterparty_name,counterparty_inn,amount,status) VALUES('2026-08-27','EXPENSE','contractor',4,'Грузчики Сервис','7704000004',20,'POSTED')");
$pdo2=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo2->exec('SET SESSION innodb_lock_wait_timeout=1');
$pdo2->beginTransaction();
$pdo2->query('SELECT id FROM finance_operations WHERE id=10 FOR UPDATE')->fetchColumn();
expectFailure(fn()=>FinanceAllocationService::createAllocation($pdo,10,['amount'=>'20','obligation_id'=>(int)$b['id']],$userCtx),'operation FOR UPDATE serializes concurrent manual writers');
$pdo2->rollBack();
$lockAllocation=FinanceAllocationService::createAllocation($pdo,10,['amount'=>'20','obligation_id'=>(int)$b['id']],$userCtx);
check($lockAllocation>0,'allocation succeeds after competing lock is released');
FinanceAllocationService::cancelAllocation($pdo,$lockAllocation,$userCtx,'Завершить locking test');

// Audit rows for canonical manual allocations carry obligation_id in serialized new_values.
$auditJson=(string)$pdo->query("SELECT new_values FROM finance_audit_log WHERE entity_type='finance_allocation' AND action='allocation_create' ORDER BY id DESC LIMIT 1")->fetchColumn();
check(str_contains($auditJson,'obligation_id'),'audit trail records canonical obligation identity');

echo "Finance manual allocation obligations behavioral regression PASS\n";