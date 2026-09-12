<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'cgi-fcgi') { fwrite(STDERR, "CLI/CGI only.\n"); exit(1); }
$root = rtrim((string)(getenv('PLANEX_ROOT') ?: ($argv[1] ?? '')), '/');
if ($root === '' || !is_file($root . '/.env') || !is_file($root . '/bootstrap/app.php')) throw new RuntimeException('PLANEX_ROOT invalid');
$migrationPath = (string)(getenv('PLANEX_TENANT_MIGRATION_PATH') ?: ($argv[2] ?? ($root . '/database/migrations-local/134_create_waste_trip_customer_payments.sql')));
if (!is_file($migrationPath)) throw new RuntimeException('Waste Trips migration 134 file missing');
$sql = file_get_contents($migrationPath);
if (!is_string($sql) || trim($sql) === '') throw new RuntimeException('Waste Trips migration 134 file empty');
$migrationName = '134_create_waste_trip_customer_payments.sql';
$checksum = hash('sha256', $sql);
$config = require $root . '/bootstrap/app.php';
require_once $root . '/app/Support/crypto_helper.php';
require_once $root . '/app/Support/company_database.php';
$centralCfg = $config['database'];
$central = new PDO('mysql:host=' . $centralCfg['host'] . ';port=' . ($centralCfg['port'] ?? 3306) . ';dbname=' . $centralCfg['database'] . ';charset=utf8mb4', $centralCfg['username'], $centralCfg['password'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false]);
$tableExists = static function(PDO $pdo, string $table): bool { $s=$pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?'); $s->execute([$table]); return (int)$s->fetchColumn()===1; };
$columnExists = static function(PDO $pdo, string $column): bool { $s=$pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=\'waste_trip_customer_payments\' AND COLUMN_NAME=?'); $s->execute([$column]); return (int)$s->fetchColumn()===1; };
$indexExists = static function(PDO $pdo): bool { $s=$pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=\'waste_trip_customer_payments\' AND INDEX_NAME=\'idx_waste_trip_customer_payments_trip\''); $s->execute(); return (int)$s->fetchColumn()>0; };
$fkExists = static function(PDO $pdo): bool { $s=$pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='waste_trip_customer_payments' AND CONSTRAINT_NAME='fk_waste_trip_customer_payments_trip' AND CONSTRAINT_TYPE='FOREIGN KEY'"); $s->execute(); return (int)$s->fetchColumn()===1; };
$companies = $central->query("SELECT id,name,status,db_identifier,db_host,db_port,db_username,db_password FROM companies WHERE status='active' AND db_identifier IS NOT NULL AND db_identifier<>'' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
if ($companies === []) throw new RuntimeException('No active tenant databases found');
$applied=0;$existing=0;
foreach ($companies as $company) {
    $cfg=companyDatabaseConfig($config,$company);
    $pdo=new PDO('mysql:host='.$cfg['host'].';port='.($cfg['port']??3306).';dbname='.$cfg['database'].';charset=utf8mb4',$cfg['username'],$cfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    if ((string)$pdo->query('SELECT DATABASE()')->fetchColumn() !== (string)$company['db_identifier']) throw new RuntimeException('Unexpected tenant DB for company '.$company['id']);
    if (!$tableExists($pdo,'schema_migrations')) throw new RuntimeException('schema_migrations missing for company '.$company['id']);
    $prereq=$pdo->prepare('SELECT COUNT(*) FROM schema_migrations WHERE migration=?');$prereq->execute(['133_create_max_integration.sql']);
    if ((int)$prereq->fetchColumn()!==1) throw new RuntimeException('Migration 133 prerequisite missing for company '.$company['id']);
    if (!$tableExists($pdo,'waste_trips')) throw new RuntimeException('waste_trips prerequisite missing for company '.$company['id']);
    $lockName='planex_waste_134_'.substr(hash('sha256',(string)$company['db_identifier']),0,20);$lock=$pdo->prepare('SELECT GET_LOCK(?,10)');$lock->execute([$lockName]);
    if ((int)$lock->fetchColumn()!==1) throw new RuntimeException('Tenant lock failed for company '.$company['id']);
    try {
        $journal=$pdo->prepare('SELECT checksum FROM schema_migrations WHERE migration=?');$journal->execute([$migrationName]);$stored=$journal->fetchColumn();
        if ($stored!==false) {
            if (!hash_equals((string)$stored,$checksum)) throw new RuntimeException('Migration 134 checksum mismatch for company '.$company['id']);
            $state='existing';$existing++;
        } else {
            $newer=(int)$pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE migration REGEXP '^[0-9]{3}_' AND CAST(LEFT(migration,3) AS UNSIGNED)>134")->fetchColumn();
            if ($newer>0) throw new RuntimeException('Newer tenant migration exists for company '.$company['id']);
            if ($tableExists($pdo,'waste_trip_customer_payments')) throw new RuntimeException('Unjournaled Waste Trips 134 schema exists for company '.$company['id']);
            $pdo->exec($sql);
            $ins=$pdo->prepare('INSERT INTO schema_migrations(migration,checksum) VALUES(?,?)');$ins->execute([$migrationName,$checksum]);
            $state='applied';$applied++;
        }
        if (!$tableExists($pdo,'waste_trip_customer_payments')) throw new RuntimeException('Migration 134 table missing for company '.$company['id']);
        foreach (['waste_trip_id','amount','payment_type','payment_method','condition_type','days_count','specific_due_date','created_by_user_id','updated_by_user_id'] as $column) if (!$columnExists($pdo,$column)) throw new RuntimeException('Migration 134 column missing for company '.$company['id'].': '.$column);
        if (!$indexExists($pdo)) throw new RuntimeException('Migration 134 index missing for company '.$company['id']);
        if (!$fkExists($pdo)) throw new RuntimeException('Migration 134 FK missing for company '.$company['id']);
        $rows=(int)$pdo->query('SELECT COUNT(*) FROM waste_trip_customer_payments')->fetchColumn();
        printf("WASTE_134_COMPANY_OK id=%d db=%s state=%s payment_rows=%d\n",(int)$company['id'],$company['db_identifier'],$state,$rows);
    } finally { $r=$pdo->prepare('SELECT RELEASE_LOCK(?)');$r->execute([$lockName]); }
}
printf("WASTE_134_OK tenants=%d applied=%d existing=%d checksum=%s\n",count($companies),$applied,$existing,$checksum);
