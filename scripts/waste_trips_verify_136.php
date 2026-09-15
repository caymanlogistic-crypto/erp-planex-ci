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
$central->exec('SET SESSION TRANSACTION READ ONLY');
$companies=$central->query("SELECT * FROM companies WHERE status='active' AND db_identifier IS NOT NULL AND db_identifier<>'' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
if($companies===[])throw new RuntimeException('No active tenants');
$snapshot=['central'=>['active_companies'=>count($companies)],'tenants'=>[]];
foreach($companies as $company){
    $cfg=companyDatabaseConfig($config,$company);
    $pdo=new PDO('mysql:host='.$cfg['host'].';port='.($cfg['port']??3306).';dbname='.$cfg['database'].';charset=utf8mb4',$cfg['username'],$cfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    $pdo->exec('SET SESSION TRANSACTION READ ONLY');
    if((string)$pdo->query('SELECT DATABASE()')->fetchColumn()!==(string)$company['db_identifier'])throw new RuntimeException('Tenant identity mismatch');
    $journal=$pdo->query("SELECT migration,checksum FROM schema_migrations WHERE migration IN ('135_create_waste_trip_carrier_payments.sql','136_waste_trip_synchronization.sql')")->fetchAll(PDO::FETCH_KEY_PAIR);
    $column=$pdo->query("SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='waste_trips' AND COLUMN_NAME='deleted_at'")->fetchColumn();
    $status=$pdo->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='waste_trips' AND COLUMN_NAME='status'")->fetchColumn();
    $index=$pdo->query("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='waste_trips' AND INDEX_NAME='idx_waste_trips_deleted'")->fetchColumn();
    $row=['company_id'=>(int)$company['id'],'migration_135'=>isset($journal['135_create_waste_trip_carrier_payments.sql'])?1:0,'migration_136'=>isset($journal['136_waste_trip_synchronization.sql'])?1:0,'migration_136_checksum'=>$journal['136_waste_trip_synchronization.sql']??null,'deleted_column'=>$column==='YES'?1:0,'planned_status'=>str_contains((string)$status,"'planned_route'")?1:0,'trip_index'=>(int)$index===2?1:0];
    // Hash existing operational data, excluding only the newly added NULL column.
    foreach(['waste_trips','waste_trip_requests','waste_trip_customer_payments','waste_trip_carrier_payments','waste_trip_audit_log','finance_obligations','finance_operation_allocations'] as $table){
        $rows=$pdo->query('SELECT * FROM `'.$table.'` ORDER BY id');$hash=hash_init('sha256');$count=0;
        while($value=$rows->fetch(PDO::FETCH_ASSOC)){if($table==='waste_trips')unset($value['deleted_at']);hash_update($hash,json_encode($value,JSON_THROW_ON_ERROR));$count++;}
        $row['data'][$table]=['count'=>$count,'sha256'=>hash_final($hash)];
    }
    $snapshot['tenants'][]=$row;
}
echo 'WASTE_136_SNAPSHOT='.json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\nWASTE_136_READ_ONLY_OK\n";
