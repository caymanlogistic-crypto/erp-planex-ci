<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$index = file_get_contents($root . '/app/Http/Controllers/Company/ResponsibleAssignmentActions/index.php');
$reassign = file_get_contents($root . '/app/Http/Controllers/Company/ResponsibleAssignmentActions/reassign.php');
$service = file_get_contents($root . '/app/Service/EntityResponsibilityService.php');

$ok = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$ok(!str_contains($index, "role_code = 'logist'") && !str_contains($index, 'role_code=\'logist\''), 'GET recipient list still hard-codes legacy logist role_code');
$ok(str_contains($index, 'EntityResponsibilityService::eligibleRecipients'), 'GET recipient list must use shared dynamic-membership eligibility');
$ok(str_contains($reassign, 'EntityResponsibilityService::eligibleRecipientMembershipId'), 'POST transfer must use the same eligibility authority');
$ok(!str_contains($reassign, "company_memberships WHERE id=? AND company_id=? AND status='active'"), 'POST must not keep a weaker duplicate membership-only rule');
$ok(str_contains($service, "JOIN user_accounts a ON a.id=m.account_id"), 'eligibility must require current global account');
$ok(str_contains($service, "JOIN company_roles r ON r.id=m.role_id AND r.company_id=m.company_id"), 'eligibility must require role bound to current company');
$ok(str_contains($service, "m.status='active'") && str_contains($service, "a.status='active'"), 'eligibility must require active membership and account');
$ok(str_contains($service, "r.normalized_name IN ('logist','senior_logist')"), 'eligibility must be limited to factual Logist/Logist+ central roles');
$ok(!preg_match("/role_code\\s*=\\s*['\"](?:logist|senior_logist|membership_role)['\"]/", $service), 'eligibility service must not authorize by tenant role_code');

$view = file_get_contents($root . '/app/View/pages/company_responsible_assignments.php');
$ok(substr_count($view, 'csrfField()') >= 2 && !str_contains($view, 'csrfInput()'), 'responsibility transfer forms must use the canonical CSRF field helper for real browser POSTs');
$ok(str_contains($view, 'data-membership-id='), 'recipient options must expose stable membership id for functional/isolation verification');
$ok(str_contains($view, 'value="<?=$l[\'membership_id\']?>"'), 'recipient option value must use stable membership id');
$ok(str_contains($view, '$item[\'logist_membership_id\']'), 'current recipient selection must compare stable membership ids');

echo "ROLES_ACCESS_RESPONSIBILITY_RECIPIENT_CONTRACT_PASS=YES\n";
