<?php
declare(strict_types=1);

// Production control-plane entry point. Inspection is read-only by default.
if (PHP_SAPI !== 'cli') {
    exit(1);
}

try {
    $root = rtrim((string) getenv('PLANEX_ROOT'), '/');
    $sourceSha = (string) getenv('PLANEX_SOURCE_SHA');
    $expectedHash = (string) getenv('PLANEX_MIGRATION_SHA256');
    $marker = (string) (getenv('PLANEX_DEPLOY_MARKER') ?: '/home/s/spugovxsim/planexp/deploy/P07_erpv2_deployed_commit.txt');
    if (!is_file($root . '/.env') || !is_file($root . '/bootstrap/app.php')
        || !preg_match('/^[a-f0-9]{40}$/D', $sourceSha)
        || !preg_match('/^[a-f0-9]{64}$/D', $expectedHash)
        || trim((string) file_get_contents($marker)) !== $sourceSha) {
        throw new RuntimeException('Exact deployed source guard failed');
    }

    $name = '149_waste_trip_legacy_metadata.sql';
    $sql = (string) file_get_contents($root . '/database/migrations-local/' . $name);
    if (!hash_equals($expectedHash, hash('sha256', $sql))) {
        throw new RuntimeException('Migration SQL checksum mismatch');
    }
    $columns = [
        'legal_entity_code', 'legacy_route_type', 'legacy_unload_type',
        'legacy_source_warehouse_id', 'legacy_destination_warehouse_id',
        'legacy_source_warehouse_name', 'legacy_destination_warehouse_name',
    ];
    if (!preg_match('/^ALTER TABLE waste_trips\s+ADD COLUMN legal_entity_code VARCHAR\(32\) NULL,/i', trim($sql))
        || substr_count(strtoupper($sql), 'ADD COLUMN') !== count($columns)
        || substr_count($sql, ';') !== 1
        || !str_ends_with(trim($sql), ';')) {
        throw new RuntimeException('Unexpected migration SQL scope');
    }

    $apply = getenv('WASTE_TRIP_149_MODE') === 'apply';
    if ($apply && getenv('WASTE_TRIP_149_CONFIRM') !== 'APPLY_WASTE_TRIP_149') {
        throw new RuntimeException('Explicit migration confirmation missing');
    }

    $config = require $root . '/bootstrap/app.php';
    require_once $root . '/app/Support/crypto_helper.php';
    require_once $root . '/app/Support/company_database.php';
    $connect = static fn (array $db): PDO => new PDO(
        'mysql:host=' . $db['host'] . ';port=' . ($db['port'] ?? 3306) . ';dbname=' . $db['database'] . ';charset=utf8mb4',
        $db['username'], $db['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
    );
    $central = $connect($config['database']);
    $companies = $central->query("SELECT * FROM companies WHERE status='active' AND db_identifier IS NOT NULL AND db_identifier<>'' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    if (!$companies) {
        throw new RuntimeException('No active tenants');
    }

    $results = [];
    foreach ($companies as $company) {
        $pdo = $connect(companyDatabaseConfig($config, $company));
        $database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
        if ($database !== (string) $company['db_identifier']) {
            throw new RuntimeException('Wrong tenant database');
        }
        $lock = 'planex_migrate_' . substr(hash('sha256', $database), 0, 32);
        $query = $pdo->prepare('SELECT GET_LOCK(?,10)');
        $query->execute([$lock]);
        if ((int) $query->fetchColumn() !== 1) {
            throw new RuntimeException('Migration lock unavailable');
        }
        try {
            $query = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
            foreach (['schema_migrations', 'waste_trips'] as $table) {
                $query->execute([$table]);
                if ((int) $query->fetchColumn() !== 1) {
                    throw new RuntimeException('Missing tenant table: ' . $table);
                }
            }
            $journal = $pdo->prepare('SELECT checksum FROM schema_migrations WHERE migration=?');
            $journal->execute(['148_create_registration_journal.sql']);
            if ($journal->fetchColumn() === false) {
                throw new RuntimeException('Migration 148 prerequisite missing');
            }
            $journal->execute([$name]);
            $stored = $journal->fetchColumn();
            if ($stored !== false && !hash_equals($expectedHash, (string) $stored)) {
                throw new RuntimeException('Migration 149 journal checksum mismatch');
            }
            $query = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='waste_trips' AND COLUMN_NAME IN ('legal_entity_code','legacy_route_type','legacy_unload_type','legacy_source_warehouse_id','legacy_destination_warehouse_id','legacy_source_warehouse_name','legacy_destination_warehouse_name') ORDER BY ORDINAL_POSITION");
            $query->execute();
            $present = $query->fetchAll(PDO::FETCH_COLUMN);
            if ($stored === false && $present !== []) {
                throw new RuntimeException('Unjournaled or partial migration 149 schema');
            }
            if ($stored !== false && $present !== $columns) {
                throw new RuntimeException('Incomplete migration 149 schema');
            }
            $beforeRows = (int) $pdo->query('SELECT COUNT(*) FROM waste_trips')->fetchColumn();
            if ($apply && $stored === false) {
                if ((int) $pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE migration REGEXP '^[0-9]{3}_' AND CAST(LEFT(migration,3) AS UNSIGNED)>149")->fetchColumn() > 0) {
                    throw new RuntimeException('Newer tenant migration exists');
                }
                $pdo->exec($sql);
                $pdo->prepare('INSERT INTO schema_migrations(migration,checksum) VALUES(?,?)')->execute([$name, $expectedHash]);
                $stored = $expectedHash;
                $query->execute();
                if ($query->fetchAll(PDO::FETCH_COLUMN) !== $columns) {
                    throw new RuntimeException('Migration 149 column verification failed');
                }
            }
            $afterRows = (int) $pdo->query('SELECT COUNT(*) FROM waste_trips')->fetchColumn();
            $results[] = ['company_id' => (int) $company['id'], 'ready' => $stored !== false, 'trip_rows_before' => $beforeRows, 'trip_rows_after' => $afterRows];
        } finally {
            $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);
        }
    }
    echo 'WASTE_TRIP_149_SNAPSHOT=' . json_encode($results, JSON_THROW_ON_ERROR) . "\n";
    echo $apply ? "WASTE_TRIP_149_APPLY_OK\n" : "WASTE_TRIP_149_READ_ONLY_OK\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'WASTE_TRIP_149_FAILED: ' . $error->getMessage() . "\n");
    exit(1);
}
