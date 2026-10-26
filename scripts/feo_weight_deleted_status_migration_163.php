<?php
declare(strict_types=1);

// Read-only by default. Only the guarded production workflow may apply this schema change.
if (PHP_SAPI !== 'cli') exit(1);
try {
    $root = rtrim((string)getenv('PLANEX_ROOT'), '/');
    $source = (string)getenv('PLANEX_SOURCE_SHA');
    $deployed = (string)getenv('PLANEX_DEPLOYED_BEFORE');
    $marker = (string)getenv('PLANEX_DEPLOY_MARKER');
    if (!is_file($root.'/.env') || !is_file($root.'/bootstrap/app.php') || !is_file($marker)
        || !preg_match('/^[a-f0-9]{40}$/D', $source) || !preg_match('/^[a-f0-9]{40}$/D', $deployed)
        || trim((string)file_get_contents($marker)) !== $deployed) throw new RuntimeException('Canonical deployment guard required');
    $name = '163_feo_weight_deleted_status.sql';
    $path = (string)getenv('PLANEX_TENANT_MIGRATION_PATH');
    if (!preg_match('#^/tmp/feo_weight_deleted_163_[0-9]+\.sql$#D', $path) || !is_file($path)) throw new RuntimeException('Guarded migration file required');
    $sql = trim((string)file_get_contents($path));
    $checksum = hash('sha256', (string)file_get_contents($path));
    if (!hash_equals((string)getenv('PLANEX_TENANT_SHA256'), $checksum)
        || !preg_match("/^ALTER TABLE feo_weight_batches\\s+MODIFY COLUMN status ENUM\\('applied','reverted','deleted'\\) NOT NULL DEFAULT 'applied';$/D", $sql)) {
        throw new RuntimeException('Migration checksum or SQL scope mismatch');
    }
    $apply = getenv('FEO_WEIGHT_DELETED_163_MODE') === 'apply';
    if ($apply && getenv('FEO_WEIGHT_DELETED_163_CONFIRM') !== 'APPLY_FEO_WEIGHT_DELETED_163') throw new RuntimeException('Explicit migration confirmation required');
    $config = require $root.'/bootstrap/app.php';
    require_once $root.'/app/Support/crypto_helper.php';
    require_once $root.'/app/Support/company_database.php';
    $connect = static fn(array $c): PDO => new PDO(
        'mysql:host='.$c['host'].';port='.($c['port'] ?? 3306).';dbname='.$c['database'].';charset=utf8mb4',
        $c['username'], $c['password'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]
    );
    $central = $connect($config['database']);
    $companies = $central->query("SELECT * FROM companies WHERE status='active' AND db_identifier IS NOT NULL AND db_identifier<>'' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    if (!$companies) throw new RuntimeException('No active tenants');
    $results = [];
    foreach ($companies as $company) {
        $pdo = $connect(companyDatabaseConfig($config, $company));
        $database = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
        if ($database !== (string)$company['db_identifier']) throw new RuntimeException('Unexpected tenant database');
        $locks = ['planex_migrate_'.substr(hash('sha256',$database),0,32),'planex_feo_'.substr(hash('sha256',$database),0,24)];
        $held = [];
        try {
            foreach ($locks as $lock) {
                $q=$pdo->prepare('SELECT GET_LOCK(?,10)'); $q->execute([$lock]);
                if ((int)$q->fetchColumn() !== 1) throw new RuntimeException('Migration/FEO lock unavailable');
                $held[]=$lock;
            }
            $q=$pdo->prepare('SELECT checksum FROM schema_migrations WHERE migration=?');
            $q->execute(['162_feo_weight_legacy_snapshot.sql']);
            if ($q->fetchColumn() === false) throw new RuntimeException('Migration 162 prerequisite missing');
            $q->execute([$name]); $stored=$q->fetchColumn();
            if ($stored !== false && !hash_equals((string)$stored,$checksum)) throw new RuntimeException('Migration 163 checksum mismatch');
            $column=$pdo->query("SELECT COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='feo_weight_batches' AND COLUMN_NAME='status'")->fetch(PDO::FETCH_ASSOC);
            $before="enum('applied','reverted')"; $after="enum('applied','reverted','deleted')";
            if (!$column || !in_array(strtolower((string)$column['COLUMN_TYPE']),[$before,$after],true)
                || $column['IS_NULLABLE']!=='NO' || $column['COLUMN_DEFAULT']!=='applied') throw new RuntimeException('Weight status column shape mismatch');
            if (($stored !== false) !== (strtolower((string)$column['COLUMN_TYPE'])===$after)) throw new RuntimeException('Unjournaled or incomplete FEO weight status schema');
            if ($apply && $stored === false) {
                $newer=(int)$pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE migration REGEXP '^[0-9]{3}_' AND CAST(LEFT(migration,3) AS UNSIGNED)>163")->fetchColumn();
                if ($newer) throw new RuntimeException('Newer migration exists');
                $pdo->exec($sql);
                $pdo->prepare('INSERT INTO schema_migrations(migration,checksum) VALUES(?,?)')->execute([$name,$checksum]);
                $q->execute([$name]);
                if (!hash_equals($checksum,(string)$q->fetchColumn())) throw new RuntimeException('Migration 163 journal verification failed');
                $column=$pdo->query("SELECT COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='feo_weight_batches' AND COLUMN_NAME='status'")->fetch(PDO::FETCH_ASSOC);
            }
            $ready=strtolower((string)$column['COLUMN_TYPE'])===$after;
            if ($apply && !$ready) throw new RuntimeException('Migration 163 column verification failed');
            $statuses=$pdo->query('SELECT status,COUNT(*) total FROM feo_weight_batches GROUP BY status ORDER BY status')->fetchAll(PDO::FETCH_ASSOC);
            $hash=hash_init('sha256');
            foreach ($pdo->query('SELECT id,status,operation_key FROM feo_weight_batches ORDER BY id') as $row) hash_update($hash,json_encode($row,JSON_THROW_ON_ERROR)."\n");
            $results[]=['company_id'=>(int)$company['id'],'ready'=>$ready,'checksum'=>$ready?$checksum:null,
                'statuses'=>$statuses,'batches_sha256'=>hash_final($hash),
                'items'=>(int)$pdo->query('SELECT COUNT(*) FROM feo_weight_items')->fetchColumn(),
                'requests'=>(int)$pdo->query('SELECT COUNT(*) FROM feo_requests')->fetchColumn()];
        } finally {
            foreach (array_reverse($held) as $lock) $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);
        }
    }
    echo 'FEO_WEIGHT_DELETED_163_SNAPSHOT='.json_encode($results,JSON_THROW_ON_ERROR)."\n";
    echo $apply ? "FEO_WEIGHT_DELETED_163_APPLY_OK\n" : "FEO_WEIGHT_DELETED_163_READ_ONLY_OK\n";
} catch (Throwable $error) {
    fwrite(STDERR,'FEO_WEIGHT_DELETED_163_FAILED: '.$error->getMessage()."\n");
    exit(1);
}
