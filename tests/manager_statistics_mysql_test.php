<?php
declare(strict_types=1);

namespace App\Service {
    final class AccessControlService {
        public static function effectiveDataScope(string $entityType): string { return 'all'; }
    }
    final class RegulatedDataScopeService {
        public static function condition(string $type, string $id, string $creator=''): array { return ['sql'=>'1=1','params'=>[]]; }
    }
}

namespace {
use App\Service\LocalMigrationService;
use App\Service\WasteTripStartStatisticsService as Statistics;

$root = dirname(__DIR__);
require $root.'/bootstrap/app.php';
require_once $root.'/app/Support/company_database.php';
spl_autoload_register(static function(string $class) use ($root): void {
    if (str_starts_with($class, 'App\\')) {
        $path = $root.'/app/'.str_replace('\\', '/', substr($class, 4)).'.php';
        if (is_file($path)) require_once $path;
    }
});
function check(bool $condition, string $message): void { if (!$condition) throw new \RuntimeException($message); }

$host=getenv('PLANEX_SCHEMA_DB_HOST')?:'127.0.0.1';$port=getenv('PLANEX_SCHEMA_DB_PORT')?:3306;
$user=getenv('PLANEX_SCHEMA_DB_USER')?:'planex_ci';$pass=getenv('PLANEX_SCHEMA_DB_PASSWORD')?:'planex_ci';
$fixed=(string)getenv('PLANEX_TEST_FIXED_DB');$database=$fixed?:'planex_manager_statistics_'.getmypid();
if($fixed!=='')check(in_array($fixed,explode(',',(string)getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST')),true),'Unapproved fixed DB');
$options=[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION,\PDO::ATTR_EMULATE_PREPARES=>false,\PDO::ATTR_DEFAULT_FETCH_MODE=>\PDO::FETCH_ASSOC];
$admin=new \PDO("mysql:host=$host;port=$port;charset=utf8mb4",$user,$pass,$options);
if($fixed==='')$admin->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo=new \PDO("mysql:host=$host;port=$port;dbname=$database;charset=utf8mb4",$user,$pass,$options);

try {
    if($fixed!=='')cleanCompanyPoolDatabase($pdo);
    LocalMigrationService::apply($pdo);
    check(Statistics::schemaReady($pdo), 'migration 141 applied');
    $pdo->exec("INSERT INTO users(id,membership_id,full_name,login,password_hash,role_code,status) VALUES
        (1,101,'Анна Белова','anna','hash','logist','active'),
        (2,102,'Борис Серов','boris','hash','logist','active'),
        (3,103,'Вера Тихая','vera','hash','logist','active');
        INSERT INTO clients(id,name,inn,status) VALUES(1,'Тестовый клиент','7700000000','active');
        INSERT INTO feo_requests(id,zayavka_id,mass_netto) VALUES(1,'STAT-1',1.5),(2,'STAT-2',2.5),(3,'STAT-3',3.0);
        INSERT INTO waste_trips(id,business_id,client_id,responsible_user_id,destination_type,status,actual_start_date,created_by_membership_id) VALUES
        (1,501,1,1,'warehouse','completed','2026-09-02',101),
        (2,502,1,1,'warehouse','in_progress','2026-09-08',101),
        (3,503,1,2,'warehouse','cancelled','2026-09-15',102),
        (4,504,1,2,'warehouse','searching_executor',NULL,102);
        INSERT INTO waste_trip_requests(waste_trip_id,feo_request_id,snapshot_mass_kg,position_no) VALUES
        (1,1,1500,1),(1,2,2500,2),(2,3,3000,1),(3,2,2500,1);");

    foreach ([1,2,3,4] as $tripId) Statistics::synchronizeTrip($pdo, $tripId);
    check((int)$pdo->query('SELECT COUNT(*) FROM waste_trip_start_statistics')->fetchColumn()===3, 'only started trips captured');

    $pdo->exec("UPDATE waste_trips SET responsible_user_id=2 WHERE id=1; DELETE FROM waste_trip_requests WHERE waste_trip_id=1 AND feo_request_id=2");
    Statistics::synchronizeTrip($pdo, 1);
    $snapshot=$pdo->query('SELECT responsible_membership_id,responsible_name_snapshot,request_count,total_mass_kg FROM waste_trip_start_statistics WHERE waste_trip_id=1')->fetch();
    check((int)$snapshot['responsible_membership_id']===101 && $snapshot['responsible_name_snapshot']==='Анна Белова', 'first manager attribution is immutable');
    check((int)$snapshot['request_count']===1 && (float)$snapshot['total_mass_kg']===1500.0, 'composition corrections update result');
    $pdo->exec("UPDATE users SET full_name='Анна Новая' WHERE id=1; UPDATE waste_trip_start_statistics SET responsible_name_snapshot='Анна Старая' WHERE waste_trip_id=2");

    $filters=Statistics::normalizeFilters(['mode'=>'custom','from'=>'2026-09-01','to'=>'2026-09-30','group'=>'week','metric'=>'trips','compare'=>'1'],new \DateTimeImmutable('2026-09-23',new \DateTimeZone('Europe/Moscow')));
    $dashboard=Statistics::dashboard($pdo,$filters,[
        ['membership_id'=>101,'full_name'=>'Анна Новая'],
        ['membership_id'=>102,'full_name'=>'Борис Серов'],
        ['membership_id'=>103,'full_name'=>'Вера Тихая'],
    ]);
    check($dashboard['totals']['trips']===3, 'company trip total');
    check($dashboard['totals']['requests']===3 && (float)$dashboard['totals']['weight']===7000.0, 'company requests and weight totals');
    check($dashboard['totals']['completed']===1 && $dashboard['totals']['cancelled']===1, 'current statuses retained');
    check(count($dashboard['managers'])===3, 'active employees include zero-result rows');
    check($dashboard['managers'][0]['name']==='Анна Новая' && $dashboard['managers'][0]['trips']===2, 'manager rows aggregate by identity and show the current employee name');
    $zero=array_values(array_filter($dashboard['managers'],static fn(array $row):bool=>$row['name']==='Вера Тихая'))[0]??null;
    check(is_array($zero)&&$zero['trips']===0, 'zero-result employee visible');
    check(count($dashboard['trend'])===5 && count($dashboard['details'])===3, 'weekly trend and drill-down');

    $filtered=$filters;$filtered['manager_ids']=[102];
    $one=Statistics::dashboard($pdo,$filtered,[
        ['membership_id'=>101,'full_name'=>'Анна Новая'],
        ['membership_id'=>102,'full_name'=>'Борис Серов'],
        ['membership_id'=>103,'full_name'=>'Вера Тихая'],
    ]);
    check($one['totals']['trips']===1 && $one['totals']['requests']===1 && (float)$one['totals']['weight']===2500.0, 'manager filter');
    check(count($one['managers'])===1 && $one['managers'][0]['name']==='Борис Серов', 'manager filter hides unselected employees');
    echo "MANAGER_STATISTICS_MYSQL=PASS\n";
} finally {
    if($fixed!=='')cleanCompanyPoolDatabase($pdo);else $admin->exec("DROP DATABASE `$database`");
}
}
