<?php

declare(strict_types=1);

use App\Security\AccessRequestContext;
use App\Security\AccessRuntime;
use App\Security\EffectiveAccess;
use App\Security\EffectiveAccessResolver;
use App\Security\PageRegistry;
use App\Security\PdoAccessStateProvider;
use App\Service\CompanyMembershipService;
use App\Service\CompanyLandingPageService;
use App\Service\CompanyRoleService;
use App\Service\EntityAssignmentService;
use App\Service\RegulatedDataScopeService;

$root = dirname(__DIR__);
require_once $root . '/bootstrap/app.php';
require_once BASE_PATH . '/app/Support/company_database.php';
foreach ([
    '/app/Security/AccessStateProvider.php',
    '/app/Security/AccessRequestContext.php',
    '/app/Security/MembershipAccessSnapshot.php',
    '/app/Security/EffectiveAccess.php',
    '/app/Security/AccessRuntime.php',
    '/app/Security/PageRegistry.php',
    '/app/Security/EffectiveAccessResolver.php',
    '/app/Security/PdoAccessStateProvider.php',
    '/app/Service/AccessControlService.php',
    '/app/Service/IdentityNormalizer.php',
    '/app/Service/CompanyRoleService.php',
    '/app/Service/CompanyMembershipService.php',
    '/app/Service/CompanyLandingPageService.php',
    '/app/Service/LegacyAccessProjectionService.php',
    '/app/Service/EntityResponsibilityService.php',
    '/app/Service/RegulatedDataScopeService.php',
    '/app/Service/EntityAssignmentService.php',
] as $file) {
    require_once BASE_PATH . $file;
}

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
$ok = static function (bool $value, string $message): void {
    if (!$value) {
        throw new RuntimeException($message);
    }
};
$throws = static function (callable $fn, string $contains, string $message) use ($ok): void {
    try {
        $fn();
    } catch (Throwable $e) {
        $ok(str_contains($e->getMessage(), $contains), $message . ': wrong exception ' . $e->getMessage());
        return;
    }
    throw new RuntimeException($message . ': no exception');
};

