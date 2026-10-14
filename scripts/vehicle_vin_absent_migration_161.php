<?php
declare(strict_types=1);

// Read-only by default. Production apply is restricted by the guarded workflow.
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
    $name = '161_vehicle_unit_vin_absent.sql';
    $path = (string)getenv('PLANEX_TENANT_MIGRATION_PATH');
    if (!preg_match('#^/tmp/vehicle_vin_absent_161_[0-9]+\.sql$#D', $path) || !is_file($path)) {
        throw new RuntimeException('Guarded migration file required');
    }
    $sql = (string)file_get_contents($path);
    $checksum = hash('sha256', $sql);
    if (!hash_equals((string)getenv('PLANEX_TENANT_SHA256'), $checksum)
        || !preg_match('/^--[^\n]*\nALTER TABLE vehicle_units\s+ADD COLUMN vin_absent TINYINT\(1\) NOT NULL DEFAULT 0 AFTER vin,\s+ADD CONSTRAINT chk_vehicle_unit_vin_absent CHECK \(vin_absent IN \(0,1\) AND \(vin_absent = 0 OR vin IS NULL\)\);\s*$/D', $sql)) {
        throw new RuntimeException('Migration checksum or SQL scope mismatch');
    }
    $apply = getenv('VEHICLE_VIN_ABSENT_161_MODE') === 'apply';
    if ($apply && getenv('VEHICLE_VIN_ABSENT_161_CONFIRM') !== 'APPLY_VEHICLE_VIN_ABSENT_161') {
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
            $column = $pdo->query("SELECT DATA_TYPE,IS_NULLABLE,COLUMN_DEFAULT FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='vehicle_units' AND column_name='vin_absent'")->fetch(PDO::FETCH_ASSOC);
            $constraint = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema=DATABASE() AND table_name='vehicle_units' AND constraint_name='chk_vehicle_unit_vin_absent' AND constraint_type='CHECK'")->fetchColumn() === 1;
            if (($stored !== false) !== (bool)$column || (bool)$column !== $constraint) {
                throw new RuntimeException('Unjournaled or incomplete VIN absence schema');
            }
            $hash = hash_init('sha256');
            $count = 0;
            foreach ($pdo->query('SELECT id,vin FROM vehicle_units ORDER BY id', PDO::FETCH_ASSOC) as $vehicle) {
                hash_update($hash, json_encode($vehicle, JSON_THROW_ON_ERROR)."\n");
                $count++;
            }
            $vinHash = hash_final($hash);
            if ($apply && !$column) {
                $pdo->exec($sql);
                $pdo->prepare('INSERT INTO schema_migrations(migration,checksum) VALUES(?,?)')->execute([$name,$checksum]);
                $column = $pdo->query("SELECT DATA_TYPE,IS_NULLABLE,COLUMN_DEFAULT FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='vehicle_units' AND column_name='vin_absent'")->fetch(PDO::FETCH_ASSOC);
                $constraint = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema=DATABASE() AND table_name='vehicle_units' AND constraint_name='chk_vehicle_unit_vin_absent' AND constraint_type='CHECK'")->fetchColumn() === 1;
            }
            $query->execute([$name]);
            if ($column && (!hash_equals($checksum, (string)$query->fetchColumn()) || !$constraint
                || $column['DATA_TYPE'] !== 'tinyint' || $column['IS_NULLABLE'] !== 'NO' || (string)$column['COLUMN_DEFAULT'] !== '0')) {
                throw new RuntimeException('VIN absence migration verification failed');
            }
            $results[] = ['company_id'=>(int)$company['id'], 'ready'=>(bool)$column,
                'vehicle_count'=>$count, 'vin_sha256'=>$vinHash,
                'marked_absent'=>$column ? (int)$pdo->query('SELECT COUNT(*) FROM vehicle_units WHERE vin_absent=1')->fetchColumn() : 0];
        } finally {
            $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
        }
    }
    echo 'VEHICLE_VIN_ABSENT_161_SNAPSHOT='.json_encode($results, JSON_THROW_ON_ERROR)."\n";
    echo $apply ? "VEHICLE_VIN_ABSENT_161_APPLY_OK\n" : "VEHICLE_VIN_ABSENT_161_READ_ONLY_OK\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'VEHICLE_VIN_ABSENT_161_FAILED: '.$error->getMessage()."\n");
    exit(1);
}
