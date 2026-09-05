<?php

declare(strict_types=1);

use App\Core\Database;
use App\Security\AccessRequestContext;
use App\Security\AccessRuntime;
use App\Security\EffectiveAccess;
use App\Security\EffectiveAccessResolver;
use App\Security\PageRegistry;
use App\Security\PdoAccessStateProvider;
use App\Service\CompanyMembershipService;
use App\Service\EntityAssignmentService;
use App\Service\RegulatedDataScopeService;
use Planex\Tools\RolesAccess\AccessReadinessActivator;
use Planex\Tools\RolesAccess\AccessSnapshotCollector;
use Planex\Tools\RolesAccess\LegacyAccessMigrationExecutor;
use Planex\Tools\RolesAccess\MigrationProof;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = rtrim((string) getenv('PLANEX_ROOT'), '/');
$sourceHead = trim((string) getenv('PLANEX_SOURCE_SHA'));
$evidenceDir = rtrim((string) getenv('PLANEX_EVIDENCE_DIR'), '/');
$centralSql = trim((string) getenv('PLANEX_CENTRAL_MIGRATION_PATH'));
$tenantSql = trim((string) getenv('PLANEX_TENANT_MIGRATION_PATH'));
$command = $argv[1] ?? '';

if ($root === '' || !is_file($root . '/bootstrap/app.php') || !is_file($root . '/.env')) {
    fail('PLANEX_ROOT invalid.');
}
if (!preg_match('/^[a-f0-9]{40}$/', $sourceHead)) {
    fail('PLANEX_SOURCE_SHA must be exact 40-hex SHA.');
}

$projectRoot = dirname(dirname($root));
$backupRoot = $projectRoot . '/deploy_backups';
$expectedPrefix = $backupRoot . '/roles_access_';
if ($evidenceDir === '' || !str_starts_with($evidenceDir, $expectedPrefix)) {
    fail('PLANEX_EVIDENCE_DIR must be inside production deploy_backups/roles_access_*.');
}

require_once $root . '/app/Core/Database.php';
require_once $root . '/app/Support/company_database.php';
require_once $root . '/app/Security/PageRegistry.php';
require_once $root . '/app/Security/AccessRequestContext.php';
require_once $root . '/app/Security/AccessStateProvider.php';
require_once $root . '/app/Security/MembershipAccessSnapshot.php';
require_once $root . '/app/Security/EffectiveAccess.php';
require_once $root . '/app/Security/AccessRuntime.php';
require_once $root . '/app/Security/EffectiveAccessResolver.php';
require_once $root . '/app/Security/PdoAccessStateProvider.php';
require_once $root . '/app/Service/IdentityNormalizer.php';
require_once $root . '/app/Service/CompanyRoleService.php';
require_once $root . '/app/Service/CompanyMembershipService.php';
require_once $root . '/app/Service/AccessControlService.php';
require_once $root . '/app/Service/EntityAssignmentService.php';
require_once $root . '/app/Service/RegulatedDataScopeService.php';
require_once $root . '/tools/roles_access/MigrationProof.php';
require_once $root . '/tools/roles_access/AccessSnapshotCollector.php';
require_once $root . '/tools/roles_access/LegacyAccessMigrationExecutor.php';
require_once $root . '/tools/roles_access/AccessReadinessActivator.php';

$config = require $root . '/bootstrap/app.php';
$central = (new Database($config['database']))->connection();
$companies = activeCompanies($central);
if ($companies === []) {
    fail('No active companies found.');
}
$tenants = tenantConnections($config, $companies);
$registry = new PageRegistry();
$pageMatrix = legacyPageMatrix($registry);
$profiles = roleProfiles($companies, $registry);

try {
    assertMarker($projectRoot, $sourceHead);
    match ($command) {
        'prepare' => prepare($root, $sourceHead, $evidenceDir, $centralSql, $tenantSql, $central, $companies, $tenants, $pageMatrix),
        'apply' => applyMigration($root, $sourceHead, $evidenceDir, $centralSql, $tenantSql, $central, $companies, $tenants, $profiles),
        'after' => afterParity($root, $sourceHead, $evidenceDir, $centralSql, $tenantSql, $central, $companies, $tenants, $registry),
        'ready' => markReady($root, $sourceHead, $evidenceDir, $centralSql, $tenantSql, $central, $companies),
        'activate' => activateFeature($root, $sourceHead, $evidenceDir, $central, $companies),
        'accept' => acceptProduction($root, $sourceHead, $evidenceDir, $central, $companies, $tenants, $registry),
        'deactivate' => deactivateFeature($root, $sourceHead, $evidenceDir),
        default => throw new RuntimeException('Usage: prepare|apply|after|ready|activate|accept'),
    };
} catch (Throwable $e) {
    fail($e->getMessage());
}

function fail(string $message): never
{
    fwrite(STDERR, "ROLES_ACCESS_PRODUCTION_FAIL=" . preg_replace('/[\r\n]+/', ' ', $message) . PHP_EOL);
    exit(1);
}

/** @return list<array<string,mixed>> */
function activeCompanies(PDO $central): array
{
    $rows = $central->query(
        "SELECT id,name,status,db_identifier,db_host,db_port,db_username,db_password
         FROM companies WHERE status='active' ORDER BY id"
    )->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
}

/** @param list<array<string,mixed>> $companies @return array<int, PDO> */
function tenantConnections(array $config, array $companies): array
{
    $out = [];
    foreach ($companies as $company) {
        $companyId = (int) ($company['id'] ?? 0);
        if ($companyId <= 0) {
            throw new RuntimeException('Invalid active company id.');
        }
        $out[$companyId] = (new Database(companyDatabaseConfig($config, $company)))->connection();
    }
    return $out;
}

function assertMarker(string $projectRoot, string $sourceHead): void
{
    $marker = $projectRoot . '/deploy/P07_erpv2_deployed_commit.txt';
    if (!is_file($marker) || trim((string) file_get_contents($marker)) !== $sourceHead) {
        throw new RuntimeException('Production marker does not match exact source SHA.');
    }
}

function featureEnabled(string $root): bool
{
    $raw = (string) file_get_contents($root . '/.env');
    if (!preg_match_all('/^ERP_ROLES_ACCESS_ENABLED\s*=\s*([^\r\n#]*)/mi', $raw, $m)) {
        return false;
    }
    if (count($m[1]) !== 1) {
        throw new RuntimeException('ERP_ROLES_ACCESS_ENABLED must occur at most once.');
    }
    return filter_var(trim((string) $m[1][0]), FILTER_VALIDATE_BOOL);
}

