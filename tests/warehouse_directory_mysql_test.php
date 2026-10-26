<?php

require_once __DIR__.'/../app/Service/WarehouseDirectoryService.php';

use App\Service\WarehouseDirectoryService as Directory;

$database = (string)getenv('PLANEX_TEST_FIXED_DB');
if ($database === '' || !in_array($database, explode(',', (string)getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST')), true)) {
    throw new RuntimeException('Disposable database required.');
}
$pdo = new PDO(
    'mysql:host='.(getenv('PLANEX_SCHEMA_DB_HOST') ?: '127.0.0.1').';port='.(getenv('PLANEX_SCHEMA_DB_PORT') ?: '3306').';dbname='.$database.';charset=utf8mb4',
    getenv('PLANEX_SCHEMA_DB_USER') ?: 'planex_ci', getenv('PLANEX_SCHEMA_DB_PASSWORD') ?: 'planex_ci',
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]
);
$pdo->exec('CREATE TABLE warehouses(id BIGINT UNSIGNED PRIMARY KEY,name VARCHAR(255),full_address VARCHAR(500)) ENGINE=InnoDB');
$pdo->exec("INSERT INTO warehouses VALUES(7,'Склад Север','Москва, Складская, 1'),(8,'Склад Юг','Тула, Южная, 2')");
$migration = file_get_contents(__DIR__.'/../database/migrations-legacy/001_warehouse_contacts.sql');
if ($migration === false) throw new RuntimeException('Migration missing.');
$pdo->exec($migration);
$preMigration = Directory::list($pdo);
if ($preMigration[0]['latitude'] !== null || $preMigration[0]['longitude'] !== null) throw new RuntimeException('Pre-migration coordinate fallback failed.');
$migrationRejected = false;
try {
    Directory::save($pdo, $pdo, $database, 7, 'Москва, Складская, 1', hash('sha256', '[]'),
        Directory::coordinatesHash(null, null), 'Москва, Складская, 1', '55.75', '37.62', [], 12);
} catch (RuntimeException) { $migrationRejected = true; }
if (!$migrationRejected) throw new RuntimeException('Coordinates accepted without schema migration.');
$pdo->exec("INSERT INTO warehouse_contacts (warehouse_id,contacts_json,updated_by_user_id,updated_at) VALUES (8,JSON_ARRAY(),12,CURRENT_TIMESTAMP(6))");
$pdo->exec(file_get_contents(__DIR__.'/../database/migrations-legacy/002_warehouse_coordinates.sql'));
if ((int)$pdo->query('SELECT COUNT(*) FROM warehouse_contacts WHERE warehouse_id=8 AND contacts_json=JSON_ARRAY() AND latitude IS NULL AND longitude IS NULL')->fetchColumn() !== 1) {
    throw new RuntimeException('Migration changed an existing contact record.');
}
$constraintRejected = false;
try {
    $pdo->exec("INSERT INTO warehouse_contacts (warehouse_id,contacts_json,updated_by_user_id,updated_at,latitude,longitude) VALUES (99,JSON_ARRAY(),12,CURRENT_TIMESTAMP(6),55.75,NULL)");
} catch (PDOException) { $constraintRejected = true; }
if (!$constraintRejected) throw new RuntimeException('Partial coordinates passed the database constraint.');
$contacts = [
    ['name'=>'Иванов Иван','position'=>'Заведующий','phone'=>'+7 900 000-00-01','email'=>'ivanov@example.test'],
    ['name'=>'Петров Пётр','position'=>'Приёмщик','phone'=>'+7 900 000-00-02','email'=>''],
];
$emptyHash = hash('sha256', '[]');
$emptyCoordinatesHash = Directory::coordinatesHash(null, null);
$savedCoordinatesHash = Directory::coordinatesHash('55.75', '37.62');
$savedHash = hash('sha256', json_encode($contacts, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
Directory::save($pdo, $pdo, $database, 7, 'Москва, Складская, 1', $emptyHash, $emptyCoordinatesHash,
    'Москва, Складская, 3', '55,75', '37.62', $contacts, 12);
$rows = Directory::list($pdo);
if (count($rows) !== 2 || $rows[0]['address'] !== 'Москва, Складская, 3' || count($rows[0]['contacts']) !== 2
    || $rows[0]['latitude'] !== '55.75' || $rows[0]['longitude'] !== '37.62') throw new RuntimeException('Saved directory mismatch.');
$rejected = static function (callable $callback): void {
    try { $callback(); } catch (RuntimeException) { return; }
    throw new RuntimeException('Invalid save was accepted.');
};
$rejected(static fn() => Directory::save($pdo, $pdo, $database, 7, 'Москва, Складская, 1', $savedHash, $savedCoordinatesHash, 'Неверный адрес', '55.75', '37.62', $contacts, 12));
$rejected(static fn() => Directory::save($pdo, $pdo, $database, 7, 'Москва, Складская, 3', $emptyHash, $savedCoordinatesHash, 'Неверный адрес', '55.75', '37.62', $contacts, 12));
$rejected(static fn() => Directory::save($pdo, $pdo, $database, 7, 'Москва, Складская, 3', $savedHash, $emptyCoordinatesHash, 'Неверный адрес', '55.75', '37.62', $contacts, 12));
$rejected(static fn() => Directory::save($pdo, $pdo, $database, 7, 'Москва, Складская, 3', $savedHash, $savedCoordinatesHash, 'Неверный адрес', '91', '37.62', $contacts, 12));
$rejected(static fn() => Directory::save($pdo, $pdo, $database, 7, 'Москва, Складская, 3', $savedHash, $savedCoordinatesHash, 'Неверный адрес', '55.75', '', $contacts, 12));
$rejected(static fn() => Directory::save($pdo, $pdo, $database, 7, 'Москва, Складская, 3', $savedHash, $savedCoordinatesHash, 'Неверный адрес', '55.12345678', '37.62', $contacts, 12));
$rejected(static fn() => Directory::save($pdo, $pdo, $database, 7, 'Москва, Складская, 3', $savedHash, $savedCoordinatesHash, 'Неверный адрес', '55.75', '37.62', [['name'=>'Без телефона','position'=>'','phone'=>'','email'=>'']], 12));
$rejected(static fn() => Directory::save($pdo, $pdo, $database, 999, '', $emptyHash, $emptyCoordinatesHash, 'Несуществующий склад', null, null, [], 12));
if (Directory::list($pdo)[0]['address'] !== 'Москва, Складская, 3') throw new RuntimeException('Rejected save changed address.');
$pdo->query("EXPLAIN INSERT INTO warehouse_contacts (warehouse_id,contacts_json,updated_by_user_id,updated_at,latitude,longitude) VALUES (0,JSON_ARRAY(),0,CURRENT_TIMESTAMP(6),NULL,NULL)");
$pdo->query("EXPLAIN UPDATE warehouse_contacts SET contacts_json=JSON_ARRAY(),latitude=NULL,longitude=NULL WHERE warehouse_id=0");
$pdo->exec('DROP TABLE warehouse_contacts');
$pdo->exec('DROP TABLE warehouses');
echo "warehouse directory MySQL OK\n";
