<?php
declare(strict_types=1);

$schema = (string) getenv('PLANEX_TEST_FIXED_DB');
if (getenv('APP_ENV') !== 'test' || !preg_match('/^planex_t_[a-f0-9]{24}$/D', $schema)
    || getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST') !== $schema) {
    throw new RuntimeException('Unique disposable test schema required');
}
define('PLANEX_LEGACY_HTTP_SCHEMA_PROBE_LIBRARY_ONLY', true);
require dirname(__DIR__).'/scripts/legacy_http_schema_readiness_probe.php';

$pdo = new PDO('mysql:host='.getenv('PLANEX_SCHEMA_DB_HOST').';port='.getenv('PLANEX_SCHEMA_DB_PORT').';dbname='.$schema.';charset=utf8mb4',
    getenv('PLANEX_SCHEMA_DB_USER'), getenv('PLANEX_SCHEMA_DB_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};

$absent = LegacyHttpSchemaReadinessProbe::inspect($pdo, 'logists');
$assert(!$absent['ready'] && count($absent['missing_migrations']) === 4
    && in_array('users.id', $absent['missing_columns'], true), 'Absent Logists schema must fail closed');

$pdo->exec('CREATE TABLE schema_migrations (migration VARCHAR(255) PRIMARY KEY)');
$tables = [
    'users' => 'id INT, created_by_user_id INT, created_by_role VARCHAR(20), position VARCHAR(255)',
    'clients' => 'id INT, created_by_user_id INT, created_by_role VARCHAR(20)',
    'contractors' => 'id INT, created_by_user_id INT, created_by_role VARCHAR(20)',
    'drivers' => 'id INT, created_by_user_id INT, created_by_role VARCHAR(20)',
    'vehicle_units' => 'id INT, created_by_user_id INT, created_by_role VARCHAR(20)',
    'crews' => 'id INT, created_by_user_id INT, created_by_role VARCHAR(20)',
    'documents' => 'id INT, document_type_id INT, created_by_user_id INT, created_by_role VARCHAR(20)',
    'entity_access_grants' => 'id INT, entity_type VARCHAR(20), entity_id INT, granted_to_user_id INT',
    'contractor_contacts' => 'id INT, contractor_id INT',
    'client_contacts' => 'id INT, client_id INT',
    'vehicle_sets' => 'id INT, primary_vehicle_unit_id INT, created_by_user_id INT, created_by_role VARCHAR(20)',
    'driver_vehicle_blocks' => 'id INT, driver_id INT, vehicle_set_id INT',
    'document_types' => 'id INT, name VARCHAR(100), entity_type VARCHAR(20)',
    'linear_route_financial_terms' => 'id INT, payment_due_type VARCHAR(100) NULL',
    'linear_route_payments' => 'id INT, payment_due_type VARCHAR(100) NULL',
];
foreach ($tables as $table => $definition) $pdo->exec("CREATE TABLE `$table` ($definition)");
$migrations = [
    '001_create_company_users.sql', '002_create_company_clients.sql', '003_create_company_contractors.sql',
    '004_create_company_drivers.sql', '005_create_company_vehicles.sql', '006_create_company_crews.sql',
    '007_create_company_documents.sql', '008_add_ownership_columns.sql', '009_create_entity_access_grants.sql',
    '010_add_position_to_users.sql', '012_create_contractor_contacts.sql',
    '016_rename_vehicles_to_vehicle_units.sql', '017_create_vehicle_sets.sql',
    '018_create_driver_vehicle_blocks.sql', '024_create_document_types.sql', '025_add_document_type_id.sql',
    '031_create_client_contacts.sql', '040_create_linear_routes.sql',
    '041_linear_route_repeatable_principals_payments.sql',
    '059_relax_legacy_linear_route_payment_terms.sql', '060_relax_linear_route_payment_legacy_due_fields.sql',
    '061_force_relax_all_legacy_route_due_fields.sql',
];
$insert = $pdo->prepare('INSERT INTO schema_migrations (migration) VALUES (?)');
foreach ($migrations as $migration) $insert->execute([$migration]);

foreach (['logists', 'clients', 'transport', 'linear'] as $scope) {
    $ready = LegacyHttpSchemaReadinessProbe::inspect($pdo, $scope);
    $assert($ready === ['ready' => true, 'missing_migrations' => [], 'missing_columns' => [], 'non_nullable_columns' => []],
        "$scope journal and runtime columns must pass");
}

$pdo->exec("DELETE FROM schema_migrations WHERE migration='016_rename_vehicles_to_vehicle_units.sql'");
$unlogged = LegacyHttpSchemaReadinessProbe::inspect($pdo, 'transport');
$assert(!$unlogged['ready'] && $unlogged['missing_migrations'] === ['016_rename_vehicles_to_vehicle_units.sql'],
    'Vehicle rename without journal must fail closed');
$insert->execute(['016_rename_vehicles_to_vehicle_units.sql']);

$pdo->exec('ALTER TABLE crews DROP COLUMN created_by_role');
$drifted = LegacyHttpSchemaReadinessProbe::inspect($pdo, 'transport');
$assert(!$drifted['ready'] && in_array('crews.created_by_role', $drifted['missing_columns'], true),
    'Missing ownership column must fail closed');
$pdo->exec('ALTER TABLE crews ADD COLUMN created_by_role VARCHAR(20) NULL');

$pdo->exec('ALTER TABLE linear_route_payments MODIFY COLUMN payment_due_type VARCHAR(100) NOT NULL');
$notNullable = LegacyHttpSchemaReadinessProbe::inspect($pdo, 'linear');
$assert(!$notNullable['ready'] && $notNullable['non_nullable_columns'] === ['linear_route_payments.payment_due_type'],
    'Request-time ALTER prerequisite must fail closed');

echo "LEGACY_HTTP_SCHEMA_READINESS_MYSQL=PASS scopes=4 absent=REJECTED ready=PASS unjournaled=REJECTED drifted=REJECTED nullable=REJECTED\n";
