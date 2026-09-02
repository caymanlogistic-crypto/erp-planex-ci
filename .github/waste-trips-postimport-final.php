<?php

declare(strict_types=1);

use App\Service\WasteTripService;

$root = rtrim((string)(getenv('PLANEX_ROOT') ?: ''), '/');
$expectedSha = trim((string)(getenv('EXPECTED_SHA') ?: ''));
$markerPath = (string)(getenv('DEPLOY_MARKER') ?: '');
if ($root === '' || $expectedSha === '' || $markerPath === '' || !is_file($markerPath)) {
    throw new RuntimeException('post-import runtime environment unavailable');
}
$marker = trim((string)file_get_contents($markerPath));
if ($marker !== $expectedSha) {
    throw new RuntimeException('production marker mismatch: ' . $marker);
}

require_once $root . '/app/Support/helpers.php';
require_once $root . '/app/Support/environment.php';
loadEnvFileNonOverwriting($root . '/.env');
$config = require $root . '/bootstrap/app.php';
require_once $root . '/app/Support/entrypoint_dependencies.php';
require_once $root . '/app/Service/WasteTripService.php';

$connectReadOnly = static function (array $c): PDO {
    $pdo = new PDO(
        'mysql:host=' . $c['host'] . ';port=' . ($c['port'] ?? 3306) . ';dbname=' . $c['database'] . ';charset=utf8mb4',
        $c['username'],
        $c['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    $pdo->exec('SET SESSION TRANSACTION READ ONLY');
    $pdo->exec('START TRANSACTION READ ONLY');
    return $pdo;
};

$central = $connectReadOnly($config['database']);
$company = $central->query("SELECT * FROM companies WHERE id=25 AND status='active' LIMIT 1")->fetch();
if (!$company) throw new RuntimeException('company 25 unavailable');
$tenant = $connectReadOnly(companyDatabaseConfig($config, $company));
if (!WasteTripService::schemaReady($tenant)) throw new RuntimeException('Waste Trips schema unavailable');

$counts = [];
foreach (['waste_trips', 'waste_trip_requests', 'waste_trip_legacy_import_audits', 'waste_trip_audit_log'] as $table) {
    $counts[$table] = (int)$tenant->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
}
$expectedCounts = ['waste_trips'=>75,'waste_trip_requests'=>961,'waste_trip_legacy_import_audits'=>75,'waste_trip_audit_log'=>75];
if ($counts !== $expectedCounts) throw new RuntimeException('post-import counts mismatch ' . json_encode($counts));

echo 'WASTE_POSTIMPORT_COUNTS=PASS trips=75 links=961 import_audits=75 audit_log=75' . PHP_EOL;

$clientStmt = $tenant->prepare("SELECT id,name,inn,status,deleted_at FROM clients WHERE inn=? ORDER BY id");
$clientStmt->execute(['4714004270']);
$clients = $clientStmt->fetchAll();
if (count($clients) !== 1 || (int)$clients[0]['id'] !== 6 || (string)$clients[0]['status'] !== 'active' || $clients[0]['deleted_at'] !== null) {
    throw new RuntimeException('FEO client verification failed');
}
if ((string)$clients[0]['name'] !== 'ФГУП "Федеральный экологический оператор"') {
    throw new RuntimeException('FEO client name mismatch');
}
echo 'WASTE_POSTIMPORT_CLIENT=PASS id=6 inn=4714004270' . PHP_EOL;

$identity = $tenant->query("SELECT COUNT(*) total, COUNT(DISTINCT legacy_flight_id) legacy_ids, COUNT(DISTINCT source_system) sources, MIN(source_system) source_min, MAX(source_system) source_max FROM waste_trips")->fetch();
if ((int)$identity['total'] !== 75 || (int)$identity['legacy_ids'] !== 75 || (int)$identity['sources'] !== 1 || $identity['source_min'] !== 'legacy_flights' || $identity['source_max'] !== 'legacy_flights') {
    throw new RuntimeException('legacy identity verification failed');
}
$distinctFeo = (int)$tenant->query('SELECT COUNT(DISTINCT feo_request_id) FROM waste_trip_requests')->fetchColumn();
if ($distinctFeo !== 828) throw new RuntimeException('distinct FEO mismatch=' . $distinctFeo);
echo 'WASTE_POSTIMPORT_IDENTITY=PASS legacy_flights=75 distinct_feo=828' . PHP_EOL;

$distribution = static function(PDO $pdo, string $sql, string $key): array {
    $out = [];
    foreach ($pdo->query($sql)->fetchAll() as $row) $out[(string)$row[$key]] = (int)$row['n'];
    ksort($out);
    return $out;
};
$status = $distribution($tenant, "SELECT status,COUNT(*) n FROM waste_trips GROUP BY status", 'status');
$expectedStatus = ['completed'=>65,'executor_assigned'=>1,'in_progress'=>3,'searching_executor'=>6]; ksort($expectedStatus);
if ($status !== $expectedStatus) throw new RuntimeException('status distribution mismatch ' . json_encode($status));
$dest = $distribution($tenant, "SELECT destination_type,COUNT(*) n FROM waste_trips GROUP BY destination_type", 'destination_type');
$expectedDest = ['utilizer'=>68,'warehouse'=>7]; ksort($expectedDest);
if ($dest !== $expectedDest) throw new RuntimeException('destination distribution mismatch ' . json_encode($dest));
$planning = $distribution($tenant, "SELECT planned_start_mode,COUNT(*) n FROM waste_trips GROUP BY planned_start_mode", 'planned_start_mode');
$expectedPlanning = ['range'=>6,'unspecified'=>69]; ksort($expectedPlanning);
if ($planning !== $expectedPlanning) throw new RuntimeException('planning distribution mismatch ' . json_encode($planning));
echo 'WASTE_POSTIMPORT_DISTRIBUTIONS=PASS statuses=65/1/3/6 destinations=68/7 planning=6/69' . PHP_EOL;

$partial = (int)$tenant->query("SELECT COUNT(*) FROM waste_trip_legacy_import_audits WHERE JSON_UNQUOTE(JSON_EXTRACT(legacy_payload_json,'$.legacy_planning_normalization.reason'))='partial_range_not_representable'")->fetchColumn();
if ($partial !== 15) throw new RuntimeException('partial planning audit mismatch=' . $partial);
$rangeDates = (int)$tenant->query("SELECT COUNT(*) FROM waste_trips WHERE planned_start_mode='range' AND planned_start_date IS NULL AND planned_start_date_from IS NOT NULL AND planned_start_date_to IS NOT NULL")->fetchColumn();
$invalidUnspecified = (int)$tenant->query("SELECT COUNT(*) FROM waste_trips WHERE planned_start_mode='unspecified' AND (planned_start_date IS NOT NULL OR planned_start_date_from IS NOT NULL OR planned_start_date_to IS NOT NULL)")->fetchColumn();
if ($rangeDates !== 6 || $invalidUnspecified !== 0) throw new RuntimeException('planning shape mismatch');
echo 'WASTE_POSTIMPORT_PARTIAL_PLAN=PASS normalized=15 complete_ranges=6 invalid_unspecified=0' . PHP_EOL;

$massStmt = $tenant->prepare("SELECT wt.legacy_flight_id,a.mass_delta_kg,a.warnings_json FROM waste_trips wt JOIN waste_trip_legacy_import_audits a ON a.waste_trip_id=wt.id WHERE wt.legacy_flight_id IN (257,258) ORDER BY wt.legacy_flight_id");
$massRows = $massStmt->fetchAll();
if (count($massRows) !== 2) throw new RuntimeException('mass evidence rows missing');
$expectedDelta = [257=>-145.0, 258=>-152.0];
foreach ($massRows as $row) {
    $id = (int)$row['legacy_flight_id'];
    $delta = (float)$row['mass_delta_kg'];
    if (!isset($expectedDelta[$id]) || abs($delta - $expectedDelta[$id]) > 0.0001) throw new RuntimeException('mass delta mismatch flight=' . $id);
    $warnings = json_decode((string)$row['warnings_json'], true);
    if (!is_array($warnings) || !in_array('legacy_mass_delta_kg=' . number_format($delta, 3, '.', ''), $warnings, true)) {
        throw new RuntimeException('mass warning missing flight=' . $id);
    }
}
echo 'WASTE_POSTIMPORT_MASS_EVIDENCE=PASS flight257=-145.000 flight258=-152.000' . PHP_EOL;

$importAuditShape = $tenant->query("SELECT COUNT(*) total, COUNT(DISTINCT legacy_flight_id) ids, SUM(snapshot_request_count) links FROM waste_trip_legacy_import_audits WHERE source_system='legacy_flights'")->fetch();
if ((int)$importAuditShape['total'] !== 75 || (int)$importAuditShape['ids'] !== 75 || (int)$importAuditShape['links'] !== 961) throw new RuntimeException('import audit shape mismatch');
$emptyAuditPayloads = (int)$tenant->query("SELECT COUNT(*) FROM waste_trip_legacy_import_audits WHERE legacy_payload_json IS NULL OR legacy_payload_json='' OR mapping_json IS NULL OR mapping_json=''")->fetchColumn();
if ($emptyAuditPayloads !== 0) throw new RuntimeException('empty immutable import evidence found');
echo 'WASTE_POSTIMPORT_AUDIT=PASS rows=75 snapshot_links=961 payloads=75' . PHP_EOL;

$trips = WasteTripService::fetchTrips($tenant, []);
if (count($trips) !== 75) throw new RuntimeException('fetchTrips runtime count mismatch=' . count($trips));
$historicalLabels = 0;
foreach ($trips as $trip) {
    if (($trip['source_system'] ?? null) !== 'legacy_flights') throw new RuntimeException('non-legacy trip in runtime list');
    if ((int)($trip['request_count'] ?? 0) <= 0) throw new RuntimeException('trip without request links');
    if (trim((string)($trip['historical_executor_label'] ?? '')) !== '') $historicalLabels++;
}
if ($historicalLabels === 0) throw new RuntimeException('historical executor enrichment absent');
echo 'WASTE_POSTIMPORT_SERVICE_RUNTIME=PASS fetchTrips=75 historical_labels=' . $historicalLabels . PHP_EOL;

$currentHist = $tenant->query("SELECT fr.zayavka_id FROM feo_placement_current pc JOIN feo_requests fr ON fr.zayavka_id=pc.zayavka_id JOIN waste_trip_requests wtr ON wtr.feo_request_id=fr.id JOIN waste_trips wt ON wt.id=wtr.waste_trip_id WHERE wt.source_system='legacy_flights' ORDER BY fr.zayavka_id LIMIT 1")->fetchColumn();
if (!is_string($currentHist) || $currentHist === '') throw new RuntimeException('no historical/current overlap available for preview check');
$preview = WasteTripService::previewSelection($tenant, [$currentHist]);
if ((int)($preview['found_count'] ?? 0) !== 1 || ($preview['occupied'] ?? []) !== []) throw new RuntimeException('historical link incorrectly blocks operational preview');
echo 'WASTE_POSTIMPORT_OPERATIONAL_ISOLATION=PASS sample=' . $currentHist . PHP_EOL;

$actorAudit = (int)$tenant->query("SELECT COUNT(*) FROM waste_trip_audit_log WHERE action='import' AND actor_user_id=1 AND actor_role='logist'")->fetchColumn();
if ($actorAudit !== 75) throw new RuntimeException('import actor audit mismatch=' . $actorAudit);
echo 'WASTE_POSTIMPORT_ACTOR_AUDIT=PASS imports=75 user=1 role=logist' . PHP_EOL;

if (trim((string)file_get_contents($markerPath)) !== $expectedSha) throw new RuntimeException('production marker changed during post-import verification');
echo 'WASTE_POSTIMPORT_MARKER=PASS sha=' . $expectedSha . PHP_EOL;
echo 'WASTE_POSTIMPORT_FINAL=PASS' . PHP_EOL;
