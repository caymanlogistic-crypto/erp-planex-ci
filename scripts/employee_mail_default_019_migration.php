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
    $name='019_employee_mail_default_sender.sql';
    $sql=(string)file_get_contents($root.'/database/migrations/'.$name);
    if (!hash_equals((string)getenv('PLANEX_MIGRATION_SHA256'),hash('sha256',$sql))) throw new RuntimeException('Migration checksum mismatch');
    if (!str_starts_with(trim($sql),'-- One preferred working mailbox') || substr_count($sql,'ALTER TABLE employee_mail_profiles')!==1
        || substr_count($sql,'ADD COLUMN is_default')!==1 || substr_count($sql,';')!==1) throw new RuntimeException('Migration scope changed');
    $apply=getenv('EMPLOYEE_MAIL_DEFAULT_019_MODE')==='apply';
    if ($apply && getenv('EMPLOYEE_MAIL_DEFAULT_019_CONFIRM')!=='APPLY_EMPLOYEE_MAIL_DEFAULT_019') throw new RuntimeException('Explicit migration confirmation required');
    $config=require $root.'/bootstrap/app.php';
    $db=$config['database'];
    $pdo=new PDO('mysql:host='.$db['host'].';port='.($db['port']??3306).';dbname='.$db['database'].';charset=utf8mb4',
        $db['username'],$db['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    $lock=$pdo->prepare('SELECT GET_LOCK(?,10)');$lock->execute(['planex_migrate_central_019']);
    if ((int)$lock->fetchColumn()!==1) throw new RuntimeException('Central migration lock unavailable');
    try {
        $journal=$pdo->prepare('SELECT checksum FROM schema_migrations WHERE migration=?');
        $journal->execute(['018_report_email_sender.sql']);
        if ($journal->fetchColumn()===false) throw new RuntimeException('Central migration 018 prerequisite missing');
        $journal->execute([$name]);$stored=$journal->fetchColumn();
        if ($stored!==false && !hash_equals((string)$stored,hash('sha256',$sql))) throw new RuntimeException('Journal checksum mismatch');
        $column=$pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='employee_mail_profiles' AND column_name='is_default'")->fetchColumn();
        if (($stored!==false)!==((int)$column===1)) throw new RuntimeException('Unjournaled default mailbox column');
        $before=$pdo->query('SELECT COUNT(*) total,COALESCE(SUM(version),0) versions FROM employee_mail_profiles')->fetch(PDO::FETCH_ASSOC);
        if ($apply && $stored===false) {
            $pdo->exec($sql);
            $pdo->prepare('INSERT INTO schema_migrations(migration,checksum) VALUES(?,?)')->execute([$name,hash('sha256',$sql)]);
            $stored=hash('sha256',$sql);
        }
        $after=$pdo->query('SELECT COUNT(*) total,COALESCE(SUM(version),0) versions FROM employee_mail_profiles')->fetch(PDO::FETCH_ASSOC);
        if ($before!==$after) throw new RuntimeException('Existing mail profiles changed');
        if ($stored!==false) {
            $duplicates=$pdo->query('SELECT account_id,company_id FROM employee_mail_profiles GROUP BY account_id,company_id HAVING SUM(is_default)>1 LIMIT 1')->fetch();
            if ($duplicates) throw new RuntimeException('Multiple default senders for one employee');
        }
        echo 'EMPLOYEE_MAIL_DEFAULT_019_SNAPSHOT='.json_encode(['ready'=>$stored!==false,'profiles'=>(int)$after['total'],'versions'=>(int)$after['versions']],JSON_THROW_ON_ERROR)."\n";
        echo $apply?"EMPLOYEE_MAIL_DEFAULT_019_APPLY_OK\n":"EMPLOYEE_MAIL_DEFAULT_019_READ_ONLY_OK\n";
    } finally {$pdo->prepare('SELECT RELEASE_LOCK(?)')->execute(['planex_migrate_central_019']);}
} catch (Throwable $error) {fwrite(STDERR,'EMPLOYEE_MAIL_DEFAULT_019_FAILED: '.$error->getMessage()."\n");exit(1);}
