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
$pdo->exec('CREATE TABLE warehouses(id BIGINT UNSIGNED PRIMARY KEY,name VARCHAR(255),full_address VARCHAR(500),latitude DECIMAL(10,7) NULL,longitude DECIMAL(10,7) NULL) ENGINE=InnoDB');
$pdo->exec("INSERT INTO warehouses VALUES(7,'Склад Север','Москва, Складская, 1',55.7500000,37.6200000),(8,'Склад Юг','Тула, Южная, 2',NULL,NULL)");
$migration = file_get_contents(__DIR__.'/../database/migrations-legacy/001_warehouse_contacts.sql');
if ($migration === false) throw new RuntimeException('Migration missing.');
$pdo->exec($migration);
$beforeNewCoordinates = Directory::list($pdo);
if ($beforeNewCoordinates[0]['latitude'] !== '55.75' || $beforeNewCoordinates[0]['longitude'] !== '37.62') {
    throw new RuntimeException('Existing warehouse coordinates were not read before the contact-table migration.');
}
$pdo->exec("INSERT INTO warehouse_contacts (warehouse_id,contacts_json,updated_by_user_id,updated_at) VALUES (8,JSON_ARRAY(),12,CURRENT_TIMESTAMP(6))");
$pdo->exec(file_get_contents(__DIR__.'/../database/migrations-legacy/002_warehouse_coordinates.sql'));
$pdo->exec("UPDATE warehouse_contacts SET latitude=1,longitude=2 WHERE warehouse_id=8");
if (Directory::list($pdo)[1]['latitude'] !== null) throw new RuntimeException('Deprecated contact coordinates leaked into warehouse details.');
$contacts = [
    ['name'=>'Иванов Иван','position'=>'Заведующий','phone'=>'+7 900 000-00-01','email'=>'ivanov@example.test'],
    ['name'=>'Петров Пётр','position'=>'Приёмщик','phone'=>'+7 900 000-00-02','email'=>''],
];
$emptyHash = hash('sha256', '[]');
$initialCoordinatesHash = Directory::coordinatesHash('55.75', '37.62');
$savedCoordinatesHash = Directory::coordinatesHash('55.8', '37.7');
$savedHash = hash('sha256', json_encode($contacts, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
Directory::save($pdo, $pdo, $database, 7, 'Москва, Складская, 1', $emptyHash, $initialCoordinatesHash,
    'Москва, Складская, 3', '55,8', '37.7', $contacts, 12);
$rows = Directory::list($pdo);
if (count($rows) !== 2 || $rows[0]['address'] !== 'Москва, Складская, 3' || count($rows[0]['contacts']) !== 2
    || $rows[0]['latitude'] !== '55.8' || $rows[0]['longitude'] !== '37.7') throw new RuntimeException('Saved directory mismatch.');
if ((int)$pdo->query('SELECT COUNT(*) FROM warehouses WHERE id=7 AND full_address="Москва, Складская, 3" AND latitude=55.8 AND longitude=37.7')->fetchColumn() !== 1
    || (int)$pdo->query('SELECT COUNT(*) FROM warehouse_contacts WHERE warehouse_id=7 AND latitude IS NULL AND longitude IS NULL')->fetchColumn() !== 1) {
    throw new RuntimeException('Address and coordinates must share warehouses; contacts must not receive coordinates.');
}
$rejected = static function (callable $callback): void {
    try { $callback(); } catch (RuntimeException) { return; }
    throw new RuntimeException('Invalid save was accepted.');
};
$rejected(static fn() => Directory::save($pdo, $pdo, $database, 7, 'Москва, Складская, 1', $savedHash, $savedCoordinatesHash, 'Неверный адрес', '55.75', '37.62', $contacts, 12));
$rejected(static fn() => Directory::save($pdo, $pdo, $database, 7, 'Москва, Складская, 3', $emptyHash, $savedCoordinatesHash, 'Неверный адрес', '55.75', '37.62', $contacts, 12));
$rejected(static fn() => Directory::save($pdo, $pdo, $database, 7, 'Москва, Складская, 3', $savedHash, Directory::coordinatesHash(null, null), 'Неверный адрес', '55.75', '37.62', $contacts, 12));
$rejected(static fn() => Directory::save($pdo, $pdo, $database, 7, 'Москва, Складская, 3', $savedHash, $savedCoordinatesHash, 'Неверный адрес', '91', '37.62', $contacts, 12));
$rejected(static fn() => Directory::save($pdo, $pdo, $database, 7, 'Москва, Складская, 3', $savedHash, $savedCoordinatesHash, 'Неверный адрес', '55.75', '', $contacts, 12));
$rejected(static fn() => Directory::save($pdo, $pdo, $database, 7, 'Москва, Складская, 3', $savedHash, $savedCoordinatesHash, 'Неверный адрес', '55.12345678', '37.62', $contacts, 12));
$rejected(static fn() => Directory::save($pdo, $pdo, $database, 7, 'Москва, Складская, 3', $savedHash, $savedCoordinatesHash, 'Неверный адрес', '55.75', '37.62', [['name'=>'Без телефона','position'=>'','phone'=>'','email'=>'']], 12));
$rejected(static fn() => Directory::save($pdo, $pdo, $database, 999, '', $emptyHash, Directory::coordinatesHash(null, null), 'Несуществующий склад', null, null, [], 12));
if (Directory::list($pdo)[0]['address'] !== 'Москва, Складская, 3') throw new RuntimeException('Rejected save changed address.');
$pdo->query("EXPLAIN INSERT INTO warehouse_contacts (warehouse_id,contacts_json,updated_by_user_id,updated_at) VALUES (0,JSON_ARRAY(),0,CURRENT_TIMESTAMP(6))");
$pdo->query("EXPLAIN UPDATE warehouse_contacts SET contacts_json=JSON_ARRAY() WHERE warehouse_id=0");
$pdo->query("EXPLAIN UPDATE warehouses SET full_address='Тест',latitude=55.75,longitude=37.62 WHERE id=0");
$pdo->exec('DROP TABLE warehouse_contacts');
$pdo->exec('DROP TABLE warehouses');
echo "warehouse directory MySQL OK\n";
