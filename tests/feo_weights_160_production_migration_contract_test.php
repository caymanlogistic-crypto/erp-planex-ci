<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$workflow=file_get_contents($root.'/.github/workflows/feo_weights_160_production_migration.yml');
$script=file_get_contents($root.'/scripts/feo_weights_migration_160.php');
$sql=file_get_contents($root.'/database/migrations-local/160_create_feo_weight_batches.sql');
foreach(['workflow_dispatch:','APPLY_FEO_WEIGHTS_160','group: erpv2-controlled-deploy','Validate exact canonical source and tooling','Require successful exact canonical ERP PLANEX CI','git merge-base --is-ancestor','FEO_160_BEFORE_PASS','FEO_160_INVARIANTS_PASS','FEO_160_DEPLOY_MARKER_UNCHANGED','erpv2/migration160','FEO_WEIGHTS_MIGRATION_MODE=apply','FEO_WEIGHTS_MIGRATION_CONFIRM=APPLY_FEO_WEIGHTS_160','feo_sha256'] as $v) if(!str_contains($workflow,$v))throw new RuntimeException('Missing workflow guard: '.$v);
foreach(["\n  push:","\n  schedule:","\n  workflow_run:",'rsync','git push'] as $v)if(str_contains($workflow,$v))throw new RuntimeException('Forbidden migration trigger/action');
foreach(['FEO_160_READ_ONLY_OK','FEO_160_APPLY_OK','GET_LOCK','Unjournaled FEO weights schema','checksum mismatch','Newer migration exists','139_create_feo_transfers.sql','Migration statement outside allowed scope'] as $v)if(!str_contains($script,$v))throw new RuntimeException('Missing script guard: '.$v);
if(substr_count($sql,'CREATE TABLE IF NOT EXISTS')!==2 || preg_match('/(?:^|;)\s*(INSERT|UPDATE|DELETE|DROP|ALTER)\s/i',$sql))throw new RuntimeException('Migration must only create two new tables');
echo "FEO_WEIGHTS_160_PRODUCTION_CONTRACT=PASS\n";
