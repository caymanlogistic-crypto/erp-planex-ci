<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$workflowPath = $root . '/.github/workflows/waste_trips_sync_schedule.yml';

if (!is_file($workflowPath)) {
    fwrite(STDERR, "Scheduler workflow missing\n");
    exit(1);
}

$workflow = (string) file_get_contents($workflowPath);

$required = [
    'name: Waste Trips Legacy Sync Schedule',
    'schedule:',
    "cron: '*/5 * * * *'",
    'workflow_dispatch:',
    'contents: read',
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
    'test "$marker" = "$SOURCE_SHA"',
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

if (substr_count($workflow, "cron: '*/5 * * * *'") !== 1) {
    fwrite(STDERR, "Scheduler cadence must be exactly one five-minute cron entry\n");
    exit(1);
}

echo "WASTE_TRIP_SCHEDULER=PASS\n";
