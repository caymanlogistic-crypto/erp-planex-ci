<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'cgi-fcgi') { fwrite(STDERR, "CLI/CGI only.\n"); exit(1); }
$root = rtrim((string)(getenv('PLANEX_ROOT') ?: ($argv[1] ?? '')), '/');
if ($root === '' || !is_file($root . '/.env') || !is_file($root . '/bootstrap/app.php')) throw new RuntimeException('PLANEX_ROOT invalid');
$config = require $root . '/bootstrap/app.php';
require_once $root . '/app/Support/crypto_helper.php';
require_once $root . '/app/Support/company_database.php';
$centralCfg = $config['database'];
$central = new PDO('mysql:host='.$centralCfg['host'].';port='.($centralCfg['port']??3306).';dbname='.$centralCfg['database'].';charset=utf8mb4',$centralCfg['username'],$centralCfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$companies = $central->query("SELECT id,name,status,db_identifier,db_host,db_port,db_username,db_password FROM companies WHERE status='active' AND db_identifier IS NOT NULL AND db_identifier<>'' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
if ($companies === []) throw new RuntimeException('No active tenant databases found');
$snapshot = [];
foreach ($companies as $company) {
    $cfg = companyDatabaseConfig($config,$company);
    $pdo = new PDO('mysql:host='.$cfg['host'].';port='.($cfg['port']??3306).';dbname='.$cfg['database'].';charset=utf8mb4',$cfg['username'],$cfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $tableExists = static function(PDO $p,string $table): int { $q=$p->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$q->execute([$table]);return (int)$q->fetchColumn(); };
    $countIf = static function(PDO $p,string $table) use($tableExists): int { return $tableExists($p,$table) ? (int)$p->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn() : 0; };
    $migration = 0;
    if ($tableExists($pdo,'schema_migrations')) { $q=$pdo->prepare('SELECT COUNT(*) FROM schema_migrations WHERE migration=?');$q->execute(['122_create_feo_requests.sql']);$migration=(int)$q->fetchColumn(); }
    $snapshot[] = [
        'company_id'=>(int)$company['id'],'db'=>(string)$company['db_identifier'],
        'tables'=>$tableExists($pdo,'feo_requests')+$tableExists($pdo,'feo_imports')+$tableExists($pdo,'feo_import_changes'),
        'requests'=>$countIf($pdo,'feo_requests'),'imports'=>$countIf($pdo,'feo_imports'),'changes'=>$countIf($pdo,'feo_import_changes'),'migration_122'=>$migration,
    ];
}
echo 'FEO_122_SNAPSHOT=' . json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . PHP_EOL;
echo 'FEO_122_READ_ONLY_OK' . PHP_EOL;
