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
    $journal=$pdo->query("SELECT migration,checksum FROM schema_migrations WHERE migration IN ('137_waste_trip_synchronization.sql','138_waste_trip_general_payments.sql')")->fetchAll(PDO::FETCH_KEY_PAIR);
    $column=$pdo->query("SELECT COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='waste_trips' AND COLUMN_NAME='financial_model_version'")->fetchColumn();
    $hasGeneral=(int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='waste_trip_general_payments'")->fetchColumn()===1;
    $nullable=$pdo->query("SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='waste_trip_customer_payments' AND COLUMN_NAME='amount'")->fetchColumn();
    $row=['company_id'=>(int)$company['id'],'migration_137'=>isset($journal['137_waste_trip_synchronization.sql'])?1:0,'migration_138'=>isset($journal['138_waste_trip_general_payments.sql'])?1:0,'migration_138_checksum'=>$journal['138_waste_trip_general_payments.sql']??null,'general_table'=>$hasGeneral?1:0,'model_column'=>$column!==false?1:0,'model_default'=>$column,'customer_nullable'=>$nullable==='YES'?1:0];
    foreach(['waste_trips','waste_trip_requests','clients','contractors','finance_operations','finance_operation_allocations','finance_invoices','finance_invoice_links'] as $table){
        $rows=$pdo->query('SELECT * FROM `'.$table.'` ORDER BY id');$hash=hash_init('sha256');$count=0;
        while($value=$rows->fetch(PDO::FETCH_ASSOC)){if($table==='waste_trips')unset($value['financial_model_version'],$value['lock_version'],$value['updated_at']);hash_update($hash,json_encode($value,JSON_THROW_ON_ERROR));$count++;}
        $row['data'][$table]=['count'=>$count,'sha256'=>hash_final($hash)];
    }
    $row['model_pending']=$column===false?null:(int)$pdo->query('SELECT COUNT(*) FROM waste_trips WHERE financial_model_version<>2')->fetchColumn();
    $row['payments']=[];
    foreach(['customer','carrier','general'] as $side){
        if($side==='general'&&!$hasGeneral){$row['payments'][$side]=[];continue;}
        $table=match($side){'customer'=>'waste_trip_customer_payments','carrier'=>'waste_trip_carrier_payments','general'=>'waste_trip_general_payments'};
        $row['payments'][$side]=$pdo->query("SELECT waste_trip_id,sort_order,amount,payment_type,payment_method,vat_rate,condition_type,days_count,days_kind,specific_due_date,condition_comment FROM {$table} ORDER BY waste_trip_id,sort_order,id")->fetchAll(PDO::FETCH_ASSOC);
    }
    $snapshot['tenants'][]=$row;
}
echo 'WASTE_138_SNAPSHOT='.json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\nWASTE_138_READ_ONLY_OK\n";
