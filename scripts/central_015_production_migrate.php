<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'cgi-fcgi') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$mode = $argv[1] ?? 'inspect';
if (!in_array($mode, ['inspect','apply','rollback'], true)) {
    throw new RuntimeException('Usage: inspect|apply|rollback');
}

$root = rtrim((string)getenv('PLANEX_ROOT'), '/');
$expectedSha = trim((string)getenv('PLANEX_SOURCE_SHA'));
$expectedDeployedSha = trim((string)(getenv('PLANEX_DEPLOYED_SHA') ?: $expectedSha));
$expectedChecksum = trim((string)getenv('PLANEX_MIGRATION_SHA256'));
$confirm = (string)getenv('PLANEX_CONFIRM_015');
if ($root === '' || !preg_match('/^[0-9a-f]{40}$/D', $expectedSha) || !preg_match('/^[0-9a-f]{40}$/D', $expectedDeployedSha) || !preg_match('/^[0-9a-f]{64}$/D', $expectedChecksum)) {
    throw new RuntimeException('Required guarded environment is missing.');
}
if (!is_file($root.'/bootstrap/app.php') || (getenv('APP_ENV') !== 'test' && !is_file($root.'/.env'))) {
    throw new RuntimeException('PLANEX_ROOT invalid.');
}
$markerPath = (string)(getenv('PLANEX_DEPLOY_MARKER') ?: '/home/s/spugovxsim/planexp/deploy/P07_erpv2_deployed_commit.txt');
$marker = trim((string)file_get_contents($markerPath));
if (!hash_equals($expectedDeployedSha, $marker)) throw new RuntimeException('Exact deployed marker mismatch.');

$migrationName = '015_add_user_accounts_max_user_id.sql';
$migrationPath = $root.'/database/migrations/'.$migrationName;
if (!is_file($migrationPath)) throw new RuntimeException('Migration 015 file missing from deployed artifact.');
$sql = (string)file_get_contents($migrationPath);
$checksum = hash('sha256', $sql);
if (!hash_equals($expectedChecksum, $checksum)) throw new RuntimeException('Migration 015 checksum mismatch.');

