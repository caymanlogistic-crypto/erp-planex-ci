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
$migrationPath = (string) (getenv('PLANEX_TENANT_MIGRATION_PATH') ?: ($argv[2] ?? ($root . '/database/migrations-local/128_create_waste_trips.sql')));
if (!is_file($migrationPath)) {
    throw new RuntimeException('Waste Trips migration file missing');
}
$sql = file_get_contents($migrationPath);
if (!is_string($sql) || trim($sql) === '') {
    throw new RuntimeException('Waste Trips migration file empty');
}
$migrationName = '128_create_waste_trips.sql';
$checksum = hash('sha256', $sql);

$config = require $root . '/bootstrap/app.php';
require_once $root . '/app/Support/crypto_helper.php';
require_once $root . '/app/Support/company_database.php';

$centralCfg = $config['database'];
$central = new PDO(
    'mysql:host=' . $centralCfg['host'] . ';port=' . ($centralCfg['port'] ?? 3306) . ';dbname=' . $centralCfg['database'] . ';charset=utf8mb4',
    $centralCfg['username'],
    $centralCfg['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
);

$tableExists = static function (PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute([$table]);
    return (int) $stmt->fetchColumn() === 1;
};
$columnCount = static function (PDO $pdo, string $table, array $columns): int {
    $marks = implode(',', array_fill(0, count($columns), '?'));
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME IN (' . $marks . ')');
    $stmt->execute(array_merge([$table], $columns));
    return (int) $stmt->fetchColumn();
};
$indexExists = static function (PDO $pdo, string $table, string $index): bool {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
    $stmt->execute([$table, $index]);
    return (int) $stmt->fetchColumn() > 0;
};
$fkExists = static function (PDO $pdo, string $table, string $constraint, string $referenced): bool {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS '
        . 'WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND REFERENCED_TABLE_NAME = ?'
    );
    $stmt->execute([$table, $constraint, $referenced]);
    return (int) $stmt->fetchColumn() === 1;
};
$splitStatements = static function (string $source): array {
    $statements = [];
    $buffer = '';
    $quote = null;
    $lineComment = false;
    $blockComment = false;
    $length = strlen($source);
    for ($i = 0; $i < $length; $i++) {
        $ch = $source[$i];
        $next = $i + 1 < $length ? $source[$i + 1] : '';
        if ($lineComment) {
            if ($ch === "\n") { $lineComment = false; $buffer .= $ch; }
            continue;
        }
        if ($blockComment) {
            if ($ch === '*' && $next === '/') { $blockComment = false; $i++; }
            continue;
        }
        if ($quote !== null) {
            $buffer .= $ch;
            if ($ch === '\\' && $quote !== '`' && $i + 1 < $length) { $buffer .= $source[++$i]; continue; }
            if ($ch === $quote) {
                if ($next === $quote) { $buffer .= $next; $i++; } else { $quote = null; }
            }
            continue;
        }
        if ($ch === '-' && $next === '-' && ($i === 0 || ctype_space($source[$i - 1]))) { $lineComment = true; $i++; continue; }
        if ($ch === '#') { $lineComment = true; continue; }
        if ($ch === '/' && $next === '*') { $blockComment = true; $i++; continue; }
        if ($ch === "'" || $ch === '"' || $ch === '`') { $quote = $ch; $buffer .= $ch; continue; }
        if ($ch === ';') {
            $statement = trim($buffer);
            if ($statement !== '') { $statements[] = $statement; }
            $buffer = '';
            continue;
        }
        $buffer .= $ch;
    }
    $tail = trim($buffer);
    if ($tail !== '') { $statements[] = $tail; }
    return $statements;
};
$statements = $splitStatements($sql);
if (count($statements) !== 4) {
    throw new RuntimeException('Waste Trips migration statement split failed: expected 4 statements');
}

$companies = $central->query(
    "SELECT id,name,status,db_identifier,db_host,db_port,db_username,db_password\n"
    . "FROM companies WHERE status='active' AND db_identifier IS NOT NULL AND db_identifier<>'' ORDER BY id"
)->fetchAll(PDO::FETCH_ASSOC);
if ($companies === []) {
    throw new RuntimeException('No active tenant databases found');
}

