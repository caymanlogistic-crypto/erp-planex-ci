<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'cgi-fcgi') {
    fwrite(STDERR, "CLI/CGI only.\n");
    exit(1);
}

$mode = $argv[1] ?? '--preview';
if (!in_array($mode, ['--preview', '--apply'], true)) {
    throw new RuntimeException('Use --preview or --apply');
}
$root = rtrim((string) (getenv('PLANEX_ROOT') ?: ($argv[2] ?? '')), '/');
if ($root === '' || !is_file($root . '/.env') || !is_file($root . '/bootstrap/app.php')) {
    throw new RuntimeException('PLANEX_ROOT invalid');
}

$config = require $root . '/bootstrap/app.php';
require_once $root . '/app/Support/crypto_helper.php';
require_once $root . '/app/Support/company_database.php';
require_once $root . '/app/Service/WasteTripLegacySourceService.php';

$migration = '130_waste_trip_legacy_linked_cutover.sql';
$sqlPath = (string) (getenv('PLANEX_TENANT_MIGRATION_PATH') ?: $root . '/database/migrations-local/' . $migration);
if (!is_file($sqlPath)) {
    throw new RuntimeException('Migration 130 SQL missing');
}
$sql = (string) file_get_contents($sqlPath);
$checksum = hash('sha256', $sql);
foreach (['ADD COLUMN `business_id`', 'ADD COLUMN `planned_finish_date`', 'ADD COLUMN `legacy_sync_error`', 'CREATE TABLE `waste_trip_namespace_state`'] as $token) {
    if (!str_contains($sql, $token)) {
        throw new RuntimeException('Migration 130 SQL contract missing ' . $token);
    }
}

$legacy = \App\Service\WasteTripLegacySourceService::connectionFromEnvironment();
if (!$legacy instanceof PDO) {
    throw new RuntimeException('Legacy flights DB not configured');
}
$freshHigh = \App\Service\WasteTripLegacySourceService::maxLegacyId($legacy);
if ($freshHigh < 243) {
    throw new RuntimeException('Legacy high-water is unexpectedly below 243');
}

