<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/Core/Database.php';
require_once __DIR__ . '/../app/Support/company_database.php';
$policy = (string)file_get_contents(__DIR__ . '/../app/Support/runtime_migration_policy.php');
if (!str_contains($policy, 'if (!$isCli && !$isExplicitTenantProvisioning)')
    || !str_contains($policy, 'return;')) {
    throw new RuntimeException('Normal HTTP migration no-op policy changed');
}
// Model the already-loaded HTTP policy; CLI maintenance remains separate.
function applyLocalMigrations(PDO $pdo): void {}
foreach (['ClientService','ClientContactService','ContractorService','ContractorContactService','DriverService','VehicleSetService'] as $service) {
    $source = (string)file_get_contents(__DIR__ . '/../app/Service/' . $service . '.php');
    if (preg_match('/\b(?:CREATE TABLE|ALTER TABLE|RENAME TABLE|DROP TABLE)\b|->exec\s*\(/i', $source)) {
        throw new RuntimeException("Request service has a schema-write path: $service");
    }
    require_once __DIR__ . '/../app/Service/' . $service . '.php';
}
foreach (['ContractorActions/add_crew_form.php','ContractorActions/add_crew_submit.php',
    'ContractorActions/create_full_form.php','ContractorActions/create_full_submit.php',
    'ContractorActions/archive.php','ContractorActions/modal_archive.php',
    'VehicleSetActions/create_submit.php'] as $action) {
    $source = (string)file_get_contents(__DIR__ . '/../app/Http/Controllers/Company/' . $action);
    if (preg_match('/\b(?:CREATE TABLE|ALTER TABLE|RENAME TABLE|DROP TABLE)\b|->exec\s*\(/i', $source)) {
        throw new RuntimeException("HTTP action has a schema-write path: $action");
    }
}

use App\Core\Database;
use App\Service\ClientContactService;
use App\Service\ClientService;
use App\Service\ContractorContactService;
use App\Service\ContractorService;
use App\Service\DriverService;
use App\Service\VehicleSetService;

$schema = (string)getenv('PLANEX_TEST_FIXED_DB');
if (getenv('APP_ENV') !== 'test' || !preg_match('/^planex_t_[a-f0-9]{24}$/D', $schema)
    || getenv('PLANEX_TEST_DB_LEASE') !== $schema
    || !in_array($schema, explode(',', (string)getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST')), true)) {
    throw new RuntimeException('Trusted disposable schema required');
}
$config = ['database' => [
    'host' => (string)getenv('PLANEX_SCHEMA_DB_HOST'),
    'port' => (string)getenv('PLANEX_SCHEMA_DB_PORT'),
    'database' => $schema,
    'username' => (string)getenv('PLANEX_SCHEMA_DB_USER'),
    'password' => (string)getenv('PLANEX_SCHEMA_DB_PASSWORD'),
]];
$pdo = (new Database($config['database']))->connection();
$company = ['db_identifier' => $schema];
$services = [
    new ClientService($config, new Database($config['database'])),
    new ContractorService($config, new Database($config['database'])),
    new DriverService($config, new Database($config['database'])),
    new VehicleSetService($config, new Database($config['database'])),
];
$checks = [
    static fn() => $services[0]->getLocalPdo($company),
    static fn() => $services[1]->getLocalPdo($company),
    static fn() => $services[2]->getLocalPdo($company),
    static fn() => $services[3]->getLocalPdo($company),
    static fn() => ClientContactService::ensureTable($pdo),
    static fn() => ContractorContactService::ensureTable($pdo),
    static fn() => $services[0]->ensureDocumentTables($pdo),
    static fn() => $services[1]->ensureDocumentTables($pdo),
    static fn() => $services[2]->ensureDocumentTables($pdo),
    static fn() => $services[3]->ensureDocumentTables($pdo),
    static fn() => $services[2]->checkDriverHasCrews($pdo, 1),
];
foreach ($checks as $index => $check) {
    try {
        $check();
        throw new RuntimeException("Missing schema did not fail closed: $index");
    } catch (PDOException $expected) {
        // Missing schema is an explicit error, never a reason to create it.
    }
}
$tables = (int)$pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn();
if ($tables !== 0) throw new RuntimeException("HTTP service created $tables table(s) in missing schema");

$pdo->exec('CREATE TABLE clients (id INT PRIMARY KEY, created_by_user_id INT NULL, created_by_role VARCHAR(20) NULL)');
$pdo->exec('CREATE TABLE contractors (id INT PRIMARY KEY, created_by_user_id INT NULL, created_by_role VARCHAR(20) NULL)');
$pdo->exec('CREATE TABLE drivers (id INT PRIMARY KEY, created_by_user_id INT NULL, created_by_role VARCHAR(20) NULL)');
$pdo->exec('CREATE TABLE vehicle_sets (id INT PRIMARY KEY, created_by_user_id INT NULL, created_by_role VARCHAR(20) NULL)');
$pdo->exec('CREATE TABLE vehicle_units (id INT PRIMARY KEY)');
$pdo->exec('CREATE TABLE entity_access_grants (id INT PRIMARY KEY)');
$pdo->exec('CREATE TABLE client_contacts (id INT PRIMARY KEY)');
$pdo->exec('CREATE TABLE contractor_contacts (id INT PRIMARY KEY)');
$pdo->exec('CREATE TABLE documents (id INT PRIMARY KEY, document_type_id INT NULL, created_by_user_id INT NULL, created_by_role VARCHAR(20) NULL)');
$pdo->exec('CREATE TABLE document_types (id INT PRIMARY KEY)');
$pdo->exec('CREATE TABLE crews (id INT PRIMARY KEY, deleted_at DATETIME NULL, driver_vehicle_block_id INT NULL)');
$pdo->exec('CREATE TABLE driver_vehicle_blocks (id INT PRIMARY KEY, driver_id INT NULL)');
$pdo->exec('CREATE TABLE crew_drivers (crew_id INT, driver_id INT)');
$pdo->exec('INSERT INTO clients VALUES (1,9,\'owner\')');
$before = (int)$pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn();
foreach (array_slice($checks, 0, 10) as $check) $check();
if ($services[2]->checkDriverHasCrews($pdo, 1)) throw new RuntimeException('Empty crew fixture returned true');
$after = (int)$pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn();
$row = $pdo->query('SELECT created_by_user_id, created_by_role FROM clients WHERE id=1')->fetch(PDO::FETCH_ASSOC);
if ($before !== $after || (int)$row['created_by_user_id'] !== 9 || $row['created_by_role'] !== 'owner') {
    throw new RuntimeException('Read-only service check changed schema or business data');
}
echo "SERVICE_HTTP_SCHEMA_READONLY_MYSQL=PASS checks=11\n";
