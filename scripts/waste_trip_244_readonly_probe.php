<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'cgi-fcgi') {
    fwrite(STDERR, "CLI/CGI only.\n");
    exit(1);
}

$root = rtrim((string) (getenv('PLANEX_ROOT') ?: ($argv[1] ?? '')), '/');
if ($root === '' || !is_file($root . '/bootstrap/app.php')) {
    throw new RuntimeException('PLANEX_ROOT invalid');
}

$config = require $root . '/bootstrap/app.php';
require_once $root . '/app/Support/crypto_helper.php';
require_once $root . '/app/Support/company_database.php';
require_once $root . '/app/Service/WasteTripLegacySourceService.php';

use App\Service\WasteTripLegacySourceService;

$centralCfg = $config['database'];
$central = new PDO(
    'mysql:host=' . $centralCfg['host'] . ';port=' . ($centralCfg['port'] ?? 3306) . ';dbname=' . $centralCfg['database'] . ';charset=utf8mb4',
    $centralCfg['username'],
    $centralCfg['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
);
$central->exec('SET SESSION TRANSACTION READ ONLY');

$tableColumns = static function (PDO $pdo, string $table): array {
    $stmt = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION');
    $stmt->execute([$table]);
    return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
};
$indexRows = static function (PDO $pdo, string $table): array {
    $stmt = $pdo->prepare('SELECT INDEX_NAME,NON_UNIQUE,GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS columns_csv FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? GROUP BY INDEX_NAME,NON_UNIQUE ORDER BY INDEX_NAME');
    $stmt->execute([$table]);
    return array_map(static fn(array $row): array => [
        'name' => (string) $row['INDEX_NAME'],
        'unique' => (int) $row['NON_UNIQUE'] === 0,
        'columns' => (string) $row['columns_csv'],
    ], $stmt->fetchAll());
};
$prepareCheck = static function (PDO $pdo, string $sql): array {
    try {
        $stmt = $pdo->prepare($sql);
        if (!$stmt instanceof PDOStatement) {
            return ['ok' => false, 'error' => 'prepare returned false'];
        }
        return ['ok' => true, 'error' => null];
    } catch (PDOException $e) {
        return [
            'ok' => false,
            'sqlstate' => (string) ($e->errorInfo[0] ?? $e->getCode()),
            'driver' => (string) ($e->errorInfo[1] ?? ''),
            'error' => preg_replace('/[\r\n\t]+/', ' ', substr($e->getMessage(), 0, 500)),
        ];
    }
};

$managerStmt = $central->prepare(
    "SELECT m.id AS membership_id,m.company_id,m.status AS membership_status,a.id AS account_id,a.full_name,a.status AS account_status,r.system_key\n"
    . "FROM company_memberships m JOIN user_accounts a ON a.id=m.account_id JOIN company_roles r ON r.id=m.role_id AND r.company_id=m.company_id\n"
    . "WHERE a.full_name LIKE ? OR a.full_name LIKE ? ORDER BY m.company_id,m.id"
);
$managerStmt->execute(['%Гладких%', '%Сидоров%']);
$centralManagers = array_map(static fn(array $row): array => [
    'membership_id' => (int) $row['membership_id'],
    'company_id' => (int) $row['company_id'],
    'membership_status' => (string) $row['membership_status'],
    'account_id' => (int) $row['account_id'],
    'full_name' => (string) $row['full_name'],
    'account_status' => (string) $row['account_status'],
    'system_key' => $row['system_key'] === null ? null : (string) $row['system_key'],
], $managerStmt->fetchAll());

$legacy = WasteTripLegacySourceService::connectionFromEnvironment();
if (!$legacy instanceof PDO) {
    throw new RuntimeException('Legacy flights DB unavailable');
}
$flight = WasteTripLegacySourceService::fetchFlight($legacy, 244);
if (!is_array($flight)) {
    throw new RuntimeException('Legacy #244 not found');
}
$legacyRequestIds = preg_split('/[\s,;]+/u', trim((string) ($flight['zayavki_ids'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
$legacyRequestIds = array_values(array_unique(array_filter(array_map('strval', $legacyRequestIds), static fn(string $id): bool => preg_match('/^[0-9]+$/D', $id) === 1)));

$companies = $central->query(
    "SELECT id,name,status,db_identifier,db_host,db_port,db_username,db_password FROM companies WHERE status='active' AND db_identifier IS NOT NULL AND db_identifier<>'' ORDER BY id"
)->fetchAll();

$expectedWasteColumns = [
    'id','business_id','client_id','route_executor_id','responsible_user_id','destination_type','utilizer_id','status',
    'planned_start_mode','planned_start_date','planned_start_date_from','planned_start_date_to','planned_finish_date','actual_start_date','actual_end_date',
    'comment','cancellation_reason','source_system','legacy_flight_id','created_by_user_id','created_by_membership_id','created_by_role','updated_by_user_id','updated_by_role'
];
$expectedUserColumns = ['id','membership_id','full_name','login','email','phone','password_hash','role_code','status','created_by_user_id','created_by_role','updated_at','deleted_at'];

$probeSql = [
    'manager_find_by_membership' => "SELECT id FROM users WHERE membership_id = ? FOR UPDATE",
    'manager_find_by_login' => "SELECT id,membership_id FROM users WHERE login = ? AND deleted_at IS NULL ORDER BY id FOR UPDATE",
    'manager_adopt' => 'UPDATE users SET membership_id=? WHERE id=?',
    'manager_insert' => 'INSERT INTO users (membership_id, full_name, login, email, phone, password_hash, role_code, status, created_by_user_id, created_by_role) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
    'manager_refresh' => 'UPDATE users SET full_name = ?, login = ?, email = ?, phone = ?, password_hash = ?, role_code = ?, status = ?, updated_at = NOW() WHERE membership_id = ?',
    'trip_duplicate_lock' => "SELECT id,business_id,source_system,legacy_flight_id FROM waste_trips WHERE business_id=? OR (legacy_flight_id=? AND source_system IN ('legacy_flights','legacy_prefill')) FOR UPDATE",
    'trip_insert' => 'INSERT INTO waste_trips (business_id,client_id,route_executor_id,responsible_user_id,destination_type,utilizer_id,status,planned_start_mode,planned_start_date,planned_start_date_from,planned_start_date_to,planned_finish_date,actual_start_date,actual_end_date,comment,cancellation_reason,source_system,legacy_flight_id,created_by_user_id,created_by_membership_id,created_by_role,updated_by_user_id,updated_by_role) VALUES (:business_id,:client_id,:route_executor_id,:responsible_user_id,:destination_type,:utilizer_id,:status,:planned_start_mode,:planned_start_date,:planned_start_date_from,:planned_start_date_to,:planned_finish_date,:actual_start_date,:actual_end_date,:comment,:cancellation_reason,:source_system,:legacy_flight_id,:created_user_id,:created_membership_id,:created_role_code,:updated_user_id,:updated_role_code)',
    'request_link_insert' => 'INSERT INTO waste_trip_requests (waste_trip_id,feo_request_id,snapshot_mass_kg,position_no) VALUES (?,?,?,?)',
    'audit_insert' => 'INSERT INTO waste_trip_audit_log (waste_trip_id,action,before_json,after_json,actor_user_id,actor_role) VALUES (?,?,?,?,?,?)',
];

$tenantResults = [];
foreach ($companies as $company) {
    $cfg = companyDatabaseConfig($config, $company);
    $pdo = new PDO(
        'mysql:host=' . $cfg['host'] . ';port=' . ($cfg['port'] ?? 3306) . ';dbname=' . $cfg['database'] . ';charset=utf8mb4',
        $cfg['username'], $cfg['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
    );
    $pdo->exec('SET SESSION TRANSACTION READ ONLY');

    $wasteColumns = $tableColumns($pdo, 'waste_trips');
    $userColumns = $tableColumns($pdo, 'users');
    $companyId = (int) $company['id'];
    $membershipIds = array_values(array_map(
        static fn(array $row): int => (int) $row['membership_id'],
        array_filter($centralManagers, static fn(array $row): bool => (int) $row['company_id'] === $companyId)
    ));

    $tenantManagers = [];
    if ($membershipIds !== []) {
        $marks = implode(',', array_fill(0, count($membershipIds), '?'));
        $stmt = $pdo->prepare("SELECT id,membership_id,full_name,login,role_code,status,deleted_at FROM users WHERE membership_id IN ({$marks}) OR full_name LIKE ? OR full_name LIKE ? ORDER BY id");
        $stmt->execute(array_merge($membershipIds, ['%Гладких%', '%Сидоров%']));
        foreach ($stmt->fetchAll() as $row) {
            $tenantManagers[] = [
                'tenant_user_id' => (int) $row['id'],
                'membership_id' => $row['membership_id'] === null ? null : (int) $row['membership_id'],
                'full_name' => (string) $row['full_name'],
                'login_sha12' => substr(hash('sha256', (string) $row['login']), 0, 12),
                'role_code' => (string) $row['role_code'],
                'status' => (string) $row['status'],
                'deleted' => $row['deleted_at'] !== null,
            ];
        }
    }

    $feoRows = [];
    if ($legacyRequestIds !== []) {
        $marks = implode(',', array_fill(0, count($legacyRequestIds), '?'));
        $stmt = $pdo->prepare("SELECT id,zayavka_id,mass_netto FROM feo_requests WHERE zayavka_id IN ({$marks}) ORDER BY zayavka_id");
        $stmt->execute($legacyRequestIds);
        foreach ($stmt->fetchAll() as $row) {
            $claim = $pdo->prepare("SELECT wt.business_id,wt.status FROM waste_trip_requests wtr JOIN waste_trips wt ON wt.id=wtr.waste_trip_id WHERE wtr.feo_request_id=? AND wtr.is_active=1 AND wt.status<>'cancelled' ORDER BY wt.id LIMIT 5");
            $claim->execute([(int) $row['id']]);
            $feoRows[] = [
                'feo_request_id' => (int) $row['id'],
                'zayavka_id' => (string) $row['zayavka_id'],
                'mass_netto' => $row['mass_netto'] === null ? null : (string) $row['mass_netto'],
                'active_claims' => $claim->fetchAll(),
            ];
        }
    }

    $clientStmt = $pdo->prepare("SELECT id,name FROM clients WHERE name LIKE ? ORDER BY id LIMIT 20");
    $clientStmt->execute(['%ФРЕГАТ%']);
    $utilizerStmt = $pdo->prepare("SELECT id,short_name,full_name,is_active FROM feo_utilizers WHERE short_name LIKE ? OR full_name LIKE ? ORDER BY id LIMIT 20");
    $utilizerStmt->execute(['%ФРЕГАТ%', '%ФРЕГАТ%']);
    $executorStmt = $pdo->prepare("SELECT cr.id AS crew_id,ct.name AS contractor_name,GROUP_CONCAT(d.full_name ORDER BY cd.position SEPARATOR ' / ') AS drivers FROM crews cr LEFT JOIN contractors ct ON ct.id=cr.contractor_id LEFT JOIN crew_drivers cd ON cd.crew_id=cr.id LEFT JOIN drivers d ON d.id=cd.driver_id WHERE ct.name LIKE ? OR d.full_name LIKE ? GROUP BY cr.id,ct.name ORDER BY cr.id LIMIT 30");
    $executorStmt->execute(['%Пинчук%', '%Пинчук%']);

    $prepareResults = [];
    foreach ($probeSql as $name => $sql) {
        $prepareResults[$name] = $prepareCheck($pdo, $sql);
    }

    $legacyState = WasteTripLegacySourceService::prefillState($pdo, $flight);
    $tenantResults[] = [
        'company_id' => $companyId,
        'company_name' => (string) $company['name'],
        'waste_trip_count' => (int) $pdo->query('SELECT COUNT(*) FROM waste_trips')->fetchColumn(),
        'legacy_244_business_collision' => (int) $pdo->query("SELECT COUNT(*) FROM waste_trips WHERE business_id=244 OR legacy_flight_id=244")->fetchColumn(),
        'missing_waste_trip_columns' => array_values(array_diff($expectedWasteColumns, $wasteColumns)),
        'missing_user_projection_columns' => array_values(array_diff($expectedUserColumns, $userColumns)),
        'waste_trip_indexes' => $indexRows($pdo, 'waste_trips'),
        'user_indexes' => $indexRows($pdo, 'users'),
        'central_target_membership_ids' => $membershipIds,
        'tenant_target_managers' => $tenantManagers,
        'legacy_244_prefill' => [
            'planned_start_mode' => $legacyState['planned_start_mode'],
            'planned_start_date' => $legacyState['planned_start_date'],
            'planned_start_date_from' => $legacyState['planned_start_date_from'],
            'planned_start_date_to' => $legacyState['planned_start_date_to'],
            'actual_start_date' => $legacyState['actual_start_date'],
            'actual_end_date' => $legacyState['actual_end_date'],
            'found_request_ids' => $legacyState['found_request_ids'],
            'missing_request_ids' => $legacyState['missing_request_ids'],
            'planned_start_incomplete' => $legacyState['planned_start_incomplete'],
        ],
        'legacy_244_feo_rows' => $feoRows,
        'fregat_clients' => $clientStmt->fetchAll(),
        'fregat_utilizers' => $utilizerStmt->fetchAll(),
        'pinchuk_executors' => $executorStmt->fetchAll(),
        'native_prepare_checks' => $prepareResults,
    ];
}

$out = [
    'mode' => 'READ_ONLY',
    'legacy_244' => [
        'id' => (int) $flight['id'],
        'request_ids' => $legacyRequestIds,
        'planned_start_date' => $flight['planned_start_date'] ?: null,
        'planned_start_date_from' => $flight['planned_start_date_from'] ?: null,
        'planned_start_date_to' => $flight['planned_start_date_to'] ?: null,
        'actual_start_date' => $flight['actual_start_date'] ?: null,
        'actual_end_date' => $flight['actual_end_date'] ?: null,
    ],
    'central_target_managers' => $centralManagers,
    'tenants' => $tenantResults,
];

echo 'WASTE_TRIP_244_READ_ONLY=' . json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
echo "WASTE_TRIP_244_READ_ONLY_PASS\n";
