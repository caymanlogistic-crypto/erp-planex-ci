<?php

declare(strict_types=1);

$path = __DIR__ . '/../.github/workflows/erpv2_agent_deploy_request.yml';
$yaml = (string) file_get_contents($path);

$needles = [
    'deploy/erpv2-agent',
    'actions: write',
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
];

foreach ($needles as $needle) {
    if (!str_contains($yaml, $needle)) {
        throw new RuntimeException('Missing agent deploy request contract marker: ' . $needle);
    }
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

echo "agent_deploy_request_contract_test: PASS\n";
