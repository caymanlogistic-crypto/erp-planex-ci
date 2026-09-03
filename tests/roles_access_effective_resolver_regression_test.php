<?php

declare(strict_types=1);

use App\Service\EffectiveAccessResolver;

require_once dirname(__DIR__) . '/app/Service/EffectiveAccessResolver.php';

$host = getenv('PLANEX_SCHEMA_DB_HOST') ?: '127.0.0.1';
$port = (int) (getenv('PLANEX_SCHEMA_DB_PORT') ?: 3306);
$user = getenv('PLANEX_SCHEMA_DB_USER') ?: 'planex_ci';
$pass = getenv('PLANEX_SCHEMA_DB_PASSWORD') ?: 'planex_ci';
$admin = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$dbName = 'planex_access_resolver_' . getmypid() . '_' . bin2hex(random_bytes(3));
$quotedDb = '`' . str_replace('`', '``', $dbName) . '`';
$admin->exec("CREATE DATABASE {$quotedDb} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

$assert = static function (bool $ok, string $message): void {
    if (!$ok) {
        throw new RuntimeException($message);
    }
};

try {
    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4",
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );

    $pdo->exec(<<<'SQL'
CREATE TABLE superadmin_users (
  id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;
CREATE TABLE companies (
  id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  name VARCHAR(255) NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'active'
) ENGINE=InnoDB;
CREATE TABLE company_users (
  id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  login VARCHAR(100) NOT NULL
) ENGINE=InnoDB;
CREATE TABLE company_roles (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  company_id INT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  system_key VARCHAR(64) NULL
) ENGINE=InnoDB;
CREATE TABLE company_memberships (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  company_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  role_id BIGINT UNSIGNED NOT NULL,
  tenant_user_id INT UNSIGNED NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'active'
) ENGINE=InnoDB;
CREATE TABLE company_role_page_permissions (
  role_id BIGINT UNSIGNED NOT NULL,
  page_key VARCHAR(160) NOT NULL,
  is_allowed TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY(role_id,page_key)
) ENGINE=InnoDB;
CREATE TABLE company_membership_page_overrides (
  membership_id BIGINT UNSIGNED NOT NULL,
  page_key VARCHAR(160) NOT NULL,
  effect VARCHAR(8) NOT NULL,
  PRIMARY KEY(membership_id,page_key)
) ENGINE=InnoDB;
CREATE TABLE company_role_data_scopes (
  role_id BIGINT UNSIGNED NOT NULL,
  entity_key VARCHAR(32) NOT NULL,
  scope VARCHAR(16) NOT NULL,
  PRIMARY KEY(role_id,entity_key)
) ENGINE=InnoDB;
CREATE TABLE company_membership_data_overrides (
  membership_id BIGINT UNSIGNED NOT NULL,
  entity_key VARCHAR(32) NOT NULL,
  scope VARCHAR(16) NOT NULL,
  PRIMARY KEY(membership_id,entity_key)
) ENGINE=InnoDB;
SQL);

    $pdo->exec("INSERT INTO superadmin_users(is_active) VALUES(1),(0)");
    $pdo->exec("INSERT INTO companies(name,status) VALUES('Компания А','active'),('Компания Б','active')");
    $pdo->exec("INSERT INTO company_users(login) VALUES('leader'),('worker'),('other')");
    $pdo->exec("INSERT INTO company_roles(company_id,name,system_key) VALUES(1,'Руководитель','leader'),(1,'Диспетчер',NULL),(2,'Логист',NULL)");
    $pdo->exec("INSERT INTO company_memberships(company_id,user_id,role_id,tenant_user_id,status) VALUES(1,1,1,101,'active'),(1,2,2,102,'active'),(2,3,3,201,'active')");
    $pdo->exec("INSERT INTO company_role_page_permissions(role_id,page_key,is_allowed) VALUES(2,'company.clients',1),(2,'company.drivers',0),(3,'company.clients',1)");
    $pdo->exec("INSERT INTO company_role_data_scopes(role_id,entity_key,scope) VALUES(2,'client','only_own'),(2,'driver','none'),(3,'client','all')");

    $resolver = new EffectiveAccessResolver();
    $leaderSession = [
        'session_auth_version' => 2,
        'global_user_id' => 1,
        'membership_id' => 1,
        'tenant_user_id' => 101,
        'user_id' => 101,
        'company_id' => 1,
        'role_code' => 'company_owner',
    ];
    $workerSession = [
        'session_auth_version' => 2,
        'global_user_id' => 2,
        'membership_id' => 2,
        'tenant_user_id' => 102,
        'user_id' => 102,
        'company_id' => 1,
        'role_code' => 'totally_stale_role_code',
    ];

    // Missing/legacy/mismatched company state fails closed.
    $assert(!$resolver->resolveContext($pdo, [])['allowed'], 'Missing membership must deny.');
    $legacy = $workerSession;
    unset($legacy['session_auth_version']);
    $assert(!$resolver->resolveContext($pdo, $legacy)['allowed'], 'Legacy company session must deny.');
    $crossCompany = $workerSession;
    $crossCompany['company_id'] = 2;
    $assert(!$resolver->resolveContext($pdo, $crossCompany)['allowed'], 'Cross-company session mismatch must deny.');

    // Session role_code is stale and intentionally ignored; persisted role decides.
    $assert($resolver->canAccessAnyPage($pdo, $workerSession, ['company.clients']), 'Persisted role page allow should succeed despite stale session role.');
    $assert(!$resolver->canAccessAnyPage($pdo, $workerSession, ['company.drivers']), 'Persisted page deny should deny.');
    $assert(!$resolver->canAccessAnyPage($pdo, $workerSession, []), 'Missing endpoint/page mapping must deny.');
    $assert(!$resolver->canAccessAnyPage($pdo, $workerSession, ['company.unknown']), 'Unknown/unconfigured page must deny ordinary role.');

    // Personal page override wins over role in either direction.
    $pdo->exec("INSERT INTO company_membership_page_overrides(membership_id,page_key,effect) VALUES(2,'company.clients','deny'),(2,'company.drivers','allow')");
    $assert(!$resolver->canAccessAnyPage($pdo, $workerSession, ['company.clients']), 'Personal deny must override role allow.');
    $assert($resolver->canAccessAnyPage($pdo, $workerSession, ['company.drivers']), 'Personal allow must override role deny.');

    // Shared endpoint is a server-owned OR-set: one allowed registered page suffices.
    $assert($resolver->canAccessAnyPage($pdo, $workerSession, ['company.clients', 'company.drivers']), 'Shared endpoint OR-set should allow through registered allowed page.');
    $assert(!$resolver->canAccessAnyPage($pdo, $workerSession, ['client_supplied.fake']), 'Client-invented key cannot expand access without persisted server permission.');

    // Data scope remains independent after page allow.
    $assert($resolver->dataScope($pdo, $workerSession, 'client') === EffectiveAccessResolver::SCOPE_ONLY_OWN, 'Role client scope mismatch.');
    $assert($resolver->dataScope($pdo, $workerSession, 'driver') === EffectiveAccessResolver::SCOPE_NONE, 'Role driver scope mismatch.');
    $driverDecision = $resolver->decision($pdo, $workerSession, ['company.drivers'], 'driver');
    $assert(!$driverDecision['allowed'] && $driverDecision['page_allowed'], 'Shared/page allow must not bypass NONE data scope.');
    $clientDecision = $resolver->decision($pdo, $workerSession, ['company.drivers'], 'client');
    $assert($clientDecision['allowed'] && $clientDecision['requires_own_filter'], 'ONLY_OWN must require downstream entity filter.');

    $pdo->exec("INSERT INTO company_membership_data_overrides(membership_id,entity_key,scope) VALUES(2,'client','all'),(2,'driver','only_own')");
    $assert($resolver->dataScope($pdo, $workerSession, 'client') === EffectiveAccessResolver::SCOPE_ALL, 'Personal ALL scope override missing.');
    $assert($resolver->dataScope($pdo, $workerSession, 'driver') === EffectiveAccessResolver::SCOPE_ONLY_OWN, 'Personal ONLY_OWN scope override missing.');
    $assert($resolver->dataScope($pdo, $workerSession, 'feo_request') === EffectiveAccessResolver::SCOPE_NONE, 'Unregulated/unknown entity scope must deny.');

    // Leader is automatic full access even in presence of corrupt deny overrides.
    $pdo->exec("INSERT INTO company_membership_page_overrides(membership_id,page_key,effect) VALUES(1,'company.secret','deny')");
    $pdo->exec("INSERT INTO company_membership_data_overrides(membership_id,entity_key,scope) VALUES(1,'client','none')");
    $assert($resolver->canAccessAnyPage($pdo, $leaderSession, ['company.secret']), 'Leader must have full page access.');
    $assert($resolver->dataScope($pdo, $leaderSession, 'client') === EffectiveAccessResolver::SCOPE_ALL, 'Leader override must not restrict data.');

    // Immediate revocation: same session, next resolver call reads current persistence.
    $pdo->exec("DELETE FROM company_membership_page_overrides WHERE membership_id=2 AND page_key='company.drivers'");
    $pdo->exec("UPDATE company_role_page_permissions SET is_allowed=1 WHERE role_id=2 AND page_key='company.drivers'");
    $assert($resolver->canAccessAnyPage($pdo, $workerSession, ['company.drivers']), 'Pre-revocation access setup failed.');
    $pdo->exec("UPDATE company_role_page_permissions SET is_allowed=0 WHERE role_id=2 AND page_key='company.drivers'");
    $assert(!$resolver->canAccessAnyPage($pdo, $workerSession, ['company.drivers']), 'Revocation must apply on next request without relogin.');

    // Membership disable is also immediate.
    $pdo->exec("UPDATE company_memberships SET status='blocked' WHERE id=2");
    $assert(!$resolver->resolveContext($pdo, $workerSession)['allowed'], 'Inactive membership must deny immediately.');
    $pdo->exec("UPDATE company_memberships SET status='active' WHERE id=2");

    // Missing role/config fails closed. No permissions row = no access.
    $pdo->exec("INSERT INTO company_roles(company_id,name,system_key) VALUES(1,'Новая роль',NULL)");
    $unknownRole = (int) $pdo->lastInsertId();
    $pdo->exec("UPDATE company_memberships SET role_id={$unknownRole} WHERE id=2");
    $assert(!$resolver->canAccessAnyPage($pdo, $workerSession, ['company.clients']), 'Unknown/unconfigured ordinary role must deny.');
    $assert($resolver->dataScope($pdo, $workerSession, 'client') === EffectiveAccessResolver::SCOPE_ALL, 'Existing personal data override should remain effective across role change.');
    $pdo->exec("DELETE FROM company_membership_data_overrides WHERE membership_id=2 AND entity_key='client'");
    $assert($resolver->dataScope($pdo, $workerSession, 'client') === EffectiveAccessResolver::SCOPE_NONE, 'Missing role data config must deny.');

    // Superadmin remains separate and is revalidated from current central row.
    $superSession = ['role_code' => 'superadmin', 'user_id' => 1];
    $assert($resolver->canAccessAnyPage($pdo, $superSession, ['company.any']), 'Active superadmin must retain technical access.');
    $pdo->exec('UPDATE superadmin_users SET is_active=0 WHERE id=1');
    $assert(!$resolver->resolveContext($pdo, $superSession)['allowed'], 'Disabled superadmin must deny on next request.');

    fwrite(STDOUT, "ROLES_ACCESS_RESOLVER_CONTEXT=PASS\n");
    fwrite(STDOUT, "ROLES_ACCESS_RESOLVER_PAGE=PASS\n");
    fwrite(STDOUT, "ROLES_ACCESS_RESOLVER_DATA=PASS\n");
    fwrite(STDOUT, "ROLES_ACCESS_RESOLVER_IMMEDIATE_REVOCATION=PASS\n");
    fwrite(STDOUT, "ROLES_ACCESS_RESOLVER_TENANT_ISOLATION=PASS\n");
} finally {
    $admin->exec("DROP DATABASE IF EXISTS {$quotedDb}");
}
