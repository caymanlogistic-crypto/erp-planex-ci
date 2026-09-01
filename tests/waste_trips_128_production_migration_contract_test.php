<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$workflow = file_get_contents($root . '/.github/workflows/waste_trips_128_production_migration.yml');
$apply = file_get_contents($root . '/scripts/waste_trips_apply_128.php');
$verify = file_get_contents($root . '/scripts/waste_trips_verify_128.php');
$migration = file_get_contents($root . '/database/migrations-local/128_create_waste_trips.sql');
$manifest = file_get_contents($root . '/.github/agent_db_migration_request.json');
foreach (compact('workflow','apply','verify','migration','manifest') as $name => $text) {
    if (!is_string($text) || trim($text) === '') { throw new RuntimeException("missing $name"); }
}
$must = static function (bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } };
$must(str_contains($workflow, "workflow_dispatch:"), 'migration workflow must be manual');
foreach (["\n  push:","\n  pull_request:","\n  workflow_run:","\n  schedule:"] as $forbidden) {
    $must(!str_contains($workflow, $forbidden), 'migration workflow must not auto-trigger: ' . trim($forbidden));
}
$must(str_contains($workflow, "APPLY_WASTE_TRIPS_128"), 'confirmation token missing');
$must(str_contains($workflow, "erpv2/migration128"), 'migration status context missing');
$must(str_contains($apply, "GET_LOCK"), 'tenant advisory lock missing');
$must(str_contains($apply, "128_create_waste_trips.sql"), 'migration name missing');
$must(str_contains($apply, "127_create_document_generator.sql"), 'migration 127 prerequisite missing');
$must(str_contains($apply, "Unjournaled Waste Trips schema"), 'unjournaled schema guard missing');
$must(str_contains($verify, "WASTE_128_READ_ONLY_OK"), 'read-only verifier marker missing');
$must(!preg_match('/\b(?:INSERT|UPDATE|DELETE|REPLACE)\b\s+(?:INTO\s+|FROM\s+)?(?:feo_requests|clients|crews|users)\b/i', $apply), 'production migration may not mutate business rows');
$must(substr_count($migration, 'CREATE TABLE IF NOT EXISTS') === 4, 'migration 128 must create exactly four Waste Trips tables');
$data = json_decode($manifest, true, 512, JSON_THROW_ON_ERROR);
$must(($data['migration_id'] ?? '') === '128', 'manifest migration_id must be 128');
$must(($data['workflow'] ?? '') === 'waste_trips_128_production_migration.yml', 'manifest workflow mismatch');
$must(($data['confirmation_value'] ?? '') === 'APPLY_WASTE_TRIPS_128', 'manifest confirmation mismatch');
echo "WASTE_TRIPS_128_PRODUCTION_MIGRATION_CONTRACT=PASS\n";
