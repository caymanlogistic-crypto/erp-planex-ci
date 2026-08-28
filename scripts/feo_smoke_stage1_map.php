<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'cgi-fcgi') {
    fwrite(STDERR, "CLI/CGI only.\n");
    exit(1);
}

$root = rtrim((string) (getenv('PLANEX_ROOT') ?: ($argv[1] ?? '')), '/');
if ($root === '' || !is_file($root . '/.env') || !is_file($root . '/bootstrap/app.php')) {
    throw new RuntimeException('PLANEX_ROOT invalid');
}
$seedPath = (string) (getenv('PLANEX_FEO_STAGE1_SEED_PATH') ?: ($argv[2] ?? (__DIR__ . '/feo_stage1_utilizer_seed_data.php')));
$requireSeed = (string) (getenv('PLANEX_SMOKE_REQUIRE_SEED') ?: '0') === '1';
$targetCompanyId = (int) (getenv('PLANEX_TARGET_COMPANY_ID') ?: 0);
$targetDbIdentifier = trim((string) getenv('PLANEX_TARGET_DB_IDENTIFIER'));
if ($targetCompanyId !== 25 || $targetDbIdentifier !== 'spugovxsim_1') {
    throw new RuntimeException('Production smoke target must be exact PLANEX company 25 / spugovxsim_1');
}
if (!is_file($seedPath)) {
    throw new RuntimeException('Stage 1 utilizer seed data missing');
}

$config = require $root . '/bootstrap/app.php';
require_once $root . '/app/Support/crypto_helper.php';
require_once $root . '/app/Support/company_database.php';
require_once $root . '/app/Service/FeoUtilizerService.php';
require_once $root . '/app/Service/FeoMapService.php';
$seed = require $seedPath;

