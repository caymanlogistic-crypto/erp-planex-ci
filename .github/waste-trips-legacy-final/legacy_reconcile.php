<?php

declare(strict_types=1);

use App\Service\LinearRouteService;
use App\Service\WasteTripLegacyImportService;
use App\Service\WasteTripService;

$root = rtrim((string)(getenv('PLANEX_ROOT') ?: ''), '/');
$candidateList = (string)(getenv('CANDIDATE_LIST') ?: '');
$jsonPath = (string)(getenv('OUT_JSON') ?: '/tmp/waste_legacy_reconciliation.json');
$csvPath = (string)(getenv('OUT_CSV') ?: '/tmp/waste_legacy_reconciliation.csv');
if ($root === '' || !is_file($root . '/.env') || !is_file($root . '/bootstrap/app.php')) {
    throw new RuntimeException('PLANEX_ROOT invalid');
}
if ($candidateList === '' || !is_file($candidateList)) {
    throw new RuntimeException('legacy config candidate list unavailable');
}

require_once $root . '/app/Support/helpers.php';
require_once $root . '/app/Support/environment.php';
loadEnvFileNonOverwriting($root . '/.env');
$config = require $root . '/bootstrap/app.php';
require_once $root . '/app/Support/entrypoint_dependencies.php';
require_once $root . '/app/Service/LinearRouteService.php';
require_once $root . '/app/Service/WasteTripService.php';
require_once $root . '/app/Service/WasteTripLegacyImportService.php';

function roPdo(array $cfg): PDO
{
    $pdo = new PDO(
        'mysql:host=' . $cfg['host'] . ';port=' . ($cfg['port'] ?? 3306) . ';dbname=' . $cfg['database'] . ';charset=utf8mb4',
        $cfg['username'],
        $cfg['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    $pdo->exec('SET SESSION TRANSACTION READ ONLY');
    $pdo->exec('START TRANSACTION READ ONLY');
    return $pdo;
}

function literalArrayValue(string $source, string $key): ?string
{
    $pattern = '~[\'\"]' . preg_quote($key, '~') . '[\'\"]\s*=>\s*([\'\"])(.*?)\\1~s';
    if (!preg_match($pattern, $source, $m)) {
        return null;
    }
    return stripcslashes((string)$m[2]);
}

function legacyCfgFromSource(string $source): ?array
{
    $host = literalArrayValue($source, 'host');
    $database = literalArrayValue($source, 'dbname');
    $username = literalArrayValue($source, 'username');
    $password = literalArrayValue($source, 'password');
    if ($host === null || $database === null || $username === null || $password === null) {
        return null;
    }
    return ['host' => $host, 'port' => 3306, 'database' => $database, 'username' => $username, 'password' => $password];
}

function chunks(array $values, int $size = 500): array
{
    return array_chunk(array_values($values), $size);
}

function selectByIds(PDO $pdo, string $table, string $idColumn, array $ids, array $columns): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if ($ids === []) return [];
    $out = [];
    foreach (chunks($ids) as $chunk) {
        $ph = implode(',', array_fill(0, count($chunk), '?'));
        $quotedCols = implode(',', array_map(static fn(string $c): string => '`' . str_replace('`', '``', $c) . '`', $columns));
        $stmt = $pdo->prepare('SELECT ' . $quotedCols . ' FROM `' . str_replace('`', '``', $table) . '` WHERE `' . str_replace('`', '``', $idColumn) . '` IN (' . $ph . ')');
        $stmt->execute($chunk);
        foreach ($stmt->fetchAll() as $row) $out[(int)$row[$idColumn]] = $row;
    }
    return $out;
}

function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() === 1;
}

