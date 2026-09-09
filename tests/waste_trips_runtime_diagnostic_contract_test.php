<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$workflow=(string)file_get_contents($root.'/.github/workflows/waste_trips_runtime_diagnostic.yml');
$controller=(string)file_get_contents($root.'/app/Http/Controllers/Company/WasteTripController.php');
$service=(string)file_get_contents($root.'/app/Service/WasteTripService.php');
$incident=(string)file_get_contents($root.'/app/Service/WasteTripIncidentService.php');
$fail=static function(string$message):never{fwrite(STDERR,"FAIL: {$message}\n");exit(1);};
$ok=static function(bool$value,string$message)use($fail):void{if(!$value)$fail($message);};

$ok(str_contains($workflow,'qa/waste-trips-runtime-diagnostic-agent'),'diagnostic keeps dedicated QA trigger');
$ok(str_contains($workflow,'workflow_dispatch:'),'diagnostic supports controlled manual read');
$ok(str_contains($workflow,'TASK_DIAGNOSTIC_BASE_SHA'),'task diagnostic must pin the exact deployed base');
$ok(str_contains($workflow,'git fetch origin "$GITHUB_REF_NAME" --depth=50'),'task diagnostic must fetch candidate ancestry before merge-base validation');
$ok(str_contains($workflow,'git merge-base --is-ancestor "$TASK_DIAGNOSTIC_BASE_SHA" "$head"'),'task diagnostic candidate must descend from the pinned base');
$ok(str_contains($workflow,'test "$marker" = "$EXPECTED_DEPLOYED_SHA"'),'diagnostic requires exact deployed marker');
$ok(str_contains($workflow,'logs/waste_trip_incidents.jsonl'),'diagnostic reads durable Waste Trips incident file');
$ok(str_contains($workflow,'tail -n 250'),'durable read is bounded');
$ok(str_contains($workflow,"^WT-[0-9]{6}-[0-9]{6}-[A-F0-9]{6}$"),'exact incident input is validated');
$ok(!str_contains($workflow,'-iname \'error_log\''),'diagnostic no longer guesses PHP/Apache error logs');
$ok(str_contains($workflow,'waste_trips_verify_130.php'),'diagnostic includes read-only schema snapshot');
$ok(str_contains($workflow,'scripts/waste_trip_legacy_readonly_probe.php'),'diagnostic must run the exact Legacy read-only probe');
$ok(str_contains($workflow,"PLANEX_WASTE_TRIP_LEGACY_ID='246'"),'task diagnostic must target Legacy 246');
foreach(['mysql -','--apply','curl -X POST','gh api --method POST']as$forbidden)$ok(!str_contains($workflow,$forbidden),'diagnostic must not contain production-write transport: '.$forbidden);

foreach(['tenant_context','schema','request_parse','manager_resolution','legacy_connection','legacy_write']as$stage)$ok(str_contains($controller,"\$stage='".$stage."'")||str_contains($controller,"'stage'=>\$stage"),'controller diagnostic stage missing: '.$stage);
$ok(str_contains($controller,"' Код ошибки: '.\$incident.'.'"),'controller exposes safe incident id only');
$ok(str_contains($controller,'WasteTripIncidentService::record'),'controller uses durable incident writer');
foreach(['access_scope','input_validation','entity_visibility','transaction_begin','duplicate_lock','request_resolution','claim_check','trip_insert','request_links','audit','commit']as$stage)$ok(str_contains($service,"\$stage='".$stage."'"),'service stage missing: '.$stage);
$ok(str_contains($service,'legacyWriteFailure($legacyId,$stage,$e,$sessionUser)'),'legacy writer preserves exact stage and actor context');
$ok(str_contains($service,'WasteTripIncidentService::record'),'legacy writer uses durable incident writer');
$ok(str_contains($service,"new RuntimeException('Создание рейса не выполнено. Код ошибки: '.\$incident.'.',0,\$e)"),'legacy writer exposes only incident id and retains cause');
foreach(['incident_id','timestamp_utc','operation','legacy_id','stage','exception_class','sqlstate','driver_code','message','deployed_sha']as$field)$ok(str_contains($incident,"'".$field."'"),'durable field missing: '.$field);
$ok(str_contains($incident,"DEFAULT_RELATIVE_LOG = 'logs/waste_trip_incidents.jsonl'"),'durable incident path contract');
$ok(str_contains($incident,'FILE_APPEND | LOCK_EX'),'durable appends must be locked');
$ok(str_contains($incident,"parse_url((string) (\$context['request_path']"),'request query string is not persisted');
$ok(str_contains($incident,"password|passwd|pwd|token|secret|cookie|csrf|authorization"),'sensitive marker redaction contract');

echo "WASTE_TRIPS_RUNTIME_DIAGNOSTIC_CONTRACT=PASS\n";
