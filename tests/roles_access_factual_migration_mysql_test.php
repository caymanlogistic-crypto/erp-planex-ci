<?php

declare(strict_types=1);

use Planex\Tools\RolesAccess\AccessReadinessActivator;
use Planex\Tools\RolesAccess\AccessSnapshotCollector;
use Planex\Tools\RolesAccess\LegacyAccessMigrationExecutor;
use Planex\Tools\RolesAccess\MigrationProof;

require_once dirname(__DIR__) . '/bootstrap/app.php';
require_once BASE_PATH . '/app/Support/company_database.php';
require_once BASE_PATH . '/tools/roles_access/MigrationProof.php';
require_once BASE_PATH . '/tools/roles_access/AccessReadinessActivator.php';
require_once BASE_PATH . '/tools/roles_access/AccessSnapshotCollector.php';
require_once BASE_PATH . '/tools/roles_access/LegacyAccessMigrationExecutor.php';

$host = getenv('PLANEX_SCHEMA_DB_HOST') ?: '127.0.0.1';
$port = (int) (getenv('PLANEX_SCHEMA_DB_PORT') ?: 3306);
$user = getenv('PLANEX_SCHEMA_DB_USER') ?: 'planex_ci';
$pass = getenv('PLANEX_SCHEMA_DB_PASSWORD') ?: 'planex_ci';
$dbName = trim((string) getenv('PLANEX_TEST_FIXED_DB'));
$allowlist = array_filter(array_map('trim', explode(',', (string) getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST'))));
if ($dbName === '' || !in_array($dbName, $allowlist, true)) {
    throw new RuntimeException('A guarded fixed runner DB is required.');
}
$pdo = new PDO(
    "mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4",
    $user,
    $pass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false],
);
cleanCompanyPoolDatabase($pdo);
try {
    $pdo->exec('CREATE TABLE companies (id INT UNSIGNED PRIMARY KEY,name VARCHAR(255),status VARCHAR(20)) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE company_users (id INT UNSIGNED PRIMARY KEY,company_id INT UNSIGNED,full_name VARCHAR(255),login VARCHAR(100),email VARCHAR(255),phone VARCHAR(50),password_hash VARCHAR(255),role VARCHAR(50),status VARCHAR(20)) ENGINE=InnoDB');
    $pdo->exec("INSERT INTO companies VALUES (7,'Test','active')");
    $pdo->exec("INSERT INTO company_users VALUES (1,7,'Owner','owner',NULL,NULL,'hash-owner','company_owner','active'),(2,7,'Logist','logist',NULL,NULL,'hash-logist','logist','active')");
    $pdo->exec((string) file_get_contents(BASE_PATH . '/database/migrations/013_create_roles_access.sql'));

    $pdo->exec('CREATE TABLE users (id INT UNSIGNED PRIMARY KEY,full_name VARCHAR(255),login VARCHAR(100),email VARCHAR(255),phone VARCHAR(50),password_hash VARCHAR(255),role_code VARCHAR(50),status VARCHAR(20),deleted_at DATETIME NULL) ENGINE=InnoDB');
    $pdo->exec("INSERT INTO users VALUES (1,'Logist','logist',NULL,NULL,'hash-logist','logist','active',NULL)");
    foreach (['clients', 'contractors', 'vehicle_units', 'drivers', 'linear_routes', 'waste_trips'] as $table) {
        $pdo->exec("CREATE TABLE `{$table}` (id BIGINT UNSIGNED PRIMARY KEY,created_by_user_id INT UNSIGNED NULL) ENGINE=InnoDB");
        $pdo->exec("INSERT INTO `{$table}` VALUES (1,1),(2,NULL)");
    }
    $pdo->exec('CREATE TABLE vehicle_sets (id BIGINT UNSIGNED PRIMARY KEY,primary_vehicle_unit_id BIGINT UNSIGNED NULL,secondary_vehicle_unit_id BIGINT UNSIGNED NULL,created_by_user_id INT UNSIGNED NULL) ENGINE=InnoDB');
    $pdo->exec("INSERT INTO vehicle_sets VALUES (1,1,NULL,1),(2,2,NULL,NULL)");
    $pdo->exec('CREATE TABLE entity_access_grants (id BIGINT UNSIGNED PRIMARY KEY,entity_type VARCHAR(50),entity_id BIGINT UNSIGNED,granted_to_user_id INT UNSIGNED,access_level VARCHAR(10),revoked_at DATETIME NULL) ENGINE=InnoDB');
    $pdo->exec("INSERT INTO entity_access_grants VALUES (1,'client',1,1,'view',NULL),(2,'linear_route',2,1,'view',NULL),(3,'vehicle_set',2,1,'edit',NULL)");
    $pdo->exec((string) file_get_contents(BASE_PATH . '/database/migrations-local/131_create_roles_access_projection.sql'));

    $pageKeys = ['company.dashboard', 'company.clients'];
    $allScopes = array_fill_keys(['client', 'contractor', 'vehicle_unit', 'driver', 'linear_trip', 'waste_trip'], 'all');
    $ownScopes = array_fill_keys(['client', 'contractor', 'vehicle_unit', 'driver', 'linear_trip', 'waste_trip'], 'own');
    $profiles = [7 => [
        'company_owner' => ['pages' => $pageKeys, 'scopes' => $allScopes],
        'senior_logist' => ['pages' => $pageKeys, 'scopes' => $allScopes],
        'logist' => ['pages' => $pageKeys, 'scopes' => $ownScopes],
    ]];
    $head = str_repeat('c', 40);
    $before = AccessSnapshotCollector::before($pdo, $pdo, 7, $head, [
        'company_owner' => $pageKeys,
        'senior_logist' => $pageKeys,
        'logist' => $pageKeys,
    ]);
    $report = LegacyAccessMigrationExecutor::migrateAll($pdo, [7 => $pdo], $profiles, $head);
    if ($report['blocked'] || ($report['companies'][7]['state'] ?? '') !== 'migrated') {
        throw new RuntimeException('factual migration did not reach migrated state');
    }
    $second = LegacyAccessMigrationExecutor::migrateAll($pdo, [7 => $pdo], $profiles, $head);
    if ($second['blocked']) {
        throw new RuntimeException('idempotent retry became blocked');
    }

    if ((int) $pdo->query('SELECT COUNT(*) FROM user_accounts')->fetchColumn() !== 2
        || (int) $pdo->query('SELECT COUNT(*) FROM company_memberships')->fetchColumn() !== 2
        || (int) $pdo->query('SELECT membership_id FROM users WHERE id=1')->fetchColumn() <= 0
        || (int) $pdo->query('SELECT COUNT(*) FROM entity_assignments')->fetchColumn() !== 1
        || (int) $pdo->query('SELECT COUNT(*) FROM legacy_access_projections')->fetchColumn() !== 2) {
        throw new RuntimeException('identity/membership/assignment projection mismatch');
    }
    if ((int) $pdo->query("SELECT COUNT(*) FROM entity_assignments WHERE assigned_by_membership_id IS NOT NULL")->fetchColumn() !== 0) {
        throw new RuntimeException('historical creator identity was incorrectly invented as an assignment');
    }

    $after = AccessSnapshotCollector::after($pdo, $pdo, 7, $head, $pageKeys);
    $comparison = MigrationProof::compare($before, $after);
    if (!$comparison['equal'] || !$comparison['accepted']) {
        throw new RuntimeException('migrated candidate did not preserve factual access: ' . json_encode($comparison));
    }

    $manifest = [
        'company_id' => 7,
        'source_head' => $head,
        'membership_count' => count($after['memberships']),
        'tenant_count' => 1,
        'before_sha256' => MigrationProof::hash(MigrationProof::canonicalSnapshot($before)),
        'after_sha256' => MigrationProof::hash(MigrationProof::canonicalSnapshot($after)),
        'comparison_sha256' => $comparison['comparison_sha256'],
        'central_migration_sha256' => hash_file('sha256', BASE_PATH . '/database/migrations/013_create_roles_access.sql'),
        'tenant_migration_sha256' => hash_file('sha256', BASE_PATH . '/database/migrations-local/131_create_roles_access_projection.sql'),
        'artifact_hashes_verified' => true,
        'comparison_accepted' => true,
        'identity_conflict_count' => 0,
        'unapproved_diff_count' => 0,
        'owner_exception_count' => 0,
        'owner_exceptions' => [],
        'gates' => array_fill_keys([
            'central_schema', 'tenant_schema', 'identity_conflicts', 'before_snapshot',
            'migration', 'after_snapshot', 'parity', 'independent_regression',
        ], 'PASS'),
    ];

    $tampered = $manifest;
    $tampered['after_sha256'] = str_repeat('0', 64);
    try {
        AccessReadinessActivator::verifyAndMarkReady($pdo, 7, $before, $after, [], $tampered);
        throw new RuntimeException('tampered proof unexpectedly activated');
    } catch (RuntimeException $e) {
        if (!str_contains($e->getMessage(), 'does not match')) {
            throw $e;
        }
    }

    AccessReadinessActivator::verifyAndMarkReady($pdo, 7, $before, $after, [], $manifest);
    $readiness = $pdo->query('SELECT state,before_sha256,after_sha256,comparison_sha256,verified_at,activated_at FROM company_access_readiness WHERE company_id=7')->fetch(PDO::FETCH_ASSOC);
    if (($readiness['state'] ?? '') !== 'ready'
        || ($readiness['before_sha256'] ?? '') !== $manifest['before_sha256']
        || ($readiness['after_sha256'] ?? '') !== $manifest['after_sha256']
        || ($readiness['comparison_sha256'] ?? '') !== $manifest['comparison_sha256']
        || empty($readiness['verified_at'])
        || empty($readiness['activated_at'])) {
        throw new RuntimeException('verified readiness was not persisted');
    }

    echo "roles access factual migration + readiness: PASS\n";
} finally {
    cleanCompanyPoolDatabase($pdo);
}
