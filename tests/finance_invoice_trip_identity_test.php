<?php
declare(strict_types=1);

use App\Service\FinanceObligationService;

$root = dirname(__DIR__);
require_once $root . '/bootstrap/app.php';
require_once $root . '/app/Service/FinanceObligationService.php';

$host = getenv('PLANEX_SCHEMA_DB_HOST') ?: '127.0.0.1';
$port = (int)(getenv('PLANEX_SCHEMA_DB_PORT') ?: 33316);
$database = (string)(getenv('PLANEX_TEST_FIXED_DB') ?: '');
$user = (string)(getenv('PLANEX_SCHEMA_DB_USER') ?: '');
$password = (string)(getenv('PLANEX_SCHEMA_DB_PASSWORD') ?: '');
if ($database === '' || $user === '') {
    throw new RuntimeException('This test requires an isolated PLANEX test database.');
}

$pdo = new PDO("mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4", $user, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$pdo->exec('CREATE TABLE waste_trips (id INT UNSIGNED PRIMARY KEY, business_id BIGINT UNSIGNED NOT NULL)');
$pdo->exec('INSERT INTO waste_trips (id, business_id) VALUES (124, 231)');
$rows = [
    ['source_parent_type' => 'WASTE_TRIP', 'source_parent_id' => 124],
    ['source_parent_type' => 'LINEAR_ROUTE', 'source_parent_id' => 125],
];
FinanceObligationService::enrichParentLabels($pdo, $rows);
if ($rows[0]['trip_business_id'] !== 231 || $rows[0]['parent_label'] !== 'Перевозка отходов #231') {
    throw new RuntimeException('Invoice obligation must expose the visible waste trip ID separately from its internal ID.');
}
if ($rows[1]['trip_business_id'] !== null || $rows[1]['parent_label'] !== 'Рейс #125') {
    throw new RuntimeException('Linear route identity must remain unchanged.');
}
echo "FINANCE_INVOICE_TRIP_IDENTITY=PASS\n";
