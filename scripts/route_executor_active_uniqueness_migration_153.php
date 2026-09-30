<?php
declare(strict_types=1);

// Read-only by default. Apply requires the exact deployed commit and a separate owner confirmation.
if (PHP_SAPI !== 'cli') exit(1);
try {
    $root = rtrim((string) getenv('PLANEX_ROOT'), '/');
    $sha = (string) getenv('PLANEX_SOURCE_SHA');
    $marker = (string) getenv('PLANEX_DEPLOY_MARKER');
    if (!is_file($root . '/.env') || !is_file($root . '/bootstrap/app.php') || !is_file($marker)
        || !preg_match('/^[a-f0-9]{40}$/D', $sha) || trim((string) file_get_contents($marker)) !== $sha) {
        throw new RuntimeException('Exact deployment guard required');
    }
    $name = '153_crew_active_uniqueness.sql';
    $sql = (string) file_get_contents($root . '/database/migrations-local/' . $name);
    $checksum = hash('sha256', $sql);
    if (!hash_equals((string) getenv('PLANEX_TENANT_SHA256'), $checksum)
        || !str_contains($sql, 'ADD COLUMN active_crew_key')
        || !str_contains($sql, 'ADD UNIQUE KEY uk_crew (contractor_id, driver_vehicle_block_id, active_crew_key)')) {
        throw new RuntimeException('Migration checksum or SQL scope mismatch');
    }
    $apply = getenv('ROUTE_EXECUTOR_153_MODE') === 'apply';
    if ($apply && getenv('ROUTE_EXECUTOR_153_CONFIRM') !== 'APPLY_ROUTE_EXECUTOR_153') {
        throw new RuntimeException('Explicit migration confirmation required');
    }
    $config = require $root . '/bootstrap/app.php';
    require_once $root . '/app/Support/crypto_helper.php';
    require_once $root . '/app/Support/company_database.php';
    $connect = static fn(array $db): PDO => new PDO(
        'mysql:host=' . $db['host'] . ';port=' . ($db['port'] ?? 3306) . ';dbname=' . $db['database'] . ';charset=utf8mb4',
        $db['username'], $db['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
    );
    $central = $connect($config['database']);
    $companies = $central->query("SELECT * FROM companies WHERE status='active' AND db_identifier IS NOT NULL AND db_identifier<>'' ORDER BY id")
        ->fetchAll(PDO::FETCH_ASSOC);
    if (!$companies) throw new RuntimeException('No active tenants');
    $results = [];
    foreach ($companies as $company) {
        $pdo = $connect(companyDatabaseConfig($config, $company));
        if ((string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== (string) $company['db_identifier']) {
            throw new RuntimeException('Unexpected tenant database');
        }
        $lockName = 'planex_migrate_' . substr(hash('sha256', (string) $company['db_identifier']), 0, 32);
        $lock = $pdo->prepare('SELECT GET_LOCK(?, 10)');
        $lock->execute([$lockName]);
        if ((int) $lock->fetchColumn() !== 1) throw new RuntimeException('Tenant migration lock unavailable');
        try {
            $q = $pdo->prepare('SELECT checksum FROM schema_migrations WHERE migration=?');
            $q->execute(['151_route_executor_review_sends.sql']);
            if ($q->fetchColumn() === false) throw new RuntimeException('Tenant migration 151 prerequisite missing');
            $q->execute([$name]);
            $stored = $q->fetchColumn();
            if ($stored !== false && !hash_equals((string) $stored, $checksum)) {
                throw new RuntimeException('Migration 153 journal checksum mismatch');
            }
            $indexColumns = static function () use ($pdo): string {
                $q = $pdo->query("SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='crews' AND INDEX_NAME='uk_crew' ORDER BY SEQ_IN_INDEX");
                return implode(',', $q->fetchAll(PDO::FETCH_COLUMN));
            };
            $expectedIndex = 'contractor_id,driver_vehicle_block_id,active_crew_key';
            $crewsBefore = (int) $pdo->query('SELECT COUNT(*) FROM crews')->fetchColumn();
            $activeBefore = (int) $pdo->query('SELECT COUNT(*) FROM crews WHERE deleted_at IS NULL')->fetchColumn();
            if ($apply && $stored === false) {
                $pdo->exec($sql);
                $pdo->prepare('INSERT INTO schema_migrations(migration,checksum) VALUES(?,?)')->execute([$name, $checksum]);
                $stored = $checksum;
            }
            $ready = $indexColumns() === $expectedIndex;
            if (($stored !== false && !$ready) || ($apply && !$ready)) {
                throw new RuntimeException('Migration 153 schema verification failed');
            }
            if ($crewsBefore !== (int) $pdo->query('SELECT COUNT(*) FROM crews')->fetchColumn()
                || $activeBefore !== (int) $pdo->query('SELECT COUNT(*) FROM crews WHERE deleted_at IS NULL')->fetchColumn()) {
                throw new RuntimeException('Existing crew business data changed');
            }
            $results[] = ['company_id' => (int) $company['id'], 'ready' => $ready, 'journaled' => $stored !== false,
                'total_crews' => $crewsBefore, 'active_crews' => $activeBefore];
        } finally {
            $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
        }
    }
    echo 'ROUTE_EXECUTOR_153_SNAPSHOT=' . json_encode($results, JSON_THROW_ON_ERROR) . "\n";
    echo $apply ? "ROUTE_EXECUTOR_153_APPLY_OK\n" : "ROUTE_EXECUTOR_153_READ_ONLY_OK\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'ROUTE_EXECUTOR_153_FAILED: ' . $error->getMessage() . "\n");
    exit(1);
}
