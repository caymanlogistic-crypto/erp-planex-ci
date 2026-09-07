<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/Service/EntityResponsibilityService.php';

use App\Service\EntityResponsibilityService;

$host = getenv('PLANEX_SCHEMA_DB_HOST') ?: '127.0.0.1';
$port = (int)(getenv('PLANEX_SCHEMA_DB_PORT') ?: 3306);
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
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false]
);
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach (['users','company_memberships','company_roles','user_accounts'] as $table) $pdo->exec("DROP TABLE IF EXISTS `{$table}`");
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
$pdo->exec("CREATE TABLE user_accounts (id INT UNSIGNED PRIMARY KEY, full_name VARCHAR(255) NOT NULL, login VARCHAR(190) NOT NULL, status VARCHAR(20) NOT NULL) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE company_roles (id INT UNSIGNED PRIMARY KEY, company_id INT UNSIGNED NOT NULL, name VARCHAR(120) NOT NULL, normalized_name VARCHAR(120) NOT NULL) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE company_memberships (id BIGINT UNSIGNED PRIMARY KEY, account_id INT UNSIGNED NOT NULL, company_id INT UNSIGNED NOT NULL, role_id INT UNSIGNED NOT NULL, status VARCHAR(20) NOT NULL) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE users (id INT UNSIGNED PRIMARY KEY, membership_id BIGINT UNSIGNED NULL, full_name VARCHAR(255) NOT NULL, login VARCHAR(190) NOT NULL, role_code VARCHAR(50) NOT NULL, status VARCHAR(20) NOT NULL, deleted_at DATETIME NULL, UNIQUE KEY uq_users_membership_id(membership_id)) ENGINE=InnoDB");

$pdo->exec("INSERT INTO company_roles VALUES (10,25,'Руководитель','company_owner'),(11,25,'Логист+','логист+'),(12,25,'Динамическая роль','custom'),(13,25,'Логист','логист'),(21,27,'Чужая роль','logist')");
$pdo->exec("INSERT INTO user_accounts VALUES
 (100,'Owner','owner','active'),(101,'Senior','senior','active'),(102,'Dynamic','dynamic','active'),(103,'Blocked','blocked','blocked'),(104,'Foreign','foreign','active'),(105,'Michael','michael','active')");
$pdo->exec("INSERT INTO company_memberships VALUES
 (200,100,25,10,'active'),(201,101,25,11,'active'),(202,102,25,12,'active'),(203,103,25,12,'active'),(204,104,27,21,'active'),(205,105,25,13,'active')");
$pdo->exec("INSERT INTO users VALUES
 (300,200,'Owner tenant','owner','company_owner','active',NULL),
 (301,201,'Senior tenant','senior','senior_logist','active',NULL),
 (302,202,'Dynamic tenant','dynamic','membership_role','active',NULL),
 (303,203,'Blocked tenant','blocked','membership_role','active',NULL),
 (304,204,'Foreign tenant','foreign','membership_role','active',NULL)");

$rows = EntityResponsibilityService::eligibleRecipients($pdo, $pdo, 25);
$ids = array_map(static fn(array $row): int => (int)$row['membership_id'], $rows);
sort($ids);
if ($ids !== [200,201,202,205]) throw new RuntimeException('MySQL eligibility mismatch: ' . json_encode($ids));
if (EntityResponsibilityService::eligibleRecipientMembershipId($pdo,$pdo,25,201)!==201) throw new RuntimeException('senior_logist membership lookup failed');
if (EntityResponsibilityService::eligibleRecipientMembershipId($pdo,$pdo,25,205)!==205) throw new RuntimeException('logist without tenant projection lookup failed');
if (EntityResponsibilityService::eligibleRecipientMembershipId($pdo,$pdo,25,202)!==202) throw new RuntimeException('dynamic role rejected');
if (EntityResponsibilityService::eligibleRecipientMembershipId($pdo,$pdo,25,200)!==200) throw new RuntimeException('owner rejected');
if (EntityResponsibilityService::eligibleRecipientMembershipId($pdo,$pdo,25,203)!==null) throw new RuntimeException('blocked account accepted');
if (EntityResponsibilityService::eligibleRecipientMembershipId($pdo,$pdo,25,204)!==null) throw new RuntimeException('foreign company accepted');
$pdo->exec("UPDATE users SET role_code='legacy_irrelevant' WHERE id=301");
if (EntityResponsibilityService::eligibleRecipientMembershipId($pdo,$pdo,25,201)!==201) throw new RuntimeException('tenant role_code affected MySQL eligibility');

echo "ROLES_ACCESS_RESPONSIBILITY_RECIPIENT_MYSQL=PASS\n";
echo "MYSQL_RENAMED_LEGACY_ROLE_RECIPIENT=PASS\n";
echo "MYSQL_USER_WITHOUT_TENANT_PROJECTION=PASS\n";
echo "MYSQL_DYNAMIC_ROLE_RECIPIENT=PASS\n";
echo "MYSQL_OWNER_RECIPIENT=PASS\n";
echo "MYSQL_CROSS_COMPANY_RECIPIENT=DENY\n";
echo "MYSQL_TENANT_ROLE_CODE_AUTHORITY=NO\n";
