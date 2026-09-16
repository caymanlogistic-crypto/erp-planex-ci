<?php
declare(strict_types=1);
$root=dirname(__DIR__);
require $root.'/bootstrap/app.php';
require_once $root.'/app/Support/company_database.php';
require_once $root.'/app/Service/LocalMigrationService.php';
function verify138(bool $ok,string $message): void { if(!$ok)throw new RuntimeException($message); }
$host=getenv('PLANEX_SCHEMA_DB_HOST')?:'127.0.0.1';$port=getenv('PLANEX_SCHEMA_DB_PORT')?:3306;
$user=getenv('PLANEX_SCHEMA_DB_USER')?:'planex_ci';$pass=getenv('PLANEX_SCHEMA_DB_PASSWORD')?:'planex_ci';
$fixed=(string)getenv('PLANEX_TEST_FIXED_DB');$db=$fixed?:'planex_m138_'.getmypid();
if($fixed!=='')verify138(in_array($fixed,explode(',',(string)getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST')),true),'Unapproved fixed DB');
$options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false];
$admin=new PDO("mysql:host=$host;port=$port;charset=utf8mb4",$user,$pass,$options);
if($fixed==='')$admin->exec("CREATE DATABASE `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo=new PDO("mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4",$user,$pass,$options);
$fixture=sys_get_temp_dir().'/planex-m138-'.bin2hex(random_bytes(8));
mkdir($fixture.'/bootstrap',0700,true);mkdir($fixture.'/app/Support',0700,true);
$files=['.env','bootstrap/app.php','app/Support/crypto_helper.php','app/Support/company_database.php'];
$oldRoot=getenv('PLANEX_ROOT');$oldPath=getenv('PLANEX_TENANT_MIGRATION_PATH');$oldDb=getenv('PLANEX_M138_TEST_DB');
try {
    if($fixed!=='')cleanCompanyPoolDatabase($pdo);
    App\Service\LocalMigrationService::apply($pdo);
    $pdo->exec("DROP TABLE waste_trip_general_payments; ALTER TABLE waste_trips DROP COLUMN financial_model_version; ALTER TABLE waste_trip_customer_payments MODIFY amount DECIMAL(12,2) NOT NULL;
        DELETE FROM schema_migrations WHERE migration>='138_';
        CREATE TABLE companies(id INT PRIMARY KEY,status VARCHAR(20),db_identifier VARCHAR(100))");
    $pdo->exec("INSERT INTO users(id,full_name,login,password_hash,role_code,status) VALUES(1,'Fixture','m138','x','company_owner','active');
        INSERT INTO clients(id,name,inn,status) VALUES(1,'Fixture','7700000000','active');
        INSERT INTO waste_trips(id,business_id,client_id,responsible_user_id,destination_type,status) VALUES(1,226,1,1,'warehouse','planned_route');
        INSERT INTO waste_trip_customer_payments(waste_trip_id,amount,payment_type,payment_method,condition_type,days_count,days_kind) VALUES(1,2000,'cash','cash','after_end',15,'calendar');
        INSERT INTO waste_trip_carrier_payments(waste_trip_id,amount,payment_type,payment_method,condition_type) VALUES(1,900,'','','')");
    $pdo->prepare("INSERT INTO companies VALUES(1,'active',?)")->execute([$db]);
    file_put_contents($fixture.'/.env',"# Synthetic migration test\n");
    file_put_contents($fixture.'/bootstrap/app.php', <<<'PHP'
<?php
return ['database'=>['host'=>getenv('PLANEX_SCHEMA_DB_HOST')?:'127.0.0.1','port'=>getenv('PLANEX_SCHEMA_DB_PORT')?:3306,'database'=>getenv('PLANEX_M138_TEST_DB'),'username'=>getenv('PLANEX_SCHEMA_DB_USER')?:'planex_ci','password'=>getenv('PLANEX_SCHEMA_DB_PASSWORD')?:'planex_ci']];
PHP);
    foreach(array_slice($files,2) as $file)copy($root.'/'.$file,$fixture.'/'.$file);
    putenv('PLANEX_ROOT='.$fixture);putenv('PLANEX_TENANT_MIGRATION_PATH='.$root.'/database/migrations-local/138_waste_trip_general_payments.sql');putenv('PLANEX_M138_TEST_DB='.$db);
    $run=static function(string $script): array {
        $proc=proc_open([PHP_BINARY,dirname(__DIR__).'/scripts/'.$script],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if(!is_resource($proc))throw new RuntimeException('Migration child process unavailable');
        $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        return [proc_close($proc),$out,$err];
    };
    $snapshot=static function()use($run):array {
        [$code,$out,$err]=$run('waste_trips_verify_138.php');verify138($code===0,'Read-only snapshot: '.$err);
        verify138(preg_match('/^WASTE_138_SNAPSHOT=(.+)$/m',$out,$matches)===1,'Snapshot output');return json_decode($matches[1],true,512,JSON_THROW_ON_ERROR);
    };
    putenv('PLANEX_WASTE_138_TOOLING='.$root.'/app/Service');
    putenv('PLANEX_CONFIRM_WASTE_138=APPLY_WASTE_TRIPS_138_AND_TRANSFER_AMOUNTS');
    $before=$snapshot();verify138($before['tenants'][0]['migration_138']===0,'Before 138');
    putenv('PLANEX_CONFIRM_WASTE_138=');[$code,,$err]=$run('waste_trips_apply_138.php');verify138($code!==0&&str_contains($err,'authorization required'),'Missing explicit approval rejected');
    putenv('PLANEX_CONFIRM_WASTE_138=APPLY_WASTE_TRIPS_138_AND_TRANSFER_AMOUNTS');
    [$code,$out,$err]=$run('waste_trips_apply_138.php');verify138($code===0&&str_contains($out,'WASTE_138_OK'),'Apply 138: '.$err);
    $after=$snapshot();verify138($after['tenants'][0]['migration_138']===1&&$after['tenants'][0]['general_table']===1,'Schema applied');
    verify138($before['tenants'][0]['data']===$after['tenants'][0]['data'],'Operational data and facts unchanged');
    verify138($after['tenants'][0]['payments']['general'][0]['amount']==='2000.00' && (int)$after['tenants'][0]['payments']['general'][0]['days_count']===15,'Old sale preserved as general with manual terms');
    verify138($after['tenants'][0]['payments']['customer'][0]['amount']==='900.00' && (int)$after['tenants'][0]['payments']['customer'][0]['days_count']===3,'Old cost now sale with default terms');
    verify138($after['tenants'][0]['payments']['carrier']===[],'Manual cost starts empty');
    [$code,$out,$err]=$run('waste_trips_apply_138.php');verify138($code===0&&str_contains($out,'WASTE_138_OK'),'Idempotent apply: '.$err);
    $pdo->exec("UPDATE schema_migrations SET checksum=REPEAT('0',64) WHERE migration='138_waste_trip_general_payments.sql'");
    [$code,,$err]=$run('waste_trips_apply_138.php');verify138($code!==0&&str_contains($err,'checksum mismatch'),'Checksum drift rejected');
    echo "WASTE_TRIPS_138_MIGRATION_MYSQL=PASS\n";
} finally {
    foreach(['PLANEX_ROOT'=>$oldRoot,'PLANEX_TENANT_MIGRATION_PATH'=>$oldPath,'PLANEX_M138_TEST_DB'=>$oldDb] as $key=>$value)putenv($value===false?$key:$key.'='.$value);
    foreach($files as $file)if(is_file($fixture.'/'.$file))unlink($fixture.'/'.$file);
    rmdir($fixture.'/app/Support');rmdir($fixture.'/app');rmdir($fixture.'/bootstrap');rmdir($fixture);
    if($fixed!=='')cleanCompanyPoolDatabase($pdo);else $admin->exec("DROP DATABASE `$db`");
}
