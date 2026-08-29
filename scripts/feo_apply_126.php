<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'cgi-fcgi') { fwrite(STDERR, "CLI/CGI only.\n"); exit(1); }
$root = rtrim((string)(getenv('PLANEX_ROOT') ?: ($argv[1] ?? '')), '/');
if ($root === '' || !is_file($root.'/.env') || !is_file($root.'/bootstrap/app.php')) throw new RuntimeException('PLANEX_ROOT invalid');
$migrationPath = (string)(getenv('PLANEX_MIGRATION_PATH') ?: ($argv[2] ?? ($root.'/database/migrations-local/126_create_feo_utilizer_aliases.sql')));
if (!is_file($migrationPath)) throw new RuntimeException('Migration 126 missing');
$sql = file_get_contents($migrationPath);
if (!is_string($sql) || trim($sql) === '') throw new RuntimeException('Migration 126 empty');
$checksum = hash('sha256', $sql);
$name = '126_create_feo_utilizer_aliases.sql';

$config = require $root.'/bootstrap/app.php';
require_once $root.'/app/Support/crypto_helper.php';
require_once $root.'/app/Support/company_database.php';
$centralCfg = $config['database'];
$central = new PDO(
    'mysql:host='.$centralCfg['host'].';port='.($centralCfg['port']??3306).';dbname='.$centralCfg['database'].';charset=utf8mb4',
    $centralCfg['username'], $centralCfg['password'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]
);
$companies = $central->query("SELECT id,name,status,db_identifier,db_host,db_port,db_username,db_password FROM companies WHERE status='active' AND db_identifier IS NOT NULL AND db_identifier<>'' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
if ($companies === []) throw new RuntimeException('No active tenant databases found');

$tableExists = static function(PDO $p, string $table): bool {
    $q=$p->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $q->execute([$table]);
    return (int)$q->fetchColumn() === 1;
};
$columnCount = static function(PDO $p, string $table, array $cols): int {
    $marks=implode(',',array_fill(0,count($cols),'?'));
    $q=$p->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME IN ('.$marks.')');
    $q->execute(array_merge([$table],$cols));
    return (int)$q->fetchColumn();
};
$indexExists = static function(PDO $p, string $table, string $index): bool {
    $q=$p->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?');
    $q->execute([$table,$index]);
    return (int)$q->fetchColumn()>0;
};
$fkExists = static function(PDO $p): bool {
    $q=$p->prepare("SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='feo_utilizer_aliases' AND CONSTRAINT_NAME='fk_feo_utilizer_alias_utilizer' AND REFERENCED_TABLE_NAME='feo_utilizers' AND DELETE_RULE='CASCADE'");
    $q->execute();
    return (int)$q->fetchColumn()===1;
};

$applied=0;$existing=0;
foreach($companies as $company){
    $cfg=companyDatabaseConfig($config,$company);
    $pdo=new PDO(
        'mysql:host='.$cfg['host'].';port='.($cfg['port']??3306).';dbname='.$cfg['database'].';charset=utf8mb4',
        $cfg['username'],$cfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]
    );
    if((string)$pdo->query('SELECT DATABASE()')->fetchColumn() !== (string)$company['db_identifier']) throw new RuntimeException('Unexpected tenant DB for company '.$company['id']);
    if(!$tableExists($pdo,'schema_migrations')||!$tableExists($pdo,'feo_utilizers')||!$tableExists($pdo,'feo_utilizer_match_rules')||!$tableExists($pdo,'feo_request_utilizer_overrides')) throw new RuntimeException('Migration 125 schema prerequisite missing for company '.$company['id']);
    $q=$pdo->prepare('SELECT COUNT(*) FROM schema_migrations WHERE migration=?');
    $q->execute(['125_create_feo_map_utilizers.sql']);
    if((int)$q->fetchColumn()!==1) throw new RuntimeException('Migration 125 journal prerequisite missing for company '.$company['id']);

    $lockName='planex_feo_126_'.substr(hash('sha256',(string)$company['db_identifier']),0,20);
    $q=$pdo->prepare('SELECT GET_LOCK(?,10)');$q->execute([$lockName]);
    if((int)$q->fetchColumn()!==1) throw new RuntimeException('Lock failed for company '.$company['id']);
    $state='';
    try{
        $q=$pdo->prepare('SELECT checksum FROM schema_migrations WHERE migration=?');$q->execute([$name]);$stored=$q->fetchColumn();
        if($stored!==false){
            if(!hash_equals((string)$stored,$checksum)) throw new RuntimeException('Checksum mismatch for company '.$company['id']);
            $state='existing';$existing++;
        } else {
            $newer=(int)$pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE migration REGEXP '^[0-9]{3}_' AND CAST(LEFT(migration,3) AS UNSIGNED)>126")->fetchColumn();
            if($newer>0) throw new RuntimeException('Newer tenant migration exists for company '.$company['id']);
            if($tableExists($pdo,'feo_utilizer_aliases')) throw new RuntimeException('Unjournaled FEO utilizer alias schema exists for company '.$company['id']);
            $pdo->exec($sql);
            $q=$pdo->prepare('INSERT INTO schema_migrations(migration,checksum) VALUES(?,?)');$q->execute([$name,$checksum]);
            $state='applied';$applied++;
        }
        if(!$tableExists($pdo,'feo_utilizer_aliases')) throw new RuntimeException('FEO 126 alias table verification failed for company '.$company['id']);
        if($columnCount($pdo,'feo_utilizer_aliases',['id','utilizer_id','alias','normalized_alias','created_by_user_id','updated_by_user_id','created_at','updated_at'])!==8) throw new RuntimeException('FEO 126 alias columns verification failed for company '.$company['id']);
        if(!$indexExists($pdo,'feo_utilizer_aliases','uk_feo_utilizer_alias_normalized')||!$indexExists($pdo,'feo_utilizer_aliases','idx_feo_utilizer_alias_utilizer')) throw new RuntimeException('FEO 126 alias indexes missing for company '.$company['id']);
        if(!$fkExists($pdo)) throw new RuntimeException('FEO 126 alias FK verification failed for company '.$company['id']);
        $duplicates=(int)$pdo->query('SELECT COUNT(*) FROM (SELECT normalized_alias FROM feo_utilizer_aliases GROUP BY normalized_alias HAVING COUNT(*)>1) x')->fetchColumn();
        if($duplicates!==0) throw new RuntimeException('FEO 126 duplicate alias invariant failed for company '.$company['id']);
        printf("FEO_126_COMPANY_OK id=%d db=%s state=%s aliases=%d\n",(int)$company['id'],$company['db_identifier'],$state,(int)$pdo->query('SELECT COUNT(*) FROM feo_utilizer_aliases')->fetchColumn());
    } finally {
        $q=$pdo->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$lockName]);
    }
}
printf("FEO_126_OK tenants=%d applied=%d existing=%d checksum=%s\n",count($companies),$applied,$existing,$checksum);
