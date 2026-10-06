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

$legacyId = (int) (getenv('PLANEX_WASTE_TRIP_LEGACY_ID') ?: 246);
$companyId = (int) (getenv('PLANEX_WASTE_TRIP_COMPANY_ID') ?: 25);
if ($legacyId < 244 || $legacyId > 1000000000 || $companyId <= 0) {
    throw new RuntimeException('Probe identifiers invalid');
}

$config = require $root . '/bootstrap/app.php';
require_once $root . '/app/Support/crypto_helper.php';
require_once $root . '/app/Support/company_database.php';
require_once $root . '/app/Service/WasteTripLegacySourceService.php';

use App\Service\WasteTripLegacySourceService;

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

$centralCfg = $config['database'];
$central = new PDO(
    'mysql:host=' . $centralCfg['host'] . ';port=' . ($centralCfg['port'] ?? 3306) . ';dbname=' . $centralCfg['database'] . ';charset=utf8mb4',
    $centralCfg['username'],
    $centralCfg['password'],
    $options
);
$central->exec('SET SESSION TRANSACTION READ ONLY');

$companyStmt = $central->prepare(
    "SELECT id,name,status,db_identifier,db_host,db_port,db_username,db_password
       FROM companies
      WHERE id=? AND status='active' AND db_identifier IS NOT NULL AND db_identifier<>''
      LIMIT 1"
);
$companyStmt->execute([$companyId]);
$company = $companyStmt->fetch();
if (!is_array($company)) {
    throw new RuntimeException('Target company unavailable');
}

$tenantCfg = companyDatabaseConfig($config, $company);
$tenant = new PDO(
    'mysql:host=' . $tenantCfg['host'] . ';port=' . ($tenantCfg['port'] ?? 3306) . ';dbname=' . $tenantCfg['database'] . ';charset=utf8mb4',
    $tenantCfg['username'],
    $tenantCfg['password'],
    $options
);
$tenant->exec('SET SESSION TRANSACTION READ ONLY');

$legacy = WasteTripLegacySourceService::connectionFromEnvironment();
if (!$legacy instanceof PDO) {
    throw new RuntimeException('Legacy flights DB unavailable');
}
$legacy->exec('SET SESSION TRANSACTION READ ONLY');
$flight = WasteTripLegacySourceService::fetchFlight($legacy, $legacyId);
if (!is_array($flight)) {
    throw new RuntimeException('Legacy flight unavailable');
}

$membershipsStmt = $central->prepare(
    "SELECT m.id AS membership_id,m.status AS membership_status,
            a.id AS account_id,a.full_name,a.login,a.status AS account_status,
            r.system_key
       FROM company_memberships m
       JOIN user_accounts a ON a.id=m.account_id
       JOIN company_roles r ON r.id=m.role_id AND r.company_id=m.company_id
      WHERE m.company_id=? AND m.status='active' AND a.status='active'
      ORDER BY a.full_name,m.id"
);
$membershipsStmt->execute([$companyId]);
$memberships = $membershipsStmt->fetchAll();

$indexStmt = $tenant->prepare(
    "SELECT INDEX_NAME,NON_UNIQUE,GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS columns_csv
       FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users'
      GROUP BY INDEX_NAME,NON_UNIQUE
      ORDER BY INDEX_NAME"
);
$indexStmt->execute();
$userIndexes = $indexStmt->fetchAll();
$uniqueLogin = false;
foreach ($userIndexes as $index) {
    if ((int) $index['NON_UNIQUE'] === 0 && (string) $index['columns_csv'] === 'login') {
        $uniqueLogin = true;
        break;
    }
}

$sanitizeTenantRows = static function (array $rows): array {
    return array_map(static fn(array $row): array => [
        'tenant_user_id' => (int) $row['id'],
        'membership_id' => $row['membership_id'] === null ? null : (int) $row['membership_id'],
        'full_name' => (string) $row['full_name'],
        'login_sha12' => substr(hash('sha256', (string) $row['login']), 0, 12),
        'role_code' => (string) $row['role_code'],
        'status' => (string) $row['status'],
        'deleted' => $row['deleted_at'] !== null,
    ], $rows);
};

