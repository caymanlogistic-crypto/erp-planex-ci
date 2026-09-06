<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'cgi-fcgi') {
    fwrite(STDERR, "CLI/CGI only.\n");
    exit(1);
}

$mode=$argv[1]??'--apply';
if(!in_array($mode,['--apply','--rollback'],true)) throw new RuntimeException('Usage: --apply|--rollback');
$root=rtrim((string)(getenv('PLANEX_ROOT')?:($argv[2]??'')),'/');
if($root===''||!is_file($root.'/.env')||!is_file($root.'/bootstrap/app.php')) throw new RuntimeException('PLANEX_ROOT invalid');
$centralPath=(string)(getenv('PLANEX_CENTRAL_MIGRATION_PATH')?:'');
$tenantPath=(string)(getenv('PLANEX_TENANT_MIGRATION_PATH')?:'');
if($centralPath===''||$tenantPath===''||!is_file($centralPath)||!is_file($tenantPath)) throw new RuntimeException('Roles Access 132 migration files missing');
$centralSql=(string)file_get_contents($centralPath); $tenantSql=(string)file_get_contents($tenantPath);
if(trim($centralSql)===''||trim($tenantSql)==='') throw new RuntimeException('Roles Access 132 migration file empty');
$centralName='014_add_role_default_page.sql'; $tenantName='132_split_assignment_and_responsibility.sql';
$centralChecksum=hash('sha256',$centralSql); $tenantChecksum=hash('sha256',$tenantSql);
$config=require $root.'/bootstrap/app.php';
require_once $root.'/app/Support/crypto_helper.php';
require_once $root.'/app/Support/company_database.php';
require_once __DIR__.'/roles_access_013_schema_proof.php';
$centralCfg=$config['database'];
$central=new PDO('mysql:host='.$centralCfg['host'].';port='.($centralCfg['port']??3306).';dbname='.$centralCfg['database'].';charset=utf8mb4',$centralCfg['username'],$centralCfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);

