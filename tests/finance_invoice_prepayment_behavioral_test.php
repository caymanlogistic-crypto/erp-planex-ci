<?php

declare(strict_types=1);

require_once __DIR__.'/../app/Service/DateCalculationService.php';
require_once __DIR__.'/../app/Service/ProductionCalendarService.php';
require_once __DIR__.'/../app/Service/RoutePaymentStatusService.php';
require_once __DIR__.'/../app/Service/FinanceAuditLogService.php';
require_once __DIR__.'/../app/Service/FinanceInvoiceService.php';
require_once __DIR__.'/../app/Service/LinearTripServiceLineService.php';
require_once __DIR__.'/../app/Service/LinearTripServiceFinanceService.php';
require_once __DIR__.'/../app/Service/FinanceSettlementCascadeService.php';
require_once __DIR__.'/../app/Service/FinanceObligationService.php';
require_once __DIR__.'/../app/Service/FinanceUninvoicedObligationService.php';
require_once __DIR__.'/../app/Service/FinanceInvoicePrepaymentService.php';
require_once __DIR__.'/../app/Service/FinanceAllocationService.php';

use App\Service\FinanceAllocationService;
use App\Service\FinanceInvoicePrepaymentService;
use App\Service\FinanceObligationService;
use App\Service\FinanceSettlementCascadeService;
use App\Service\FinanceUninvoicedObligationService;
use App\Service\LinearTripServiceFinanceService;

function ok(bool $value, string $message): void
{
    if (!$value) {
        throw new RuntimeException('FAIL: ' . $message);
    }
}

