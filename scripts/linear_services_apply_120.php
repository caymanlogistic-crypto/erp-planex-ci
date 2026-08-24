<?php
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'cgi-fcgi') { fwrite(STDERR, "CLI/CGI only.\n"); exit(1); }
function env120(string $path): array { $out=[]; foreach (file($path, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) ?: [] as $row) { $row=trim($row); if ($row==='' || $row[0]==='#') continue; $p=strpos($row,'='); if ($p===false) continue; $k=trim(substr($row,0,$p)); $v=trim(substr($row,$p+1)); if (strlen($v)>=2 && (($v[0]==='"'&&$v[-1]==='"')||($v[0]==="'"&&$v[-1]==="'"))) $v=substr($v,1,-1); $out[$k]=$v; } return $out; }
function cfg120(array $env, string $db): array { $c=['host'=>$env['DB_HOST']??'127.0.0.1','port'=>$env['DB_PORT']??'3306','username'=>$env['DB_USERNAME']??'','password'=>$env['DB_PASSWORD']??'']; $pool=json_decode($env['COMPANY_DB_POOL_JSON']??'',true); if (is_array($pool)) foreach ($pool as $x) { if (is_array($x) && ($x['database']??$x['db']??'')===$db) { foreach (['host','port','username','password'] as $k) if (!empty($x[$k])) $c[$k]=$x[$k]; break; } } return $c; }
$root=rtrim((string)(getenv('PLANEX_ROOT')?:($argv[1]??'')),'/');
if ($root==='' || !is_file($root.'/.env')) throw new RuntimeException('PLANEX_ROOT invalid');
$path=(string)(getenv('PLANEX_MIGRATION_PATH')?:($argv[2]??''));
if ($path==='') $path=$root.'/database/migrations-local/120_create_linear_route_services.sql';
if (!is_file($path)) throw new RuntimeException('Migration 120 missing');
$name='120_create_linear_route_services.sql';
$sql=file_get_contents($path); if ($sql===false || trim($sql)==='') throw new RuntimeException('Migration empty');
$checksum=hash('sha256',$sql);
$env=env120($root.'/.env'); $db='spugovxsim_1'; $c=cfg120($env,$db);
$pdo=new PDO('mysql:host='.$c['host'].';port='.$c['port'].';dbname='.$db.';charset=utf8mb4',$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
if ($pdo->query('SELECT DATABASE()')->fetchColumn()!==$db) throw new RuntimeException('Unexpected DB');
$pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,migration VARCHAR(255) NOT NULL UNIQUE,checksum VARCHAR(64) NOT NULL,executed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$lock='planex_linear_services_120'; $q=$pdo->prepare('SELECT GET_LOCK(?,10)'); $q->execute([$lock]); if ((int)$q->fetchColumn()!==1) throw new RuntimeException('Lock failed');
$state='';
try {
    $q=$pdo->prepare('SELECT checksum FROM schema_migrations WHERE migration=?'); $q->execute([$name]); $stored=$q->fetchColumn();
    if ($stored!==false) { if ((string)$stored!==$checksum) throw new RuntimeException('Checksum mismatch'); $state='existing'; }
    else {
        $newer=(int)$pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE migration REGEXP '^[0-9]{3}_' AND CAST(LEFT(migration,3) AS UNSIGNED)>120")->fetchColumn();
        if ($newer) throw new RuntimeException('Newer migration exists');
        $pdo->exec($sql);
        $q=$pdo->prepare('INSERT INTO schema_migrations(migration,checksum) VALUES(?,?)'); $q->execute([$name,$checksum]);
        $state='applied';
    }
    $tables=(int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('linear_route_services','linear_route_service_payments')")->fetchColumn();
    $serviceCols=(int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='linear_route_services'")->fetchColumn();
    $paymentCols=(int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='linear_route_service_payments'")->fetchColumn();
    if ($tables!==2 || $serviceCols<10 || $paymentCols<20) throw new RuntimeException('120 schema verification failed');
    printf("LINEAR_SERVICES_120_COMPANY_OK id=25 db=%s state=%s\n",$db,$state);
} finally { $r=$pdo->prepare('SELECT RELEASE_LOCK(?)'); $r->execute([$lock]); }
printf("LINEAR_SERVICES_120_OK applied=%d existing=%d checksum=%s\n",$state==='applied'?1:0,$state==='existing'?1:0,$checksum);