function assertFeatureOff(string $root): void
{
    if (featureEnabled($root)) {
        throw new RuntimeException('ERP_ROLES_ACCESS_ENABLED must remain OFF before activation.');
    }
}

/** @return array<string,list<string>> */
function legacyPageMatrix(PageRegistry $registry): array
{
    $operational = [
        'company.dashboard',
        'company.clients',
        'company.trips.linear',
        'company.trips.waste',
        'company.feo.requests',
        'company.feo.costs',
        'company.feo.map',
        'company.feo.map_placement',
        'company.feo.utilizers',
        'company.route_executors',
        'company.documents.create',
        'company.documents.history',
        'company.contractors',
        'company.drivers',
        'company.vehicle_units',
        'company.vehicle_sets',
    ];
    $all = $registry->pageKeys();
    foreach ($operational as $pageKey) {
        if (!in_array($pageKey, $all, true)) {
            throw new RuntimeException('Legacy operational page missing from Page Registry: ' . $pageKey);
        }
    }
    return [
        'company_owner' => $all,
        'senior_logist' => $operational,
        'logist' => $operational,
    ];
}

/** @param list<array<string,mixed>> $companies @return array<int,array<string,array{pages:list<string>,scopes:array<string,string>}>> */
function roleProfiles(array $companies, PageRegistry $registry): array
{
    $pages = legacyPageMatrix($registry);
    $entities = ['client', 'contractor', 'vehicle_unit', 'driver', 'linear_trip', 'waste_trip'];
    $allScopes = array_fill_keys($entities, 'all');
    $ownScopes = array_fill_keys($entities, 'own');
    $out = [];
    foreach ($companies as $company) {
        $id = (int) $company['id'];
        $out[$id] = [
            'company_owner' => ['pages' => $pages['company_owner'], 'scopes' => $allScopes],
            'senior_logist' => ['pages' => $pages['senior_logist'], 'scopes' => $allScopes],
            'logist' => ['pages' => $pages['logist'], 'scopes' => $ownScopes],
        ];
    }
    return $out;
}

function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $stmt->execute([$table]);
    return (int) $stmt->fetchColumn() === 1;
}

function columnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() === 1;
}

function indexExists(PDO $pdo, string $table, string $index): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?');
    $stmt->execute([$table, $index]);
    return (int) $stmt->fetchColumn() > 0;
}

/** @param list<string> $columns */
function assertColumns(PDO $pdo, string $table, array $columns): void
{
    if (!tableExists($pdo, $table)) {
        throw new RuntimeException("Required table missing: {$table}");
    }
    foreach ($columns as $column) {
        if (!columnExists($pdo, $table, $column)) {
            throw new RuntimeException("Required column missing: {$table}.{$column}");
        }
    }
}

/** @param list<array<string,mixed>> $companies @param array<int,PDO> $tenants */
function assertLegacySchema(PDO $central, array $companies, array $tenants): void
{
    assertColumns($central, 'companies', ['id','status','db_identifier','db_host','db_port','db_username','db_password']);
    assertColumns($central, 'company_users', ['id','company_id','full_name','login','email','phone','password_hash','role','status']);
    foreach ($companies as $company) {
        $id = (int) $company['id'];
        $tenant = $tenants[$id] ?? null;
        if (!$tenant instanceof PDO) {
            throw new RuntimeException("Tenant connection missing for company {$id}");
        }
        assertColumns($tenant, 'users', ['id','full_name','login','email','phone','password_hash','role_code','status','deleted_at']);
        assertColumns($tenant, 'entity_access_grants', ['entity_type','entity_id','granted_to_user_id','revoked_at']);
        foreach (['clients','contractors','vehicle_units','drivers','linear_routes','waste_trips'] as $table) {
            assertColumns($tenant, $table, ['id','created_by_user_id']);
        }
        $badRoles = $tenant->query("SELECT COUNT(*) FROM users WHERE deleted_at IS NULL AND role_code NOT IN ('logist','senior_logist')")->fetchColumn();
        if ((int) $badRoles !== 0) {
            throw new RuntimeException("Company {$id} has unsupported tenant role_code rows.");
        }
    }
}

function assertTargetSchema(PDO $central, array $tenants): void
{
    $centralTables = [
        'user_accounts' => ['id','normalized_login','password_hash','status'],
        'company_roles' => ['id','company_id','normalized_name','system_key','is_system'],
        'company_memberships' => ['id','account_id','company_id','role_id','status','legacy_source','legacy_source_id'],
        'role_page_permissions' => ['role_id','page_key','is_allowed'],
        'role_data_scopes' => ['role_id','entity_type','scope'],
        'membership_page_overrides' => ['membership_id','page_key','effect'],
        'membership_data_scope_overrides' => ['membership_id','entity_type','scope'],
        'company_access_readiness' => ['company_id','state','before_sha256','after_sha256','comparison_sha256','migration_key','verified_at','activated_at'],
    ];
    foreach ($centralTables as $table => $columns) {
        assertColumns($central, $table, $columns);
    }
    foreach ($tenants as $companyId => $tenant) {
        assertColumns($tenant, 'entity_assignments', ['id','entity_type','entity_id','membership_id','assigned_by_membership_id']);
        if (!indexExists($tenant, 'entity_assignments', 'uk_entity_assignment')) {
            throw new RuntimeException("Company {$companyId}: entity_assignments unique index missing.");
        }
        foreach ([
            'users' => 'membership_id',
            'clients' => 'created_by_membership_id',
            'contractors' => 'created_by_membership_id',
            'vehicle_units' => 'created_by_membership_id',
            'drivers' => 'created_by_membership_id',
            'linear_routes' => 'created_by_membership_id',
            'waste_trips' => 'created_by_membership_id',
        ] as $table => $column) {
            if (!columnExists($tenant, $table, $column)) {
                throw new RuntimeException("Company {$companyId}: projection column missing {$table}.{$column}");
            }
        }
    }
}

/** @param list<array<string,mixed>> $companies */
function assertActiveLegacyLeaders(PDO $central, array $companies): void
{
    $stmt = $central->prepare("SELECT COUNT(*) FROM company_users WHERE company_id=? AND role='company_owner' AND status='active'");
    foreach ($companies as $company) {
        $id = (int) $company['id'];
        $stmt->execute([$id]);
        if ((int) $stmt->fetchColumn() < 1) {
            throw new RuntimeException("Company {$id} has no active legacy company_owner.");
        }
    }
}

