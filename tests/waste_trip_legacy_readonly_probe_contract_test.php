<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$probe=(string)file_get_contents($root.'/scripts/waste_trip_legacy_readonly_probe.php');
$fail=static function(string $m):never{fwrite(STDERR,"FAIL: {$m}\n");exit(1);};
$ok=static function(bool $v,string $m)use($fail):void{if(!$v)$fail($m);};
foreach(['PLANEX_WASTE_TRIP_LEGACY_ID','PLANEX_WASTE_TRIP_COMPANY_ID','SET SESSION TRANSACTION READ ONLY','manager_projection','current_resolver_failure','unique_login_row_hidden_by_deleted_filter','legacy_encoding','prefill_encoding','prefill_json','legacy_collision_rows','missing_waste_trip_columns','applied_late_migrations','WASTE_TRIP_LEGACY_READ_ONLY_PASS'] as $needle){$ok(str_contains($probe,$needle),'missing probe contract: '.$needle);}
foreach(['INSERT INTO ','UPDATE users','DELETE FROM ','ALTER TABLE','CREATE TABLE','DROP TABLE','TRUNCATE '] as $forbidden){$ok(!str_contains($probe,$forbidden),'read-only probe contains write SQL: '.$forbidden);}
$ok(str_contains($probe,"PDO::ATTR_EMULATE_PREPARES => false"),'native prepares required');
$ok(str_contains($probe,"login_sha12"),'login must be represented only by hash in output');
$ok(!str_contains($probe,"'login' => (string)"),'raw login must not be emitted');
$ok(str_contains($probe,"hash('sha256',\$raw)"),'encoding evidence must emit only hash/length/validity, not raw text');
echo "WASTE_TRIP_LEGACY_READ_ONLY_PROBE_CONTRACT=PASS\n";
