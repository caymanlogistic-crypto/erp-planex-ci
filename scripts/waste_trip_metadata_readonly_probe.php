<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { exit(1); }
$root = rtrim((string) getenv('PLANEX_ROOT'), '/');
$flightId = (int) getenv('PLANEX_FLIGHT_ID');
$companyId = (int) getenv('PLANEX_COMPANY_ID');
if (!is_file($root . '/bootstrap/app.php') || $flightId < 226 || $companyId < 1) {
    throw new RuntimeException('Invalid read-only probe inputs');
}
$config = require $root . '/bootstrap/app.php';
require_once $root . '/app/Support/crypto_helper.php';
require_once $root . '/app/Support/company_database.php';
require_once $root . '/app/Service/WasteTripLegacySourceService.php';

$connect = static function (array $db): PDO {
    $pdo = new PDO(
        'mysql:host=' . $db['host'] . ';port=' . ($db['port'] ?? 3306) . ';dbname=' . $db['database'] . ';charset=utf8mb4',
        $db['username'], $db['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
    );
    $pdo->exec('SET SESSION TRANSACTION READ ONLY');
    return $pdo;
};
$central = $connect($config['database']);
$query = $central->prepare("SELECT * FROM companies WHERE id=? AND status='active' AND db_identifier IS NOT NULL AND db_identifier<>'' LIMIT 1");
$query->execute([$companyId]);
$company = $query->fetch();
if (!is_array($company)) { throw new RuntimeException('Active company unavailable'); }
$tenant = $connect(companyDatabaseConfig($config, $company));
$legacy = \App\Service\WasteTripLegacySourceService::connectionFromEnvironment();
if (!$legacy instanceof PDO) { throw new RuntimeException('Legacy connection unavailable'); }
$legacy->exec('SET SESSION TRANSACTION READ ONLY');

$sourceRequestsQuery = $legacy->prepare('SELECT zayavki_ids FROM flights WHERE id=? LIMIT 1');
$sourceRequestsQuery->execute([$flightId]);
$sourceRequestsRaw = $sourceRequestsQuery->fetchColumn();
$sourceRequestIds = $sourceRequestsRaw === false ? null
    : \App\Service\WasteTripLegacySourceService::parseRequestIdsStrict((string) $sourceRequestsRaw);

$targetRequestsQuery = $tenant->prepare(
    'SELECT wt.id AS trip_id,fr.zayavka_id,wtr.position_no
       FROM waste_trips wt
       LEFT JOIN waste_trip_requests wtr ON wtr.waste_trip_id=wt.id AND wtr.is_active=1
       LEFT JOIN feo_requests fr ON fr.id=wtr.feo_request_id
      WHERE wt.business_id=? OR wt.legacy_flight_id=?
      ORDER BY wt.id,wtr.position_no,wtr.id'
);
$targetRequestsQuery->execute([$flightId, $flightId]);
$targetRequestIdsByTrip = [];
foreach ($targetRequestsQuery->fetchAll() as $row) {
    $tripId = (int) $row['trip_id'];
    $targetRequestIdsByTrip[$tripId] ??= [];
    if ($row['zayavka_id'] !== null) $targetRequestIdsByTrip[$tripId][] = (string) $row['zayavka_id'];
}
$targetRequestIds = count($targetRequestIdsByTrip) === 1 ? reset($targetRequestIdsByTrip) : null;
$tenantOnlyRequestIds = $sourceRequestIds !== null && $targetRequestIds !== null
    ? array_values(array_diff($targetRequestIds, $sourceRequestIds)) : [];
$journalBlockers = [];
if ($tenantOnlyRequestIds !== []) {
    $marks = implode(',', array_fill(0, count($tenantOnlyRequestIds), '?'));
    $query = $tenant->prepare(
        "SELECT fr.zayavka_id,wtr.id AS trip_request_id,wtr.position_no,
                COUNT(j.id) AS journal_entries,MIN(j.occurred_at) AS first_event_at,
                MAX(j.occurred_at) AS last_event_at,
                GROUP_CONCAT(DISTINCT j.event_kind ORDER BY j.event_kind SEPARATOR ',') AS event_kinds
           FROM waste_trips wt
           JOIN waste_trip_requests wtr ON wtr.waste_trip_id=wt.id AND wtr.is_active=1
           JOIN feo_requests fr ON fr.id=wtr.feo_request_id
           LEFT JOIN waste_trip_registration_journal j ON j.waste_trip_request_id=wtr.id
          WHERE wt.business_id=? AND fr.zayavka_id IN ({$marks})
          GROUP BY fr.zayavka_id,wtr.id,wtr.position_no
          ORDER BY wtr.position_no,wtr.id"
    );
    $query->execute(array_merge([$flightId], $tenantOnlyRequestIds));
    $journalBlockers = $query->fetchAll();
}

$sourceColumns = $legacy->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='flights'")->fetchAll(PDO::FETCH_COLUMN);
$required = ['legal_entity_code', 'route_type', 'unload_type', 'source_warehouse_id', 'destination_warehouse_id'];
$missingSource = array_values(array_diff($required, $sourceColumns));
$raw = null;
if ($missingSource === []) {
    $query = $legacy->prepare('SELECT id,legal_entity_code,route_type,unload_type,source_warehouse_id,destination_warehouse_id FROM flights WHERE id=? LIMIT 1');
    $query->execute([$flightId]);
    $raw = $query->fetch() ?: null;
}
$source = null;
$sourceError = null;
try {
    $flight = \App\Service\WasteTripLegacySourceService::fetchFlight($legacy, $flightId);
    $source = $flight === null ? null : \App\Service\WasteTripLegacyMetadataService::fromFlight($flight);
} catch (Throwable $error) {
    $sourceError = $error->getMessage();
}

$tenantColumns = $tenant->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='waste_trips'")->fetchAll(PDO::FETCH_COLUMN);
$missingTenant = array_values(array_diff(\App\Service\WasteTripLegacyMetadataService::COLUMNS, $tenantColumns));
$target = null;
if ($missingTenant === []) {
    $query = $tenant->prepare('SELECT business_id,source_system,legacy_flight_id,legal_entity_code,legacy_route_type,legacy_unload_type,legacy_source_warehouse_id,legacy_destination_warehouse_id,legacy_source_warehouse_name,legacy_destination_warehouse_name,legacy_sync_error,legacy_last_checked_at,legacy_last_success_at FROM waste_trips WHERE business_id=? OR legacy_flight_id=? ORDER BY id LIMIT 5');
    $query->execute([$flightId, $flightId]);
    $target = $query->fetchAll();
}
$journal = $tenant->prepare('SELECT checksum FROM schema_migrations WHERE migration=?');
$journal->execute(['149_waste_trip_legacy_metadata.sql']);
$out = [
    'company_id' => $companyId, 'flight_id' => $flightId,
    'source_missing_columns' => $missingSource, 'source_raw' => $raw,
    'source_metadata' => $source, 'source_fetch_error' => $sourceError,
    'source_request_ids' => $sourceRequestIds,
    'tenant_request_ids_by_trip' => $targetRequestIdsByTrip,
    'source_only_request_ids' => $sourceRequestIds !== null && $targetRequestIds !== null ? array_values(array_diff($sourceRequestIds, $targetRequestIds)) : null,
    'tenant_only_request_ids' => $sourceRequestIds !== null && $targetRequestIds !== null ? array_values(array_diff($targetRequestIds, $sourceRequestIds)) : null,
    'tenant_only_journal_links' => $journalBlockers,
    'tenant_missing_columns' => $missingTenant, 'tenant_rows' => $target,
    'migration_149_journaled' => $journal->fetchColumn() !== false,
];
echo 'WASTE_TRIP_METADATA_READ_ONLY=' . json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
echo "WASTE_TRIP_METADATA_READ_ONLY_PASS\n";