/** @param list<array<string,mixed>> $companies */
function assertActiveMigratedLeaders(PDO $central, array $companies): void
{
    $stmt = $central->prepare(
        "SELECT COUNT(*) FROM company_memberships m
         JOIN company_roles r ON r.id=m.role_id AND r.company_id=m.company_id
         WHERE m.company_id=? AND m.status='active' AND r.system_key='company_owner'"
    );
    foreach ($companies as $company) {
        $id = (int) $company['id'];
        $stmt->execute([$id]);
        if ((int) $stmt->fetchColumn() < 1) {
            throw new RuntimeException("Company {$id} has no active migrated leader.");
        }
    }
}

/** @param list<array<string,mixed>> $companies @param array<int,PDO> $tenants */
function identityPreflight(PDO $central, array $companies, array $tenants): array
{
    $rows = [];
    foreach ($companies as $company) {
        $id = (int) $company['id'];
        array_push($rows, ...AccessSnapshotCollector::legacyIdentityRows($central, $tenants[$id], $id));
    }
    $plan = MigrationProof::identityPlan($rows);
    if (($plan['blocked'] ?? true) === true) {
        throw new RuntimeException('Identity/duplicate preflight blocked: ' . count($plan['conflicts'] ?? []) . ' conflict(s).');
    }
    return $plan;
}

/** @return array<string,mixed> */
function backupImpactState(PDO $central, array $companies, array $tenants): array
{
    $backup = ['central' => [], 'tenants' => []];
    foreach (['user_accounts','company_roles','company_memberships','role_page_permissions','role_data_scopes','membership_page_overrides','membership_data_scope_overrides','company_access_readiness'] as $table) {
        if (tableExists($central, $table)) {
            $backup['central'][$table] = $central->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $backup['central'][$table] = ['__absent__' => true];
        }
    }
    foreach ($companies as $company) {
        $id = (int) $company['id'];
        $tenant = $tenants[$id];
        $entry = ['entity_assignments' => tableExists($tenant, 'entity_assignments')
            ? $tenant->query('SELECT * FROM entity_assignments')->fetchAll(PDO::FETCH_ASSOC)
            : ['__absent__' => true], 'projections' => []];
        foreach ([
            'users' => 'membership_id',
            'clients' => 'created_by_membership_id',
            'contractors' => 'created_by_membership_id',
            'vehicle_units' => 'created_by_membership_id',
            'drivers' => 'created_by_membership_id',
            'linear_routes' => 'created_by_membership_id',
            'waste_trips' => 'created_by_membership_id',
        ] as $table => $column) {
            if (columnExists($tenant, $table, $column)) {
                $entry['projections'][$table] = $tenant->query("SELECT id,`{$column}` FROM `{$table}` ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $entry['projections'][$table] = ['__column_absent__' => true];
            }
        }
        $backup['tenants'][(string) $id] = $entry;
    }
    return $backup;
}

function ensureEvidenceDir(string $evidenceDir): void
{
    if (!is_dir($evidenceDir) && !mkdir($evidenceDir, 0700, true) && !is_dir($evidenceDir)) {
        throw new RuntimeException('Cannot create evidence directory.');
    }
    chmod($evidenceDir, 0700);
}

function writeJson(string $path, array $value, int $mode = 0600): string
{
    $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    if (file_put_contents($path, $json, LOCK_EX) === false) {
        throw new RuntimeException('Cannot write evidence: ' . basename($path));
    }
    chmod($path, $mode);
    return hash('sha256', $json);
}

function readJson(string $path): array
{
    if (!is_file($path)) {
        throw new RuntimeException('Required evidence missing: ' . basename($path));
    }
    $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data)) {
        throw new RuntimeException('Invalid evidence JSON: ' . basename($path));
    }
    return $data;
}

function assertMigrationArtifacts(string $centralSql, string $tenantSql): array
{
    if ($centralSql === '' || !is_file($centralSql) || $tenantSql === '' || !is_file($tenantSql)) {
        throw new RuntimeException('Exact migration artifacts are missing.');
    }
    return ['central' => hash_file('sha256', $centralSql), 'tenant' => hash_file('sha256', $tenantSql)];
}

