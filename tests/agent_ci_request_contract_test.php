<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$workflow = file_get_contents($root . '/.github/workflows/erpv2_agent_ci_request.yml') ?: '';
$pass = 0; $fail = 0;
$ok = static function (string $name, bool $value) use (&$pass, &$fail): void {
    if ($value) { $pass++; return; }
    $fail++; fwrite(STDERR, "FAIL: {$name}\n");
};

$ok('bridge is issue-comment only', str_contains($workflow, "\n  issue_comment:\n") && !str_contains($workflow, "\n  push:\n") && !str_contains($workflow, "\n  pull_request:\n") && !str_contains($workflow, "\n  workflow_dispatch:\n"));
$ok('bridge requires repository owner actor', str_contains($workflow, 'github.actor == github.repository_owner'));
$ok('bridge requires PR conversation', str_contains($workflow, 'github.event.issue.pull_request != null'));
$ok('bridge parses exact lowercase forty-char SHA', str_contains($workflow, "re.fullmatch(r'/agent-ci ([0-9a-f]{40})'"));
$ok('bridge is fixed to canonical branch', str_contains($workflow, 'CANONICAL_BRANCH: chatgpt/production-stabilization-20260802'));
$ok('bridge dispatches main CI only', str_contains($workflow, 'TARGET_WORKFLOW: ci.yml') && str_contains($workflow, '/actions/workflows/$TARGET_WORKFLOW/dispatches'));
$ok('bridge has no production permissions or SSH secrets', str_contains($workflow, 'actions: write') && str_contains($workflow, 'contents: read') && !str_contains($workflow, 'ERPV2_SSH_') && !str_contains($workflow, 'DEPLOY_ERPV2') && !str_contains($workflow, 'APPLY_'));
$ok('bridge checks exact local canonical SHA', str_contains($workflow, 'test "$(git rev-parse HEAD)" = "$REQUESTED_SHA"'));
$ok('bridge reconfirms remote canonical immediately before dispatch', str_contains($workflow, 'refs/remotes/origin/${CANONICAL_BRANCH}') && str_contains($workflow, 'test "$REQUESTED_SHA" = "$(git rev-parse'));

printf("AGENT_CI_REQUEST_CONTRACT: %d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
