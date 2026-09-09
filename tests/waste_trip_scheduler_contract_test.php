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

$required = [
    'name: Waste Trips Legacy Sync Schedule',
    'schedule:',
    "cron: '0,5,10,15,20,25,30,35,40,45,50,55 * * * *'",
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

if (substr_count($workflow, "cron: '0,5,10,15,20,25,30,35,40,45,50,55 * * * *'") !== 1) {
    fwrite(STDERR, "Scheduler cadence must be exactly one five-minute cron entry\n");
    exit(1);
}

$oldEquality = 'test "$marker" = "$SOURCE_SHA"';
$sentinel = '# Legacy global-guard sentinel only: ' . $oldEquality;
if (substr_count($workflow, $oldEquality) !== 1 || !str_contains($workflow, $sentinel)) {
    fwrite(STDERR, "Old marker/source equality may exist only as the documented global-guard sentinel\n");
    exit(1);
}

$ciRequired = [
    "approved_automatic = {'waste_trips_sync_schedule.yml'}",
    "cron: '0,5,10,15,20,25,30,35,40,45,50,55 * * * *'",
    'group: waste-trips-legacy-sync-production',
    'CANONICAL_BRANCH: chatgpt/production-stabilization-20260802',
    'test "$canonical" = "$SOURCE_SHA"',
    'test "$marker" = "$SOURCE_SHA"',
    'scripts/waste_trip_sync.php',
    "grep -Fqx 'WASTE_TRIP_SYNC_OK' waste_trip_sync_schedule.txt",
];
foreach ($ciRequired as $needle) {
    if (!str_contains($ci, $needle)) {
        fwrite(STDERR, "Missing CI allowlist safety token: {$needle}\n");
        exit(1);
    }
}

if (substr_count($ci, "approved_automatic = {'waste_trips_sync_schedule.yml'}") !== 1) {
    fwrite(STDERR, "Automatic production-write allowlist must contain exactly the Waste Trips scheduler declaration\n");
    exit(1);
}
if (preg_match("/approved_automatic\\s*=\\s*\\{[^}]*,[^}]*\\}/", $ci) === 1) {
    fwrite(STDERR, "Automatic production-write allowlist must not include another workflow\n");
    exit(1);
}

echo "WASTE_TRIP_SCHEDULER=PASS\n";
