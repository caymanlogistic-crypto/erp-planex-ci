<?php

declare(strict_types=1);

use App\Service\CompanyMembershipService;
use App\Service\CompanyRoleService;

require_once dirname(__DIR__) . '/app/Service/CompanyRoleService.php';
require_once dirname(__DIR__) . '/app/Service/CompanyMembershipService.php';

$host = getenv('PLANEX_SCHEMA_DB_HOST') ?: '127.0.0.1';
$port = (int) (getenv('PLANEX_SCHEMA_DB_PORT') ?: 3306);
$user = getenv('PLANEX_SCHEMA_DB_USER') ?: 'planex_ci';
$pass = getenv('PLANEX_SCHEMA_DB_PASSWORD') ?: 'planex_ci';
$admin = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$dbName = 'planex_roles_access_' . getmypid() . '_' . bin2hex(random_bytes(3));
$quotedDb = '`' . str_replace('`', '``', $dbName) . '`';
$admin->exec("CREATE DATABASE {$quotedDb} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

$assert = static function (bool $ok, string $message): void {
    if (!$ok) {
        throw new RuntimeException($message);
    }
};
$expectRuntime = static function (callable $fn, string $contains = '') use ($assert): void {
    try {
        $fn();
    } catch (RuntimeException $e) {
        if ($contains !== '') {
            $assert(str_contains($e->getMessage(), $contains), 'Unexpected RuntimeException: ' . $e->getMessage());
        }
        return;
    }
    throw new RuntimeException('Expected RuntimeException was not thrown.');
};

try {
    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4",
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $pdo->exec(<<<'SQL'
CREATE TABLE companies (
  id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  name VARCHAR(255) NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE company_users (
  id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  company_id INT UNSIGNED NULL,
  full_name VARCHAR(255) NOT NULL,
  login VARCHAR(100) NOT NULL,
  email VARCHAR(255) NULL,
  phone VARCHAR(50) NULL,
  position VARCHAR(255) NULL,
  password_hash VARCHAR(255) NOT NULL,
  role VARCHAR(50) NOT NULL DEFAULT 'member',
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  comments TEXT NULL,
  UNIQUE KEY uk_company_users_login (login)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE company_roles (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  company_id INT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  name_normalized VARCHAR(120) NOT NULL,
  system_key VARCHAR(64) NULL,
  legacy_role_code VARCHAR(64) NULL,
  UNIQUE KEY uk_role_name (company_id, name_normalized),
  UNIQUE KEY uk_role_system (company_id, system_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE company_memberships (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  company_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  role_id BIGINT UNSIGNED NOT NULL,
  tenant_user_id INT UNSIGNED NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  UNIQUE KEY uk_membership_user (company_id, user_id),
  UNIQUE KEY uk_membership_actor (company_id, tenant_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE company_role_page_permissions (
  role_id BIGINT UNSIGNED NOT NULL,
  page_key VARCHAR(160) NOT NULL,
  is_allowed TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (role_id, page_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE company_membership_page_overrides (
  membership_id BIGINT UNSIGNED NOT NULL,
  page_key VARCHAR(160) NOT NULL,
  effect VARCHAR(8) NOT NULL,
  PRIMARY KEY (membership_id, page_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE company_role_data_scopes (
  role_id BIGINT UNSIGNED NOT NULL,
  entity_key VARCHAR(32) NOT NULL,
  scope VARCHAR(16) NOT NULL,
  PRIMARY KEY (role_id, entity_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE company_membership_data_overrides (
  membership_id BIGINT UNSIGNED NOT NULL,
  entity_key VARCHAR(32) NOT NULL,
  scope VARCHAR(16) NOT NULL,
  PRIMARY KEY (membership_id, entity_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

    $pdo->exec("INSERT INTO companies (name,status) VALUES ('Компания А','active'),('Компания Б','active')");

    $roles = new CompanyRoleService();
    $memberships = new CompanyMembershipService();

    $insertSystemRole = $pdo->prepare(
        'INSERT INTO company_roles (company_id,name,name_normalized,system_key,legacy_role_code) VALUES (?,?,?,?,?)'
    );
    $insertSystemRole->execute([1, 'Руководитель', CompanyRoleService::normalizeName('Руководитель'), CompanyRoleService::SYSTEM_LEADER, 'company_owner']);
    $leaderRoleA = (int) $pdo->lastInsertId();
    $insertSystemRole->execute([2, 'Руководитель', CompanyRoleService::normalizeName('Руководитель'), CompanyRoleService::SYSTEM_LEADER, 'company_owner']);
    $leaderRoleB = (int) $pdo->lastInsertId();

    $logistRoleA = $roles->createRole($pdo, 1, 'Логист');
    $logistRoleB = $roles->createRole($pdo, 2, 'Логист');
    $pdo->prepare('UPDATE company_roles SET legacy_role_code = ? WHERE id IN (?,?)')->execute(['logist', $logistRoleA, $logistRoleB]);

    $globalA = $memberships->createGlobalUser($pdo, 1, [
        'full_name' => 'Иванов Иван',
        'login' => 'ivanov',
        'password' => 'secret123',
    ]);
    $globalB = $memberships->createGlobalUser($pdo, 1, [
        'full_name' => 'Петров Пётр',
        'login' => 'petrov',
        'password' => 'secret123',
    ]);
    $globalC = $memberships->createGlobalUser($pdo, 1, [
        'full_name' => 'Сидоров Сидор',
        'login' => 'sidorov',
        'password' => 'secret123',
    ]);

    $leaderMembershipA = $memberships->createMembership($pdo, 1, $globalA, $leaderRoleA, 101);
    $sameGlobalOtherCompany = $memberships->createMembership($pdo, 2, $globalA, $logistRoleB, 201);
    $assert($globalA === (int) $pdo->query("SELECT id FROM company_users WHERE login='ivanov'")->fetchColumn(), 'Multi-company created duplicate identity.');
    $assert((int) $pdo->query("SELECT COUNT(*) FROM company_memberships WHERE user_id={$globalA}")->fetchColumn() === 2, 'Expected two memberships for one global account.');
    $assert((int) $pdo->query("SELECT role_id FROM company_memberships WHERE id={$leaderMembershipA}")->fetchColumn() === $leaderRoleA, 'Company A role changed unexpectedly.');
    $assert((int) $pdo->query("SELECT role_id FROM company_memberships WHERE id={$sameGlobalOtherCompany}")->fetchColumn() === $logistRoleB, 'Company B role mismatch.');

    $expectRuntime(
        fn() => $memberships->createMembership($pdo, 1, $globalA, $logistRoleA, 102),
        'уже добавлен'
    );

    $dispatcher = $roles->createRole($pdo, 1, 'Диспетчер');
    $assert((int) $pdo->query("SELECT COUNT(*) FROM company_role_page_permissions WHERE role_id={$dispatcher}")->fetchColumn() === 0, 'New role must have no allowed pages.');
    $scopes = $pdo->query("SELECT entity_key,scope FROM company_role_data_scopes WHERE role_id={$dispatcher}")->fetchAll(PDO::FETCH_KEY_PAIR);
    $assert(count($scopes) === count(CompanyRoleService::DATA_ENTITIES), 'New role must initialize all regulated scopes.');
    foreach (CompanyRoleService::DATA_ENTITIES as $entityKey) {
        $assert(($scopes[$entityKey] ?? null) === 'none', 'New role scope must be NONE: ' . $entityKey);
    }

    $expectRuntime(fn() => $roles->createRole($pdo, 1, '  диспетчер  '), 'уже существует');
    $expectRuntime(fn() => $roles->createRole($pdo, 1, 'Руководитель'), 'зарезервировано');
    $expectRuntime(fn() => $roles->createRole($pdo, 1, 'superadmin'), 'зарезервировано');

    $dispatcherMembership = $memberships->createMembership($pdo, 1, $globalB, $dispatcher, 102);
    $roles->saveRoleAccess(
        $pdo,
        1,
        $dispatcher,
        ['company.clients' => true, 'company.drivers' => true],
        [
            'client' => 'only_own',
            'contractor' => 'none',
            'vehicle_unit' => 'all',
            'driver' => 'only_own',
            'linear_trip' => 'all',
            'waste_trip' => 'none',
        ],
        ['company.clients', 'company.drivers', 'company.dashboard']
    );
    $roles->renameRole($pdo, 1, $dispatcher, 'Диспетчер 1');
    $assert((int) $pdo->query("SELECT role_id FROM company_memberships WHERE id={$dispatcherMembership}")->fetchColumn() === $dispatcher, 'Rename changed role identity/membership.');

    $memberships->savePersonalAccess(
        $pdo,
        1,
        $dispatcherMembership,
        ['company.dashboard' => 'allow', 'company.drivers' => 'deny'],
        ['client' => 'all', 'driver' => 'none']
    );

    $copy = $roles->copyRole($pdo, 1, $dispatcher, 'Копия диспетчера');
    $copyRole = $roles->getRole($pdo, 1, $copy);
    $assert(($copyRole['system_key'] ?? null) === null, 'Copied role must not be system role.');
    $assert((int) $pdo->query("SELECT COUNT(*) FROM company_memberships WHERE role_id={$copy}")->fetchColumn() === 0, 'Copy must not copy memberships.');
    $copyPages = $pdo->query("SELECT page_key FROM company_role_page_permissions WHERE role_id={$copy} AND is_allowed=1 ORDER BY page_key")->fetchAll(PDO::FETCH_COLUMN);
    $assert($copyPages === ['company.clients', 'company.drivers'], 'Copy page settings mismatch.');
    $copyScopes = $pdo->query("SELECT entity_key,scope FROM company_role_data_scopes WHERE role_id={$copy}")->fetchAll(PDO::FETCH_KEY_PAIR);
    $assert(($copyScopes['client'] ?? '') === 'only_own' && ($copyScopes['vehicle_unit'] ?? '') === 'all', 'Copy data scopes mismatch.');
    $assert((int) $pdo->query("SELECT COUNT(*) FROM company_membership_page_overrides WHERE membership_id={$dispatcherMembership}")->fetchColumn() === 2, 'Source personal overrides unexpectedly changed.');

    $leaderCopy = $roles->copyRole($pdo, 1, $leaderRoleA, 'Полный доступ копия', ['company.dashboard', 'company.clients']);
    $assert((int) $pdo->query("SELECT COUNT(*) FROM company_role_page_permissions WHERE role_id={$leaderCopy} AND is_allowed=1")->fetchColumn() === 2, 'Leader copy must snapshot current PageRegistry keys.');
    $leaderCopyScopes = $pdo->query("SELECT scope FROM company_role_data_scopes WHERE role_id={$leaderCopy}")->fetchAll(PDO::FETCH_COLUMN);
    $assert(count($leaderCopyScopes) === 6 && count(array_unique($leaderCopyScopes)) === 1 && $leaderCopyScopes[0] === 'all', 'Leader copy must snapshot ALL scopes.');

    $memberships->updateMembership($pdo, 1, $dispatcherMembership, $dispatcher, 'blocked');
    $expectRuntime(fn() => $roles->deleteRole($pdo, 1, $dispatcher), 'используется');
    $memberships->updateMembership($pdo, 1, $dispatcherMembership, $copy, 'active');
    $roles->deleteRole($pdo, 1, $dispatcher);
    $assert($roles->getRole($pdo, 1, $dispatcher) === null, 'Unused ordinary role should be deleted.');

    $expectRuntime(fn() => $roles->renameRole($pdo, 1, $leaderRoleA, 'Начальник'), 'Системную роль');
    $expectRuntime(fn() => $roles->deleteRole($pdo, 1, $leaderRoleA), 'Системную роль');
    $expectRuntime(fn() => $roles->saveRoleAccess($pdo, 1, $leaderRoleA, [], [], []), 'всегда имеет полный доступ');
    $expectRuntime(fn() => $memberships->savePersonalAccess($pdo, 1, $leaderMembershipA, ['company.clients' => 'deny'], []), 'нельзя ограничивать');

    // One active leader: every operation that could leave zero must fail.
    $expectRuntime(fn() => $memberships->updateMembership($pdo, 1, $leaderMembershipA, $leaderRoleA, 'blocked'), 'минимум один активный Руководитель');
    $expectRuntime(fn() => $memberships->updateMembership($pdo, 1, $leaderMembershipA, $logistRoleA, 'active'), 'минимум один активный Руководитель');
    $expectRuntime(fn() => $memberships->removeMembership($pdo, 1, $leaderMembershipA), 'минимум один активный Руководитель');

    // Second leader makes changing the first legal.
    $secondLeader = $memberships->createMembership($pdo, 1, $globalC, $leaderRoleA, 103);
    $memberships->updateMembership($pdo, 1, $leaderMembershipA, $logistRoleA, 'active');
    $assert((int) $pdo->query("SELECT role_id FROM company_memberships WHERE id={$leaderMembershipA}")->fetchColumn() === $logistRoleA, 'First leader role change should succeed with second leader present.');

    // Personal overrides survive role/status changes.
    $memberships->updateMembership($pdo, 1, $dispatcherMembership, $logistRoleA, 'blocked');
    $memberships->updateMembership($pdo, 1, $dispatcherMembership, $copy, 'active');
    $assert((int) $pdo->query("SELECT COUNT(*) FROM company_membership_page_overrides WHERE membership_id={$dispatcherMembership}")->fetchColumn() === 2, 'Page overrides must survive role/status changes.');
    $assert((int) $pdo->query("SELECT COUNT(*) FROM company_membership_data_overrides WHERE membership_id={$dispatcherMembership}")->fetchColumn() === 2, 'Data overrides must survive role/status changes.');

    // Company-admin list cannot expose another company's membership internals.
    $companyAUsers = $memberships->membershipsForCompany($pdo, 1);
    foreach ($companyAUsers as $row) {
        $assert((int) $row['company_id'] === 1, 'Company list leaked another-company membership.');
    }
    $assert(count(array_filter($companyAUsers, fn(array $r): bool => (int) $r['user_id'] === $globalA)) === 1, 'Current company should expose exactly one membership for global user.');

    $globalRow = $memberships->findGlobalUserById($pdo, $globalA);
    $active = $memberships->membershipsForUser($pdo, $globalA, true);
    $initial = $memberships->selectInitialMembership($globalRow ?: [], $active);
    $assert((int) ($initial['company_id'] ?? 0) === 1, 'Initial membership should prefer legacy/home company when active.');

    fwrite(STDOUT, "ROLES_ACCESS_MULTI_COMPANY=PASS\n");
    fwrite(STDOUT, "ROLES_ACCESS_DYNAMIC_ROLES=PASS\n");
    fwrite(STDOUT, "ROLES_ACCESS_LAST_LEADER=PASS\n");
    fwrite(STDOUT, "ROLES_ACCESS_PERSONAL_OVERRIDES=PASS\n");
    fwrite(STDOUT, "ROLES_ACCESS_COMPANY_ISOLATION=PASS\n");
} finally {
    $admin->exec("DROP DATABASE IF EXISTS {$quotedDb}");
}
