<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/Security/EffectiveAccess.php';
require_once __DIR__ . '/../app/Security/AccessRuntime.php';
require_once __DIR__ . '/../app/Service/RegulatedDataScopeService.php';
require_once __DIR__ . '/../app/Service/FinanceReceivedPaidService.php';

use App\Service\FinanceReceivedPaidService;
use App\Security\EffectiveAccess;

$invoiceRoutes = file_get_contents(__DIR__ . '/../app/Http/Routes/company_finance_invoices.php');
if (!str_contains($invoiceRoutes, "require_once base_path('app/Service/FinanceReceivedPaidService.php')")) {
    throw new RuntimeException('The production route must load the received/paid service.');
}

$invoiceAccess = EffectiveAccess::member(1, 1, ['company.finance.invoices' => true], ['waste_trip' => 'all']);
if (!$invoiceAccess->canAccessPage('company.finance.received_paid')) throw new RuntimeException('Existing invoice permission must allow the new page.');
$noInvoiceAccess = EffectiveAccess::member(1, 1, [], ['waste_trip' => 'all']);
if ($noInvoiceAccess->canAccessPage('company.finance.received_paid')) throw new RuntimeException('The new page must not bypass invoice permission.');

$pdo = new PDO(
    'mysql:host=' . (getenv('PLANEX_SCHEMA_DB_HOST') ?: '127.0.0.1')
    . ';port=' . (getenv('PLANEX_SCHEMA_DB_PORT') ?: '3306')
    . ';dbname=' . (getenv('PLANEX_TEST_FIXED_DB') ?: 'erp_received_paid_test') . ';charset=utf8mb4',
    getenv('PLANEX_SCHEMA_DB_USER') ?: 'root',
    getenv('PLANEX_SCHEMA_DB_PASSWORD') ?: 'root',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
foreach (['finance_operation_allocations','finance_operations','bank_transactions','finance_invoice_links',
          'finance_invoices','finance_obligations','waste_trip_carrier_payments','waste_trip_customer_payments',
          'waste_trips','crews','contractors','clients'] as $table) {
    $pdo->exec("DROP TABLE IF EXISTS `$table`");
}
$pdo->exec('CREATE TABLE clients(id INT PRIMARY KEY,name VARCHAR(100))');
$pdo->exec('CREATE TABLE contractors(id INT PRIMARY KEY,name VARCHAR(100))');
$pdo->exec('CREATE TABLE crews(id INT PRIMARY KEY,contractor_id INT)');
$pdo->exec('CREATE TABLE waste_trips(id INT PRIMARY KEY,business_id INT,comment VARCHAR(100),actual_start_date DATE NULL,planned_start_date DATE NULL,planned_start_date_from DATE NULL,actual_end_date DATE NULL,planned_finish_date DATE NULL,client_id INT,route_executor_id INT,legal_entity_code VARCHAR(32),status VARCHAR(32),deleted_at DATETIME NULL,created_by_membership_id INT NULL,created_by_user_id INT NULL)');
$pdo->exec('CREATE TABLE waste_trip_customer_payments(waste_trip_id INT,amount DECIMAL(12,2))');
$pdo->exec('CREATE TABLE waste_trip_carrier_payments(waste_trip_id INT,amount DECIMAL(12,2))');
$pdo->exec('CREATE TABLE finance_obligations(id INT PRIMARY KEY,source_parent_type VARCHAR(30),source_parent_id INT,party_role VARCHAR(20),amount DECIMAL(12,2),status VARCHAR(30),due_date DATE NULL,forecast_due_date DATE NULL,cancelled_at DATETIME NULL)');
$pdo->exec('CREATE TABLE finance_invoices(id INT PRIMARY KEY,direction VARCHAR(20),number VARCHAR(30),invoice_date DATE,status VARCHAR(30),cancelled_at DATETIME NULL)');
$pdo->exec('CREATE TABLE finance_invoice_links(invoice_id INT,obligation_id INT,amount DECIMAL(12,2))');
$pdo->exec('CREATE TABLE finance_operations(id INT PRIMARY KEY,operation_date DATE,status VARCHAR(20),bank_transaction_id INT NULL)');
$pdo->exec('CREATE TABLE bank_transactions(id INT PRIMARY KEY,operation_date DATE)');
$pdo->exec('CREATE TABLE finance_operation_allocations(operation_id INT,invoice_id INT NULL,obligation_id INT,allocation_date DATE,amount DECIMAL(12,2),cancelled_at DATETIME NULL)');
$pdo->exec("INSERT INTO clients VALUES(1,'Клиент')");
$pdo->exec("INSERT INTO contractors VALUES(2,'Перевозчик')");
$pdo->exec('INSERT INTO crews VALUES(1,2)');
$pdo->exec("INSERT INTO waste_trips VALUES(1,310,'Рейс 310',NULL,'2026-09-28',NULL,NULL,NULL,1,1,'planex','completed',NULL,1,1),(2,309,'Рейс 309',NULL,'2026-09-28',NULL,NULL,NULL,1,1,'planex','completed',NULL,1,1),(3,308,'Чужой рейс',NULL,NULL,NULL,NULL,NULL,1,1,'mg_logistic','completed',NULL,1,1),(4,311,'Рейс в работе',NULL,'2026-09-30',NULL,NULL,NULL,1,1,'planex','in_progress',NULL,1,1)");
$pdo->exec('INSERT INTO waste_trip_customer_payments VALUES(1,280000),(2,280000),(3,280000)');
$pdo->exec('INSERT INTO waste_trip_carrier_payments VALUES(1,200000),(1,40000),(3,240000)');
$today = new DateTimeImmutable('today', new DateTimeZone('Europe/Moscow'));
$past = $today->modify('-1 day')->format('Y-m-d');
$dueToday = $today->format('Y-m-d');
$future = $today->modify('+3 days')->format('Y-m-d');
$pdo->exec("INSERT INTO finance_obligations VALUES(1,'WASTE_TRIP',1,'customer',280000,'planned','$past',NULL,NULL),(2,'WASTE_TRIP',2,'customer',280000,'planned','$dueToday',NULL,NULL),(3,'WASTE_TRIP',1,'carrier',999000,'planned',NULL,NULL,NULL),(4,'WASTE_TRIP',2,'carrier',240000,'planned',NULL,NULL,NULL)");
$pdo->exec("INSERT INTO finance_invoices VALUES(1,'OUTGOING','SHARED','2026-09-29','issued',NULL),(2,'INCOMING','CARRIER','2026-09-29','received',NULL)");
$pdo->exec('INSERT INTO finance_invoice_links VALUES(1,1,180000),(1,2,100000),(2,3,240000)');
$pdo->exec("INSERT INTO finance_operations VALUES(1,'2026-09-30','POSTED',NULL),(2,'2026-09-30','DRAFT',NULL)");
$pdo->exec("INSERT INTO finance_operation_allocations VALUES(1,1,1,'2026-09-30',60000,NULL),(1,1,2,'2026-09-30',40000,NULL),(2,2,3,'2026-09-30',50000,NULL)");
$_SESSION['role_code'] = 'company_owner';
$rows = FinanceReceivedPaidService::fetch($pdo);
if (count($rows) !== 2 || (int)$rows[0]['business_id'] !== 310) throw new RuntimeException('Only completed PLANEX trips must appear, newest first.');
if ((string)$rows[0]['sides']['carrier']['rate'] !== '240000.00') throw new RuntimeException('Carrier cost must sum the trip Себес rows, not financial obligations.');
if ($rows[1]['sides']['carrier']['rate'] !== null) throw new RuntimeException('Missing Себес rows must not inherit a preserved carrier obligation rate.');
if (count($rows[0]['sides']['customer']['invoices']) !== 1 || (string)$rows[0]['sides']['customer']['invoices'][0]['amount'] !== '180000.00') throw new RuntimeException('Shared invoice amount must be scoped to trip.');
if (count($rows[0]['sides']['customer']['payments']) !== 1 || (string)$rows[0]['sides']['customer']['payments'][0]['amount'] !== '60000.00') throw new RuntimeException('Only posted trip payment must appear.');
if ((string)$rows[1]['sides']['customer']['invoices'][0]['amount'] !== '100000.00' || (string)$rows[1]['sides']['customer']['payments'][0]['amount'] !== '40000.00') throw new RuntimeException('Second trip must have its own invoice and payment share.');
if ($rows[0]['sides']['carrier']['payments'] !== []) throw new RuntimeException('Unposted payment must not appear.');
if ($rows[0]['planned_receipt']['date'] !== $past || !$rows[0]['planned_receipt']['remaining']) throw new RuntimeException('Unpaid customer due date must appear.');
if ($rows[1]['planned_receipt']['date'] !== $dueToday || !$rows[1]['planned_receipt']['remaining']) throw new RuntimeException('Today customer due date must appear.');
$pdo->exec("UPDATE finance_obligations SET amount=60000 WHERE id=1");
$pdo->exec("UPDATE finance_invoice_links SET amount=60000 WHERE obligation_id=1");
$pdo->exec("INSERT INTO finance_obligations VALUES(5,'WASTE_TRIP',1,'customer',220000,'planned','$future',NULL,NULL)");
$rows = FinanceReceivedPaidService::fetch($pdo);
if ($rows[0]['planned_receipt']['date'] !== $future || !$rows[0]['planned_receipt']['remaining']) throw new RuntimeException('A fully paid earlier stage must yield the next unpaid date.');
echo "FINANCE_RECEIVED_PAID_BEHAVIORAL_OK\n";
