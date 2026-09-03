<?php

declare(strict_types=1);

use App\Service\CompanyAssignmentService;
use App\Service\DataScopeService;

require_once dirname(__DIR__) . '/app/Service/CompanyAssignmentService.php';
require_once dirname(__DIR__) . '/app/Service/DataScopeService.php';

$host = getenv('PLANEX_SCHEMA_DB_HOST') ?: '127.0.0.1';
$port = (int) (getenv('PLANEX_SCHEMA_DB_PORT') ?: 3306);
$user = getenv('PLANEX_SCHEMA_DB_USER') ?: 'planex_ci';
$pass = getenv('PLANEX_SCHEMA_DB_PASSWORD') ?: 'planex_ci';
$admin = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$dbName = 'planex_scope_' . getmypid() . '_' . bin2hex(random_bytes(3));
$quoted = '`' . str_replace('`', '``', $dbName) . '`';
$admin->exec("CREATE DATABASE {$quoted} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

$assert = static function (bool $ok, string $message): void {
    if (!$ok) {
        throw new RuntimeException($message);
    }
};
$expectThrow = static function (callable $fn, string $message) use ($assert): void {
    try {
        $fn();
    } catch (RuntimeException) {
        return;
    }
    $assert(false, $message);
};

try {
    $pdo = new PDO("mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $pdo->exec("
        CREATE TABLE company_user_assignments (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            entity_type VARCHAR(32) NOT NULL,
            entity_id BIGINT UNSIGNED NOT NULL,
            membership_id BIGINT UNSIGNED NOT NULL,
            assigned_by_membership_id BIGINT UNSIGNED NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_company_user_assignment (entity_type, entity_id, membership_id),
            KEY idx_company_user_assignment_membership (membership_id, entity_type, entity_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        CREATE TABLE clients (id INT UNSIGNED PRIMARY KEY, created_by_user_id INT UNSIGNED NULL);
        CREATE TABLE contractors (id INT UNSIGNED PRIMARY KEY, created_by_user_id INT UNSIGNED NULL);
        CREATE TABLE vehicle_units (id INT UNSIGNED PRIMARY KEY, created_by_user_id INT UNSIGNED NULL);
        CREATE TABLE drivers (id INT UNSIGNED PRIMARY KEY, created_by_user_id INT UNSIGNED NULL);
        CREATE TABLE linear_routes (id INT UNSIGNED PRIMARY KEY, created_by_user_id INT UNSIGNED NULL);
        CREATE TABLE waste_trips (id INT UNSIGNED PRIMARY KEY, created_by_user_id INT UNSIGNED NULL);
    ");
    $pdo->exec("INSERT INTO clients VALUES (1,10),(2,11); INSERT INTO contractors VALUES (1,10); INSERT INTO vehicle_units VALUES (1,10),(2,10); INSERT INTO drivers VALUES (1,10); INSERT INTO linear_routes VALUES (1,10),(2,11); INSERT INTO waste_trips VALUES (1,10),(2,11);");

    $leader = ['allowed' => true, 'is_leader' => true, 'is_superadmin' => false, 'membership_id' => 100, 'tenant_user_id' => 10];
    $leader2 = ['allowed' => true, 'is_leader' => true, 'is_superadmin' => false, 'membership_id' => 101, 'tenant_user_id' => 11];
    $member = ['allowed' => true, 'is_leader' => false, 'is_superadmin' => false, 'membership_id' => 200, 'tenant_user_id' => 20];
    $member2 = ['allowed' => true, 'is_leader' => false, 'is_superadmin' => false, 'membership_id' => 201, 'tenant_user_id' => 21];
    $superadmin = ['allowed' => true, 'is_leader' => false, 'is_superadmin' => true, 'membership_id' => null, 'tenant_user_id' => null];

    // Manual mutation is leader-only.
    $expectThrow(
        static fn() => CompanyAssignmentService::assign($pdo, 'client', 1, 200, $member),
        'ordinary member must not mutate assignments'
    );

    CompanyAssignmentService::assign($pdo, 'contractor', 1, 200, $leader);
    CompanyAssignmentService::assign($pdo, 'contractor', 1, 201, $leader);
    $assert(CompanyAssignmentService::assignedMembershipIds($pdo, 'contractor', 1) === [200, 201], 'contractor assignment must be many-to-many');

    // Current assignment, not creator, is ownership. Transfer is explicit and independent.
    CompanyAssignmentService::assign($pdo, 'client', 1, 200, $leader);
    $assert(CompanyAssignmentService::isAssigned($pdo, 'client', 1, 200), 'initial client assignment missing');
    CompanyAssignmentService::assign($pdo, 'client', 1, 201, $leader);
    CompanyAssignmentService::unassign($pdo, 'client', 1, 200, $leader);
    $assert(!CompanyAssignmentService::isAssigned($pdo, 'client', 1, 200), 'creator/current assignee must be removable');
    $assert(CompanyAssignmentService::isAssigned($pdo, 'client', 1, 201), 'transferred assignee missing');

    // Vehicle is exact vehicle_unit and has no propagation from contractor.
    CompanyAssignmentService::assign($pdo, 'vehicle_unit', 1, 200, $leader);
    $assert(CompanyAssignmentService::assignedEntityIds($pdo, 'vehicle_unit', 200) === [1], 'vehicle_unit assignment must use exact canonical identifier');
    $assert(!CompanyAssignmentService::isAssigned($pdo, 'vehicle_unit', 2, 200), 'vehicle assignment must not propagate to sibling vehicle');
    $assert(!CompanyAssignmentService::isAssigned($pdo, 'driver', 1, 200), 'vehicle assignment must not propagate to driver');

    // Auto assignment for concrete member and leader; never synthetic for superadmin/system.
    $assert(CompanyAssignmentService::autoAssignCreator($pdo, 'driver', 1, $member), 'ordinary creator should auto-assign');
    $assert(CompanyAssignmentService::autoAssignCreator($pdo, 'client', 2, $leader2), 'leader creator should auto-assign');
    $assert(!CompanyAssignmentService::autoAssignCreator($pdo, 'vehicle_unit', 2, $superadmin), 'superadmin must not receive synthetic assignment');
    $assert(!CompanyAssignmentService::autoAssignCreator($pdo, 'vehicle_unit', 2, ['allowed' => false]), 'system/import without context must not receive synthetic assignment');

    // ALL/NONE/ONLY_OWN SQL filters.
    $all = DataScopeService::filter('all', 'client', 200, 20, 'c.id', 'c.created_by_user_id');
    $none = DataScopeService::filter('none', 'client', 200, 20, 'c.id', 'c.created_by_user_id');
    $own = DataScopeService::filter('only_own', 'client', 201, 21, 'c.id', 'c.created_by_user_id');
    $assert($all['where'] === '1=1' && $all['params'] === [], 'ALL filter mismatch');
    $assert($none['where'] === '1=0' && $none['params'] === [], 'NONE filter mismatch');
    $assert(str_contains($own['where'], 'company_user_assignments') && $own['params'] === ['client', 201], 'ONLY_OWN assignment filter mismatch');

    $client1 = $pdo->query('SELECT * FROM clients WHERE id=1')->fetch();
    $client2 = $pdo->query('SELECT * FROM clients WHERE id=2')->fetch();
    $assert(DataScopeService::canAccessRow($pdo, 'only_own', 'client', $client1, 201, 21), 'assigned client should be visible');
    $assert(!DataScopeService::canAccessRow($pdo, 'only_own', 'client', $client1, 200, 20), 'removed client assignment must revoke visibility');
    $assert(DataScopeService::canAccessRow($pdo, 'all', 'client', $client1, 0, 0), 'ALL should allow tenant row');
    $assert(!DataScopeService::canAccessRow($pdo, 'none', 'client', $client2, 101, 11), 'NONE must deny even assigned/leader-shaped IDs at data layer');

    // Trips are creator-only and never assignment-backed.
    $linear1 = $pdo->query('SELECT * FROM linear_routes WHERE id=1')->fetch();
    $linear2 = $pdo->query('SELECT * FROM linear_routes WHERE id=2')->fetch();
    $waste1 = $pdo->query('SELECT * FROM waste_trips WHERE id=1')->fetch();
    $assert(DataScopeService::ownershipMode('linear_trip') === 'creator', 'linear trip ownership mode must be creator');
    $assert(DataScopeService::ownershipMode('waste_trip') === 'creator', 'waste trip ownership mode must be creator');
    $assert(DataScopeService::canAccessRow($pdo, 'only_own', 'linear_trip', $linear1, 999, 10), 'linear creator should see own trip');
    $assert(!DataScopeService::canAccessRow($pdo, 'only_own', 'linear_trip', $linear2, 999, 10), 'linear creator must not see another trip');
    $assert(DataScopeService::canAccessRow($pdo, 'only_own', 'waste_trip', $waste1, 999, 10), 'waste creator should see own trip');
    $assert(CompanyAssignmentService::assignedEntityIds($pdo, 'linear_trip', 200) === [], 'trips must not be assignment entities');

    // Scope transitions do not touch persistence.
    $before = CompanyAssignmentService::assignedEntityIds($pdo, 'driver', 200);
    DataScopeService::filter('all', 'driver', 200, 20, 'd.id', 'd.created_by_user_id');
    DataScopeService::filter('none', 'driver', 200, 20, 'd.id', 'd.created_by_user_id');
    DataScopeService::filter('only_own', 'driver', 200, 20, 'd.id', 'd.created_by_user_id');
    $after = CompanyAssignmentService::assignedEntityIds($pdo, 'driver', 200);
    $assert($before === $after && $after === [1], 'scope changes must preserve assignments');

    echo "ROLES_ACCESS_ASSIGNMENTS=PASS\n";
    echo "ROLES_ACCESS_DATA_SCOPE=PASS\n";
    echo "ROLES_ACCESS_TRIP_CREATOR_SCOPE=PASS\n";
} finally {
    $admin->exec("DROP DATABASE IF EXISTS {$quoted}");
}