$centralCfg = $config['database'];
$central = new PDO(
    'mysql:host=' . $centralCfg['host'] . ';port=' . ($centralCfg['port'] ?? 3306) . ';dbname=' . $centralCfg['database'] . ';charset=utf8mb4',
    $centralCfg['username'],
    $centralCfg['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$q = $central->prepare(
    "SELECT id,name,status,db_identifier,db_host,db_port,db_username,db_password FROM companies WHERE id=? LIMIT 1"
);
$q->execute([$targetCompanyId]);
$company = $q->fetch(PDO::FETCH_ASSOC);
if (!$company || (string) $company['status'] !== 'active' || (string) $company['db_identifier'] !== $targetDbIdentifier) {
    throw new RuntimeException('Exact PLANEX target company is not active or DB identity changed');
}
$cfg = companyDatabaseConfig($config, $company);
$pdo = new PDO(
    'mysql:host=' . $cfg['host'] . ';port=' . ($cfg['port'] ?? 3306) . ';dbname=' . $cfg['database'] . ';charset=utf8mb4',
    $cfg['username'],
    $cfg['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
if ((string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== $targetDbIdentifier) {
    throw new RuntimeException('Unexpected tenant database');
}

$counts = [
    'feo_requests' => (int) $pdo->query('SELECT COUNT(*) FROM feo_requests')->fetchColumn(),
    'placement_current' => (int) $pdo->query('SELECT COUNT(*) FROM feo_placement_current')->fetchColumn(),
    'cost_blocks' => (int) $pdo->query('SELECT COUNT(*) FROM feo_request_cost_blocks')->fetchColumn(),
    'cost_members' => (int) $pdo->query('SELECT COUNT(*) FROM feo_request_cost_block_requests')->fetchColumn(),
    'utilizers' => (int) $pdo->query('SELECT COUNT(*) FROM feo_utilizers')->fetchColumn(),
    'utilizer_rules' => (int) $pdo->query('SELECT COUNT(*) FROM feo_utilizer_match_rules')->fetchColumn(),
    'utilizer_overrides' => (int) $pdo->query('SELECT COUNT(*) FROM feo_request_utilizer_overrides')->fetchColumn(),
];

$utilizerService = new App\Service\FeoUtilizerService();
$mapService = new App\Service\FeoMapService($utilizerService);
$rows = $mapService->availableRows($pdo);
$classifications = $utilizerService->classifyRequests($pdo, $rows);
$payload = App\Service\FeoMapService::buildPayload($rows, $classifications);

$statusCounts = ['matched' => 0, 'inactive' => 0, 'unmatched' => 0];
$matchedByUtilizer = [];
$unmatchedSamples = [];
foreach ($rows as $row) {
    $id = (string) ($row['zayavka_id'] ?? '');
    $c = $classifications[$id] ?? ['status' => 'unmatched', 'utilizer' => null];
    $status = (string) ($c['status'] ?? 'unmatched');
    if (!isset($statusCounts[$status])) $statusCounts[$status] = 0;
    $statusCounts[$status]++;
    if ($status === 'matched' && is_array($c['utilizer'] ?? null)) {
        $short = (string) ($c['utilizer']['short_name'] ?? '');
        $matchedByUtilizer[$short] = ($matchedByUtilizer[$short] ?? 0) + 1;
    } elseif ($status === 'unmatched' && count($unmatchedSamples) < 30) {
        $unmatchedSamples[] = [
            'zayavka_id' => $id,
            'recipient' => (string) ($row['gruzopoluchatel'] ?? ''),
            'delivery_address' => (string) ($row['adres_dostavki_otkhodov'] ?? ''),
        ];
    }
}
ksort($matchedByUtilizer, SORT_NATURAL | SORT_FLAG_CASE);

$expectedShort = array_map(static fn(array $u): string => (string) $u['short_name'], $seed['utilizers'] ?? []);
$presentShort = array_column($pdo->query('SELECT short_name FROM feo_utilizers ORDER BY short_name')->fetchAll(PDO::FETCH_ASSOC) ?: [], 'short_name');
$missingSeedUtilizers = array_values(array_diff($expectedShort, $presentShort));
if ($requireSeed && $missingSeedUtilizers !== []) {
    throw new RuntimeException('Seeded utilizers missing: ' . implode(', ', $missingSeedUtilizers));
}
if ($requireSeed && $counts['utilizers'] < 8) {
    throw new RuntimeException('Expected at least 8 seeded utilizers');
}
if ($requireSeed && $counts['utilizer_rules'] < 38) {
    throw new RuntimeException('Expected at least 38 explicit seeded rules');
}
if ($counts['placement_current'] <= 0 || count($rows) !== $counts['placement_current']) {
    throw new RuntimeException('Canonical placement-current source is empty or inconsistent');
}

$result = [
    'company_id' => $targetCompanyId,
    'company_name' => (string) ($company['name'] ?? ''),
    'db' => $targetDbIdentifier,
    'require_seed' => $requireSeed,
    'counts' => $counts,
    'classification' => $statusCounts,
    'matched_by_utilizer' => $matchedByUtilizer,
    'map_diagnostics' => $payload['diagnostics'],
    'marker_count' => count($payload['markers']),
    'layer_count' => count($payload['layers']),
    'missing_seed_utilizers' => $missingSeedUtilizers,
    'unmatched_samples' => $unmatchedSamples,
];
printf("FEO_STAGE1_SMOKE=%s\n", json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
printf("FEO_STAGE1_SMOKE_OK company_id=%d db=%s available=%d matched=%d unmatched=%d missing_coords=%d invalid_coords=%d markers=%d\n",
    $targetCompanyId,
    $targetDbIdentifier,
    count($rows),
    (int) ($statusCounts['matched'] ?? 0),
    (int) ($statusCounts['unmatched'] ?? 0),
    (int) ($payload['diagnostics']['missing_coordinates_count'] ?? 0),
    (int) ($payload['diagnostics']['invalid_coordinates_count'] ?? 0),
    count($payload['markers'])
);
