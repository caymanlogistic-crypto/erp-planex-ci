<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$workflow=file_get_contents($root.'/.github/workflows/manager_statistics_141_production_migration.yml');
$script=file_get_contents($root.'/scripts/manager_statistics_migration_141.php');
$sql=file_get_contents($root.'/database/migrations-local/141_create_waste_trip_start_statistics.sql');
foreach(['workflow_dispatch:','APPLY_MANAGER_STATISTICS_141','group: erpv2-controlled-deploy','Validate exact canonical source and tooling','Require successful exact canonical ERP PLANEX CI','git merge-base --is-ancestor','MANAGER_141_BEFORE_PASS','MANAGER_141_INVARIANTS_PASS','MANAGER_141_DEPLOY_MARKER_UNCHANGED','erpv2/migration141','MANAGER_STATISTICS_MIGRATION_MODE=apply','MANAGER_STATISTICS_MIGRATION_CONFIRM=APPLY_MANAGER_STATISTICS_141','trip_sha256','request_sha256']as$value)if(!str_contains($workflow,$value))throw new RuntimeException('Missing workflow guard: '.$value);
foreach(["\n  push:","\n  schedule:","\n  workflow_run:",'rsync','git push']as$value)if(str_contains($workflow,$value))throw new RuntimeException('Forbidden migration trigger/action');
foreach(['MANAGER_141_READ_ONLY_OK','MANAGER_141_APPLY_OK','GET_LOCK','Unjournaled manager statistics schema','checksum mismatch','Newer migration exists','140_create_trip_document_collection.sql','Migration backfill outside allowed scope','changed source business data']as$value)if(!str_contains($script,$value))throw new RuntimeException('Missing script guard: '.$value);
if(substr_count($sql,'CREATE TABLE IF NOT EXISTS')!==1||substr_count($sql,'INSERT INTO `waste_trip_start_statistics`')!==1||preg_match('/(?:^|;)\s*(UPDATE|DELETE|DROP|ALTER)\s/i',$sql))throw new RuntimeException('Migration scope changed');
echo "MANAGER_STATISTICS_141_PRODUCTION_CONTRACT=PASS\n";
