<?php

declare(strict_types=1);

$root=dirname(__DIR__);
$workflow=(string)file_get_contents($root.'/.github/workflows/waste_trips_135_production_migration.yml');
$apply=(string)file_get_contents($root.'/scripts/waste_trips_apply_135.php');
$verify=(string)file_get_contents($root.'/scripts/waste_trips_verify_135.php');
$sql=(string)file_get_contents($root.'/database/migrations-local/135_create_waste_trip_carrier_payments.sql');
$manifest=json_decode((string)file_get_contents($root.'/.github/agent_db_migration_request.json'),true);
$ok=static function(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);};

$ok(is_array($manifest),'migration request manifest invalid');
$ok(str_contains($workflow,"\n  workflow_dispatch:"),'Waste Trips 135 migration must be workflow_dispatch');
foreach(["\n  push:","\n  workflow_run:","\n  pull_request:","\n  schedule:"]as$f)$ok(!str_contains($workflow,$f),'Waste Trips 135 migration has forbidden trigger '.$f);
foreach(['APPLY_WASTE_TRIPS_135','Validate exact canonical source and tooling','Require successful exact canonical ERP PLANEX CI','Require deployed marker as successful canonical ancestor','WASTE_135_DEPLOYED_ANCESTOR_PASS','Snapshot BEFORE','Apply tenant migration 135','Snapshot AFTER and verify invariants','WASTE_135_INVARIANTS_PASS=YES','WASTE_135_BUSINESS_DATA_MUTATION=0','WASTE_135_DEPLOY_MARKER_UNCHANGED=YES','erpv2/migration135','waste_trips_apply_135.php','waste_trips_verify_135.php','135_create_waste_trip_carrier_payments.sql']as$t)$ok(str_contains($workflow,$t),'Waste Trips 135 workflow missing '.$t);
$ok(str_contains($workflow,'group: erpv2-controlled-deploy'),'Waste Trips 135 migration must serialize with controlled deploy');
$ok(!str_contains($workflow,'git push')&&!str_contains($workflow,'rsync'),'Waste Trips 135 migration must not deploy application code');
$ok(!str_contains($workflow,'crontab')&&!str_contains($workflow,'schedule:'),'Waste Trips 135 migration must not change scheduler authority');
foreach(['135_create_waste_trip_carrier_payments.sql','134_create_waste_trip_customer_payments.sql','GET_LOCK','schema_migrations','Unjournaled Waste Trips 135 schema','waste_trip_carrier_payments','waste_trips','idx_waste_trip_carrier_payments_trip','fk_waste_trip_carrier_payments_trip','WASTE_135_OK']as$t)$ok(str_contains($apply,$t),'Waste Trips 135 apply missing '.$t);
foreach(['WASTE_135_SNAPSHOT=','WASTE_135_READ_ONLY_OK','migration_134','migration_135','migration_135_checksum','waste_trips_rows','customer_payment_rows','amount_column','condition_type_column','trip_index','trip_fk','trip_fk_delete_rule']as$t)$ok(str_contains($verify,$t),'Waste Trips 135 verifier missing '.$t);
foreach(['CREATE TABLE IF NOT EXISTS `waste_trip_carrier_payments`','`amount` DECIMAL(12,2) NOT NULL','`condition_type` VARCHAR(50) NOT NULL','idx_waste_trip_carrier_payments_trip','fk_waste_trip_carrier_payments_trip','ON DELETE RESTRICT']as$t)$ok(str_contains($sql,$t),'Waste Trips 135 SQL missing '.$t);

echo "WASTE_TRIPS_135_PRODUCTION_MIGRATION_CONTRACT=PASS\n";
