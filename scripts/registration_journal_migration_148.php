<?php
declare(strict_types=1);

// Guarded tenant migration. Inspection is read-only; apply requires an exact marker.
if (PHP_SAPI!=='cli') exit(1);
try {
    $root=rtrim((string)getenv('PLANEX_ROOT'),'/');
    if (!is_file($root.'/.env') || !is_file($root.'/bootstrap/app.php')) throw new RuntimeException('Invalid root');
    $sha=(string)getenv('PLANEX_SOURCE_SHA');
    $deployed=(string)getenv('PLANEX_DEPLOYED_SHA');
    $expected=(string)getenv('PLANEX_MIGRATION_SHA256');
    if (!preg_match('/^[a-f0-9]{40}$/D',$sha) || $sha!==$deployed || !preg_match('/^[a-f0-9]{64}$/D',$expected)) throw new RuntimeException('Exact deployment guard required');
    $marker=(string)(getenv('PLANEX_DEPLOY_MARKER')?:'/home/s/spugovxsim/planexp/deploy/P07_erpv2_deployed_commit.txt');
    if (trim((string)file_get_contents($marker))!==$sha) throw new RuntimeException('Deployment marker mismatch');
    $name='148_create_registration_journal.sql';
    $sql=(string)file_get_contents($root.'/database/migrations-local/'.$name);
    if (!hash_equals($expected,hash('sha256',$sql))) throw new RuntimeException('Migration checksum mismatch');
    $apply=getenv('REGISTRATION_JOURNAL_148_MODE')==='apply';
    if ($apply && getenv('REGISTRATION_JOURNAL_148_CONFIRM')!=='APPLY_REGISTRATION_JOURNAL_148') throw new RuntimeException('Explicit migration confirmation required');
    $config=require $root.'/bootstrap/app.php';
    require_once $root.'/app/Support/crypto_helper.php';
    require_once $root.'/app/Support/company_database.php';
    $connect=static fn(array $db):PDO=>new PDO('mysql:host='.$db['host'].';port='.($db['port']??3306).';dbname='.$db['database'].';charset=utf8mb4',$db['username'],$db['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $central=$connect($config['database']);
    $companies=$central->query("SELECT * FROM companies WHERE status='active' AND db_identifier IS NOT NULL AND db_identifier<>'' ORDER BY id")->fetchAll();
    if (!$companies) throw new RuntimeException('No active tenants');
    $exists=static function(PDO $pdo,string $table):bool {
        $q=$pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');$q->execute([$table]);return (int)$q->fetchColumn()===1;
    };
    $hash=static function(PDO $pdo,string $table):string {
        $order=$table==='waste_trip_completion_items'?'waste_trip_request_id':'id';
        $state=hash_init('sha256');foreach($pdo->query('SELECT * FROM `'.$table.'` ORDER BY `'.$order.'`') as $row) hash_update($state,json_encode($row,JSON_THROW_ON_ERROR)."\n");return hash_final($state);
    };
    $results=[];
    foreach ($companies as $company) {
        $pdo=$connect(companyDatabaseConfig($config,$company));
        if ((string)$pdo->query('SELECT DATABASE()')->fetchColumn()!==(string)$company['db_identifier']) throw new RuntimeException('Wrong tenant database');
        $lock='planex_migrate_'.substr(hash('sha256',(string)$company['db_identifier']),0,32);
        $q=$pdo->prepare('SELECT GET_LOCK(?,10)');$q->execute([$lock]);if ((int)$q->fetchColumn()!==1) throw new RuntimeException('Migration lock unavailable');
        try {
            foreach (['schema_migrations','waste_trips','waste_trip_requests','waste_trip_completion_items','waste_trip_completion_documents'] as $table)
                if (!$exists($pdo,$table)) throw new RuntimeException('Prerequisite missing: '.$table);
            $q=$pdo->prepare('SELECT checksum FROM schema_migrations WHERE migration=?');
            $q->execute(['147_trip_completion_tn_absent.sql']);if ($q->fetchColumn()===false) throw new RuntimeException('Migration 147 prerequisite missing');
            $q->execute([$name]);$stored=$q->fetchColumn();$tableExists=$exists($pdo,'waste_trip_registration_journal');
            if ($stored!==false && !hash_equals((string)$stored,$expected)) throw new RuntimeException('Journal checksum mismatch');
            if (($stored!==false)!==$tableExists) throw new RuntimeException('Partial or unjournaled schema');
            $beforeItems=$hash($pdo,'waste_trip_completion_items');$beforeDocuments=$hash($pdo,'waste_trip_completion_documents');
            $itemCount=(int)$pdo->query('SELECT COUNT(*) FROM waste_trip_completion_items')->fetchColumn();
            $documentCount=(int)$pdo->query('SELECT COUNT(*) FROM waste_trip_completion_documents')->fetchColumn();
            $justApplied=$apply && $stored===false;
            if ($justApplied) {
                if ((int)$pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE migration REGEXP '^[0-9]{3}_' AND CAST(LEFT(migration,3) AS UNSIGNED)>148")->fetchColumn()>0) throw new RuntimeException('Newer tenant migration exists');
                $statements=array_values(array_filter(array_map('trim',explode(';',(string)preg_replace('/^\s*--[^\n]*$/m','',$sql)))));
                if (count($statements)!==3 || !str_starts_with($statements[0],'CREATE TABLE IF NOT EXISTS waste_trip_registration_journal') || !str_starts_with($statements[1],'INSERT INTO waste_trip_registration_journal') || !str_starts_with($statements[2],'INSERT INTO waste_trip_registration_journal')) throw new RuntimeException('Unexpected SQL scope');
                foreach ($statements as $statement) $pdo->exec($statement);
                $pdo->prepare('INSERT INTO schema_migrations(migration,checksum) VALUES(?,?)')->execute([$name,$expected]);
                $stored=$expected;$tableExists=true;
            }
            if ($justApplied) {
                $rows=(int)$pdo->query("SELECT COUNT(*) FROM waste_trip_registration_journal WHERE event_kind='initial'")->fetchColumn();
                if ($rows<$itemCount+$documentCount) throw new RuntimeException('Initial journal backfill incomplete');
            }
            if ($beforeItems!==$hash($pdo,'waste_trip_completion_items') || $beforeDocuments!==$hash($pdo,'waste_trip_completion_documents')) throw new RuntimeException('Existing registration data changed');
            $results[]=['company_id'=>(int)$company['id'],'journal_ready'=>$tableExists,'initial_rows'=>$tableExists?(int)$pdo->query("SELECT COUNT(*) FROM waste_trip_registration_journal WHERE event_kind='initial'")->fetchColumn():0,'completion_rows'=>$itemCount,'document_rows'=>$documentCount];
        } finally {$q=$pdo->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$lock]);}
    }
    echo 'REGISTRATION_JOURNAL_148_SNAPSHOT='.json_encode($results,JSON_THROW_ON_ERROR)."\n";
    echo $apply?"REGISTRATION_JOURNAL_148_APPLY_OK\n":"REGISTRATION_JOURNAL_148_READ_ONLY_OK\n";
} catch (Throwable $error) { fwrite(STDERR,'REGISTRATION_JOURNAL_148_FAILED: '.$error->getMessage()."\n");exit(1); }
