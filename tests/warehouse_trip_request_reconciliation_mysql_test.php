<?php

require_once __DIR__.'/../app/Service/WasteTripLegacySourceService.php';
require_once __DIR__.'/../app/Service/WarehouseTripRequestReconciliationService.php';

use App\Service\WarehouseTripRequestReconciliationService as Reconcile;

function checkMatch(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function rejectedMatch(callable $call, string $message): void
{
    try { $call(); } catch (RuntimeException) { return; }
    throw new RuntimeException($message);
}

$database = (string)getenv('PLANEX_TEST_FIXED_DB');
checkMatch($database !== '' && in_array($database, explode(',', (string)getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST')), true), 'Disposable database required.');
$host = getenv('PLANEX_SCHEMA_DB_HOST') ?: '127.0.0.1';
$port = getenv('PLANEX_SCHEMA_DB_PORT') ?: '3306';
$user = getenv('PLANEX_SCHEMA_DB_USER') ?: 'planex_ci';
$pass = getenv('PLANEX_SCHEMA_DB_PASSWORD') ?: 'planex_ci';
$dsn = "mysql:host=$host;port=$port;dbname=$database;charset=utf8mb4";
$options = [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false];
$legacy = new PDO($dsn, $user, $pass, $options);
$tenant = new PDO($dsn, $user, $pass, $options);
$legacy->exec("CREATE TABLE flights(id INT PRIMARY KEY,status VARCHAR(30),route_type VARCHAR(40),source_warehouse_id INT NULL,destination_warehouse_id INT NULL,zayavki_ids TEXT NOT NULL) ENGINE=InnoDB;
CREATE TABLE feo(zayavka_id INT PRIMARY KEY,mass_netto DECIMAL(12,4),mass_brutto DECIMAL(12,4),summarnyy_obem DECIMAL(12,4),naim_otkhoda_fkko VARCHAR(20)) ENGINE=InnoDB;
CREATE TABLE warehouse_movements(id INT AUTO_INCREMENT PRIMARY KEY,movement_type VARCHAR(30),warehouse_id INT,source_warehouse_id INT NULL,destination_warehouse_id INT NULL,flight_id INT,zayavka_id INT,fkko_code VARCHAR(20),mass_netto DECIMAL(12,4),mass_brutto DECIMAL(12,4),volume DECIMAL(12,4),movement_date DATETIME,status VARCHAR(20),comment VARCHAR(255),created_at DATETIME,updated_at DATETIME,UNIQUE KEY uniq_flight_movement_zayavka(flight_id,movement_type,warehouse_id,zayavka_id)) ENGINE=InnoDB;
CREATE TABLE waste_trips(id INT PRIMARY KEY,business_id INT,status VARCHAR(30),source_system VARCHAR(30),legacy_flight_id INT,lock_version INT,deleted_at DATETIME NULL) ENGINE=InnoDB;
CREATE TABLE feo_requests(id INT PRIMARY KEY,zayavka_id INT) ENGINE=InnoDB;
CREATE TABLE waste_trip_requests(id INT PRIMARY KEY,waste_trip_id INT,feo_request_id INT,snapshot_mass_kg DECIMAL(18,3),position_no INT) ENGINE=InnoDB;
INSERT INTO feo VALUES(311706,2.1290,2.3000,3.0000,'A'),(295036,1.3890,1.5000,2.0000,'B'),(296555,3.2800,3.5000,4.0000,'C');
INSERT INTO feo_requests VALUES(1,311706),(2,295036),(3,296555);
INSERT INTO flights VALUES(286,'completed','generator_to_warehouse',NULL,1,'311706,295036'),(290,'completed','generator_to_warehouse',NULL,1,'311706'),(309,'completed','generator_to_warehouse',NULL,1,'295036');
INSERT INTO waste_trips VALUES(1,286,'completed','legacy_sync',286,1,NULL),(2,309,'completed','legacy_sync',309,1,NULL);
INSERT INTO waste_trip_requests VALUES(1,1,2,669.000,1),(2,2,2,1389.000,1),(3,2,3,3280.000,2);
INSERT INTO warehouse_movements(movement_type,warehouse_id,destination_warehouse_id,flight_id,zayavka_id,mass_netto,mass_brutto,volume,movement_date,status,comment,created_at,updated_at) VALUES
('receipt',1,1,286,311706,2.1290,2.3000,3.0000,'2026-09-18 12:00:00','active','старый приход',NOW(),NOW()),
('receipt',1,1,286,295036,1.3890,1.5000,2.0000,'2026-09-18 12:00:00','active','старый приход',NOW(),NOW()),
('receipt',1,1,290,311706,2.1290,2.3000,3.0000,'2026-09-20 12:00:00','active','другой рейс',NOW(),NOW()),
('receipt',1,1,309,295036,1.3890,1.5000,2.0000,'2026-09-22 12:00:00','active','приход',NOW(),NOW());");

$preview = Reconcile::preview($legacy, $tenant, $database, 286, 1, 'in');
checkMatch($preview['ready'] && $preview['remove_ids'] === ['311706'] && $preview['change_mass_ids'] === ['295036'], 'Preview must explain the selected flight changes.');
checkMatch($preview['after_kg'] === 669.0, 'ERP snapshot mass must be the target.');
rejectedMatch(static fn() => Reconcile::apply($legacy, $tenant, $database, 286, 1, 'in', str_repeat('0', 64), 7), 'Stale preview must fail.');
$result = Reconcile::apply($legacy, $tenant, $database, 286, 1, 'in', $preview['fingerprint'], 7);
checkMatch($result['removed'] === 1 && $result['updated'] === 1 && $result['created'] === 0, 'Selected flight must be reconciled.');
checkMatch((string)$legacy->query('SELECT zayavki_ids FROM flights WHERE id=286')->fetchColumn() === '295036', 'Legacy flight list must match ERP.');
checkMatch((float)$legacy->query("SELECT mass_netto FROM warehouse_movements WHERE flight_id=286 AND zayavka_id=295036 AND status='active'")->fetchColumn() === 0.669, 'Movement mass must use ERP snapshot.');
checkMatch((int)$legacy->query("SELECT COUNT(*) FROM warehouse_movements WHERE flight_id=286 AND zayavka_id=311706 AND status='cancelled'")->fetchColumn() === 1, 'Removed movement must remain as cancelled audit row.');
checkMatch((int)$legacy->query("SELECT COUNT(*) FROM warehouse_movements WHERE flight_id=290 AND zayavka_id=311706 AND status='active'")->fetchColumn() === 1, 'Another flight must remain untouched.');

$preview = Reconcile::preview($legacy, $tenant, $database, 309, 1, 'in');
checkMatch($preview['ready'] && $preview['add_ids'] === ['296555'], 'Missing ERP request must be added.');
$result = Reconcile::apply($legacy, $tenant, $database, 309, 1, 'in', $preview['fingerprint'], 7);
checkMatch($result['created'] === 1 && (int)$legacy->query("SELECT COUNT(*) FROM warehouse_movements WHERE flight_id=309 AND status='active'")->fetchColumn() === 2, 'Exact ERP request set must be active.');
checkMatch((string)$legacy->query('SELECT zayavki_ids FROM flights WHERE id=309')->fetchColumn() === '295036,296555', 'Legacy membership follows ERP.');

$legacy->exec("INSERT INTO flights VALUES(288,'completed','generator_to_warehouse',NULL,1,'311706');
INSERT INTO waste_trips VALUES(3,288,'completed','legacy_sync',288,1,NULL);
INSERT INTO waste_trip_requests VALUES(4,3,2,669.000,1);
INSERT INTO warehouse_movements(movement_type,warehouse_id,destination_warehouse_id,flight_id,zayavka_id,mass_netto,movement_date,status,comment,created_at,updated_at) VALUES
('receipt',1,1,288,311706,2.1290,'2026-09-10 12:00:00','active','старый приход',NOW(),NOW()),
('issue',1,NULL,500,311706,2.1290,'2026-09-11 12:00:00','active','дальнейший вывоз',NOW(),NOW());");
$dependent = Reconcile::preview($legacy, $tenant, $database, 288, 1, 'in');
checkMatch($dependent['ready'] && $dependent['warnings'] !== [], 'Dependent issue must be warned before changing only the selected flight.');
Reconcile::apply($legacy, $tenant, $database, 288, 1, 'in', $dependent['fingerprint'], 7);
checkMatch((int)$legacy->query("SELECT COUNT(*) FROM warehouse_movements WHERE flight_id=500 AND status='active'")->fetchColumn() === 1, 'Related issue remains untouched.');

$legacy->exec("INSERT INTO flights VALUES(320,'completed','warehouse_to_warehouse',1,2,'295036'),(321,'completed','warehouse_to_utilizer',2,NULL,'296555');
INSERT INTO waste_trips VALUES(4,320,'completed','legacy_sync',320,1,NULL),(5,321,'completed','legacy_sync',321,1,NULL);
INSERT INTO waste_trip_requests VALUES(5,4,3,3280.000,1),(6,5,3,3280.000,1);
INSERT INTO warehouse_movements(movement_type,warehouse_id,source_warehouse_id,destination_warehouse_id,flight_id,zayavka_id,mass_netto,movement_date,status,comment,created_at,updated_at) VALUES
('transfer_out',1,1,2,320,295036,0.6690,'2026-09-23 12:00:00','active','старый перевод',NOW(),NOW()),
('transfer_in',2,1,2,320,295036,0.6690,'2026-09-23 12:00:00','active','старый перевод',NOW(),NOW()),
('issue',2,2,NULL,321,296555,1.0000,'2026-09-24 12:00:00','active','старое списание',NOW(),NOW());");
$transfer = Reconcile::preview($legacy, $tenant, $database, 320, 1, 'out');
checkMatch($transfer['ready'] && $transfer['remove_ids'] === ['295036'] && $transfer['add_ids'] === ['296555'], 'Transfer preview must cover both warehouses.');
$transferResult = Reconcile::apply($legacy, $tenant, $database, 320, 1, 'out', $transfer['fingerprint'], 7);
checkMatch($transferResult['removed'] === 2 && $transferResult['created'] === 2, 'Transfer must change both legs atomically.');
checkMatch((int)$legacy->query("SELECT COUNT(*) FROM warehouse_movements WHERE flight_id=320 AND status='active' AND zayavka_id=296555")->fetchColumn() === 2, 'Both transfer legs use ERP membership.');

$issue = Reconcile::preview($legacy, $tenant, $database, 321, 2, 'out');
checkMatch($issue['ready'] && $issue['change_mass_ids'] === ['296555'], 'Issue preview must use ERP mass.');
$legacy->exec("UPDATE waste_trip_requests SET snapshot_mass_kg=3000.000 WHERE id=6");
rejectedMatch(static fn() => Reconcile::apply($legacy, $tenant, $database, 321, 2, 'out', $issue['fingerprint'], 7), 'Changed ERP snapshot must invalidate preview.');
checkMatch((float)$legacy->query("SELECT mass_netto FROM warehouse_movements WHERE flight_id=321 AND status='active'")->fetchColumn() === 1.0, 'Stale preview must leave issue unchanged.');
$issue = Reconcile::preview($legacy, $tenant, $database, 321, 2, 'out');
Reconcile::apply($legacy, $tenant, $database, 321, 2, 'out', $issue['fingerprint'], 7);
checkMatch((float)$legacy->query("SELECT mass_netto FROM warehouse_movements WHERE flight_id=321 AND status='active'")->fetchColumn() === 3.0, 'Outgoing mass recalculated from ERP snapshot.');

$legacy->exec("INSERT INTO flights VALUES(330,'completed','generator_to_warehouse',NULL,1,'295036');
INSERT INTO waste_trips VALUES(6,330,'completed','legacy_sync',330,1,NULL);
INSERT INTO waste_trip_requests VALUES(7,6,3,3280.000,1);
INSERT INTO warehouse_movements(movement_type,warehouse_id,destination_warehouse_id,flight_id,zayavka_id,mass_netto,movement_date,status,comment,created_at,updated_at) VALUES
('receipt',1,1,330,295036,1.3890,'2026-09-25 12:00:00','active','старый приход',NOW(),NOW()),
('receipt',1,1,330,296555,3.2800,'2026-09-25 12:00:00','cancelled','чужая отмена',NOW(),NOW());");
$conflict = Reconcile::preview($legacy, $tenant, $database, 330, 1, 'in');
rejectedMatch(static fn() => Reconcile::apply($legacy, $tenant, $database, 330, 1, 'in', $conflict['fingerprint'], 7), 'Cancelled unique-key conflict must roll back.');
checkMatch((string)$legacy->query('SELECT zayavki_ids FROM flights WHERE id=330')->fetchColumn() === '295036', 'Conflict must roll back legacy membership.');
checkMatch((int)$legacy->query("SELECT COUNT(*) FROM warehouse_movements WHERE flight_id=330 AND zayavka_id=295036 AND status='active'")->fetchColumn() === 1, 'Conflict must roll back active movement.');

$legacy->exec("INSERT INTO flights VALUES(340,'completed','generator_to_warehouse',NULL,1,'295036');
INSERT INTO waste_trips VALUES(7,340,'completed','legacy_sync',340,1,NULL);
INSERT INTO waste_trip_requests VALUES(8,7,3,3280.000,1);
INSERT INTO warehouse_movements(movement_type,warehouse_id,destination_warehouse_id,flight_id,zayavka_id,mass_netto,movement_date,status,comment,created_at,updated_at) VALUES
('receipt',1,1,340,295036,1.3890,'2026-09-26 12:00:00','active','старый приход',NOW(),NOW());");
$sameConnection = Reconcile::preview($legacy, $legacy, $database, 340, 1, 'in');
Reconcile::apply($legacy, $legacy, $database, 340, 1, 'in', $sameConnection['fingerprint'], 7);
checkMatch((string)$legacy->query('SELECT zayavki_ids FROM flights WHERE id=340')->fetchColumn() === '296555', 'Shared tenant and legacy connection must use one transaction.');

echo "warehouse trip request reconciliation OK\n";