$tableExists=static function(PDO $pdo,string $table):bool{$q=$pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$q->execute([$table]);return (int)$q->fetchColumn()===1;};
$columnExists=static function(PDO $pdo,string $table,string $column):bool{$q=$pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');$q->execute([$table,$column]);return (int)$q->fetchColumn()===1;};
$indexExists=static function(PDO $pdo,string $table,string $index):bool{$q=$pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?');$q->execute([$table,$index]);return (int)$q->fetchColumn()>0;};
$ensureJournal=static function(PDO $pdo):void{$pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,migration VARCHAR(255) NOT NULL UNIQUE,checksum VARCHAR(64) NOT NULL,executed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");};
$journal=static function(PDO $pdo,string $name) use($ensureJournal):string|false{$ensureJournal($pdo);$q=$pdo->prepare('SELECT checksum FROM schema_migrations WHERE migration=?');$q->execute([$name]);$v=$q->fetchColumn();return $v===false?false:(string)$v;};
$insertJournal=static function(PDO $pdo,string $name,string $checksum):void{$q=$pdo->prepare('INSERT INTO schema_migrations(migration,checksum) VALUES(?,?)');$q->execute([$name,$checksum]);};
$deleteJournal=static function(PDO $pdo,string $name):void{$q=$pdo->prepare('DELETE FROM schema_migrations WHERE migration=?');$q->execute([$name]);};
$lock=static function(PDO $pdo,string $name):void{$q=$pdo->prepare('SELECT GET_LOCK(?,15)');$q->execute([$name]);if((int)$q->fetchColumn()!==1)throw new RuntimeException('Migration lock failed: '.$name);};
$unlock=static function(PDO $pdo,string $name):void{$q=$pdo->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$name]);};

$companies=$central->query("SELECT id,name,status,db_identifier,db_host,db_port,db_username,db_password FROM companies WHERE status='active' AND db_identifier IS NOT NULL AND db_identifier<>'' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
if($companies===[]) throw new RuntimeException('No active tenant databases found');
$tenants=[];
foreach($companies as $company){$cfg=companyDatabaseConfig($config,$company);$pdo=new PDO('mysql:host='.$cfg['host'].';port='.($cfg['port']??3306).';dbname='.$cfg['database'].';charset=utf8mb4',$cfg['username'],$cfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);if((string)$pdo->query('SELECT DATABASE()')->fetchColumn()!==(string)$company['db_identifier'])throw new RuntimeException('Unexpected tenant DB for company '.$company['id']);$tenants[(int)$company['id']]=$pdo;}

$verifyCentral=static function(PDO $pdo) use($columnExists,$indexExists):void{
    if(!$columnExists($pdo,'company_roles','default_page_key')) throw new RuntimeException('Central 014 default_page_key missing');
    if(!$indexExists($pdo,'company_roles','idx_company_roles_default_page')) throw new RuntimeException('Central 014 index missing');
    $owners=(int)$pdo->query("SELECT COUNT(*) FROM company_roles WHERE system_key='company_owner' AND is_system=1")->fetchColumn();
    $defaults=(int)$pdo->query("SELECT COUNT(*) FROM company_roles WHERE system_key='company_owner' AND is_system=1 AND default_page_key='company.dashboard'")->fetchColumn();
    if($owners<1||$owners!==$defaults) throw new RuntimeException('Central 014 owner landing invariant failed');
};
$verifyTenant=static function(PDO $pdo) use($tableExists,$columnExists,$indexExists):void{
    if(!$columnExists($pdo,'entity_assignments','assignment_kind')) throw new RuntimeException('Tenant 132 assignment_kind missing');
    if($indexExists($pdo,'entity_assignments','uk_entity_assignment')) throw new RuntimeException('Tenant 132 old unique remains');
    if(!$indexExists($pdo,'entity_assignments','uk_entity_assignment_kind')||!$indexExists($pdo,'entity_assignments','idx_assignment_kind')) throw new RuntimeException('Tenant 132 indexes incomplete');
    if(!$tableExists($pdo,'entity_responsibilities')) throw new RuntimeException('Tenant 132 responsibilities table missing');
};

if($mode==='--apply'){
    $central013=$journal($central,'013_create_roles_access.sql');
    $central013Proof=rolesAccessCentral013SchemaProof($central);
    if(!$central013Proof['complete']) throw new RuntimeException('Central migration 013 factual schema prerequisite incomplete: '.implode(',',$central013Proof['missing']));
    $central013Provenance=$central013===false?'historical_shape':'journaled';
    printf("ROLES_ACCESS_013_PREREQUISITE=%s\n",$central013Provenance);
    $cStored=$journal($central,$centralName);
    if($cStored!==false && !hash_equals($cStored,$centralChecksum)) throw new RuntimeException('Central 014 checksum mismatch');
    if($cStored===false && ($columnExists($central,'company_roles','default_page_key')||$indexExists($central,'company_roles','idx_company_roles_default_page'))) throw new RuntimeException('Unjournaled partial central 014 schema');
    foreach($tenants as $cid=>$pdo){
        if($journal($pdo,'131_create_roles_access_projection.sql')===false) throw new RuntimeException("Company {$cid}: tenant migration 131 prerequisite missing");
        $stored=$journal($pdo,$tenantName);
        if($stored!==false && !hash_equals($stored,$tenantChecksum)) throw new RuntimeException("Company {$cid}: tenant 132 checksum mismatch");
        if($stored===false){
            if(!$tableExists($pdo,'entity_assignments')||!$indexExists($pdo,'entity_assignments','uk_entity_assignment')) throw new RuntimeException("Company {$cid}: pre-132 assignment schema missing");
            if($columnExists($pdo,'entity_assignments','assignment_kind')||$indexExists($pdo,'entity_assignments','uk_entity_assignment_kind')||$indexExists($pdo,'entity_assignments','idx_assignment_kind')||$tableExists($pdo,'entity_responsibilities')) throw new RuntimeException("Company {$cid}: unjournaled partial tenant 132 schema");
            $newer=(int)$pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE migration REGEXP '^[0-9]{3}_' AND CAST(LEFT(migration,3) AS UNSIGNED)>132")->fetchColumn();
            if($newer!==0) throw new RuntimeException("Company {$cid}: newer tenant migration exists while 132 absent");
        }
    }
    $centralLock='planex_roles_access_014';$lock($central,$centralLock);
    try{
        $state='existing';
        if($cStored===false){$central->exec($centralSql);$insertJournal($central,$centralName,$centralChecksum);$state='applied';}
        $verifyCentral($central);
        printf("ROLES_ACCESS_014_OK state=%s checksum=%s\n",$state,$centralChecksum);
    }finally{$unlock($central,$centralLock);}
    $applied=0;$existing=0;
    foreach($tenants as $cid=>$pdo){
        $name='planex_roles_access_132_'.substr(hash('sha256',(string)$cid),0,20);$lock($pdo,$name);
        try{
            $stored=$journal($pdo,$tenantName);$state='existing';
            if($stored===false){$pdo->exec($tenantSql);$insertJournal($pdo,$tenantName,$tenantChecksum);$state='applied';$applied++;}else{$existing++;}
            $verifyTenant($pdo);
            printf("ROLES_ACCESS_132_COMPANY_OK id=%d state=%s\n",$cid,$state);
        }finally{$unlock($pdo,$name);}
    }
    printf("ROLES_ACCESS_132_OK tenants=%d applied=%d existing=%d checksum=%s\n",count($tenants),$applied,$existing,$tenantChecksum);
    exit(0);
}

if((string)getenv('PLANEX_ROLES_132_ROLLBACK_CONFIRM')!=='ROLLBACK_ROLES_ACCESS_132') throw new RuntimeException('Rollback confirmation missing');
foreach($tenants as $cid=>$pdo){
    if($tableExists($pdo,'entity_responsibilities')){
        $n=(int)$pdo->query('SELECT COUNT(*) FROM entity_responsibilities')->fetchColumn();
        if($n!==0) throw new RuntimeException("Company {$cid}: rollback blocked because responsibilities exist");
    }
    if($columnExists($pdo,'entity_assignments','assignment_kind')){
        $n=(int)$pdo->query("SELECT COUNT(*) FROM entity_assignments WHERE assignment_kind<>'legacy_unclassified'")->fetchColumn();
        if($n!==0) throw new RuntimeException("Company {$cid}: rollback blocked because factual new provenance exists");
    }
}
if($columnExists($central,'company_roles','default_page_key')){
    $n=(int)$central->query("SELECT COUNT(*) FROM company_roles WHERE is_system=0 AND default_page_key IS NOT NULL")->fetchColumn();
    if($n!==0) throw new RuntimeException('Central rollback blocked because configurable role landing data exists');
}
foreach($tenants as $cid=>$pdo){
    $name='planex_roles_access_132_'.substr(hash('sha256',(string)$cid),0,20);$lock($pdo,$name);
    try{
        if($tableExists($pdo,'entity_responsibilities')) $pdo->exec('DROP TABLE entity_responsibilities');
        if($indexExists($pdo,'entity_assignments','idx_assignment_kind')) $pdo->exec('ALTER TABLE entity_assignments DROP INDEX idx_assignment_kind');
        if($indexExists($pdo,'entity_assignments','uk_entity_assignment_kind')) $pdo->exec('ALTER TABLE entity_assignments DROP INDEX uk_entity_assignment_kind');
        if($columnExists($pdo,'entity_assignments','assignment_kind')) $pdo->exec('ALTER TABLE entity_assignments DROP COLUMN assignment_kind');
        if(!$indexExists($pdo,'entity_assignments','uk_entity_assignment')) $pdo->exec('ALTER TABLE entity_assignments ADD UNIQUE KEY uk_entity_assignment(entity_type,entity_id,membership_id)');
        $deleteJournal($pdo,$tenantName);
        printf("ROLES_ACCESS_132_ROLLBACK_COMPANY_OK id=%d\n",$cid);
    }finally{$unlock($pdo,$name);}
}
$centralLock='planex_roles_access_014';$lock($central,$centralLock);
try{
    if($indexExists($central,'company_roles','idx_company_roles_default_page')) $central->exec('ALTER TABLE company_roles DROP INDEX idx_company_roles_default_page');
    if($columnExists($central,'company_roles','default_page_key')) $central->exec('ALTER TABLE company_roles DROP COLUMN default_page_key');
    $deleteJournal($central,$centralName);
}finally{$unlock($central,$centralLock);}
echo "ROLES_ACCESS_132_ROLLBACK_OK\n";
