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
$ok(str_contains($workflow, 'QA_RESPONSIBILITY_FIXTURE_READY=YES'), 'workflow must require a prepared responsibility fixture before browser writes');
$ok(str_contains($workflow, 'Cleanup temporary QA owner identity') && str_contains($workflow, 'if: always()') && str_contains($workflow, 'QA_IDENTITY_RESIDUE=0'), 'temporary QA identity cleanup must be unconditional and residue-gated');
$ok(str_contains($workflow, 'QA_RESPONSIBILITY_FIXTURE_RESIDUE=0'), 'temporary responsibility fixture cleanup must be residue-gated');
$ok(str_contains($workflow, 'QA_FOREIGN_MEMBERSHIP_ID'), 'workflow must pass a real foreign membership identifier into the isolation proof');
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
$ok(str_contains($script, 'dynamicRecipientIds.has(Number(x.membership_id))'), 'responsibility transfer target must be a dynamic-role recipient');
foreach (['CompanyMembershipService','qa_func_','OWNER_SYSTEM_KEY','user_accounts','company_memberships','QA_IDENTITY_CREATED=YES','QA_IDENTITY_RESIDUE=0'] as $needle) {
    $ok(str_contains($identity, $needle), "QA identity helper missing lifecycle token {$needle}");
}
$ok(str_contains($identity, "system_key='company_owner'") && str_contains($identity, "ar.state='ready'"), 'QA identity must bind only to a ready company owner role');
$ok(str_contains($identity, 'm.company_id<>?'), 'QA identity helper must resolve a real foreign-company membership for isolation');
$ok(str_contains($identity, 'EntityResponsibilityService::eligibleRecipients') && str_contains($identity, 'findResponsibilityFixtureEntity'), 'QA helper must choose a tenant-projected dynamic recipient and visible responsibility fixture entity');
foreach (['responsibility_fixture','original','QA_RESPONSIBILITY_FIXTURE_READY=YES','QA_RESPONSIBILITY_FIXTURE_RESIDUE=0','restoreQaResponsibilityFixture'] as $needle) {
    $ok(str_contains($identity, $needle), "QA responsibility fixture lifecycle token missing {$needle}");
}
$statePos = strpos($identity, 'file_put_contents($stateFile');
$fixtureWritePos = strrpos($identity, 'INSERT INTO entity_responsibilities');
$ok($statePos !== false && $fixtureWritePos !== false && $statePos < $fixtureWritePos, 'QA fixture must persist exact pre-run state before tenant responsibility mutation');
$ok(!str_contains($identity, 'INSERT INTO users') && !str_contains($identity, 'company_users'), 'QA identity must not create tenant or legacy identity projections');
$ok(str_contains($workflow, 'erpv2/roles-access-functional'), 'successful acceptance must publish exact-SHA commit status');

echo "ROLES_ACCESS_PRODUCTION_FUNCTIONAL_ACCEPTANCE_CONTRACT_PASS=YES\n";
