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
    $pdo->exec("CREATE TEMPORARY TABLE user_accounts(id BIGINT PRIMARY KEY,full_name VARCHAR(100),login VARCHAR(100),status VARCHAR(20));
        CREATE TEMPORARY TABLE company_roles(id BIGINT PRIMARY KEY,company_id INT,name VARCHAR(120),normalized_name VARCHAR(120),is_system TINYINT,system_key VARCHAR(50));
        CREATE TEMPORARY TABLE company_memberships(id BIGINT PRIMARY KEY,account_id BIGINT,company_id INT,role_id BIGINT,status VARCHAR(20));
        INSERT INTO user_accounts VALUES(1,'Точный Логист','exact','active'),(2,'Руководитель','owner','active'),(3,'Логист плюс','senior','active'),(4,'Заблокированный','blocked','blocked'),(5,'Системный Логист','system','active'),(6,'Логист Лайт','lite','active');
        INSERT INTO company_roles VALUES
            (1,25,'Логист','логист',0,NULL),
            (2,25,'Руководитель','руководитель',1,'company_owner'),
            (3,25,'Логист+','логист+',1,'senior_logist'),
            (4,27,'Логист','логист',1,'logist'),
            (5,25,'Логист','логист',1,'logist'),
            (6,25,'Логист (lite)','логист (lite)',0,NULL);
        INSERT INTO company_memberships VALUES(101,1,25,1,'active'),(102,2,25,2,'active'),(103,3,25,3,'active'),(104,4,25,1,'active'),(105,5,25,5,'active'),(106,6,25,6,'active'),(201,1,27,4,'active');");
    $exactLogists=Statistics::eligibleLogists($pdo,25);
    check(
        array_column($exactLogists, 'membership_id') === [105,101],
        'central exact active Logist role accepts system and historical shapes only'
    );
    $pdo->exec("INSERT INTO users(id,membership_id,full_name,login,password_hash,role_code,status) VALUES
        (1,101,'Анна Белова','anna','hash','logist','active'),
        (2,102,'Борис Серов','boris','hash','logist','active'),
        (3,103,'Вера Тихая','vera','hash','logist','active'),
        (4,104,'Старший Логист','senior','hash','senior_logist','active');
        INSERT INTO clients(id,name,inn,status) VALUES(1,'Тестовый клиент','7700000000','active');
        INSERT INTO feo_requests(id,zayavka_id,mass_netto) VALUES(1,'STAT-1',1.5),(2,'STAT-2',2.5),(3,'STAT-3',3.0),(4,'STAT-4',4.0);
        INSERT INTO waste_trips(id,business_id,client_id,responsible_user_id,destination_type,status,actual_start_date,created_by_membership_id) VALUES
        (1,501,1,1,'warehouse','completed','2026-09-02',101),
        (2,502,1,1,'warehouse','in_progress','2026-09-08',101),
        (3,503,1,2,'warehouse','cancelled','2026-09-15',102),
        (4,504,1,2,'warehouse','searching_executor',NULL,102),
        (5,505,1,4,'warehouse','completed','2026-09-16',104);
        INSERT INTO waste_trip_requests(waste_trip_id,feo_request_id,snapshot_mass_kg,position_no) VALUES
        (1,1,1500,1),(1,2,2500,2),(2,3,3000,1),(3,2,2500,1),(5,4,4000,1);");

    foreach ([1,2,3,4,5] as $tripId) Statistics::synchronizeTrip($pdo, $tripId);
    check((int)$pdo->query('SELECT COUNT(*) FROM waste_trip_start_statistics')->fetchColumn()===4, 'only started trips captured');

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
    check($dashboard['totals']['trips']===3, 'company trip total excludes users outside exact Logist role');
    check($dashboard['totals']['requests']===3 && (float)$dashboard['totals']['weight']===7000.0, 'company requests and weight totals');
    check($dashboard['totals']['completed']===1 && $dashboard['totals']['cancelled']===1, 'current statuses retained');
    check(count($dashboard['managers'])===3, 'active employees include zero-result rows');
    check($dashboard['managers'][0]['name']==='Анна Новая' && $dashboard['managers'][0]['trips']===2, 'manager rows aggregate by identity and show the current employee name');
    $zero=array_values(array_filter($dashboard['managers'],static fn(array $row):bool=>$row['name']==='Вера Тихая'))[0]??null;
    check(is_array($zero)&&$zero['trips']===0, 'zero-result employee visible');
    check(count($dashboard['trend'])===5 && count($dashboard['details'])===3, 'weekly trend and drill-down');
    check(count($dashboard['period_rows'])===5 && count($dashboard['manager_trends'])===3, 'exact period matrix and per-logist dynamics');

    $filtered=$filters;$filtered['manager_ids']=[102];
    $one=Statistics::dashboard($pdo,$filtered,[
        ['membership_id'=>101,'full_name'=>'Анна Новая'],
        ['membership_id'=>102,'full_name'=>'Борис Серов'],
        ['membership_id'=>103,'full_name'=>'Вера Тихая'],
    ]);
    check($one['totals']['trips']===1 && $one['totals']['requests']===1 && (float)$one['totals']['weight']===2500.0, 'manager filter');
    check(count($one['managers'])===1 && $one['managers'][0]['name']==='Борис Серов', 'manager filter hides unselected employees');
    $forbidden=$filters;$forbidden['manager_ids']=[104];
    $none=Statistics::dashboard($pdo,$forbidden,[
        ['membership_id'=>101,'full_name'=>'Анна Новая'],
        ['membership_id'=>102,'full_name'=>'Борис Серов'],
        ['membership_id'=>103,'full_name'=>'Вера Тихая'],
    ]);
    check($none['totals']['trips']===0 && $none['details']===[], 'non-Logist membership cannot be forced through query filters');
    $year=Statistics::normalizeFilters(['mode'=>'year','year'=>'2025','group'=>'month'],new \DateTimeImmutable('2026-09-23',new \DateTimeZone('Europe/Moscow')));
    check($year['from']==='2025-01-01'&&$year['to']==='2025-12-31'&&$year['previous_from']==='2024-01-01','calendar year boundaries');
    $week=Statistics::normalizeFilters(['mode'=>'week','week'=>'2026-W01'],new \DateTimeImmutable('2026-09-23',new \DateTimeZone('Europe/Moscow')));
    check($week['from']==='2025-12-29'&&$week['to']==='2026-01-04'&&$week['group']==='day','ISO week boundaries');
    $month=Statistics::normalizeFilters(['mode'=>'month','month'=>'2026-03'],new \DateTimeImmutable('2026-09-23',new \DateTimeZone('Europe/Moscow')));
    check($month['from']==='2026-03-01'&&$month['to']==='2026-03-31'&&$month['previous_from']==='2026-02-01'&&$month['previous_to']==='2026-02-28','calendar month comparison');
    echo "MANAGER_STATISTICS_MYSQL=PASS\n";
} finally {
    if($fixed!=='')cleanCompanyPoolDatabase($pdo);else $admin->exec("DROP DATABASE `$database`");
}
}
