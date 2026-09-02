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
    '.github/p07/P07_deploy_erpv2.sh',
    'Preserve exact-SHA P07 deploy engine',
    'P07_deploy_erpv2_${GITHUB_RUN_ID}.sh',
    'steps.engine.outputs.sha256',
    'remote_engine_sha=',
    'p07_engine_sha256',
    'resources/document-templates',
    'template_sha() {',
    "base64 -d >> \"\$decoded\"",
    'contract_template_sha="$(template_sha contract_vehicle_lease_crew_v1.docx)"',
    'waybill_template_sha="$(template_sha waybill_driver_assignment_v1.docx)"',
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
$quoteSafeRemoteSha = <<<'YAML'
"sha256sum '$remote_engine'" | awk '{print $1}'
YAML;
if (!str_contains($controlled, $quoteSafeRemoteSha)) {
    throw new RuntimeException('Remote P07 SHA must be parsed locally so bash -u cannot expand awk positional $1.');
}
$nestedRemoteAwk = <<<'YAML'
"sha256sum '$remote_engine' | awk
YAML;
if (str_contains($controlled, $nestedRemoteAwk)) {
    throw new RuntimeException('Remote P07 SHA command must not embed awk inside the quoted SSH command.');
}
if (str_contains($controlled, 'template_shas="$(php -r')) {
    throw new RuntimeException('Post-deploy document resource verification must not depend on the hosting account default PHP CLI.');
}
if (str_contains($controlled, '$SERVER_ROOT/deploy/P07_deploy_erpv2.sh')) {
    throw new RuntimeException('Controlled deploy must execute the exact-SHA canonical P07 helper, not a persistent server copy.');
}
if (preg_match('~git archive[^\n]*--[^\n]*(?:storage|uploads|logs|vendor|\.env)~', $controlled)) {
    throw new RuntimeException('Controlled deploy artifact roots must not include runtime/secret directories.');
}

$p07 = (string) file_get_contents(__DIR__ . '/../.github/p07/P07_deploy_erpv2.sh');
foreach ([
    'ALLOWED_ROOTS=(app bootstrap config database public scripts tools tests resources)',
    'PLANEX_P07_TEST_MODE',
    'PLANEX_P07_PHP_BIN',
    'resolve_php_bin() {',
    '/opt/php/8.1/bin/php',
    'PHP_VERSION_ID >= 80100',
    'PHP_BIN="$(resolve_php_bin)"',
    '"$PHP_BIN" -l "$file"',
    "forbidden='(^|/)(\\.git|\\.env([^/]*)?|storage|uploads|logs|tmp|screenshots|node_modules|vendor)",
    'marker_tmp="$MARKER.tmp.$$"',
    "printf 'P07_DEPLOY_OK commit=%s backup=%s",
] as $needle) {
    if (!str_contains($p07, $needle)) {
        throw new RuntimeException('Missing canonical P07 engine contract marker: ' . $needle);
    }
}

if (!str_contains($p07, "echo 'No PHP CLI >= 8.1 available for P07 preflight.'")) {
    throw new RuntimeException('P07 must fail closed when no compatible PHP CLI is available.');
}

echo "agent_deploy_request_contract_test: PASS\n";
