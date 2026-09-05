<?php

declare(strict_types=1);

use App\Security\PdoAccessStateProvider;

require_once __DIR__ . '/../app/Security/AccessStateProvider.php';
require_once __DIR__ . '/../app/Security/MembershipAccessSnapshot.php';
require_once __DIR__ . '/../app/Security/PdoAccessStateProvider.php';

$host = getenv('PLANEX_SCHEMA_DB_HOST') ?: '127.0.0.1';
$port = (int) (getenv('PLANEX_SCHEMA_DB_PORT') ?: 3306);
$user = getenv('PLANEX_SCHEMA_DB_USER') ?: 'planex_ci';
$password = getenv('PLANEX_SCHEMA_DB_PASSWORD') ?: 'planex_ci';
$dbName = getenv('PLANEX_TEST_FIXED_DB') ?: '';
$allow = array_filter(explode(',', getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST') ?: ''));
if ($dbName === '' || !in_array($dbName, $allow, true)) {
    throw new RuntimeException('A guarded fixed runner DB is required.');
}
$pdo = new PDO(
    "mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4",
    $user,
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
);
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach (['membership_data_scope_overrides','membership_page_overrides','role_data_scopes','role_page_permissions','company_memberships','company_access_readiness','company_roles','user_accounts','companies'] as $table) {
    $pdo->exec('DROP TABLE IF EXISTS ' . $table);
}
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
$pdo->exec("CREATE TABLE companies (id INT UNSIGNED NOT NULL PRIMARY KEY,name VARCHAR(255) NOT NULL,status VARCHAR(20) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$migration = (string) file_get_contents(__DIR__ . '/../database/migrations/013_create_roles_access.sql');
$pdo->exec($migration);
$pdo->exec($migration);

$pdo->exec("INSERT INTO companies(id,name,status) VALUES (1,'One','active'),(2,'Two','active')");
$pdo->exec("INSERT INTO user_accounts(id,full_name,login,normalized_login,password_hash,status) VALUES (10,'User','user','user','hash','active')");
$pdo->exec("INSERT INTO company_roles(id,company_id,name,normalized_name,system_key,is_system) VALUES (20,1,'Dispatcher','dispatcher',NULL,0),(21,2,'Foreign','foreign',NULL,0)");
$pdo->exec("INSERT INTO company_memberships(id,account_id,company_id,role_id,status) VALUES (30,10,1,20,'active')");
$pdo->exec("INSERT INTO role_page_permissions(role_id,page_key,is_allowed) VALUES (20,'company.dashboard',1),(20,'company.clients',0)");
$pdo->exec("INSERT INTO role_data_scopes(role_id,entity_type,scope) VALUES (20,'client','own')");
$pdo->exec("INSERT INTO membership_page_overrides(membership_id,page_key,effect) VALUES (30,'company.clients','allow')");
$pdo->exec("INSERT INTO membership_data_scope_overrides(membership_id,entity_type,scope) VALUES (30,'client','all')");
$hash = str_repeat('a', 64);
$stmt = $pdo->prepare("INSERT INTO company_access_readiness (company_id,state,before_sha256,after_sha256,comparison_sha256,verified_at,activated_at) VALUES (1,'ready',?,?,?,NOW(),NOW())");
$stmt->execute([$hash, $hash, $hash]);

$provider = new PdoAccessStateProvider($pdo);
if (!$provider->isCompanyAccessReady(1) || $provider->isCompanyAccessReady(2)) {
    throw new RuntimeException('readiness gate mismatch');
}
$snapshot = $provider->loadMembershipAccess(10, 1);
if ($snapshot === null
    || $snapshot->membershipId !== 30
    || $snapshot->rolePageKeys !== ['company.dashboard']
    || $snapshot->pageOverrides !== ['company.clients' => 'allow']
    || $snapshot->roleDataScopes !== ['client' => 'own']
    || $snapshot->dataScopeOverrides !== ['client' => 'all']) {
    throw new RuntimeException('provider snapshot mismatch');
}
$crossCompanyRejected = false;
try {
    $pdo->exec("INSERT INTO company_memberships(account_id,company_id,role_id,status) VALUES (10,1,21,'active')");
} catch (PDOException) {
    $crossCompanyRejected = true;
}
if (!$crossCompanyRejected) {
    throw new RuntimeException('cross-company role assignment was accepted');
}
$pdo->exec("UPDATE company_memberships SET status='blocked' WHERE id=30");
if ($provider->loadMembershipAccess(10, 1)?->status !== 'blocked') {
    throw new RuntimeException('provider did not observe immediate membership revocation');
}

echo "roles access provider mysql: PASS\n";
