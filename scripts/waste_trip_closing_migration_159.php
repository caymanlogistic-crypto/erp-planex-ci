<?php
declare(strict_types=1);

// Read-only by default. Apply requires an exact deployed SHA and a task-specific confirmation.
if (PHP_SAPI!=='cli') exit(1);
try {
    $root=rtrim((string)getenv('PLANEX_ROOT'),'/');
    $sha=(string)getenv('PLANEX_SOURCE_SHA');
    $marker=(string)getenv('PLANEX_DEPLOY_MARKER');
    if (!is_file($root.'/.env') || !is_file($root.'/bootstrap/app.php') || !is_file($marker)
        || !preg_match('/^[a-f0-9]{40}$/D',$sha) || trim((string)file_get_contents($marker))!==$sha) {
        throw new RuntimeException('Exact deployment guard required');
    }
    $name='159_waste_trip_closing_payment_queue.sql';
    $sql=(string)file_get_contents($root.'/database/migrations-local/'.$name);
    $checksum=hash('sha256',$sql);
    if (!hash_equals((string)getenv('PLANEX_TENANT_SHA256'),$checksum)
        || trim($sql)!=="ALTER TABLE waste_trip_closing_reviews\n  MODIFY COLUMN state ENUM('work','sending','review','failed','unknown','payment') NOT NULL DEFAULT 'work';") {
        throw new RuntimeException('Migration checksum or SQL scope mismatch');
    }
    $apply=getenv('WASTE_TRIP_CLOSING_159_MODE')==='apply';
    if ($apply && getenv('WASTE_TRIP_CLOSING_159_CONFIRM')!=='APPLY_WASTE_TRIP_CLOSING_159') {
        throw new RuntimeException('Explicit migration confirmation required');
    }
    $config=require $root.'/bootstrap/app.php';
    require_once $root.'/app/Support/crypto_helper.php';
    require_once $root.'/app/Support/company_database.php';
    $connect=static fn(array $db):PDO=>new PDO(
        'mysql:host='.$db['host'].';port='.($db['port']??3306).';dbname='.$db['database'].';charset=utf8mb4',
        $db['username'],$db['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]
    );
    $central=$connect($config['database']);
    $companies=$central->query("SELECT * FROM companies WHERE status='active' AND db_identifier IS NOT NULL AND db_identifier<>'' ORDER BY id")
        ->fetchAll(PDO::FETCH_ASSOC);
    if (!$companies) throw new RuntimeException('No active tenants');
    $results=[];
    foreach ($companies as $company) {
        $pdo=$connect(companyDatabaseConfig($config,$company));
        if ((string)$pdo->query('SELECT DATABASE()')->fetchColumn()!==(string)$company['db_identifier']) throw new RuntimeException('Unexpected tenant database');
        $lockName='planex_migrate_'.substr(hash('sha256',(string)$company['db_identifier']),0,32);
        $lock=$pdo->prepare('SELECT GET_LOCK(?,10)');$lock->execute([$lockName]);
        if ((int)$lock->fetchColumn()!==1) throw new RuntimeException('Tenant migration lock unavailable');
        try {
            $journal=$pdo->prepare('SELECT checksum FROM schema_migrations WHERE migration=?');
            $journal->execute(['152_waste_trip_closing_documents.sql']);
            if ($journal->fetchColumn()===false) throw new RuntimeException('Migration 152 prerequisite missing');
            $journal->execute([$name]);$stored=$journal->fetchColumn();
            if ($stored!==false && !hash_equals((string)$stored,$checksum)) throw new RuntimeException('Migration 159 journal checksum mismatch');
            $column=$pdo->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='waste_trip_closing_reviews' AND COLUMN_NAME='state'")->fetchColumn();
            $before="enum('work','sending','review','failed','unknown')";
            $after="enum('work','sending','review','failed','unknown','payment')";
            if ($column!==$before && $column!==$after) throw new RuntimeException('Unexpected closing review state schema');
            $installed=$column===$after;
            if (($stored!==false)!==$installed) throw new RuntimeException('Unjournaled closing review state schema');
            $rowCount=(int)$pdo->query('SELECT COUNT(*) FROM waste_trip_closing_reviews')->fetchColumn();
            if ($apply && !$installed) {
                $pdo->exec($sql);
                $pdo->prepare('INSERT INTO schema_migrations(migration,checksum) VALUES(?,?)')->execute([$name,$checksum]);
                $installed=true;
            }
            $column=$pdo->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='waste_trip_closing_reviews' AND COLUMN_NAME='state'")->fetchColumn();
            $journal->execute([$name]);$verified=$journal->fetchColumn();
            if ($installed && ($column!==$after || !hash_equals($checksum,(string)$verified))) throw new RuntimeException('Migration 159 verification failed');
            if ($rowCount!==(int)$pdo->query('SELECT COUNT(*) FROM waste_trip_closing_reviews')->fetchColumn()) throw new RuntimeException('Review rows changed');
            $results[]=['company_id'=>(int)$company['id'],'ready'=>$installed,'review_count'=>$rowCount];
        } finally {$pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);}
    }
    echo 'WASTE_TRIP_CLOSING_159_SNAPSHOT='.json_encode($results,JSON_THROW_ON_ERROR)."\n";
    echo $apply?"WASTE_TRIP_CLOSING_159_APPLY_OK\n":"WASTE_TRIP_CLOSING_159_READ_ONLY_OK\n";
} catch (Throwable $error) {
    fwrite(STDERR,'WASTE_TRIP_CLOSING_159_FAILED: '.$error->getMessage()."\n");exit(1);
}
