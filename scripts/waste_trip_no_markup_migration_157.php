<?php
declare(strict_types=1);

// Read-only by default. Apply requires the guarded canonical source and separate owner authorization.
if (PHP_SAPI !== 'cli') exit(1);
try {
    $root = rtrim((string)getenv('PLANEX_ROOT'), '/');
    $sha = (string)getenv('PLANEX_SOURCE_SHA');
    $deployed = (string)getenv('PLANEX_DEPLOYED_BEFORE');
    $marker = (string)getenv('PLANEX_DEPLOY_MARKER');
    if (!is_file($root.'/.env') || !is_file($root.'/bootstrap/app.php') || !is_file($marker)
        || !preg_match('/^[a-f0-9]{40}$/D', $sha) || !preg_match('/^[a-f0-9]{40}$/D', $deployed)
        || trim((string)file_get_contents($marker)) !== $deployed) {
        throw new RuntimeException('Canonical/ancestor deployment guard required');
    }
    $name = '157_waste_trip_no_markup.sql';
    $path = (string)getenv('PLANEX_TENANT_MIGRATION_PATH');
    if (!preg_match('#^/tmp/waste_trip_no_markup_157_[0-9]+\.sql$#D', $path) || !is_file($path)) {
        throw new RuntimeException('Guarded migration file required');
    }
    $sql = (string)file_get_contents($path);
    $checksum = hash('sha256', $sql);
    if (!hash_equals((string)getenv('PLANEX_TENANT_SHA256'), $checksum)
        || !preg_match('/^ALTER TABLE waste_trips\\s+ADD COLUMN no_markup TINYINT\\(1\\) NOT NULL DEFAULT 0 AFTER financial_model_version;\\s*$/D', trim($sql))) {
        throw new RuntimeException('Migration checksum or SQL scope mismatch');
    }
    $apply = getenv('WASTE_TRIP_NO_MARKUP_157_MODE') === 'apply';
    if ($apply && getenv('WASTE_TRIP_NO_MARKUP_157_CONFIRM') !== 'APPLY_WASTE_TRIP_NO_MARKUP_157') {
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
            $exists = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='waste_trips' AND column_name='no_markup'")->fetchColumn() === 1;
            if (($stored !== false) !== $exists) throw new RuntimeException('Unjournaled no_markup column');
            if ($apply && !$exists) {
                $pdo->exec($sql);
                $pdo->prepare('INSERT INTO schema_migrations(migration,checksum) VALUES(?,?)')->execute([$name,$checksum]);
                $exists = true;
            }
            $query->execute([$name]);
            if ($exists && !hash_equals($checksum, (string)$query->fetchColumn())) throw new RuntimeException('Migration verification failed');
            $column = $exists ? $pdo->query("SELECT DATA_TYPE,IS_NULLABLE,COLUMN_DEFAULT FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='waste_trips' AND column_name='no_markup'")->fetch(PDO::FETCH_ASSOC) : false;
            if ($exists && (!$column || $column['DATA_TYPE'] !== 'tinyint' || $column['IS_NULLABLE'] !== 'NO' || (string)$column['COLUMN_DEFAULT'] !== '0')) {
                throw new RuntimeException('no_markup column shape mismatch');
            }
            $results[] = [
                'company_id'=>(int)$company['id'], 'ready'=>$exists,
                'trip_count'=>(int)$pdo->query('SELECT COUNT(*) FROM waste_trips')->fetchColumn(),
                'customer_count'=>(int)$pdo->query('SELECT COUNT(*) FROM waste_trip_customer_payments')->fetchColumn(),
                'customer_total'=>(string)$pdo->query('SELECT COALESCE(SUM(amount),0) FROM waste_trip_customer_payments')->fetchColumn(),
                'carrier_count'=>(int)$pdo->query('SELECT COUNT(*) FROM waste_trip_carrier_payments')->fetchColumn(),
                'carrier_total'=>(string)$pdo->query('SELECT COALESCE(SUM(amount),0) FROM waste_trip_carrier_payments')->fetchColumn(),
            ];
        } finally {
            $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
        }
    }
    echo 'WASTE_TRIP_NO_MARKUP_157_SNAPSHOT='.json_encode($results, JSON_THROW_ON_ERROR)."\n";
    echo $apply ? "WASTE_TRIP_NO_MARKUP_157_APPLY_OK\n" : "WASTE_TRIP_NO_MARKUP_157_READ_ONLY_OK\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'WASTE_TRIP_NO_MARKUP_157_FAILED: '.$error->getMessage()."\n");
    exit(1);
}