$projection = [];
foreach ($memberships as $membership) {
    $membershipId = (int) $membership['membership_id'];
    $login = (string) $membership['login'];

    $byMembershipStmt = $tenant->prepare(
        'SELECT id,membership_id,full_name,login,role_code,status,deleted_at FROM users WHERE membership_id=? ORDER BY id'
    );
    $byMembershipStmt->execute([$membershipId]);
    $byMembership = $byMembershipStmt->fetchAll();

    $byLoginStmt = $tenant->prepare(
        'SELECT id,membership_id,full_name,login,role_code,status,deleted_at FROM users WHERE login=? ORDER BY id'
    );
    $byLoginStmt->execute([$login]);
    $byLogin = $byLoginStmt->fetchAll();
    $activeLoginRows = array_values(array_filter($byLogin, static fn(array $row): bool => $row['deleted_at'] === null));

    $resolverPath = 'insert_new_user';
    $currentResolverFailure = null;
    if ($byMembership !== []) {
        $resolverPath = 'existing_membership';
    } elseif (count($activeLoginRows) > 1) {
        $resolverPath = 'ambiguous_active_login';
        $currentResolverFailure = 'multiple_active_login_rows';
    } elseif (count($activeLoginRows) === 1) {
        $projected = (int) ($activeLoginRows[0]['membership_id'] ?? 0);
        if ($projected > 0 && $projected !== $membershipId) {
            $resolverPath = 'active_login_foreign_membership';
            $currentResolverFailure = 'tenant_login_bound_to_other_membership';
        } else {
            $resolverPath = 'adopt_active_login';
        }
    } elseif ($uniqueLogin && $byLogin !== []) {
        $currentResolverFailure = 'unique_login_row_hidden_by_deleted_filter';
    }

    $projection[] = [
        'membership_id' => $membershipId,
        'full_name' => (string) $membership['full_name'],
        'account_id' => (int) $membership['account_id'],
        'system_key' => $membership['system_key'] === null ? null : (string) $membership['system_key'],
        'login_sha12' => substr(hash('sha256', $login), 0, 12),
        'resolver_path' => $resolverPath,
        'current_resolver_failure' => $currentResolverFailure,
        'membership_rows' => $sanitizeTenantRows($byMembership),
        'login_rows' => $sanitizeTenantRows($byLogin),
    ];
}

