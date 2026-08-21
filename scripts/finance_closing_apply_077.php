<?php
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'cgi-fcgi') { fwrite(STDERR, "CLI/CGI only.\n"); exit(1); }
function fc77_env_file(string $path): array { if (!is_file($path)) throw new RuntimeException('Missing .env file.'); $rows=file($path,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES); $out=[]; foreach($rows as $row){$row=trim($row); if($row===''||$row[0]==='#')continue; $pos=strpos($row,'='); if($pos===false)continue; $key=trim(substr($row,0,$pos)); $value=trim(substr($row,$pos+1)); if(strlen($value)>=2){$a=$value[0];$b=$value[strlen($value)-1];if(($a==='"'&&$b==='"')||($a==="'"&&$b==="'"))$value=substr($value,1,-1);} $out[$key]=$value;} return $out; }
function fc77_tenant_cfg(array $env,string $dbName): array { $cfg=['host'=>$env['DB_HOST']??'127.0.0.1','port'=>$env['DB_PORT']??'3306','username'=>$env['DB_USERNAME']??'','password'=>$env['DB_PASSWORD']??'']; if(isset($env['COMPANY_DB_POOL_JSON'])&&trim($env['COMPANY_DB_POOL_JSON'])!==''){ $pool=json_decode($env['COMPANY_DB_POOL_JSON'],true); if(is_array($pool)){ foreach($pool as $entry){ if(!is_array($entry))continue; $db=$entry['database']??($entry['db']??''); if($db!==$dbName)continue; foreach(['host','port','username','password'] as $k){ if(isset($entry[$k])&&$entry[$k]!=='')$cfg[$k]=$entry[$k]; } break; } } } return $cfg; }
$root=rtrim((string)(getenv('PLANEX_ROOT')?:($argv[1]??'')),'/');
$migrationPath=(string)($argv[2]??'');
if($root===''||!is_file($root.'/.env'))throw new RuntimeException('PLANEX_ROOT is invalid.');
if($migrationPath===''||!is_file($migrationPath))throw new RuntimeException('Migration 077 path missing.');
$name='077_finance_closing_documents.sql';
$sql=file_get_contents($migrationPath); if($sql===false||trim($sql)==='')throw new RuntimeException('Migration 077 empty.');
$checksum=hash('sha256',$sql);
$env=fc77_env_file($root.'/.env'); $dbName='spugovxsim_1'; $cfg=fc77_tenant_cfg($env,$dbName); if($cfg['username']==='')throw new RuntimeException('Tenant DB username missing.');
$pdo=new PDO('mysql:host='.$cfg['host'].';port='.$cfg['port'].';dbname='.$dbName.';charset=utf8mb4',$cfg['username'],$cfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
if((string)$pdo->query('SELECT DATABASE()')->fetchColumn()!==$dbName)throw new RuntimeException('Unexpected tenant database.');
$pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (id INT UNSIGNED NOT NULL AUTO_INCREMENT,migration VARCHAR(255) NOT NULL,checksum VARCHAR(64) NOT NULL,executed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(id),UNIQUE KEY uk_local_migration(migration)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$lockName='planex_finance_closing_077_'.substr(hash('sha256',$dbName),0,24); $lock=$pdo->prepare('SELECT GET_LOCK(?,10)'); $lock->execute([$lockName]); if((int)$lock->fetchColumn()!==1)throw new RuntimeException('Cannot acquire migration lock.');
$state='';
try {
  $stmt=$pdo->prepare('SELECT checksum FROM schema_migrations WHERE migration=? LIMIT 1'); $stmt->execute([$name]); $stored=$stmt->fetchColumn();
  if($stored!==false){ if((string)$stored!==$checksum)throw new RuntimeException('Migration 077 checksum mismatch.'); $state='existing'; }
  else {
    $newer=$pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE migration REGEXP '^[0-9]{3}_' AND CAST(LEFT(migration,3) AS UNSIGNED)>77"); if((int)$newer->fetchColumn()!==0)throw new RuntimeException('Newer migration exists while 077 absent.');
    $pdo->exec($sql);
    $ins=$pdo->prepare('INSERT INTO schema_migrations(migration,checksum) VALUES(?,?)'); $ins->execute([$name,$checksum]); $state='applied';
  }
  foreach(['finance_closing_counterparties','finance_closing_document_links'] as $table){ $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?"); $q->execute([$table]); if((int)$q->fetchColumn()!==1)throw new RuntimeException('Missing table '.$table); }
  $fk=(int)$pdo->query("SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND CONSTRAINT_NAME IN ('fk_fcc_bank_transaction','fk_fcdl_bank_transaction','fk_fcdl_document')")->fetchColumn(); if($fk!==3)throw new RuntimeException('Migration 077 FK shape invalid.');
  printf("FINANCE_CLOSING_077_COMPANY_OK id=25 db=%s state=%s\n",$dbName,$state);
} finally { $release=$pdo->prepare('SELECT RELEASE_LOCK(?)'); $release->execute([$lockName]); }
printf("FINANCE_CLOSING_077_OK applied=%d existing=%d companies=1 checksum=%s\n",$state==='applied'?1:0,$state==='existing'?1:0,$checksum);
