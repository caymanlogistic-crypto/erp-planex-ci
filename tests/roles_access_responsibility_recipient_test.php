<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/Service/EntityResponsibilityService.php';

use App\Service\EntityResponsibilityService;

$ok = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    fwrite(STDERR, "SKIP: pdo_sqlite unavailable\n");
    exit(0);
}

$central = new PDO('sqlite::memory:');
$central->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$tenant = new PDO('sqlite::memory:');
$tenant->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$central->exec('CREATE TABLE user_accounts (id INTEGER PRIMARY KEY, full_name TEXT, login TEXT, status TEXT)');
$central->exec('CREATE TABLE company_roles (id INTEGER PRIMARY KEY, company_id INTEGER, name TEXT)');
$central->exec('CREATE TABLE company_memberships (id INTEGER PRIMARY KEY, account_id INTEGER, company_id INTEGER, role_id INTEGER, status TEXT)');
$tenant->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, membership_id INTEGER, full_name TEXT, login TEXT, role_code TEXT, status TEXT, deleted_at TEXT NULL)');

$central->exec("INSERT INTO company_roles(id,company_id,name) VALUES
    (11,25,'Руководитель'),(12,25,'Логист+'),(13,25,'Пользовательская роль'),(21,27,'Чужая роль')");
$central->exec("INSERT INTO user_accounts(id,full_name,login,status) VALUES
    (101,'Owner','owner','active'),
    (102,'Senior','senior','active'),
    (103,'Dynamic','dynamic','active'),
    (104,'Blocked membership','blocked_m','active'),
    (105,'Blocked account','blocked_a','blocked'),
    (106,'Foreign','foreign','active'),
    (107,'Deleted projection','deleted_p','active'),
    (108,'Inactive projection','inactive_p','active'),
    (109,'No projection','none','active')");
$central->exec("INSERT INTO company_memberships(id,account_id,company_id,role_id,status) VALUES
    (201,101,25,11,'active'),
    (202,102,25,12,'active'),
    (203,103,25,13,'active'),
    (204,104,25,13,'blocked'),
    (205,105,25,13,'active'),
    (206,106,27,21,'active'),
    (207,107,25,13,'active'),
    (208,108,25,13,'active'),
    (209,109,25,13,'active')");

$tenant->exec("INSERT INTO users(id,membership_id,full_name,login,role_code,status,deleted_at) VALUES
    (301,201,'Owner tenant','owner','company_owner','active',NULL),
    (302,202,'Senior tenant','senior','senior_logist','active',NULL),
    (303,203,'Dynamic tenant','dynamic','membership_role','active',NULL),
    (304,204,'Blocked membership tenant','blocked_m','membership_role','active',NULL),
    (305,205,'Blocked account tenant','blocked_a','membership_role','active',NULL),
    (306,206,'Foreign tenant','foreign','membership_role','active',NULL),
    (307,207,'Deleted tenant','deleted_p','membership_role','active','2026-09-06 00:00:00'),
    (308,208,'Inactive tenant','inactive_p','membership_role','blocked',NULL)");

$rows = EntityResponsibilityService::eligibleRecipients($central, $tenant, 25);
$ids = array_map(static fn(array $row): int => (int)$row['id'], $rows);
$ok($ids === [303, 301, 302], 'eligible recipients must follow central active membership/account and ignore tenant role_code; got ' . json_encode($ids));

$byId = [];
foreach ($rows as $row) $byId[(int)$row['id']] = $row;
$ok(($byId[302]['membership_id'] ?? null) === 202, 'senior_logist projection must resolve to membership 202');
$ok(($byId[303]['membership_id'] ?? null) === 203, 'membership_role projection must resolve to membership 203');
$ok(($byId[303]['full_name'] ?? '') === 'Dynamic', 'display identity must come from central global account');

$ok(EntityResponsibilityService::eligibleRecipientMembershipId($central, $tenant, 25, 302) === 202, 'senior recipient lookup failed');
$ok(EntityResponsibilityService::eligibleRecipientMembershipId($central, $tenant, 25, 303) === 203, 'dynamic recipient lookup failed');
$ok(EntityResponsibilityService::eligibleRecipientMembershipId($central, $tenant, 25, 304) === null, 'blocked membership must be rejected');
$ok(EntityResponsibilityService::eligibleRecipientMembershipId($central, $tenant, 25, 305) === null, 'blocked account must be rejected');
$ok(EntityResponsibilityService::eligibleRecipientMembershipId($central, $tenant, 25, 306) === null, 'cross-company membership must be rejected');
$ok(EntityResponsibilityService::eligibleRecipientMembershipId($central, $tenant, 25, 307) === null, 'deleted tenant projection must be rejected');
$ok(EntityResponsibilityService::eligibleRecipientMembershipId($central, $tenant, 25, 308) === null, 'inactive tenant projection must be rejected');
$ok(EntityResponsibilityService::eligibleRecipientMembershipId($central, $tenant, 25, 999) === null, 'unknown tenant user must be rejected');

$tenant->exec("UPDATE users SET role_code='totally_legacy_irrelevant' WHERE id=303");
$ok(EntityResponsibilityService::eligibleRecipientMembershipId($central, $tenant, 25, 303) === 203, 'tenant role_code must never define eligibility');

echo "ROLES_ACCESS_RESPONSIBILITY_RECIPIENT_PASS=YES\n";
echo "SENIOR_LOGIST_ELIGIBLE=YES\n";
echo "MEMBERSHIP_ROLE_ELIGIBLE=YES\n";
echo "TENANT_ROLE_CODE_AUTHORITY=NO\n";
echo "CROSS_COMPANY_RECIPIENT=DENY\n";
