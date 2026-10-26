<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$workflow=(string)file_get_contents($root.'/.github/workflows/feo_weight_deleted_163_production_migration.yml');
$script=(string)file_get_contents($root.'/scripts/feo_weight_deleted_status_migration_163.php');
$sql=(string)file_get_contents($root.'/database/migrations-local/163_feo_weight_deleted_status.sql');
foreach (['workflow_dispatch:','APPLY_FEO_WEIGHT_DELETED_163','group: erpv2-controlled-deploy','Exact canonical ERP PLANEX CI','git merge-base --is-ancestor','FEO_WEIGHT_DELETED_163_READ_ONLY_OK','FEO_WEIGHT_DELETED_163_APPLY_OK','FEO_WEIGHT_DELETED_163_BUSINESS_DATA_UNCHANGED=YES'] as $required) {
    if (!str_contains($workflow,$required)) throw new RuntimeException('Missing migration workflow guard: '.$required);
}
foreach (["\n  push:","\n  schedule:","\n  workflow_run:",'git push'] as $forbidden) {
    if (str_contains($workflow,$forbidden)) throw new RuntimeException('Forbidden migration workflow action');
}
foreach (['PLANEX_DEPLOYED_BEFORE','PLANEX_TENANT_SHA256','162_feo_weight_legacy_snapshot.sql','Unjournaled or incomplete FEO weight status schema','planex_feo_','Newer migration exists'] as $required) {
    if (!str_contains($script,$required)) throw new RuntimeException('Missing migration script guard: '.$required);
}
if (!preg_match("/^ALTER TABLE feo_weight_batches\\s+MODIFY COLUMN status ENUM\\('applied','reverted','deleted'\\) NOT NULL DEFAULT 'applied';$/D",trim($sql))) {
    throw new RuntimeException('Migration SQL must only extend the batch status enum');
}
echo "FEO_WEIGHT_DELETED_163_PRODUCTION_CONTRACT=PASS\n";
