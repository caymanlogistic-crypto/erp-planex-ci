<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$workflow=file_get_contents($root.'/.github/workflows/feo_transfers_139_production_migration.yml');
$script=file_get_contents($root.'/scripts/feo_transfers_migration_139.php');
$sql=file_get_contents($root.'/database/migrations-local/139_create_feo_transfers.sql');
foreach(['workflow_dispatch:','APPLY_FEO_TRANSFERS_139','group: erpv2-controlled-deploy','Validate exact canonical source and tooling','Require successful exact canonical ERP PLANEX CI','git merge-base --is-ancestor','FEO_139_BEFORE_PASS','FEO_139_INVARIANTS_PASS','FEO_139_DEPLOY_MARKER_UNCHANGED','erpv2/migration139','FEO_TRANSFERS_MIGRATION_MODE=apply','FEO_TRANSFERS_MIGRATION_CONFIRM=APPLY_FEO_TRANSFERS_139','feo_sha256'] as $v) if(!str_contains($workflow,$v))throw new RuntimeException('Missing workflow guard: '.$v);
foreach(["\n  push:","\n  schedule:","\n  workflow_run:",'rsync','git push'] as $v)if(str_contains($workflow,$v))throw new RuntimeException('Forbidden migration trigger/action');
foreach(['FEO_139_READ_ONLY_OK','FEO_139_APPLY_OK','GET_LOCK','Unjournaled FEO transfers schema','checksum mismatch','Newer migration exists','138_waste_trip_general_payments.sql','Migration statement outside allowed scope'] as $v)if(!str_contains($script,$v))throw new RuntimeException('Missing script guard: '.$v);
if(substr_count($sql,'CREATE TABLE IF NOT EXISTS')!==5 || preg_match('/(?:^|;)\s*(INSERT|UPDATE|DELETE|DROP|ALTER)\s/i',$sql))throw new RuntimeException('Migration must only create five new tables');
echo "FEO_TRANSFERS_139_PRODUCTION_CONTRACT=PASS\n";
