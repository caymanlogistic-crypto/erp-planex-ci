<?php

declare(strict_types=1);

require_once __DIR__.'/../app/Service/DateCalculationService.php';
require_once __DIR__.'/../app/Service/ProductionCalendarService.php';
require_once __DIR__.'/../app/Service/RoutePaymentStatusService.php';
require_once __DIR__.'/../app/Service/FinanceAuditLogService.php';
require_once __DIR__.'/../app/Service/FinanceInvoiceService.php';
require_once __DIR__.'/../app/Service/FinanceSettlementCascadeService.php';
require_once __DIR__.'/../app/Service/LinearTripServiceLineService.php';
require_once __DIR__.'/../app/Service/LinearTripServiceFinanceService.php';
require_once __DIR__.'/../app/Service/FinanceObligationService.php';

use App\Service\FinanceObligationService;
use App\Service\FinanceSettlementCascadeService;

function ok(bool $v,string $m):void{if(!$v)throw new RuntimeException('FAIL: '.$m);}
$pdo=new PDO('mysql:host='.(getenv('PLANEX_SCHEMA_DB_HOST')?:'127.0.0.1').';port='.(getenv('PLANEX_SCHEMA_DB_PORT')?:'3306').';dbname='.(getenv('PLANEX_TEST_FIXED_DB')?:'erp_obligations_test').';charset=utf8mb4',getenv('PLANEX_SCHEMA_DB_USER')?:'root',getenv('PLANEX_SCHEMA_DB_PASSWORD')?:'root',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach(['finance_operation_allocations','finance_invoice_links','finance_obligations','finance_operations','finance_invoices','finance_audit_log','production_calendar_days','production_calendar_years','linear_route_payments','linear_route_principals','linear_routes','clients','contractors'] as $t)$pdo->exec("DROP TABLE IF EXISTS `$t`");
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
$pdo->exec("CREATE TABLE clients(id INT UNSIGNED PRIMARY KEY,name VARCHAR(255),inn VARCHAR(20)) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE contractors(id INT UNSIGNED PRIMARY KEY,name VARCHAR(255),inn VARCHAR(20)) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE linear_routes(id INT UNSIGNED PRIMARY KEY,client_id INT UNSIGNED,carrier_contractor_id INT UNSIGNED,planned_loading_date DATE,planned_unloading_date DATE,actual_loading_date DATE NULL,actual_unloading_date DATE NULL,closing_documents_received_date DATE NULL,deleted_at DATETIME NULL) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE linear_route_principals(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,linear_route_id INT UNSIGNED,principal_type VARCHAR(20),principal_id INT UNSIGNED,sort_order INT,deleted_at DATETIME NULL) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE linear_route_payments(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,linear_route_id INT UNSIGNED,party_role VARCHAR(20),linear_route_principal_id INT UNSIGNED NULL,sort_order INT,amount DECIMAL(12,2),condition_type VARCHAR(50) NULL,payment_due_type VARCHAR(100) NULL,payment_due_days INT NULL,payment_due_days_kind VARCHAR(20) NULL,days_count INT NULL,days_kind VARCHAR(20) NULL,specific_due_date DATE NULL,side VARCHAR(10) NULL,paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0,payment_status VARCHAR(30) NOT NULL DEFAULT 'planned',forecast_due_date DATE NULL,calculated_due_date DATE NULL,paid_at DATE NULL,cancelled_at DATETIME NULL,status_updated_at DATETIME NULL,created_by_user_id INT NULL,created_by_role VARCHAR(20) NULL,updated_by_user_id INT NULL,updated_by_role VARCHAR(20) NULL,deleted_at DATETIME NULL) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE production_calendar_years(calendar_year SMALLINT UNSIGNED PRIMARY KEY,status VARCHAR(16) NOT NULL,source_title VARCHAR(255) NULL,source_url VARCHAR(500) NULL,note VARCHAR(1000) NULL,created_by_user_id INT NULL,created_by_role VARCHAR(50) NULL,updated_by_user_id INT NULL,updated_by_role VARCHAR(50) NULL,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE production_calendar_days(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,calendar_date DATE UNIQUE,calendar_year SMALLINT UNSIGNED,day_type VARCHAR(32),is_working_day TINYINT(1),name VARCHAR(255) NULL,note VARCHAR(1000) NULL,created_by_user_id INT NULL,created_by_role VARCHAR(50) NULL,updated_by_user_id INT NULL,updated_by_role VARCHAR(50) NULL,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE finance_invoices(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,direction VARCHAR(20),number VARCHAR(100),invoice_date DATE,counterparty_entity_type VARCHAR(20) NULL,counterparty_entity_id INT NULL,counterparty_name VARCHAR(500) NULL,counterparty_inn VARCHAR(20) NULL,amount DECIMAL(12,2),planned_payment_date DATE NULL,status VARCHAR(30),paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0,cancelled_at DATETIME NULL,first_paid_at DATE NULL,fully_paid_at DATE NULL,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE finance_invoice_links(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,invoice_id INT UNSIGNED,obligation_id INT UNSIGNED NULL,linear_route_id INT UNSIGNED NULL,linear_route_payment_id INT UNSIGNED NULL,amount DECIMAL(12,2),side VARCHAR(20) NULL,created_by_user_id INT NULL,created_by_role VARCHAR(50) NULL) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE finance_operations(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,operation_date DATE,operation_type VARCHAR(20),counterparty_inn VARCHAR(20) NULL,purpose TEXT NULL,amount DECIMAL(12,2),status VARCHAR(20)) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE finance_operation_allocations(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,operation_id INT UNSIGNED,invoice_id INT UNSIGNED NULL,obligation_id INT UNSIGNED NULL,linear_route_id INT UNSIGNED NULL,linear_route_payment_id INT UNSIGNED NULL,amount DECIMAL(12,2),allocation_date DATE,method VARCHAR(30),comment TEXT NULL,created_by_user_id INT NULL,created_by_role VARCHAR(50) NULL,cancelled_at DATETIME NULL,cancelled_by_user_id INT NULL,cancelled_by_role VARCHAR(50) NULL,cancel_reason TEXT NULL) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE finance_audit_log(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,entity_type VARCHAR(64),entity_id INT UNSIGNED,action VARCHAR(64),old_values TEXT NULL,new_values TEXT NULL,created_by_user_id INT NULL,created_by_role VARCHAR(64) NULL,created_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE finance_obligations(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,source_type VARCHAR(50),source_key VARCHAR(255) UNIQUE,source_id INT UNSIGNED NULL,source_parent_type VARCHAR(50),source_parent_id INT UNSIGNED,direction VARCHAR(20),party_role VARCHAR(20) NULL,counterparty_entity_type VARCHAR(20) NULL,counterparty_entity_id INT NULL,counterparty_name VARCHAR(500) NULL,counterparty_inn VARCHAR(20) NULL,amount DECIMAL(12,2),condition_type VARCHAR(50) NULL,days_count INT NULL,days_kind VARCHAR(20) NULL,specific_due_date DATE NULL,event_date DATE NULL,forecast_due_date DATE NULL,due_date DATE NULL,paid_amount DECIMAL(12,2) DEFAULT 0,status VARCHAR(30),cancelled_at DATETIME NULL,created_by_user_id INT NULL,created_by_role VARCHAR(20) NULL,updated_by_user_id INT NULL,updated_by_role VARCHAR(20) NULL,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB");

$pdo->exec("INSERT INTO production_calendar_years(calendar_year,status) VALUES(2026,'READY')");
$insDay=$pdo->prepare('INSERT INTO production_calendar_days(calendar_date,calendar_year,day_type,is_working_day) VALUES(?,2026,?,?)');
$d=new DateTimeImmutable('2026-01-01');$end=new DateTimeImmutable('2027-01-01');
while($d<$end){$s=$d->format('Y-m-d');$working=(int)$d->format('N')<=5?1:0;if($s==='2026-11-04')$working=0;$insDay->execute([$s,$working?'WORKDAY':'WEEKEND',$working]);$d=$d->modify('+1 day');}
$pdo->exec("INSERT INTO clients VALUES(1,'Клиент А','7701000001')");
$pdo->exec("INSERT INTO contractors VALUES(2,'Перевозчик Б','7702000002')");
$pdo->exec("INSERT INTO linear_routes VALUES(10,1,2,'2026-11-02','2026-11-02','2026-11-02','2026-11-02',NULL,NULL)");
$pdo->exec("INSERT INTO linear_route_payments(linear_route_id,party_role,sort_order,amount,condition_type,days_count,days_kind,side,created_by_role,updated_by_role) VALUES(10,'customer',1,600,'after_end',2,'working','income','company_owner','company_owner'),(10,'customer',2,400,'after_end',2,'working','income','company_owner','company_owner')");

$r=FinanceObligationService::syncAllLinearRoutes($pdo);ok($r['obligations']===2,'two obligations synced');
$obs=$pdo->query('SELECT * FROM finance_obligations ORDER BY id')->fetchAll();ok(count($obs)===2,'two durable rows');ok($obs[0]['due_date']==='2026-11-05','official holiday skipped in working-day due date');
$oldIds=array_column($obs,'id');$oldSource=array_column($obs,'source_id');
$pdo->exec("INSERT INTO finance_invoices(direction,number,invoice_date,counterparty_entity_type,counterparty_entity_id,counterparty_name,counterparty_inn,amount,status) VALUES('OUTGOING','INV-26-001','2026-11-02','client',1,'Клиент А','7701000001',1000,'issued')");
FinanceObligationService::replaceInvoiceLinks($pdo,1,[['obligation_id'=>(int)$obs[0]['id'],'amount'=>'600.00'],['obligation_id'=>(int)$obs[1]['id'],'amount'=>'400.00']],['user_id'=>1,'role_code'=>'company_owner']);
ok((int)$pdo->query('SELECT COUNT(*) FROM finance_invoice_links WHERE invoice_id=1')->fetchColumn()===2,'one invoice links two obligations');
$pdo->exec("INSERT INTO finance_operations(operation_date,operation_type,counterparty_inn,purpose,amount,status) VALUES('2026-11-06','INCOME','7701000001','Оплата по счету INV-26-001',1000,'POSTED')");
$a=FinanceObligationService::autoAllocateIncomingCustomerReceipts($pdo,['user_id'=>1,'role_code'=>'company_owner']);
ok($a['operations']===0&&(int)$pdo->query('SELECT COUNT(*) FROM finance_operation_allocations')->fetchColumn()===0,'incoming receipt stays unallocated even with matching invoice number and amount');
$insertManual=$pdo->prepare("INSERT INTO finance_operation_allocations(operation_id,invoice_id,obligation_id,amount,allocation_date,method,created_by_role) VALUES(1,1,?,?,'2026-11-06','manual','company_owner')");
foreach ([['id'=>(int)$obs[0]['id'],'amount'=>'600.00'],['id'=>(int)$obs[1]['id'],'amount'=>'400.00']] as $part) {
    $insertManual->execute([$part['id'],$part['amount']]);
    FinanceSettlementCascadeService::cascadeAfterAllocationCreate($pdo,(int)$pdo->lastInsertId());
}
ok((int)$pdo->query('SELECT COUNT(*) FROM finance_operation_allocations')->fetchColumn()===2,'manual receipt split over two obligations');
ok((string)$pdo->query('SELECT paid_amount FROM finance_invoices WHERE id=1')->fetchColumn()==='1000.00','invoice fully paid after manual allocation');
FinanceObligationService::syncAllLinearRoutes($pdo);ok((string)$pdo->query('SELECT SUM(paid_amount) FROM finance_obligations')->fetchColumn()==='1000.00','obligations fully settled');

$pdo->exec("UPDATE linear_route_payments SET deleted_at=NOW() WHERE linear_route_id=10");
$pdo->exec("INSERT INTO linear_route_payments(linear_route_id,party_role,sort_order,amount,condition_type,days_count,days_kind,side,created_by_role,updated_by_role) VALUES(10,'customer',1,600,'after_end',2,'working','income','company_owner','company_owner'),(10,'customer',2,400,'after_end',2,'working','income','company_owner','company_owner')");
FinanceObligationService::syncAllLinearRoutes($pdo);$new=$pdo->query("SELECT * FROM finance_obligations WHERE cancelled_at IS NULL ORDER BY id")->fetchAll();ok(array_column($new,'id')===$oldIds,'obligation ids survive route payment row replacement');ok(array_column($new,'source_id')!==$oldSource,'source row ids updated after route edit');ok((string)$pdo->query("SELECT SUM(paid_amount) FROM finance_obligations WHERE cancelled_at IS NULL")->fetchColumn()==='1000.00','settlement survives route payment id churn');
echo "FINANCE_OBLIGATIONS_BEHAVIOR_OK\n";
