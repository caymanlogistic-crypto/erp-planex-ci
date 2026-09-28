<?php
declare(strict_types=1);

// Production migration is read-only unless the exact deployed SHA and explicit confirmation match.
if (PHP_SAPI !== 'cli') exit(1);
try {
    $root=rtrim((string)getenv('PLANEX_ROOT'),'/');
    $sha=(string)getenv('PLANEX_SOURCE_SHA');
    $marker=(string)getenv('PLANEX_DEPLOY_MARKER');
    if (!is_file($root.'/.env') || !is_file($root.'/bootstrap/app.php') || !is_file($marker)
        || !preg_match('/^[a-f0-9]{40}$/D',$sha) || trim((string)file_get_contents($marker))!==$sha) throw new RuntimeException('Exact deployment guard required');
    $name='020_route_executor_review_email_recipients.sql';
    $sql=(string)file_get_contents($root.'/database/migrations/'.$name);
    if (!hash_equals((string)getenv('PLANEX_MIGRATION_SHA256'),hash('sha256',$sql))) throw new RuntimeException('Migration checksum mismatch');
    if (substr_count($sql,'CREATE TABLE IF NOT EXISTS')!==2 || substr_count($sql,'company_route_executor_review_email_settings')<1
        || substr_count($sql,'company_route_executor_review_email_recipients')<1 || substr_count($sql,';')!==2) throw new RuntimeException('Migration scope changed');
    $apply=getenv('ROUTE_EXECUTOR_REVIEW_020_MODE')==='apply';
    if ($apply && getenv('ROUTE_EXECUTOR_REVIEW_020_CONFIRM')!=='APPLY_ROUTE_EXECUTOR_REVIEW_020') throw new RuntimeException('Explicit migration confirmation required');
    $config=require $root.'/bootstrap/app.php';
    $db=$config['database'];
    $pdo=new PDO('mysql:host='.$db['host'].';port='.($db['port']??3306).';dbname='.$db['database'].';charset=utf8mb4',
        $db['username'],$db['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    $lock=$pdo->prepare('SELECT GET_LOCK(?,10)');$lock->execute(['planex_migrate_central_020']);
    if ((int)$lock->fetchColumn()!==1) throw new RuntimeException('Central migration lock unavailable');
    try {
        $journal=$pdo->prepare('SELECT checksum FROM schema_migrations WHERE migration=?');
        $journal->execute(['019_employee_mail_default_sender.sql']);
        if ($journal->fetchColumn()===false) throw new RuntimeException('Central migration 019 prerequisite missing');
        $journal->execute([$name]);$stored=$journal->fetchColumn();
        if ($stored!==false && !hash_equals((string)$stored,hash('sha256',$sql))) throw new RuntimeException('Journal checksum mismatch');
        $tableCount=(int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('company_route_executor_review_email_settings','company_route_executor_review_email_recipients')")->fetchColumn();
        if (($stored!==false)!==($tableCount===2)) throw new RuntimeException('Unjournaled route executor review recipient tables');
        $companyCount=(int)$pdo->query('SELECT COUNT(*) FROM companies')->fetchColumn();
        if ($apply && $stored===false) {
            $pdo->exec($sql);
            $pdo->prepare('INSERT INTO schema_migrations(migration,checksum) VALUES(?,?)')->execute([$name,hash('sha256',$sql)]);
            $stored=hash('sha256',$sql);
        }
        if ((int)$pdo->query('SELECT COUNT(*) FROM companies')->fetchColumn()!==$companyCount) throw new RuntimeException('Existing companies changed');
        if ($stored!==false) {
            $tableCount=(int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('company_route_executor_review_email_settings','company_route_executor_review_email_recipients')")->fetchColumn();
            if ($tableCount!==2) throw new RuntimeException('Route executor review recipient tables missing');
        }
        echo 'ROUTE_EXECUTOR_REVIEW_020_SNAPSHOT='.json_encode(['ready'=>$stored!==false,'companies'=>$companyCount],JSON_THROW_ON_ERROR)."\n";
        echo $apply?"ROUTE_EXECUTOR_REVIEW_020_APPLY_OK\n":"ROUTE_EXECUTOR_REVIEW_020_READ_ONLY_OK\n";
    } finally {$pdo->prepare('SELECT RELEASE_LOCK(?)')->execute(['planex_migrate_central_020']);}
} catch (Throwable $error) {fwrite(STDERR,'ROUTE_EXECUTOR_REVIEW_020_FAILED: '.$error->getMessage()."\n");exit(1);}