cleanCompanyPoolDatabase($pdo);
try {
    // Central ACL schema.
    $pdo->exec("CREATE TABLE companies (id INT UNSIGNED NOT NULL PRIMARY KEY,name VARCHAR(255) NOT NULL,status VARCHAR(20) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec((string) file_get_contents(BASE_PATH . '/database/migrations/013_create_roles_access.sql'));
    $pdo->exec((string) file_get_contents(BASE_PATH . '/database/migrations/014_add_role_default_page.sql'));
    $pdo->exec((string) file_get_contents(BASE_PATH . '/database/migrations/015_add_user_accounts_max_user_id.sql'));
    $pdo->exec("INSERT INTO companies(id,name,status) VALUES(7,'QA One','active'),(8,'QA Two','active')");
    $hash = str_repeat('a', 64);
    $ready = $pdo->prepare("INSERT INTO company_access_readiness(company_id,state,before_sha256,after_sha256,comparison_sha256,verified_at,activated_at) VALUES(?,'ready',?,?,?,NOW(),NOW())");
    foreach ([7, 8] as $companyId) {
        $ready->execute([$companyId, $hash, $hash, $hash]);
    }

    $pdo->exec("INSERT INTO company_roles(id,company_id,name,normalized_name,system_key,is_system) VALUES
      (1,7,'Руководитель','company_owner','company_owner',1),
      (2,7,'Логист','logist',NULL,0),
      (9,8,'Foreign','foreign',NULL,0)");
    foreach ([1,2,9] as $roleId) {
        foreach (CompanyRoleService::regulatedEntities() as $entityType) {
            $scope = $roleId === 1 ? 'all' : ($roleId === 2 ? 'own' : 'none');
            $stmt = $pdo->prepare('INSERT INTO role_data_scopes(role_id,entity_type,scope) VALUES(?,?,?)');
            $stmt->execute([$roleId, $entityType, $scope]);
        }
    }
    $pdo->exec("INSERT INTO role_page_permissions(role_id,page_key,is_allowed) VALUES
      (2,'company.dashboard',1),(2,'company.clients',1),(2,'company.finance.dashboard',0),
      (9,'company.dashboard',1)");

    foreach ([
        [101, 'Owner', 'owner', 'owner'],
        [102, 'Logist', 'logist', 'logist'],
        [109, 'Foreign', 'foreign', 'foreign'],
    ] as [$id, $name, $login, $norm]) {
        $stmt = $pdo->prepare("INSERT INTO user_accounts(id,full_name,login,normalized_login,password_hash,status) VALUES(?,?,?,?,?,'active')");
        $stmt->execute([$id, $name, $login, $norm, password_hash('Pass!' . $id, PASSWORD_DEFAULT)]);
    }
    $pdo->exec("INSERT INTO company_memberships(id,account_id,company_id,role_id,status) VALUES
      (11,101,7,1,'active'),(12,102,7,2,'active'),(19,109,8,9,'active')");

    // Tenant projection schema in the same isolated DB.
    $pdo->exec("CREATE TABLE users (id INT UNSIGNED NOT NULL AUTO_INCREMENT, PRIMARY KEY(id)) ENGINE=InnoDB");
    foreach (['clients','contractors','vehicle_units','vehicle_sets','drivers','linear_routes','waste_trips'] as $table) {
        $extra = $table === 'vehicle_sets' ? ', primary_vehicle_unit_id BIGINT UNSIGNED NULL, secondary_vehicle_unit_id BIGINT UNSIGNED NULL' : '';
        $pdo->exec("CREATE TABLE `{$table}` (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, created_by_user_id INT UNSIGNED NULL{$extra}, PRIMARY KEY(id)) ENGINE=InnoDB");
    }
    $pdo->exec((string) file_get_contents(BASE_PATH . '/database/migrations-local/131_create_roles_access_projection.sql'));
    $pdo->exec((string) file_get_contents(BASE_PATH . '/database/migrations-local/132_split_assignment_and_responsibility.sql'));

    // Factual rows: id=1 is not created by logist; id=2 is creator-owned by logist.
    foreach (['clients','contractors','vehicle_units','drivers','linear_routes','waste_trips'] as $table) {
        $pdo->exec("INSERT INTO `{$table}`(id,created_by_user_id,created_by_membership_id) VALUES(1,NULL,11),(2,NULL,12)");
    }
    $pdo->exec("INSERT INTO vehicle_sets(id,created_by_user_id,created_by_membership_id) VALUES(1,NULL,11)");

    $registry = new PageRegistry();
    $pageKeys = $registry->pageKeys();
    $grantablePageKeys = $registry->grantablePageKeys();
    $landingPageKeys = array_values(array_map(static fn(array $p): string => (string)$p['key'], array_filter($registry->landingPages(), static fn(array $p): bool => (bool)($p['grantable'] ?? true))));
    $roleService = new CompanyRoleService($pdo);
    $membershipService = new CompanyMembershipService($pdo);

    // ROLE CRUD: create -> rename/pages/6 scopes -> readback -> copy -> cleanup.
    $role = $roleService->create(7, 'QA Temporary Role');
    $roleId = (int) $role['id'];
    $pdo->prepare("INSERT INTO role_page_permissions(role_id,page_key,is_allowed) VALUES(?,'company.warehouses.stock',1)")->execute([$roleId]);
    $legacyRole = null;
    foreach ($roleService->listForCompany(7, $pageKeys) as $candidate) if ((int) $candidate['id'] === $roleId) $legacyRole = $candidate;
    $ok(($legacyRole['pages']['company.warehouses.addresses'] ?? false) === true, 'existing warehouse role must show inherited address access before first save');
    $scopes = [
        'client' => 'own', 'contractor' => 'all', 'vehicle_unit' => 'none',
        'driver' => 'own', 'linear_trip' => 'all', 'waste_trip' => 'none',
    ];
    $pages = ['company.dashboard' => true, 'company.clients' => true, 'company.finance.dashboard' => true];
    $roleService->save(7, $roleId, 'QA Temporary Role Renamed', $pages, $scopes, 'company.finance.dashboard', $grantablePageKeys, $landingPageKeys);
    $roles = $roleService->listForCompany(7, $pageKeys);
    $savedRole = null;
    foreach ($roles as $candidate) if ((int) $candidate['id'] === $roleId) $savedRole = $candidate;
    $ok(is_array($savedRole) && $savedRole['name'] === 'QA Temporary Role Renamed', 'role rename/readback failed');
    foreach ($pages as $pageKey => $allowed) $ok(($savedRole['pages'][$pageKey] ?? null) === $allowed, 'role page readback failed ' . $pageKey);
    $ok(($savedRole['pages']['company.warehouses.addresses'] ?? null) === false, 'saved role must keep warehouse addresses independently disabled');
    foreach ($scopes as $entityType => $scope) $ok(($savedRole['data_scopes'][$entityType] ?? null) === $scope, 'role scope readback failed ' . $entityType);
    $ok(($savedRole['default_page_key'] ?? null) === 'company.finance.dashboard', 'role default page readback failed');
    $copy = $roleService->copy(7, $roleId, 'QA Temporary Role Copy', $pageKeys);
    $copyId = (int) $copy['id'];
    $roles = $roleService->listForCompany(7, $pageKeys);
    $copied = null;
    foreach ($roles as $candidate) if ((int) $candidate['id'] === $copyId) $copied = $candidate;
    $ok(is_array($copied), 'copied role missing');
    foreach ($pages as $pageKey => $allowed) $ok(($copied['pages'][$pageKey] ?? null) === $allowed, 'copied role page mismatch ' . $pageKey);
    foreach ($scopes as $entityType => $scope) $ok(($copied['data_scopes'][$entityType] ?? null) === $scope, 'copied role scope mismatch ' . $entityType);
    $throws(fn() => $roleService->save(7, 1, 'Owner Changed', [], []), 'Системную роль нельзя', 'protected owner save guard failed');
    $throws(fn() => $roleService->delete(7, 1), 'Системную роль нельзя', 'protected owner delete guard failed');

    // MEMBERSHIP: isolated creation/removal plus personal overrides/readback.
    $added = $membershipService->add(7, $roleId, 'QA Membership', 'qa_membership', 'TempPass!123', null, null, 'active', '712345678901234567', true);
    $tempMembershipId = (int) $added['membership_id'];
    $membershipService->save(7, $tempMembershipId, $roleId, 'active', [
        'company.clients' => 'deny',
        'company.finance.dashboard' => 'allow',
    ], [
        'client' => 'all',
        'driver' => 'none',
    ], $grantablePageKeys);
    $members = $membershipService->listForCompany(7, $pageKeys);
    $savedMember = null;
    foreach ($members as $candidate) if ((int) $candidate['id'] === $tempMembershipId) $savedMember = $candidate;
    $ok(is_array($savedMember), 'membership create/readback failed');
    $ok(($savedMember['max_user_id'] ?? null) === '712345678901234567', 'MAX ID create/readback failed');
    $ok(($savedMember['page_overrides']['company.clients'] ?? '') === 'deny', 'personal page deny readback failed');
    $ok(($savedMember['page_overrides']['company.finance.dashboard'] ?? '') === 'allow', 'finance page override readback failed');
    $ok(($savedMember['data_scope_overrides']['client'] ?? '') === 'all', 'personal client scope readback failed');
    $ok(($savedMember['data_scope_overrides']['driver'] ?? '') === 'none', 'personal driver scope readback failed');

    // Next-request authority changes without relogin.
    $provider = new PdoAccessStateProvider($pdo);
    $resolver = new EffectiveAccessResolver($registry, $provider);
    $tempAccountId = (int) $added['account_id'];
    $access = $resolver->resolve(new AccessRequestContext($tempAccountId, 7));
    $ok(!$access->canAccessPage('company.clients') && $access->canAccessPage('company.finance.dashboard'), 'personal page overrides not effective on next resolve');
    $ok($access->dataScope('client') === 'all' && $access->dataScope('driver') === 'none', 'personal data overrides not effective on next resolve');
    $roleService->save(7, $roleId, 'QA Temporary Role Renamed', $pages + ['company.warehouses.stock'=>true], $scopes, 'company.finance.dashboard', $grantablePageKeys, $landingPageKeys);
    $warehouseAccess = $resolver->resolve(new AccessRequestContext($tempAccountId, 7));
    $ok($warehouseAccess->canAccessPage('company.warehouses.stock') && !$warehouseAccess->canAccessPage('company.warehouses.addresses'), 'saved role must deny addresses independently of stock');
    $roleService->save(7, $roleId, 'QA Temporary Role Renamed', $pages + ['company.warehouses.addresses'=>true], $scopes, 'company.finance.dashboard', $grantablePageKeys, $landingPageKeys);
    $warehouseAccess = $resolver->resolve(new AccessRequestContext($tempAccountId, 7));
    $ok($warehouseAccess->canAccessPage('company.warehouses.addresses') && !$warehouseAccess->canAccessPage('company.warehouses.stock'), 'saved role must grant addresses independently of stock');
    $roleService->save(7, $roleId, 'QA Temporary Role Renamed', $pages + ['company.route_executors'=>true], $scopes, 'company.finance.dashboard', $grantablePageKeys, $landingPageKeys);
    $driverTsAccess = $resolver->resolve(new AccessRequestContext($tempAccountId, 7));
    $ok($driverTsAccess->canAccessPage('company.route_executors') && !$driverTsAccess->canAccessPage('company.driver_ts_data'), 'saved route executor grant must not open driver and vehicle data');
    $roleService->save(7, $roleId, 'QA Temporary Role Renamed', $pages + ['company.driver_ts_data'=>true], $scopes, 'company.finance.dashboard', $grantablePageKeys, $landingPageKeys);
    $driverTsAccess = $resolver->resolve(new AccessRequestContext($tempAccountId, 7));
    $ok($driverTsAccess->canAccessPage('company.driver_ts_data') && !$driverTsAccess->canAccessPage('company.route_executors'), 'saved driver and vehicle grant must not open route executors');
    $roleService->save(7, $roleId, 'QA Temporary Role Renamed', $pages, $scopes, 'company.finance.dashboard', $grantablePageKeys, $landingPageKeys);
    $landingService = new CompanyLandingPageService($pdo, $registry);
    $ok($landingService->pathForAccount($tempAccountId, 7) === '/company/finance/dashboard', 'ordinary role default landing not resolved');
    $ok($landingService->pathForAccess(EffectiveAccess::systemOwner(11, 7, $pageKeys)) === '/company/dashboard', 'owner landing is not fixed to dashboard');
    $membershipService->save(7, $tempMembershipId, $roleId, 'active', ['company.clients' => 'allow'], ['client' => 'own'], $grantablePageKeys, '798765432109876543', true);
    $ok($pdo->query("SELECT max_user_id FROM user_accounts WHERE id={$tempAccountId}")->fetchColumn() === '798765432109876543', 'MAX ID edit failed');
    $membershipService->save(7, $tempMembershipId, $roleId, 'active', ['company.clients' => 'allow'], ['client' => 'own'], $grantablePageKeys, '', true);
    $ok($pdo->query("SELECT max_user_id FROM user_accounts WHERE id={$tempAccountId}")->fetchColumn() === null, 'MAX ID nullable clear failed');
    $access = $resolver->resolve(new AccessRequestContext($tempAccountId, 7));
    $ok($access->canAccessPage('company.clients') && $access->dataScope('client') === 'own', 'next-request override update was cached');

    // Last active leader and cross-company isolation.
    $throws(fn() => $membershipService->save(7, 11, 2, 'active', [], []), 'хотя бы один активный Руководитель', 'last leader role-change guard failed');
    $throws(fn() => $membershipService->save(7, 11, 1, 'blocked', [], []), 'хотя бы один активный Руководитель', 'last leader block guard failed');
    $throws(fn() => $membershipService->remove(7, 11), 'хотя бы один активный Руководитель', 'last leader delete guard failed');
    $throws(fn() => $membershipService->save(7, 19, 2, 'active', [], []), 'Пользователь компании не найден', 'cross-company membership mutation accepted');
    $throws(fn() => $membershipService->add(7, 9, 'Foreign Role User', 'foreign_role_user', 'Pass!123'), 'Роль не принадлежит', 'cross-company role accepted');

    // Four assignment types: exact ON/OFF/readback using authoritative service.
    AccessRuntime::setCurrent(EffectiveAccess::systemOwner(11, 7, $pageKeys));
    foreach ([
        'client' => 'clients',
        'contractor' => 'contractors',
        'vehicle_unit' => 'vehicle_units',
        'driver' => 'drivers',
    ] as $entityType => $table) {
        EntityAssignmentService::replace($pdo, $entityType, 1, [12], 11);
        $ok(EntityAssignmentService::membershipIds($pdo, $entityType, 1) === [12], "{$entityType} assignment ON/readback failed");
        EntityAssignmentService::replace($pdo, $entityType, 1, [], 11);
        $ok(EntityAssignmentService::membershipIds($pdo, $entityType, 1) === [], "{$entityType} assignment OFF/readback failed");
    }

    // ONLY_OWN: explicit assignment grants visibility; removing it revokes visibility. Creator still owns own rows.
    $ownScopes = array_fill_keys(CompanyRoleService::regulatedEntities(), 'own');
    AccessRuntime::setCurrent(EffectiveAccess::member(12, 7, ['company.clients' => true], $ownScopes, 'logist'));
    $ok(!RegulatedDataScopeService::canAccess($pdo, 'client', 'clients', 1), 'ONLY_OWN exposed unassigned non-created client');
    AccessRuntime::setCurrent(EffectiveAccess::systemOwner(11, 7, $pageKeys));
    EntityAssignmentService::replace($pdo, 'client', 1, [12], 11);
    AccessRuntime::setCurrent(EffectiveAccess::member(12, 7, ['company.clients' => true], $ownScopes, 'logist'));
    $ok(RegulatedDataScopeService::canAccess($pdo, 'client', 'clients', 1), 'ONLY_OWN assignment did not grant visibility');
    AccessRuntime::setCurrent(EffectiveAccess::systemOwner(11, 7, $pageKeys));
    EntityAssignmentService::replace($pdo, 'client', 1, [], 11);
    AccessRuntime::setCurrent(EffectiveAccess::member(12, 7, ['company.clients' => true], $ownScopes, 'logist'));
    $ok(!RegulatedDataScopeService::canAccess($pdo, 'client', 'clients', 1), 'removing assignment did not revoke ONLY_OWN visibility');
    $ok(RegulatedDataScopeService::canAccess($pdo, 'client', 'clients', 2), 'reference creator semantics failed');
    $ok(RegulatedDataScopeService::canAccess($pdo, 'linear_trip', 'linear_routes', 2), 'linear trip creator ownership failed');
    $ok(RegulatedDataScopeService::canAccess($pdo, 'waste_trip', 'waste_trips', 2), 'waste trip creator ownership failed');
    $ok(!RegulatedDataScopeService::canAccess($pdo, 'linear_trip', 'linear_routes', 1), 'linear ONLY_OWN leaked non-created trip');
    $ok(!RegulatedDataScopeService::canAccess($pdo, 'waste_trip', 'waste_trips', 1), 'waste ONLY_OWN leaked non-created trip');

    // Cleanup temporary membership and roles; no test residue in isolated DB semantics.
    AccessRuntime::clear();
    $membershipService->remove(7, $tempMembershipId);
    $roleService->delete(7, $copyId);
    $roleService->delete(7, $roleId);
    $ok((int) $pdo->query("SELECT COUNT(*) FROM company_roles WHERE name LIKE 'QA Temporary Role%'")->fetchColumn() === 0, 'temporary role residue');
    $ok((int) $pdo->query("SELECT COUNT(*) FROM company_memberships WHERE id={$tempMembershipId}")->fetchColumn() === 0, 'temporary membership residue');

    echo "roles access real write-path mysql: PASS\n";
} finally {
    AccessRuntime::clear();
    cleanCompanyPoolDatabase($pdo);
}
