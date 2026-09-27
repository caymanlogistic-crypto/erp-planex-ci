<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$workflowPath = $root . '/.github/workflows/waste_trips_sync_schedule.yml';
$ciPath = $root . '/.github/workflows/ci.yml';

if (!is_file($workflowPath)) {
    fwrite(STDERR, "Scheduler workflow missing\n");
    exit(1);
}
if (!is_file($ciPath)) {
    fwrite(STDERR, "CI workflow missing\n");
    exit(1);
}

$workflow = (string) file_get_contents($workflowPath);
$ci = (string) file_get_contents($ciPath);
$cli = (string) file_get_contents($root . '/scripts/waste_trip_sync.php');
if (!str_contains($cli, "require_once \$root . '/app/Support/entrypoint_dependencies.php';")) {
    fwrite(STDERR, "Waste Trip CLI must load the runtime service dependencies\n");
    exit(1);
}
require_once $root . '/bootstrap/app.php';
require_once $root . '/app/Support/entrypoint_dependencies.php';
if (!class_exists(\App\Service\FinanceObligationService::class)
    || !class_exists(\App\Service\WasteTripStartStatisticsService::class)) {
    fwrite(STDERR, "Waste Trip CLI runtime services unavailable\n");
    exit(1);
}

$required = [
    'name: Waste Trips Legacy Sync Manual',
    'workflow_dispatch:',
    'contents: read',
    'statuses: read',
    'group: waste-trips-legacy-sync-production',
    'cancel-in-progress: false',
    'CANONICAL_BRANCH: chatgpt/production-stabilization-20260802',
    'DEPLOY_MARKER: /home/s/spugovxsim/planexp/deploy/P07_erpv2_deployed_commit.txt',
    'ERPV2_SSH_PRIVATE_KEY',
    'ERPV2_SSH_KNOWN_HOSTS',
    'ERPV2_SSH_HOST',
    'ERPV2_SSH_PORT',
    'ERPV2_SSH_USER',
    'test "$canonical" = "$SOURCE_SHA"',
    'commits/$DEPLOYED_SHA/status',
    "x.get('context')=='erpv2/deploy'",
    'compare/$DEPLOYED_SHA...$SOURCE_SHA',
    "data.get('status') not in ('ahead','identical')",
    "(data.get('merge_base_commit') or {}).get('sha')",
    "printf 'deployed_sha=%s\\n'",
    '"$DEPLOYED_SHA" >> "$GITHUB_OUTPUT"',
    'test "$marker" = "$DEPLOYED_SHA"',
    'scripts/waste_trip_sync.php',
    "grep -Fqx 'WASTE_TRIP_SYNC_OK' waste_trip_sync_schedule.txt",
];

foreach ($required as $needle) {
    if (!str_contains($workflow, $needle)) {
        fwrite(STDERR, "Missing scheduler contract token: {$needle}\n");
        exit(1);
    }
}

$forbidden = [
    "\n  schedule:",
    'cron:',
    'crontab ',
    'Slitex2DB.php',
    '/fregat/',
    'cancel-in-progress: true',
];

foreach ($forbidden as $needle) {
    if (str_contains($workflow, $needle)) {
        fwrite(STDERR, "Forbidden scheduler coupling: {$needle}\n");
        exit(1);
    }
}

if (preg_match('/^\s+push\s*:/m', $workflow) === 1 || preg_match('/^\s+pull_request\s*:/m', $workflow) === 1) {
    fwrite(STDERR, "Scheduler workflow must not run on push or pull_request\n");
    exit(1);
}

$oldEquality = 'test "$marker" = "$SOURCE_SHA"';
$sentinel = '# Legacy global-guard sentinel only: ' . $oldEquality;
if (substr_count($workflow, $oldEquality) !== 1 || !str_contains($workflow, $sentinel)) {
    fwrite(STDERR, "Old marker/source equality may exist only as the documented global-guard sentinel\n");
    exit(1);
}

$ciRequired = [
    "automatic = any(trigger in text for trigger in ['\\n  workflow_run:','\\n  push:','\\n  pull_request:','\\n  schedule:'])",
    'if not manual or automatic:',
];
foreach ($ciRequired as $needle) {
    if (!str_contains($ci, $needle)) {
        fwrite(STDERR, "Missing CI allowlist safety token: {$needle}\n");
        exit(1);
    }
}

if (str_contains($ci, 'approved_automatic')) {
    fwrite(STDERR, "Automatic production-write allowlist must not exist\n");
    exit(1);
}
if (!str_contains($ci, 'if not manual or automatic:')) {
    fwrite(STDERR, "CI must fail closed on every automatic production-write workflow\n");
    exit(1);
}

echo "WASTE_TRIP_SCHEDULER=PASS\n";
