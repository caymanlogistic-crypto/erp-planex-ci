<?php
$root = dirname(__DIR__);
$workflowPath = $root . '/.github/workflows/feo_125_production_migration.yml';
$applyPath = $root . '/scripts/feo_apply_125.php';
$verifyPath = $root . '/scripts/feo_verify_125.php';
$migrationPath = $root . '/database/migrations-local/125_create_feo_map_utilizers.sql';
foreach ([$workflowPath,$applyPath,$verifyPath,$migrationPath] as $path) {
    if (!is_file($path) || filesize($path) === 0) { fwrite(STDERR,"missing: $path\n"); exit(1); }
}
$workflow = file_get_contents($workflowPath);
$apply = file_get_contents($applyPath);
$verify = file_get_contents($verifyPath);
$migration = file_get_contents($migrationPath);
$workflowNeedles = [
    "\n  workflow_dispatch:", 'APPLY_FEO_125', 'erpv2/migration125', 'Snapshot before',
    'Apply migration 125 to active tenant databases', 'Snapshot after and verify invariants',
    'FEO_125_INVARIANTS_OK', 'HTTP acceptance', 'Upload migration evidence', 'Cleanup'
];
foreach ($workflowNeedles as $needle) if (strpos($workflow,$needle)===false) { fwrite(STDERR,"workflow missing: $needle\n"); exit(1); }
foreach (["\n  push:","\n  workflow_run:","\n  pull_request:","\n  schedule:"] as $forbidden) if(strpos($workflow,$forbidden)!==false){fwrite(STDERR,"automatic trigger forbidden: $forbidden\n");exit(1);}
$applyNeedles = [
    '125_create_feo_map_utilizers.sql', '122_create_feo_requests.sql', '123_create_feo_map_placement.sql', '124_create_feo_request_costs.sql',
    'Unjournaled FEO map-utilizer schema', 'Newer tenant migration exists', 'GET_LOCK', 'checksum',
    'uk_feo_utilizers_short_name', 'uk_feo_utilizer_rule_canonical', 'fk_feo_utilizer_rule_utilizer',
    'fk_feo_request_utilizer_override_request', 'fk_feo_request_utilizer_override_utilizer', 'FEO_125_OK'
];
foreach ($applyNeedles as $needle) if (strpos($apply,$needle)===false) { fwrite(STDERR,"apply missing: $needle\n"); exit(1); }
$verifyNeedles = ['FEO_125_SNAPSHOT=','FEO_125_READ_ONLY_OK','migration_122','migration_123','migration_124','migration_125','utilizers','utilizer_rules','utilizer_overrides'];
foreach ($verifyNeedles as $needle) if (strpos($verify,$needle)===false) { fwrite(STDERR,"verify missing: $needle\n"); exit(1); }
foreach ([
    'CREATE TABLE IF NOT EXISTS `feo_utilizers`',
    'CREATE TABLE IF NOT EXISTS `feo_utilizer_match_rules`',
    'CREATE TABLE IF NOT EXISTS `feo_request_utilizer_overrides`',
    'uk_feo_utilizers_short_name', 'uk_feo_utilizer_rule_canonical',
    'ON DELETE CASCADE', 'ON DELETE RESTRICT'
] as $needle) if (strpos($migration,$needle)===false) { fwrite(STDERR,"migration missing: $needle\n"); exit(1); }
echo "FEO_125_PRODUCTION_MIGRATION_CONTRACT=PASS\n";
