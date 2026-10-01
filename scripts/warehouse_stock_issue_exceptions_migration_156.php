<?php
declare(strict_types=1);

// Read-only by default. Apply requires the exact deployed SHA and separate owner authorization.
if (PHP_SAPI !== 'cli') exit(1);
try {
    $root = rtrim((string)getenv('PLANEX_ROOT'), '/');
    $sha = (string)getenv('PLANEX_SOURCE_SHA');
    $marker = (string)getenv('PLANEX_DEPLOY_MARKER');
    if (!is_file($root.'/.env') || !is_file($root.'/bootstrap/app.php') || !is_file($marker)
        || !preg_match('/^[a-f0-9]{40}$/D', $sha) || trim((string)file_get_contents($marker)) !== $sha) {
        throw new RuntimeException('Exact deployment guard required');
    }
    $name = '156_warehouse_stock_issue_exceptions.sql';
    $sql = (string)file_get_contents($root.'/database/migrations-local/'.$name);
    $checksum = hash('sha256', $sql);
    if (!hash_equals((string)getenv('PLANEX_TENANT_SHA256'), $checksum)
        || !str_starts_with(trim($sql), 'CREATE TABLE IF NOT EXISTS warehouse_stock_issue_exceptions (')
        || substr_count($sql, 'CREATE TABLE') !== 1 || substr_count($sql, ';') !== 1) {
        throw new RuntimeException('Migration checksum or SQL scope mismatch');
    }
    $apply = getenv('WAREHOUSE_EXCEPTIONS_156_MODE') === 'apply';
    if ($apply && getenv('WAREHOUSE_EXCEPTIONS_156_CONFIRM') !== 'APPLY_WAREHOUSE_EXCEPTIONS_156') {
        throw new RuntimeException('Explicit migration confirmation required');
    }
    $config = require $root.'/bootstrap/app.php';
    require_once $root.'/app/Support/crypto_helper.php';
    require_once $root.'/app/Support/company_database.php';
    $connect = static fn(array $db): PDO => new PDO(
        'mysql:host='.$db['host'].';port='.($db['port'] ?? 3306).';dbname='.$db['database'].';charset=utf8mb4',
        $db['username'], $db['password'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]
    );
    $central = $connect($config['database']);
    $companies = $central->query("SELECT * FROM companies WHERE status='active' AND db_identifier IS NOT NULL AND db_identifier<>'' ORDER BY id")
        ->fetchAll(PDO::FETCH_ASSOC);
    if (!$companies) throw new RuntimeException('No active tenants');
    $results = [];
    foreach ($companies as $company) {
        $pdo = $connect(companyDatabaseConfig($config, $company));
        if ((string)$pdo->query('SELECT DATABASE()')->fetchColumn() !== (string)$company['db_identifier']) {
            throw new RuntimeException('Unexpected tenant database');
        }
        $lockName = 'planex_migrate_'.substr(hash('sha256', (string)$company['db_identifier']), 0, 32);
        $lock = $pdo->prepare('SELECT GET_LOCK(?,10)');
        $lock->execute([$lockName]);
        if ((int)$lock->fetchColumn() !== 1) throw new RuntimeException('Tenant migration lock unavailable');
        try {
            $query = $pdo->prepare('SELECT checksum FROM schema_migrations WHERE migration=?');
            $query->execute([$name]);
            $stored = $query->fetchColumn();
            if ($stored !== false && !hash_equals((string)$stored, $checksum)) throw new RuntimeException('Migration journal checksum mismatch');
            $exists = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='warehouse_stock_issue_exceptions'")->fetchColumn() === 1;
            if (($stored !== false) !== $exists) throw new RuntimeException('Unjournaled exception table');
            if ($apply && !$exists) {
                $pdo->exec($sql);
                $pdo->prepare('INSERT INTO schema_migrations(migration,checksum) VALUES(?,?)')->execute([$name,$checksum]);
                $exists = true;
            }
            $query->execute([$name]);
            if ($exists && !hash_equals($checksum, (string)$query->fetchColumn())) throw new RuntimeException('Migration verification failed');
            $count = $exists ? (int)$pdo->query('SELECT COUNT(*) FROM warehouse_stock_issue_exceptions')->fetchColumn() : 0;
            $results[] = ['company_id'=>(int)$company['id'],'ready'=>$exists,'exceptions'=>$count];
        } finally {
            $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
        }
    }
    echo 'WAREHOUSE_EXCEPTIONS_156_SNAPSHOT='.json_encode($results, JSON_THROW_ON_ERROR)."\n";
    echo $apply ? "WAREHOUSE_EXCEPTIONS_156_APPLY_OK\n" : "WAREHOUSE_EXCEPTIONS_156_READ_ONLY_OK\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'WAREHOUSE_EXCEPTIONS_156_FAILED: '.$error->getMessage()."\n");
    exit(1);
}
