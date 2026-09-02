<?php

declare(strict_types=1);

$root = rtrim((string)(getenv('PLANEX_ROOT') ?: ''), '/');
$expectedSha = trim((string)(getenv('EXPECTED_SHA') ?: ''));
$markerPath = trim((string)(getenv('DEPLOY_MARKER') ?: ''));
if ($root === '' || !is_file($root . '/.env') || !is_file($root . '/bootstrap/app.php')) {
    throw new RuntimeException('PLANEX_ROOT invalid');
}
if (!preg_match('/^[0-9a-f]{40}$/D', $expectedSha)) {
    throw new RuntimeException('EXPECTED_SHA invalid');
}
if ($markerPath === '' || !is_file($markerPath)) {
    throw new RuntimeException('DEPLOY_MARKER invalid');
}
$marker = trim((string)file_get_contents($markerPath));
if ($marker !== $expectedSha) {
    throw new RuntimeException('deployed marker mismatch: ' . $marker);
}

require_once $root . '/app/Support/helpers.php';
require_once $root . '/app/Support/environment.php';
loadEnvFileNonOverwriting($root . '/.env');
$config = require $root . '/bootstrap/app.php';
require_once $root . '/app/Support/crypto_helper.php';
require_once $root . '/app/Support/company_database.php';

$connectReadOnly = static function(array $cfg): PDO {
    $pdo = new PDO(
        'mysql:host=' . $cfg['host'] . ';port=' . ($cfg['port'] ?? 3306) . ';dbname=' . $cfg['database'] . ';charset=utf8mb4',
        $cfg['username'],
        $cfg['password'],
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
if (!$company) {
    throw new RuntimeException('PLANEX company unavailable');
}
$tenant = $connectReadOnly(companyDatabaseConfig($config, $company));

$tables = ['waste_trips', 'waste_trip_requests', 'waste_trip_legacy_import_audits', 'waste_trip_audit_log'];
$expectedCounts = [
    'waste_trips' => 75,
    'waste_trip_requests' => 961,
    'waste_trip_legacy_import_audits' => 75,
    'waste_trip_audit_log' => 75,
];
$counts = [];
$fingerprints = [];
foreach ($tables as $table) {
    $counts[$table] = (int)$tenant->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
    $rows = $tenant->query('SELECT * FROM `' . $table . '` ORDER BY id')->fetchAll();
    $fingerprints[$table] = hash('sha256', json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
}
if ($counts !== $expectedCounts) {
    throw new RuntimeException('Waste Trips counts mismatch: ' . json_encode($counts));
}

$sourceIdentity = $tenant->query("SELECT COUNT(*) total, COUNT(DISTINCT legacy_flight_id) legacy_ids, COUNT(DISTINCT source_system) sources, MIN(source_system) source_min, MAX(source_system) source_max FROM waste_trips")->fetch();
if ((int)$sourceIdentity['total'] !== 75 || (int)$sourceIdentity['legacy_ids'] !== 75 || (int)$sourceIdentity['sources'] !== 1 || $sourceIdentity['source_min'] !== 'legacy_flights' || $sourceIdentity['source_max'] !== 'legacy_flights') {
    throw new RuntimeException('legacy source identity mismatch');
}

$legacyMap = [];
$stmt = $tenant->query("SELECT id, legacy_flight_id FROM waste_trips WHERE legacy_flight_id IN (1,257,258) ORDER BY legacy_flight_id");
foreach ($stmt->fetchAll() as $row) {
    $legacyMap[(string)$row['legacy_flight_id']] = (int)$row['id'];
}
if (array_map('strval', array_keys($legacyMap)) !== ['1','257','258']) {
    throw new RuntimeException('required legacy acceptance trips missing: ' . json_encode($legacyMap));
}

$result = [
    'expected_sha' => $expectedSha,
    'deployed_marker' => $marker,
    'counts' => $counts,
    'fingerprints' => $fingerprints,
    'source_identity' => [
        'total' => (int)$sourceIdentity['total'],
        'legacy_ids' => (int)$sourceIdentity['legacy_ids'],
        'source_system' => (string)$sourceIdentity['source_min'],
    ],
    'legacy_to_trip_id' => $legacyMap,
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
