<?php
declare(strict_types=1);
$root=dirname(__DIR__);
require $root.'/bootstrap/app.php';
require_once $root.'/app/Support/company_database.php';
require_once $root.'/app/Service/LocalMigrationService.php';
function verify137(bool $ok,string $message): void { if(!$ok)throw new RuntimeException($message); }
$host=getenv('PLANEX_SCHEMA_DB_HOST')?:'127.0.0.1';$port=getenv('PLANEX_SCHEMA_DB_PORT')?:3306;
$user=getenv('PLANEX_SCHEMA_DB_USER')?:'planex_ci';$pass=getenv('PLANEX_SCHEMA_DB_PASSWORD')?:'planex_ci';
$fixed=(string)getenv('PLANEX_TEST_FIXED_DB');$db=$fixed?:'planex_m137_'.getmypid();
if($fixed!=='')verify137(in_array($fixed,explode(',',(string)getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST')),true),'Unapproved fixed DB');
$options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false];
$admin=new PDO("mysql:host=$host;port=$port;charset=utf8mb4",$user,$pass,$options);
if($fixed==='')$admin->exec("CREATE DATABASE `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo=new PDO("mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4",$user,$pass,$options);
$fixture=sys_get_temp_dir().'/planex-m137-'.bin2hex(random_bytes(8));
mkdir($fixture.'/bootstrap',0700,true);mkdir($fixture.'/app/Support',0700,true);
$files=['.env','bootstrap/app.php','app/Support/crypto_helper.php','app/Support/company_database.php'];
$oldRoot=getenv('PLANEX_ROOT');$oldPath=getenv('PLANEX_TENANT_MIGRATION_PATH');$oldDb=getenv('PLANEX_M137_TEST_DB');
try {
    if($fixed!=='')cleanCompanyPoolDatabase($pdo);
    App\Service\LocalMigrationService::apply($pdo);
    $pdo->exec("ALTER TABLE waste_trips DROP INDEX idx_waste_trips_deleted, DROP COLUMN deleted_at, MODIFY status ENUM('searching_executor','executor_assigned','in_progress','completed','cancelled') NOT NULL DEFAULT 'searching_executor';
        DELETE FROM schema_migrations WHERE migration='137_waste_trip_synchronization.sql';
        CREATE TABLE companies(id INT PRIMARY KEY,status VARCHAR(20),db_identifier VARCHAR(100))");
    $pdo->prepare("INSERT INTO companies VALUES(1,'active',?)")->execute([$db]);
    file_put_contents($fixture.'/.env',"# Synthetic migration test\n");
    file_put_contents($fixture.'/bootstrap/app.php', <<<'PHP'
<?php
return ['database'=>['host'=>getenv('PLANEX_SCHEMA_DB_HOST')?:'127.0.0.1','port'=>getenv('PLANEX_SCHEMA_DB_PORT')?:3306,'database'=>getenv('PLANEX_M137_TEST_DB'),'username'=>getenv('PLANEX_SCHEMA_DB_USER')?:'planex_ci','password'=>getenv('PLANEX_SCHEMA_DB_PASSWORD')?:'planex_ci']];
PHP);
    foreach(array_slice($files,2) as $file)copy($root.'/'.$file,$fixture.'/'.$file);
    putenv('PLANEX_ROOT='.$fixture);putenv('PLANEX_TENANT_MIGRATION_PATH='.$root.'/database/migrations-local/137_waste_trip_synchronization.sql');putenv('PLANEX_M137_TEST_DB='.$db);
    $run=static function(string $script): array {
        $proc=proc_open([PHP_BINARY,dirname(__DIR__).'/scripts/'.$script],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if(!is_resource($proc))throw new RuntimeException('Migration child process unavailable');
        $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        return [proc_close($proc),$out,$err];
    };
    $snapshot=static function()use($run):array {
        [$code,$out,$err]=$run('waste_trips_verify_137.php');verify137($code===0,'Read-only snapshot: '.$err);
        verify137(preg_match('/^WASTE_137_SNAPSHOT=(.+)$/m',$out,$matches)===1,'Snapshot output');return json_decode($matches[1],true,512,JSON_THROW_ON_ERROR);
    };
    $before=$snapshot();verify137($before['tenants'][0]['migration_137']===0,'Before 137');
    [$code,$out,$err]=$run('waste_trips_apply_137.php');verify137($code===0&&str_contains($out,'state=applied'),'Apply 137: '.$err);
    $after=$snapshot();verify137($after['tenants'][0]['migration_137']===1&&$after['tenants'][0]['planned_status']===1,'Schema applied');
    verify137($before['tenants'][0]['data']===$after['tenants'][0]['data'],'Existing data unchanged');
    [$code,$out,$err]=$run('waste_trips_apply_137.php');verify137($code===0&&str_contains($out,'state=existing'),'Idempotent apply: '.$err);
    $pdo->exec("UPDATE schema_migrations SET checksum=REPEAT('0',64) WHERE migration='137_waste_trip_synchronization.sql'");
    [$code,,$err]=$run('waste_trips_apply_137.php');verify137($code!==0&&str_contains($err,'checksum mismatch'),'Checksum drift rejected');
    echo "WASTE_TRIPS_137_MIGRATION_MYSQL=PASS\n";
} finally {
    foreach(['PLANEX_ROOT'=>$oldRoot,'PLANEX_TENANT_MIGRATION_PATH'=>$oldPath,'PLANEX_M137_TEST_DB'=>$oldDb] as $key=>$value)putenv($value===false?$key:$key.'='.$value);
    foreach($files as $file)if(is_file($fixture.'/'.$file))unlink($fixture.'/'.$file);
    rmdir($fixture.'/app/Support');rmdir($fixture.'/app');rmdir($fixture.'/bootstrap');rmdir($fixture);
    if($fixed!=='')cleanCompanyPoolDatabase($pdo);else $admin->exec("DROP DATABASE `$db`");
}
