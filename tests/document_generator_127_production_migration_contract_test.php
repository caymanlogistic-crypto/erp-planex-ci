<?php

$root = dirname(__DIR__);
$workflow = (string) file_get_contents($root . '/.github/workflows/document_generator_127_production_migration.yml');
$apply = (string) file_get_contents($root . '/scripts/document_generator_apply_127.php');
$verify = (string) file_get_contents($root . '/scripts/document_generator_verify_127.php');
$tenantSql = (string) file_get_contents($root . '/database/migrations-local/127_create_document_generator.sql');
$controlSql = (string) file_get_contents($root . '/database/migrations/012_add_company_bank_requisites.sql');

$workflowNeedles = [
    "\n  workflow_dispatch:",
    'APPLY_DOCUMENT_GENERATOR_127',
    'erpv2/migration127',
    'document_generator_apply_127.php',
    'document_generator_verify_127.php',
    '127_create_document_generator.sql',
    '012_add_company_bank_requisites.sql',
    'Snapshot before',
    'Snapshot after and verify invariants',
    'HTTP acceptance',
];
foreach ($workflowNeedles as $needle) {
    if (!str_contains($workflow, $needle)) {
        fwrite(STDERR, "missing workflow token: {$needle}\n");
        exit(1);
    }
}
foreach (["\n  push:", "\n  workflow_run:", "\n  pull_request:", "\n  schedule:"] as $forbidden) {
    if (str_contains($workflow, $forbidden)) {
        fwrite(STDERR, "forbidden automatic trigger: {$forbidden}\n");
        exit(1);
    }
}

foreach ([
    '127_create_document_generator.sql',
    '126_create_feo_utilizer_aliases.sql',
    'ensureCompanyBankColumns',
    'schema_migrations',
    'GET_LOCK',
    "CAST(LEFT(migration,3) AS UNSIGNED) > 127",
    'document_templates',
    'generated_documents',
    'fk_document_templates_active_version',
] as $needle) {
    if (!str_contains($apply, $needle)) {
        fwrite(STDERR, "missing apply token: {$needle}\n");
        exit(1);
    }
}
foreach (['DOCGEN_127_SNAPSHOT=', 'migration_126', 'migration_127', 'bank_account', 'generated_document_requests_table'] as $needle) {
    if (!str_contains($verify, $needle)) {
        fwrite(STDERR, "missing verify token: {$needle}\n");
        exit(1);
    }
}
foreach (['document_templates', 'document_template_versions', 'generated_documents', 'generated_document_requests'] as $needle) {
    if (!str_contains($tenantSql, $needle)) {
        fwrite(STDERR, "tenant migration missing table: {$needle}\n");
        exit(1);
    }
}
foreach (['bank_account', 'bank_name', 'bank_bik', 'bank_corr_account'] as $needle) {
    if (!str_contains($controlSql, $needle)) {
        fwrite(STDERR, "control migration missing column: {$needle}\n");
        exit(1);
    }
}

echo "DOCUMENT_GENERATOR_127_PRODUCTION_MIGRATION_CONTRACT=PASS\n";
