<?php
declare(strict_types=1);

// Read-only inspection is the default. Writes require the exact deployed SHA and confirmation.
if (PHP_SAPI !== 'cli') exit(1);
try {
    $root=rtrim((string)getenv('PLANEX_ROOT'),'/');
    $sha=(string)getenv('PLANEX_SOURCE_SHA');
    $marker=(string)getenv('PLANEX_DEPLOY_MARKER');
    if (!is_file($root.'/.env') || !is_file($root.'/bootstrap/app.php') || !is_file($marker)
        || !preg_match('/^[a-f0-9]{40}$/D',$sha) || trim((string)file_get_contents($marker))!==$sha) throw new RuntimeException('Exact deployment guard required');
    $centralName='018_report_email_sender.sql';
    $tenantName='150_create_waste_trip_completion_reviews.sql';
    $centralSql=(string)file_get_contents($root.'/database/migrations/'.$centralName);
    $tenantSql=(string)file_get_contents($root.'/database/migrations-local/'.$tenantName);
    if (!hash_equals((string)getenv('PLANEX_CENTRAL_SHA256'),hash('sha256',$centralSql))
        || !hash_equals((string)getenv('PLANEX_TENANT_SHA256'),hash('sha256',$tenantSql))) throw new RuntimeException('Migration checksum mismatch');
    if (!str_starts_with(trim($centralSql),'ALTER TABLE company_report_email_settings')
        || substr_count($centralSql,'ADD COLUMN')!==1 || substr_count($centralSql,';')!==1) throw new RuntimeException('Central SQL scope changed');
    if (!str_starts_with(trim($tenantSql),'ALTER TABLE waste_trip_registration_journal MODIFY waste_trip_request_id BIGINT UNSIGNED NULL;')
        || substr_count($tenantSql,'CREATE TABLE')!==1 || substr_count($tenantSql,';')!==2) throw new RuntimeException('Tenant SQL scope changed');
    $apply=getenv('TRIP_REVIEW_150_MODE')==='apply';
    if ($apply && getenv('TRIP_REVIEW_150_CONFIRM')!=='APPLY_TRIP_REVIEW_150') throw new RuntimeException('Explicit migration confirmation required');
    $config=require $root.'/bootstrap/app.php';
    require_once $root.'/app/Support/crypto_helper.php';
    require_once $root.'/app/Support/company_database.php';
    $connect=static fn(array $db):PDO=>new PDO('mysql:host='.$db['host'].';port='.($db['port']??3306).';dbname='.$db['database'].';charset=utf8mb4',$db['username'],$db['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    $exists=static function(PDO $pdo,string $kind,string $name,string $table=''):bool {
        $catalog=$kind==='table'?'tables':'columns';
        $sql='SELECT COUNT(*) FROM information_schema.'.$catalog.' WHERE table_schema=DATABASE() AND '.($kind==='table'?'table_name=?':'table_name=? AND column_name=?');
        $q=$pdo->prepare($sql);$q->execute($kind==='table'?[$name]:[$table,$name]);return (int)$q->fetchColumn()===1;
    };
    $requestColumnNullable=static function(PDO $pdo):bool {
        $q=$pdo->query("SELECT IS_NULLABLE FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='waste_trip_registration_journal' AND column_name='waste_trip_request_id'");
        return $q->fetchColumn()==='YES';
    };
    $checkJournal=static function(PDO $pdo,string $name,string $sql,string $prerequisite,bool $present):bool {
        if (!$present) throw new RuntimeException('Prerequisite missing: '.$prerequisite);
        $q=$pdo->prepare('SELECT checksum FROM schema_migrations WHERE migration=?');
        $q->execute([$name]);$stored=$q->fetchColumn();
        if ($stored!==false && !hash_equals((string)$stored,hash('sha256',$sql))) throw new RuntimeException('Journal checksum mismatch: '.$name);
        return $stored!==false;
    };
    $central=$connect($config['database']);
    $lock=$central->prepare('SELECT GET_LOCK(?,10)');$lock->execute(['planex_migrate_central_018']);
    if ((int)$lock->fetchColumn()!==1) throw new RuntimeException('Central migration lock unavailable');
    try {
        $q=$central->prepare('SELECT checksum FROM schema_migrations WHERE migration=?');
        $q->execute(['017_registration_journal_and_report_recipients.sql']);
        if ($q->fetchColumn()===false) throw new RuntimeException('Central migration 017 prerequisite missing');
        $centralApplied=$checkJournal($central,$centralName,$centralSql,'schema_migrations',$exists($central,'table','schema_migrations'));
        if ($centralApplied!==$exists($central,'column','sender_profile_id','company_report_email_settings')) throw new RuntimeException('Unjournaled central sender column');
        if ($apply && !$centralApplied) {
            $central->exec($centralSql);
            $central->prepare('INSERT INTO schema_migrations(migration,checksum) VALUES(?,?)')->execute([$centralName,hash('sha256',$centralSql)]);
            $centralApplied=true;
        }
        $companies=$central->query("SELECT * FROM companies WHERE status='active' AND db_identifier IS NOT NULL AND db_identifier<>'' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    } finally {$central->prepare('SELECT RELEASE_LOCK(?)')->execute(['planex_migrate_central_018']);}
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
            $q->execute(['149_waste_trip_legacy_metadata.sql']);
            if ($q->fetchColumn()===false) throw new RuntimeException('Tenant migration 149 prerequisite missing');
            if (!$exists($pdo,'table','waste_trip_registration_journal')) throw new RuntimeException('Registration journal prerequisite missing');
            $applied=$checkJournal($pdo,$tenantName,$tenantSql,'waste_trips',$exists($pdo,'table','waste_trips'));
            if ($applied!==$exists($pdo,'table','waste_trip_completion_reviews') || $applied!==$requestColumnNullable($pdo)) throw new RuntimeException('Unjournaled tenant review schema');
            if ($apply && !$applied) {
                foreach (array_filter(array_map('trim',explode(';',$tenantSql))) as $statement) $pdo->exec($statement);
                $pdo->prepare('INSERT INTO schema_migrations(migration,checksum) VALUES(?,?)')->execute([$tenantName,hash('sha256',$tenantSql)]);
                $applied=true;
            }
            $results[]=['company_id'=>(int)$company['id'],'ready'=>$applied,'trip_count'=>(int)$pdo->query('SELECT COUNT(*) FROM waste_trips')->fetchColumn()];
        } finally {$pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);}
    }
    echo 'TRIP_REVIEW_150_SNAPSHOT='.json_encode(['central_ready'=>$centralApplied,'tenants'=>$results],JSON_THROW_ON_ERROR)."\n";
    echo $apply?"TRIP_REVIEW_150_APPLY_OK\n":"TRIP_REVIEW_150_READ_ONLY_OK\n";
} catch (Throwable $error) { fwrite(STDERR,'TRIP_REVIEW_150_FAILED: '.$error->getMessage()."\n");exit(1); }
