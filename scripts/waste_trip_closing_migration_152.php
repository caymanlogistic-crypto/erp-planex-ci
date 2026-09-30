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
    $name='152_waste_trip_closing_documents.sql';
    $sql=(string)file_get_contents($root.'/database/migrations-local/'.$name);
    if (!hash_equals((string)getenv('PLANEX_TENANT_SHA256'),hash('sha256',$sql))
        || !str_starts_with(trim($sql),'CREATE TABLE IF NOT EXISTS waste_trip_closing_documents (')
        || substr_count($sql,'CREATE TABLE IF NOT EXISTS')!==3 || substr_count($sql,';')!==3) {
        throw new RuntimeException('Migration checksum or SQL scope mismatch');
    }
    $apply=getenv('WASTE_TRIP_CLOSING_152_MODE')==='apply';
    if ($apply && getenv('WASTE_TRIP_CLOSING_152_CONFIRM')!=='APPLY_WASTE_TRIP_CLOSING_152') {
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
            foreach (['077_finance_closing_documents.sql','121_closing_document_evidence_overrides.sql',
                '128_create_waste_trips.sql','134_create_waste_trip_customer_payments.sql'] as $prerequisite) {
                $q->execute([$prerequisite]);
                if ($q->fetchColumn()===false) throw new RuntimeException('Tenant prerequisite missing: '.$prerequisite);
            }
            $q->execute([$name]);$stored=$q->fetchColumn();
            if ($stored!==false && !hash_equals((string)$stored,hash('sha256',$sql))) {
                throw new RuntimeException('Migration 152 journal checksum mismatch');
            }
            $tables=['waste_trip_closing_documents','waste_trip_closing_reviews','waste_trip_closing_finance_links'];
            $tableQuery=$pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
            $existing=0;
            foreach ($tables as $table) {$tableQuery->execute([$table]);$existing+=(int)$tableQuery->fetchColumn();}
            if ($existing!==0 && $existing!==3) throw new RuntimeException('Partial waste trip closing schema');
            $exists=$existing===3;
            if (($stored!==false)!==$exists) throw new RuntimeException('Unjournaled waste trip closing tables');
            $tripsBefore=(int)$pdo->query('SELECT COUNT(*) FROM waste_trips')->fetchColumn();
            $documentsBefore=(int)$pdo->query('SELECT COUNT(*) FROM documents')->fetchColumn();
            $financeBefore=(int)$pdo->query('SELECT COUNT(*) FROM finance_closing_document_links')->fetchColumn();
            if ($apply && !$exists) {
                $pdo->exec($sql);
                $pdo->prepare('INSERT INTO schema_migrations(migration,checksum) VALUES(?,?)')
                    ->execute([$name,hash('sha256',$sql)]);
                $exists=true;
            }
            $q=$pdo->prepare('SELECT checksum FROM schema_migrations WHERE migration=?');$q->execute([$name]);
            if ($exists && !hash_equals(hash('sha256',$sql),(string)$q->fetchColumn())) {
                throw new RuntimeException('Migration 152 verification failed');
            }
            if ($tripsBefore!==(int)$pdo->query('SELECT COUNT(*) FROM waste_trips')->fetchColumn()
                || $documentsBefore!==(int)$pdo->query('SELECT COUNT(*) FROM documents')->fetchColumn()
                || $financeBefore!==(int)$pdo->query('SELECT COUNT(*) FROM finance_closing_document_links')->fetchColumn()) {
                throw new RuntimeException('Existing trip or document business data changed');
            }
            $results[]=['company_id'=>(int)$company['id'],'ready'=>$exists,
                'document_count'=>$exists?(int)$pdo->query('SELECT COUNT(*) FROM waste_trip_closing_documents')->fetchColumn():0];
        } finally { $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]); }
    }
    echo 'WASTE_TRIP_CLOSING_152_SNAPSHOT='.json_encode($results,JSON_THROW_ON_ERROR)."\n";
    echo $apply?"WASTE_TRIP_CLOSING_152_APPLY_OK\n":"WASTE_TRIP_CLOSING_152_READ_ONLY_OK\n";
} catch (Throwable $error) {
    fwrite(STDERR,'WASTE_TRIP_CLOSING_152_FAILED: '.$error->getMessage()."\n");exit(1);
}
