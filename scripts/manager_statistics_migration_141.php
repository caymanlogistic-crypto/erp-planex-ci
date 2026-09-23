<?php
declare(strict_types=1);

// Production Control Plane only. Default mode is read-only inspection.
if (PHP_SAPI !== 'cli') throw new RuntimeException('CLI only');
$root=rtrim((string)getenv('PLANEX_ROOT'),'/');
if(!is_file($root.'/.env')||!is_file($root.'/bootstrap/app.php'))throw new RuntimeException('PLANEX_ROOT invalid');
$apply=getenv('MANAGER_STATISTICS_MIGRATION_MODE')==='apply';
if($apply&&getenv('MANAGER_STATISTICS_MIGRATION_CONFIRM')!=='APPLY_MANAGER_STATISTICS_141')throw new RuntimeException('Explicit migration confirmation missing');
$name='141_create_waste_trip_start_statistics.sql';
$path=getenv('PLANEX_TENANT_MIGRATION_PATH')?:$root.'/database/migrations-local/'.$name;
$sql=file_get_contents($path);if($sql===false)throw new RuntimeException('Migration SQL missing');
$checksum=hash('sha256',$sql);
$config=require $root.'/bootstrap/app.php';
require_once $root.'/app/Support/crypto_helper.php';
require_once $root.'/app/Support/company_database.php';
$connect=static fn(array $c):PDO=>new PDO('mysql:host='.$c['host'].';port='.($c['port']??3306).';dbname='.$c['database'].';charset=utf8mb4',$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$central=$connect($config['database']);
$companies=$central->query("SELECT * FROM companies WHERE status='active' AND db_identifier IS NOT NULL AND db_identifier<>'' ORDER BY id")->fetchAll();
if(!$companies)throw new RuntimeException('No active tenants');
$exists=static function(PDO $pdo,string $table):bool{$q=$pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$q->execute([$table]);return(int)$q->fetchColumn()===1;};
$hashRows=static function(PDO $pdo,string $query):array{$hash=hash_init('sha256');$count=0;foreach($pdo->query($query)as$row){hash_update($hash,json_encode($row,JSON_THROW_ON_ERROR)."\n");$count++;}return['rows'=>$count,'sha256'=>hash_final($hash)];};
$expectedColumns=['waste_trip_id','actual_start_date','responsible_user_id','responsible_membership_id','responsible_name_snapshot','request_count','total_mass_kg','is_backfilled','captured_at','updated_at'];
$snapshot=['tenants'=>[]];
foreach($companies as$company){
    $pdo=$connect(companyDatabaseConfig($config,$company));$database=(string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    if($database!==(string)$company['db_identifier'])throw new RuntimeException('Wrong tenant');
    $lock='planex_migrate_'.substr(hash('sha256',$database),0,32);$held=false;
    try{
        $q=$pdo->prepare('SELECT GET_LOCK(?,10)');$q->execute([$lock]);if((int)$q->fetchColumn()!==1)throw new RuntimeException('Migration lock unavailable');$held=true;
        foreach(['schema_migrations','waste_trips','waste_trip_requests','users']as$table)if(!$exists($pdo,$table))throw new RuntimeException('Migration 141 prerequisite missing: '.$table);
        $journal=$pdo->prepare('SELECT checksum FROM schema_migrations WHERE migration=?');
        $journal->execute(['140_create_trip_document_collection.sql']);if($journal->fetchColumn()===false)throw new RuntimeException('Migration 140 prerequisite missing');
        $journal->execute([$name]);$stored=$journal->fetchColumn();$present=$exists($pdo,'waste_trip_start_statistics');
        if($stored!==false&&!hash_equals((string)$stored,$checksum))throw new RuntimeException('Migration 141 checksum mismatch');
        if($stored===false&&$present)throw new RuntimeException('Unjournaled manager statistics schema');
        if($stored!==false&&!$present)throw new RuntimeException('Incomplete manager statistics schema');
        $beforeTrips=$hashRows($pdo,'SELECT * FROM waste_trips ORDER BY id');
        $beforeLinks=$hashRows($pdo,'SELECT * FROM waste_trip_requests ORDER BY id');
        if($apply&&$stored===false){
            if((int)$pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE CAST(LEFT(migration,3) AS UNSIGNED)>141")->fetchColumn()>0)throw new RuntimeException('Newer migration exists');
            $withoutComments=preg_replace('/^\s*--[^\n]*$/m','',$sql);
            $statements=array_values(array_filter(array_map('trim',explode(';',(string)$withoutComments))));
            if(count($statements)!==2)throw new RuntimeException('Unexpected migration statement count');
            if(!str_starts_with($statements[0],'CREATE TABLE IF NOT EXISTS `waste_trip_start_statistics`'))throw new RuntimeException('Migration create outside allowed scope');
            if(!str_starts_with($statements[1],'INSERT INTO `waste_trip_start_statistics`'))throw new RuntimeException('Migration backfill outside allowed scope');
            foreach($statements as$statement)$pdo->exec($statement);
            $pdo->prepare('INSERT INTO schema_migrations(migration,checksum) VALUES(?,?)')->execute([$name,$checksum]);$stored=$checksum;$present=true;
        }
        $row=['company_id'=>(int)$company['id'],'migration_141'=>$stored!==false?1:0,'checksum'=>$stored!==false?$stored:null,'table_present'=>$present,'trip_rows'=>$beforeTrips['rows'],'trip_sha256'=>$beforeTrips['sha256'],'request_rows'=>$beforeLinks['rows'],'request_sha256'=>$beforeLinks['sha256'],'fact_rows'=>0,'expected_fact_rows'=>0];
        $row['expected_fact_rows']=(int)$pdo->query("SELECT COUNT(*) FROM waste_trips WHERE actual_start_date IS NOT NULL AND status IN ('in_progress','completed','cancelled')")->fetchColumn();
        if($present){
            $q=$pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION');$q->execute(['waste_trip_start_statistics']);
            if($q->fetchAll(PDO::FETCH_COLUMN)!==$expectedColumns)throw new RuntimeException('Migration 141 column mismatch');
            foreach(['PRIMARY','idx_waste_trip_start_stats_date','idx_waste_trip_start_stats_manager']as$index){$q=$pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?');$q->execute(['waste_trip_start_statistics',$index]);if((int)$q->fetchColumn()===0)throw new RuntimeException('Migration 141 index missing');}
            $row['fact_rows']=(int)$pdo->query('SELECT COUNT(*) FROM waste_trip_start_statistics')->fetchColumn();
            if($row['fact_rows']!==$row['expected_fact_rows'])throw new RuntimeException('Migration 141 backfill mismatch');
        }
        $afterTrips=$hashRows($pdo,'SELECT * FROM waste_trips ORDER BY id');$afterLinks=$hashRows($pdo,'SELECT * FROM waste_trip_requests ORDER BY id');
        if($beforeTrips!==$afterTrips||$beforeLinks!==$afterLinks)throw new RuntimeException('Migration 141 changed source business data');
        $snapshot['tenants'][]=$row;
    }finally{if($held){$q=$pdo->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$lock]);}}
}
echo 'MANAGER_141_SNAPSHOT='.json_encode($snapshot,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
echo $apply?"MANAGER_141_APPLY_OK\n":"MANAGER_141_READ_ONLY_OK\n";
