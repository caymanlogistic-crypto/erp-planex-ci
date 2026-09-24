<?php
declare(strict_types=1);

// Production Control Plane only. Default mode is read-only inspection.
if(PHP_SAPI!=='cli')throw new RuntimeException('CLI only');
$root=rtrim((string)getenv('PLANEX_ROOT'),'/');
if(!is_file($root.'/.env')||!is_file($root.'/bootstrap/app.php'))throw new RuntimeException('PLANEX_ROOT invalid');
$apply=getenv('TRIP_COMPLETION_MIGRATION_MODE')==='apply';
if($apply&&getenv('TRIP_COMPLETION_MIGRATION_CONFIRM')!=='APPLY_TRIP_COMPLETION_144')throw new RuntimeException('Explicit migration confirmation missing');
$name='144_trip_completion_explicit_statuses.sql';
$path=getenv('PLANEX_TENANT_MIGRATION_PATH')?:$root.'/database/migrations-local/'.$name;
$sql=file_get_contents($path);if($sql===false)throw new RuntimeException('Migration SQL missing');$checksum=hash('sha256',$sql);
$config=require $root.'/bootstrap/app.php';require_once $root.'/app/Support/crypto_helper.php';require_once $root.'/app/Support/company_database.php';
$connect=static fn(array$c):PDO=>new PDO('mysql:host='.$c['host'].';port='.($c['port']??3306).';dbname='.$c['database'].';charset=utf8mb4',$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$central=$connect($config['database']);$companies=$central->query("SELECT * FROM companies WHERE status='active' AND db_identifier IS NOT NULL AND db_identifier<>'' ORDER BY id")->fetchAll();
if(!$companies)throw new RuntimeException('No active tenants');
$exists=static function(PDO$pdo,string$table):bool{$q=$pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$q->execute([$table]);return(int)$q->fetchColumn()===1;};
$columnExists=static function(PDO$pdo,string$table,string$column):bool{$q=$pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');$q->execute([$table,$column]);return(int)$q->fetchColumn()===1;};
$hashRows=static function(PDO$pdo,string$query):array{$hash=hash_init('sha256');$count=0;foreach($pdo->query($query)as$row){hash_update($hash,json_encode($row,JSON_THROW_ON_ERROR)."\n");$count++;}return['rows'=>$count,'sha256'=>hash_final($hash)];};
$expected=[
 'waste_trip_completion_items'=>['waste_trip_request_id','arrived_at','departed_at','driver_signature_present','driver_signature_status','sender_signature_present','sender_signature_status','tn_datetime_missing','tn_datetime_status','data_from_tracker','data_from_driver','data_invented','recovery_source','comment','updated_by_membership_id','lock_version','created_at','updated_at'],
 'waste_trip_completion_documents'=>['id','waste_trip_request_id','original_name','download_name','stored_path','mime_type','size_bytes','uploaded_by_membership_id','created_at','deleted_at','deleted_by_membership_id'],
];
$indexes=['waste_trip_completion_items'=>['PRIMARY','idx_waste_trip_completion_updated'],'waste_trip_completion_documents'=>['PRIMARY','uk_waste_trip_completion_stored_path','idx_waste_trip_completion_documents_request']];
$snapshot=['tenants'=>[]];
foreach($companies as$company){
 $pdo=$connect(companyDatabaseConfig($config,$company));$database=(string)$pdo->query('SELECT DATABASE()')->fetchColumn();if($database!==(string)$company['db_identifier'])throw new RuntimeException('Wrong tenant');
 $lock='planex_migrate_'.substr(hash('sha256',$database),0,32);$held=false;
 try{
  $q=$pdo->prepare('SELECT GET_LOCK(?,10)');$q->execute([$lock]);if((int)$q->fetchColumn()!==1)throw new RuntimeException('Migration lock unavailable');$held=true;
  foreach(['schema_migrations','waste_trips','waste_trip_requests','feo_requests','waste_trip_completion_items','waste_trip_completion_documents']as$table)if(!$exists($pdo,$table))throw new RuntimeException('Migration 144 prerequisite missing: '.$table);
  $journal=$pdo->prepare('SELECT checksum FROM schema_migrations WHERE migration=?');$journal->execute(['143_extend_waste_trip_completion_provenance.sql']);if($journal->fetchColumn()===false)throw new RuntimeException('Migration 143 prerequisite missing');
  $journal->execute([$name]);$stored=$journal->fetchColumn();$items=$exists($pdo,'waste_trip_completion_items');$documents=$exists($pdo,'waste_trip_completion_documents');
  $statusColumns=0;foreach(['sender_signature_status','driver_signature_status','tn_datetime_status','recovery_source']as$column)if($columnExists($pdo,'waste_trip_completion_items',$column))$statusColumns++;
  if($stored!==false&&!hash_equals((string)$stored,$checksum))throw new RuntimeException('Migration 144 checksum mismatch');
  if($stored===false&&$statusColumns!==0)throw new RuntimeException('Unjournaled trip completion explicit status schema');
  if($stored!==false&&$statusColumns!==4)throw new RuntimeException('Incomplete trip completion explicit status schema');
  $beforeTrips=$hashRows($pdo,'SELECT * FROM waste_trips ORDER BY id');$beforeLinks=$hashRows($pdo,'SELECT * FROM waste_trip_requests ORDER BY id');
  $beforeCompletion=$hashRows($pdo,'SELECT waste_trip_request_id,arrived_at,departed_at,driver_signature_present,sender_signature_present,comment,updated_by_membership_id,lock_version,created_at,updated_at FROM waste_trip_completion_items ORDER BY waste_trip_request_id');
  if($apply&&$stored===false){
   if((int)$pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE CAST(LEFT(migration,3) AS UNSIGNED)>144")->fetchColumn()>0)throw new RuntimeException('Newer migration exists');
   $withoutComments=preg_replace('/^\s*--[^\n]*$/m','',$sql);$statements=array_values(array_filter(array_map('trim',explode(';',(string)$withoutComments))));
   if(count($statements)!==1)throw new RuntimeException('Unexpected migration statement count');
   if(!str_starts_with($statements[0],'ALTER TABLE `waste_trip_completion_items`')||substr_count($statements[0],'ADD COLUMN')!==4)throw new RuntimeException('Migration change outside allowed scope');
   foreach($statements as$statement)$pdo->exec($statement);$pdo->prepare('INSERT INTO schema_migrations(migration,checksum) VALUES(?,?)')->execute([$name,$checksum]);$stored=$checksum;$items=$documents=true;
   $statusColumns=4;
  }
  foreach($expected as$table=>$columns)if($stored!==false&&$exists($pdo,$table)){
   $q=$pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION');$q->execute([$table]);if($q->fetchAll(PDO::FETCH_COLUMN)!==$columns)throw new RuntimeException('Migration 144 column mismatch: '.$table);
   foreach($indexes[$table]as$index){$q=$pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?');$q->execute([$table,$index]);if((int)$q->fetchColumn()===0)throw new RuntimeException('Migration 144 index missing: '.$index);}
  }
  $afterTrips=$hashRows($pdo,'SELECT * FROM waste_trips ORDER BY id');$afterLinks=$hashRows($pdo,'SELECT * FROM waste_trip_requests ORDER BY id');$afterCompletion=$hashRows($pdo,'SELECT waste_trip_request_id,arrived_at,departed_at,driver_signature_present,sender_signature_present,comment,updated_by_membership_id,lock_version,created_at,updated_at FROM waste_trip_completion_items ORDER BY waste_trip_request_id');if($beforeTrips!==$afterTrips||$beforeLinks!==$afterLinks||$beforeCompletion!==$afterCompletion)throw new RuntimeException('Migration 144 changed source business data');
  $snapshot['tenants'][]=['company_id'=>(int)$company['id'],'migration_144'=>$stored!==false?1:0,'checksum'=>$stored!==false?$stored:null,'items_table'=>$items,'documents_table'=>$documents,'status_columns'=>$statusColumns,'trip_rows'=>$beforeTrips['rows'],'trip_sha256'=>$beforeTrips['sha256'],'request_rows'=>$beforeLinks['rows'],'request_sha256'=>$beforeLinks['sha256'],'completion_rows'=>$beforeCompletion['rows'],'completion_sha256'=>$beforeCompletion['sha256'],'document_rows'=>$documents?(int)$pdo->query('SELECT COUNT(*) FROM waste_trip_completion_documents')->fetchColumn():0];
 }finally{if($held){$q=$pdo->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$lock]);}}
}
echo 'TRIP_COMPLETION_144_SNAPSHOT='.json_encode($snapshot,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";echo $apply?"TRIP_COMPLETION_144_APPLY_OK\n":"TRIP_COMPLETION_144_READ_ONLY_OK\n";