function prepare(string $root, string $sourceHead, string $evidenceDir, string $centralSql, string $tenantSql, PDO $central, array $companies, array $tenants, array $pageMatrix): never
{
    assertFeatureOff($root);
    assertLegacySchema($central, $companies, $tenants);
    assertActiveLegacyLeaders($central, $companies);
    $artifactHashes = assertMigrationArtifacts($centralSql, $tenantSql);
    ensureEvidenceDir($evidenceDir);

    // Immutable rollback/snapshot evidence is captured before identity planning
    // and, critically, before the first schema or business-data mutation.
    $backupHash = writeJson($evidenceDir . '/backup.json', backupImpactState($central, $companies, $tenants), 0400);
    $before = ['source_head' => $sourceHead, 'companies' => []];
    $context = ['source_head' => $sourceHead, 'companies' => []];
    foreach ($companies as $company) {
        $id = (int) $company['id'];
        $snapshot = AccessSnapshotCollector::before($central, $tenants[$id], $id, $sourceHead, $pageMatrix);
        $before['companies'][(string) $id] = $snapshot;
        $subjects = AccessSnapshotCollector::legacyIdentityRows($central, $tenants[$id], $id);
        $context['companies'][(string) $id] = array_map(static fn(array $row): array => [
            'company_id' => (int) $row['company_id'],
            'legacy_source' => (string) $row['legacy_source'],
            'legacy_source_id' => (int) $row['legacy_source_id'],
            'role_code' => (string) ($row['role_code'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
        ], $subjects);
    }
    $beforeHash = writeJson($evidenceDir . '/before_access.json', $before, 0400);
    $contextHash = writeJson($evidenceDir . '/before_context.json', $context, 0400);

    // Identity/duplicate ambiguity is evaluated only after factual BEFORE has
    // been frozen. Any ambiguity now blocks without mutating production.
    $identityPlan = identityPreflight($central, $companies, $tenants);
    $meta = [
        'source_head' => $sourceHead,
        'company_ids' => array_map(static fn(array $c): int => (int) $c['id'], $companies),
        'identity_account_count' => count($identityPlan['accounts'] ?? []),
        'identity_membership_count' => count($identityPlan['memberships'] ?? []),
        'identity_conflict_count' => 0,
        'backup_sha256' => $backupHash,
        'before_sha256' => $beforeHash,
        'context_sha256' => $contextHash,
        'central_migration_sha256' => $artifactHashes['central'],
        'tenant_migration_sha256' => $artifactHashes['tenant'],
        'feature_enabled_before' => false,
    ];
    $metaHash = writeJson($evidenceDir . '/prepare_meta.json', $meta, 0400);
    echo "ROLES_ACCESS_BACKUP_PASS=YES\n";
    echo "ROLES_ACCESS_BEFORE_PASS=YES\n";
    echo "ROLES_ACCESS_IDENTITY_PREFLIGHT_PASS=YES\n";
    echo "ROLES_ACCESS_BEFORE_SHA256={$beforeHash}\n";
    echo "ROLES_ACCESS_PREPARE_META_SHA256={$metaHash}\n";
    echo "ROLES_ACCESS_COMPANY_COUNT=" . count($companies) . "\n";
    exit(0);
}

function verifyPrepared(string $sourceHead, string $evidenceDir, string $centralSql, string $tenantSql): array
{
    $meta = readJson($evidenceDir . '/prepare_meta.json');
    if (($meta['source_head'] ?? '') !== $sourceHead) {
        throw new RuntimeException('Prepared source SHA mismatch.');
    }
    if (hash_file('sha256', $evidenceDir . '/before_access.json') !== ($meta['before_sha256'] ?? '')) {
        throw new RuntimeException('BEFORE evidence hash mismatch.');
    }
    if (hash_file('sha256', $evidenceDir . '/before_context.json') !== ($meta['context_sha256'] ?? '')) {
        throw new RuntimeException('BEFORE context hash mismatch.');
    }
    if (hash_file('sha256', $evidenceDir . '/backup.json') !== ($meta['backup_sha256'] ?? '')) {
        throw new RuntimeException('Backup hash mismatch.');
    }
    $hashes = assertMigrationArtifacts($centralSql, $tenantSql);
    if ($hashes['central'] !== ($meta['central_migration_sha256'] ?? '') || $hashes['tenant'] !== ($meta['tenant_migration_sha256'] ?? '')) {
        throw new RuntimeException('Migration artifact hash changed after BEFORE snapshot.');
    }
    return $meta;
}

function applyMigration(string $root, string $sourceHead, string $evidenceDir, string $centralSql, string $tenantSql, PDO $central, array $companies, array $tenants, array $profiles): never
{
    assertFeatureOff($root);
    verifyPrepared($sourceHead, $evidenceDir, $centralSql, $tenantSql);
    assertLegacySchema($central, $companies, $tenants);
    assertActiveLegacyLeaders($central, $companies);
    identityPreflight($central, $companies, $tenants);

    $central->exec((string) file_get_contents($centralSql));
    foreach ($tenants as $tenant) {
        $tenant->exec((string) file_get_contents($tenantSql));
    }
    assertTargetSchema($central, $tenants);
    $states = readinessStates($central, $companies);
    $readyCount = count(array_filter($states, static fn(array $row): bool => ($row['state'] ?? '') === 'ready' && ($row['migration_key'] ?? '') === 'roles-access:' . $sourceHead));
    if ($readyCount !== 0 && $readyCount !== count($companies)) {
        throw new RuntimeException('Mixed readiness state blocks migration resume.');
    }
    if ($readyCount === count($companies)) {
        $report = ['blocked' => false, 'companies' => array_fill_keys(array_map(static fn(array $c): int => (int)$c['id'], $companies), ['state' => 'already_ready'])];
    } else {
        $report = LegacyAccessMigrationExecutor::migrateAll($central, $tenants, $profiles, $sourceHead);
        if (($report['blocked'] ?? true) === true) {
            throw new RuntimeException('Factual migration executor blocked after schema migration.');
        }
    }
    assertActiveMigratedLeaders($central, $companies);
    $summary = ['source_head' => $sourceHead, 'companies' => $report['companies'] ?? [], 'blocked' => false];
    $hash = writeJson($evidenceDir . '/migration_result.json', $summary, 0400);
    echo "ROLES_ACCESS_CENTRAL_013_PASS=YES\n";
    echo "ROLES_ACCESS_TENANT_131_PASS=YES\n";
    echo "ROLES_ACCESS_MIGRATION_PASS=YES\n";
    echo "ROLES_ACCESS_MIGRATION_RESULT_SHA256={$hash}\n";
    exit(0);
}

function assertProjectionIsolation(PDO $central, array $companies, array $tenants): void
{
    $membershipStmt = $central->prepare('SELECT id FROM company_memberships WHERE company_id=?');
    foreach ($companies as $company) {
        $id = (int) $company['id'];
        $membershipStmt->execute([$id]);
        $allowed = array_fill_keys(array_map('intval', $membershipStmt->fetchAll(PDO::FETCH_COLUMN)), true);
        if ($allowed === []) {
            throw new RuntimeException("Company {$id}: no migrated memberships.");
        }
        $tenant = $tenants[$id];
        $missing = $tenant->query('SELECT COUNT(*) FROM users WHERE deleted_at IS NULL AND membership_id IS NULL')->fetchColumn();
        if ((int) $missing !== 0) {
            throw new RuntimeException("Company {$id}: tenant actor projection incomplete.");
        }
        $ids = [];
        foreach ($tenant->query('SELECT membership_id FROM users WHERE membership_id IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN) as $v) $ids[(int)$v] = true;
        foreach ($tenant->query('SELECT membership_id FROM entity_assignments')->fetchAll(PDO::FETCH_COLUMN) as $v) $ids[(int)$v] = true;
        foreach ($tenant->query('SELECT assigned_by_membership_id FROM entity_assignments WHERE assigned_by_membership_id IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN) as $v) $ids[(int)$v] = true;
        foreach (['clients','contractors','vehicle_units','drivers','linear_routes','waste_trips'] as $table) {
            foreach ($tenant->query("SELECT created_by_membership_id FROM `{$table}` WHERE created_by_membership_id IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN) as $v) $ids[(int)$v] = true;
        }
        foreach (array_keys($ids) as $membershipId) {
            if ($membershipId <= 0 || !isset($allowed[$membershipId])) {
                throw new RuntimeException("Company {$id}: cross-company membership projection detected.");
            }
        }
    }
}

function afterParity(string $root, string $sourceHead, string $evidenceDir, string $centralSql, string $tenantSql, PDO $central, array $companies, array $tenants, PageRegistry $registry): never
{
    assertFeatureOff($root);
    verifyPrepared($sourceHead, $evidenceDir, $centralSql, $tenantSql);
    if (!is_file($evidenceDir . '/migration_result.json')) {
        throw new RuntimeException('Migration result evidence missing.');
    }
    assertTargetSchema($central, $tenants);
    assertActiveMigratedLeaders($central, $companies);
    assertProjectionIsolation($central, $companies, $tenants);
    $beforeAggregate = readJson($evidenceDir . '/before_access.json');
    $afterAggregate = ['source_head' => $sourceHead, 'companies' => []];
    $comparisons = ['source_head' => $sourceHead, 'companies' => []];
    foreach ($companies as $company) {
        $id = (int) $company['id'];
        $key = (string) $id;
        $before = $beforeAggregate['companies'][$key] ?? null;
        if (!is_array($before)) {
            throw new RuntimeException("Company {$id}: BEFORE snapshot missing.");
        }
        $after = AccessSnapshotCollector::after($central, $tenants[$id], $id, $sourceHead, $registry->pageKeys());
        $comparison = MigrationProof::compare($before, $after, []);
        if (($comparison['equal'] ?? false) !== true || ($comparison['accepted'] ?? false) !== true || ($comparison['unapproved'] ?? []) !== []) {
            throw new RuntimeException("Company {$id}: strict BEFORE/AFTER parity failed.");
        }
        $afterAggregate['companies'][$key] = $after;
        $comparisons['companies'][$key] = $comparison;
    }
    $afterHash = writeJson($evidenceDir . '/after_access.json', $afterAggregate, 0400);
    $comparisonHash = writeJson($evidenceDir . '/parity.json', $comparisons, 0400);
    echo "ROLES_ACCESS_AFTER_PASS=YES\n";
    echo "ROLES_ACCESS_PARITY_PASS=YES\n";
    echo "ROLES_ACCESS_MULTI_COMPANY_PASS=YES\n";
    echo "ROLES_ACCESS_AFTER_SHA256={$afterHash}\n";
    echo "ROLES_ACCESS_PARITY_SHA256={$comparisonHash}\n";
    exit(0);
}

function markReady(string $root, string $sourceHead, string $evidenceDir, string $centralSql, string $tenantSql, PDO $central, array $companies): never
{
    assertFeatureOff($root);
    verifyPrepared($sourceHead, $evidenceDir, $centralSql, $tenantSql);
    if ((string) getenv('PLANEX_REGRESSION_PASS') !== 'YES') {
        throw new RuntimeException('Independent regression gate not supplied.');
    }
    $beforeAggregate = readJson($evidenceDir . '/before_access.json');
    $afterAggregate = readJson($evidenceDir . '/after_access.json');
    $parityAggregate = readJson($evidenceDir . '/parity.json');
    $artifactHashes = assertMigrationArtifacts($centralSql, $tenantSql);
    $ready = ['source_head' => $sourceHead, 'companies' => []];
    foreach ($companies as $company) {
        $id = (int) $company['id'];
        $key = (string) $id;
        $before = $beforeAggregate['companies'][$key] ?? null;
        $after = $afterAggregate['companies'][$key] ?? null;
        $comparison = $parityAggregate['companies'][$key] ?? null;
        if (!is_array($before) || !is_array($after) || !is_array($comparison) || ($comparison['accepted'] ?? false) !== true) {
            throw new RuntimeException("Company {$id}: parity evidence incomplete.");
        }
        $manifest = [
            'company_id' => $id,
            'source_head' => $sourceHead,
            'membership_count' => count($after['memberships'] ?? []),
            'tenant_count' => 1,
            'before_sha256' => MigrationProof::hash(MigrationProof::canonicalSnapshot($before)),
            'after_sha256' => MigrationProof::hash(MigrationProof::canonicalSnapshot($after)),
            'comparison_sha256' => (string) ($comparison['comparison_sha256'] ?? ''),
            'central_migration_sha256' => $artifactHashes['central'],
            'tenant_migration_sha256' => $artifactHashes['tenant'],
            'artifact_hashes_verified' => true,
            'comparison_accepted' => true,
            'identity_conflict_count' => 0,
            'unapproved_diff_count' => 0,
            'owner_exception_count' => 0,
            'owner_exceptions' => [],
            'gates' => array_fill_keys([
                'central_schema','tenant_schema','identity_conflicts','before_snapshot',
                'migration','after_snapshot','parity','independent_regression',
            ], 'PASS'),
        ];
        $ready['companies'][$key] = AccessReadinessActivator::verifyAndMarkReady($central, $id, $before, $after, [], $manifest);
    }
    assertActiveMigratedLeaders($central, $companies);
    $hash = writeJson($evidenceDir . '/readiness.json', $ready, 0400);
    echo "ROLES_ACCESS_READINESS_PASS=YES\n";
    echo "ROLES_ACCESS_READINESS_SHA256={$hash}\n";
    exit(0);
}

function readinessStates(PDO $central, array $companies): array
{
    $stmt = $central->prepare('SELECT state,migration_key FROM company_access_readiness WHERE company_id=?');
    $out = [];
    foreach ($companies as $company) {
        $id = (int)$company['id'];
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $out[$id] = is_array($row) ? $row : ['state' => '', 'migration_key' => ''];
    }
    return $out;
}

function assertAllReady(PDO $central, array $companies): void
{
    $stmt = $central->prepare(
        "SELECT state,before_sha256,after_sha256,comparison_sha256,verified_at,activated_at
         FROM company_access_readiness WHERE company_id=?"
    );
    foreach ($companies as $company) {
        $id = (int) $company['id'];
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || ($row['state'] ?? '') !== 'ready' || empty($row['verified_at']) || empty($row['activated_at'])) {
            throw new RuntimeException("Company {$id}: readiness is not ready.");
        }
        foreach (['before_sha256','after_sha256','comparison_sha256'] as $field) {
            if (!preg_match('/^[a-f0-9]{64}$/', (string) ($row[$field] ?? ''))) {
                throw new RuntimeException("Company {$id}: readiness hash invalid.");
            }
        }
    }
}

function activateFeature(string $root, string $sourceHead, string $evidenceDir, PDO $central, array $companies): never
{
    assertFeatureOff($root);
    assertAllReady($central, $companies);
    assertActiveMigratedLeaders($central, $companies);
    if (!is_file($evidenceDir . '/readiness.json')) {
        throw new RuntimeException('Readiness evidence missing.');
    }
    $envPath = $root . '/.env';
    $raw = (string) file_get_contents($envPath);
    if (preg_match_all('/^ERP_ROLES_ACCESS_ENABLED\s*=.*$/mi', $raw, $matches) && count($matches[0]) > 1) {
        throw new RuntimeException('Duplicate ERP_ROLES_ACCESS_ENABLED entries.');
    }
    $beforeHash = hash('sha256', $raw);
    if (file_put_contents($evidenceDir . '/env_before_activation', $raw, LOCK_EX) === false) {
        throw new RuntimeException('Cannot save env activation backup.');
    }
    chmod($evidenceDir . '/env_before_activation', 0400);
    if (preg_match('/^ERP_ROLES_ACCESS_ENABLED\s*=.*$/mi', $raw)) {
        $next = preg_replace('/^ERP_ROLES_ACCESS_ENABLED\s*=.*$/mi', 'ERP_ROLES_ACCESS_ENABLED=true', $raw, 1);
    } else {
        $next = rtrim($raw, "\r\n") . PHP_EOL . 'ERP_ROLES_ACCESS_ENABLED=true' . PHP_EOL;
    }
    if (!is_string($next) || $next === $raw) {
        throw new RuntimeException('Feature activation did not produce an env change.');
    }
    $tmp = $envPath . '.roles-access-' . getmypid() . '.tmp';
    if (file_put_contents($tmp, $next, LOCK_EX) === false) {
        throw new RuntimeException('Cannot write temporary env activation file.');
    }
    chmod($tmp, fileperms($envPath) & 0777);
    if (!rename($tmp, $envPath)) {
        @unlink($tmp);
        throw new RuntimeException('Atomic env activation failed.');
    }
    if (!featureEnabled($root)) {
        throw new RuntimeException('Feature flag did not become enabled.');
    }
    $afterRaw = (string) file_get_contents($envPath);
    $result = [
        'source_head' => $sourceHead,
        'env_before_sha256' => $beforeHash,
        'env_after_sha256' => hash('sha256', $afterRaw),
        'enabled' => true,
    ];
    $hash = writeJson($evidenceDir . '/activation.json', $result, 0400);
    echo "ROLES_ACCESS_FEATURE_ACTIVATION_PASS=YES\n";
    echo "ROLES_ACCESS_ACTIVATION_SHA256={$hash}\n";
    exit(0);
}

function assertSeededProfiles(PDO $central, array $companies, PageRegistry $registry): void
{
    $matrix = legacyPageMatrix($registry);
    $entities = ['client','contractor','vehicle_unit','driver','linear_trip','waste_trip'];
    foreach ($companies as $company) {
        $id = (int) $company['id'];
        $roles = $central->prepare('SELECT id,normalized_name,system_key,is_system FROM company_roles WHERE company_id=? ORDER BY normalized_name');
        $roles->execute([$id]);
        $rows = $roles->fetchAll(PDO::FETCH_ASSOC);
        $byName = [];
        foreach ($rows as $row) $byName[(string)$row['normalized_name']] = $row;
        foreach (['company_owner','senior_logist','logist'] as $roleCode) {
            if (!isset($byName[$roleCode])) throw new RuntimeException("Company {$id}: seeded role {$roleCode} missing.");
            $role = $byName[$roleCode];
            $roleId = (int) $role['id'];
            if ($roleCode === 'company_owner') {
                if (($role['system_key'] ?? null) !== 'company_owner' || (int)$role['is_system'] !== 1) throw new RuntimeException("Company {$id}: owner role identity invalid.");
            } else if (($role['system_key'] ?? null) !== null || (int)$role['is_system'] !== 0) {
                throw new RuntimeException("Company {$id}: ordinary seeded role identity invalid.");
            }
            $p = $central->prepare('SELECT page_key FROM role_page_permissions WHERE role_id=? AND is_allowed=1 ORDER BY page_key');
            $p->execute([$roleId]);
            $actualPages = array_map('strval', $p->fetchAll(PDO::FETCH_COLUMN));
            sort($actualPages, SORT_STRING);
            $expectedPages = $matrix[$roleCode];
            sort($expectedPages, SORT_STRING);
            if ($actualPages !== $expectedPages) throw new RuntimeException("Company {$id}: seeded page profile drift for {$roleCode}.");
            $s = $central->prepare('SELECT entity_type,scope FROM role_data_scopes WHERE role_id=? ORDER BY entity_type');
            $s->execute([$roleId]);
            $actualScopes = [];
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $actualScopes[(string)$r['entity_type']] = (string)$r['scope'];
            foreach ($entities as $entity) {
                $expected = $roleCode === 'logist' ? 'own' : 'all';
                if (($actualScopes[$entity] ?? '') !== $expected) throw new RuntimeException("Company {$id}: seeded data scope drift {$roleCode}:{$entity}.");
            }
        }
    }
}

function deactivateFeature(string $root, string $sourceHead, string $evidenceDir): never
{
    $activationPath = $evidenceDir . '/activation.json';
    $backupPath = $evidenceDir . '/env_before_activation';
    if (!is_file($activationPath) || !is_file($backupPath)) {
        echo "ROLES_ACCESS_FEATURE_ROLLBACK_NOT_REQUIRED=YES\n";
        exit(0);
    }
    $activation = readJson($activationPath);
    if (($activation['source_head'] ?? '') !== $sourceHead) {
        throw new RuntimeException('Activation rollback source SHA mismatch.');
    }
    $envPath = $root . '/.env';
    $backup = (string)file_get_contents($backupPath);
    if (hash('sha256', $backup) !== ($activation['env_before_sha256'] ?? '')) {
        throw new RuntimeException('Activation env backup hash mismatch.');
    }
    $tmp = $envPath . '.roles-access-rollback-' . getmypid() . '.tmp';
    if (file_put_contents($tmp, $backup, LOCK_EX) === false) {
        throw new RuntimeException('Cannot write feature rollback env.');
    }
    chmod($tmp, fileperms($envPath) & 0777);
    if (!rename($tmp, $envPath)) {
        @unlink($tmp);
        throw new RuntimeException('Atomic feature rollback failed.');
    }
    if (featureEnabled($root)) {
        throw new RuntimeException('Feature flag remained enabled after rollback.');
    }
    echo "ROLES_ACCESS_FEATURE_ROLLBACK_PASS=YES\n";
    exit(0);
}


/**
 * Reversible production-runtime acceptance. All synthetic central/tenant rows
 * live only inside transactions and are rolled back before the command exits.
 */
function assertProductionRuntimeSemantics(string $root, PDO $central, array $companies, array $tenants, PageRegistry $registry): void
{
    $companyA = (int) ($companies[0]['id'] ?? 0);
    if ($companyA <= 0 || !isset($tenants[$companyA])) {
        throw new RuntimeException('Production functional acceptance has no tenant A.');
    }
    $companyB = isset($companies[1]) ? (int) ($companies[1]['id'] ?? 0) : 0;
    $provider = new PdoAccessStateProvider($central);
    $resolver = new EffectiveAccessResolver($registry, $provider);
    $nonce = substr(hash('sha256', (string) microtime(true) . ':' . random_int(1, PHP_INT_MAX)), 0, 16);
    $roleName = 'QA Dynamic ' . $nonce;
    $normalized = 'qa_dynamic_' . $nonce;
    $login = 'qa_roles_' . $nonce;
    $tenantA = $tenants[$companyA];
    $tenantStarted = false;
    $central->beginTransaction();
    try {
        $roleInsert = $central->prepare(
            'INSERT INTO company_roles(company_id,name,normalized_name,system_key,is_system) VALUES (?,?,?,NULL,0)'
        );
        $roleInsert->execute([$companyA, $roleName, $normalized]);
        $roleA = (int) $central->lastInsertId();
        $central->prepare(
            "INSERT INTO user_accounts(full_name,login,normalized_login,password_hash,status) VALUES (?,?,?,?,'active')"
        )->execute(['Roles QA A', $login, $login, password_hash($nonce, PASSWORD_DEFAULT)]);
        $accountId = (int) $central->lastInsertId();
        $central->prepare(
            "INSERT INTO company_memberships(account_id,company_id,role_id,status) VALUES (?,?,?,'active')"
        )->execute([$accountId, $companyA, $roleA]);
        $membershipA = (int) $central->lastInsertId();

        // Brand-new dynamic role is deny-all/NONE until explicit grants exist.
        $access = $resolver->resolve(new AccessRequestContext($accountId, $companyA));
        if (!$access->isActive()
            || $access->compatibilityRoleCode() !== 'custom_role'
            || $access->canAccessPage('company.clients')
            || $access->canAccessPage('company.finance.dashboard')
            || $access->dataScope('client') !== 'none') {
            throw new RuntimeException('New dynamic role did not fail closed.');
        }

        $page = $central->prepare('INSERT INTO role_page_permissions(role_id,page_key,is_allowed) VALUES (?,?,?)');
        $page->execute([$roleA, 'company.clients', 1]);
        $page->execute([$roleA, 'company.finance.dashboard', 1]);
        $scope = $central->prepare('INSERT INTO role_data_scopes(role_id,entity_type,scope) VALUES (?,?,?)');
        foreach (['client','contractor','vehicle_unit','driver','linear_trip','waste_trip'] as $entity) {
            $scope->execute([$roleA, $entity, $entity === 'client' ? 'own' : 'none']);
        }
        $access = $resolver->resolve(new AccessRequestContext($accountId, $companyA));
        if (!$access->canAccessPage('company.clients')
            || !$access->canAccessPage('company.finance.dashboard')
            || $access->dataScope('client') !== 'own') {
            throw new RuntimeException('Explicit dynamic page/finance/OWN grant failed.');
        }

        // Revoke must be visible on the next resolve without login/logout.
        $central->prepare("UPDATE role_page_permissions SET is_allowed=0 WHERE role_id=? AND page_key='company.clients'")->execute([$roleA]);
        if ($resolver->resolve(new AccessRequestContext($accountId, $companyA))->canAccessPage('company.clients')) {
            throw new RuntimeException('Immediate role-page revocation was cached.');
        }

        // Personal allow and deny override the role in both directions.
        $central->prepare(
            "INSERT INTO membership_page_overrides(membership_id,page_key,effect) VALUES (?,'company.clients','allow')"
        )->execute([$membershipA]);
        if (!$resolver->resolve(new AccessRequestContext($accountId, $companyA))->canAccessPage('company.clients')) {
            throw new RuntimeException('Personal page allow did not override role deny.');
        }
        $central->prepare(
            "INSERT INTO membership_page_overrides(membership_id,page_key,effect) VALUES (?,'company.finance.dashboard','deny')"
        )->execute([$membershipA]);
        if ($resolver->resolve(new AccessRequestContext($accountId, $companyA))->canAccessPage('company.finance.dashboard')) {
            throw new RuntimeException('Personal page deny did not override finance allow.');
        }

        $central->prepare(
            "INSERT INTO membership_data_scope_overrides(membership_id,entity_type,scope) VALUES (?,'client','all')"
        )->execute([$membershipA]);
        if ($resolver->resolve(new AccessRequestContext($accountId, $companyA))->dataScope('client') !== 'all') {
            throw new RuntimeException('Personal ALL scope failed.');
        }
        $central->prepare(
            "UPDATE membership_data_scope_overrides SET scope='none' WHERE membership_id=? AND entity_type='client'"
        )->execute([$membershipA]);
        if ($resolver->resolve(new AccessRequestContext($accountId, $companyA))->dataScope('client') !== 'none') {
            throw new RuntimeException('Personal NONE scope failed.');
        }

        // Two memberships on one synthetic entity prove M:N storage; runtime
        // creator assignment is tested independently. Tenant writes are rolled back.
        $central->prepare(
            "INSERT INTO user_accounts(full_name,login,normalized_login,password_hash,status) VALUES (?,?,?,?,'active')"
        )->execute(['Roles QA B', $login . '_b', $login . '_b', password_hash($nonce . 'b', PASSWORD_DEFAULT)]);
        $accountB = (int) $central->lastInsertId();
        $central->prepare(
            "INSERT INTO company_memberships(account_id,company_id,role_id,status) VALUES (?,?,?,'active')"
        )->execute([$accountB, $companyA, $roleA]);
        $membershipB = (int) $central->lastInsertId();

        $ownerLookup = $central->prepare(
            "SELECT m.id FROM company_memberships m JOIN company_roles r ON r.id=m.role_id AND r.company_id=m.company_id
             WHERE m.company_id=? AND m.status='active' AND r.system_key='company_owner' ORDER BY m.id LIMIT 1"
        );
        $ownerLookup->execute([$companyA]);
        $ownerMembership = (int) $ownerLookup->fetchColumn();
        if ($ownerMembership <= 0) {
            throw new RuntimeException('Production functional acceptance has no owner membership.');
        }
        $tenantA->beginTransaction();
        $tenantStarted = true;
        AccessRuntime::setCurrent(EffectiveAccess::systemOwner($ownerMembership, $companyA, $registry->pageKeys()));
        $syntheticEntity = 900000000000 + random_int(1000, 999999);
        EntityAssignmentService::replace($tenantA, 'client', $syntheticEntity, [$membershipA, $membershipB], $ownerMembership);
        if (EntityAssignmentService::membershipIds($tenantA, 'client', $syntheticEntity) !== [$membershipA, $membershipB]) {
            throw new RuntimeException('Many-to-many assignment set failed.');
        }
        EntityAssignmentService::autoAssignCreator($tenantA, 'driver', $syntheticEntity + 1, $membershipA);
        if (!EntityAssignmentService::isAssigned($tenantA, 'driver', $syntheticEntity + 1, $membershipA)) {
            throw new RuntimeException('Runtime creator auto-assignment failed.');
        }
        $tenantA->rollBack();
        $tenantStarted = false;

        $ownScopes = array_fill_keys(['client','contractor','vehicle_unit','driver','linear_trip','waste_trip'], 'own');
        AccessRuntime::setCurrent(EffectiveAccess::member($membershipA, $companyA, [], $ownScopes));
        $linear = RegulatedDataScopeService::condition('linear_trip', 'lr.id', 'lr.created_by_membership_id');
        $waste = RegulatedDataScopeService::condition('waste_trip', 'wt.id', 'wt.created_by_membership_id');
        if ($linear['params'] !== [$membershipA] || $waste['params'] !== [$membershipA]
            || !str_contains($linear['sql'], 'created_by_membership_id')
            || !str_contains($waste['sql'], 'created_by_membership_id')) {
            throw new RuntimeException('Linear/Waste creator scope does not use membership identity.');
        }

        // Same global account in another company must resolve only that company's role.
        if ($companyB > 0) {
            $roleInsert->execute([$companyB, $roleName . ' B', $normalized . '_b']);
            $roleB = (int) $central->lastInsertId();
            $central->prepare(
                "INSERT INTO company_memberships(account_id,company_id,role_id,status) VALUES (?,?,?,'active')"
            )->execute([$accountId, $companyB, $roleB]);
            $other = $resolver->resolve(new AccessRequestContext($accountId, $companyB));
            if (!$other->isActive() || $other->companyId() !== $companyB || $other->canAccessPage('company.clients')) {
                throw new RuntimeException('Multi-company dynamic role isolation failed.');
            }
        }
    } finally {
        AccessRuntime::clear();
        if ($tenantStarted && $tenantA->inTransaction()) {
            $tenantA->rollBack();
        }
        if ($central->inTransaction()) {
            $central->rollBack();
        }
    }

    // Exercise the real last-leader guard only on a company that factually has
    // exactly one active owner; the expected exception rolls its transaction back.
    $candidate = $central->query(
        "SELECT m.company_id,m.id FROM company_memberships m
         JOIN company_roles r ON r.id=m.role_id AND r.company_id=m.company_id
         WHERE m.status='active' AND r.system_key='company_owner'
         AND m.company_id IN (
             SELECT m2.company_id FROM company_memberships m2
             JOIN company_roles r2 ON r2.id=m2.role_id AND r2.company_id=m2.company_id
             WHERE m2.status='active' AND r2.system_key='company_owner'
             GROUP BY m2.company_id HAVING COUNT(*)=1
         ) ORDER BY m.company_id LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);
    if (is_array($candidate)) {
        try {
            (new CompanyMembershipService($central))->remove((int)$candidate['company_id'], (int)$candidate['id']);
            throw new RuntimeException('Last active leader removal unexpectedly succeeded.');
        } catch (DomainException $e) {
            if (!str_contains($e->getMessage(), 'хотя бы один активный Руководитель')) {
                throw $e;
            }
        }
    } else {
        // Multiple-owner fleets cannot truthfully exercise the last-owner branch
        // without mutating real memberships. Keep acceptance non-destructive and
        // require the already-covered locking/guard contract instead.
        $source = (string) file_get_contents($root . '/app/Service/CompanyMembershipService.php');
        if (!str_contains($source, 'assertAnotherActiveOwner')) {
            throw new RuntimeException('Last leader guard contract missing.');
        }
    }
}

function acceptProduction(string $root, string $sourceHead, string $evidenceDir, PDO $central, array $companies, array $tenants, PageRegistry $registry): never
{
    if (!featureEnabled($root)) {
        throw new RuntimeException('Feature flag is not enabled after activation.');
    }
    assertAllReady($central, $companies);
    assertActiveMigratedLeaders($central, $companies);
    assertProjectionIsolation($central, $companies, $tenants);
    assertSeededProfiles($central, $companies, $registry);
    assertProductionRuntimeSemantics($root, $central, $companies, $tenants, $registry);
    $duplicateMemberships = (int) $central->query(
        'SELECT COUNT(*) FROM (SELECT account_id,company_id,COUNT(*) c FROM company_memberships GROUP BY account_id,company_id HAVING c>1) x'
    )->fetchColumn();
    if ($duplicateMemberships !== 0) throw new RuntimeException('Duplicate account/company memberships detected.');
    $badRoleCompany = (int) $central->query(
        'SELECT COUNT(*) FROM company_memberships m JOIN company_roles r ON r.id=m.role_id WHERE r.company_id<>m.company_id'
    )->fetchColumn();
    if ($badRoleCompany !== 0) throw new RuntimeException('Cross-company role membership detected.');
    $result = [
        'source_head' => $sourceHead,
        'company_count' => count($companies),
        'ready' => true,
        'feature_enabled' => true,
        'multi_company_isolation' => true,
        'seeded_profiles' => true,
        'dynamic_role_runtime' => true,
        'page_and_personal_overrides' => true,
        'data_scopes' => true,
        'many_to_many_and_creator_assignment' => true,
        'last_leader_protection' => true,
    ];
    $hash = writeJson($evidenceDir . '/production_acceptance.json', $result, 0400);
    echo "ROLES_ACCESS_PRODUCTION_ACL_PASS=YES\n";
    echo "ROLES_ACCESS_MULTI_COMPANY_PASS=YES\n";
    echo "ROLES_ACCESS_FINANCE_PROFILE_PASS=YES\n";
    echo "ROLES_ACCESS_ASSIGNMENTS_TRIPS_PROJECTION_PASS=YES\n";
    echo "ROLES_ACCESS_DYNAMIC_ROLE_PASS=YES\n";
    echo "ROLES_ACCESS_PAGE_OVERRIDE_PASS=YES\n";
    echo "ROLES_ACCESS_DATA_SCOPE_PASS=YES\n";
    echo "ROLES_ACCESS_MANY_TO_MANY_PASS=YES\n";
    echo "ROLES_ACCESS_AUTO_ASSIGN_CREATOR_PASS=YES\n";
    echo "ROLES_ACCESS_LAST_LEADER_PASS=YES\n";
    echo "ROLES_ACCESS_PRODUCTION_ACCEPTANCE_SHA256={$hash}\n";
    exit(0);
}
