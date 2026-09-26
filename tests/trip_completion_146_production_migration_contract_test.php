<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$script = (string)file_get_contents($root.'/scripts/trip_completion_migration_146.php');
$workflow = (string)file_get_contents($root.'/.github/workflows/trip_completion_146_production_migration.yml');
$sql = trim((string)file_get_contents($root.'/database/migrations-local/146_remap_trip_completion_invented_source.sql'));
$ok = static function(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$ok(substr_count($sql, ';') === 1 && str_contains($sql, "WHERE recovery_source = 'invented' OR data_invented = 1"), 'Migration must touch only former invented selections');
$ok(str_contains($sql, "recovery_source = 'driver'") && str_contains($sql, 'data_from_driver = 1') && str_contains($sql, 'data_invented = 0'), 'Migration must convert all recovery flags');
foreach (['PHP_SAPI', 'TRIP_COMPLETION_MIGRATION_CONFIRM', 'APPLY_TRIP_COMPLETION_146', 'GET_LOCK', 'beginTransaction', 'FOR UPDATE', 'Unexpected completion data change', 'trip_sha256', 'request_sha256', 'document_sha256', 'invented_remaining'] as $token) $ok(str_contains($script, $token), 'Guard missing: '.$token);
$ok(!str_contains($workflow, "\n  push:") && !str_contains($workflow, "\n  schedule:") && str_contains($workflow, "\n  workflow_dispatch:"), 'Migration workflow must be manual only');
foreach (['source_sha', 'APPLY_TRIP_COMPLETION_146', 'CANONICAL_BRANCH', 'Require successful exact canonical ERP PLANEX CI', 'Snapshot BEFORE', 'Snapshot AFTER and verify invariants', 'TRIP_COMPLETION_146_INVARIANTS_PASS', 'DEPLOY_MARKER_UNCHANGED', 'Upload migration evidence'] as $token) $ok(str_contains($workflow, $token), 'Workflow guard missing: '.$token);
$ok(str_contains($workflow, '146_remap_trip_completion_invented_source.sql') && str_contains($workflow, 'trip_completion_migration_146.php'), 'Workflow must use exact migration artifacts');
echo "TRIP_COMPLETION_146_PRODUCTION_CONTRACT=PASS\n";
