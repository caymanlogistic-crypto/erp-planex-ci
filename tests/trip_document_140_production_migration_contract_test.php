<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$workflow=file_get_contents($root.'/.github/workflows/trip_document_140_production_migration.yml');
$script=file_get_contents($root.'/scripts/trip_document_migration_140.php');
$sql=file_get_contents($root.'/database/migrations-local/140_create_trip_document_collection.sql');
foreach(['workflow_dispatch:','APPLY_DOCUMENT_COLLECTION_140','group: erpv2-controlled-deploy','Validate exact canonical source and tooling','Require successful exact canonical ERP PLANEX CI','git merge-base --is-ancestor','DOCUMENT_140_BEFORE_PASS','DOCUMENT_140_INVARIANTS_PASS','DOCUMENT_140_DEPLOY_MARKER_UNCHANGED','erpv2/migration140','DOCUMENT_COLLECTION_MIGRATION_MODE=apply','DOCUMENT_COLLECTION_MIGRATION_CONFIRM=APPLY_DOCUMENT_COLLECTION_140','trip_sha256'] as $v) if(!str_contains($workflow,$v))throw new RuntimeException('Missing workflow guard: '.$v);
foreach(["\n  push:","\n  schedule:","\n  workflow_run:",'rsync','git push'] as $v)if(str_contains($workflow,$v))throw new RuntimeException('Forbidden migration trigger/action');
foreach(['DOCUMENT_140_READ_ONLY_OK','DOCUMENT_140_APPLY_OK','GET_LOCK','Unjournaled document collection schema','checksum mismatch','Newer migration exists','139_create_feo_transfers.sql','Migration statement outside allowed scope'] as $v)if(!str_contains($script,$v))throw new RuntimeException('Missing script guard: '.$v);
if(substr_count($sql,'CREATE TABLE IF NOT EXISTS')!==6 || preg_match('/(?:^|;)\s*(INSERT|UPDATE|DELETE|DROP|ALTER)\s/i',$sql))throw new RuntimeException('Migration must only create six new tables');
foreach(['DOCUMENT_SCHEDULER_TICK=PASS','scripts/trip_document_worker.php --company=25','crontab -l | grep -Fqx','test "$DEPLOYED" = "$SOURCE_SHA"','APP_PUBLIC_URL=https://plan-ex.ru/erpv2'] as $guard)if(!str_contains($workflow,$guard))throw new RuntimeException('Missing scheduler guard: '.$guard);
echo "DOCUMENT_COLLECTION_140_PRODUCTION_CONTRACT=PASS\n";
