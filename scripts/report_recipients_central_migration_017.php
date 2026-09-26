<?php
declare(strict_types=1);

// Additive central migration for report recipients and journal page grants.
if (PHP_SAPI!=='cli') exit(1);
try {
    $root=rtrim((string)getenv('PLANEX_ROOT'),'/');
    $sha=(string)getenv('PLANEX_SOURCE_SHA');$deployed=(string)getenv('PLANEX_DEPLOYED_SHA');
    $expected=(string)getenv('PLANEX_MIGRATION_SHA256');
    if (!preg_match('/^[a-f0-9]{40}$/D',$sha) || $sha!==$deployed || !preg_match('/^[a-f0-9]{64}$/D',$expected)) throw new RuntimeException('Exact deployment guard required');
    $marker=(string)(getenv('PLANEX_DEPLOY_MARKER')?:'/home/s/spugovxsim/planexp/deploy/P07_erpv2_deployed_commit.txt');
    if (trim((string)file_get_contents($marker))!==$sha) throw new RuntimeException('Deployment marker mismatch');
    $name='017_registration_journal_and_report_recipients.sql';
    $sql=(string)file_get_contents($root.'/database/migrations/'.$name);
    if (!hash_equals($expected,hash('sha256',$sql))) throw new RuntimeException('Migration checksum mismatch');
    $apply=getenv('REPORT_RECIPIENTS_017_MODE')==='apply';
    if ($apply && getenv('REPORT_RECIPIENTS_017_CONFIRM')!=='APPLY_REPORT_RECIPIENTS_017') throw new RuntimeException('Explicit migration confirmation required');
    $config=require $root.'/bootstrap/app.php';$db=$config['database'];
    $pdo=new PDO('mysql:host='.$db['host'].';port='.($db['port']??3306).';dbname='.$db['database'].';charset=utf8mb4',$db['username'],$db['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    $lock='planex_central_017_report_recipients';$q=$pdo->prepare('SELECT GET_LOCK(?,30)');$q->execute([$lock]);
    if ((int)$q->fetchColumn()!==1) throw new RuntimeException('Migration lock unavailable');
    try {
        $exists=static function(string $table) use($pdo):bool {$q=$pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');$q->execute([$table]);return (int)$q->fetchColumn()===1;};
        foreach (['schema_migrations','companies','user_accounts','company_memberships','company_roles','role_page_permissions','membership_page_overrides','employee_mail_profiles'] as $table) if (!$exists($table)) throw new RuntimeException('Prerequisite missing: '.$table);
        $journal=$pdo->prepare('SELECT checksum FROM schema_migrations WHERE migration=?');
        $journal->execute(['016_create_employee_mail_profiles.sql']);if ($journal->fetchColumn()===false) throw new RuntimeException('Migration 016 prerequisite missing');
        $journal->execute([$name]);$stored=$journal->fetchColumn();
        $tables=$exists('company_report_email_settings') && $exists('company_report_email_recipients');
        if ($stored!==false && !hash_equals((string)$stored,$expected)) throw new RuntimeException('Journal checksum mismatch');
        if (($stored!==false)!==$tables) throw new RuntimeException('Partial or unjournaled schema');
        $before=(int)$pdo->query('SELECT COUNT(*) FROM user_accounts')->fetchColumn();
        if ($apply && $stored===false) {
            if ((int)$pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE migration REGEXP '^[0-9]{3}_' AND CAST(LEFT(migration,3) AS UNSIGNED)>17")->fetchColumn()>0) throw new RuntimeException('Newer central migration exists');
            $statements=array_values(array_filter(array_map('trim',explode(';',(string)preg_replace('/^\s*--[^\n]*$/m','',$sql)))));
            if (count($statements)!==5 || !str_starts_with($statements[0],'CREATE TABLE IF NOT EXISTS company_report_email_settings') || !str_starts_with($statements[1],'CREATE TABLE IF NOT EXISTS company_report_email_recipients')) throw new RuntimeException('Unexpected SQL scope');
            foreach ($statements as $statement) $pdo->exec($statement);
            $pdo->prepare('INSERT INTO schema_migrations(migration,checksum) VALUES(?,?)')->execute([$name,$expected]);
            $stored=$expected;$tables=true;
        }
        if ($before!==(int)$pdo->query('SELECT COUNT(*) FROM user_accounts')->fetchColumn()) throw new RuntimeException('Identity rows changed');
        $recipientRows=$tables?(int)$pdo->query('SELECT COUNT(*) FROM company_report_email_recipients')->fetchColumn():0;
        echo 'REPORT_RECIPIENTS_017_SNAPSHOT='.json_encode(['ready'=>$tables,'recipients'=>$recipientRows,'identity_rows'=>$before],JSON_THROW_ON_ERROR)."\n";
        echo $apply?"REPORT_RECIPIENTS_017_APPLY_OK\n":"REPORT_RECIPIENTS_017_READ_ONLY_OK\n";
    } finally {$q=$pdo->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$lock]);}
} catch (Throwable $error) {fwrite(STDERR,'REPORT_RECIPIENTS_017_FAILED: '.$error->getMessage()."\n");exit(1);}
