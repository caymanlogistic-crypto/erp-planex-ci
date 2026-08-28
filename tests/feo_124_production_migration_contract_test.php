<?php
$root = dirname(__DIR__);
$workflowPath = $root . '/.github/workflows/feo_124_production_migration.yml';
$applyPath = $root . '/scripts/feo_apply_124.php';
$verifyPath = $root . '/scripts/feo_verify_124.php';
$migrationPath = $root . '/database/migrations-local/124_create_feo_request_costs.sql';
foreach ([$workflowPath,$applyPath,$verifyPath,$migrationPath] as $path) {
    if (!is_file($path) || filesize($path) === 0) { fwrite(STDERR,"missing: $path\n"); exit(1); }
}
$workflow = file_get_contents($workflowPath);
$apply = file_get_contents($applyPath);
$verify = file_get_contents($verifyPath);
$migration = file_get_contents($migrationPath);
$workflowNeedles = [
    "\n  workflow_dispatch:", 'APPLY_FEO_124', 'erpv2/migration124', 'Snapshot before',
    'Apply migration 124 to active tenant databases', 'Snapshot after and verify invariants',
    'FEO_124_INVARIANTS_OK', 'HTTP acceptance', 'Upload migration evidence', 'Cleanup'
];
foreach ($workflowNeedles as $needle) if (strpos($workflow,$needle)===false) { fwrite(STDERR,"workflow missing: $needle\n"); exit(1); }
foreach (["\n  push:","\n  workflow_run:","\n  pull_request:","\n  schedule:"] as $forbidden) if(strpos($workflow,$forbidden)!==false){fwrite(STDERR,"automatic trigger forbidden: $forbidden\n");exit(1);}
$applyNeedles = [
    "124_create_feo_request_costs.sql", "122_create_feo_requests.sql", "123_create_feo_map_placement.sql",
    "Unjournaled FEO request-cost schema", "Newer tenant migration exists", "GET_LOCK", "checksum",
    "uk_feo_request_cost_one_price_per_request", "fk_feo_request_cost_block_requests_block", "INDEX_NAME=?", "FEO_124_OK"
];
foreach ($applyNeedles as $needle) if (strpos($apply,$needle)===false) { fwrite(STDERR,"apply missing: $needle\n"); exit(1); }
if (strpos($apply,'INDEQ_NAME')!==false) { fwrite(STDERR,"apply contains invalid information_schema column INDEQ_NAME\n"); exit(1); }
$verifyNeedles = ['FEO_124_SNAPSHOT=','FEO_124_READ_ONLY_OK','migration_122','migration_123','migration_124','cost_blocks','cost_members'];
foreach ($verifyNeedles as $needle) if (strpos($verify,$needle)===false) { fwrite(STDERR,"verify missing: $needle\n"); exit(1); }
foreach (['CREATE TABLE IF NOT EXISTS `feo_request_cost_blocks`','CREATE TABLE IF NOT EXISTS `feo_request_cost_block_requests`','uk_feo_request_cost_one_price_per_request','ON DELETE CASCADE'] as $needle) if (strpos($migration,$needle)===false) { fwrite(STDERR,"migration missing: $needle\n"); exit(1); }
echo "FEO_124_PRODUCTION_MIGRATION_CONTRACT=PASS\n";