$applied = 0;
$existing = 0;
foreach ($companies as $company) {
    $cfg = companyDatabaseConfig($config, $company);
    $pdo = new PDO(
        'mysql:host=' . $cfg['host'] . ';port=' . ($cfg['port'] ?? 3306) . ';dbname=' . $cfg['database'] . ';charset=utf8mb4',
        $cfg['username'],
        $cfg['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
    );
    if ((string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== (string) $company['db_identifier']) {
        throw new RuntimeException('Unexpected tenant DB for company ' . $company['id']);
    }
    foreach (['schema_migrations','clients','users','crews','feo_requests','feo_utilizers'] as $requiredTable) {
        if (!$tableExists($pdo, $requiredTable)) {
            throw new RuntimeException('Tenant prerequisite table missing for company ' . $company['id'] . ': ' . $requiredTable);
        }
    }
    $prereq = $pdo->prepare('SELECT COUNT(*) FROM schema_migrations WHERE migration = ?');
    $prereq->execute(['127_create_document_generator.sql']);
    if ((int) $prereq->fetchColumn() !== 1) {
        throw new RuntimeException('Migration 127 journal prerequisite missing for company ' . $company['id']);
    }

    $lockName = 'planex_waste_128_' . substr(hash('sha256', (string) $company['db_identifier']), 0, 20);
    $lock = $pdo->prepare('SELECT GET_LOCK(?, 10)');
    $lock->execute([$lockName]);
    if ((int) $lock->fetchColumn() !== 1) {
        throw new RuntimeException('Tenant lock failed for company ' . $company['id']);
    }
    try {
        $journal = $pdo->prepare('SELECT checksum FROM schema_migrations WHERE migration = ?');
        $journal->execute([$migrationName]);
        $stored = $journal->fetchColumn();
        if ($stored !== false) {
            if (!hash_equals((string) $stored, $checksum)) {
                throw new RuntimeException('Migration 128 checksum mismatch for company ' . $company['id']);
            }
            $state = 'existing';
            $existing++;
        } else {
            $newer = (int) $pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE migration REGEXP '^[0-9]{3}_' AND CAST(LEFT(migration,3) AS UNSIGNED) > 128")->fetchColumn();
            if ($newer > 0) {
                throw new RuntimeException('Newer tenant migration exists for company ' . $company['id']);
            }
            foreach (['waste_trips','waste_trip_requests','waste_trip_legacy_import_audits','waste_trip_audit_log'] as $table) {
                if ($tableExists($pdo, $table)) {
                    throw new RuntimeException('Unjournaled Waste Trips schema exists for company ' . $company['id'] . ': ' . $table);
                }
            }
            foreach ($statements as $statement) {
                $pdo->exec($statement);
            }
            $insert = $pdo->prepare('INSERT INTO schema_migrations(migration, checksum) VALUES(?, ?)');
            $insert->execute([$migrationName, $checksum]);
            $state = 'applied';
            $applied++;
        }

        $expectedColumns = [
            'waste_trips' => ['id','client_id','route_executor_id','responsible_user_id','destination_type','utilizer_id','status','planned_start_mode','planned_start_date','planned_start_date_from','planned_start_date_to','actual_start_date','actual_end_date','comment','cancellation_reason','cancelled_at','cancelled_by_user_id','cancelled_by_role','source_system','legacy_flight_id','lock_version','created_by_user_id','created_by_role','updated_by_user_id','updated_by_role','created_at','updated_at'],
            'waste_trip_requests' => ['id','waste_trip_id','feo_request_id','snapshot_mass_kg','position_no','created_at'],
            'waste_trip_legacy_import_audits' => ['id','waste_trip_id','source_system','legacy_flight_id','legacy_payload_json','mapping_json','snapshot_request_count','snapshot_total_mass_kg','mass_delta_kg','warnings_json','legacy_total_mass_kg','legacy_cost','legacy_payment_terms','imported_by_user_id','imported_by_role','created_at'],
            'waste_trip_audit_log' => ['id','waste_trip_id','action','before_json','after_json','actor_user_id','actor_role','created_at'],
        ];
        foreach ($expectedColumns as $table => $columns) {
            if (!$tableExists($pdo, $table) || $columnCount($pdo, $table, $columns) !== count($columns)) {
                throw new RuntimeException('Migration 128 table/column verification failed for ' . $table . ' company ' . $company['id']);
            }
        }
        foreach ([
            ['waste_trips','uk_waste_trip_legacy_identity'],
            ['waste_trip_requests','uk_waste_trip_request'],
            ['waste_trip_legacy_import_audits','uk_waste_trip_legacy_audit_identity'],
            ['waste_trip_audit_log','idx_waste_trip_audit_trip'],
        ] as [$table, $index]) {
            if (!$indexExists($pdo, $table, $index)) {
                throw new RuntimeException('Migration 128 index verification failed for company ' . $company['id'] . ': ' . $index);
            }
        }
        foreach ([
            ['waste_trips','fk_waste_trips_client','clients'],
            ['waste_trips','fk_waste_trips_executor','crews'],
            ['waste_trips','fk_waste_trips_responsible','users'],
            ['waste_trips','fk_waste_trips_utilizer','feo_utilizers'],
            ['waste_trip_requests','fk_waste_trip_requests_trip','waste_trips'],
            ['waste_trip_requests','fk_waste_trip_requests_feo','feo_requests'],
            ['waste_trip_legacy_import_audits','fk_waste_trip_legacy_audit_trip','waste_trips'],
            ['waste_trip_audit_log','fk_waste_trip_audit_trip','waste_trips'],
        ] as [$table, $constraint, $referenced]) {
            if (!$fkExists($pdo, $table, $constraint, $referenced)) {
                throw new RuntimeException('Migration 128 FK verification failed for company ' . $company['id'] . ': ' . $constraint);
            }
        }
        printf("WASTE_128_COMPANY_OK id=%d db=%s state=%s\n", (int) $company['id'], $company['db_identifier'], $state);
    } finally {
        $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $release->execute([$lockName]);
    }
}

printf("WASTE_128_OK tenants=%d applied=%d existing=%d checksum=%s\n", count($companies), $applied, $existing, $checksum);
