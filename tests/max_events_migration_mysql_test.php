<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/app.php';
require_once BASE_PATH.'/app/Support/company_database.php';
$db=trim((string)getenv('PLANEX_TEST_FIXED_DB'));
if(getenv('APP_ENV')!=='test' || $db==='' || !in_array($db,explode(',',(string)getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST')),true)) throw new RuntimeException('Guarded test DB required');
$pdo=new PDO('mysql:host='.getenv('PLANEX_SCHEMA_DB_HOST').';port='.(getenv('PLANEX_SCHEMA_DB_PORT')?:3306).';dbname='.$db.';charset=utf8mb4',getenv('PLANEX_SCHEMA_DB_USER'),getenv('PLANEX_SCHEMA_DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$ok=static function(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);};
cleanCompanyPoolDatabase($pdo);
$fixture=BASE_PATH.'/storage/tmp/max-events-migration-'.bin2hex(random_bytes(6));
$files=[];
$envKeys=['PLANEX_ROOT','PLANEX_TENANT_MIGRATION_PATH','MAX_EVENTS_MIGRATION_MODE','MAX_EVENTS_MIGRATION_CONFIRM'];
$previous=[];foreach($envKeys as $key)$previous[$key]=getenv($key);
try {
    foreach(['bootstrap','app/Support'] as $dir)mkdir($fixture.'/'.$dir,0700,true);
    foreach(['crypto_helper.php','company_database.php'] as $name){$path=$fixture.'/app/Support/'.$name;copy(BASE_PATH.'/app/Support/'.$name,$path);$files[]=$path;}
    file_put_contents($fixture.'/.env','');$files[]=$fixture.'/.env';
    $bootstrap='<?php require '.var_export(BASE_PATH.'/bootstrap/app.php',true).'; return ["database"=>["host"=>getenv("PLANEX_SCHEMA_DB_HOST"),"port"=>getenv("PLANEX_SCHEMA_DB_PORT"),"database"=>getenv("PLANEX_TEST_FIXED_DB"),"username"=>getenv("PLANEX_SCHEMA_DB_USER"),"password"=>getenv("PLANEX_SCHEMA_DB_PASSWORD")]];';
    file_put_contents($fixture.'/bootstrap/app.php',$bootstrap);$files[]=$fixture.'/bootstrap/app.php';
    $pdo->exec('CREATE TABLE companies(id INT PRIMARY KEY,name VARCHAR(100),status VARCHAR(20),db_identifier VARCHAR(100),db_host VARCHAR(255),db_port INT,db_username VARCHAR(255),db_password VARCHAR(1024))');
    $q=$pdo->prepare("INSERT INTO companies VALUES(1,'Local','active',?,NULL,NULL,NULL,NULL)");$q->execute([$db]);
    $pdo->exec('CREATE TABLE schema_migrations(migration VARCHAR(255) PRIMARY KEY,checksum VARCHAR(64))');
    $pdo->exec(file_get_contents(BASE_PATH.'/database/migrations-local/133_create_max_integration.sql'));
    $pdo->exec("INSERT INTO schema_migrations VALUES('133_create_max_integration.sql','prior')");
    $pdo->exec("INSERT INTO max_bots(name,token_encrypted,webhook_secret_encrypted) VALUES('untouched','encrypted-token-fixture','encrypted-secret-fixture')");
    $before=$pdo->query('SELECT * FROM max_bots')->fetchAll(PDO::FETCH_ASSOC);
    putenv('PLANEX_ROOT='.$fixture);putenv('PLANEX_TENANT_MIGRATION_PATH='.BASE_PATH.'/database/migrations-local/136_create_max_event_notifications.sql');
    putenv('MAX_EVENTS_MIGRATION_MODE');putenv('MAX_EVENTS_MIGRATION_CONFIRM');
    $run=static function(bool $success=true) use($ok):string {
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(BASE_PATH.'/scripts/max_events_migration_136.php').' 2>&1',$lines,$code);
        $ok(($code===0)===$success,'Migration unexpected exit '.$code.': '.implode("\n",$lines));return implode("\n",$lines);
    };
    $ok(str_contains($run(),'MAX_136_READ_ONLY_OK'),'Read-only before failed');
    $ok((int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='max_event_rules'")->fetchColumn()===0,'Verifier wrote schema');
    putenv('MAX_EVENTS_MIGRATION_MODE=apply');$run(false);
    putenv('MAX_EVENTS_MIGRATION_CONFIRM=APPLY_MAX_EVENTS_136');$ok(str_contains($run(),'MAX_136_APPLY_OK'),'Apply failed');
    $run();
    $ok((int)$pdo->query('SELECT COUNT(*) FROM max_event_rules')->fetchColumn()===0 && (int)$pdo->query('SELECT COUNT(*) FROM max_event_deliveries')->fetchColumn()===0,'Migration must not seed settings or send history');
    $ok($pdo->query('SELECT * FROM max_bots')->fetchAll(PDO::FETCH_ASSOC)===$before,'Existing bot changed');
    putenv('MAX_EVENTS_MIGRATION_MODE');$run();
    $pdo->exec("UPDATE schema_migrations SET checksum='bad' WHERE migration='136_create_max_event_notifications.sql'");$run(false);
    $pdo->exec("DELETE FROM schema_migrations WHERE migration='136_create_max_event_notifications.sql'");$run(false);
    echo "MAX_EVENTS_MIGRATION_MYSQL=PASS readonly approval apply idempotency unchanged-config checksum\n";
} finally {
    foreach($previous as $key=>$value)putenv($value===false?$key:$key.'='.$value);
    foreach(array_reverse($files) as $file)if(is_file($file))unlink($file);
    foreach(['app/Support','app','bootstrap',''] as $dir)if(is_dir($fixture.'/'.$dir))rmdir($fixture.'/'.$dir);
    cleanCompanyPoolDatabase($pdo);
}
