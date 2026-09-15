<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'cgi-fcgi') { fwrite(STDERR, "CLI/CGI only.\n"); exit(1); }
$root = rtrim((string)(getenv('PLANEX_ROOT') ?: ($argv[1] ?? '')), '/');
if ($root === '' || !is_file($root . '/.env') || !is_file($root . '/bootstrap/app.php')) throw new RuntimeException('PLANEX_ROOT invalid');
$migrationPath = (string)(getenv('PLANEX_TENANT_MIGRATION_PATH') ?: ($argv[2] ?? ($root . '/database/migrations-local/137_waste_trip_synchronization.sql')));
if (!is_file($migrationPath)) throw new RuntimeException('Waste Trips migration 137 file missing');
$sql = file_get_contents($migrationPath);
if (!is_string($sql) || trim($sql) === '') throw new RuntimeException('Waste Trips migration 137 file empty');
$migrationName = '137_waste_trip_synchronization.sql';
$checksum = hash('sha256', $sql);
$config = require $root . '/bootstrap/app.php';
require_once $root . '/app/Support/crypto_helper.php';
require_once $root . '/app/Support/company_database.php';
$centralCfg = $config['database'];
$central = new PDO('mysql:host=' . $centralCfg['host'] . ';port=' . ($centralCfg['port'] ?? 3306) . ';dbname=' . $centralCfg['database'] . ';charset=utf8mb4', $centralCfg['username'], $centralCfg['password'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false]);
$companies=$central->query("SELECT * FROM companies WHERE status='active' AND db_identifier IS NOT NULL AND db_identifier<>'' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
if($companies===[])throw new RuntimeException('No active tenants');
foreach($companies as $company){
    $cfg=companyDatabaseConfig($config,$company);
    $pdo=new PDO('mysql:host='.$cfg['host'].';port='.($cfg['port']??3306).';dbname='.$cfg['database'].';charset=utf8mb4',$cfg['username'],$cfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    if((string)$pdo->query('SELECT DATABASE()')->fetchColumn()!==(string)$company['db_identifier'])throw new RuntimeException('Tenant identity mismatch');
    $lockName='planex_migrate_'.substr(hash('sha256',(string)$company['db_identifier']),0,32);
    $lock=$pdo->prepare('SELECT GET_LOCK(?,10)');$lock->execute([$lockName]);
    if((int)$lock->fetchColumn()!==1)throw new RuntimeException('Migration lock unavailable');
    try{
        $q=$pdo->prepare('SELECT checksum FROM schema_migrations WHERE migration=?');
        $q->execute(['136_create_max_event_notifications.sql']);
        if($q->fetchColumn()===false)throw new RuntimeException('Migration 136 prerequisite missing');
        $q->execute([$migrationName]);$stored=$q->fetchColumn();
        $column=$pdo->query("SELECT COLUMN_TYPE,IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='waste_trips' AND COLUMN_NAME='deleted_at'")->fetch(PDO::FETCH_ASSOC);
        if($stored!==false){
            if(!hash_equals((string)$stored,$checksum))throw new RuntimeException('Migration 137 checksum mismatch');
        }else{
            if($column!==false)throw new RuntimeException('Unjournaled Waste Trips 137 schema exists');
            if((int)$pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE migration REGEXP '^[0-9]{3}_' AND CAST(LEFT(migration,3) AS UNSIGNED)>137")->fetchColumn()>0)throw new RuntimeException('Newer tenant migration exists');
            $pdo->exec($sql);
            $pdo->prepare('INSERT INTO schema_migrations(migration,checksum) VALUES(?,?)')->execute([$migrationName,$checksum]);
        }
        $column=$pdo->query("SELECT COLUMN_TYPE,IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='waste_trips' AND COLUMN_NAME='deleted_at'")->fetch(PDO::FETCH_ASSOC);
        $status=$pdo->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='waste_trips' AND COLUMN_NAME='status'")->fetchColumn();
        $index=$pdo->query("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='waste_trips' AND INDEX_NAME='idx_waste_trips_deleted'")->fetchColumn();
        if(!$column||$column['IS_NULLABLE']!=='YES'||!str_contains((string)$status,"'planned_route'")||(int)$index!==2)throw new RuntimeException('Migration 137 schema incomplete');
        printf("WASTE_137_COMPANY_OK id=%d state=%s\n",(int)$company['id'],$stored===false?'applied':'existing');
    }finally{$pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);}
}
printf("WASTE_137_OK tenants=%d checksum=%s\n",count($companies),$checksum);