function money(string $value): string
{
    $value = trim(str_replace(',', '.', $value));
    [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
    return ((int)$whole) . '.' . str_pad(substr($fraction, 0, 2), 2, '0');
}

function cents(string $value): int
{
    $value = money($value);
    [$whole, $fraction] = explode('.', $value, 2);
    return ((int)$whole * 100) + (int)$fraction;
}

$dsn = getenv('PLANEX_FINANCE_TEST_DSN') ?: 'mysql:host=127.0.0.1;port=3306;dbname=erp_obligations_test;charset=utf8mb4';
$user = getenv('PLANEX_FINANCE_TEST_USER') ?: 'root';
$pass = getenv('PLANEX_FINANCE_TEST_PASSWORD') ?: 'root';
$pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$tables = [
    'finance_operation_allocations', 'finance_invoice_links', 'finance_obligations',
    'finance_operations', 'finance_invoices', 'finance_audit_log',
    'linear_route_service_payments', 'linear_route_services',
    'linear_route_payments', 'linear_route_principals', 'linear_routes',
    'clients', 'contractors',
];
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach ($tables as $table) {
    $pdo->exec("DROP TABLE IF EXISTS `$table`");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');

$pdo->exec("CREATE TABLE clients(id INT UNSIGNED PRIMARY KEY,name VARCHAR(255),inn VARCHAR(20)) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE contractors(id INT UNSIGNED PRIMARY KEY,name VARCHAR(255),inn VARCHAR(20)) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE linear_routes(id INT UNSIGNED PRIMARY KEY,client_id INT UNSIGNED,carrier_contractor_id INT UNSIGNED,planned_loading_date DATE,planned_unloading_date DATE,actual_loading_date DATE NULL,actual_unloading_date DATE NULL,closing_documents_received_date DATE NULL,deleted_at DATETIME NULL) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE linear_route_principals(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,linear_route_id INT UNSIGNED,principal_type VARCHAR(20),principal_id INT UNSIGNED,sort_order INT,deleted_at DATETIME NULL) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE linear_route_payments(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,linear_route_id INT UNSIGNED,party_role VARCHAR(20),linear_route_principal_id INT UNSIGNED NULL,sort_order INT,amount DECIMAL(12,2),condition_type VARCHAR(50) NULL,payment_due_type VARCHAR(100) NULL,payment_due_days INT NULL,payment_due_days_kind VARCHAR(20) NULL,days_count INT NULL,days_kind VARCHAR(20) NULL,specific_due_date DATE NULL,side VARCHAR(10) NULL,paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0,payment_status VARCHAR(30) NOT NULL DEFAULT 'planned',forecast_due_date DATE NULL,calculated_due_date DATE NULL,paid_at DATE NULL,cancelled_at DATETIME NULL,status_updated_at DATETIME NULL,created_by_user_id INT NULL,created_by_role VARCHAR(20) NULL,updated_by_user_id INT NULL,updated_by_role VARCHAR(20) NULL,deleted_at DATETIME NULL) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE linear_route_services(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,linear_route_id INT UNSIGNED NOT NULL,contractor_id INT UNSIGNED NOT NULL,service_name VARCHAR(190) NOT NULL,description TEXT NULL,sort_order INT UNSIGNED NOT NULL DEFAULT 0,created_by_user_id INT UNSIGNED NULL,created_by_role VARCHAR(40) NULL,updated_by_user_id INT UNSIGNED NULL,updated_by_role VARCHAR(40) NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,deleted_at DATETIME NULL,deleted_by_user_id INT UNSIGNED NULL,deleted_by_role VARCHAR(40) NULL) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE linear_route_service_payments(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,linear_route_service_id INT UNSIGNED NOT NULL,amount DECIMAL(15,2) NOT NULL,payment_type VARCHAR(100) NULL,payment_method VARCHAR(20) NOT NULL DEFAULT 'cashless',vat_rate VARCHAR(10) NULL,condition_type VARCHAR(100) NOT NULL,days_count INT UNSIGNED NULL,days_kind VARCHAR(20) NULL,specific_due_date DATE NULL,condition_comment VARCHAR(500) NULL,calculated_due_date DATE NULL,paid_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,status VARCHAR(40) NOT NULL DEFAULT 'planned',sort_order INT UNSIGNED NOT NULL DEFAULT 0,created_by_user_id INT UNSIGNED NULL,created_by_role VARCHAR(40) NULL,updated_by_user_id INT UNSIGNED NULL,updated_by_role VARCHAR(40) NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,deleted_at DATETIME NULL,deleted_by_user_id INT UNSIGNED NULL,deleted_by_role VARCHAR(40) NULL) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE finance_invoices(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,direction VARCHAR(20),number VARCHAR(100),invoice_date DATE,counterparty_entity_type VARCHAR(20) NULL,counterparty_entity_id INT NULL,counterparty_name VARCHAR(500) NULL,counterparty_inn VARCHAR(20) NULL,amount DECIMAL(12,2),planned_payment_date DATE NULL,status VARCHAR(30),paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0,cancelled_at DATETIME NULL,first_paid_at DATE NULL,fully_paid_at DATE NULL,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE finance_invoice_links(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,invoice_id INT UNSIGNED,obligation_id INT UNSIGNED NULL,linear_route_id INT UNSIGNED NULL,linear_route_payment_id INT UNSIGNED NULL,amount DECIMAL(12,2),side VARCHAR(20) NULL,created_by_user_id INT NULL,created_by_role VARCHAR(50) NULL) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE finance_operations(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,operation_date DATE,operation_type VARCHAR(20),counterparty_inn VARCHAR(20) NULL,purpose TEXT NULL,amount DECIMAL(12,2),status VARCHAR(20)) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE finance_operation_allocations(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,operation_id INT UNSIGNED,invoice_id INT UNSIGNED NULL,obligation_id INT UNSIGNED NULL,linear_route_id INT UNSIGNED NULL,linear_route_payment_id INT UNSIGNED NULL,amount DECIMAL(12,2),allocation_date DATE,method VARCHAR(30),comment TEXT NULL,created_by_user_id INT NULL,created_by_role VARCHAR(50) NULL,cancelled_at DATETIME NULL,cancelled_by_user_id INT NULL,cancelled_by_role VARCHAR(50) NULL,cancel_reason TEXT NULL,created_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE finance_audit_log(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,entity_type VARCHAR(64),entity_id INT UNSIGNED,action VARCHAR(64),old_values TEXT NULL,new_values TEXT NULL,created_by_user_id INT NULL,created_by_role VARCHAR(64) NULL,created_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE finance_obligations(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,source_type VARCHAR(50),source_key VARCHAR(255) UNIQUE,source_id INT UNSIGNED NULL,source_parent_type VARCHAR(50),source_parent_id INT UNSIGNED,direction VARCHAR(20),party_role VARCHAR(20) NULL,counterparty_entity_type VARCHAR(20) NULL,counterparty_entity_id INT NULL,counterparty_name VARCHAR(500) NULL,counterparty_inn VARCHAR(20) NULL,amount DECIMAL(12,2),condition_type VARCHAR(50) NULL,days_count INT NULL,days_kind VARCHAR(20) NULL,specific_due_date DATE NULL,event_date DATE NULL,forecast_due_date DATE NULL,due_date DATE NULL,paid_amount DECIMAL(12,2) DEFAULT 0,status VARCHAR(30),cancelled_at DATETIME NULL,created_by_user_id INT NULL,created_by_role VARCHAR(20) NULL,updated_by_user_id INT NULL,updated_by_role VARCHAR(20) NULL,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB");

$pdo->exec("INSERT INTO clients VALUES(1,'Заказчик','7701000001')");
$pdo->exec("INSERT INTO contractors VALUES(2,'Основной перевозчик','7702000002'),(3,'Кран Сервис','7703000003'),(4,'Грузчики Сервис','7704000004')");
$pdo->exec("INSERT INTO linear_routes VALUES(10,1,2,'2026-08-25','2026-08-26','2026-08-25','2026-08-26',NULL,NULL)");
$pdo->exec("INSERT INTO linear_route_payments(linear_route_id,party_role,sort_order,amount,condition_type,specific_due_date,side,created_by_role,updated_by_role) VALUES(10,'carrier',1,1000,'prepayment','2026-09-03','expense','company_owner','company_owner')");
$pdo->exec("INSERT INTO linear_route_services(linear_route_id,contractor_id,service_name,sort_order) VALUES(10,3,'Кран',1),(10,4,'Грузчики',2)");
$pdo->exec("INSERT INTO linear_route_service_payments(linear_route_service_id,amount,condition_type,specific_due_date,calculated_due_date,sort_order) VALUES(1,100,'specific_date','2026-09-01','2026-09-01',1),(2,200,'specific_date','2026-09-02','2026-09-02',1)");

FinanceObligationService::syncAllLinearRoutes($pdo);
$carrierObligationId = (int)$pdo->query("SELECT id FROM finance_obligations WHERE source_type='LINEAR_ROUTE_PAYMENT' AND party_role='carrier'")->fetchColumn();
$serviceA = $pdo->query("SELECT * FROM finance_obligations WHERE source_type='LINEAR_ROUTE_SERVICE_PAYMENT' AND source_id=1")->fetch();
$serviceB = $pdo->query("SELECT * FROM finance_obligations WHERE source_type='LINEAR_ROUTE_SERVICE_PAYMENT' AND source_id=2")->fetch();
ok($carrierObligationId > 0, 'base carrier obligation exists');
ok((int)$serviceA['counterparty_entity_id'] === 3 && (int)$serviceB['counterparty_entity_id'] === 4, 'two service obligations keep distinct contractors');
ok((string)$serviceA['due_date'] === '2026-09-01' && (string)$serviceB['due_date'] === '2026-09-02', 'service obligations keep distinct due dates');

$pdo->exec("INSERT INTO finance_operations(operation_date,operation_type,counterparty_inn,purpose,amount,status) VALUES('2026-08-27','EXPENSE','7703000003','Предоплата за кран',80,'POSTED'),('2026-08-27','EXPENSE','7704000004','Предоплата за грузчиков',200,'POSTED')");
$insertDirect = $pdo->prepare("INSERT INTO finance_operation_allocations(operation_id,obligation_id,linear_route_id,amount,allocation_date,method,comment,created_by_user_id,created_by_role) VALUES(?,?,10,?,'2026-08-27','manual',?,1,'company_owner')");
$insertDirect->execute([1,(int)$serviceA['id'],'80.00','Прямая предоплата услуги А']);
$insertDirect->execute([2,(int)$serviceB['id'],'200.00','Прямая предоплата услуги Б']);
LinearTripServiceFinanceService::syncRoute($pdo, 10);
ok(money((string)$pdo->query("SELECT paid_amount FROM linear_route_service_payments WHERE id=1")->fetchColumn()) === '80.00', 'service A direct prepayment reflected');
ok((string)$pdo->query("SELECT status FROM linear_route_service_payments WHERE id=2")->fetchColumn() === 'paid', 'service B is fully prepaid');

$pdo->exec("INSERT INTO finance_invoices(direction,number,invoice_date,counterparty_entity_type,counterparty_entity_id,counterparty_name,counterparty_inn,amount,status) VALUES('INCOMING','SVC-A-50','2026-08-27','contractor',3,'Кран Сервис','7703000003',50,'received'),('INCOMING','SVC-B-200','2026-08-27','contractor',4,'Грузчики Сервис','7704000004',200,'received')");
$userCtx = ['user_id'=>1,'role_code'=>'company_owner'];
FinanceObligationService::replaceInvoiceLinks($pdo, 1, [['obligation_id'=>(int)$serviceA['id'],'amount'=>'50.00']], $userCtx);
$resultA = FinanceInvoicePrepaymentService::reconcileInvoice($pdo, 1, $userCtx);
ok($resultA['amount'] === '50.00', '50.00 of existing prepayment inherited by late invoice A');
ok(money((string)$pdo->query("SELECT paid_amount FROM finance_invoices WHERE id=1")->fetchColumn()) === '50.00', 'late invoice A immediately reflects inherited payment');
ok((string)$pdo->query("SELECT status FROM finance_invoices WHERE id=1")->fetchColumn() === 'paid', 'late invoice A is not recreated as unpaid debt');
ok(money((string)$pdo->query("SELECT SUM(amount) FROM finance_operation_allocations WHERE operation_id=1 AND cancelled_at IS NULL")->fetchColumn()) === '80.00', 'split preserves total operation allocation');
ok(money((string)$pdo->query("SELECT SUM(amount) FROM finance_operation_allocations WHERE obligation_id=".(int)$serviceA['id']." AND invoice_id=1 AND cancelled_at IS NULL")->fetchColumn()) === '50.00', 'invoice A receives inherited 50.00');
ok(money((string)$pdo->query("SELECT SUM(amount) FROM finance_operation_allocations WHERE obligation_id=".(int)$serviceA['id']." AND invoice_id IS NULL AND cancelled_at IS NULL")->fetchColumn()) === '30.00', 'direct residual after split is 30.00');

$openA = array_values(array_filter(FinanceUninvoicedObligationService::fetchOpen($pdo, false), static fn(array $row): bool => (int)$row['id'] === (int)$serviceA['id']));
ok(count($openA) === 1, 'service A retains one uninvoiced residual');
ok((string)$openA[0]['uninvoiced_remaining_amount'] === '20.00', 'partial prepayment + partial invoice leaves exact economic residual 20.00');
$invoiceARemaining = FinanceSettlementCascadeService::getInvoiceRemainingAmount($pdo, 1, '50.00');
ok($invoiceARemaining === '0.00', 'invoice A remaining is zero after inherited prepayment');
ok(cents($invoiceARemaining) + cents((string)$openA[0]['uninvoiced_remaining_amount']) === 2000, 'aggregate remaining is 20.00 without double-counting');

FinanceObligationService::replaceInvoiceLinks($pdo, 2, [['obligation_id'=>(int)$serviceB['id'],'amount'=>'200.00']], $userCtx);
$resultB = FinanceInvoicePrepaymentService::reconcileInvoice($pdo, 2, $userCtx);
ok($resultB['amount'] === '200.00', 'full service prepayment inherited by late invoice B');
ok((string)$pdo->query("SELECT status FROM finance_invoices WHERE id=2")->fetchColumn() === 'paid', 'fully prepaid late invoice B is paid');
$openIds = array_map(static fn(array $row): int => (int)$row['id'], FinanceUninvoicedObligationService::fetchOpen($pdo, false));
ok(!in_array((int)$serviceB['id'], $openIds, true), 'fully prepaid and fully invoiced service B has no residual');
ok(money((string)$pdo->query("SELECT SUM(amount) FROM finance_operation_allocations WHERE operation_id=2 AND cancelled_at IS NULL")->fetchColumn()) === '200.00', 'full inheritance preserves operation B total');
ok((int)$pdo->query("SELECT COUNT(*) FROM finance_operation_allocations WHERE obligation_id=$carrierObligationId")->fetchColumn() === 0, 'base carrier obligation remains untouched');

$inheritAId = (int)$pdo->query("SELECT id FROM finance_operation_allocations WHERE invoice_id=1 AND obligation_id=".(int)$serviceA['id']." AND method='prepayment_inherit' AND cancelled_at IS NULL LIMIT 1")->fetchColumn();
ok($inheritAId > 0, 'inherited allocation A is identifiable');
FinanceAllocationService::cancelAllocation($pdo, $inheritAId, $userCtx, 'Тест отмены унаследованной предоплаты');
ok(money((string)$pdo->query("SELECT paid_amount FROM finance_invoices WHERE id=1")->fetchColumn()) === '0.00', 'cancelling inherited allocation reopens invoice A');
ok(money((string)$pdo->query("SELECT paid_amount FROM linear_route_service_payments WHERE id=1")->fetchColumn()) === '30.00', 'cancellation cascades back to service payment paid amount');
$openAAfterCancel = array_values(array_filter(FinanceUninvoicedObligationService::fetchOpen($pdo, false), static fn(array $row): bool => (int)$row['id'] === (int)$serviceA['id']));
ok(count($openAAfterCancel) === 1 && (string)$openAAfterCancel[0]['uninvoiced_remaining_amount'] === '20.00', 'uninvoiced residual remains 20.00 after cancel');
$invoiceARemainingAfterCancel = FinanceSettlementCascadeService::getInvoiceRemainingAmount($pdo, 1, '50.00');
ok($invoiceARemainingAfterCancel === '50.00', 'invoice A remaining returns to 50.00 after cancel');
ok(cents($invoiceARemainingAfterCancel) + cents((string)$openAAfterCancel[0]['uninvoiced_remaining_amount']) === 7000, 'aggregate remaining becomes 70.00 = 100.00 - active 30.00 settlement');

$auditCount = (int)$pdo->query("SELECT COUNT(*) FROM finance_audit_log WHERE action IN ('prepayment_inherit_invoice','prepayment_split_direct_remainder')")->fetchColumn();
ok($auditCount >= 3, 'prepayment inheritance/split is audit logged');

echo "FINANCE_INVOICE_PREPAYMENT_BEHAVIOR_OK\n";
