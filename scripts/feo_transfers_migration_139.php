<?php
declare(strict_types=1);

// Production Control Plane only. Default is a read-only snapshot, never runtime DDL.
if (PHP_SAPI !== 'cli') throw new RuntimeException('CLI only');
$root=rtrim((string)getenv('PLANEX_ROOT'),'/');
if (!is_file($root.'/.env') || !is_file($root.'/bootstrap/app.php')) throw new RuntimeException('PLANEX_ROOT invalid');
$apply=getenv('FEO_TRANSFERS_MIGRATION_MODE')==='apply';
if ($apply && getenv('FEO_TRANSFERS_MIGRATION_CONFIRM')!=='APPLY_FEO_TRANSFERS_139') throw new RuntimeException('Explicit migration confirmation missing');
$name='139_create_feo_transfers.sql';
$path=getenv('PLANEX_TENANT_MIGRATION_PATH')?:$root.'/database/migrations-local/'.$name;
$sql=file_get_contents($path); if ($sql===false) throw new RuntimeException('Migration SQL missing');
$checksum=hash('sha256',$sql);
$config=require $root.'/bootstrap/app.php';
require_once $root.'/app/Support/crypto_helper.php';
require_once $root.'/app/Support/company_database.php';
$connect=static fn(array $c):PDO=>new PDO('mysql:host='.$c['host'].';port='.($c['port']??3306).';dbname='.$c['database'].';charset=utf8mb4',$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$central=$connect($config['database']);
$companies=$central->query("SELECT * FROM companies WHERE status='active' AND db_identifier IS NOT NULL AND db_identifier<>'' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
if (!$companies) throw new RuntimeException('No active tenants');
$targets=['feo_transfer_batches','feo_transfer_items','feo_location_overrides','feo_transfer_outbox','geocoding_settings'];
$expectedColumns=[
 'feo_transfer_batches'=>['id','name','mode','source_request_id','destination_json','comment','request_count','status','operation_key','created_by_user_id','created_by_role','created_by_name','created_at','reverted_by_user_id','reverted_by_name','reverted_at'],
 'feo_transfer_items'=>['batch_id','zayavka_id','request_row_id','before_json','after_json','previous_batch_id'],
 'feo_location_overrides'=>['zayavka_id','batch_id'],
 'feo_transfer_outbox'=>['id','batch_id','operation','payload_json','status','created_at','sent_at','last_error'],
 'geocoding_settings'=>['id','api_key_encrypted','updated_at'],
];
$exists=static function(PDO $p,string $table):bool {$q=$p->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$q->execute([$table]);return (int)$q->fetchColumn()===1;};
$snapshot=['tenants'=>[]];
foreach ($companies as $company) {
    $pdo=$connect(companyDatabaseConfig($config,$company));
    $database=(string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    if ($database!==$company['db_identifier']) throw new RuntimeException('Wrong tenant');
    $locks=['planex_migrate_'.substr(hash('sha256',$database),0,32),'planex_feo_'.substr(hash('sha256',$database),0,24)]; $held=[];
    try {
        foreach ($locks as $lock) {$q=$pdo->prepare('SELECT GET_LOCK(?,10)');$q->execute([$lock]);if((int)$q->fetchColumn()!==1)throw new RuntimeException('Migration/FEO lock unavailable');$held[]=$lock;}
        $journal=$pdo->prepare('SELECT checksum FROM schema_migrations WHERE migration=?');
        $journal->execute(['138_waste_trip_general_payments.sql']); if($journal->fetchColumn()===false || !$exists($pdo,'feo_requests'))throw new RuntimeException('Migration 138 prerequisite missing');
        $journal->execute([$name]); $stored=$journal->fetchColumn();
        if($stored!==false && !hash_equals((string)$stored,$checksum))throw new RuntimeException('Migration 139 checksum mismatch');
        $flags=array_map(fn(string $table):bool=>$exists($pdo,$table),$targets);
        if($stored===false && in_array(true,$flags,true))throw new RuntimeException('Unjournaled FEO transfers schema');
        if($stored!==false && in_array(false,$flags,true))throw new RuntimeException('Incomplete FEO transfers schema');
        if($apply && $stored===false) {
            if((int)$pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE CAST(LEFT(migration,3) AS UNSIGNED)>139")->fetchColumn()>0)throw new RuntimeException('Newer migration exists');
            $withoutComments=preg_replace('/^\s*--[^\n]*$/m','',$sql);
            $statements=array_values(array_filter(array_map('trim',explode(';',(string)$withoutComments))));
            if(count($statements)!==5)throw new RuntimeException('Unexpected migration statement count');
            foreach($statements as $index=>$statement) {
                if(!preg_match('/^CREATE TABLE IF NOT EXISTS ([a-z_]+)\s*\(/',$statement,$match) || $match[1]!==$targets[$index])throw new RuntimeException('Migration statement outside allowed scope');
                $pdo->exec($statement);
            }
            $pdo->prepare('INSERT INTO schema_migrations(migration,checksum) VALUES(?,?)')->execute([$name,$checksum]); $stored=$checksum;
        }
        $row=['company_id'=>(int)$company['id'],'migration_139'=>$stored!==false?1:0,'checksum'=>$stored!==false?$stored:null,'tables'=>[]];
        foreach($targets as $table) {
            $present=$exists($pdo,$table);
            $row['tables'][$table]=['present'=>$present,'rows'=>$present?(int)$pdo->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn():0];
            if($stored!==false) {
                $q=$pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION'); $q->execute([$table]);
                if($q->fetchAll(PDO::FETCH_COLUMN)!==$expectedColumns[$table])throw new RuntimeException('Migration 139 column mismatch');
            }
        }
        $hash=hash_init('sha256'); $count=0;
        $q=$pdo->query('SELECT * FROM feo_requests ORDER BY id');
        while($request=$q->fetch(PDO::FETCH_ASSOC)) {hash_update($hash,json_encode($request,JSON_THROW_ON_ERROR)."\n");$count++;}
        $row['feo_rows']=$count; $row['feo_sha256']=hash_final($hash);
        $snapshot['tenants'][]=$row;
    } finally {foreach(array_reverse($held) as $lock)$pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);}
}
echo 'FEO_139_SNAPSHOT='.json_encode($snapshot,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
echo $apply?"FEO_139_APPLY_OK\n":"FEO_139_READ_ONLY_OK\n";
