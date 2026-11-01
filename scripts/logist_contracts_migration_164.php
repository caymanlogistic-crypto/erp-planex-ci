<?php
declare(strict_types=1);

// Read-only by default. Production DDL requires the separately authorized workflow.
if (PHP_SAPI !== 'cli') exit(1);
try {
    $root = rtrim((string)getenv('PLANEX_ROOT'), '/');
    $source = (string)getenv('PLANEX_SOURCE_SHA');
    $marker = (string)getenv('PLANEX_DEPLOY_MARKER');
    $path = (string)getenv('PLANEX_TENANT_MIGRATION_PATH');
    if (!is_file($root.'/.env') || !is_file($root.'/bootstrap/app.php') || !is_file($marker)
        || !preg_match('/^[a-f0-9]{40}$/D', $source)
        || trim((string)file_get_contents($marker)) !== $source
        || !preg_match('#^/tmp/logist_contracts_164_[0-9]+\.sql$#D', $path) || !is_file($path)) {
        throw new RuntimeException('Exact deployed canonical and guarded SQL are required');
    }
    $sql = trim((string)file_get_contents($path));
    $checksum = hash_file('sha256', $path);
    if (!$checksum || !hash_equals((string)getenv('PLANEX_TENANT_SHA256'), $checksum)
        || !preg_match('/^CREATE TABLE IF NOT EXISTS `logist_contracts` \(/D', $sql)
        || !str_ends_with($sql, 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;')
        || substr_count($sql, ';') !== 1) throw new RuntimeException('Migration SQL scope/checksum mismatch');
    $apply = getenv('LOGIST_CONTRACTS_164_MODE') === 'apply';
    if ($apply && getenv('LOGIST_CONTRACTS_164_CONFIRM') !== 'APPLY_LOGIST_CONTRACTS_164') {
        throw new RuntimeException('Separate schema migration authorization required');
    }
    $config = require $root.'/bootstrap/app.php';
    require_once $root.'/app/Support/crypto_helper.php';
    require_once $root.'/app/Support/company_database.php';
    $connect = static fn(array $c): PDO => new PDO(
        'mysql:host='.$c['host'].';port='.($c['port'] ?? 3306).';dbname='.$c['database'].';charset=utf8mb4',
        $c['username'], $c['password'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]
    );
    $central = $connect($config['database']);
    $companies = $central->query("SELECT * FROM companies WHERE status='active' AND db_identifier IS NOT NULL AND db_identifier<>'' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    if ($companies === []) throw new RuntimeException('No active tenants');
    $result = [];
    foreach ($companies as $company) {
        $pdo = $connect(companyDatabaseConfig($config, $company));
        $database = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
        if ($database !== (string)$company['db_identifier']) throw new RuntimeException('Unexpected tenant database');
        $lock = 'planex_migrate_'.substr(hash('sha256', $database), 0, 32);
        $q = $pdo->prepare('SELECT GET_LOCK(?,10)'); $q->execute([$lock]);
        if ((int)$q->fetchColumn() !== 1) throw new RuntimeException('Migration lock unavailable');
        try {
            $migration = '164_logist_contracts.sql';
            $q = $pdo->prepare('SELECT checksum FROM schema_migrations WHERE migration=?');
            $q->execute([$migration]); $stored = $q->fetchColumn();
            if ($stored !== false && !hash_equals((string)$stored, $checksum)) throw new RuntimeException('Migration 164 checksum mismatch');
            $shape = static function (PDO $db): array {
                $table = (int)$db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='logist_contracts'")->fetchColumn();
                if (!$table) return ['ready'=>false,'rows'=>0];
                $columns = $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='logist_contracts'")->fetchAll(PDO::FETCH_COLUMN);
                foreach (['id','trip_id','business_id','contract_type','version_no','template_id','template_version_id','route_executor_id','snapshot_json','pdf_path','pdf_name','pdf_sha256','status','review_reason','reviewed_by_user_id','reviewed_at','created_by_user_id','created_at'] as $column) {
                    if (!in_array($column, $columns, true)) throw new RuntimeException('Migration 164 table shape mismatch: '.$column);
                }
                $unique = (int)$db->query("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='logist_contracts' AND INDEX_NAME='uk_logist_contract_version'")->fetchColumn();
                if ($unique !== 3) throw new RuntimeException('Migration 164 unique key mismatch');
                return ['ready'=>true,'rows'=>(int)$db->query('SELECT COUNT(*) FROM logist_contracts')->fetchColumn()];
            };
            $before = $shape($pdo);
            if (($stored !== false) !== $before['ready']) throw new RuntimeException('Unjournaled/incomplete migration 164');
            if ($apply && !$before['ready']) {
                $pdo->exec($sql);
                $pdo->prepare('INSERT INTO schema_migrations(migration,checksum) VALUES(?,?)')->execute([$migration,$checksum]);
            }
            $after = $shape($pdo);
            if ($apply && !$after['ready']) throw new RuntimeException('Migration 164 table missing after DDL');
            if ($before['ready'] && $after['rows'] !== $before['rows']) throw new RuntimeException('Existing contract rows changed');
            $result[] = ['company_id'=>(int)$company['id'],'before'=>$before,'after'=>$after,'checksum'=>$after['ready']?$checksum:null];
        } finally {
            $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);
        }
    }
    echo 'LOGIST_CONTRACTS_164_SNAPSHOT='.json_encode($result, JSON_THROW_ON_ERROR)."\n";
    echo $apply ? "LOGIST_CONTRACTS_164_APPLY_OK\n" : "LOGIST_CONTRACTS_164_READ_ONLY_OK\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'LOGIST_CONTRACTS_164_FAILED: '.$error->getMessage()."\n");
    exit(1);
}
