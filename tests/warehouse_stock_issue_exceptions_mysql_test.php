<?php

require_once __DIR__.'/../app/Service/WarehouseStockService.php';

use App\Service\WarehouseStockService;

$database = (string)getenv('PLANEX_TEST_FIXED_DB');
if ($database === '' || !in_array($database, explode(',', (string)getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST')), true)) {
    throw new RuntimeException('Disposable database required.');
}
$pdo = new PDO(
    'mysql:host='.(getenv('PLANEX_SCHEMA_DB_HOST') ?: '127.0.0.1').';port='.(getenv('PLANEX_SCHEMA_DB_PORT') ?: '3306').';dbname='.$database.';charset=utf8mb4',
    getenv('PLANEX_SCHEMA_DB_USER') ?: 'planex_ci', getenv('PLANEX_SCHEMA_DB_PASSWORD') ?: 'planex_ci',
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]
);
$migration = file_get_contents(__DIR__.'/../database/migrations-local/156_warehouse_stock_issue_exceptions.sql');
if ($migration === false) throw new RuntimeException('Migration missing.');
$pdo->exec($migration);
$pdo->exec($migration);
$message = 'Новосибирск / списание: не списаны заявки 306474, 309026.';
$hash = WarehouseStockService::messageHash($message);
$pdo->prepare('INSERT INTO warehouse_stock_issue_exceptions
    (flight_id,message_hash,message_text,trip_name,warehouse_name,approved_by_user_id,approved_at)
    VALUES (?,?,?,?,?,?,CURRENT_TIMESTAMP(6))')->execute([269,$hash,$message,'Алтай +НСК 20тн','Новосибирск',7]);
$row = $pdo->query('SELECT * FROM warehouse_stock_issue_exceptions')->fetch();
if (!$row || $row['message_hash'] !== $hash || $row['message_text'] !== $message) throw new RuntimeException('Exception was not saved.');
$pdo->prepare('UPDATE warehouse_stock_issue_exceptions SET revoked_by_user_id=?,revoked_at=CURRENT_TIMESTAMP(6) WHERE id=? AND revoked_at IS NULL')->execute([8,$row['id']]);
if ($pdo->query('SELECT revoked_at FROM warehouse_stock_issue_exceptions')->fetchColumn() === null) throw new RuntimeException('Exception was not restored.');
echo "warehouse stock issue exceptions MySQL OK\n";
