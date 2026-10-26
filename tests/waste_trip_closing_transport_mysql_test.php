<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/bootstrap/app.php';
require_once BASE_PATH.'/app/Support/company_database.php';
require_once BASE_PATH.'/app/Security/EffectiveAccess.php';
require_once BASE_PATH.'/app/Security/AccessRuntime.php';
require_once BASE_PATH.'/app/Service/AccessControlService.php';
require_once BASE_PATH.'/app/Service/WasteTripClosingService.php';
require_once BASE_PATH.'/app/Service/WasteTripClosingTransportService.php';

use App\Security\AccessRuntime;
use App\Security\EffectiveAccess;
use App\Service\WasteTripClosingTransportService as Transport;

$database=(string)getenv('PLANEX_TEST_FIXED_DB');
if ($database==='' || !in_array($database,explode(',',(string)getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST')),true)) {
    throw new RuntimeException('Guarded test database required');
}
$pdo=new PDO('mysql:host='.getenv('PLANEX_SCHEMA_DB_HOST').';port='.(getenv('PLANEX_SCHEMA_DB_PORT')?:3306).';dbname='.$database.';charset=utf8mb4',
    getenv('PLANEX_SCHEMA_DB_USER'),getenv('PLANEX_SCHEMA_DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
cleanCompanyPoolDatabase($pdo);
try {
    $_SESSION['role_code']='company_owner';
    $_SESSION['user_id']=1;
    $pdo->exec("CREATE TABLE users(id INT PRIMARY KEY,membership_id INT NOT NULL) ENGINE=InnoDB;
        CREATE TABLE waste_trips(id INT PRIMARY KEY,route_executor_id INT,responsible_user_id INT,status VARCHAR(20),deleted_at DATETIME NULL) ENGINE=InnoDB;
        CREATE TABLE crews(id INT PRIMARY KEY,driver_vehicle_block_id INT,deleted_at DATETIME NULL) ENGINE=InnoDB;
        CREATE TABLE driver_vehicle_blocks(id INT PRIMARY KEY,vehicle_set_id INT,deleted_at DATETIME NULL) ENGINE=InnoDB;
        CREATE TABLE vehicle_sets(id INT PRIMARY KEY,set_type VARCHAR(20),primary_vehicle_unit_id INT,secondary_vehicle_unit_id INT NULL,deleted_at DATETIME NULL,created_by_user_id INT NULL) ENGINE=InnoDB;
        CREATE TABLE vehicle_units(id INT PRIMARY KEY,plate_number VARCHAR(20),brand VARCHAR(100),diagnostic_card_number VARCHAR(50),diagnostic_card_date DATE NULL,deleted_at DATETIME NULL) ENGINE=InnoDB;
        CREATE TABLE document_types(id INT PRIMARY KEY,code VARCHAR(30)) ENGINE=InnoDB;
        CREATE TABLE entity_access_grants(id INT PRIMARY KEY,entity_type VARCHAR(30),entity_id INT,granted_to_user_id INT,access_level VARCHAR(20),revoked_at DATETIME NULL) ENGINE=InnoDB;
        CREATE TABLE documents(id INT PRIMARY KEY,entity_type VARCHAR(20),entity_id INT,document_type_id INT,original_name VARCHAR(100),deleted_at DATETIME NULL) ENGINE=InnoDB;
        INSERT INTO users VALUES(10,71),(11,72);
        INSERT INTO waste_trips VALUES(1,11,10,'completed',NULL),(2,12,10,'completed',NULL),(3,13,10,'completed',NULL),(4,11,11,'completed',NULL);
        INSERT INTO crews VALUES(11,21,NULL),(12,22,NULL),(13,23,NULL);
        INSERT INTO driver_vehicle_blocks VALUES(21,31,NULL),(22,32,NULL),(23,33,NULL);
        INSERT INTO vehicle_sets VALUES(31,'single',101,NULL,NULL,NULL),(32,'coupling',102,103,NULL,NULL),(33,'road_train',104,105,NULL,NULL);
        INSERT INTO vehicle_units VALUES(101,'А111АА','ГАЗ',NULL,NULL,NULL),(102,'В222ВВ','МАЗ',NULL,NULL,NULL),(103,'С333СС','ТОНАР','CARD-3','2026-09-20',NULL),(104,'Е444ЕЕ','КАМАЗ',NULL,NULL,NULL),(105,'К555КК','НЕФАЗ',NULL,NULL,NULL);
        INSERT INTO document_types VALUES(1,'diagnostic_card'),(2,'sts');
        INSERT INTO documents VALUES(201,'vehicle_unit',103,1,'card-1.pdf',NULL),(202,'vehicle_unit',103,1,'card-2.pdf',NULL),(203,'vehicle_unit',102,2,'sts.pdf',NULL),(204,'vehicle_unit',101,1,'old.pdf',NOW());");
    $result=Transport::forTrips($pdo,[1,2,3]);
    if (count($result[1]['units'])!==1 || $result[1]['units'][0]['role']!=='primary' || $result[1]['units'][0]['documents']!==[]) {
        throw new RuntimeException('Single unit must have one missing diagnostic card');
    }
    if (count($result[2]['units'])!==2 || $result[2]['units'][0]['label']!=='Тягач' || $result[2]['units'][1]['label']!=='Полуприцеп'
        || count($result[2]['units'][1]['documents'])!==2 || $result[2]['units'][0]['documents']!==[]) {
        throw new RuntimeException('Coupling cards must belong to their own units and allow several files');
    }
    if (array_key_exists('number',$result[2]['units'][1]) || array_key_exists('date',$result[2]['units'][1])) {
        throw new RuntimeException('Stored card number and date must not be exposed in closing documents');
    }
    if (count($result[3]['units'])!==2 || $result[3]['units'][1]['label']!=='Прицеп') {
        throw new RuntimeException('Road train must expose truck and trailer separately');
    }
    $_SESSION['role_code']='logist';
    $_SESSION['user_id']=5;
    $withoutEdit=Transport::forTrips($pdo,[2]);
    if (count($withoutEdit[2]['units'])!==2 || empty($withoutEdit[2]['units'][1]['has_card'])
        || !empty($withoutEdit[2]['units'][1]['can_upload']) || $withoutEdit[2]['units'][1]['documents']!==[]) {
        throw new RuntimeException('Existing vehicle card must satisfy the trip without document edit access or exposing its files');
    }
    $_SERVER['REQUEST_URI']='/company/logistics/closing-documents';
    AccessRuntime::setCurrent(EffectiveAccess::member(71,1,
        ['company.documents.trip_closing_registry'=>true,'company.vehicle_sets'=>false],
        ['waste_trip'=>'none','vehicle_unit'=>'none'],'logist'));
    $assigned=Transport::forTrips($pdo,[1,2,4]);
    if (empty($assigned[1]['units'][0]['can_upload']) || empty($assigned[2]['units'][0]['can_upload'])
        || empty($assigned[2]['units'][1]['can_upload']) || count($assigned[2]['units'][1]['documents'])!==2
        || !empty($assigned[4]['units'][0]['can_upload'])) {
        throw new RuntimeException('Closing card access must follow the assigned trip, including both units, without leaking a shared vehicle to another trip');
    }
    AccessRuntime::setCurrent(EffectiveAccess::member(71,1,
        ['company.documents.trip_closing_registry'=>false,'company.vehicle_sets'=>false],
        ['waste_trip'=>'none','vehicle_unit'=>'none'],'logist'));
    if (!empty(Transport::forTrips($pdo,[1])[1]['units'][0]['can_upload'])) {
        throw new RuntimeException('Closing card upload must require the closing registry page');
    }
    AccessRuntime::setCurrent(EffectiveAccess::member(71,1,
        ['company.documents.trip_closing_registry'=>true,'company.vehicle_sets'=>false],
        ['waste_trip'=>'none','vehicle_unit'=>'none'],'logist'));
    $_SERVER['REQUEST_URI']='/company/trips/closing-documents';
    if (!empty(Transport::forTrips($pdo,[1])[1]['units'][0]['can_upload'])) {
        throw new RuntimeException('Closing registry permission must not extend to a different route');
    }
    echo "WASTE_TRIP_CLOSING_TRANSPORT=PASS\n";
} finally {
    AccessRuntime::clear();
    cleanCompanyPoolDatabase($pdo);
}
