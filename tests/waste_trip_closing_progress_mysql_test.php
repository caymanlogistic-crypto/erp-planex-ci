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
cleanCompanyPoolDatabase($pdo);
try {
    $pdo->exec("CREATE TABLE feo_requests(id INT PRIMARY KEY) ENGINE=InnoDB;
        CREATE TABLE waste_trip_requests(id INT PRIMARY KEY,waste_trip_id INT,feo_request_id INT,removed_at DATETIME NULL) ENGINE=InnoDB;
        CREATE TABLE waste_trip_completion_items(waste_trip_request_id INT PRIMARY KEY,tn_absent TINYINT,arrived_at DATETIME NULL,departed_at DATETIME NULL) ENGINE=InnoDB;
        INSERT INTO feo_requests VALUES(1),(2),(3),(4),(5);
        INSERT INTO waste_trip_requests VALUES(1,10,1,NULL),(2,10,2,NULL),(3,10,3,NULL),(4,10,4,NULL),(5,10,5,NOW());
        INSERT INTO waste_trip_completion_items VALUES
            (1,1,NULL,NULL),(2,0,'2026-10-11 10:00:00','2026-10-11 11:00:00'),
            (3,0,'2026-10-11 10:00:00',NULL),(4,0,NULL,NULL),(5,1,NULL,NULL);");
    $counts=Closing::processedCounts($pdo,[10]);
    if ((int)$counts[10]['request_count']!==4 || (int)$counts[10]['processed_count']!==2) {
        throw new RuntimeException('Processed count must match logistics assistant: no TN or both timestamps, excluding removed requests');
    }
    echo "WASTE_TRIP_CLOSING_PROGRESS=PASS\n";
} finally {cleanCompanyPoolDatabase($pdo);}
