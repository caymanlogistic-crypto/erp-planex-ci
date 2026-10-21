<?php
declare(strict_types=1);

// Read-only by default; the guarded workflow supplies the exact source and confirmation.
if (PHP_SAPI !== 'cli') exit(1);
try {
    $root = rtrim((string)getenv('PLANEX_ROOT'), '/');
    $source = (string)getenv('PLANEX_SOURCE_SHA');
    $deployed = (string)getenv('PLANEX_DEPLOYED_BEFORE');
    $marker = (string)getenv('PLANEX_DEPLOY_MARKER');
    if (!is_file($root.'/.env') || !is_file($root.'/bootstrap/app.php') || !is_file($marker)
        || !preg_match('/^[a-f0-9]{40}$/D', $source) || !preg_match('/^[a-f0-9]{40}$/D', $deployed)
        || trim((string)file_get_contents($marker)) !== $deployed) throw new RuntimeException('Canonical deployment guard required');
    $name = '162_feo_weight_legacy_snapshot.sql';
    $path = (string)getenv('PLANEX_TENANT_MIGRATION_PATH');
    if (!preg_match('#^/tmp/feo_weight_legacy_162_[0-9]+\.sql$#D', $path) || !is_file($path)) throw new RuntimeException('Guarded migration file required');
    $sql = (string)file_get_contents($path);
    $checksum = hash('sha256', $sql);
    if (!hash_equals((string)getenv('PLANEX_TENANT_SHA256'), $checksum)
        || !preg_match('/^ALTER TABLE feo_weight_items\s+ADD COLUMN legacy_snapshot_json JSON NULL AFTER after_brutto;\s*$/D', trim($sql))) {
        throw new RuntimeException('Migration checksum or SQL scope mismatch');
    }
    $apply = getenv('FEO_WEIGHT_LEGACY_162_MODE') === 'apply';
    if ($apply && getenv('FEO_WEIGHT_LEGACY_162_CONFIRM') !== 'APPLY_FEO_WEIGHT_LEGACY_162') throw new RuntimeException('Explicit migration confirmation required');
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
            $q->execute(['160_create_feo_weight_batches.sql']);
            if ($q->fetchColumn() === false) throw new RuntimeException('Migration 160 prerequisite missing');
            $q->execute([$name]); $stored=$q->fetchColumn();
            if ($stored !== false && !hash_equals((string)$stored,$checksum)) throw new RuntimeException('Migration 162 checksum mismatch');
            $column=$pdo->query("SELECT DATA_TYPE,IS_NULLABLE,COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='feo_weight_items' AND COLUMN_NAME='legacy_snapshot_json'")->fetch(PDO::FETCH_ASSOC);
            if (($stored !== false) !== ($column !== false)) throw new RuntimeException('Unjournaled or incomplete FEO weight snapshot schema');
            if ($apply && $column === false) {
                $newer=(int)$pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE migration REGEXP '^[0-9]{3}_' AND CAST(LEFT(migration,3) AS UNSIGNED)>162")->fetchColumn();
                if ($newer) throw new RuntimeException('Newer migration exists');
                $pdo->exec($sql);
                $pdo->prepare('INSERT INTO schema_migrations(migration,checksum) VALUES(?,?)')->execute([$name,$checksum]);
                $q->execute([$name]);
                if (!hash_equals($checksum,(string)$q->fetchColumn())) throw new RuntimeException('Migration 162 journal verification failed');
                $column=$pdo->query("SELECT DATA_TYPE,IS_NULLABLE,COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='feo_weight_items' AND COLUMN_NAME='legacy_snapshot_json'")->fetch(PDO::FETCH_ASSOC);
            }
            if ($column !== false && ($column['DATA_TYPE'] !== 'json' || $column['IS_NULLABLE'] !== 'YES' || $column['COLUMN_DEFAULT'] !== null)) throw new RuntimeException('Legacy snapshot column shape mismatch');
            $hash=hash_init('sha256'); $items=0;
            foreach ($pdo->query('SELECT id,batch_id,zayavka_id,request_row_id,before_netto,before_brutto,after_netto,after_brutto FROM feo_weight_items ORDER BY id') as $item) {
                hash_update($hash,json_encode($item,JSON_THROW_ON_ERROR)."\n"); $items++;
            }
            $results[]=['company_id'=>(int)$company['id'],'ready'=>$column!==false,'checksum'=>$column!==false?$checksum:null,
                'items'=>$items,'items_sha256'=>hash_final($hash),
                'requests'=>(int)$pdo->query('SELECT COUNT(*) FROM feo_requests')->fetchColumn(),
                'legacy_snapshot_nonnull'=>$column!==false?(int)$pdo->query('SELECT COUNT(*) FROM feo_weight_items WHERE legacy_snapshot_json IS NOT NULL')->fetchColumn():0];
        } finally {
            foreach (array_reverse($held) as $lock) $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);
        }
    }
    echo 'FEO_WEIGHT_LEGACY_162_SNAPSHOT='.json_encode($results,JSON_THROW_ON_ERROR)."\n";
    echo $apply ? "FEO_WEIGHT_LEGACY_162_APPLY_OK\n" : "FEO_WEIGHT_LEGACY_162_READ_ONLY_OK\n";
} catch (Throwable $error) {
    fwrite(STDERR,'FEO_WEIGHT_LEGACY_162_FAILED: '.$error->getMessage()."\n");
    exit(1);
}
