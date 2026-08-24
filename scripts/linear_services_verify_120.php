<?php
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'cgi-fcgi') { fwrite(STDERR, "CLI/CGI only.\n"); exit(1); }
function envv120(string $path): array { $out=[]; foreach (file($path, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) ?: [] as $row) { $row=trim($row); if ($row==='' || $row[0]==='#') continue; $p=strpos($row,'='); if ($p===false) continue; $k=trim(substr($row,0,$p)); $v=trim(substr($row,$p+1)); if (strlen($v)>=2 && (($v[0]==='"'&&$v[-1]==='"')||($v[0]==="'"&&$v[-1]==="'"))) $v=substr($v,1,-1); $out[$k]=$v; } return $out; }
function cfgv120(array $env, string $db): array { $c=['host'=>$env['DB_HOST']??'127.0.0.1','port'=>$env['DB_PORT']??'3306','username'=>$env['DB_USERNAME']??'','password'=>$env['DB_PASSWORD']??'']; $pool=json_decode($env['COMPANY_DB_POOL_JSON']??'',true); if (is_array($pool)) foreach ($pool as $x) { if (is_array($x) && ($x['database']??$x['db']??'')===$db) { foreach (['host','port','username','password'] as $k) if (!empty($x[$k])) $c[$k]=$x[$k]; break; } } return $c; }
$root=rtrim((string)(getenv('PLANEX_ROOT')?:($argv[1]??'')),'/'); if ($root==='' || !is_file($root.'/.env')) throw new RuntimeException('PLANEX_ROOT invalid');
$env=envv120($root.'/.env'); $db='spugovxsim_1'; $c=cfgv120($env,$db);
$pdo=new PDO('mysql:host='.$c['host'].';port='.$c['port'].';dbname='.$db.';charset=utf8mb4',$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
if ($pdo->query('SELECT DATABASE()')->fetchColumn()!==$db) throw new RuntimeException('Unexpected DB');
$tableExists=static function(PDO $pdo,string $table): int { $s=$pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?'); $s->execute([$table]); return (int)$s->fetchColumn(); };
$countIf=static function(PDO $pdo,string $table) use ($tableExists): int { return $tableExists($pdo,$table) ? (int)$pdo->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn() : 0; };
$migration=0; if ($tableExists($pdo,'schema_migrations')) { $s=$pdo->prepare('SELECT COUNT(*) FROM schema_migrations WHERE migration=?'); $s->execute(['120_create_linear_route_services.sql']); $migration=(int)$s->fetchColumn(); }
$snapshot=[
  'linear_routes'=>$countIf($pdo,'linear_routes'),
  'linear_route_payments'=>$countIf($pdo,'linear_route_payments'),
  'finance_obligations'=>$countIf($pdo,'finance_obligations'),
  'documents'=>$countIf($pdo,'documents'),
  'service_table'=>$tableExists($pdo,'linear_route_services'),
  'service_payment_table'=>$tableExists($pdo,'linear_route_service_payments'),
  'service_rows'=>$countIf($pdo,'linear_route_services'),
  'service_payment_rows'=>$countIf($pdo,'linear_route_service_payments'),
  'migration_120_applied'=>$migration,
];
if ($snapshot['service_table']) {
  $snapshot['service_columns']=(int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='linear_route_services'")->fetchColumn();
  $snapshot['service_indexes']=(int)$pdo->query("SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='linear_route_services'")->fetchColumn();
} else { $snapshot['service_columns']=0; $snapshot['service_indexes']=0; }
if ($snapshot['service_payment_table']) {
  $snapshot['payment_columns']=(int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='linear_route_service_payments'")->fetchColumn();
  $snapshot['payment_indexes']=(int)$pdo->query("SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='linear_route_service_payments'")->fetchColumn();
} else { $snapshot['payment_columns']=0; $snapshot['payment_indexes']=0; }
echo 'LINEAR_SERVICES_120_SNAPSHOT='.json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
echo 'LINEAR_SERVICES_120_READ_ONLY_OK'.PHP_EOL;
