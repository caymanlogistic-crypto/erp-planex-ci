<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$fail = static function (string $message): never {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
};

$paths = [
    'database/migrations-local/130_waste_trip_legacy_linked_cutover.sql',
    'scripts/waste_trips_apply_130.php',
    'scripts/waste_trips_verify_130.php',
    'scripts/waste_trip_sync.php',
    '.github/workflows/waste_trips_130_production_migration.yml',
];
foreach ($paths as $path) {
    if (!is_file($root . '/' . $path)) {
        $fail('missing ' . $path);
    }
}

$apply = (string) file_get_contents($root . '/scripts/waste_trips_apply_130.php');
foreach ([
    '--preview', '--apply', 'APPLY_WASTE_TRIPS_130', 'legacy_high_water_at_cutover',
    "source_system='legacy_flights'", 'unknown inbound FK', 'waste_trip_audit_log',
    'DROP TABLE waste_trip_legacy_import_audits', 'uk_waste_trip_business_id',
    'Legacy flights MAX(id) changed during cutover', 'import audit exists outside legacy_flights provenance',
] as $needle) {
    if (!str_contains($apply, $needle)) {
        $fail('apply contract missing ' . $needle);
    }
}
if (str_contains($apply, 'DELETE FROM {$child}')) {
    $fail('dynamic destructive table identifier is forbidden');
}

$verify = (string) file_get_contents($root . '/scripts/waste_trips_verify_130.php');
foreach (['business_id_column', 'business_id_nullable', 'namespace_state_table', 'WASTE_130_READ_ONLY_OK'] as $needle) {
    if (!str_contains($verify, $needle)) {
        $fail('verify contract missing ' . $needle);
    }
}

$workflow = (string) file_get_contents($root . '/.github/workflows/waste_trips_130_production_migration.yml');
foreach ([
    'source_sha', 'APPLY_WASTE_TRIPS_130',
    'git ls-remote origin refs/heads/chatgpt/production-stabilization-20260802',
    'ERP PLANEX CI', 'Waste Trips Regression', 'P07_erpv2_deployed_commit.txt',
    '--preview', '--apply', 'WASTE_130_OK', 'WASTE_130_READ_ONLY_OK',
    'erpv2/migration130', 'WASTE_TRIP_SYNC_OK', '*/5 * * * *', '# ERP PLANEX waste_trip_sync',
] as $needle) {
    if (!str_contains($workflow, $needle)) {
        $fail('workflow contract missing ' . $needle);
    }
}
if (str_contains($workflow, "\n  schedule:") || str_contains($workflow, "\n    schedule:")) {
    $fail('production migration workflow itself must be manual only');
}
if (str_contains($workflow, 'agent_db_migration_request.json')) {
    $fail('Waste Trips 130 must not take ownership of the shared migration request manifest');
}

echo "WASTE_TRIPS_130_PRODUCTION_MIGRATION=PASS\n";
