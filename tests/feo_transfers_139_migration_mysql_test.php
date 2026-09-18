<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/app.php';
require_once BASE_PATH.'/app/Support/company_database.php';
$db=(string)getenv('PLANEX_TEST_FIXED_DB');
if(getenv('APP_ENV')!=='test'||!$db||!in_array($db,explode(',',(string)getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST')),true))throw new RuntimeException('Guarded test DB required');
$pdo=new PDO('mysql:host='.getenv('PLANEX_SCHEMA_DB_HOST').';port='.(getenv('PLANEX_SCHEMA_DB_PORT')?:3306).';dbname='.$db.';charset=utf8mb4',getenv('PLANEX_SCHEMA_DB_USER'),getenv('PLANEX_SCHEMA_DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$root=sys_get_temp_dir().'/feo139-'.bin2hex(random_bytes(8));
mkdir($root.'/bootstrap',0700,true); mkdir($root.'/app/Support',0700,true);
file_put_contents($root.'/.env','');
file_put_contents($root.'/bootstrap/app.php','<?php return require '.var_export(BASE_PATH.'/bootstrap/app.php',true).';');
foreach(['crypto_helper','company_database'] as $f)file_put_contents($root.'/app/Support/'.$f.'.php','<?php require_once '.var_export(BASE_PATH.'/app/Support/'.$f.'.php',true).';');
$run=static function(string $mode='',string $confirm='') use($root,$db):array {
    $env=getenv();
    foreach(['DB_HOST'=>'PLANEX_SCHEMA_DB_HOST','DB_PORT'=>'PLANEX_SCHEMA_DB_PORT','DB_USERNAME'=>'PLANEX_SCHEMA_DB_USER','DB_PASSWORD'=>'PLANEX_SCHEMA_DB_PASSWORD'] as $to=>$from)$env[$to]=(string)getenv($from);
    $env['DB_DATABASE']=$db;$env['PLANEX_ROOT']=$root;$env['PLANEX_TENANT_MIGRATION_PATH']=BASE_PATH.'/database/migrations-local/139_create_feo_transfers.sql';$env['FEO_TRANSFERS_MIGRATION_MODE']=$mode;$env['FEO_TRANSFERS_MIGRATION_CONFIRM']=$confirm;
    $proc=proc_open([PHP_BINARY,BASE_PATH.'/scripts/feo_transfers_migration_139.php'],[1=>['pipe','w'],2=>['pipe','w']],$pipes,BASE_PATH,$env);
    $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($proc);
    preg_match('/^FEO_139_SNAPSHOT=(.+)$/m',$out,$matches);
    return ['code'=>$code,'snapshot'=>isset($matches[1])?json_decode($matches[1],true):null,'output'=>$out.$err];
};
$ok=static function(bool $value,string $message):void{if(!$value)throw new RuntimeException($message);};
cleanCompanyPoolDatabase($pdo);
try {
    $pdo->exec(file_get_contents(BASE_PATH.'/database/migrations-local/122_create_feo_requests.sql'));
    $pdo->exec("CREATE TABLE schema_migrations(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,migration VARCHAR(255) UNIQUE NOT NULL,checksum VARCHAR(64) NOT NULL)");
    $pdo->exec("INSERT INTO schema_migrations(migration,checksum) VALUES('138_waste_trip_general_payments.sql','prerequisite')");
    $pdo->exec("CREATE TABLE companies(id INT PRIMARY KEY,name VARCHAR(50),status VARCHAR(20),db_identifier VARCHAR(100))");
    $pdo->prepare("INSERT INTO companies VALUES(1,'Synthetic','active',?)")->execute([$db]);
    $pdo->exec("INSERT INTO feo_requests(zayavka_id,mno_adres_pogruzki,mno_sh,mno_d) VALUES('123456','Не менять','37','55')");
    $before=$run();$ok($before['code']===0,'Read-only before: '.$before['output']);
    $ok($before['snapshot']['tenants'][0]['migration_139']===0,'Read-only must not migrate');
    $denied=$run('apply');$ok($denied['code']!==0,'Explicit confirmation required');
    $applied=$run('apply','APPLY_FEO_TRANSFERS_139');$ok($applied['code']===0,'Apply: '.$applied['output']);
    $after=$run();$ok($after['code']===0 && $after['snapshot']['tenants'][0]['migration_139']===1,'Journal and full shape');
    $ok($before['snapshot']['tenants'][0]['feo_sha256']===$after['snapshot']['tenants'][0]['feo_sha256'],'Existing FEO records unchanged');
    $repeat=$run('apply','APPLY_FEO_TRANSFERS_139');$ok($repeat['code']===0 && $repeat['snapshot']===$after['snapshot'],'Idempotent migration');
    $pdo->exec("UPDATE schema_migrations SET checksum='tampered' WHERE migration='139_create_feo_transfers.sql'");
    $ok($run()['code']!==0,'Tampered journal rejected');
    echo "FEO_TRANSFERS_139_MYSQL=PASS readonly confirmation apply idempotency data hash checksum\n";
} finally {
    cleanCompanyPoolDatabase($pdo);
    foreach(['/bootstrap/app.php','/app/Support/crypto_helper.php','/app/Support/company_database.php','/.env'] as $f)unlink($root.$f);
    foreach(['/app/Support','/app','/bootstrap',''] as $d)rmdir($root.$d);
}
