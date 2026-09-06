<?php

declare(strict_types=1);

use App\Security\AccessRuntime;
use App\Security\EffectiveAccess;
use App\Service\EntityAssignmentService;
use App\Service\EntityResponsibilityService;
use App\Service\RegulatedDataScopeService;

$root = dirname(__DIR__);
require_once $root . '/bootstrap/app.php';
require_once BASE_PATH . '/app/Support/company_database.php';
foreach ([
    '/app/Security/EffectiveAccess.php',
    '/app/Security/AccessRuntime.php',
    '/app/Service/AccessControlService.php',
    '/app/Service/LegacyAccessProjectionService.php',
    '/app/Service/EntityResponsibilityService.php',
    '/app/Service/RegulatedDataScopeService.php',
    '/app/Service/EntityAssignmentService.php',
] as $file) {
    require_once BASE_PATH . $file;
}

$host = getenv('PLANEX_SCHEMA_DB_HOST') ?: '127.0.0.1';
$port = (int)(getenv('PLANEX_SCHEMA_DB_PORT') ?: 3306);
$user = getenv('PLANEX_SCHEMA_DB_USER') ?: 'planex_ci';
$pass = getenv('PLANEX_SCHEMA_DB_PASSWORD') ?: 'planex_ci';
$dbName = trim((string)getenv('PLANEX_TEST_FIXED_DB'));
$allowlist = array_values(array_filter(array_map('trim', explode(',', (string)getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST')))));
if ($dbName === '' || !in_array($dbName, $allowlist, true) || (string)getenv('APP_ENV') !== 'test') {
    throw new RuntimeException('Guarded fixed test DB is required.');
}
$pdo = new PDO(
    "mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4",
    $user,
    $pass,
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false]
);
$ok = static function (bool $value, string $message): void {
    if (!$value) throw new RuntimeException($message);
};

cleanCompanyPoolDatabase($pdo);
try {
    $pdo->exec("CREATE TABLE contractors (
        id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
        name VARCHAR(255) NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'active',
        deleted_at DATETIME NULL,
        created_by_user_id INT UNSIGNED NULL,
        created_by_membership_id BIGINT UNSIGNED NULL
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE entity_assignments (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        entity_type ENUM('client','contractor','vehicle_unit','driver') NOT NULL,
        entity_id BIGINT UNSIGNED NOT NULL,
        membership_id BIGINT UNSIGNED NOT NULL,
        assigned_by_membership_id BIGINT UNSIGNED DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY(id),
        UNIQUE KEY uk_entity_assignment(entity_type,entity_id,membership_id),
        KEY idx_assignment_membership_entity(membership_id,entity_type,entity_id),
        KEY idx_assignment_entity(entity_type,entity_id)
    ) ENGINE=InnoDB");

    // Factual ambiguous pre-132 state: row 1 looks like creator-auto OR manual self assignment.
    // Row 2 is a non-creator grant. Migration must not pretend either provenance is known.
    $pdo->exec("INSERT INTO contractors(id,name,created_by_membership_id) VALUES
        (1,'Creator ambiguous',12),(2,'Legacy other-member',11),(3,'Fresh dual kind',12),(4,'Transfer target',12)");
    $pdo->exec("INSERT INTO entity_assignments(id,entity_type,entity_id,membership_id,assigned_by_membership_id) VALUES
        (101,'contractor',1,12,12),
        (102,'contractor',2,12,11)");
    $before = $pdo->query("SELECT id,entity_type,entity_id,membership_id,assigned_by_membership_id FROM entity_assignments ORDER BY id")
        ->fetchAll(PDO::FETCH_ASSOC);

    $migration = (string)file_get_contents(BASE_PATH . '/database/migrations-local/132_split_assignment_and_responsibility.sql');
    $pdo->exec($migration);

    $after = $pdo->query("SELECT id,entity_type,entity_id,membership_id,assigned_by_membership_id FROM entity_assignments WHERE id IN (101,102) ORDER BY id")
        ->fetchAll(PDO::FETCH_ASSOC);
    $ok($after === $before, '132 changed factual assignment identity/provenance columns');
    $kinds = $pdo->query("SELECT id,assignment_kind FROM entity_assignments WHERE id IN (101,102) ORDER BY id")
        ->fetchAll(PDO::FETCH_KEY_PAIR);
    $ok(($kinds[101] ?? null) === 'legacy_unclassified', 'ambiguous self assignment was guessed');
    $ok(($kinds[102] ?? null) === 'legacy_unclassified', 'legacy non-self row was guessed instead of preserved');

    // Pre-132 access remains factual. Non-creator legacy grant is still honored.
    $scopes = ['client'=>'none','contractor'=>'own','vehicle_unit'=>'none','driver'=>'none','linear_trip'=>'none','waste_trip'=>'none'];
    AccessRuntime::setCurrent(EffectiveAccess::member(12, 7, [], $scopes, 'logist'));
    $ok(RegulatedDataScopeService::canAccess($pdo, 'contractor', 'contractors', 1), 'ambiguous creator row lost pre-transfer access');
    $ok(RegulatedDataScopeService::canAccess($pdo, 'contractor', 'contractors', 2), 'legacy non-creator grant lost access');

    // New writes have factual provenance and same member may hold both reasons.
    AccessRuntime::setCurrent(EffectiveAccess::systemOwner(11, 7, []));
    EntityAssignmentService::autoAssignCreator($pdo, 'contractor', 3, 12);
    EntityAssignmentService::replace($pdo, 'contractor', 3, [12], 11);
    $dual = $pdo->query("SELECT assignment_kind FROM entity_assignments WHERE entity_type='contractor' AND entity_id=3 AND membership_id=12 ORDER BY assignment_kind")
        ->fetchAll(PDO::FETCH_COLUMN);
    sort($dual, SORT_STRING);
    $ok($dual === ['creator_auto','manual'], 'creator_auto + manual provenance cannot coexist');
    $ok(EntityAssignmentService::membershipIds($pdo, 'contractor', 3) === [12], 'checkbox read model must expose manual only');

    // Responsibility transfer mutates only current base responsibility. Creator history is immutable.
    EntityAssignmentService::replace($pdo, 'contractor', 4, [14], 11);
    $beforeCreator = (int)$pdo->query('SELECT created_by_membership_id FROM contractors WHERE id=4')->fetchColumn();
    $beforeManual = EntityAssignmentService::membershipIds($pdo, 'contractor', 4);
    EntityResponsibilityService::transfer($pdo, 'contractor', [4], 13, 11);
    $afterCreator = (int)$pdo->query('SELECT created_by_membership_id FROM contractors WHERE id=4')->fetchColumn();
    $afterManual = EntityAssignmentService::membershipIds($pdo, 'contractor', 4);
    $ok($beforeCreator === 12 && $afterCreator === 12, 'responsibility transfer rewrote creator history');
    $ok($beforeManual === [14] && $afterManual === [14], 'responsibility transfer destroyed explicit assignments');
    $ok(EntityResponsibilityService::effectiveBaseMembershipId($pdo, 'contractor', 4) === 13, 'new base responsibility not stored');

    AccessRuntime::setCurrent(EffectiveAccess::member(12, 7, [], $scopes, 'logist'));
    $ok(!RegulatedDataScopeService::canAccess($pdo, 'contractor', 'contractors', 4), 'old creator remained base-accessible after responsibility transfer');
    AccessRuntime::setCurrent(EffectiveAccess::member(13, 7, [], $scopes, 'logist'));
    $ok(RegulatedDataScopeService::canAccess($pdo, 'contractor', 'contractors', 4), 'new base responsible lacks ONLY_OWN access');
    AccessRuntime::setCurrent(EffectiveAccess::member(14, 7, [], $scopes, 'logist'));
    $ok(RegulatedDataScopeService::canAccess($pdo, 'contractor', 'contractors', 4), 'third-party explicit assignment was not preserved');

    // Ambiguous creator-like legacy row is not silently upgraded to manual. Once base responsibility is
    // explicitly transferred, creator history alone no longer acts as current responsibility.
    AccessRuntime::setCurrent(EffectiveAccess::systemOwner(11, 7, []));
    EntityResponsibilityService::transfer($pdo, 'contractor', [1], 13, 11);
    AccessRuntime::setCurrent(EffectiveAccess::member(12, 7, [], $scopes, 'logist'));
    $ok(!RegulatedDataScopeService::canAccess($pdo, 'contractor', 'contractors', 1), 'legacy ambiguous self-row overrode explicit responsibility transfer');
    AccessRuntime::setCurrent(EffectiveAccess::member(13, 7, [], $scopes, 'logist'));
    $ok(RegulatedDataScopeService::canAccess($pdo, 'contractor', 'contractors', 1), 'transferred legacy object unavailable to new base responsible');

    echo "ROLES_ACCESS_132_FACTUAL_MIGRATION_PROOF=PASS\n";
    echo "LEGACY_PROVENANCE_GUESSED=NO\n";
    echo "CREATOR_HISTORY_IMMUTABLE=PASS\n";
    echo "RESPONSIBILITY_TRANSFER=PASS\n";
    echo "EXPLICIT_ASSIGNMENTS_PRESERVED=PASS\n";
} finally {
    AccessRuntime::clear();
    cleanCompanyPoolDatabase($pdo);
}
