<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/app.php';
require_once BASE_PATH.'/app/Support/company_database.php';
$db=(string)getenv('PLANEX_TEST_FIXED_DB');
if(getenv('APP_ENV')!=='test'||!$db||!in_array($db,explode(',',(string)getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST')),true))throw new RuntimeException('Guarded test DB required');
$pdo=new PDO('mysql:host='.getenv('PLANEX_SCHEMA_DB_HOST').';port='.(getenv('PLANEX_SCHEMA_DB_PORT')?:3306).';dbname='.$db.';charset=utf8mb4',getenv('PLANEX_SCHEMA_DB_USER'),getenv('PLANEX_SCHEMA_DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$root=sys_get_temp_dir().'/manager141-'.bin2hex(random_bytes(8));
mkdir($root.'/bootstrap',0700,true);mkdir($root.'/app/Support',0700,true);file_put_contents($root.'/.env','');
file_put_contents($root.'/bootstrap/app.php','<?php return require '.var_export(BASE_PATH.'/bootstrap/app.php',true).';');
foreach(['crypto_helper','company_database']as$file)file_put_contents($root.'/app/Support/'.$file.'.php','<?php require_once '.var_export(BASE_PATH.'/app/Support/'.$file.'.php',true).';');
$run=static function(string $mode='',string $confirm='')use($root,$db):array{
    $env=getenv();foreach(['DB_HOST'=>'PLANEX_SCHEMA_DB_HOST','DB_PORT'=>'PLANEX_SCHEMA_DB_PORT','DB_USERNAME'=>'PLANEX_SCHEMA_DB_USER','DB_PASSWORD'=>'PLANEX_SCHEMA_DB_PASSWORD']as$to=>$from)$env[$to]=(string)getenv($from);
    $env['DB_DATABASE']=$db;$env['PLANEX_ROOT']=$root;$env['PLANEX_TENANT_MIGRATION_PATH']=BASE_PATH.'/database/migrations-local/141_create_waste_trip_start_statistics.sql';$env['MANAGER_STATISTICS_MIGRATION_MODE']=$mode;$env['MANAGER_STATISTICS_MIGRATION_CONFIRM']=$confirm;
    $process=proc_open([PHP_BINARY,BASE_PATH.'/scripts/manager_statistics_migration_141.php'],[1=>['pipe','w'],2=>['pipe','w']],$pipes,BASE_PATH,$env);
    $out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($process);preg_match('/^MANAGER_141_SNAPSHOT=(.+)$/m',$out,$match);
    return['code'=>$code,'snapshot'=>isset($match[1])?json_decode($match[1],true):null,'output'=>$out.$error];
};
$check=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);};
cleanCompanyPoolDatabase($pdo);
try{
    $pdo->exec("CREATE TABLE schema_migrations(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,migration VARCHAR(255) UNIQUE NOT NULL,checksum VARCHAR(64) NOT NULL);
        INSERT INTO schema_migrations(migration,checksum) VALUES('140_create_trip_document_collection.sql','prerequisite');
        CREATE TABLE companies(id INT PRIMARY KEY,name VARCHAR(50),status VARCHAR(20),db_identifier VARCHAR(100));
        CREATE TABLE users(id INT UNSIGNED PRIMARY KEY,membership_id BIGINT UNSIGNED NULL,full_name VARCHAR(255) NOT NULL);
        CREATE TABLE waste_trips(id BIGINT UNSIGNED PRIMARY KEY,responsible_user_id INT UNSIGNED NOT NULL,actual_start_date DATE NULL,status ENUM('searching_executor','executor_assigned','in_progress','completed','cancelled') NOT NULL);
        CREATE TABLE waste_trip_requests(id BIGINT UNSIGNED PRIMARY KEY,waste_trip_id BIGINT UNSIGNED NOT NULL,snapshot_mass_kg DECIMAL(18,3) NOT NULL);
        INSERT INTO users VALUES(1,101,'Анна Белова'),(2,102,'Борис Серов');
        INSERT INTO waste_trips VALUES(1,1,'2026-09-01','completed'),(2,2,'2026-09-02','in_progress'),(3,2,NULL,'searching_executor');
        INSERT INTO waste_trip_requests VALUES(1,1,1500),(2,1,2500),(3,2,3000);");
    $pdo->prepare("INSERT INTO companies VALUES(1,'Synthetic','active',?)")->execute([$db]);
    $before=$run();$check($before['code']===0,'Read-only before: '.$before['output']);$check($before['snapshot']['tenants'][0]['migration_141']===0&&!$before['snapshot']['tenants'][0]['table_present'],'Read-only must not migrate');
    $check($run('apply')['code']!==0,'Explicit confirmation required');
    $applied=$run('apply','APPLY_MANAGER_STATISTICS_141');$check($applied['code']===0,'Apply: '.$applied['output']);
    $after=$run();$row=$after['snapshot']['tenants'][0];$check($after['code']===0&&$row['migration_141']===1&&$row['fact_rows']===2,'Journal, shape and backfill');
    $check($before['snapshot']['tenants'][0]['trip_sha256']===$row['trip_sha256']&&$before['snapshot']['tenants'][0]['request_sha256']===$row['request_sha256'],'Source rows unchanged');
    $facts=$pdo->query('SELECT waste_trip_id,responsible_membership_id,responsible_name_snapshot,request_count,total_mass_kg,is_backfilled FROM waste_trip_start_statistics ORDER BY waste_trip_id')->fetchAll(PDO::FETCH_ASSOC);
    $check((int)$facts[0]['responsible_membership_id']===101&&(int)$facts[0]['request_count']===2&&(float)$facts[0]['total_mass_kg']===4000.0&&(int)$facts[0]['is_backfilled']===1,'Historical manager and composition snapshot');
    $repeat=$run('apply','APPLY_MANAGER_STATISTICS_141');$check($repeat['code']===0&&$repeat['snapshot']===$after['snapshot'],'Idempotent migration');
    $pdo->exec("UPDATE schema_migrations SET checksum='tampered' WHERE migration='141_create_waste_trip_start_statistics.sql'");$check($run()['code']!==0,'Tampered journal rejected');
    echo "MANAGER_STATISTICS_141_MYSQL=PASS\n";
}finally{
    cleanCompanyPoolDatabase($pdo);
    foreach(['/bootstrap/app.php','/app/Support/crypto_helper.php','/app/Support/company_database.php','/.env']as$file)unlink($root.$file);
    foreach(['/app/Support','/app','/bootstrap','']as$dir)rmdir($root.$dir);
}
