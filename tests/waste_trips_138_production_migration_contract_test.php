<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$workflow=file_get_contents($root.'/.github/workflows/waste_trips_138_production_migration.yml');
$apply=file_get_contents($root.'/scripts/waste_trips_apply_138.php');
$verify=file_get_contents($root.'/scripts/waste_trips_verify_138.php');
foreach(['workflow_dispatch:','APPLY_WASTE_TRIPS_138_AND_TRANSFER_AMOUNTS','Require successful exact canonical ERP PLANEX CI','group: erpv2-controlled-deploy','Snapshot BEFORE','Snapshot AFTER and verify invariants','python3 scripts/waste_trips_compare_138.py waste_138_before.txt waste_138_after.txt','WASTE_138_DEPLOY_MARKER_UNCHANGED=YES'] as $token)if(!str_contains($workflow,$token))throw new RuntimeException('Missing gate '.$token);
foreach(["\n  push:","\n  schedule:","\n  pull_request:",'rsync','crontab'] as $token)if(str_contains($workflow,$token))throw new RuntimeException('Forbidden migration trigger/action');
foreach(['PLANEX_CONFIRM_WASTE_138','GET_LOCK','RELEASE_LOCK','Tenant identity mismatch','hash_equals','Unjournaled Waste Trips 138 schema','WasteTripFinancialTransitionService::transition'] as $token)if(!str_contains($apply,$token))throw new RuntimeException('Missing apply guard '.$token);
foreach(['SET SESSION TRANSACTION READ ONLY','finance_operation_allocations','finance_invoice_links','hash_final','WASTE_138_READ_ONLY_OK'] as $token)if(!str_contains($verify,$token))throw new RuntimeException('Missing read-only proof '.$token);
$regression=file_get_contents($root.'/.github/workflows/waste_trips_regression.yml');
foreach([137,138] as $migration) {
    $pair="php tests/waste_trips_{$migration}_migration_mysql_test.php | tee /tmp/m{$migration}-mysql.out\n          grep -Fqx 'WASTE_TRIPS_{$migration}_MIGRATION_MYSQL=PASS' /tmp/m{$migration}-mysql.out";
    if(!str_contains(str_replace("\r\n","\n",$regression),$pair))throw new RuntimeException('Migration CI output/marker mismatch '.$migration);
}
echo "WASTE_TRIPS_138_PRODUCTION_MIGRATION_CONTRACT=PASS\n";
