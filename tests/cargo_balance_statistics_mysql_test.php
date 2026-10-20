<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/bootstrap/app.php';
require_once BASE_PATH.'/app/Service/CargoBalanceStatisticsService.php';

use App\Service\CargoBalanceStatisticsService;

$database = (string)getenv('PLANEX_TEST_FIXED_DB');
$allowed = explode(',', (string)getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST'));
if (getenv('APP_ENV') !== 'test' || !preg_match('/^[A-Za-z0-9_]+$/D', $database) || !in_array($database, $allowed, true)) {
    throw new RuntimeException('Isolated test database required');
}
$pdo = new PDO(
    'mysql:host='.getenv('PLANEX_SCHEMA_DB_HOST').';port='.(getenv('PLANEX_SCHEMA_DB_PORT') ?: 3306).';dbname='.$database.';charset=utf8mb4',
    getenv('PLANEX_SCHEMA_DB_USER'), getenv('PLANEX_SCHEMA_DB_PASSWORD'),
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false]
);
$pdo->exec("CREATE TABLE feo_placement_blocks (id INT PRIMARY KEY,block_type VARCHAR(20),status VARCHAR(20),created_at DATETIME);
CREATE TABLE feo_placement_block_requests (id INT PRIMARY KEY,block_id INT,zayavka_id VARCHAR(64),position_no INT);
CREATE TABLE feo_placement_current (zayavka_id VARCHAR(64) PRIMARY KEY);
CREATE TABLE waste_trips (id INT PRIMARY KEY,status VARCHAR(30),actual_start_date DATE,deleted_at DATETIME NULL);
CREATE TABLE waste_trip_requests (id INT PRIMARY KEY,waste_trip_id INT,feo_request_id INT,removed_at DATETIME NULL);
CREATE TABLE feo_requests (id INT PRIMARY KEY,zayavka_id VARCHAR(64));");
$yesterday = (new DateTimeImmutable('yesterday'))->format('Y-m-d');
$today = (new DateTimeImmutable('today'))->format('Y-m-d');
$blocks = $pdo->prepare("INSERT INTO feo_placement_blocks VALUES(?,?, 'applied', ?)");
$blocks->execute([1,'addition',$yesterday.' 10:00:00']);
$blocks->execute([2,'exclusion',$today.' 10:00:00']);
$blocks->execute([3,'addition',$today.' 11:00:00']);
$pdo->exec("INSERT INTO feo_placement_block_requests VALUES (1,1,'A',1),(2,1,'B',2),(3,1,'C',3),(4,2,'C',1),(5,3,'D',1);
INSERT INTO feo_placement_current VALUES ('A'),('B'),('D');
INSERT INTO feo_requests VALUES (1,'A'),(2,'B'),(3,'C'),(4,'D');");
$trips = $pdo->prepare('INSERT INTO waste_trips VALUES (?,?,?,?)');
foreach ([[1,'in_progress',$yesterday,null],[2,'completed',$today,null],[3,'completed',$today,null],
          [4,'cancelled',$today,null],[5,'searching_executor',null,null],[6,'in_progress',$today,$today.' 12:00:00'],
          [7,'assigned',null,null],[8,'assigned',null,null],[9,'assigned',null,$today.' 12:00:00']] as $row) {
    $trips->execute($row);
}
$pdo->exec("INSERT INTO waste_trip_requests(id,waste_trip_id,feo_request_id) VALUES (1,1,1),(2,2,2),(3,3,1),(4,4,3),(5,5,4),(6,6,4),(7,7,1),(8,7,4),(9,8,4),(10,9,3)");
$pdo->exec("UPDATE waste_trip_requests SET removed_at=NOW() WHERE id=9");
if (!CargoBalanceStatisticsService::schemaReady($pdo)) throw new RuntimeException('Schema check failed');
$dashboard = CargoBalanceStatisticsService::dashboard($pdo);
$daily = $dashboard['daily'];
$last = end($daily);
if ($last['assigned'] !== 3 || $last['started'] !== 2 || $last['received'] !== 1
    || $last['withdrawn'] !== 1 || $last['started_new'] !== 1 || $last['assigned']-$last['started'] !== 1) {
    throw new RuntimeException('Cargo balance movement or distinct work count incorrect: '.json_encode($last));
}
if ($dashboard['forecast'] !== ['formed_trip_count'=>2,'projected_new'=>1]) {
    throw new RuntimeException('Formed trip forecast must exclude deleted trips, removed links, and requests already started: '.json_encode($dashboard['forecast']));
}
echo "CARGO_BALANCE_STATISTICS_MYSQL=PASS\n";
