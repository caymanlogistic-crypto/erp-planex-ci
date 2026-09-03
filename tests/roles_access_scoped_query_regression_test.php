<?php

declare(strict_types=1);

use App\Service\CompanyAssignmentService;
use App\Service\DataScopeService;
use App\Service\ScopedEntityQueryService;

require_once dirname(__DIR__) . '/app/Service/CompanyAssignmentService.php';
require_once dirname(__DIR__) . '/app/Service/DataScopeService.php';
require_once dirname(__DIR__) . '/app/Service/ScopedEntityQueryService.php';

$host = getenv('PLANEX_SCHEMA_DB_HOST') ?: '127.0.0.1';
$port = (int) (getenv('PLANEX_SCHEMA_DB_PORT') ?: 3306);
$user = getenv('PLANEX_SCHEMA_DB_USER') ?: 'planex_ci';
$pass = getenv('PLANEX_SCHEMA_DB_PASSWORD') ?: 'planex_ci';
$admin = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$dbName = 'planex_scoped_query_' . getmypid() . '_' . bin2hex(random_bytes(3));
$quoted = '`' . str_replace('`', '``', $dbName) . '`';
$admin->exec("CREATE DATABASE {$quoted} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

$assert = static function (bool $ok, string $message): void {
    if (!$ok) {
        throw new RuntimeException($message);
    }
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
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_company_user_assignment (entity_type, entity_id, membership_id)
        );
        CREATE TABLE clients (id INT UNSIGNED PRIMARY KEY, name VARCHAR(120), inn VARCHAR(20), created_by_user_id INT UNSIGNED NULL, deleted_at DATETIME NULL);
        CREATE TABLE contractors (id INT UNSIGNED PRIMARY KEY, name VARCHAR(120), inn VARCHAR(20), created_by_user_id INT UNSIGNED NULL, deleted_at DATETIME NULL);
        CREATE TABLE vehicle_units (id INT UNSIGNED PRIMARY KEY, plate_number VARCHAR(30), brand VARCHAR(80), model VARCHAR(80), created_by_user_id INT UNSIGNED NULL, deleted_at DATETIME NULL);
        CREATE TABLE drivers (id INT UNSIGNED PRIMARY KEY, full_name VARCHAR(120), phone VARCHAR(30), email VARCHAR(120), created_by_user_id INT UNSIGNED NULL, deleted_at DATETIME NULL);
        CREATE TABLE linear_routes (id INT UNSIGNED PRIMARY KEY, created_by_user_id INT UNSIGNED NULL, deleted_at DATETIME NULL);
        CREATE TABLE waste_trips (id INT UNSIGNED PRIMARY KEY, created_by_user_id INT UNSIGNED NULL);

        INSERT INTO clients VALUES
            (1,'Alpha','1111111111',10,NULL),
            (2,'Beta','2222222222',11,NULL),
            (3,'Deleted','3333333333',10,NOW());
        INSERT INTO contractors VALUES
            (1,'Carrier Alpha','4444444444',10,NULL),
            (2,'Carrier Beta','5555555555',11,NULL);
        INSERT INTO vehicle_units VALUES
            (1,'A111AA','MAN','TGX',10,NULL),
            (2,'B222BB','Volvo','FH',11,NULL);
        INSERT INTO drivers VALUES
            (1,'Иван Иванов','+70000000001','ivan@example.test',10,NULL),
            (2,'Пётр Петров','+70000000002','petr@example.test',11,NULL);
        INSERT INTO linear_routes VALUES (1,10,NULL),(2,11,NULL),(3,10,NOW());
        INSERT INTO waste_trips VALUES (1,10),(2,11);

        INSERT INTO company_user_assignments(entity_type,entity_id,membership_id) VALUES
            ('client',1,200),
            ('contractor',1,200),
            ('vehicle_unit',1,200),
            ('driver',1,200),
            ('client',2,201);
    ");

    // Lists/counters/direct ID share the same assignment-backed scope.
    $assert(ScopedEntityQueryService::visibleIds($pdo, 'client', 'only_own', 200, 10) === [1], 'client ONLY_OWN visibleIds mismatch');
    $assert(ScopedEntityQueryService::countVisible($pdo, 'client', 'only_own', 200, 10) === 1, 'client ONLY_OWN counter mismatch');
    $assert((int) (ScopedEntityQueryService::findById($pdo, 'client', 1, 'only_own', 200, 10)['id'] ?? 0) === 1, 'assigned direct client must resolve');
    $assert(ScopedEntityQueryService::findById($pdo, 'client', 2, 'only_own', 200, 10) === null, 'unassigned direct client must be hidden');

    // NONE blocks every tested surface.
    $assert(ScopedEntityQueryService::visibleIds($pdo, 'client', 'none', 200, 10) === [], 'NONE list must be empty');
    $assert(ScopedEntityQueryService::countVisible($pdo, 'client', 'none', 200, 10) === 0, 'NONE counter must be zero');
    $assert(ScopedEntityQueryService::findById($pdo, 'client', 1, 'none', 200, 10) === null, 'NONE direct ID must be hidden');
    $assert(ScopedEntityQueryService::search($pdo, 'client', 'Alpha', 'none', 200, 10) === [], 'NONE search must be empty');

    // ALL remains tenant-normal and respects canonical soft-delete filters.
    $assert(ScopedEntityQueryService::visibleIds($pdo, 'client', 'all', 0, 0) === [1,2], 'ALL must exclude soft deleted clients');
    $assert(ScopedEntityQueryService::visibleIds($pdo, 'linear_trip', 'all', 0, 0) === [1,2], 'ALL must exclude soft deleted linear trips');

    // Search/autocomplete never returns records outside current assignment.
    $clientSearch = ScopedEntityQueryService::search($pdo, 'client', '', 'only_own', 200, 10, 50);
    $assert(array_map(static fn(array $r): int => (int) $r['id'], $clientSearch) === [1], 'client autocomplete leaked unassigned rows');
    $contractorSearch = ScopedEntityQueryService::search($pdo, 'contractor', 'Carrier', 'only_own', 200, 10, 50);
    $assert(array_map(static fn(array $r): int => (int) $r['id'], $contractorSearch) === [1], 'contractor search leaked unassigned rows');
    $vehicleSearch = ScopedEntityQueryService::search($pdo, 'vehicle_unit', '', 'only_own', 200, 10, 50);
    $assert(array_map(static fn(array $r): int => (int) $r['id'], $vehicleSearch) === [1], 'vehicle autocomplete leaked unassigned rows');
    $driverSearch = ScopedEntityQueryService::search($pdo, 'driver', '', 'only_own', 200, 10, 50);
    $assert(array_map(static fn(array $r): int => (int) $r['id'], $driverSearch) === [1], 'driver autocomplete leaked unassigned rows');

    // Assignment is current state: a user can see entity created by someone else.
    $assert((int) (ScopedEntityQueryService::findById($pdo, 'client', 2, 'only_own', 201, 999)['id'] ?? 0) === 2, 'assignment must override immutable creator ownership concept');

    // Trips use tenant actor creator, not membership assignment.
    $assert(ScopedEntityQueryService::visibleIds($pdo, 'linear_trip', 'only_own', 999, 10) === [1], 'linear trip creator list mismatch');
    $assert(ScopedEntityQueryService::visibleIds($pdo, 'waste_trip', 'only_own', 999, 10) === [1], 'waste trip creator list mismatch');
    $assert(ScopedEntityQueryService::findById($pdo, 'linear_trip', 2, 'only_own', 999, 10) === null, 'linear direct ID leaked another creator');
    $assert(ScopedEntityQueryService::findById($pdo, 'waste_trip', 2, 'only_own', 999, 10) === null, 'waste direct ID leaked another creator');

    // Unknown/non-regulated types are fail-closed.
    $assert(ScopedEntityQueryService::visibleIds($pdo, 'feo_request', 'all', 200, 10) === [], 'unregulated FEO must not enter data-scope engine');
    $assert(ScopedEntityQueryService::search($pdo, 'vehicle_set', '', 'all', 200, 10) === [], 'vehicle_set must not become a regulated type');

    echo "ROLES_ACCESS_SCOPED_LIST=PASS\n";
    echo "ROLES_ACCESS_SCOPED_SEARCH=PASS\n";
    echo "ROLES_ACCESS_SCOPED_DIRECT_ID=PASS\n";
    echo "ROLES_ACCESS_SCOPED_COUNTER=PASS\n";
} finally {
    $admin->exec("DROP DATABASE IF EXISTS {$quoted}");
}
