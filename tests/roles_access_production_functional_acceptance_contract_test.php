<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$workflow = file_get_contents($root . '/.github/workflows/roles_access_production_functional_acceptance.yml');
$script = file_get_contents($root . '/.github/roles-access/production_functional_acceptance.js');
$identity = file_get_contents($root . '/.github/roles-access/qa_identity.php');

$ok = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$ok(str_contains($workflow, 'workflow_dispatch:'), 'functional acceptance must retain manual exact-SHA trigger');
$ok(str_contains($workflow, 'push:') && str_contains($workflow, 'qa/roles-access-functional-agent'), 'functional acceptance must support only the dedicated QA push trigger');
$ok(str_contains($workflow, 'test "$REF_NAME" = "$QA_TRIGGER_BRANCH"'), 'push-triggered acceptance must fail closed outside the dedicated QA branch');
$ok(str_contains($workflow, 'SOURCE_SHA:') && str_contains($workflow, 'github.event_name') && str_contains($workflow, 'inputs.source_sha') && str_contains($workflow, 'github.sha'), 'workflow must resolve source SHA from manual input or exact QA push SHA');
$ok(str_contains($workflow, 'ref: ${{ env.SOURCE_SHA }}') && str_contains($workflow, 'test "$(git rev-parse HEAD)" = "$SHA"'), 'workflow must bind checkout to resolved exact SHA');
$ok(str_contains($workflow, 'git ls-remote origin refs/heads/${CANONICAL_BRANCH}'), 'workflow must reconfirm exact current canonical');
$ok(str_contains($workflow, 'P07_erpv2_deployed_commit.txt'), 'workflow must require exact deployed marker');
$ok(!str_contains($workflow, 'P11_RUNTIME_CREDENTIALS_B64'), 'functional acceptance must not depend on an unavailable real-user password secret');
$ok(str_contains($workflow, 'Provision isolated temporary QA owner identity') && str_contains($workflow, '.github/roles-access/qa_identity.php'), 'workflow must provision the isolated temporary QA identity');
$ok(str_contains($workflow, 'QA_DYNAMIC_RECIPIENT_READY=YES') && str_contains($workflow, 'QA_TENANT_PROJECTIONS_READY=YES'), 'workflow must require isolated dynamic recipient projections before browser writes');
$ok(str_contains($workflow, 'QA_CONTRACTOR_FIXTURE_READY=YES') && str_contains($workflow, 'QA_RESPONSIBILITY_FIXTURE_READY=YES'), 'workflow must require the isolated contractor and responsibility fixtures before browser writes');
$ok(str_contains($workflow, 'Cleanup temporary QA owner identity') && str_contains($workflow, 'if: always()') && str_contains($workflow, 'QA_IDENTITY_RESIDUE=0'), 'temporary QA identity cleanup must be unconditional and residue-gated');
$ok(str_contains($workflow, 'QA_RESPONSIBILITY_FIXTURE_RESIDUE=0'), 'temporary responsibility fixture cleanup must be residue-gated');
$ok(str_contains($workflow, 'QA_CONTRACTOR_FIXTURE_RESIDUE=0'), 'temporary contractor fixture cleanup must be residue-gated');
foreach (['QA_TENANT_PROJECTION_RESIDUE=0','QA_DYNAMIC_RECIPIENT_RESIDUE=0','QA_TEMP_ROLE_RESIDUE=0'] as $marker) {
    $ok(str_contains($workflow, $marker), "workflow cleanup missing {$marker}");
}
$ok(str_contains($workflow, 'QA_FOREIGN_MEMBERSHIP_ID'), 'workflow must pass a foreign-company membership identifier into the isolation proof');
$ok(str_contains($workflow, 'QA_OWNER_TENANT_USER_ID') && str_contains($workflow, 'QA_DYNAMIC_MEMBERSHIP_ID') && str_contains($workflow, 'QA_DYNAMIC_TENANT_USER_ID'), 'workflow must bind browser acceptance to the exact run-scoped owner and dynamic tenant projections');
$ok(str_contains($workflow, 'QA_CONTRACTOR_ID'), 'workflow must bind browser acceptance to the exact run-scoped contractor fixture');
$ok(str_contains($workflow, 'LIVE_PARENT: /home/s/spugovxsim/planexp/public_html'), 'functional acceptance must use the real production web root');
$ok(str_contains($script, "page.locator('input[name=\"login\"]')") && str_contains($script, "page.locator('input[name=\"password\"]')"), 'acceptance must perform normal username/password login');
$ok(!str_contains($script, 'QA_LEGACY_SESSION_ID') && !str_contains($script, 'create-legacy-owner'), 'acceptance must not use synthetic legacy-session authentication');
foreach ([
    'DEFAULT_LANDING=PASS','EFFECTIVE_MENU=PASS','ROLES_ACCESS=PASS','ROLES_READBACK=PASS',
    'PERSONAL_OVERRIDES_READBACK=PASS','ASSIGNMENTS_TAB=PASS','RESPONSIBLE_ASSIGNMENTS=PASS',
    'DYNAMIC_RESPONSIBILITY_RECIPIENT=PASS',
    'RESPONSIBILITY_TRANSFER=PASS','RESPONSIBILITY_BACKEND_READBACK=PASS','RESPONSIBILITY_RESTORE=PASS',
    'QA_RESIDUE=0','CROSS_COMPANY_LEAKAGE=NO','PRODUCTION_FUNCTIONAL_ACCEPTANCE=PASS'
] as $marker) {
    $ok(str_contains($script, $marker), "acceptance script missing marker {$marker}");
}
$ok(str_contains($script, '/company/roles-access/assignments?membership_id='), 'acceptance must exercise assignments backend readback/isolation');
$ok(str_contains($script, '/company/responsible-assignments?tab='), 'acceptance must exercise responsibility UI');
$ok(str_contains($script, 'form.evaluate(f=>f.requestSubmit())'), 'responsibility transfer must use the real browser form POST');
$ok(str_contains($script, 'suppliedContractorId') && str_contains($script, 'exact run-scoped contractor transfer form is missing'), 'responsibility proof must target the exact run-scoped contractor row');
$ok(str_contains($script, 'original === suppliedOwnerTenantUserId'), 'responsibility proof must verify the exact run-scoped owner baseline before transfer');
$ok(str_contains($script, 'Number(x.user_id) === suppliedDynamicTenantUserId') && str_contains($script, 'Number(x.membership_id) === suppliedDynamicMembershipId'), 'responsibility transfer target must be the exact run-scoped dynamic-role recipient');
$ok(str_contains($script, 'suppliedDynamicMembershipId') && str_contains($script, 'suppliedDynamicTenantUserId'), 'browser acceptance must bind to exact dynamic membership and tenant user ids');
foreach (['CompanyRoleService','CompanyMembershipService','qa_func_','OWNER_SYSTEM_KEY','user_accounts','company_memberships','QA_IDENTITY_CREATED=YES','QA_IDENTITY_RESIDUE=0'] as $needle) {
    $ok(str_contains($identity, $needle), "QA identity helper missing lifecycle token {$needle}");
}
$ok(str_contains($identity, "system_key='company_owner'") && str_contains($identity, "ar.state='ready'"), 'QA identity must bind only to a ready company owner role');
$ok(str_contains($identity, "SELECT id,name FROM companies WHERE status='active' AND id<>? ORDER BY id LIMIT 1"), 'foreign-company isolation must require only a second active company');
$ok(!str_contains($identity, 'no two active QA companies with independent owners'), 'foreign-company isolation must not require an independent owner in the foreign company');
$ok(str_contains($identity, 'EntityResponsibilityService::eligibleRecipients'), 'QA helper must verify tenant-projected eligible responsibility recipients');
$ok(str_contains($identity, "app/Support/crypto_helper.php"), 'QA helper must load crypto_helper before resolving encrypted tenant DB credentials');
$ok(str_contains($identity, '$foreign=$membershipService->add($foreignCompanyId,(int)$foreignRole'), 'foreign proof membership must use a run-scoped ordinary role rather than a foreign owner role');
foreach (['QA Functional Role ','QA Functional Foreign Role ','QA Functional Owner ','QA Functional Dynamic ','QA Functional Foreign ','foreign_role_id','new App\\Service\\CompanyRoleService($pdo)','->create(','->delete('] as $needle) {
    $ok(str_contains($identity, $needle), "QA dynamic role/account lifecycle token missing {$needle}");
}
$ok(str_contains($identity, 'INSERT INTO users (membership_id,full_name,login,password_hash,role_code,status)') && str_contains($identity, "'membership_role'") && str_contains($identity, "'company_owner'"), 'QA helper must create stable membership-linked owner/dynamic tenant projections without role_code authority');
foreach (['QA_OWNER_TENANT_USER_ID=','QA_DYNAMIC_MEMBERSHIP_ID=','QA_DYNAMIC_TENANT_USER_ID=','QA_DYNAMIC_RECIPIENT_READY=YES','QA_TENANT_PROJECTIONS_READY=YES','QA_TENANT_PROJECTION_RESIDUE=0','QA_DYNAMIC_RECIPIENT_RESIDUE=0','QA_TEMP_ROLE_RESIDUE=0'] as $needle) {
    $ok(str_contains($identity, $needle), "QA dynamic fixture lifecycle token missing {$needle}");
}
foreach (['qa_contractor_fixture','QA Functional Contractor ','INSERT INTO contractors (name,inn,status)','QA_CONTRACTOR_ID=','cleanupQaContractorFixture','QA_CONTRACTOR_FIXTURE_READY=YES','QA_CONTRACTOR_FIXTURE_RESIDUE=0'] as $needle) {
    $ok(str_contains($identity, $needle), "QA contractor fixture lifecycle token missing {$needle}");
}
foreach (['responsibility_fixture','QA_RESPONSIBILITY_FIXTURE_READY=YES','QA_RESPONSIBILITY_FIXTURE_RESIDUE=0','restoreQaResponsibilityFixture'] as $needle) {
    $ok(str_contains($identity, $needle), "QA responsibility fixture lifecycle token missing {$needle}");
}
$statePos = strpos($identity, 'file_put_contents($stateFile');
$contractorWritePos = strpos($identity, 'INSERT INTO contractors (name,inn,status)');
$fixtureWritePos = strrpos($identity, 'INSERT INTO entity_responsibilities');
$contractorCleanupPos = strpos($identity, 'cleanupQaContractorFixture($pdo,$config,$state,$runId);');
$projectionCleanupPos = strpos($identity, 'cleanupQaTenantProjections($pdo,$config,$runId,$accounts');
$ownerCleanupPos = strpos($identity, '$membershipService->remove');
$ok($statePos !== false && $contractorWritePos !== false && $statePos < $contractorWritePos, 'QA fixture must persist recovery state before tenant contractor mutation');
$ok($contractorWritePos < $fixtureWritePos, 'QA responsibility baseline must only target the run-scoped contractor after it exists');
$ok($contractorCleanupPos !== false && $projectionCleanupPos !== false && $ownerCleanupPos !== false && $contractorCleanupPos < $projectionCleanupPos && $projectionCleanupPos < $ownerCleanupPos, 'QA responsibility/contractor/projections must be removed before central memberships');
$roleCreatePos = strpos($identity, '$role=$roleService->create($companyId,$roleName);');
$ownerAddPos = strpos($identity, "'QA Functional Owner '.");
$projectionWritePos = strpos($identity, 'INSERT INTO users (membership_id,full_name,login,password_hash,role_code,status)');
$ok($roleCreatePos !== false && $ownerAddPos !== false && $projectionWritePos !== false && $roleCreatePos < $ownerAddPos && $ownerAddPos < $projectionWritePos && $projectionWritePos < $contractorWritePos, 'QA lifecycle order must be role -> central identities -> tenant projections -> contractor -> responsibility');
$ok(!str_contains($identity, 'company_users'), 'QA helper must not use obsolete company_users projection');
$ok(str_contains($workflow, 'erpv2/roles-access-functional'), 'successful acceptance must publish exact-SHA commit status');

echo "ROLES_ACCESS_PRODUCTION_FUNCTIONAL_ACCEPTANCE_CONTRACT_PASS=YES\n";
