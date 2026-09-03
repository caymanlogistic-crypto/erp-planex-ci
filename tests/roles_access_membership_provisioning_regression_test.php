<?php

declare(strict_types=1);

use App\Service\CompanyMembershipProvisioningService;
use App\Service\CompanyMembershipService;
use App\Service\CompanyRoleService;

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}
require_once BASE_PATH . '/app/Core/Database.php';
require_once BASE_PATH . '/app/Support/company_database.php';
require_once BASE_PATH . '/app/Service/CompanyRoleService.php';
require_once BASE_PATH . '/app/Service/CompanyMembershipService.php';
require_once BASE_PATH . '/app/Service/CompanyActorProjectionService.php';
require_once BASE_PATH . '/app/Service/CompanyMembershipProvisioningService.php';

$host = getenv('PLANEX_SCHEMA_DB_HOST') ?: '127.0.0.1';
$port = (int) (getenv('PLANEX_SCHEMA_DB_PORT') ?: 3306);
$user = getenv('PLANEX_SCHEMA_DB_USER') ?: 'planex_ci';
$pass = getenv('PLANEX_SCHEMA_DB_PASSWORD') ?: 'planex_ci';
$admin = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$prefix = 'planex_roles_provision_' . getmypid() . '_' . bin2hex(random_bytes(3));
$centralDb = $prefix . '_central';
$tenantA = $prefix . '_a';
$tenantB = $prefix . '_b';
$quote = static fn(string $db): string => '`' . str_replace('`', '``', $db) . '`';
$connect = static fn(string $db): PDO => new PDO(
    "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4",
    $user,
    $pass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
$assert = static function (bool $ok, string $message): void {
    if (!$ok) {
        throw new RuntimeException($message);
    }
};

foreach ([$centralDb, $tenantA, $tenantB] as $db) {
    $admin->exec('CREATE DATABASE ' . $quote($db) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
}

try {
    $central = $connect($centralDb);
    $central->exec(<<<'SQL'
CREATE TABLE companies (
  id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  name VARCHAR(255) NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  db_identifier VARCHAR(100) NULL,
  db_host VARCHAR(255) NULL,
  db_port INT NULL,
  db_username VARCHAR(255) NULL,
  db_password VARCHAR(1024) NULL
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
  UNIQUE KEY uk_role_name (company_id,name_normalized),
  UNIQUE KEY uk_role_system (company_id,system_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE company_memberships (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  company_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  role_id BIGINT UNSIGNED NOT NULL,
  tenant_user_id INT UNSIGNED NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  UNIQUE KEY uk_membership_user (company_id,user_id),
  UNIQUE KEY uk_membership_actor (company_id,tenant_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE company_role_page_permissions (
  role_id BIGINT UNSIGNED NOT NULL,
  page_key VARCHAR(160) NOT NULL,
  is_allowed TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY(role_id,page_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE company_membership_page_overrides (
  membership_id BIGINT UNSIGNED NOT NULL,
  page_key VARCHAR(160) NOT NULL,
  effect VARCHAR(8) NOT NULL,
  PRIMARY KEY(membership_id,page_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE company_role_data_scopes (
  role_id BIGINT UNSIGNED NOT NULL,
  entity_key VARCHAR(32) NOT NULL,
  scope VARCHAR(16) NOT NULL,
  PRIMARY KEY(role_id,entity_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE company_membership_data_overrides (
  membership_id BIGINT UNSIGNED NOT NULL,
  entity_key VARCHAR(32) NOT NULL,
  scope VARCHAR(16) NOT NULL,
  PRIMARY KEY(membership_id,entity_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

    foreach ([$tenantA, $tenantB] as $tenantDb) {
        $tenant = $connect($tenantDb);
        $tenant->exec(<<<'SQL'
CREATE TABLE users (
  id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  full_name VARCHAR(255) NOT NULL,
  login VARCHAR(100) NOT NULL,
  email VARCHAR(255) NULL,
  phone VARCHAR(50) NULL,
  position VARCHAR(255) NULL,
  password_hash VARCHAR(255) NOT NULL,
  role_code VARCHAR(50) NOT NULL DEFAULT 'logist',
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  global_user_id INT UNSIGNED NULL,
  membership_id BIGINT UNSIGNED NULL,
  created_by_user_id INT UNSIGNED NULL,
  created_by_role VARCHAR(20) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_login(login),
  UNIQUE KEY uk_users_global_user_id(global_user_id),
  UNIQUE KEY uk_users_membership_id(membership_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);
    }

    $companyInsert = $central->prepare(
        'INSERT INTO companies (name,status,db_identifier,db_host,db_port,db_username,db_password) VALUES (?,?,?,?,?,?,?)'
    );
    $companyInsert->execute(['Компания А', 'active', $tenantA, $host, $port, $user, $pass]);
    $companyAId = (int) $central->lastInsertId();
    $companyInsert->execute(['Компания Б', 'active', $tenantB, $host, $port, $user, $pass]);
    $companyBId = (int) $central->lastInsertId();

    $roles = new CompanyRoleService();
    $membershipService = new CompanyMembershipService();
    $roleA = $roles->createRole($central, $companyAId, 'Логист');
    $roleB = $roles->createRole($central, $companyBId, 'Диспетчер');
    $central->prepare('UPDATE company_roles SET legacy_role_code=? WHERE id=?')->execute(['logist', $roleA]);

    $globalUserId = $membershipService->createGlobalUser($central, $companyAId, [
        'full_name' => 'Иванов Иван',
        'login' => 'ivanov',
        'password' => 'GlobalSecret!123',
        'email' => 'ivanov@example.test',
    ]);
    $globalHash = (string) $central->query("SELECT password_hash FROM company_users WHERE id={$globalUserId}")->fetchColumn();

    $config = [
        'database' => [
            'host' => $host,
            'port' => $port,
            'database' => $centralDb,
            'username' => $user,
            'password' => $pass,
            'charset' => 'utf8mb4',
        ],
    ];
    $provision = new CompanyMembershipProvisioningService($config);
    $companyA = $central->query("SELECT * FROM companies WHERE id={$companyAId}")->fetch();
    $companyB = $central->query("SELECT * FROM companies WHERE id={$companyBId}")->fetch();

    $first = $provision->addExistingGlobalUser($central, $companyA, $globalUserId, $roleA);
    $second = $provision->addExistingGlobalUser($central, $companyB, $globalUserId, $roleB);

    $assert((int) $central->query("SELECT COUNT(*) FROM company_users WHERE login='ivanov'")->fetchColumn() === 1, 'Adding second company duplicated global identity.');
    $assert((int) $central->query("SELECT COUNT(*) FROM company_memberships WHERE user_id={$globalUserId}")->fetchColumn() === 2, 'One global user must have two memberships.');
    $assert((int) $first['global_user_id'] === (int) $second['global_user_id'], 'Provisioning changed global user identity.');
    $assert((int) $first['membership_id'] !== (int) $second['membership_id'], 'Membership identity must be company-specific.');

    $tenantAPdo = $connect($tenantA);
    $tenantBPdo = $connect($tenantB);
    foreach ([[$tenantAPdo, $first], [$tenantBPdo, $second]] as [$tenantPdo, $result]) {
        $row = $tenantPdo->query('SELECT * FROM users LIMIT 1')->fetch();
        $assert((int) ($row['global_user_id'] ?? 0) === $globalUserId, 'Tenant actor not linked to global identity.');
        $assert((int) ($row['membership_id'] ?? 0) === (int) $result['membership_id'], 'Tenant actor not linked to membership.');
        $assert((int) ($row['id'] ?? 0) === (int) $result['tenant_user_id'], 'Central membership tenant_user_id mismatch.');
        $assert((string) ($row['password_hash'] ?? '') !== $globalHash, 'Tenant actor must not copy global credential hash.');
        $assert(!password_verify('GlobalSecret!123', (string) $row['password_hash']), 'Tenant actor must not authenticate with global password.');
    }

    try {
        $provision->addExistingGlobalUser($central, $companyB, $globalUserId, $roleB);
        throw new RuntimeException('Duplicate membership should have been rejected.');
    } catch (RuntimeException $e) {
        $assert(str_contains($e->getMessage(), 'уже добавлен'), 'Unexpected duplicate membership error: ' . $e->getMessage());
    }
    $assert((int) $tenantBPdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 1, 'Duplicate membership attempt created extra tenant actor.');

    $newResult = $provision->addByLoginOrCreate($central, $companyB, 'newuser', $roleB, [
        'full_name' => 'Новый Пользователь',
        'password' => 'NewSecret!123',
    ]);
    $assert($newResult['created_global_user'] === true, 'Unknown login must create a new global identity.');
    $assert((int) $central->query("SELECT COUNT(*) FROM company_users WHERE login='newuser'")->fetchColumn() === 1, 'New global identity not created exactly once.');

    $existingResult = $provision->addByLoginOrCreate($central, $companyA, 'newuser', $roleA, [
        'full_name' => 'SHOULD NOT CREATE',
        'password' => 'SHOULD_NOT_CHANGE',
    ]);
    $assert($existingResult['created_global_user'] === false, 'Existing login must create membership only.');
    $assert((int) $central->query("SELECT COUNT(*) FROM company_users WHERE login='newuser'")->fetchColumn() === 1, 'Existing account path duplicated identity.');
    $assert((int) $central->query("SELECT COUNT(*) FROM company_memberships WHERE user_id=" . (int) $newResult['global_user_id'])->fetchColumn() === 2, 'Existing account path did not add second membership.');

    fwrite(STDOUT, "ROLES_ACCESS_PROVISION_EXISTING_SECOND_COMPANY=PASS\n");
    fwrite(STDOUT, "ROLES_ACCESS_PROVISION_NEW_ACCOUNT=PASS\n");
    fwrite(STDOUT, "ROLES_ACCESS_TENANT_ACTOR_NON_AUTH=PASS\n");
} finally {
    foreach ([$centralDb, $tenantA, $tenantB] as $db) {
        $admin->exec('DROP DATABASE IF EXISTS ' . $quote($db));
    }
}
