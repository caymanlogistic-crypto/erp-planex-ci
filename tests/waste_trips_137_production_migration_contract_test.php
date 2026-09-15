<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$read=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);
$ok=static function(bool $value,string $message):void{if(!$value)throw new RuntimeException($message);};
$workflow=$read('.github/workflows/waste_trips_137_production_migration.yml');
$apply=$read('scripts/waste_trips_apply_137.php');$verify=$read('scripts/waste_trips_verify_137.php');
$sql=$read('database/migrations-local/137_waste_trip_synchronization.sql');
$manifest=json_decode($read('.github/agent_db_migration_request.json'),true,512,JSON_THROW_ON_ERROR);
$ok($manifest['migration_id']==='137'&&$manifest['workflow']==='waste_trips_137_production_migration.yml'&&$manifest['confirmation_value']==='APPLY_WASTE_TRIPS_137','Exact migration request target');
foreach(['workflow_dispatch:','APPLY_WASTE_TRIPS_137','Require successful exact canonical ERP PLANEX CI','Require deployed marker as successful canonical ancestor','group: erpv2-controlled-deploy','Snapshot BEFORE','Snapshot AFTER and verify invariants',"row['data']!=b[cid]['data']",'WASTE_137_BUSINESS_DATA_MUTATION=0','WASTE_137_DEPLOY_MARKER_UNCHANGED=YES'] as $needle)$ok(str_contains($workflow,$needle),'Missing migration gate: '.$needle);
foreach(["\n  push:","\n  schedule:","\n  pull_request:",'rsync','crontab','git push']as$needle)$ok(!str_contains($workflow,$needle),'Forbidden migration behavior: '.$needle);
foreach(['136_create_max_event_notifications.sql','GET_LOCK','planex_migrate_','RELEASE_LOCK','Unjournaled Waste Trips 137 schema','hash_equals','schema_migrations','idx_waste_trips_deleted','planned_route']as$needle)$ok(str_contains($apply,$needle),'Apply guard: '.$needle);
foreach(['SET SESSION TRANSACTION READ ONLY','hash_init','hash_final','finance_operation_allocations','waste_trip_requests','WASTE_137_READ_ONLY_OK']as$needle)$ok(str_contains($verify,$needle),'Read-only snapshot: '.$needle);
$ok(str_contains($sql,'ALTER TABLE `waste_trips`')&&str_contains($sql,"'planned_route'")&&str_contains($sql,'`deleted_at` DATETIME NULL'),'Required schema');
foreach(['DELETE ','UPDATE ','INSERT ','DROP ','TRUNCATE ']as$needle)$ok(!str_contains(strtoupper($sql),$needle),'Migration changes existing data');
echo "WASTE_TRIPS_137_PRODUCTION_MIGRATION_CONTRACT=PASS\n";