$centralCfg = $config['database'];
$central = new PDO(
    'mysql:host=' . $centralCfg['host'] . ';port=' . ($centralCfg['port'] ?? 3306) . ';dbname=' . $centralCfg['database'] . ';charset=utf8mb4',
    $centralCfg['username'],
    $centralCfg['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
);
$companies = $central->query(
    "SELECT id,name,status,db_identifier,db_host,db_port,db_username,db_password\n"
    . "FROM companies WHERE status='active' AND db_identifier IS NOT NULL AND db_identifier<>'' ORDER BY id"
)->fetchAll(PDO::FETCH_ASSOC);
if ($companies === []) {
    throw new RuntimeException('No active tenant databases');
}

$tableExists = static function (PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $stmt->execute([$table]);
    return (int) $stmt->fetchColumn() === 1;
};
$columnMeta = static function (PDO $pdo, string $table, string $column): ?array {
    $stmt = $pdo->prepare('SELECT IS_NULLABLE,COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
    $stmt->execute([$table, $column]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
};
$indexExists = static function (PDO $pdo, string $table, string $index): bool {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?');
    $stmt->execute([$table, $index]);
    return (int) $stmt->fetchColumn() > 0;
};
$journal = static function (PDO $pdo, string $name) use ($tableExists): ?string {
    if (!$tableExists($pdo, 'schema_migrations')) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT checksum FROM schema_migrations WHERE migration=?');
    $stmt->execute([$name]);
    $value = $stmt->fetchColumn();
    return $value === false ? null : (string) $value;
};
$assertLegacyHighWaterStable = static function () use ($legacy, $freshHigh): void {
    $current = \App\Service\WasteTripLegacySourceService::maxLegacyId($legacy);
    if ($current !== $freshHigh) {
        throw new RuntimeException('Legacy flights MAX(id) changed during cutover: ' . $freshHigh . ' -> ' . $current);
    }
};

$allowedFkTables = ['waste_trip_requests', 'waste_trip_audit_log', 'waste_trip_legacy_import_audits'];
$inventory = [];
$tenants = [];
foreach ($companies as $company) {
    $cfg = companyDatabaseConfig($config, $company);
    $pdo = new PDO(
        'mysql:host=' . $cfg['host'] . ';port=' . ($cfg['port'] ?? 3306) . ';dbname=' . $cfg['database'] . ';charset=utf8mb4',
        $cfg['username'],
        $cfg['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
    );
    if ((string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== (string) $company['db_identifier']) {
        throw new RuntimeException('Unexpected tenant DB company ' . (int) $company['id']);
    }
    foreach (['schema_migrations', 'waste_trips', 'waste_trip_requests', 'waste_trip_audit_log'] as $requiredTable) {
        if (!$tableExists($pdo, $requiredTable)) {
            throw new RuntimeException('company ' . (int) $company['id'] . ': prerequisite table missing ' . $requiredTable);
        }
    }
    if ($journal($pdo, '128_create_waste_trips.sql') === null || $journal($pdo, '129_document_generator_auto_number_and_bank_inn.sql') === null) {
        throw new RuntimeException('company ' . (int) $company['id'] . ': migrations 128/129 prerequisite missing');
    }
    $existing130 = $journal($pdo, $migration);
    if ($existing130 !== null && !hash_equals($existing130, $checksum)) {
        throw new RuntimeException('company ' . (int) $company['id'] . ': migration 130 checksum mismatch');
    }

    $foreignKeys = $pdo->query(
        "SELECT TABLE_NAME,CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE\n"
        . "WHERE REFERENCED_TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME='waste_trips'\n"
        . "ORDER BY TABLE_NAME,CONSTRAINT_NAME"
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($foreignKeys as $fk) {
        if (!in_array((string) $fk['TABLE_NAME'], $allowedFkTables, true)) {
            throw new RuntimeException('company ' . (int) $company['id'] . ': unknown inbound FK ' . $fk['TABLE_NAME'] . '.' . $fk['CONSTRAINT_NAME']);
        }
    }

    $sources = $pdo->query("SELECT COALESCE(source_system,'<NULL>') source_system,COUNT(*) cnt FROM waste_trips GROUP BY source_system")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($sources as $source) {
        if (!in_array((string) $source['source_system'], ['<NULL>', 'legacy_flights'], true)) {
            throw new RuntimeException('company ' . (int) $company['id'] . ': unknown Waste Trips provenance ' . $source['source_system']);
        }
    }

    $nonLegacyImportAudit = (int) $pdo->query(
        "SELECT COUNT(*) FROM waste_trip_audit_log a\n"
        . "JOIN waste_trips wt ON wt.id=a.waste_trip_id\n"
        . "WHERE a.action='import' AND COALESCE(wt.source_system,'')<>'legacy_flights'"
    )->fetchColumn();
    if ($nonLegacyImportAudit !== 0) {
        throw new RuntimeException('company ' . (int) $company['id'] . ': import audit exists outside legacy_flights provenance');
    }

    $businessMeta = $columnMeta($pdo, 'waste_trips', 'business_id');
    $state = $tableExists($pdo, 'waste_trip_namespace_state')
        ? ($pdo->query('SELECT * FROM waste_trip_namespace_state WHERE singleton_id=1')->fetch(PDO::FETCH_ASSOC) ?: null)
        : null;
    if ($state && $state['cutover_completed_at'] !== null && (int) $state['legacy_high_water_at_cutover'] !== $freshHigh) {
        throw new RuntimeException('company ' . (int) $company['id'] . ': completed cutover namespace violation');
    }

    $inventory[] = [
        'company_id' => (int) $company['id'],
        'db' => (string) $company['db_identifier'],
        'legacy_high_water' => $freshHigh,
        'snapshot_rows' => (int) $pdo->query("SELECT COUNT(*) FROM waste_trips WHERE source_system='legacy_flights'")->fetchColumn(),
        'independent_rows' => (int) $pdo->query("SELECT COUNT(*) FROM waste_trips WHERE source_system IS NULL")->fetchColumn(),
        'max_business_id' => $businessMeta ? (int) $pdo->query('SELECT COALESCE(MAX(business_id),0) FROM waste_trips')->fetchColumn() : 0,
        'import_audit_table' => $tableExists($pdo, 'waste_trip_legacy_import_audits') ? 1 : 0,
        'inbound_fk' => $foreignKeys,
        'migration_130_checksum' => $existing130,
        'cutover_complete' => ($state && $state['cutover_completed_at'] !== null) ? 1 : 0,
    ];
    $tenants[] = ['company' => $company, 'pdo' => $pdo];
}

$assertLegacyHighWaterStable();
echo 'WASTE_130_PREVIEW=' . json_encode($inventory, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
echo "WASTE_130_PREVIEW_OK\n";
if ($mode === '--preview') {
    exit(0);
}
if ((string) getenv('PLANEX_WASTE_TRIPS_130_CONFIRM') !== 'APPLY_WASTE_TRIPS_130') {
    throw new RuntimeException('Missing APPLY_WASTE_TRIPS_130 confirmation');
}

foreach ($tenants as $item) {
    $company = $item['company'];
    $pdo = $item['pdo'];
    $companyId = (int) $company['id'];
    $assertLegacyHighWaterStable();

    if (!$columnMeta($pdo, 'waste_trips', 'business_id')) {
        $pdo->exec('ALTER TABLE waste_trips ADD COLUMN business_id BIGINT UNSIGNED NULL AFTER id');
    }
    if (!$columnMeta($pdo, 'waste_trips', 'planned_finish_date')) {
        $pdo->exec('ALTER TABLE waste_trips ADD COLUMN planned_finish_date DATE NULL AFTER planned_start_date_to');
    }
    if (!$columnMeta($pdo, 'waste_trips', 'legacy_sync_error')) {
        $pdo->exec('ALTER TABLE waste_trips ADD COLUMN legacy_sync_error VARCHAR(1000) NULL AFTER legacy_flight_id');
    }
    if (!$columnMeta($pdo, 'waste_trips', 'legacy_last_checked_at')) {
        $pdo->exec('ALTER TABLE waste_trips ADD COLUMN legacy_last_checked_at DATETIME NULL AFTER legacy_sync_error');
    }
    if (!$columnMeta($pdo, 'waste_trips', 'legacy_last_success_at')) {
        $pdo->exec('ALTER TABLE waste_trips ADD COLUMN legacy_last_success_at DATETIME NULL AFTER legacy_last_checked_at');
    }
    if (!$tableExists($pdo, 'waste_trip_namespace_state')) {
        $pdo->exec(
            "CREATE TABLE waste_trip_namespace_state (\n"
            . "singleton_id TINYINT UNSIGNED NOT NULL,\n"
            . "legacy_high_water_at_cutover BIGINT UNSIGNED NOT NULL,\n"
            . "last_issued_business_id BIGINT UNSIGNED NOT NULL,\n"
            . "cutover_started_at DATETIME NULL,cutover_completed_at DATETIME NULL,\n"
            . "created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n"
            . "updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,\n"
            . "PRIMARY KEY(singleton_id),\n"
            . "CONSTRAINT chk_waste_trip_namespace_singleton CHECK(singleton_id=1)\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    $state = $pdo->query('SELECT * FROM waste_trip_namespace_state WHERE singleton_id=1')->fetch(PDO::FETCH_ASSOC) ?: null;
    $alreadyComplete = $state && $state['cutover_completed_at'] !== null;
    if ($alreadyComplete) {
        if ((int) $state['legacy_high_water_at_cutover'] !== $freshHigh) {
            throw new RuntimeException('company ' . $companyId . ': completed cutover high-water no longer matches source; namespace violation');
        }
    } else {
        if ($state === null) {
            $maxBusiness = (int) $pdo->query('SELECT COALESCE(MAX(business_id),0) FROM waste_trips')->fetchColumn();
            $start = max($freshHigh, $maxBusiness);
            $stmt = $pdo->prepare('INSERT INTO waste_trip_namespace_state(singleton_id,legacy_high_water_at_cutover,last_issued_business_id,cutover_started_at) VALUES(1,?,?,NOW())');
            $stmt->execute([$freshHigh, $start]);
        } elseif ((int) $state['legacy_high_water_at_cutover'] !== $freshHigh) {
            throw new RuntimeException('company ' . $companyId . ': source high-water changed during unfinished cutover');
        }

        $pdo->beginTransaction();
        try {
            $snapshotIds = $pdo->query("SELECT id FROM waste_trips WHERE source_system='legacy_flights' ORDER BY id FOR UPDATE")->fetchAll(PDO::FETCH_COLUMN);
            if ($snapshotIds !== []) {
                $marks = implode(',', array_fill(0, count($snapshotIds), '?'));
                $deleteRequests = $pdo->prepare("DELETE FROM waste_trip_requests WHERE waste_trip_id IN ({$marks})");
                $deleteRequests->execute($snapshotIds);
                $deleteAudit = $pdo->prepare("DELETE FROM waste_trip_audit_log WHERE waste_trip_id IN ({$marks})");
                $deleteAudit->execute($snapshotIds);
                if ($tableExists($pdo, 'waste_trip_legacy_import_audits')) {
                    $deleteImportAudit = $pdo->prepare("DELETE FROM waste_trip_legacy_import_audits WHERE waste_trip_id IN ({$marks})");
                    $deleteImportAudit->execute($snapshotIds);
                }
                $deleteTrips = $pdo->prepare("DELETE FROM waste_trips WHERE id IN ({$marks}) AND source_system='legacy_flights'");
                $deleteTrips->execute($snapshotIds);
                if ($deleteTrips->rowCount() !== count($snapshotIds)) {
                    throw new RuntimeException('snapshot provenance delete mismatch');
                }
            }

            $stateRow = $pdo->query('SELECT last_issued_business_id FROM waste_trip_namespace_state WHERE singleton_id=1 FOR UPDATE')->fetch(PDO::FETCH_ASSOC);
            if (!$stateRow) {
                throw new RuntimeException('namespace state missing during backfill');
            }
            $sequence = max(
                $freshHigh,
                (int) $stateRow['last_issued_business_id'],
                (int) $pdo->query('SELECT COALESCE(MAX(business_id),0) FROM waste_trips')->fetchColumn()
            );
            $ids = $pdo->query('SELECT id FROM waste_trips WHERE source_system IS NULL AND business_id IS NULL ORDER BY id FOR UPDATE')->fetchAll(PDO::FETCH_COLUMN);
            $backfill = $pdo->prepare('UPDATE waste_trips SET business_id=? WHERE id=? AND business_id IS NULL');
            foreach ($ids as $id) {
                $sequence++;
                $backfill->execute([$sequence, (int) $id]);
                if ($backfill->rowCount() !== 1) {
                    throw new RuntimeException('business ID backfill conflict');
                }
            }
            $pdo->prepare('UPDATE waste_trip_namespace_state SET last_issued_business_id=?,updated_at=NOW() WHERE singleton_id=1')->execute([$sequence]);
            if ((int) $pdo->query('SELECT COUNT(*) FROM waste_trips WHERE business_id IS NULL')->fetchColumn() !== 0) {
                throw new RuntimeException('business_id backfill incomplete');
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    $meta = $columnMeta($pdo, 'waste_trips', 'business_id');
    if (($meta['IS_NULLABLE'] ?? 'YES') !== 'NO') {
        $pdo->exec('ALTER TABLE waste_trips MODIFY business_id BIGINT UNSIGNED NOT NULL');
    }
    if (!$indexExists($pdo, 'waste_trips', 'uk_waste_trip_business_id')) {
        $pdo->exec('ALTER TABLE waste_trips ADD UNIQUE KEY uk_waste_trip_business_id (business_id)');
    }
    if ($tableExists($pdo, 'waste_trip_legacy_import_audits')) {
        $pdo->exec('DROP TABLE waste_trip_legacy_import_audits');
    }
    if ((int) $pdo->query("SELECT COUNT(*) FROM waste_trip_audit_log WHERE action='import'")->fetchColumn() !== 0) {
        throw new RuntimeException('company ' . $companyId . ': legacy import audit actions remain after provenance cleanup');
    }
    $pdo->exec("ALTER TABLE waste_trip_audit_log MODIFY action ENUM('create','update','composition_correction','cancel') NOT NULL");

    if ((int) $pdo->query("SELECT COUNT(*) FROM waste_trips WHERE source_system='legacy_flights'")->fetchColumn() !== 0) {
        throw new RuntimeException('legacy snapshot rows remain');
    }
    if ((int) $pdo->query('SELECT COUNT(*) FROM waste_trips WHERE business_id IS NULL')->fetchColumn() !== 0) {
        throw new RuntimeException('business id null remains');
    }
    $assertLegacyHighWaterStable();
    $pdo->prepare('UPDATE waste_trip_namespace_state SET cutover_completed_at=COALESCE(cutover_completed_at,NOW()),updated_at=NOW() WHERE singleton_id=1')->execute();
    if ($journal($pdo, $migration) === null) {
        $pdo->prepare('INSERT INTO schema_migrations(migration,checksum) VALUES(?,?)')->execute([$migration, $checksum]);
    }
    echo 'company=' . $companyId . " WASTE_130_TENANT_OK\n";
}

$assertLegacyHighWaterStable();
echo "WASTE_130_OK\n";
