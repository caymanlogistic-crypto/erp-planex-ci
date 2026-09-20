<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap/app.php';
require_once BASE_PATH . '/app/Support/company_database.php';

if (getenv('APP_ENV') !== 'test') throw new RuntimeException('Test only.');

$db = trim((string)getenv('PLANEX_TEST_FIXED_DB'));
$allow = array_filter(array_map('trim', explode(',', (string)getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST'))));
if ($db === '' || !in_array($db, $allow, true)) throw new RuntimeException('Guarded fixed test DB required.');

$host=(string)getenv('PLANEX_SCHEMA_DB_HOST');
$port=(string)(getenv('PLANEX_SCHEMA_DB_PORT') ?: '3306');
$user=(string)getenv('PLANEX_SCHEMA_DB_USER');
$pass=(string)getenv('PLANEX_SCHEMA_DB_PASSWORD');
$pdo=new PDO(
    "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4",
    $user,$pass,
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]
);

$ok=static function(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);};
$run=static function(string $mode):array {
    $cmd=[PHP_BINARY, BASE_PATH.'/scripts/central_015_production_migrate.php', $mode];
    $desc=[1=>['pipe','w'],2=>['pipe','w']];
    $proc=proc_open($cmd,$desc,$pipes,BASE_PATH);
    if(!is_resource($proc)) throw new RuntimeException('Cannot start migration CLI.');
    $out=stream_get_contents($pipes[1]); fclose($pipes[1]);
    $err=stream_get_contents($pipes[2]); fclose($pipes[2]);
    $rc=proc_close($proc);
    return [$rc,(string)$out,(string)$err];
};
cleanCompanyPoolDatabase($pdo);
$tmp=sys_get_temp_dir().DIRECTORY_SEPARATOR.'planex-central015-'.bin2hex(random_bytes(6));
$backup=$tmp.DIRECTORY_SEPARATOR.'backups';
$marker=$tmp.DIRECTORY_SEPARATOR.'marker.txt';
if(!mkdir($backup,0700,true) && !is_dir($backup)) throw new RuntimeException('Cannot create temp backup dir.');

$expectedSha=str_repeat('a',40);
$deployedSha=str_repeat('b',40);
file_put_contents($marker,$deployedSha);
$migration015=(string)file_get_contents(BASE_PATH.'/database/migrations/015_add_user_accounts_max_user_id.sql');
$checksum015=hash('sha256',$migration015);
$migration013=(string)file_get_contents(BASE_PATH.'/database/migrations/013_create_roles_access.sql');
$migration014=(string)file_get_contents(BASE_PATH.'/database/migrations/014_add_role_default_page.sql');

putenv('DB_HOST='.$host);
putenv('DB_PORT='.$port);
putenv('DB_DATABASE='.$db);
putenv('DB_USERNAME='.$user);
putenv('DB_PASSWORD='.$pass);
putenv('PLANEX_ROOT='.BASE_PATH);
putenv('PLANEX_SOURCE_SHA='.$expectedSha);
putenv('PLANEX_DEPLOYED_SHA='.$deployedSha);
putenv('PLANEX_MIGRATION_SHA256='.$checksum015);
putenv('PLANEX_DEPLOY_MARKER='.$marker);
putenv('PLANEX_BACKUP_ROOT='.$backup);
putenv('PLANEX_CONFIRM_015');

try {
    $pdo->exec("CREATE TABLE companies(id INT UNSIGNED NOT NULL PRIMARY KEY,name VARCHAR(255) NOT NULL,status VARCHAR(20) NOT NULL) ENGINE=InnoDB");
    $pdo->exec($migration013);
    $pdo->exec($migration014);
    $pdo->exec("CREATE TABLE schema_migrations(
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        migration VARCHAR(255) NOT NULL UNIQUE,
        checksum VARCHAR(64) NOT NULL,
        executed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");
    $j=$pdo->prepare('INSERT INTO schema_migrations(migration,checksum) VALUES(?,?)');
    $j->execute(['013_create_roles_access.sql',hash('sha256',$migration013)]);
    $j->execute(['014_add_role_default_page.sql',hash('sha256',$migration014)]);

    $insert=$pdo->prepare("INSERT INTO user_accounts(full_name,login,normalized_login,email,phone,password_hash,status)
        VALUES(?,?,?,?,?,?,?)");
    $insert->execute(['Иван Иванов','ivan','ivan','ivan@example.test','+70000000001','hash-one','active']);
    $insert->execute(['Пётр Петров','petr','petr',null,null,'hash-two','blocked']);
    $before=$pdo->query("SELECT id,full_name,login,normalized_login,email,phone,password_hash,status FROM user_accounts ORDER BY id")
        ->fetchAll(PDO::FETCH_ASSOC);

    [$rc,$out,$err]=$run('inspect');
    $ok($rc===0,'inspect rc: '.$err);
    $ok(str_contains($out,'CENTRAL_015_INSPECT=READY'),'inspect not ready');
    $ok(str_contains($out,'CENTRAL_015_JOURNALED=NO'),'unexpected journal before');

    [$rc,$out,$err]=$run('apply');
    $ok($rc!==0,'apply without confirmation unexpectedly succeeded');
    $ok(str_contains($err,'Explicit migration confirmation missing'),'missing-confirmation refusal not explicit: '.$err);

    putenv('PLANEX_CONFIRM_015=APPLY_CENTRAL_015_MAX_USER_ID');
    [$rc,$out,$err]=$run('apply');
    $ok($rc===0,'approved apply failed: '.$err);
    $ok(str_contains($out,'CENTRAL_015_APPLY=PASS'),'apply PASS marker missing');
    $ok(str_contains($out,'CENTRAL_015_AFTER_MAX_ID_NONNULL=0'),'migration invented MAX IDs');
    $column=$pdo->query("SELECT DATA_TYPE,IS_NULLABLE,CHARACTER_MAXIMUM_LENGTH
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='user_accounts' AND COLUMN_NAME='max_user_id'")
        ->fetch(PDO::FETCH_ASSOC);
    $ok(is_array($column),'max_user_id column absent');
    $ok($column['DATA_TYPE']==='varchar' && $column['IS_NULLABLE']==='YES' && (int)$column['CHARACTER_MAXIMUM_LENGTH']===32,'column shape');

    $index=(int)$pdo->query("SELECT COUNT(*) FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='user_accounts'
          AND INDEX_NAME='uk_user_accounts_max_user_id' AND NON_UNIQUE=0 AND COLUMN_NAME='max_user_id'")->fetchColumn();
    $ok($index===1,'unique index absent');

    $stored=$pdo->query("SELECT checksum FROM schema_migrations WHERE migration='015_add_user_accounts_max_user_id.sql'")->fetchColumn();
    $ok(is_string($stored) && hash_equals($checksum015,$stored),'migration journal/checksum');

    $after=$pdo->query("SELECT id,full_name,login,normalized_login,email,phone,password_hash,status FROM user_accounts ORDER BY id")
        ->fetchAll(PDO::FETCH_ASSOC);
    $ok($after===$before,'existing identity rows changed');

    [$rc,$out,$err]=$run('inspect');
    $ok($rc===0 && str_contains($out,'CENTRAL_015_INSPECT=ALREADY_APPLIED'),'post-apply inspect failed: '.$err);

    [$rc,$out,$err]=$run('apply');
    $ok($rc===0 && str_contains($out,'CENTRAL_015_APPLY=EXISTING'),'idempotent reapply failed: '.$err);

    putenv('PLANEX_CONFIRM_015=ROLLBACK_CENTRAL_015_MAX_USER_ID');
    [$rc,$out,$err]=$run('rollback');
    $ok($rc===0 && str_contains($out,'CENTRAL_015_ROLLBACK=PASS'),'guarded rollback failed: '.$err);
    $columnCount=(int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='user_accounts' AND COLUMN_NAME='max_user_id'")->fetchColumn();
    $journalCount=(int)$pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE migration='015_add_user_accounts_max_user_id.sql'")->fetchColumn();
    $ok($columnCount===0 && $journalCount===0,'rollback did not restore pre-015 schema/journal');

    putenv('PLANEX_CONFIRM_015');
    [$rc,$out,$err]=$run('inspect');
    $ok($rc===0 && str_contains($out,'CENTRAL_015_INSPECT=READY'),'post-rollback inspect not READY: '.$err);

    $dirs=array_values(array_filter(glob($backup.DIRECTORY_SEPARATOR.'central_015_*') ?: [],'is_dir'));
    $ok(count($dirs)===1,'expected exactly one backup directory');
    $ok(is_file($dirs[0].DIRECTORY_SEPARATOR.'user_accounts_before.json'),'before backup missing');
    $ok(is_file($dirs[0].DIRECTORY_SEPARATOR.'result.json'),'result evidence missing');

    echo "CENTRAL_015_PRODUCTION_MIGRATION_MYSQL=PASS\n";
} finally {
    if($pdo->inTransaction()) $pdo->rollBack();
    cleanCompanyPoolDatabase($pdo);
    foreach(['DB_HOST','DB_PORT','DB_DATABASE','DB_USERNAME','DB_PASSWORD','PLANEX_ROOT','PLANEX_SOURCE_SHA',
        'PLANEX_DEPLOYED_SHA','PLANEX_MIGRATION_SHA256','PLANEX_DEPLOY_MARKER','PLANEX_BACKUP_ROOT','PLANEX_CONFIRM_015'] as $name) putenv($name);
    if(is_dir($tmp)){
        $it=new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($tmp,FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach($it as $entry){
            if($entry->isDir()) rmdir($entry->getPathname()); else unlink($entry->getPathname());
        }
        rmdir($tmp);
    }
}
