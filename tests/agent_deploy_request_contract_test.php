<?php

declare(strict_types=1);

$path = __DIR__ . '/../.github/workflows/erpv2_agent_deploy_request.yml';
$yaml = (string) file_get_contents($path);

$needles = [
    'deploy/erpv2-agent',
    'actions: write',
    "github.ref_name == 'deploy/erpv2-agent'",
    'CANONICAL_BRANCH: chatgpt/production-stabilization-20260802',
    'CONTROLLED_DEPLOY_WORKFLOW: erpv2_controlled_deploy.yml',
    'CANONICAL_CI_WORKFLOW: ci.yml',
    'Validate exact canonical HEAD',
    'Ensure exact canonical ERP PLANEX CI PASS',
    'event=$event',
    'workflow_dispatch',
    'AGENT_DEPLOY_CANONICAL_CI_DISPATCHED=YES',
    'actions/workflows/$CANONICAL_CI_WORKFLOW/dispatches',
    'Reconfirm canonical HEAD immediately before dispatch',
    'actions/workflows/$CONTROLLED_DEPLOY_WORKFLOW/dispatches',
    "'confirm_deploy': 'DEPLOY_ERPV2'",
    "elif run.get('conclusion') == 'skipped':",
    'print(f"SKIP:{run.get(\'id\')}:{run.get(\'run_number\')}:{event}")',
    'SKIP:*) echo "Canonical push CI intentionally skipped:',
    'if [ "$push_state" = NONE ] || [[ "$push_state" == SKIP:* ]]; then',
    'SKIP:*) : ;;',
];

foreach ($needles as $needle) {
    if (!str_contains($yaml, $needle)) {
        throw new RuntimeException('Missing agent deploy request contract marker: ' . $needle);
    }
}

if (str_contains($yaml, 'github.actor == github.repository_owner')) {
    throw new RuntimeException('Agent deploy request must authorize the request ref/exact canonical SHA, not the technical integration actor.');
}

if (str_contains($yaml, 'public_html/erpv2') || str_contains($yaml, 'ERPV2_SSH_PRIVATE_KEY')) {
    throw new RuntimeException('Agent deploy request must remain control-plane only and must not carry production SSH/write logic.');
}

if (!str_contains($yaml, 'test "$SOURCE_SHA" = "$canonical_sha"')) {
    throw new RuntimeException('Exact canonical HEAD equality gate is missing.');
}

if (!str_contains($yaml, 'test "$SOURCE_SHA" = "$(git rev-parse "refs/remotes/origin/${CANONICAL_BRANCH}")"')) {
    throw new RuntimeException('Immediate pre-dispatch canonical HEAD reconfirmation is missing.');
}

if (str_contains($yaml, 'FAIL:*) echo "Canonical push CI failed: $push_state"') && !str_contains($yaml, 'SKIP:*) echo "Canonical push CI intentionally skipped:')) {
    throw new RuntimeException('Skipped canonical push CI must not be treated as a hard failure before dispatched CI is checked.');
}

$controlledPath = __DIR__ . '/../.github/workflows/erpv2_controlled_deploy.yml';
$controlled = (string) file_get_contents($controlledPath);
$controlledNeedles = [
    'git archive --format=tar --output=erpv2_source.tar "$SOURCE_SHA" -- app bootstrap config database public scripts tools tests resources',
    'php tests/document_generator_deploy_artifact_test.php "$artifact"',
    'resources/document-templates',
    '0e3e7a3ca4d81face23fa070bcabffacc86636d08affdda91faa3c725d4e8adc',
    '5a45e855c6fb47dbc96e6d8b9185d7032b5ca86a9baefbb78fdc6419749f73ab',
    'contract_template_sha256',
    'waybill_template_sha256',
];
foreach ($controlledNeedles as $needle) {
    if (!str_contains($controlled, $needle)) {
        throw new RuntimeException('Missing controlled deploy artifact contract marker: ' . $needle);
    }
}
if (preg_match('~git archive[^\n]*--[^\n]*(?:storage|uploads|logs|vendor|\.env)~', $controlled)) {
    throw new RuntimeException('Controlled deploy artifact roots must not include runtime/secret directories.');
}

echo "agent_deploy_request_contract_test: PASS\n";
