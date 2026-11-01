<?php
declare(strict_types=1);

// Task-specific central schema change. No company or backup data is modified.
if (PHP_SAPI !== 'cli') exit(1);
$phase = 'PREFLIGHT';
try {
    $root = rtrim((string)getenv('PLANEX_ROOT'), '/');
    $sourceSha = (string)getenv('PLANEX_SOURCE_SHA');
    $marker = (string)getenv('PLANEX_DEPLOY_MARKER');
    if ($root === '' || !is_file($root . '/.env') || !is_file($root . '/bootstrap/app.php')
        || !is_file($marker) || !preg_match('/^[a-f0-9]{40}$/D', $sourceSha)
        || trim((string)file_get_contents($marker)) !== $sourceSha) {
        throw new RuntimeException('Exact deployed source guard required');
    }
    $name = '021_company_deletion_runs.sql';
    $sql = (string)file_get_contents($root . '/database/migrations/' . $name);
    if (!preg_match('/^[a-f0-9]{64}$/D', (string)getenv('PLANEX_MIGRATION_SHA256'))
        || !hash_equals((string)getenv('PLANEX_MIGRATION_SHA256'), hash('sha256', $sql))) {
        throw new RuntimeException('Migration checksum mismatch');
    }
    if (substr_count($sql, ';') !== 1
        || !preg_match('/^CREATE TABLE IF NOT EXISTS company_deletion_runs\s*\(/', trim($sql))
        || !str_contains($sql, 'UNIQUE KEY uq_company_deletion_runs_company (company_id)')
        || !str_contains($sql, 'UNIQUE KEY uq_company_deletion_runs_backup (backup_id)')) {
        throw new RuntimeException('Migration scope changed');
    }
    $apply = getenv('COMPANY_DELETION_021_MODE') === 'apply';
    if ($apply && getenv('COMPANY_DELETION_021_CONFIRM') !== 'APPLY_COMPANY_DELETION_021') {
        throw new RuntimeException('Explicit migration confirmation required');
    }
    $phase = 'DB_CONNECT';
    $config = require $root . '/bootstrap/app.php';
    $db = $config['database'];
    $pdo = new PDO(
        'mysql:host=' . $db['host'] . ';port=' . ($db['port'] ?? 3306) . ';dbname=' . $db['database'] . ';charset=utf8mb4',
        $db['username'], $db['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
    );
    $phase = 'SCHEMA_CHECK';
    $lock = $pdo->prepare('SELECT GET_LOCK(?,10)');
    $lock->execute(['planex_migrate_central_021']);
    if ((int)$lock->fetchColumn() !== 1) throw new RuntimeException('Central migration lock unavailable');
    try {
        $exists = static function (PDO $pdo, string $table): bool {
            $query = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
            $query->execute([$table]);
            return (int)$query->fetchColumn() === 1;
        };
        if (!$exists($pdo, 'schema_migrations') || !$exists($pdo, 'companies') || !$exists($pdo, 'deleted_entities')) {
            throw new RuntimeException('Central prerequisite table missing');
        }
        $journal = $pdo->prepare('SELECT checksum FROM schema_migrations WHERE migration=?');
        $journal->execute([$name]);
        $stored = $journal->fetchColumn();
        $checksum = hash('sha256', $sql);
        if ($stored !== false && !hash_equals((string)$stored, $checksum)) {
            throw new RuntimeException('Central migration journal checksum mismatch');
        }
        $tablePresent = $exists($pdo, 'company_deletion_runs');
        if (($stored !== false) !== $tablePresent) {
            throw new RuntimeException('Unjournaled or missing company deletion journal table');
        }
        if ($apply && !$tablePresent) {
            $phase = 'APPLY';
            $pdo->exec($sql);
            $pdo->prepare('INSERT INTO schema_migrations(migration,checksum) VALUES(?,?)')->execute([$name, $checksum]);
            $tablePresent = true;
        }
        $phase = 'POSTCHECK';
        if ($tablePresent) {
            $columns = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='company_deletion_runs'")
                ->fetchAll(PDO::FETCH_COLUMN);
            $required = ['company_id','company_name','db_identifier','actor_user_id','actor_role','backup_id',
                'backup_manifest_sha256','database_archive_sha256','storage_archive_sha256','restore_receipt_sha256',
                'backup_created_at','backup_expires_at','status','stage','error_code','completed_at'];
            if (array_diff($required, $columns) !== []) throw new RuntimeException('Company deletion journal columns incomplete');
            $indexes = $pdo->query("SELECT DISTINCT index_name FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='company_deletion_runs'")
                ->fetchAll(PDO::FETCH_COLUMN);
            if (array_diff(['uq_company_deletion_runs_company','uq_company_deletion_runs_backup'], $indexes) !== []) {
                throw new RuntimeException('Company deletion journal uniqueness missing');
            }
            if ((int)$pdo->query('SELECT COUNT(*) FROM company_deletion_runs')->fetchColumn() !== 0) {
                throw new RuntimeException('Unexpected company deletion run exists; no deletion was authorized');
            }
        }
        echo 'COMPANY_DELETION_021_SNAPSHOT=' . json_encode(['ready' => $tablePresent, 'runs' => 0], JSON_THROW_ON_ERROR) . "\n";
        echo $apply ? "COMPANY_DELETION_021_APPLY_OK\n" : "COMPANY_DELETION_021_READ_ONLY_OK\n";
    } finally {
        $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute(['planex_migrate_central_021']);
    }
} catch (Throwable $error) {
    // Production connector and schema exception details must not reach Actions logs.
    fwrite(STDERR, 'COMPANY_DELETION_021_FAILED_' . $phase . "\n");
    exit(1);
}
