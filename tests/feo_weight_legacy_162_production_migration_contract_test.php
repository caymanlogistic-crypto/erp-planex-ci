<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$workflow=(string)file_get_contents($root.'/.github/workflows/feo_weight_legacy_162_production_migration.yml');
$script=(string)file_get_contents($root.'/scripts/feo_weight_legacy_snapshot_migration_162.php');
$sql=(string)file_get_contents($root.'/database/migrations-local/162_feo_weight_legacy_snapshot.sql');
foreach (['workflow_dispatch:','APPLY_FEO_WEIGHT_LEGACY_162','group: erpv2-controlled-deploy','Exact canonical ERP PLANEX CI','git merge-base --is-ancestor','FEO_WEIGHT_LEGACY_162_READ_ONLY_OK','FEO_WEIGHT_LEGACY_162_APPLY_OK','FEO_WEIGHT_LEGACY_162_BUSINESS_DATA_UNCHANGED=YES'] as $required) {
    if (!str_contains($workflow,$required)) throw new RuntimeException('Missing migration workflow guard: '.$required);
}
foreach (["\n  push:","\n  schedule:","\n  workflow_run:",'git push'] as $forbidden) {
    if (str_contains($workflow,$forbidden)) throw new RuntimeException('Forbidden migration workflow action');
}
foreach (['PLANEX_DEPLOYED_BEFORE','PLANEX_TENANT_SHA256','160_create_feo_weight_batches.sql','Unjournaled or incomplete FEO weight snapshot schema','planex_feo_','Newer migration exists'] as $required) {
    if (!str_contains($script,$required)) throw new RuntimeException('Missing migration script guard: '.$required);
}
if (!preg_match('/^ALTER TABLE feo_weight_items\s+ADD COLUMN legacy_snapshot_json JSON NULL AFTER after_brutto;\s*$/D',trim($sql))) {
    throw new RuntimeException('Migration SQL must add only the legacy snapshot column');
}
echo "FEO_WEIGHT_LEGACY_162_PRODUCTION_CONTRACT=PASS\n";