$config = require $root.'/bootstrap/app.php';
$db = $config['database'] ?? null;
if (!is_array($db)) throw new RuntimeException('Central DB config missing.');
$pdo = new PDO(
    'mysql:host='.$db['host'].';port='.($db['port'] ?? 3306).';dbname='.$db['database'].';charset=utf8mb4',
    $db['username'], $db['password'],
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false]
);
$tableExists = static function(PDO $pdo, string $table): bool {
    $q=$pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $q->execute([$table]);
    return (int)$q->fetchColumn()===1;
};
$column = static function(PDO $pdo): array|false {
    $q=$pdo->query("SELECT DATA_TYPE,COLUMN_TYPE,IS_NULLABLE,CHARACTER_MAXIMUM_LENGTH,ORDINAL_POSITION
                    FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='user_accounts' AND COLUMN_NAME='max_user_id'");
    return $q->fetch(PDO::FETCH_ASSOC);
};
$index = static function(PDO $pdo): array {
    $q=$pdo->query("SELECT INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME
                    FROM information_schema.STATISTICS
                    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='user_accounts' AND INDEX_NAME='uk_user_accounts_max_user_id'
                    ORDER BY SEQ_IN_INDEX");
    return $q->fetchAll(PDO::FETCH_ASSOC);
};
$journal = static function(PDO $pdo, string $name): string|false {
    $q=$pdo->prepare('SELECT checksum FROM schema_migrations WHERE migration=?');
    $q->execute([$name]);
    $v=$q->fetchColumn();
    return $v===false ? false : (string)$v;
};
$snapshot = static function(PDO $pdo): array {
    $hash=hash_init('sha256');
    $count=0;
    $q=$pdo->query("SELECT id,full_name,login,normalized_login,email,phone,password_hash,status,created_at,updated_at
                    FROM user_accounts ORDER BY id");
    while ($row=$q->fetch(PDO::FETCH_ASSOC)) {
        hash_update($hash, json_encode($row, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");
        $count++;
    }
    $nonnull=0;
    $columnCount=(int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='user_accounts' AND COLUMN_NAME='max_user_id'")->fetchColumn();
    if ($columnCount===1) {
        $nonnull=(int)$pdo->query("SELECT COUNT(*) FROM user_accounts WHERE max_user_id IS NOT NULL")->fetchColumn();
    }
    return ['rows'=>$count,'identity_hash'=>hash_final($hash),'max_user_id_nonnull'=>$nonnull];
};
$verify015 = static function(PDO $pdo) use($column,$index,$journal,$migrationName,$checksum): void {
    $c=$column($pdo);
    if (!$c) throw new RuntimeException('max_user_id column missing after migration.');
    if (($c['DATA_TYPE'] ?? '')!=='varchar' || (int)($c['CHARACTER_MAXIMUM_LENGTH'] ?? 0)!==32 || ($c['IS_NULLABLE'] ?? '')!=='YES') {
        throw new RuntimeException('max_user_id column shape mismatch.');
    }
    $idx=$index($pdo);
    if (count($idx)!==1 || (int)$idx[0]['NON_UNIQUE']!==0 || (int)$idx[0]['SEQ_IN_INDEX']!==1 || $idx[0]['COLUMN_NAME']!=='max_user_id') {
        throw new RuntimeException('MAX ID unique index shape mismatch.');
    }
    $stored=$journal($pdo,$migrationName);
    if ($stored===false || !hash_equals($checksum,$stored)) throw new RuntimeException('Migration 015 journal mismatch.');
};
if (!$tableExists($pdo,'schema_migrations')) throw new RuntimeException('Central schema_migrations table missing.');
if (!$tableExists($pdo,'user_accounts')) throw new RuntimeException('Central user_accounts table missing.');

$requiredColumns=['id','full_name','login','normalized_login','email','phone','password_hash','status','created_at','updated_at'];
foreach ($requiredColumns as $name) {
    $q=$pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
    $q->execute(['user_accounts',$name]);
    if ((int)$q->fetchColumn()!==1) throw new RuntimeException('Central 013 user_accounts prerequisite missing: '.$name);
}

$old014=$journal($pdo,'014_add_role_default_page.sql');
if ($old014===false) throw new RuntimeException('Central migration 014 journal prerequisite missing.');
$path014=$root.'/database/migrations/014_add_role_default_page.sql';
if (!is_file($path014)) throw new RuntimeException('Migration 014 file missing.');
if (!hash_equals(hash('sha256',(string)file_get_contents($path014)),$old014)) {
    throw new RuntimeException('Central migration 014 checksum mismatch.');
}

$newer=(int)$pdo->query("SELECT COUNT(*) FROM schema_migrations
    WHERE migration REGEXP '^[0-9]{3}_'
      AND CAST(LEFT(migration,3) AS UNSIGNED)>15")->fetchColumn();

$stored015=$journal($pdo,$migrationName);
$c015=$column($pdo);
$i015=$index($pdo);
$before=$snapshot($pdo);

printf("CENTRAL_015_MARKER=%s\n",$marker);
printf("CENTRAL_015_CHECKSUM=%s\n",$checksum);
printf("CENTRAL_015_ROWS=%d\n",$before['rows']);
printf("CENTRAL_015_IDENTITY_HASH=%s\n",$before['identity_hash']);
printf("CENTRAL_015_NEWER_MIGRATIONS=%d\n",$newer);
printf("CENTRAL_015_JOURNALED=%s\n",$stored015===false?'NO':'YES');
printf("CENTRAL_015_COLUMN=%s\n",$c015===false?'NO':'YES');
printf("CENTRAL_015_INDEX=%s\n",$i015===[]?'NO':'YES');
printf("CENTRAL_015_MAX_ID_NONNULL=%d\n",$before['max_user_id_nonnull']);

if ($mode==='inspect') {
    if ($stored015!==false) {
        $verify015($pdo);
        echo "CENTRAL_015_INSPECT=ALREADY_APPLIED\n";
    } else {
        if ($c015!==false || $i015!==[]) throw new RuntimeException('Unjournaled partial migration 015 schema detected.');
        if ($newer!==0) throw new RuntimeException('Newer central migration exists while 015 is absent.');
        echo "CENTRAL_015_INSPECT=READY\n";
    }
    exit(0);
}
if ($mode==='rollback') {
    if ($confirm!=='ROLLBACK_CENTRAL_015_MAX_USER_ID') throw new RuntimeException('Explicit rollback confirmation missing.');
    $lockName='planex_central_015_max_user_id';
    $q=$pdo->prepare('SELECT GET_LOCK(?,30)');
    $q->execute([$lockName]);
    if ((int)$q->fetchColumn()!==1) throw new RuntimeException('Central migration 015 lock failed.');
    try {
        $stored015=$journal($pdo,$migrationName);
        if ($stored015===false) {
            if ($column($pdo)!==false || $index($pdo)!==[]) throw new RuntimeException('Unjournaled partial migration 015 schema detected.');
            echo "CENTRAL_015_ROLLBACK=NOT_REQUIRED\n";
            exit(0);
        }
        if (!hash_equals($checksum,$stored015)) throw new RuntimeException('Existing migration 015 checksum mismatch.');
        $verify015($pdo);
        $newer=(int)$pdo->query("SELECT COUNT(*) FROM schema_migrations
            WHERE migration REGEXP '^[0-9]{3}_'
              AND CAST(LEFT(migration,3) AS UNSIGNED)>15")->fetchColumn();
        if ($newer!==0) throw new RuntimeException('Rollback blocked because newer central migration exists.');
        $nonnull=(int)$pdo->query('SELECT COUNT(*) FROM user_accounts WHERE max_user_id IS NOT NULL')->fetchColumn();
        if ($nonnull!==0) throw new RuntimeException('Rollback blocked because MAX IDs already exist.');
        $pdo->exec('ALTER TABLE user_accounts DROP INDEX uk_user_accounts_max_user_id');
        $pdo->exec('ALTER TABLE user_accounts DROP COLUMN max_user_id');
        $del=$pdo->prepare('DELETE FROM schema_migrations WHERE migration=? AND checksum=?');
        $del->execute([$migrationName,$checksum]);
        if ($del->rowCount()!==1) throw new RuntimeException('Migration 015 journal rollback failed.');
        if ($column($pdo)!==false || $index($pdo)!==[] || $journal($pdo,$migrationName)!==false) {
            throw new RuntimeException('Migration 015 rollback readback failed.');
        }
        echo "CENTRAL_015_ROLLBACK=PASS\n";
    } finally {
        $u=$pdo->prepare('SELECT RELEASE_LOCK(?)');
        $u->execute([$lockName]);
    }
    exit(0);
}

if ($confirm!=='APPLY_CENTRAL_015_MAX_USER_ID') throw new RuntimeException('Explicit migration confirmation missing.');

$lockName='planex_central_015_max_user_id';
$q=$pdo->prepare('SELECT GET_LOCK(?,30)');
$q->execute([$lockName]);
if ((int)$q->fetchColumn()!==1) throw new RuntimeException('Central migration 015 lock failed.');

$appliedThisRun=false;
$evidenceDir='';
try {
    $stored015=$journal($pdo,$migrationName);
    $c015=$column($pdo);
    $i015=$index($pdo);
    $newer=(int)$pdo->query("SELECT COUNT(*) FROM schema_migrations
        WHERE migration REGEXP '^[0-9]{3}_'
          AND CAST(LEFT(migration,3) AS UNSIGNED)>15")->fetchColumn();

    if ($stored015!==false) {
        if (!hash_equals($checksum,$stored015)) throw new RuntimeException('Existing migration 015 checksum mismatch.');
        $verify015($pdo);
        echo "CENTRAL_015_APPLY=EXISTING\n";
        exit(0);
    }
    if ($c015!==false || $i015!==[]) throw new RuntimeException('Unjournaled partial migration 015 schema detected.');
    if ($newer!==0) throw new RuntimeException('Newer central migration exists while 015 is absent.');

    $timestamp=gmdate('Ymd\THis\Z');
    $backupRoot=rtrim((string)(getenv('PLANEX_BACKUP_ROOT') ?: '/home/s/spugovxsim/planexp/deploy_backups'), '/');
    if ($backupRoot==='' || (!is_dir($backupRoot) && !mkdir($backupRoot,0700,true) && !is_dir($backupRoot))) throw new RuntimeException('Backup root unavailable.');
    $evidenceDir=$backupRoot.'/central_015_'.$timestamp.'_'.substr($expectedSha,0,12);
    if (!mkdir($evidenceDir,0700,true) && !is_dir($evidenceDir)) throw new RuntimeException('Cannot create migration evidence directory.');
    chmod($evidenceDir,0700);

    $show=(string)$pdo->query('SHOW CREATE TABLE user_accounts')->fetch(PDO::FETCH_NUM)[1];
    $rows=$pdo->query("SELECT id,full_name,login,normalized_login,email,phone,password_hash,status,created_at,updated_at
                      FROM user_accounts ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    $backup=[
        'source_sha'=>$expectedSha,
        'migration'=>$migrationName,
        'checksum'=>$checksum,
        'created_at'=>gmdate(DATE_ATOM),
        'show_create_user_accounts'=>$show,
        'snapshot'=>$snapshot($pdo),
        'rows'=>$rows,
    ];
    $backupPath=$evidenceDir.'/user_accounts_before.json';
    file_put_contents($backupPath,json_encode($backup,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
    chmod($backupPath,0600);
    file_put_contents($evidenceDir.'/migration_015.sql',$sql);
    chmod($evidenceDir.'/migration_015.sql',0600);

    $pre=$snapshot($pdo);
    $pdo->exec($sql);
    $insert=$pdo->prepare('INSERT INTO schema_migrations(migration,checksum) VALUES(?,?)');
    $insert->execute([$migrationName,$checksum]);
    $appliedThisRun=true;

    $verify015($pdo);
    $post=$snapshot($pdo);
    if ($post['rows']!==$pre['rows'] || !hash_equals($pre['identity_hash'],$post['identity_hash'])) {
        throw new RuntimeException('Existing user_accounts business data changed during migration.');
    }
    if ($post['max_user_id_nonnull']!==0) throw new RuntimeException('Migration invented MAX IDs.');

    $result=[
        'source_sha'=>$expectedSha,
        'migration'=>$migrationName,
        'checksum'=>$checksum,
        'before'=>$pre,
        'after'=>$post,
        'journaled'=>true,
        'column_shape'=>$column($pdo),
        'index_shape'=>$index($pdo),
        'finished_at'=>gmdate(DATE_ATOM),
    ];
    $resultPath=$evidenceDir.'/result.json';
    file_put_contents($resultPath,json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
    chmod($resultPath,0600);

    printf("CENTRAL_015_EVIDENCE_DIR=%s\n",$evidenceDir);
    printf("CENTRAL_015_AFTER_ROWS=%d\n",$post['rows']);
    printf("CENTRAL_015_AFTER_IDENTITY_HASH=%s\n",$post['identity_hash']);
    printf("CENTRAL_015_AFTER_MAX_ID_NONNULL=%d\n",$post['max_user_id_nonnull']);
    echo "CENTRAL_015_APPLY=PASS\n";
} catch (Throwable $e) {
    if ($appliedThisRun) {
        try {
            $nonnull=(int)$pdo->query('SELECT COUNT(*) FROM user_accounts WHERE max_user_id IS NOT NULL')->fetchColumn();
            if ($nonnull===0) {
                if ($index($pdo)!==[]) $pdo->exec('ALTER TABLE user_accounts DROP INDEX uk_user_accounts_max_user_id');
                if ($column($pdo)!==false) $pdo->exec('ALTER TABLE user_accounts DROP COLUMN max_user_id');
                $del=$pdo->prepare('DELETE FROM schema_migrations WHERE migration=? AND checksum=?');
                $del->execute([$migrationName,$checksum]);
                echo "CENTRAL_015_ROLLBACK=PASS\n";
            } else {
                echo "CENTRAL_015_ROLLBACK=BLOCKED_NON_NULL_DATA\n";
            }
        } catch (Throwable $rollbackError) {
            fwrite(STDERR,'ROLLBACK_ERROR='.$rollbackError->getMessage()."\n");
        }
    }
    throw $e;
} finally {
    $u=$pdo->prepare('SELECT RELEASE_LOCK(?)');
    $u->execute([$lockName]);
}
