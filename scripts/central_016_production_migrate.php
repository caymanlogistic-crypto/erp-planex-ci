<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') exit(1);
// Additive central schema only; profile population is a separate authorized step.
try {
    $mode=$argv[1]??'inspect';
    if (!in_array($mode,['inspect','apply','rollback'],true)) throw new RuntimeException('Invalid mode');
    $root=rtrim((string)getenv('PLANEX_ROOT'),'/');
    $sha=(string)getenv('PLANEX_SOURCE_SHA');
    $deployed=(string)(getenv('PLANEX_DEPLOYED_SHA')?:$sha);
    $expected=(string)getenv('PLANEX_MIGRATION_SHA256');
    if (!preg_match('/^[a-f0-9]{40}$/D',$sha) || $sha!==$deployed || !preg_match('/^[a-f0-9]{64}$/D',$expected)) throw new RuntimeException('Exact deployment guard required');
    $marker=(string)(getenv('PLANEX_DEPLOY_MARKER')?:'/home/s/spugovxsim/planexp/deploy/P07_erpv2_deployed_commit.txt');
    if (trim((string)file_get_contents($marker))!==$sha) throw new RuntimeException('Exact deployed marker mismatch');
    $name='016_create_employee_mail_profiles.sql';
    $sql=(string)file_get_contents($root.'/database/migrations/'.$name);
    if (!hash_equals($expected,hash('sha256',$sql))) throw new RuntimeException('Migration checksum mismatch');
    $config=require $root.'/bootstrap/app.php';
    $db=$config['database'];
    $pdo=new PDO('mysql:host='.$db['host'].';port='.$db['port'].';dbname='.$db['database'].';charset=utf8mb4',$db['username'],$db['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    $lock='planex_central_016_employee_mail';
    $q=$pdo->prepare('SELECT GET_LOCK(?,30)');$q->execute([$lock]);
    if ((int)$q->fetchColumn()!==1) throw new RuntimeException('Migration lock unavailable');
    try {
        $journal=static function() use($pdo,$name):string|false {$q=$pdo->prepare('SELECT checksum FROM schema_migrations WHERE migration=?');$q->execute([$name]);return $q->fetchColumn();};
        $exists=static function(string $table) use($pdo):bool {$q=$pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');$q->execute([$table]);return (int)$q->fetchColumn()===1;};
        $verify=static function() use($pdo,$journal,$expected):void {
            if ($journal()!==$expected) throw new RuntimeException('Journal mismatch');
            foreach (['employee_mail_settings'=>['company_id','imap_host','imap_port','smtp_host','smtp_port','encryption'],'employee_mail_legal_entities'=>['id','company_id','code','name','email_domain'],'employee_mail_profiles'=>['id','account_id','company_id','legal_entity_id','email','password_encrypted','connection_status','checked_at','version','created_at','updated_at']] as $table=>$columns) {
                $q=$pdo->prepare('SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? ORDER BY ORDINAL_POSITION');$q->execute([$table]);
                if ($q->fetchAll(PDO::FETCH_COLUMN)!==$columns) throw new RuntimeException('Schema shape mismatch');
            }
            $indexes=(int)$pdo->query("SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='employee_mail_profiles' AND NON_UNIQUE=0")->fetchColumn();
            $fks=(int)$pdo->query("SELECT COUNT(*) FROM information_schema.referential_constraints WHERE constraint_schema=DATABASE() AND table_name='employee_mail_profiles'")->fetchColumn();
            if ($indexes!==3 || $fks!==2) throw new RuntimeException('Schema keys mismatch');
        };
        $snapshot=static function() use($pdo):array {
            $rows=$pdo->query('SELECT id,full_name,login,normalized_login,email,phone,password_hash,status,max_user_id FROM user_accounts ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
            return ['rows'=>count($rows),'hash'=>hash('sha256',json_encode($rows,JSON_THROW_ON_ERROR))];
        };
        $q=$pdo->prepare('SELECT checksum FROM schema_migrations WHERE migration=?');$q->execute(['015_add_user_accounts_max_user_id.sql']);
        if ($q->fetchColumn()!==hash_file('sha256',$root.'/database/migrations/015_add_user_accounts_max_user_id.sql')) throw new RuntimeException('Migration 015 prerequisite mismatch');
        $stored=$journal();$a=$exists('employee_mail_legal_entities');$b=$exists('employee_mail_profiles');$c=$exists('employee_mail_settings');$before=$snapshot();
        $newer=(int)$pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE migration REGEXP '^[0-9]{3}_' AND CAST(LEFT(migration,3) AS UNSIGNED)>16")->fetchColumn();
        printf("CENTRAL_016_MARKER=%s\nCENTRAL_016_CHECKSUM=%s\nCENTRAL_016_ROWS=%d\nCENTRAL_016_IDENTITY_HASH=%s\nCENTRAL_016_JOURNALED=%s\nCENTRAL_016_TABLE=%s\n",$sha,$expected,$before['rows'],$before['hash'],$stored===false?'NO':'YES',$a&&$b&&$c?'YES':'NO');
        if ($mode==='inspect') {
            if ($stored!==false) {$verify();echo "CENTRAL_016_INSPECT=ALREADY_APPLIED\n";}
            else {if($a||$b||$c||$newer)throw new RuntimeException('Unjournaled partial/newer schema');echo "CENTRAL_016_INSPECT=READY\n";}
        } elseif ($mode==='rollback') {
            if (getenv('PLANEX_CONFIRM_016')!=='ROLLBACK_CENTRAL_016_EMPLOYEE_MAIL' || $newer) throw new RuntimeException('Rollback guard required');
            if ($stored!==false) $verify();
            foreach (['employee_mail_profiles','employee_mail_legal_entities','employee_mail_settings'] as $table) {
                if ($exists($table) && (int)$pdo->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn()!==0) throw new RuntimeException('Rollback blocked: mail configuration exists');
            }
            $pdo->exec('DROP TABLE IF EXISTS employee_mail_profiles');$pdo->exec('DROP TABLE IF EXISTS employee_mail_legal_entities');
            $pdo->exec('DROP TABLE IF EXISTS employee_mail_settings');
            $q=$pdo->prepare('DELETE FROM schema_migrations WHERE migration=? AND checksum=?');$q->execute([$name,$expected]);
            echo "CENTRAL_016_ROLLBACK=PASS\n";
        } else {
            if (getenv('PLANEX_CONFIRM_016')!=='APPLY_CENTRAL_016_EMPLOYEE_MAIL') throw new RuntimeException('Explicit migration authorization required');
            if ($stored!==false) {$verify();echo "CENTRAL_016_APPLY=EXISTING\n";}
            else {
                if ($a||$b||$c||$newer) throw new RuntimeException('Unjournaled partial/newer schema');
                $backup=rtrim((string)(getenv('PLANEX_BACKUP_ROOT')?:'/home/s/spugovxsim/planexp/deploy_backups'),'/').'/central_016_'.gmdate('Ymd_His').'_'.substr($sha,0,12);
                if (!mkdir($backup,0700,true)) throw new RuntimeException('Backup directory unavailable');
                file_put_contents($backup.'/before.json',json_encode(['source_sha'=>$sha,'checksum'=>$expected,'identity'=>$before,'tables_absent'=>true],JSON_THROW_ON_ERROR));chmod($backup.'/before.json',0600);
                file_put_contents($backup.'/migration.sql',$sql);chmod($backup.'/migration.sql',0600);
                $pdo->exec($sql);
                $q=$pdo->prepare('INSERT INTO schema_migrations(migration,checksum) VALUES(?,?)');$q->execute([$name,$expected]);
                $verify();
                if ($before!==$snapshot()) throw new RuntimeException('Identity changed during migration');
                echo "CENTRAL_016_APPLY=PASS\n";
            }
        }
    } finally {$q=$pdo->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$lock]);}
} catch (Throwable) {fwrite(STDERR,"CENTRAL_016_FAILED: guarded migration did not complete; inspect schema before retry.\n");exit(1);}
