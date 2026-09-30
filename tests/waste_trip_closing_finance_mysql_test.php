<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/bootstrap/app.php';
require_once BASE_PATH.'/app/Support/company_database.php';
require_once BASE_PATH.'/app/Service/WasteTripClosingService.php';

use App\Service\WasteTripClosingService as Closing;

$database=(string)getenv('PLANEX_TEST_FIXED_DB');
if ($database==='' || !in_array($database,explode(',',(string)getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST')),true)) {
    throw new RuntimeException('Guarded test database required');
}
$pdo=new PDO('mysql:host='.getenv('PLANEX_SCHEMA_DB_HOST').';port='.(getenv('PLANEX_SCHEMA_DB_PORT')?:3306).';dbname='.$database.';charset=utf8mb4',
    getenv('PLANEX_SCHEMA_DB_USER'),getenv('PLANEX_SCHEMA_DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$assert=static function(bool $condition,string $message):void { if (!$condition) throw new RuntimeException($message); };
cleanCompanyPoolDatabase($pdo);
try {
    $pdo->exec("CREATE TABLE waste_trips(id BIGINT UNSIGNED PRIMARY KEY,status VARCHAR(20),deleted_at DATETIME NULL) ENGINE=InnoDB;
        CREATE TABLE documents(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,entity_type VARCHAR(20),entity_id INT UNSIGNED,original_name VARCHAR(255),relative_path VARCHAR(1000),mime_type VARCHAR(100),file_size INT,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,deleted_at DATETIME NULL) ENGINE=InnoDB;
        CREATE TABLE bank_transactions(id INT UNSIGNED PRIMARY KEY,operation_date DATE,document_number VARCHAR(100),credit_amount DECIMAL(12,2),is_internal_transfer TINYINT NOT NULL DEFAULT 0) ENGINE=InnoDB;
        CREATE TABLE finance_employee_movements(id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,bank_transaction_id INT UNSIGNED) ENGINE=InnoDB;
        CREATE TABLE finance_operations(id INT UNSIGNED PRIMARY KEY,bank_transaction_id INT UNSIGNED,status VARCHAR(20)) ENGINE=InnoDB;
        CREATE TABLE finance_obligations(id INT UNSIGNED PRIMARY KEY,source_parent_type VARCHAR(50),source_parent_id BIGINT UNSIGNED,party_role VARCHAR(20),amount DECIMAL(12,2),paid_amount DECIMAL(12,2),cancelled_at DATETIME NULL,status VARCHAR(30)) ENGINE=InnoDB;
        CREATE TABLE finance_operation_allocations(id INT UNSIGNED PRIMARY KEY,obligation_id INT UNSIGNED,operation_id INT UNSIGNED,amount DECIMAL(12,2),cancelled_at DATETIME NULL) ENGINE=InnoDB;
        CREATE TABLE finance_closing_document_links(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,bank_transaction_id INT UNSIGNED,document_id INT UNSIGNED,requirement_code VARCHAR(32),is_edo TINYINT NOT NULL DEFAULT 0,UNIQUE KEY uq_payment_document_code(bank_transaction_id,document_id,requirement_code)) ENGINE=InnoDB;
        INSERT INTO waste_trips VALUES(1,'completed',NULL);
        INSERT INTO bank_transactions VALUES(7,CURDATE(),'PAY-7',100.00,0),(8,CURDATE(),'PAY-8',100.00,0);
        INSERT INTO finance_operations VALUES(11,7,'POSTED'),(12,8,'POSTED');
        INSERT INTO finance_obligations VALUES(21,'WASTE_TRIP',1,'customer',100.00,50.00,NULL,'partially_paid'),(22,'WASTE_TRIP',1,'carrier',100.00,100.00,NULL,'paid');
        INSERT INTO finance_operation_allocations VALUES(31,21,11,50.00,NULL),(32,22,12,100.00,NULL);");
    $pdo->exec((string)file_get_contents(BASE_PATH.'/database/migrations-local/152_waste_trip_closing_documents.sql'));
    $assert(Closing::ready($pdo),'migration readiness');
    $assert(Closing::recipient('planex')==='info@plan-ex.ru' && Closing::recipient('mg_logistic')==='shibanov@mg-log.ru','executor routing');
    foreach (['contract'=>'application','invoice'=>'invoice','act'=>'upd','waybill'=>'waybill'] as $slot=>$code) {
        $pdo->prepare("INSERT INTO documents(entity_type,entity_id,original_name,relative_path,mime_type,file_size) VALUES('waste_trip',1,?,?,'application/pdf',100)")
            ->execute([$slot.'.pdf','companies/1/documents/waste_trip/1/'.$slot.'.pdf']);
        $pdo->prepare('INSERT INTO waste_trip_closing_documents(waste_trip_id,document_id,slot_code,requirement_code) VALUES(1,?,?,?)')
            ->execute([(int)$pdo->lastInsertId(),$slot,$code]);
    }
    Closing::syncFinance($pdo,1);
    $assert((int)$pdo->query('SELECT COUNT(*) FROM finance_closing_document_links')->fetchColumn()===0,'partial customer payment must not transfer');
    $pdo->exec('UPDATE finance_obligations SET paid_amount=100.00,status=\'paid\' WHERE id=21');
    $pdo->exec('UPDATE finance_operation_allocations SET amount=100.00 WHERE id=31');
    Closing::syncFinance($pdo,1);Closing::syncFinance($pdo,1);
    $assert((int)$pdo->query('SELECT COUNT(*) FROM finance_closing_document_links WHERE bank_transaction_id=7')->fetchColumn()===4,'fully paid trip transfers each file exactly once');
    $assert((int)$pdo->query('SELECT COUNT(*) FROM finance_closing_document_links WHERE bank_transaction_id=8')->fetchColumn()===0,'carrier payout must be excluded');
    $assert((int)$pdo->query('SELECT COUNT(*) FROM waste_trip_closing_finance_links')->fetchColumn()===4,'four provenance links');
    $pdo->exec('UPDATE finance_obligations SET paid_amount=0,status=\'planned\' WHERE id=21');
    $pdo->exec('UPDATE finance_operation_allocations SET cancelled_at=NOW() WHERE id=31');
    Closing::syncFinance($pdo,1);
    $assert((int)$pdo->query('SELECT COUNT(*) FROM finance_closing_document_links')->fetchColumn()===0,'cancelled allocation removes generated links');
    echo "WASTE_TRIP_CLOSING_FINANCE=PASS\n";
} finally {
    cleanCompanyPoolDatabase($pdo);
}
