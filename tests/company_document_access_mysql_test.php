<?php

declare(strict_types=1);

use App\Core\Database;
use App\Security\AccessRuntime;
use App\Security\EffectiveAccess;

$config = require dirname(__DIR__) . '/bootstrap/app.php';
require_once base_path('app/Support/entrypoint_dependencies.php');
$db = new Database($config['database']);
$router = new class {
    public function get(...$args): void {}
    public function post(...$args): void {}
};
require base_path('app/Http/Routes/company_vehicle_sets.php');
require base_path('app/Http/Routes/company_documents.php');

$dbName = trim((string) getenv('PLANEX_TEST_FIXED_DB'));
$allowlist = array_filter(array_map('trim', explode(',', (string) getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST'))));
if ($dbName === '' || !in_array($dbName, $allowlist, true)) {
    throw new RuntimeException('A guarded disposable test database is required.');
}
$pdo = new PDO(
    'mysql:host=' . (getenv('PLANEX_SCHEMA_DB_HOST') ?: '127.0.0.1')
        . ';port=' . (getenv('PLANEX_SCHEMA_DB_PORT') ?: '3306')
        . ';dbname=' . $dbName . ';charset=utf8mb4',
    getenv('PLANEX_SCHEMA_DB_USER') ?: '',
    getenv('PLANEX_SCHEMA_DB_PASSWORD') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
);

$pdo->exec('CREATE TABLE vehicle_units (id INT PRIMARY KEY, created_by_membership_id INT NULL)');
$pdo->exec('CREATE TABLE vehicle_sets (id INT PRIMARY KEY, primary_vehicle_unit_id INT NULL, secondary_vehicle_unit_id INT NULL, created_by_membership_id INT NULL)');
$pdo->exec('CREATE TABLE contractors (id INT PRIMARY KEY, created_by_membership_id INT NULL)');
$pdo->exec('CREATE TABLE drivers (id INT PRIMARY KEY, created_by_membership_id INT NULL)');
$pdo->exec('CREATE TABLE entity_responsibilities (entity_type VARCHAR(30), entity_id INT, membership_id INT)');
$pdo->exec('CREATE TABLE entity_assignments (entity_type VARCHAR(30), entity_id INT, membership_id INT, assignment_kind VARCHAR(30))');
$pdo->exec('CREATE TABLE legacy_access_projections (entity_type VARCHAR(30), entity_id INT, membership_id INT, access_level VARCHAR(10))');
$pdo->exec('INSERT INTO vehicle_units VALUES (11, 7), (12, 7), (13, 7)');
$pdo->exec('INSERT INTO vehicle_sets VALUES (21, 11, 12, 7), (22, 13, NULL, 7)');
$pdo->exec('INSERT INTO contractors VALUES (31, 7)');
$pdo->exec('INSERT INTO drivers VALUES (41, 7)');
$pdo->exec("INSERT INTO legacy_access_projections VALUES ('vehicle_set', 21, 8, 'view')");

$check = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$check(documentEntityPageKeys('contractor') === ['company.contractors'], 'contractor page');
$check(documentEntityPageKeys('driver') === ['company.drivers'], 'driver page');
$check(documentEntityPageKeys('vehicle_unit') === ['company.vehicle_sets'], 'vehicle page');

AccessRuntime::setCurrent(EffectiveAccess::member(8, 1, ['company.vehicle_sets' => true], [
    'vehicle_unit' => 'own', 'contractor' => 'none', 'driver' => 'none',
]));
$check(canAccessDocumentEntity($pdo, 'vehicle_unit', 11), 'direct set grant must allow primary unit document');
$check(canAccessDocumentEntity($pdo, 'vehicle_unit', 12), 'direct set grant must allow secondary unit document');
$check(!canAccessDocumentEntity($pdo, 'vehicle_unit', 13), 'unrelated vehicle document leaked');
$check(!canAccessDocumentEntity($pdo, 'contractor', 31), 'contractor scope NONE leaked');
$check(!canAccessDocumentEntity($pdo, 'driver', 41), 'driver scope NONE leaked');

AccessRuntime::setCurrent(EffectiveAccess::member(8, 1, [
    'company.contractors' => true, 'company.drivers' => true, 'company.vehicle_sets' => true,
], ['vehicle_unit' => 'all', 'contractor' => 'all', 'driver' => 'all']));
$check(canAccessDocumentEntity($pdo, 'vehicle_unit', 13), 'vehicle scope ALL should allow document');
$check(canAccessDocumentEntity($pdo, 'contractor', 31), 'contractor scope ALL should allow document');
$check(canAccessDocumentEntity($pdo, 'driver', 41), 'driver scope ALL should allow document');

AccessRuntime::setCurrent(EffectiveAccess::member(8, 1, ['company.vehicle_sets' => true], [
    'vehicle_unit' => 'none', 'contractor' => 'none', 'driver' => 'none',
]));
$check(!canAccessDocumentEntity($pdo, 'vehicle_unit', 11), 'vehicle scope NONE leaked direct set document');
echo "COMPANY_DOCUMENT_ACCESS=PASS\n";
