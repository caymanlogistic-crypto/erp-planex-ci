<?php
declare(strict_types=1);

// Read-only by default. Apply requires an exact deployed SHA and separate owner authorization.
if (PHP_SAPI!=='cli') exit(1);
try {
    $root=rtrim((string)getenv('PLANEX_ROOT'),'/');
    $sha=(string)getenv('PLANEX_SOURCE_SHA');
    $marker=(string)getenv('PLANEX_DEPLOY_MARKER');
    if (!is_file($root.'/.env') || !is_file($root.'/bootstrap/app.php') || !is_file($marker)
        || !preg_match('/^[a-f0-9]{40}$/D',$sha) || trim((string)file_get_contents($marker))!==$sha) {
        throw new RuntimeException('Exact deployment guard required');
    }
    $name='154_route_executor_review_cards.sql';
    $sql=(string)file_get_contents($root.'/database/migrations-local/'.$name);
    $checksum=hash('sha256',$sql);
    if (!hash_equals((string)getenv('PLANEX_TENANT_SHA256'),$checksum)
        || !str_starts_with(trim($sql),'CREATE TABLE route_executor_review_cards (')
        || substr_count($sql,'CREATE TABLE')!==1 || substr_count($sql,';')!==1) {
        throw new RuntimeException('Migration checksum or SQL scope mismatch');
    }
    $apply=getenv('ROUTE_EXECUTOR_154_MODE')==='apply';
    if ($apply && getenv('ROUTE_EXECUTOR_154_CONFIRM')!=='APPLY_ROUTE_EXECUTOR_154') {
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
        $q=$pdo->prepare('SELECT GET_LOCK(?,10)');$q->execute([$lockName]);
        if ((int)$q->fetchColumn()!==1) throw new RuntimeException('Tenant migration lock unavailable');
        try {
            $q=$pdo->prepare('SELECT checksum FROM schema_migrations WHERE migration=?');
            $q->execute(['151_route_executor_review_sends.sql']);
            if ($q->fetchColumn()===false) throw new RuntimeException('Tenant migration 151 prerequisite missing');
            $q->execute([$name]);$stored=$q->fetchColumn();
            if ($stored!==false && !hash_equals((string)$stored,$checksum)) throw new RuntimeException('Migration 154 journal checksum mismatch');
            $exists=(int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='route_executor_review_cards'")->fetchColumn()===1;
            if (($stored!==false)!==$exists) throw new RuntimeException('Unjournaled route executor cards table');
            $before=(int)$pdo->query('SELECT COUNT(*) FROM route_executor_review_sends')->fetchColumn();
            if ($apply && !$exists) {
                $pdo->exec($sql);
                $pdo->prepare('INSERT INTO schema_migrations(migration,checksum) VALUES(?,?)')->execute([$name,$checksum]);
                $exists=true;
            }
            $q->execute([$name]);
            if ($exists && !hash_equals($checksum,(string)$q->fetchColumn())) throw new RuntimeException('Migration 154 verification failed');
            if ($before!==(int)$pdo->query('SELECT COUNT(*) FROM route_executor_review_sends')->fetchColumn()) throw new RuntimeException('Existing review log changed');
            $results[]=['company_id'=>(int)$company['id'],'ready'=>$exists,'cards'=>$exists?(int)$pdo->query('SELECT COUNT(*) FROM route_executor_review_cards')->fetchColumn():0];
        } finally { $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]); }
    }
    echo 'ROUTE_EXECUTOR_154_SNAPSHOT='.json_encode($results,JSON_THROW_ON_ERROR)."\n";
    echo $apply?"ROUTE_EXECUTOR_154_APPLY_OK\n":"ROUTE_EXECUTOR_154_READ_ONLY_OK\n";
} catch (Throwable $error) {
    fwrite(STDERR,'ROUTE_EXECUTOR_154_FAILED: '.$error->getMessage()."\n");
    exit(1);
}
