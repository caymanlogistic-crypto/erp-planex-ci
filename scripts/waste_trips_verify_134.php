<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'cgi-fcgi') { fwrite(STDERR, "CLI/CGI only.\n"); exit(1); }
$root = rtrim((string)(getenv('PLANEX_ROOT') ?: ($argv[1] ?? '')), '/');
if ($root === '' || !is_file($root . '/.env') || !is_file($root . '/bootstrap/app.php')) throw new RuntimeException('PLANEX_ROOT invalid');
$config = require $root . '/bootstrap/app.php';
require_once $root . '/app/Support/crypto_helper.php';
require_once $root . '/app/Support/company_database.php';
$centralCfg=$config['database'];
$central=new PDO('mysql:host='.$centralCfg['host'].';port='.($centralCfg['port']??3306).';dbname='.$centralCfg['database'].';charset=utf8mb4',$centralCfg['username'],$centralCfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$tableExists=static function(PDO $pdo,string $table):bool{$s=$pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$s->execute([$table]);return(int)$s->fetchColumn()===1;};
$columnExists=static function(PDO $pdo,string $column):bool{$s=$pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='waste_trip_customer_payments' AND COLUMN_NAME=?");$s->execute([$column]);return(int)$s->fetchColumn()===1;};
$indexExists=static function(PDO $pdo):bool{$s=$pdo->query("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='waste_trip_customer_payments' AND INDEX_NAME='idx_waste_trip_customer_payments_trip'");return(int)$s->fetchColumn()>0;};
$fkExists=static function(PDO $pdo):bool{$s=$pdo->query("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='waste_trip_customer_payments' AND CONSTRAINT_NAME='fk_waste_trip_customer_payments_trip' AND CONSTRAINT_TYPE='FOREIGN KEY'");return(int)$s->fetchColumn()===1;};
$deleteRule=static function(PDO $pdo):string{$s=$pdo->query("SELECT DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='waste_trip_customer_payments' AND CONSTRAINT_NAME='fk_waste_trip_customer_payments_trip'");$v=$s->fetchColumn();return$v===false?'':(string)$v;};
$journal=static function(PDO $pdo,string $name):array{$s=$pdo->prepare('SELECT checksum FROM schema_migrations WHERE migration=?');$s->execute([$name]);$v=$s->fetchColumn();return['present'=>$v!==false?1:0,'checksum'=>$v!==false?(string)$v:''];};
$companies=$central->query("SELECT id,name,status,db_identifier,db_host,db_port,db_username,db_password FROM companies WHERE status='active' AND db_identifier IS NOT NULL AND db_identifier<>'' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
if($companies===[])throw new RuntimeException('No active tenant databases found');
$snapshot=['central'=>['db'=>(string)$central->query('SELECT DATABASE()')->fetchColumn(),'active_companies'=>count($companies)],'tenants'=>[]];
foreach($companies as$company){
    $cfg=companyDatabaseConfig($config,$company);$pdo=new PDO('mysql:host='.$cfg['host'].';port='.($cfg['port']??3306).';dbname='.$cfg['database'].';charset=utf8mb4',$cfg['username'],$cfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    if((string)$pdo->query('SELECT DATABASE()')->fetchColumn()!==(string)$company['db_identifier'])throw new RuntimeException('Unexpected tenant DB for company '.$company['id']);
    if(!$tableExists($pdo,'schema_migrations'))throw new RuntimeException('schema_migrations missing for company '.$company['id']);
    $m133=$journal($pdo,'133_create_max_integration.sql');$m134=$journal($pdo,'134_create_waste_trip_customer_payments.sql');
    $wt=$tableExists($pdo,'waste_trips');$pay=$tableExists($pdo,'waste_trip_customer_payments');
    $snapshot['tenants'][]=[
        'company_id'=>(int)$company['id'],'db'=>(string)$company['db_identifier'],
        'migration_133'=>$m133['present'],'migration_134'=>$m134['present'],'migration_134_checksum'=>$m134['checksum'],
        'waste_trips_table'=>$wt?1:0,'waste_trip_customer_payments_table'=>$pay?1:0,
        'waste_trips_rows'=>$wt?(int)$pdo->query('SELECT COUNT(*) FROM waste_trips')->fetchColumn():0,
        'customer_payment_rows'=>$pay?(int)$pdo->query('SELECT COUNT(*) FROM waste_trip_customer_payments')->fetchColumn():0,
        'amount_column'=>$pay&&$columnExists($pdo,'amount')?1:0,
        'condition_type_column'=>$pay&&$columnExists($pdo,'condition_type')?1:0,
        'days_count_column'=>$pay&&$columnExists($pdo,'days_count')?1:0,
        'specific_due_date_column'=>$pay&&$columnExists($pdo,'specific_due_date')?1:0,
        'created_by_user_column'=>$pay&&$columnExists($pdo,'created_by_user_id')?1:0,
        'updated_by_user_column'=>$pay&&$columnExists($pdo,'updated_by_user_id')?1:0,
        'trip_index'=>$pay&&$indexExists($pdo)?1:0,
        'trip_fk'=>$pay&&$fkExists($pdo)?1:0,
        'trip_fk_delete_rule'=>$pay?$deleteRule($pdo):'',
    ];
}
echo 'WASTE_134_SNAPSHOT='.json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\nWASTE_134_READ_ONLY_OK\n";
