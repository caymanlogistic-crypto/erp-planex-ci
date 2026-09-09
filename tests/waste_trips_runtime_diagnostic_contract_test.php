<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$workflow=(string)file_get_contents($root.'/.github/workflows/waste_trips_runtime_diagnostic.yml');
$controller=(string)file_get_contents($root.'/app/Http/Controllers/Company/WasteTripController.php');
$service=(string)file_get_contents($root.'/app/Service/WasteTripService.php');
$fail=static function(string $message):never{fwrite(STDERR,"FAIL: {$message}\n");exit(1);};
$ok=static function(bool $value,string $message)use($fail):void{if(!$value)$fail($message);};

$ok(str_contains($workflow,'qa/waste-trips-runtime-diagnostic-agent'),'diagnostic must be isolated to its dedicated QA trigger branch');
$ok(str_contains($workflow,'test "$(git rev-parse HEAD)" = "$(git rev-parse FETCH_HEAD)"'),'diagnostic must require exact canonical HEAD');
$ok(str_contains($workflow,'test "$marker" = "$SHA"'),'diagnostic must require exact deployed marker');
$ok(str_contains($workflow,'waste_trip_write_failure|waste trip database error:|waste unexpected:'),'diagnostic must read only Waste Trips error markers');
$ok(str_contains($workflow,'waste_trips_verify_130.php'),'diagnostic must include the existing read-only Waste Trips schema snapshot');
$ok(str_contains($workflow,'-mmin -1440'),'diagnostic log scan must be time-bounded');
foreach(['mysql -','--apply','curl -X POST','gh api --method POST','scp -P'] as $forbidden){$ok(!str_contains($workflow,$forbidden),'diagnostic must not contain production-write transport: '.$forbidden);}

foreach(['tenant_context','schema','request_parse','manager_resolution','legacy_connection','legacy_write'] as $stage){$ok(str_contains($controller,"\$stage='".$stage."'")||str_contains($controller,"'stage'=>\$stage"),'controller diagnostic stage missing: '.$stage);}
$ok(str_contains($controller,"' Код ошибки: '.\$incident.'.'"),'controller must return a safe incident id instead of PDO detail');
$ok(str_contains($controller,'waste_trip_write_failure incident='),'controller must emit structured internal failure evidence');

foreach(['access_scope','input_validation','entity_visibility','transaction_begin','duplicate_lock','request_resolution','claim_check','trip_insert','request_links','audit','commit'] as $stage){$ok(str_contains($service,"\$stage='".$stage."'"),'service diagnostic stage missing: '.$stage);}
$ok(str_contains($service,'legacyWriteFailure($legacyId,$stage,$e)'),'legacy writer must preserve exact failing stage');
$ok(str_contains($service,'waste_trip_write_failure incident='),'legacy writer must log structured incident evidence');
$ok(str_contains($service,"new RuntimeException('Создание рейса не выполнено. Код ошибки: '.\$incident.'.',0,\$e)"),'legacy writer must expose only the incident id while retaining the original exception as cause');

echo "WASTE_TRIPS_RUNTIME_DIAGNOSTIC_CONTRACT=PASS\n";
