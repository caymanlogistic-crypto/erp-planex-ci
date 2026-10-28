<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap/app.php';
require_once BASE_PATH . '/app/Support/company_database.php';
require_once BASE_PATH . '/app/Service/WasteTripClosingService.php';

use App\Service\WasteTripClosingService;

$database = (string)getenv('PLANEX_TEST_FIXED_DB');
if ($database === '' || !in_array($database, explode(',', (string)getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST')), true)) {
    throw new RuntimeException('Guarded test database required');
}
$pdo = new PDO('mysql:host=' . getenv('PLANEX_SCHEMA_DB_HOST') . ';port=' . getenv('PLANEX_SCHEMA_DB_PORT') . ';dbname=' . $database . ';charset=utf8mb4',
    getenv('PLANEX_SCHEMA_DB_USER'), getenv('PLANEX_SCHEMA_DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
cleanCompanyPoolDatabase($pdo);
try {
    $pdo->exec("CREATE TABLE documents(id INT PRIMARY KEY,deleted_at DATETIME NULL) ENGINE=InnoDB;
        CREATE TABLE waste_trip_closing_documents(waste_trip_id INT,document_id INT,slot_code VARCHAR(32),deleted_at DATETIME NULL) ENGINE=InnoDB;
        CREATE TABLE feo_requests(id INT PRIMARY KEY,zayavka_id INT) ENGINE=InnoDB;
        CREATE TABLE waste_trip_requests(id INT PRIMARY KEY,waste_trip_id INT,feo_request_id INT,removed_at DATETIME NULL) ENGINE=InnoDB;
        CREATE TABLE waste_trip_completion_items(waste_trip_request_id INT PRIMARY KEY,tn_absent TINYINT,arrived_at DATETIME NULL,departed_at DATETIME NULL,driver_signature_present TINYINT,sender_signature_present TINYINT,tn_datetime_status VARCHAR(20),recovery_source VARCHAR(100)) ENGINE=InnoDB;
        CREATE TABLE waste_trip_completion_documents(id INT PRIMARY KEY,waste_trip_request_id INT,deleted_at DATETIME NULL) ENGINE=InnoDB;
        INSERT INTO documents VALUES(1,NULL),(2,NULL),(3,NULL),(4,NULL),(5,NULL),(6,NULL),(7,NULL),(8,NOW());
        INSERT INTO waste_trip_closing_documents VALUES(10,1,'contract',NULL),(10,2,'invoice',NULL),(10,3,'act',NULL),(10,4,'waybill',NULL),(11,5,'contract',NULL),(11,6,'invoice',NULL),(11,7,'waybill',NULL),(11,8,'act',NULL);
        INSERT INTO feo_requests VALUES(1,501),(2,502);
        INSERT INTO waste_trip_requests VALUES(1,10,1,NULL),(2,11,2,NULL);
        INSERT INTO waste_trip_completion_items VALUES(1,0,'2026-10-20 10:00:00','2026-10-20 11:00:00',1,1,'yes',NULL),(2,0,NULL,NULL,0,0,'no',NULL);
        INSERT INTO waste_trip_completion_documents VALUES(1,1,NULL);");
    $result = WasteTripClosingService::paymentReadinessForTrips($pdo, [10, 11, 12, 10]);
    if ($result[10]['status'] !== 'ready' || $result[10]['missing'] !== [] || $result[10]['processed'] !== 1 || $result[10]['total'] !== 1) throw new RuntimeException('Complete trip must await payment');
    if ($result[11]['status'] !== 'incomplete' || $result[11]['missing'] !== ['АКТ/УПД'] || $result[11]['processed'] !== 0 || $result[11]['total'] !== 1) throw new RuntimeException('Missing active document and unprocessed request must block payment');
    if (!str_contains(implode(' ', $result[11]['tn_issues']), 'Заявка №502: нет скана ТН')) throw new RuntimeException('Concrete TN errors must be available');
    if ($result[12]['status'] !== 'incomplete' || $result[12]['total'] !== 0) throw new RuntimeException('Trip without requests must be incomplete');
    echo "FINANCE_RECEIVED_PAID_CLOSING_READINESS=PASS\n";
} finally {
    cleanCompanyPoolDatabase($pdo);
}
