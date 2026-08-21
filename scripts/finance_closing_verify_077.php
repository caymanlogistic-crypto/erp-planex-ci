<?php
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'cgi-fcgi') { fwrite(STDERR, "CLI/CGI only.\n"); exit(1); }
function fcv77_env_file(string $path): array { if (!is_file($path)) throw new RuntimeException('Missing .env file.'); $rows=file($path,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES); $out=[]; foreach($rows as $row){$row=trim($row); if($row===''||$row[0]==='#')continue; $pos=strpos($row,'='); if($pos===false)continue; $key=trim(substr($row,0,$pos)); $value=trim(substr($row,$pos+1)); if(strlen($value)>=2){$a=$value[0];$b=$value[strlen($value)-1];if(($a==='"'&&$b==='"')||($a==="'"&&$b==="'"))$value=substr($value,1,-1);} $out[$key]=$value;} return $out; }
function fcv77_tenant_cfg(array $env,string $dbName): array { $cfg=['host'=>$env['DB_HOST']??'127.0.0.1','port'=>$env['DB_PORT']??'3306','username'=>$env['DB_USERNAME']??'','password'=>$env['DB_PASSWORD']??'']; if(isset($env['COMPANY_DB_POOL_JSON'])&&trim($env['COMPANY_DB_POOL_JSON'])!==''){ $pool=json_decode($env['COMPANY_DB_POOL_JSON'],true); if(is_array($pool)){ foreach($pool as $entry){ if(!is_array($entry))continue; $db=$entry['database']??($entry['db']??''); if($db!==$dbName)continue; foreach(['host','port','username','password'] as $k){ if(isset($entry[$k])&&$entry[$k]!=='')$cfg[$k]=$entry[$k]; } break; } } } return $cfg; }
function fcv77_scalar(PDO $pdo,string $sql): int { $v=$pdo->query($sql)->fetchColumn(); return (int)($v===false?0:$v); }
function fcv77_table(PDO $pdo,string $name): int { $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?"); $q->execute([$name]); return (int)$q->fetchColumn(); }
$root=rtrim((string)(getenv('PLANEX_ROOT')?:($argv[1]??'')),'/'); if($root===''||!is_file($root.'/.env'))throw new RuntimeException('PLANEX_ROOT is invalid.');
$env=fcv77_env_file($root.'/.env'); $dbName='spugovxsim_1'; $cfg=fcv77_tenant_cfg($env,$dbName); if($cfg['username']==='')throw new RuntimeException('Tenant DB username missing.');
$pdo=new PDO('mysql:host='.$cfg['host'].';port='.$cfg['port'].';dbname='.$dbName.';charset=utf8mb4',$cfg['username'],$cfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
if((string)$pdo->query('SELECT DATABASE()')->fetchColumn()!==$dbName)throw new RuntimeException('Unexpected tenant database.');
$pdo->exec('SET TRANSACTION READ ONLY'); $pdo->beginTransaction();
try {
  $migrationTable=fcv77_table($pdo,'schema_migrations');
  $snapshot=[
    'company_id'=>25,
    'tenant_db'=>$dbName,
    'bank_transactions'=>fcv77_scalar($pdo,'SELECT COUNT(*) FROM bank_transactions'),
    'documents'=>fcv77_scalar($pdo,'SELECT COUNT(*) FROM documents'),
    'finance_employee_movements'=>fcv77_scalar($pdo,'SELECT COUNT(*) FROM finance_employee_movements'),
    'closing_counterparties_table'=>fcv77_table($pdo,'finance_closing_counterparties'),
    'closing_document_links_table'=>fcv77_table($pdo,'finance_closing_document_links'),
    'closing_counterparties_rows'=>fcv77_table($pdo,'finance_closing_counterparties')?fcv77_scalar($pdo,'SELECT COUNT(*) FROM finance_closing_counterparties'):0,
    'closing_document_links_rows'=>fcv77_table($pdo,'finance_closing_document_links')?fcv77_scalar($pdo,'SELECT COUNT(*) FROM finance_closing_document_links'):0,
    'migration_077_applied'=>$migrationTable?fcv77_scalar($pdo,"SELECT COUNT(*) FROM schema_migrations WHERE migration='077_finance_closing_documents.sql'"):0,
  ];
  $json=json_encode($snapshot,JSON_UNESCAPED_SLASHES); if($json===false)throw new RuntimeException('JSON encode failed.');
  echo 'FINANCE_CLOSING_077_SNAPSHOT='.$json.PHP_EOL;
  echo 'FINANCE_CLOSING_077_FINGERPRINT='.hash('sha256',$json).PHP_EOL;
  echo "FINANCE_CLOSING_077_READ_ONLY_OK\n";
  $pdo->rollBack();
} catch (Throwable $e) { if($pdo->inTransaction())$pdo->rollBack(); throw $e; }
