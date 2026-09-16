<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'cgi-fcgi') throw new RuntimeException('CLI/CGI only.');
if (getenv('PLANEX_CONFIRM_WASTE_138') !== 'APPLY_WASTE_TRIPS_138_AND_TRANSFER_AMOUNTS') throw new RuntimeException('Explicit migration and amount-transfer authorization required.');
$root = rtrim((string)getenv('PLANEX_ROOT'), '/');
$tooling = rtrim((string)getenv('PLANEX_WASTE_138_TOOLING'), '/');
$sqlPath = (string)getenv('PLANEX_TENANT_MIGRATION_PATH');
if (!is_file($root.'/.env') || !is_file($root.'/bootstrap/app.php') || !is_file($sqlPath) || !is_file($tooling.'/WasteTripFinancialTransitionService.php')) throw new RuntimeException('Invalid migration paths');
$config = require $root.'/bootstrap/app.php';
require_once $root.'/app/Support/crypto_helper.php';
require_once $root.'/app/Support/company_database.php';
spl_autoload_register(static function(string $class) use($root,$tooling): void {
    if (!str_starts_with($class,'App\\')) return;
    $relative=str_replace('\\','/',substr($class,4)).'.php';
    $candidate=$tooling.'/'.basename($relative);
    if (str_starts_with($relative,'Service/') && is_file($candidate)) require_once $candidate;
    elseif (is_file($root.'/app/'.$relative)) require_once $root.'/app/'.$relative;
});
$connect=static fn(array $c):PDO=>new PDO('mysql:host='.$c['host'].';port='.($c['port']??3306).';dbname='.$c['database'].';charset=utf8mb4',$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$central=$connect($config['database']);
$companies=$central->query("SELECT * FROM companies WHERE status='active' AND db_identifier IS NOT NULL AND db_identifier<>'' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
if ($companies===[]) throw new RuntimeException('No active tenants');
$sql=file_get_contents($sqlPath);$checksum=hash('sha256',$sql);$migration='138_waste_trip_general_payments.sql';
foreach($companies as $company) {
    $pdo=$connect(companyDatabaseConfig($config,$company));
    $database=(string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    if($database!==(string)$company['db_identifier'])throw new RuntimeException('Tenant identity mismatch');
    $locks=['planex_migrate_'.substr(hash('sha256',$database),0,32),'erp_waste_trip_legacy_sync_'.substr(hash('sha256',$database),0,24)];$held=[];
    try {
        foreach($locks as $name){$q=$pdo->prepare('SELECT GET_LOCK(?,10)');$q->execute([$name]);if((int)$q->fetchColumn()!==1)throw new RuntimeException('Migration/synchronization lock unavailable');$held[]=$name;}
        $q=$pdo->prepare('SELECT checksum FROM schema_migrations WHERE migration=?');$q->execute(['137_waste_trip_synchronization.sql']);
        if($q->fetchColumn()===false)throw new RuntimeException('Migration 137 prerequisite missing');
        $q->execute([$migration]);$stored=$q->fetchColumn();
        if($stored!==false) { if(!hash_equals((string)$stored,$checksum))throw new RuntimeException('Migration 138 checksum mismatch'); }
        else {
            if((int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='waste_trip_general_payments'")->fetchColumn()>0)throw new RuntimeException('Unjournaled Waste Trips 138 schema');
            if((int)$pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE CAST(LEFT(migration,3) AS UNSIGNED)>138")->fetchColumn()>0)throw new RuntimeException('Newer tenant migration exists');
            $pdo->exec($sql);$pdo->prepare('INSERT INTO schema_migrations(migration,checksum) VALUES(?,?)')->execute([$migration,$checksum]);
        }
        $count=App\Service\WasteTripFinancialTransitionService::transition($pdo,['user_id'=>0,'role_code'=>'system']);
        printf("WASTE_138_COMPANY_OK id=%d transferred=%d\n",(int)$company['id'],$count);
    } finally {foreach(array_reverse($held) as $name)$pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$name]);}
}
printf("WASTE_138_OK tenants=%d checksum=%s\n",count($companies),$checksum);
