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
$contacts = [
    ['name'=>'Иванов Иван','position'=>'Заведующий','phone'=>'+7 900 000-00-01','email'=>'ivanov@example.test'],
    ['name'=>'Петров Пётр','position'=>'Приёмщик','phone'=>'+7 900 000-00-02','email'=>''],
];
$emptyHash = hash('sha256', '[]');
$savedHash = hash('sha256', json_encode($contacts, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
Directory::save($pdo, $pdo, $database, 7, 'Москва, Складская, 1', $emptyHash, 'Москва, Складская, 3', $contacts, 12);
$rows = Directory::list($pdo);
if (count($rows) !== 2 || $rows[0]['address'] !== 'Москва, Складская, 3' || count($rows[0]['contacts']) !== 2) throw new RuntimeException('Saved directory mismatch.');
$rejected = static function (callable $callback): void {
    try { $callback(); } catch (RuntimeException) { return; }
    throw new RuntimeException('Invalid save was accepted.');
};
$rejected(static fn() => Directory::save($pdo, $pdo, $database, 7, 'Москва, Складская, 1', $savedHash, 'Неверный адрес', $contacts, 12));
$rejected(static fn() => Directory::save($pdo, $pdo, $database, 7, 'Москва, Складская, 3', $emptyHash, 'Неверный адрес', $contacts, 12));
$rejected(static fn() => Directory::save($pdo, $pdo, $database, 7, 'Москва, Складская, 3', $savedHash, 'Неверный адрес', [['name'=>'Без телефона','position'=>'','phone'=>'','email'=>'']], 12));
$rejected(static fn() => Directory::save($pdo, $pdo, $database, 999, '', $emptyHash, 'Несуществующий склад', [], 12));
if (Directory::list($pdo)[0]['address'] !== 'Москва, Складская, 3') throw new RuntimeException('Rejected save changed address.');
echo "warehouse directory MySQL OK\n";
