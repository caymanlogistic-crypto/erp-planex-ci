<?php

declare(strict_types=1);

use Planex\Tools\RolesAccess\AccessSnapshotCollector;
use Planex\Tools\RolesAccess\MigrationProof;

require_once __DIR__ . '/../tools/roles_access/MigrationProof.php';
require_once __DIR__ . '/../tools/roles_access/AccessSnapshotCollector.php';

$central = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$tenant = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$central->exec('CREATE TABLE company_users (id INTEGER PRIMARY KEY,company_id INTEGER,full_name TEXT,login TEXT,email TEXT,phone TEXT,password_hash TEXT,role TEXT,status TEXT)');
$central->exec("INSERT INTO company_users VALUES (1,7,'Owner','owner',NULL,NULL,'hash-owner','company_owner','active'),(2,7,'Logist','logist',NULL,NULL,'hash-logist','logist','active')");
$tenant->exec('CREATE TABLE users (id INTEGER PRIMARY KEY,full_name TEXT,login TEXT,email TEXT,phone TEXT,password_hash TEXT,role_code TEXT,status TEXT,deleted_at TEXT,membership_id INTEGER)');
$tenant->exec("INSERT INTO users VALUES (1,'Logist','logist',NULL,NULL,'hash-logist','logist','active',NULL,NULL)");
$tenant->exec('CREATE TABLE entity_access_grants (id INTEGER PRIMARY KEY,entity_type TEXT,entity_id INTEGER,granted_to_user_id INTEGER,access_level TEXT,revoked_at TEXT)');
foreach (['clients', 'contractors', 'vehicle_units', 'drivers', 'linear_routes', 'waste_trips'] as $table) {
    $tenant->exec("CREATE TABLE {$table} (id INTEGER PRIMARY KEY,created_by_user_id INTEGER,created_by_membership_id INTEGER)");
    $tenant->exec("INSERT INTO {$table} VALUES (1,1,NULL),(2,2,NULL)");
}
$tenant->exec('CREATE TABLE vehicle_sets (id INTEGER PRIMARY KEY,primary_vehicle_unit_id INTEGER,secondary_vehicle_unit_id INTEGER,created_by_user_id INTEGER,created_by_membership_id INTEGER)');
$tenant->exec('INSERT INTO vehicle_sets VALUES (1,1,NULL,1,NULL),(2,2,NULL,2,NULL)');
$tenant->exec("INSERT INTO entity_access_grants VALUES (1,'client',2,1,'view',NULL),(2,'linear_route',2,1,'view',NULL),(3,'vehicle_set',2,1,'edit',NULL)");

$head = str_repeat('a', 40);
$pages = [
    'company_owner' => ['company.dashboard', 'company.clients'],
    'logist' => ['company.dashboard', 'company.clients'],
];
$before = AccessSnapshotCollector::before($central, $tenant, 7, $head, $pages);
if (count($before['memberships']) !== 2) {
    throw new RuntimeException('BEFORE collector missed mirrored factual memberships');
}
$logistBefore = $before['memberships'][1];
if ($logistBefore['clients'] !== ['1', '2']
    || $logistBefore['contractors'] !== ['1']
    || $logistBefore['vehicle_sets'] !== ['1', '2']
    || $logistBefore['linear_trips'] !== ['1', '2']) {
    throw new RuntimeException('BEFORE collector did not reproduce creator/reference/route/set grant visibility');
}

$central->exec('CREATE TABLE company_roles (id INTEGER PRIMARY KEY,company_id INTEGER,system_key TEXT)');
$central->exec('CREATE TABLE company_memberships (id INTEGER PRIMARY KEY,company_id INTEGER,role_id INTEGER,status TEXT,legacy_source TEXT,legacy_source_id INTEGER)');
$central->exec('CREATE TABLE role_page_permissions (role_id INTEGER,page_key TEXT,is_allowed INTEGER)');
$central->exec('CREATE TABLE membership_page_overrides (membership_id INTEGER,page_key TEXT,effect TEXT)');
$central->exec('CREATE TABLE role_data_scopes (role_id INTEGER,entity_type TEXT,scope TEXT)');
$central->exec('CREATE TABLE membership_data_scope_overrides (membership_id INTEGER,entity_type TEXT,scope TEXT)');
$central->exec("INSERT INTO company_roles VALUES (10,7,'company_owner'),(11,7,NULL)");
$central->exec("INSERT INTO company_memberships VALUES (100,7,10,'active','central_company_user',1),(101,7,11,'active','central_company_user',2)");
$central->exec("INSERT INTO role_page_permissions VALUES (11,'company.dashboard',1),(11,'company.clients',1)");
foreach (['client', 'contractor', 'vehicle_unit', 'driver', 'linear_trip', 'waste_trip'] as $entityType) {
    $central->exec("INSERT INTO role_data_scopes VALUES (10,'{$entityType}','all')");
    $central->exec("INSERT INTO role_data_scopes VALUES (11,'{$entityType}','own')");
}
$tenant->exec('CREATE TABLE entity_assignments (entity_type TEXT,entity_id INTEGER,membership_id INTEGER)');
$tenant->exec('CREATE TABLE legacy_access_projections (legacy_grant_id INTEGER,entity_type TEXT,entity_id INTEGER,membership_id INTEGER,access_level TEXT)');
foreach (['clients', 'contractors', 'vehicle_units', 'vehicle_sets', 'drivers', 'linear_routes', 'waste_trips'] as $table) {
    $tenant->exec("UPDATE {$table} SET created_by_membership_id=101 WHERE created_by_user_id=1");
}
$tenant->exec("INSERT INTO entity_assignments VALUES ('client',2,101)");
$tenant->exec("INSERT INTO legacy_access_projections VALUES (2,'linear_trip',2,101,'view'),(3,'vehicle_set',2,101,'edit')");

$after = AccessSnapshotCollector::after($central, $tenant, 7, $head, ['company.dashboard', 'company.clients']);
$comparison = MigrationProof::compare($before, $after);
if (!$comparison['equal'] || !$comparison['accepted']) {
    throw new RuntimeException('Automatic BEFORE/AFTER collector parity failed: ' . json_encode($comparison));
}

echo "roles access snapshot collector: PASS\n";
