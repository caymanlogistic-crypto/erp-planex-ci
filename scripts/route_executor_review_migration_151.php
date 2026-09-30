<?php
declare(strict_types=1);

// Inspection is read-only. Apply requires an exact deployed SHA and explicit confirmation.
if (PHP_SAPI!=='cli') exit(1);
try {
    $root=rtrim((string)getenv('PLANEX_ROOT'),'/');
    $sha=(string)getenv('PLANEX_SOURCE_SHA');
    $marker=(string)getenv('PLANEX_DEPLOY_MARKER');
    if (!is_file($root.'/.env') || !is_file($root.'/bootstrap/app.php') || !is_file($marker)
        || !preg_match('/^[a-f0-9]{40}$/D',$sha) || trim((string)file_get_contents($marker))!==$sha) {
        throw new RuntimeException('Exact deployment guard required');
    }
    $name='151_route_executor_review_sends.sql';
    $sql=(string)file_get_contents($root.'/database/migrations-local/'.$name);
    if (!hash_equals((string)getenv('PLANEX_TENANT_SHA256'),hash('sha256',$sql))
        || !str_starts_with(trim($sql),'CREATE TABLE route_executor_review_sends (')
        || substr_count($sql,'CREATE TABLE')!==1 || substr_count($sql,';')!==1) {
        throw new RuntimeException('Migration checksum or SQL scope mismatch');
    }
    $apply=getenv('ROUTE_EXECUTOR_151_MODE')==='apply';
    if ($apply && getenv('ROUTE_EXECUTOR_151_CONFIRM')!=='APPLY_ROUTE_EXECUTOR_151') {
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
        if ((string)$pdo->query('SELECT DATABASE()')->fetchColumn()!==(string)$company['db_identifier']) {
            throw new RuntimeException('Unexpected tenant database');
        }
        $lockName='planex_migrate_'.substr(hash('sha256',(string)$company['db_identifier']),0,32);
        $q=$pdo->prepare('SELECT GET_LOCK(?,10)');$q->execute([$lockName]);
        if ((int)$q->fetchColumn()!==1) throw new RuntimeException('Tenant migration lock unavailable');
        try {
            $q=$pdo->prepare('SELECT checksum FROM schema_migrations WHERE migration=?');
            $q->execute(['150_create_waste_trip_completion_reviews.sql']);
            if ($q->fetchColumn()===false) throw new RuntimeException('Tenant migration 150 prerequisite missing');
            $q->execute([$name]);$stored=$q->fetchColumn();
            if ($stored!==false && !hash_equals((string)$stored,hash('sha256',$sql))) {
                throw new RuntimeException('Migration 151 journal checksum mismatch');
            }
            $q=$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='route_executor_review_sends'");
            $exists=(int)$q->fetchColumn()===1;
            if (($stored!==false)!==$exists) throw new RuntimeException('Unjournaled route executor review table');
            $crewsBefore=(int)$pdo->query('SELECT COUNT(*) FROM crews')->fetchColumn();
            if ($apply && !$exists) {
                $pdo->exec($sql);
                $pdo->prepare('INSERT INTO schema_migrations(migration,checksum) VALUES(?,?)')
                    ->execute([$name,hash('sha256',$sql)]);
                $exists=true;
            }
            $q=$pdo->prepare('SELECT checksum FROM schema_migrations WHERE migration=?');$q->execute([$name]);
            if ($exists && !hash_equals(hash('sha256',$sql),(string)$q->fetchColumn())) {
                throw new RuntimeException('Migration 151 verification failed');
            }
            if ($crewsBefore!==(int)$pdo->query('SELECT COUNT(*) FROM crews')->fetchColumn()) {
                throw new RuntimeException('Existing route executor business data changed');
            }
            $results[]=['company_id'=>(int)$company['id'],'ready'=>$exists,
                'send_count'=>$exists?(int)$pdo->query('SELECT COUNT(*) FROM route_executor_review_sends')->fetchColumn():0];
        } finally { $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]); }
    }
    echo 'ROUTE_EXECUTOR_151_SNAPSHOT='.json_encode($results,JSON_THROW_ON_ERROR)."\n";
    echo $apply?"ROUTE_EXECUTOR_151_APPLY_OK\n":"ROUTE_EXECUTOR_151_READ_ONLY_OK\n";
} catch (Throwable $error) {
    fwrite(STDERR,'ROUTE_EXECUTOR_151_FAILED: '.$error->getMessage()."\n");exit(1);
}