function tableColumns(PDO $pdo, string $table): array
{
    if (!tableExists($pdo, $table)) return [];
    return array_values(array_map(static fn(array $r): string => (string)$r['Field'], $pdo->query('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '`')->fetchAll()));
}

function pickExisting(array $columns, array $wanted): array
{
    return array_values(array_filter($wanted, static fn(string $c): bool => in_array($c, $columns, true)));
}

function legacyMassKg(mixed $tonnes): float
{
    $v = str_replace(',', '.', trim((string)$tonnes));
    return is_numeric($v) ? round(max(0.0, (float)$v) * 1000, 3) : 0.0;
}

// Resolve production PLANEX tenant in read-only mode.
$central = roPdo($config['database']);
$company = $central->query("SELECT * FROM companies WHERE id=25 AND status='active' LIMIT 1")->fetch() ?: null;
if (!$company) throw new RuntimeException('PLANEX tenant unavailable');
$tenantCfg = companyDatabaseConfig($config, $company);
$tenant = roPdo($tenantCfg);

// Discover legacy connection by statically parsing server-side config files. Never execute those files.
$paths = array_values(array_unique(array_filter(array_map('trim', file($candidateList, FILE_IGNORE_NEW_LINES) ?: []))));
$connections = [];
$parsedConfigs = 0;
foreach ($paths as $path) {
    if (!is_file($path) || filesize($path) === false || filesize($path) > 524288) continue;
    $source = @file_get_contents($path);
    if (!is_string($source)) continue;
    $cfg = legacyCfgFromSource($source);
    if ($cfg === null) continue;
    $parsedConfigs++;
    $identity = hash('sha256', $cfg['host'] . "\0" . $cfg['database'] . "\0" . $cfg['username']);
    if (isset($connections[$identity])) {
        $connections[$identity]['paths'][] = $path;
        continue;
    }
    try {
        $pdo = roPdo($cfg);
        if (!tableExists($pdo, 'flights')) continue;
        $connections[$identity] = [
            'config' => $cfg,
            'database' => (string)$pdo->query('SELECT DATABASE()')->fetchColumn(),
            'paths' => [$path],
        ];
    } catch (Throwable) {
        // Invalid/stale config candidate: skip without disclosing credentials.
    }
}
if (count($connections) !== 1) {
    throw new RuntimeException('expected exactly one unique legacy connection containing flights; found=' . count($connections) . '; scanned=' . count($paths) . '; parsed=' . $parsedConfigs);
}
$selected = array_values($connections)[0];
$legacy = roPdo($selected['config']);
$legacyDbName = (string)$legacy->query('SELECT DATABASE()')->fetchColumn();

$flightColumns = tableColumns($legacy, 'flights');
$requiredFlightColumns = ['id', 'zayavki_ids'];
foreach ($requiredFlightColumns as $required) {
    if (!in_array($required, $flightColumns, true)) throw new RuntimeException('legacy flights missing required column ' . $required);
}
$wantedFlightColumns = ['id','block_date','zayavki_ids','zayavki_count','total_mass_tonn','unload_type','status','contractor_id','driver_id','cost','payment_terms','comment','planned_start_date','planned_start_date_from','planned_start_date_to','actual_start_date','actual_end_date','assigned_manager_id'];
$selectedFlightColumns = pickExisting($flightColumns, $wantedFlightColumns);
$flights = $legacy->query('SELECT ' . implode(',', array_map(static fn(string $c): string => '`' . $c . '`', $selectedFlightColumns)) . ' FROM flights ORDER BY id')->fetchAll();
$legacyTotal = count($flights);

$contractorIds = $driverIds = $managerIds = [];
$allRequestSet = [];
foreach ($flights as $flight) {
    if (!empty($flight['contractor_id'])) $contractorIds[] = (int)$flight['contractor_id'];
    if (!empty($flight['driver_id'])) $driverIds[] = (int)$flight['driver_id'];
    if (!empty($flight['assigned_manager_id'])) $managerIds[] = (int)$flight['assigned_manager_id'];
    foreach (WasteTripLegacyImportService::parseLegacyRequestIds((string)($flight['zayavki_ids'] ?? '')) as $requestId) $allRequestSet[$requestId] = true;
}

$contractors = [];
if ($contractorIds && tableExists($legacy, 'contractors')) {
    $cols = pickExisting(tableColumns($legacy, 'contractors'), ['id','name','inn','ИНН','ogrn','ОГРН']);
    if (!in_array('id', $cols, true)) array_unshift($cols, 'id');
    $contractors = selectByIds($legacy, 'contractors', 'id', $contractorIds, $cols);
}
$drivers = [];
if ($driverIds && tableExists($legacy, 'drivers')) {
    $cols = pickExisting(tableColumns($legacy, 'drivers'), ['id','full_name','vehicle_make_plate','contractor_id']);
    if (!in_array('id', $cols, true)) array_unshift($cols, 'id');
    $drivers = selectByIds($legacy, 'drivers', 'id', $driverIds, $cols);
}
$managers = [];
if ($managerIds && tableExists($legacy, 'users')) {
    $cols = pickExisting(tableColumns($legacy, 'users'), ['id','Фамилия','Имя','Отчество','email','Email','E-mail','name','full_name']);
    if (!in_array('id', $cols, true)) array_unshift($cols, 'id');
    $managers = selectByIds($legacy, 'users', 'id', $managerIds, $cols);
}

$requestIds = array_keys($allRequestSet);
$requestMap = [];
foreach (chunks($requestIds) as $chunk) {
    if (!$chunk) continue;
    $ph = implode(',', array_fill(0, count($chunk), '?'));
    $stmt = $tenant->prepare("SELECT id,zayavka_id,mass_netto,polnoe_naim_zakazchika,inn_zakazchika FROM feo_requests WHERE zayavka_id IN ($ph)");
    $stmt->execute($chunk);
    foreach ($stmt->fetchAll() as $r) $requestMap[(string)$r['zayavka_id']] = $r;
}

$claimMap = [];
$requestPhysicalIds = array_values(array_map(static fn(array $r): int => (int)$r['id'], $requestMap));
foreach (chunks($requestPhysicalIds) as $chunk) {
    if (!$chunk) continue;
    $ph = implode(',', array_fill(0, count($chunk), '?'));
    $stmt = $tenant->prepare("SELECT wtr.feo_request_id,wt.id waste_trip_id FROM waste_trip_requests wtr JOIN waste_trips wt ON wt.id=wtr.waste_trip_id WHERE wtr.feo_request_id IN ($ph) AND wt.status<>'cancelled'");
    $stmt->execute($chunk);
    foreach ($stmt->fetchAll() as $r) $claimMap[(int)$r['feo_request_id']] = (int)$r['waste_trip_id'];
}

$legacyIds = array_values(array_map(static fn(array $f): int => (int)$f['id'], $flights));
$importMap = [];
foreach (chunks($legacyIds) as $chunk) {
    if (!$chunk) continue;
    $ph = implode(',', array_fill(0, count($chunk), '?'));
    $params = array_merge([WasteTripLegacyImportService::SOURCE_SYSTEM], $chunk);
    $stmt = $tenant->prepare("SELECT id,legacy_flight_id FROM waste_trips WHERE source_system=? AND legacy_flight_id IN ($ph)");
    $stmt->execute($params);
    foreach ($stmt->fetchAll() as $r) $importMap[(int)$r['legacy_flight_id']] = (int)$r['id'];
}

$clients = $tenant->query("SELECT id,name,inn FROM clients WHERE status='active' AND deleted_at IS NULL ORDER BY id")->fetchAll();
$clientsByInn = [];
foreach ($clients as $client) {
    $inn = trim((string)($client['inn'] ?? ''));
    if ($inn !== '') $clientsByInn[$inn][] = $client;
}
$tenantUserId = (int)$tenant->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
$routeExecutors = $tenantUserId > 0 ? LinearRouteService::fetchVisibleRouteExecutors($tenant, ['user_id' => $tenantUserId, 'role_code' => 'company_owner']) : [];
$erpUsers = WasteTripService::fetchActiveUsers($tenant);
$utilizers = WasteTripService::fetchActiveUtilizers($tenant);

$summary = [
    'legacy_flights_total' => $legacyTotal,
    'already_imported' => 0,
    'missing_in_erp' => 0,
    'hard_blocked' => 0,
    'ready_for_manual_mapping' => 0,
    'blocked_missing_feo' => 0,
    'blocked_request_conflicts' => 0,
    'blocked_unknown_status' => 0,
    'blocked_unknown_unload_type' => 0,
    'blocked_no_requests' => 0,
    'mass_mismatch_gt_1kg' => 0,
    'multiple_customer_inns' => 0,
    'client_exact_inn_suggested' => 0,
    'needs_client_mapping' => 0,
    'needs_executor_mapping' => 0,
    'needs_responsible_mapping' => 0,
    'needs_destination_mapping' => 0,
    'status_counts' => [],
    'unload_type_counts' => [],
];
$rows = [];
foreach ($flights as $flight) {
    $id = (int)$flight['id'];
    $legacyStatus = trim((string)($flight['status'] ?? ''));
    $unloadType = trim((string)($flight['unload_type'] ?? ''));
    $summary['status_counts'][$legacyStatus] = ($summary['status_counts'][$legacyStatus] ?? 0) + 1;
    $summary['unload_type_counts'][$unloadType] = ($summary['unload_type_counts'][$unloadType] ?? 0) + 1;
    $requestIdsForFlight = WasteTripLegacyImportService::parseLegacyRequestIds((string)($flight['zayavki_ids'] ?? ''));
    $missing = [];
    $occupied = [];
    $resolved = [];
    $customerInns = [];
    $currentMassKg = 0.0;
    foreach ($requestIdsForFlight as $rid) {
        $request = $requestMap[$rid] ?? null;
        if ($request === null) {
            $missing[] = $rid;
            continue;
        }
        $resolved[] = $rid;
        $currentMassKg += WasteTripService::massKg($request['mass_netto'] ?? null);
        $inn = trim((string)($request['inn_zakazchika'] ?? ''));
        if ($inn !== '') $customerInns[$inn] = true;
        $physicalId = (int)$request['id'];
        if (isset($claimMap[$physicalId])) $occupied[$rid] = $claimMap[$physicalId];
    }
    $mappedStatus = WasteTripLegacyImportService::mapLegacyStatus($legacyStatus);
    $alreadyImported = isset($importMap[$id]);
    if ($alreadyImported) $summary['already_imported']++; else $summary['missing_in_erp']++;
    $hard = [];
    if ($requestIdsForFlight === []) { $hard[] = 'no_requests'; $summary['blocked_no_requests']++; }
    if ($missing !== []) { $hard[] = 'missing_feo'; $summary['blocked_missing_feo']++; }
    if ($occupied !== []) { $hard[] = 'request_conflict'; $summary['blocked_request_conflicts']++; }
    if ($mappedStatus === null) { $hard[] = 'unknown_status'; $summary['blocked_unknown_status']++; }
    if (!in_array($unloadType, ['OO','SKLAD'], true)) { $hard[] = 'unknown_unload_type'; $summary['blocked_unknown_unload_type']++; }
    if (count($customerInns) > 1) { $hard[] = 'multiple_customer_inns'; $summary['multiple_customer_inns']++; }
    if (!$alreadyImported && $hard !== []) $summary['hard_blocked']++;
    if (!$alreadyImported && $hard === []) $summary['ready_for_manual_mapping']++;

    $legacyMass = legacyMassKg($flight['total_mass_tonn'] ?? null);
    $massDelta = round($currentMassKg - $legacyMass, 3);
    if (abs($massDelta) > 1.0) $summary['mass_mismatch_gt_1kg']++;

    $clientCandidates = [];
    $customerInnList = array_keys($customerInns);
    if (count($customerInnList) === 1) {
        foreach ($clientsByInn[$customerInnList[0]] ?? [] as $c) $clientCandidates[(int)$c['id']] = $c;
    }
    $clientExact = count($customerInnList) === 1 && count($clientCandidates) === 1;
    if (!$alreadyImported) {
        $summary['needs_client_mapping']++;
        if ($clientExact) $summary['client_exact_inn_suggested']++;
        $summary['needs_responsible_mapping']++;
        $summary['needs_destination_mapping']++;
        if (in_array($mappedStatus, [WasteTripService::STATUS_ASSIGNED, WasteTripService::STATUS_IN_PROGRESS, WasteTripService::STATUS_COMPLETED], true)) $summary['needs_executor_mapping']++;
    }

    $contractor = $contractors[(int)($flight['contractor_id'] ?? 0)] ?? [];
    $driver = $drivers[(int)($flight['driver_id'] ?? 0)] ?? [];
    $manager = $managers[(int)($flight['assigned_manager_id'] ?? 0)] ?? [];
    $managerName = trim(implode(' ', array_filter([(string)($manager['Фамилия'] ?? ''),(string)($manager['Имя'] ?? ''),(string)($manager['Отчество'] ?? '')])));
    if ($managerName === '') $managerName = (string)($manager['full_name'] ?? $manager['name'] ?? '');

    $rows[] = [
        'legacy_flight_id' => $id,
        'already_imported' => $alreadyImported,
        'erp_waste_trip_id' => $importMap[$id] ?? null,
        'legacy_status' => $legacyStatus,
        'mapped_status' => $mappedStatus,
        'legacy_unload_type' => $unloadType,
        'request_ids' => $requestIdsForFlight,
        'resolved_request_ids' => $resolved,
        'missing_request_ids' => $missing,
        'occupied_requests' => $occupied,
        'legacy_mass_kg' => $legacyMass,
        'current_feo_mass_kg' => round($currentMassKg, 3),
        'mass_delta_kg' => $massDelta,
        'customer_inns' => $customerInnList,
        'client_exact_candidate_id' => $clientExact ? (int)array_key_first($clientCandidates) : null,
        'legacy_contractor' => ['id' => $flight['contractor_id'] ?? null, 'name' => $contractor['name'] ?? null],
        'legacy_driver' => ['id' => $flight['driver_id'] ?? null, 'name' => $driver['full_name'] ?? null, 'vehicle' => $driver['vehicle_make_plate'] ?? null],
        'legacy_manager' => ['id' => $flight['assigned_manager_id'] ?? null, 'name' => $managerName !== '' ? $managerName : null],
        'executor_mapping_required' => !$alreadyImported && in_array($mappedStatus, [WasteTripService::STATUS_ASSIGNED, WasteTripService::STATUS_IN_PROGRESS, WasteTripService::STATUS_COMPLETED], true),
        'responsible_mapping_required' => !$alreadyImported,
        'destination_mapping_required' => !$alreadyImported,
        'hard_blockers' => $hard,
        'ready_for_manual_mapping' => !$alreadyImported && $hard === [],
    ];
}
ksort($summary['status_counts']);
ksort($summary['unload_type_counts']);

$wasteCounts = [];
foreach (['waste_trips','waste_trip_requests','waste_trip_legacy_import_audits','waste_trip_audit_log'] as $table) {
    $wasteCounts[$table] = (int)$tenant->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
}
$result = [
    'source_system' => WasteTripLegacyImportService::SOURCE_SYSTEM,
    'legacy' => [
        'database' => $legacyDbName,
        'config_files_scanned' => count($paths),
        'parsed_config_files' => $parsedConfigs,
        'matching_unique_connections' => count($connections),
        'selected_config_path_hashes' => array_map(static fn(string $p): string => hash('sha256', $p), $selected['paths']),
    ],
    'erp' => [
        'company_id' => 25,
        'waste_counts' => $wasteCounts,
        'feo_requests' => (int)$tenant->query('SELECT COUNT(*) FROM feo_requests')->fetchColumn(),
        'clients_available' => count($clients),
        'route_executors_available' => count($routeExecutors),
        'responsible_users_available' => count($erpUsers),
        'utilizers_available' => count($utilizers),
    ],
    'summary' => $summary,
    'flights' => $rows,
];
file_put_contents($jsonPath, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

$csv = fopen($csvPath, 'wb');
if ($csv === false) throw new RuntimeException('cannot create CSV evidence');
fputcsv($csv, ['legacy_flight_id','already_imported','legacy_status','mapped_status','legacy_unload_type','request_ids','missing_request_ids','occupied_request_ids','legacy_mass_kg','current_feo_mass_kg','mass_delta_kg','client_exact_candidate_id','executor_mapping_required','responsible_mapping_required','destination_mapping_required','hard_blockers','ready_for_manual_mapping']);
foreach ($rows as $row) {
    fputcsv($csv, [
        $row['legacy_flight_id'], $row['already_imported'] ? 1 : 0, $row['legacy_status'], $row['mapped_status'], $row['legacy_unload_type'],
        implode('|', $row['request_ids']), implode('|', $row['missing_request_ids']), implode('|', array_keys($row['occupied_requests'])),
        $row['legacy_mass_kg'], $row['current_feo_mass_kg'], $row['mass_delta_kg'], $row['client_exact_candidate_id'],
        $row['executor_mapping_required'] ? 1 : 0, $row['responsible_mapping_required'] ? 1 : 0, $row['destination_mapping_required'] ? 1 : 0,
        implode('|', $row['hard_blockers']), $row['ready_for_manual_mapping'] ? 1 : 0,
    ]);
}
fclose($csv);

printf("WASTE_LEGACY_DATABASE=%s\n", $legacyDbName);
printf("WASTE_LEGACY_TOTAL=%d\n", $summary['legacy_flights_total']);
printf("WASTE_LEGACY_ALREADY_IMPORTED=%d\n", $summary['already_imported']);
printf("WASTE_LEGACY_MISSING_IN_ERP=%d\n", $summary['missing_in_erp']);
printf("WASTE_LEGACY_READY_FOR_MANUAL_MAPPING=%d\n", $summary['ready_for_manual_mapping']);
printf("WASTE_LEGACY_HARD_BLOCKED=%d\n", $summary['hard_blocked']);
printf("WASTE_LEGACY_BLOCKED_MISSING_FEO=%d\n", $summary['blocked_missing_feo']);
printf("WASTE_LEGACY_BLOCKED_REQUEST_CONFLICTS=%d\n", $summary['blocked_request_conflicts']);
printf("WASTE_LEGACY_UNKNOWN_STATUS=%d\n", $summary['blocked_unknown_status']);
printf("WASTE_LEGACY_UNKNOWN_UNLOAD_TYPE=%d\n", $summary['blocked_unknown_unload_type']);
printf("WASTE_LEGACY_MASS_MISMATCH_GT_1KG=%d\n", $summary['mass_mismatch_gt_1kg']);
echo "WASTE_LEGACY_RECONCILIATION=PASS\n";
