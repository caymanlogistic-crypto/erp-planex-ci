<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/app.php';require_once BASE_PATH.'/app/Support/company_database.php';
$name=(string)getenv('PLANEX_TEST_FIXED_DB');
if(getenv('APP_ENV')!=='test'||$name===''||!in_array($name,explode(',',(string)getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST')),true))throw new RuntimeException('Guarded test DB required');
$pdo=new PDO('mysql:host='.getenv('PLANEX_SCHEMA_DB_HOST').';port='.getenv('PLANEX_SCHEMA_DB_PORT').';dbname='.$name.';charset=utf8mb4',getenv('PLANEX_SCHEMA_DB_USER'),getenv('PLANEX_SCHEMA_DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$dir=sys_get_temp_dir().'/planex-mail-migration-'.bin2hex(random_bytes(5));mkdir($dir,0700);
$sha=str_repeat('a',40);file_put_contents($dir.'/marker',$sha);
$env=array_merge(getenv(),['PLANEX_ROOT'=>BASE_PATH,'PLANEX_SOURCE_SHA'=>$sha,'PLANEX_DEPLOYED_SHA'=>$sha,'PLANEX_DEPLOY_MARKER'=>$dir.'/marker','PLANEX_BACKUP_ROOT'=>$dir.'/backups','PLANEX_MIGRATION_SHA256'=>hash_file('sha256',BASE_PATH.'/database/migrations/016_create_employee_mail_profiles.sql'),'DB_HOST'=>getenv('PLANEX_SCHEMA_DB_HOST'),'DB_PORT'=>getenv('PLANEX_SCHEMA_DB_PORT'),'DB_DATABASE'=>$name,'DB_USERNAME'=>getenv('PLANEX_SCHEMA_DB_USER'),'DB_PASSWORD'=>getenv('PLANEX_SCHEMA_DB_PASSWORD')]);
$run=static function(string $mode,array $override=[],bool $pass=true)use($env):string{
    $p=proc_open([PHP_BINARY,BASE_PATH.'/scripts/central_016_production_migrate.php',$mode],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,BASE_PATH,array_merge($env,$override));fclose($pipes[0]);$out=stream_get_contents($pipes[1]);fclose($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[2]);$rc=proc_close($p);
    if(($rc===0)!==$pass)throw new RuntimeException('Migration scenario failed: '.$mode.' '.$out.$err);return $out;
};
cleanCompanyPoolDatabase($pdo);
try {
    $pdo->exec('CREATE TABLE companies(id INT UNSIGNED PRIMARY KEY) ENGINE=InnoDB');
    $pdo->exec(file_get_contents(BASE_PATH.'/database/migrations/013_create_roles_access.sql'));
    $pdo->exec(file_get_contents(BASE_PATH.'/database/migrations/015_add_user_accounts_max_user_id.sql'));
    $pdo->exec('CREATE TABLE schema_migrations(id INT AUTO_INCREMENT PRIMARY KEY,migration VARCHAR(255) UNIQUE,checksum VARCHAR(64),executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)');
    $q=$pdo->prepare('INSERT INTO schema_migrations(migration,checksum) VALUES(?,?)');$q->execute(['015_add_user_accounts_max_user_id.sql',hash_file('sha256',BASE_PATH.'/database/migrations/015_add_user_accounts_max_user_id.sql')]);
    $run('inspect');$run('apply',[],false);
    $apply=['PLANEX_CONFIRM_016'=>'APPLY_CENTRAL_016_EMPLOYEE_MAIL'];
    $run('apply',$apply);$run('apply',$apply);$run('inspect');
    $run('inspect',['PLANEX_SOURCE_SHA'=>str_repeat('b',40)],false);
    $run('inspect',['PLANEX_MIGRATION_SHA256'=>str_repeat('b',64)],false);
    $pdo->exec('INSERT INTO companies(id) VALUES(1)');
    $pdo->exec("INSERT INTO employee_mail_legal_entities(company_id,code,name,email_domain) VALUES(1,'planex','Company A','example.test')");
    $rollback=['PLANEX_CONFIRM_016'=>'ROLLBACK_CENTRAL_016_EMPLOYEE_MAIL'];
    $run('rollback',$rollback,false);
    $pdo->exec('DELETE FROM employee_mail_legal_entities');$run('rollback',$rollback);$run('inspect');
    echo "EMPLOYEE_MAIL_MIGRATION_MYSQL=PASS\n";
}finally{cleanCompanyPoolDatabase($pdo);}
