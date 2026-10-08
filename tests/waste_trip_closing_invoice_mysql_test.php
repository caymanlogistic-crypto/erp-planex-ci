<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/bootstrap/app.php';
require_once BASE_PATH.'/app/Support/company_database.php';
require_once BASE_PATH.'/app/Service/FinanceInvoicePaymentTimelineService.php';
require_once BASE_PATH.'/app/Service/WasteTripClosingInvoiceService.php';

use App\Service\WasteTripClosingInvoiceService as Invoices;

$database=(string)getenv('PLANEX_TEST_FIXED_DB');
if($database===''||!in_array($database,explode(',',(string)getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST')),true)) throw new RuntimeException('Guarded test database required');
$pdo=new PDO('mysql:host='.getenv('PLANEX_SCHEMA_DB_HOST').';port='.(getenv('PLANEX_SCHEMA_DB_PORT')?:3306).';dbname='.$database.';charset=utf8mb4',
    getenv('PLANEX_SCHEMA_DB_USER'),getenv('PLANEX_SCHEMA_DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
cleanCompanyPoolDatabase($pdo);
try {
    $pdo->exec("CREATE TABLE finance_obligations(id INT PRIMARY KEY,source_parent_type VARCHAR(30),source_parent_id INT,party_role VARCHAR(20),cancelled_at DATETIME NULL,status VARCHAR(30),due_date DATE NULL,forecast_due_date DATE NULL) ENGINE=InnoDB;
        CREATE TABLE finance_invoices(id INT PRIMARY KEY,amount DECIMAL(12,2),status VARCHAR(30),direction VARCHAR(20),counterparty_entity_type VARCHAR(20),counterparty_entity_id INT,paid_amount DECIMAL(12,2),number VARCHAR(100),invoice_date DATE) ENGINE=InnoDB;
        CREATE TABLE finance_invoice_links(id INT PRIMARY KEY,invoice_id INT,obligation_id INT,amount DECIMAL(12,2),linear_route_id INT NULL) ENGINE=InnoDB;
        CREATE TABLE finance_operation_allocations(id INT PRIMARY KEY,invoice_id INT,obligation_id INT,amount DECIMAL(12,2),allocation_date DATE,method VARCHAR(30),operation_id INT,cancelled_at DATETIME NULL) ENGINE=InnoDB;
        CREATE TABLE finance_operations(id INT PRIMARY KEY,status VARCHAR(20),operation_date DATE,bank_transaction_id INT NULL) ENGINE=InnoDB;
        CREATE TABLE bank_transactions(id INT PRIMARY KEY,operation_date DATE) ENGINE=InnoDB;
        INSERT INTO finance_obligations VALUES(1,'WASTE_TRIP',1,'carrier',NULL,'planned',NULL,NULL),
            (2,'WASTE_TRIP',2,'carrier',NULL,'planned',DATE_SUB(CURDATE(),INTERVAL 7 DAY),NULL),
            (3,'WASTE_TRIP',3,'carrier',NULL,'paid',DATE_SUB(CURDATE(),INTERVAL 8 DAY),NULL);
        INSERT INTO finance_invoices VALUES(10,100.00,'received','INCOMING','contractor',42,0,'10',CURDATE()),
            (11,100.00,'paid','INCOMING','contractor',42,100.00,'11',CURDATE());
        INSERT INTO finance_invoice_links VALUES(21,10,2,100.00,NULL),(22,11,3,100.00,NULL);
        INSERT INTO finance_operations VALUES(30,'POSTED',CURDATE(),NULL);
        INSERT INTO finance_operation_allocations VALUES(40,11,3,100.00,CURDATE(),'bank',30,NULL);");
    $states=Invoices::summaries($pdo,[1,2,3]);
    if($states[1]['status']!=='missing'||$states[1]['detail']!=='—') throw new RuntimeException('Missing invoice state');
    if($states[2]['status']!=='waiting'||$states[2]['detail']!=='Просрочен 7 дней') throw new RuntimeException('Actual trip due date');
    if($states[3]['status']!=='paid'||!str_starts_with($states[3]['detail'],'Оплата: ')) throw new RuntimeException('Posted payment date');
    echo "WASTE_TRIP_CLOSING_INVOICE=PASS\n";
} finally { cleanCompanyPoolDatabase($pdo); }
