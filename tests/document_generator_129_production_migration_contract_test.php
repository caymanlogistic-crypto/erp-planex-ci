<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$workflow = file_get_contents($root . '/.github/workflows/document_generator_129_production_migration.yml');
$apply = file_get_contents($root . '/scripts/document_generator_apply_129.php');
$verify = file_get_contents($root . '/scripts/document_generator_verify_129.php');
$migration = file_get_contents($root . '/database/migrations-local/129_document_generator_auto_number_and_bank_inn.sql');
$manifest = file_get_contents($root . '/.github/agent_db_migration_request.json');
foreach (compact('workflow', 'apply', 'verify', 'migration', 'manifest') as $name => $text) {
    if (!is_string($text) || trim($text) === '') {
        throw new RuntimeException("missing {$name}");
    }
}
$must = static function (bool $ok, string $message): void {
    if (!$ok) {
        throw new RuntimeException($message);
    }
};

$must(str_contains($workflow, "workflow_dispatch:"), 'migration workflow must be manual');
foreach (["\n  push:", "\n  pull_request:", "\n  workflow_run:", "\n  schedule:"] as $forbidden) {
    $must(!str_contains($workflow, $forbidden), 'migration workflow must not auto-trigger: ' . trim($forbidden));
}
$must(str_contains($workflow, 'APPLY_DOCUMENT_GENERATOR_129'), 'confirmation token missing');
$must(str_contains($workflow, 'erpv2/migration129'), 'migration status context missing');
$must(str_contains($workflow, 'git ls-remote origin refs/heads/chatgpt/production-stabilization-20260802'), 'canonical SHA guard missing');
$must(str_contains($workflow, 'DOCGEN_129_READ_ONLY_OK'), 'read-only snapshot marker missing from workflow');
$must(str_contains($workflow, 'DOCGEN_129_OK'), 'apply marker missing from workflow');

foreach (['GET_LOCK', '129_document_generator_auto_number_and_bank_inn.sql', '128_create_waste_trips.sql', 'AUTO NUMBER RANGE COLLISION', 'DUPLICATE DOCUMENT NUMBER COLLISION', 'Unjournaled migration 129 schema'] as $needle) {
    $must(str_contains($apply, $needle), 'apply guard missing: ' . $needle);
}
$must(str_contains($verify, 'DOCGEN_129_READ_ONLY_OK'), 'read-only verifier marker missing');
$must(str_contains($verify, 'auto_range_rows'), 'read-only verifier must report P105+ range');
$must(str_contains($verify, 'duplicate_document_numbers'), 'read-only verifier must report duplicate numbers');

$must(str_contains($migration, 'ADD COLUMN `bank_inn` VARCHAR(10)'), 'contractor bank_inn column missing');
$must(str_contains($migration, 'CREATE TABLE IF NOT EXISTS `document_number_sequences`'), 'sequence table missing');
$must(str_contains($migration, "'vehicle_lease_crew_contract','П ',105"), 'sequence seed P105 missing');
$must(str_contains($migration, 'UNIQUE KEY `uk_generated_documents_type_number`'), 'document number unique backstop missing');

$must(!preg_match('/\b(?:UPDATE|DELETE|REPLACE)\b\s+(?:INTO\s+|FROM\s+)?(?:contractors|generated_documents|feo_requests|crews|drivers|clients)\b/i', $apply), 'production migration may not rewrite business rows');
$must(!preg_match('/\bINSERT\s+INTO\s+(?:contractors|generated_documents|feo_requests|crews|drivers|clients)\b/i', $apply), 'production migration may not insert business rows');

$data = json_decode($manifest, true, 512, JSON_THROW_ON_ERROR);
$must(($data['migration_id'] ?? '') === '129', 'manifest migration_id must be 129');
$must(($data['workflow'] ?? '') === 'document_generator_129_production_migration.yml', 'manifest workflow mismatch');
$must(($data['confirmation_value'] ?? '') === 'APPLY_DOCUMENT_GENERATOR_129', 'manifest confirmation mismatch');

echo "DOCUMENT_GENERATOR_129_PRODUCTION_MIGRATION_CONTRACT=PASS\n";
