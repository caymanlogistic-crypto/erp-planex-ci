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
    try {Closing::assertEditable($pdo,1);throw new RuntimeException('Queued trip must reject document edits');}
    catch (DomainException $error) {if (!str_contains($error->getMessage(),'верните')) throw $error;}
    try {Closing::changePaymentStage($pdo,1,3,'rework');throw new RuntimeException('Stale action must fail');}
    catch (DomainException $error) {if (!str_contains($error->getMessage(),'изменились')) throw $error;}
    Closing::changePaymentStage($pdo,1,4,'rework');
    $row=$pdo->query('SELECT state,version FROM waste_trip_closing_reviews WHERE waste_trip_id=1')->fetch(PDO::FETCH_ASSOC);
    if ($row['state']!=='work' || (int)$row['version']!==5) throw new RuntimeException('Return to rework must persist and advance version');
    Closing::changePaymentStage($pdo,1,5,'queue');
    $row=$pdo->query('SELECT state,version FROM waste_trip_closing_reviews WHERE waste_trip_id=1')->fetch(PDO::FETCH_ASSOC);
    if ($row['state']!=='payment' || (int)$row['version']!==6) throw new RuntimeException('Ready trip must move to payment queue');
    Closing::changePaymentStage($pdo,1,6,'rework');
    $pdo->exec('UPDATE waste_trip_completion_documents SET deleted_at=NOW() WHERE id=1');
    try {Closing::changePaymentStage($pdo,1,7,'queue');throw new RuntimeException('Trip with a missing TN scan must not enter payment queue');}
    catch (DomainException $error) {if (!str_contains($error->getMessage(),'ошибки ТН')) throw $error;}
    $pdo->exec('UPDATE waste_trip_completion_documents SET deleted_at=NULL WHERE id=1');
    $pdo->exec('UPDATE waste_trip_closing_documents SET deleted_at=NOW() WHERE id=4');
    try {Closing::changePaymentStage($pdo,1,7,'queue');throw new RuntimeException('Incomplete trip must not enter payment queue');}
    catch (DomainException $error) {if (!str_contains($error->getMessage(),'обязательные документы')) throw $error;}
    $docs=array_map(static fn(string $slot):array=>['slot_code'=>$slot],array_keys(Closing::SLOTS));
    $trip=['documents'=>$docs,'tn_issues'=>[]];$transport=['units'=>[['documents'=>[['id'=>1]]]]];
    if (!Closing::paymentReady($trip,$transport) || !Closing::paymentReady($trip,['units'=>[['has_card'=>true,'documents'=>[]]]])
        || Closing::paymentReady($trip,['units'=>[['has_card'=>false,'documents'=>[]]]])
        || Closing::paymentReady(['documents'=>$docs,'tn_issues'=>['missing scan']],$transport)) {
        throw new RuntimeException('Payment readiness must require all documents, cards and no TN issues');
    }
    echo "WASTE_TRIP_CLOSING_PAYMENT=PASS\n";
} finally {cleanCompanyPoolDatabase($pdo);}
