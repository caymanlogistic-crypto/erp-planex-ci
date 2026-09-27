<?php

require_once __DIR__.'/../app/Service/WarehouseTripCorrectionService.php';

use App\Service\WarehouseTripCorrectionService as Correction;

function checkCorrection(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function correctionRejected(callable $call, string $message): void
{
    try { $call(); } catch (Throwable) { return; }
    throw new RuntimeException($message);
}

$database = (string)getenv('PLANEX_TEST_FIXED_DB');
checkCorrection($database !== '' && in_array($database, explode(',', (string)getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST')), true), 'Disposable database required.');
$host = getenv('PLANEX_SCHEMA_DB_HOST') ?: '127.0.0.1';
$port = getenv('PLANEX_SCHEMA_DB_PORT') ?: '3306';
$user = getenv('PLANEX_SCHEMA_DB_USER') ?: 'planex_ci';
$pass = getenv('PLANEX_SCHEMA_DB_PASSWORD') ?: 'planex_ci';
$pdo = new PDO("mysql:host=$host;port=$port;dbname=$database;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]);

$pdo->exec("CREATE TABLE warehouses(id INT PRIMARY KEY,name VARCHAR(100) NOT NULL,is_active TINYINT NOT NULL) ENGINE=InnoDB;
CREATE TABLE flights(id INT PRIMARY KEY,status VARCHAR(30) NOT NULL,route_type VARCHAR(40),unload_type VARCHAR(20),source_warehouse_id INT NULL,destination_warehouse_id INT NULL,zayavki_ids TEXT NOT NULL,actual_end_date DATETIME NULL,comment VARCHAR(100)) ENGINE=InnoDB;
CREATE TABLE feo(zayavka_id INT PRIMARY KEY,naim_otkhoda_fkko VARCHAR(20),mass_netto DECIMAL(12,4),mass_brutto DECIMAL(12,4),summarnyy_obem DECIMAL(12,4)) ENGINE=InnoDB;
CREATE TABLE warehouse_movements(id INT AUTO_INCREMENT PRIMARY KEY,movement_type VARCHAR(30) NOT NULL,warehouse_id INT NOT NULL,source_warehouse_id INT NULL,destination_warehouse_id INT NULL,flight_id INT NOT NULL,zayavka_id INT NOT NULL,fkko_code VARCHAR(20),mass_netto DECIMAL(12,4),mass_brutto DECIMAL(12,4),volume DECIMAL(12,4),movement_date DATETIME,status VARCHAR(20),comment VARCHAR(255),created_at DATETIME,updated_at DATETIME,INDEX idx_balance(warehouse_id,zayavka_id,status),INDEX idx_flight(flight_id)) ENGINE=InnoDB;
INSERT INTO warehouses VALUES(1,'Север',1),(2,'Юг',1),(3,'Закрыт',0);
INSERT INTO feo VALUES(101,'code','1.0000','1.1000','2.0000'),(102,'code','2.0000','2.2000','3.0000');
INSERT INTO flights VALUES
(20,'completed','generator_to_utilizer','OO',NULL,NULL,'101,102','2026-09-01 12:00:00','Приход'),
(21,'started','generator_to_utilizer','OO',NULL,NULL,'101',NULL,'Вывоз'),
(22,'started','generator_to_utilizer','OO',NULL,NULL,'102',NULL,'Перемещение'),
(23,'started','generator_to_utilizer','OO',NULL,NULL,'102',NULL,'Недостаток'),
(24,'started','generator_to_utilizer','OO',NULL,NULL,'101',NULL,'Устаревшая проверка'),
(25,'started','generator_to_utilizer','OO',NULL,NULL,'102',NULL,'Откат');");

$preview = Correction::preview($pdo, $database, 21, 'warehouse_to_utilizer', 1, 0);
checkCorrection(!$preview['ready'], 'Outgoing flight without stock must be blocked.');
$preview = Correction::preview($pdo, $database, 20, 'generator_to_warehouse', 0, 1);
checkCorrection($preview['ready'] && $preview['operation_count'] === 2, 'Incoming preview.');
$result = Correction::apply($pdo, $database, 20, 'generator_to_warehouse', 0, 1, $preview['fingerprint']);
checkCorrection($result['created'] === 2, 'Two receipts committed.');
checkCorrection((int)$pdo->query("SELECT COUNT(*) FROM warehouse_movements WHERE flight_id=20 AND movement_type='receipt'")->fetchColumn() === 2, 'Receipt rows.');
correctionRejected(static fn() => Correction::apply($pdo, $database, 20, 'generator_to_warehouse', 0, 1, $preview['fingerprint']), 'Duplicate correction must fail.');

$preview = Correction::preview($pdo, $database, 21, 'warehouse_to_utilizer', 1, 0);
checkCorrection($preview['ready'], 'Stock must allow issue.');
Correction::apply($pdo, $database, 21, 'warehouse_to_utilizer', 1, 0, $preview['fingerprint']);
checkCorrection((int)$pdo->query("SELECT COUNT(*) FROM warehouse_movements WHERE flight_id=21 AND movement_type='issue'")->fetchColumn() === 1, 'Issue row.');

$preview = Correction::preview($pdo, $database, 22, 'warehouse_to_warehouse', 1, 2);
checkCorrection($preview['ready'] && $preview['operation_count'] === 2, 'Transfer preview.');
Correction::apply($pdo, $database, 22, 'warehouse_to_warehouse', 1, 2, $preview['fingerprint']);
checkCorrection((int)$pdo->query('SELECT COUNT(*) FROM warehouse_movements WHERE flight_id=22')->fetchColumn() === 2, 'Both transfer rows.');
checkCorrection(!Correction::preview($pdo, $database, 23, 'warehouse_to_utilizer', 1, 0)['ready'], 'Already transferred stock cannot be issued again.');

$preview = Correction::preview($pdo, $database, 24, 'generator_to_warehouse', 0, 2);
$pdo->exec("UPDATE feo SET mass_netto='1.2000' WHERE zayavka_id=101");
correctionRejected(static fn() => Correction::apply($pdo, $database, 24, 'generator_to_warehouse', 0, 2, $preview['fingerprint']), 'Stale preview must fail.');
checkCorrection((int)$pdo->query('SELECT COUNT(*) FROM warehouse_movements WHERE flight_id=24')->fetchColumn() === 0, 'Stale preview wrote nothing.');

$preview = Correction::preview($pdo, $database, 25, 'warehouse_to_warehouse', 2, 1);
checkCorrection($preview['ready'], 'Rollback preview.');
$pdo->exec("ALTER TABLE warehouse_movements ADD CONSTRAINT ck_late_failure CHECK (flight_id<>25 OR movement_type<>'transfer_in')");
correctionRejected(static fn() => Correction::apply($pdo, $database, 25, 'warehouse_to_warehouse', 2, 1, $preview['fingerprint']), 'Late second INSERT must roll back all changes.');
checkCorrection((int)$pdo->query('SELECT COUNT(*) FROM warehouse_movements WHERE flight_id=25')->fetchColumn() === 0, 'Partial transfer was not rolled back.');
checkCorrection((string)$pdo->query('SELECT route_type FROM flights WHERE id=25')->fetchColumn() === 'generator_to_utilizer', 'Flight update was not rolled back.');

echo "warehouse trip correction OK\n";
