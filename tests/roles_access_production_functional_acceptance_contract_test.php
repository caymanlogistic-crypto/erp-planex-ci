<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$workflow = file_get_contents($root . '/.github/workflows/roles_access_production_functional_acceptance.yml');
$script = file_get_contents($root . '/.github/roles-access/production_functional_acceptance.js');

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
$ok(str_contains($workflow, 'P11_RUNTIME_CREDENTIALS_B64'), 'workflow must use protected current runtime credentials');
$ok(str_contains($script, "page.locator('input[name=\"login\"]')") && str_contains($script, "page.locator('input[name=\"password\"]')"), 'acceptance must perform normal username/password login');
$ok(!str_contains($script, 'QA_LEGACY_SESSION_ID') && !str_contains($script, 'create-legacy-owner'), 'acceptance must not use synthetic legacy-session authentication');
foreach ([
    'DEFAULT_LANDING=PASS','EFFECTIVE_MENU=PASS','ROLES_ACCESS=PASS','ROLES_READBACK=PASS',
    'PERSONAL_OVERRIDES_READBACK=PASS','ASSIGNMENTS_TAB=PASS','RESPONSIBLE_ASSIGNMENTS=PASS',
    'RESPONSIBILITY_TRANSFER=PASS','RESPONSIBILITY_BACKEND_READBACK=PASS','RESPONSIBILITY_RESTORE=PASS',
    'QA_RESIDUE=0','CROSS_COMPANY_LEAKAGE=NO','PRODUCTION_FUNCTIONAL_ACCEPTANCE=PASS'
] as $marker) {
    $ok(str_contains($script, $marker), "acceptance script missing marker {$marker}");
}
$ok(str_contains($script, '/company/roles-access/assignments?membership_id='), 'acceptance must exercise assignments backend readback/isolation');
$ok(str_contains($script, '/company/responsible-assignments?tab='), 'acceptance must exercise responsibility UI');
$ok(str_contains($script, 'form.evaluate(f=>f.requestSubmit())'), 'responsibility transfer must use the real browser form POST');
$ok(str_contains($workflow, 'erpv2/roles-access-functional'), 'successful acceptance must publish exact-SHA commit status');

echo "ROLES_ACCESS_PRODUCTION_FUNCTIONAL_ACCEPTANCE_CONTRACT_PASS=YES\n";
