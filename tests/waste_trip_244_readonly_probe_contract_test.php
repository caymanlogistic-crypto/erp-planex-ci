<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$probe = (string) file_get_contents($root . '/scripts/waste_trip_244_readonly_probe.php');
$workflow = (string) file_get_contents($root . '/.github/workflows/waste_trips_runtime_diagnostic.yml');
$fail = static function (string $message): never { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); };
$ok = static function (bool $value, string $message) use ($fail): void { if (!$value) $fail($message); };

$ok(str_contains($probe, 'fetchFlight($legacy, 244)'), 'probe must inspect exact Legacy #244');
$ok(substr_count($probe, "SET SESSION TRANSACTION READ ONLY") >= 2, 'central and tenant sessions must be explicitly read-only');
$ok(str_contains($probe, "'%Гладких%'") && str_contains($probe, "'%Сидоров%'"), 'probe must inspect the two reported manager identities');
$ok(str_contains($probe, "'%ФРЕГАТ%'") && str_contains($probe, "'%Пинчук%'"), 'probe must inspect the reported client/utilizer/executor preconditions');
$ok(str_contains($probe, "'trip_insert' => 'INSERT INTO waste_trips") && str_contains($probe, "'audit_insert' => 'INSERT INTO waste_trip_audit_log"), 'probe must native-prepare the exact write statements without executing them');
$ok(str_contains($probe, '$prepareResults[$name] = $prepareCheck($pdo, $sql);'), 'candidate DML must only go through native prepare check');
$ok(!preg_match('/->exec\s*\(\s*[\'\"]\s*(INSERT|UPDATE|DELETE|REPLACE|ALTER|DROP|CREATE|TRUNCATE)\b/i', $probe), 'probe must not execute production DML/DDL');
$ok(!preg_match('/->query\s*\(\s*[\'\"]\s*(INSERT|UPDATE|DELETE|REPLACE|ALTER|DROP|CREATE|TRUNCATE)\b/i', $probe), 'probe queries must be read-only');
$ok(str_contains($probe, "WASTE_TRIP_244_READ_ONLY_PASS"), 'probe pass marker missing');

$ok(str_contains($workflow, 'Run Legacy 244 read-only production precondition probe'), 'runtime diagnostic must invoke the read-only probe');
$ok(str_contains($workflow, 'cat scripts/waste_trip_244_readonly_probe.php |'), 'workflow must stream probe over SSH without staging production files');
$ok(str_contains($workflow, 'PLANEX_ROOT=') && str_contains($workflow, '/usr/bin/php8.5'), 'workflow must run probe against deployed ERPv2 runtime');
$ok(str_contains($workflow, "grep -q '^WASTE_TRIP_244_READ_ONLY_PASS$'"), 'workflow must require probe PASS marker');
$ok(!str_contains($workflow, 'scp -P'), 'probe transport must not write a temporary production-side file');

 echo "WASTE_TRIP_244_READ_ONLY_PROBE_CONTRACT=PASS\n";
