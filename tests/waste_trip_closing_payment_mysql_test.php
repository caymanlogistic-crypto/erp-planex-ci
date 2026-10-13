<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/bootstrap/app.php';
require_once BASE_PATH.'/app/Support/company_database.php';
require_once BASE_PATH.'/app/Security/EffectiveAccess.php';
require_once BASE_PATH.'/app/Security/AccessRuntime.php';
require_once BASE_PATH.'/app/Service/RegulatedDataScopeService.php';
require_once BASE_PATH.'/app/Service/WasteTripClosingTransportService.php';
require_once BASE_PATH.'/app/Service/WasteTripClosingService.php';

use App\Service\WasteTripClosingService as Closing;

$database=(string)getenv('PLANEX_TEST_FIXED_DB');
if ($database==='' || !in_array($database,explode(',',(string)getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST')),true)) {
    throw new RuntimeException('Guarded test database required');
}
$pdo=new PDO('mysql:host='.getenv('PLANEX_SCHEMA_DB_HOST').';port='.(getenv('PLANEX_SCHEMA_DB_PORT')?:3306).';dbname='.$database.';charset=utf8mb4',
    getenv('PLANEX_SCHEMA_DB_USER'),getenv('PLANEX_SCHEMA_DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
cleanCompanyPoolDatabase($pdo);
try {
    $_SESSION['role_code']='company_owner';$_SESSION['user_id']=1;
    $pdo->exec("CREATE TABLE waste_trips(id BIGINT UNSIGNED PRIMARY KEY,business_id INT,legal_entity_code VARCHAR(30),actual_end_date DATE,created_by_membership_id INT,route_executor_id INT,status VARCHAR(20),deleted_at DATETIME NULL) ENGINE=InnoDB;
        CREATE TABLE waste_trip_closing_reviews(waste_trip_id BIGINT UNSIGNED PRIMARY KEY,state ENUM('work','sending','review','failed','unknown','payment') NOT NULL DEFAULT 'work',version INT UNSIGNED NOT NULL DEFAULT 0) ENGINE=InnoDB;
        CREATE TABLE crews(id INT PRIMARY KEY,driver_vehicle_block_id INT,deleted_at DATETIME NULL) ENGINE=InnoDB;
        CREATE TABLE driver_vehicle_blocks(id INT PRIMARY KEY,vehicle_set_id INT,deleted_at DATETIME NULL) ENGINE=InnoDB;
        CREATE TABLE vehicle_sets(id INT PRIMARY KEY,set_type VARCHAR(20),primary_vehicle_unit_id INT,secondary_vehicle_unit_id INT NULL,deleted_at DATETIME NULL) ENGINE=InnoDB;
        CREATE TABLE vehicle_units(id INT PRIMARY KEY,plate_number VARCHAR(20),brand VARCHAR(100),deleted_at DATETIME NULL) ENGINE=InnoDB;
        CREATE TABLE document_types(id INT PRIMARY KEY,code VARCHAR(30)) ENGINE=InnoDB;
        CREATE TABLE documents(id INT PRIMARY KEY,entity_type VARCHAR(30),entity_id INT,document_type_id INT,original_name VARCHAR(255),relative_path VARCHAR(255),mime_type VARCHAR(100),file_size INT,created_at DATETIME,deleted_at DATETIME NULL) ENGINE=InnoDB;
        CREATE TABLE waste_trip_closing_documents(id INT PRIMARY KEY,waste_trip_id BIGINT UNSIGNED,document_id INT,slot_code VARCHAR(24),requirement_code VARCHAR(32),custom_title VARCHAR(255),deleted_at DATETIME NULL) ENGINE=InnoDB;
        CREATE TABLE feo_requests(id INT PRIMARY KEY) ENGINE=InnoDB;
        CREATE TABLE waste_trip_requests(id INT PRIMARY KEY,waste_trip_id INT,feo_request_id INT,removed_at DATETIME NULL) ENGINE=InnoDB;
        CREATE TABLE waste_trip_completion_items(waste_trip_request_id INT PRIMARY KEY,tn_absent TINYINT,arrived_at DATETIME NULL,departed_at DATETIME NULL,driver_signature_present TINYINT,sender_signature_present TINYINT,tn_datetime_status VARCHAR(20),recovery_source VARCHAR(20)) ENGINE=InnoDB;
        CREATE TABLE waste_trip_completion_documents(id INT PRIMARY KEY,waste_trip_request_id INT,deleted_at DATETIME NULL) ENGINE=InnoDB;
        CREATE TABLE finance_obligations(id INT PRIMARY KEY,source_parent_type VARCHAR(30),source_parent_id INT,party_role VARCHAR(20),cancelled_at DATETIME NULL) ENGINE=InnoDB;
        CREATE TABLE finance_operations(id INT PRIMARY KEY,status VARCHAR(20)) ENGINE=InnoDB;
        CREATE TABLE finance_invoices(id INT PRIMARY KEY,status VARCHAR(20),cancelled_at DATETIME NULL) ENGINE=InnoDB;
        CREATE TABLE finance_operation_allocations(id INT PRIMARY KEY,obligation_id INT,operation_id INT,invoice_id INT NULL,amount DECIMAL(12,2),cancelled_at DATETIME NULL) ENGINE=InnoDB;
        INSERT INTO waste_trips VALUES(1,322,'planex','2026-10-11',1,11,'completed',NULL);
        INSERT INTO waste_trip_closing_reviews VALUES(1,'payment',4);
        INSERT INTO crews VALUES(11,21,NULL);
        INSERT INTO driver_vehicle_blocks VALUES(21,31,NULL);
        INSERT INTO vehicle_sets VALUES(31,'single',101,NULL,NULL);
        INSERT INTO vehicle_units VALUES(101,'А111АА','ГАЗ',NULL);
        INSERT INTO document_types VALUES(1,'diagnostic_card');
        INSERT INTO documents VALUES(1,'vehicle_unit',101,1,'card.pdf','card.pdf','application/pdf',100,NOW(),NULL),
            (2,'waste_trip',1,NULL,'contract.pdf','contract.pdf','application/pdf',100,NOW(),NULL),
            (3,'waste_trip',1,NULL,'invoice.pdf','invoice.pdf','application/pdf',100,NOW(),NULL),
            (4,'waste_trip',1,NULL,'act.pdf','act.pdf','application/pdf',100,NOW(),NULL),
            (5,'waste_trip',1,NULL,'waybill.pdf','waybill.pdf','application/pdf',100,NOW(),NULL);
        INSERT INTO waste_trip_closing_documents VALUES(1,1,2,'contract','application',NULL,NULL),
            (2,1,3,'invoice','invoice',NULL,NULL),(3,1,4,'act','upd',NULL,NULL),(4,1,5,'waybill','waybill',NULL,NULL);
        INSERT INTO feo_requests VALUES(1);
        INSERT INTO waste_trip_requests VALUES(1,1,1,NULL);
        INSERT INTO waste_trip_completion_items VALUES(1,0,'2026-10-11 10:00:00','2026-10-11 11:00:00',1,1,'full',NULL);
        INSERT INTO waste_trip_completion_documents VALUES(1,1,NULL);");
    Closing::assertEditable($pdo,1);
    $row=$pdo->query('SELECT state,version FROM waste_trip_closing_reviews WHERE waste_trip_id=1')->fetch(PDO::FETCH_ASSOC);
    if ($row['state']!=='payment' || (int)$row['version']!==4) throw new RuntimeException('Legacy payment state must not block editing or mutate itself');
    if (Closing::blockedUnloadedCount($pdo)!==0) throw new RuntimeException('Ready unloaded trip must not raise logistics badge');
    $pdo->exec('UPDATE documents SET deleted_at=NOW() WHERE id=5');
    if (Closing::blockedUnloadedCount($pdo)!==1) throw new RuntimeException('Unloaded trip with a missing required file must raise one badge');
    $pdo->exec('UPDATE documents SET deleted_at=NULL WHERE id=5; DELETE FROM waste_trip_completion_items WHERE waste_trip_request_id=1');
    if (Closing::blockedUnloadedCount($pdo)!==1) throw new RuntimeException('Unprocessed TN must keep unloaded trip blocked');
    $pdo->exec("INSERT INTO waste_trip_completion_items VALUES(1,0,'2026-10-11 10:00:00','2026-10-11 11:00:00',1,1,'full',NULL);
        UPDATE waste_trips SET status='in_progress' WHERE id=1");
    if (Closing::blockedUnloadedCount($pdo)!==0) throw new RuntimeException('Trip still in progress must not raise unloaded badge');
    $pdo->exec("UPDATE waste_trips SET status='completed',actual_end_date=NULL WHERE id=1; UPDATE documents SET deleted_at=NOW() WHERE id=5");
    if (Closing::blockedUnloadedCount($pdo)!==0) throw new RuntimeException('Trip without factual unloading date must not raise badge');
    $pdo->exec("UPDATE waste_trips SET actual_end_date='2026-10-11' WHERE id=1;
        INSERT INTO finance_obligations VALUES(1,'WASTE_TRIP',1,'carrier',NULL);
        INSERT INTO finance_operations VALUES(1,'POSTED');
        INSERT INTO finance_operation_allocations VALUES(1,1,1,NULL,100.00,NULL)");
    if (Closing::blockedUnloadedCount($pdo)!==0) throw new RuntimeException('Paid trip with incomplete documents is not payment-blocked');
    $pdo->exec('UPDATE documents SET deleted_at=NOW() WHERE id=1');
    $docs=array_map(static fn(string $slot):array=>['slot_code'=>$slot],array_keys(Closing::SLOTS));
    $trip=['documents'=>$docs,'tn_issues'=>[],'request_count'=>1,'processed_count'=>1,'carrier_paid_amount'=>'0.00','carrier_rate'=>'250000.00'];
    if (Closing::section($trip)!=='ready') throw new RuntimeException('Complete unpaid trip must be ready automatically');
    $trip['carrier_paid_amount']='50000.00';
    if (Closing::section($trip)!=='paid_complete' || Closing::carrierDebt($trip)!=='Долг 200 000 ₽') throw new RuntimeException('Partial carrier payment must count as paid with red debt');
    $trip['documents']=array_slice($docs,0,3);
    if (Closing::section($trip)!=='paid_incomplete') throw new RuntimeException('Paid trip without a required document must be incomplete');
    $trip['carrier_paid_amount']='0.00';
    if (Closing::section($trip)!=='incomplete') throw new RuntimeException('Unpaid trip without a required document must be incomplete');
    $trip['documents']=$docs;$trip['processed_count']=0;
    if (Closing::section($trip)!=='incomplete') throw new RuntimeException('All TN must be processed');
    $trip['processed_count']=1;$trip['tn_issues']=['missing scan'];
    if (Closing::section($trip)!=='ready') throw new RuntimeException('Processed TN must allow readiness despite validation remarks');
    $trip['carrier_paid_amount']='50000.00';
    if (Closing::section($trip)!=='paid_complete') throw new RuntimeException('Processed TN with remarks must remain in paid complete');
    echo "WASTE_TRIP_CLOSING_PAYMENT=PASS\n";
} finally {cleanCompanyPoolDatabase($pdo);}