$requestIds = preg_split('/[\s,;]+/u', trim((string) ($flight['zayavki_ids'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
$requestIds = array_values(array_unique(array_map('strval', $requestIds)));
$prefill = WasteTripLegacySourceService::prefillState($tenant, $flight);

$encodingFacts = static function (mixed $value): array {
    if ($value === null) return ['is_null'=>true,'bytes'=>0,'utf8_valid'=>true,'sha256'=>null,'json_encodable'=>true,'json_error'=>null];
    $raw = (string) $value;
    $valid = function_exists('mb_check_encoding') ? mb_check_encoding($raw, 'UTF-8') : preg_match('//u', $raw) === 1;
    try {
        json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $jsonOk = true; $jsonError = null;
    } catch (JsonException $e) {
        $jsonOk = false; $jsonError = $e->getMessage();
    }
    return ['is_null'=>false,'bytes'=>strlen($raw),'utf8_valid'=>$valid,'sha256'=>hash('sha256',$raw),'json_encodable'=>$jsonOk,'json_error'=>$jsonError];
};
$legacyEncoding = [
    'comment' => $encodingFacts($flight['comment'] ?? null),
    'zayavki_ids' => $encodingFacts($flight['zayavki_ids'] ?? null),
];
$prefillEncoding = [];
foreach (['comment','planned_start_mode','planned_start_date','planned_start_date_from','planned_start_date_to','planned_finish_date','actual_start_date','actual_end_date'] as $key) {
    $prefillEncoding[$key] = $encodingFacts($prefill[$key] ?? null);
}
try {
    json_encode($prefill, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $prefillJson = ['encodable'=>true,'error'=>null];
} catch (JsonException $e) {
    $prefillJson = ['encodable'=>false,'error'=>$e->getMessage()];
}

$feoRows = [];
if ($requestIds !== []) {
    $marks = implode(',', array_fill(0, count($requestIds), '?'));
    $feoStmt = $tenant->prepare("SELECT id,zayavka_id,mass_netto FROM feo_requests WHERE zayavka_id IN ({$marks}) ORDER BY zayavka_id");
    $feoStmt->execute($requestIds);
    foreach ($feoStmt->fetchAll() as $row) {
        $claimStmt = $tenant->prepare(
            "SELECT wt.business_id,wt.status
               FROM waste_trip_requests wtr
               JOIN waste_trips wt ON wt.id=wtr.waste_trip_id
              WHERE wtr.feo_request_id=? AND wtr.is_active=1 AND wt.status<>'cancelled'
              ORDER BY wt.id LIMIT 5"
        );
        $claimStmt->execute([(int) $row['id']]);
        $feoRows[] = [
            'feo_request_id' => (int) $row['id'],
            'zayavka_id' => (string) $row['zayavka_id'],
            'mass_netto' => $row['mass_netto'] === null ? null : (string) $row['mass_netto'],
            'active_claims' => $claimStmt->fetchAll(),
        ];
    }
}

$collisionStmt = $tenant->prepare('SELECT id,business_id,source_system,legacy_flight_id,status FROM waste_trips WHERE business_id=? OR legacy_flight_id=? ORDER BY id');
$collisionStmt->execute([$legacyId, $legacyId]);

$columnsStmt = $tenant->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='waste_trips' ORDER BY ORDINAL_POSITION");
$columnsStmt->execute();
$wasteColumns = array_map('strval', $columnsStmt->fetchAll(PDO::FETCH_COLUMN));
$requiredWasteColumns = [
    'id','business_id','client_id','route_executor_id','responsible_user_id','destination_type','utilizer_id','status',
    'planned_start_mode','planned_start_date','planned_start_date_from','planned_start_date_to','planned_finish_date','actual_start_date','actual_end_date',
    'comment','cancellation_reason','source_system','legacy_flight_id','created_by_user_id','created_by_membership_id','created_by_role','updated_by_user_id','updated_by_role',
];

$migrationStmt = $tenant->prepare('SELECT migration FROM schema_migrations WHERE migration IN (?,?) ORDER BY migration');
$migrationStmt->execute(['131_create_roles_access_projection.sql', '132_split_assignment_and_responsibility.sql']);

$out = [
    'mode' => 'READ_ONLY',
    'company_id' => $companyId,
    'company_name' => (string) $company['name'],
    'legacy' => [
        'id' => (int) $flight['id'],
        'request_ids' => $requestIds,
        'planned_start_date' => $flight['planned_start_date'] ?: null,
        'planned_start_date_from' => $flight['planned_start_date_from'] ?: null,
        'planned_start_date_to' => $flight['planned_start_date_to'] ?: null,
        'actual_start_date' => $flight['actual_start_date'] ?: null,
        'actual_end_date' => $flight['actual_end_date'] ?: null,
    ],
    'prefill' => [
        'planned_start_mode' => $prefill['planned_start_mode'],
        'planned_start_date' => $prefill['planned_start_date'],
        'planned_start_date_from' => $prefill['planned_start_date_from'],
        'planned_start_date_to' => $prefill['planned_start_date_to'],
        'actual_start_date' => $prefill['actual_start_date'],
        'actual_end_date' => $prefill['actual_end_date'],
        'found_request_ids' => $prefill['found_request_ids'],
        'missing_request_ids' => $prefill['missing_request_ids'],
        'planned_start_incomplete' => $prefill['planned_start_incomplete'],
    ],
    'manager_projection' => $projection,
    'legacy_encoding' => $legacyEncoding,
    'prefill_encoding' => $prefillEncoding,
    'prefill_json' => $prefillJson,
    'users_login_unique' => $uniqueLogin,
    'legacy_collision_rows' => $collisionStmt->fetchAll(),
    'feo_rows' => $feoRows,
    'missing_waste_trip_columns' => array_values(array_diff($requiredWasteColumns, $wasteColumns)),
    'applied_late_migrations' => array_values(array_map('strval', $migrationStmt->fetchAll(PDO::FETCH_COLUMN))),
    'runtime' => [
        'php_version' => PHP_VERSION,
        'php_sapi' => PHP_SAPI,
        'mysql_version' => (string) $tenant->query('SELECT VERSION()')->fetchColumn(),
        'sql_mode' => (string) $tenant->query('SELECT @@SESSION.sql_mode')->fetchColumn(),
        'pdo_emulate_prepares' => (int) $tenant->getAttribute(PDO::ATTR_EMULATE_PREPARES),
    ],
];

echo 'WASTE_TRIP_LEGACY_READ_ONLY=' . json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
echo "WASTE_TRIP_LEGACY_READ_ONLY_PASS\n";
