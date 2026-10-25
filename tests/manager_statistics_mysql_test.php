<?php
declare(strict_types=1);

namespace App\Service {
    final class AccessControlService {
        public static function effectiveDataScope(string $entityType): string { return 'none'; }
    }
    final class RegulatedDataScopeService {
        public static function condition(string $type, string $id, string $creator=''): array { return ['sql'=>'1=0','params'=>[]]; }
    }
}

namespace {
use App\Service\LocalMigrationService;
use App\Service\WasteTripStartStatisticsService as Statistics;

$root = dirname(__DIR__);
$appConfig=require $root.'/bootstrap/app.php';
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
        INSERT INTO feo_requests(id,zayavka_id,mass_netto) VALUES(1,'STAT-1',1.5),(2,'STAT-2',2.5),(3,'STAT-3',3.0),(4,'STAT-4',4.0),(5,'STAT-5',2.0),(6,'STAT-6',3.0),(7,'STAT-7',1.0),(8,'STAT-8',4.0);
        INSERT INTO waste_trips(id,business_id,client_id,responsible_user_id,destination_type,status,actual_start_date,created_by_membership_id,legacy_route_type,legacy_source_warehouse_id) VALUES
        (1,501,1,1,'warehouse','completed','2026-09-02',101,'generator_to_warehouse',NULL),
        (2,502,1,1,'warehouse','in_progress','2026-09-08',101,NULL,NULL),
        (3,503,1,2,'warehouse','cancelled','2026-09-15',102,NULL,NULL),
        (4,504,1,2,'warehouse','searching_executor',NULL,102,NULL,NULL),
        (5,505,1,4,'warehouse','completed','2026-09-16',104,NULL,NULL),
        (6,506,1,1,'utilizer','completed','2026-09-17',101,'warehouse_to_utilizer',11),
        (7,507,1,1,'warehouse','completed','2026-09-18',101,'warehouse_to_warehouse',11),
        (8,508,1,2,'utilizer','completed','2026-09-19',102,'mixed_to_utilizer',11),
        (9,509,1,2,'utilizer','completed','2026-09-20',102,NULL,11);
        INSERT INTO waste_trip_requests(waste_trip_id,feo_request_id,snapshot_mass_kg,position_no) VALUES
        (1,1,1500,1),(1,2,2500,2),(2,3,3000,1),(3,2,2500,1),(5,4,4000,1),
        (6,5,2000,1),(7,6,3000,1),(8,7,1000,1),(9,8,4000,1);");

    foreach ([1,2,3,4,5,6,7,8,9] as $tripId) Statistics::synchronizeTrip($pdo, $tripId);
    check((int)$pdo->query('SELECT COUNT(*) FROM waste_trip_start_statistics')->fetchColumn()===8, 'only started trips captured');

    $pdo->exec("UPDATE waste_trips SET responsible_user_id=2 WHERE id=1; DELETE FROM waste_trip_requests WHERE waste_trip_id=1 AND feo_request_id=2");
    Statistics::synchronizeTrip($pdo, 1);
    $snapshot=$pdo->query('SELECT responsible_membership_id,responsible_name_snapshot,request_count,total_mass_kg FROM waste_trip_start_statistics WHERE waste_trip_id=1')->fetch();
    check((int)$snapshot['responsible_membership_id']===101 && $snapshot['responsible_name_snapshot']==='Анна Белова', 'first manager attribution is immutable');
    check((int)$snapshot['request_count']===1 && (float)$snapshot['total_mass_kg']===1500.0, 'composition corrections update result');
    $pdo->exec("UPDATE users SET full_name='Анна Новая' WHERE id=1; UPDATE waste_trip_start_statistics SET responsible_name_snapshot='Анна Старая' WHERE waste_trip_id=2");

    $filters=Statistics::normalizeFilters(['mode'=>'week','metric'=>'trips'],new \DateTimeImmutable('2026-09-23',new \DateTimeZone('Europe/Moscow')));
    $dashboard=Statistics::dashboard($pdo,$filters,[
        ['membership_id'=>101,'full_name'=>'Анна Новая'],
        ['membership_id'=>102,'full_name'=>'Борис Серов'],
        ['membership_id'=>103,'full_name'=>'Вера Тихая'],
    ]);
    check($dashboard['totals']['trips']===3, 'granted report includes company Logist trips even when trip data scope is none');
    check($dashboard['totals']['requests']===3 && (float)$dashboard['totals']['weight']===7000.0, 'company requests and weight totals');
    check($dashboard['totals']['completed']===1 && $dashboard['totals']['cancelled']===1, 'current statuses retained');
    check(count($dashboard['managers'])===3, 'active employees include zero-result rows');
    check($dashboard['managers'][0]['name']==='Анна Новая' && $dashboard['managers'][0]['trips']===2, 'manager rows aggregate by identity and show the current employee name');
    $zero=array_values(array_filter($dashboard['managers'],static fn(array $row):bool=>$row['name']==='Вера Тихая'))[0]??null;
    check(is_array($zero)&&$zero['trips']===0, 'zero-result employee visible');
    check(count($dashboard['trend'])===8 && count($dashboard['details'])===3, 'eight-week trend and drill-down');
    check(count($dashboard['period_rows'])===8 && count($dashboard['manager_trends'])===3, 'eight exact periods and per-logist dynamics');
    check($filters['include_warehouse_loads']===false, 'warehouse loading default is No');

    $withWarehouse=Statistics::normalizeFilters(['mode'=>'week','metric'=>'trips','warehouse'=>'1'],new \DateTimeImmutable('2026-09-23',new \DateTimeZone('Europe/Moscow')));
    $all=Statistics::dashboard($pdo,$withWarehouse,[
        ['membership_id'=>101,'full_name'=>'Анна Новая'],
        ['membership_id'=>102,'full_name'=>'Борис Серов'],
        ['membership_id'=>103,'full_name'=>'Вера Тихая'],
    ]);
    check($all['totals']['trips']===7 && $all['totals']['requests']===7 && (float)$all['totals']['weight']===17000.0, 'Yes includes known warehouse loading routes and source warehouse');
    check(count($all['details'])===7 && count($dashboard['details'])===3, 'details use the same warehouse selection');
    check(array_sum(array_column($all['trend'],'trips'))===7 && array_sum(array_column($dashboard['trend'],'trips'))===3, 'chart and periods use the same warehouse selection');

    $personalFilters=Statistics::normalizePersonalFilters(
        ['mode'=>'week','manager_ids'=>[101],'from'=>'2020-01-01'],
        new \DateTimeImmutable('2026-10-25',new \DateTimeZone('Europe/Moscow'))
    );
    check($personalFilters['from']==='2026-09-14' && $personalFilters['to']==='2026-10-25'
        && $personalFilters['manager_ids']===[] && !$personalFilters['include_warehouse_loads'],
        'personal window starts at week 38; query cannot select another logist');
    $personalEligible=[
        ['membership_id'=>101,'full_name'=>'Анна Новая'],
        ['membership_id'=>102,'full_name'=>'Борис Серов'],
        ['membership_id'=>103,'full_name'=>'Вера Тихая'],
    ];
    $personal=Statistics::personalDashboard($pdo,$personalFilters,$personalEligible,102);
    check($personal['totals']['trips']===1 && $personal['managers'][0]['trips']===1,
        'company and signed-in logist totals use the same warehouse filter');
    check(count($personal['trend'])===6 && count($personal['manager_trends'])===1,
        'personal report shows weeks 38 through 43 and exactly one line');
    check($personal['manager_trends'][0]['membership_id']===102
        && $personal['manager_trends'][0]['name']==='Мои показатели',
        'only server-selected logist identity reaches the chart');
    check($personal['details']===[]
        && array_keys($personal['period_rows'][0]['manager_values'])===[102]
        && array_column($personal['managers'],'membership_id')===[102],
        'other logist names and rows are absent from the personal report payload');
    $personalFilters['include_warehouse_loads']=true;
    $personalWithWarehouse=Statistics::personalDashboard($pdo,$personalFilters,$personalEligible,102);
    check($personalWithWarehouse['totals']['trips']===5
        && $personalWithWarehouse['managers'][0]['trips']===3,
        'warehouse Yes changes company and own aggregates independently');

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
    $year=Statistics::normalizeFilters(['mode'=>'year','year'=>'2025','group'=>'week'],new \DateTimeImmutable('2026-09-23',new \DateTimeZone('Europe/Moscow')));
    check($year['from']==='2025-10-01'&&$year['to']==='2026-09-23'&&$year['group']==='month'&&$year['previous_from']==='2024-10-01'&&$year['previous_to']==='2025-09-23','rolling twelve-month boundaries ignore removed selectors');
    $week=Statistics::normalizeFilters(['mode'=>'week','week'=>'2026-W01'],new \DateTimeImmutable('2026-09-23',new \DateTimeZone('Europe/Moscow')));
    check($week['from']==='2026-08-03'&&$week['to']==='2026-09-23'&&$week['group']==='week'&&$week['previous_from']==='2026-06-08'&&$week['previous_to']==='2026-07-29','current and seven previous weeks');
    $month=Statistics::normalizeFilters(['mode'=>'month','month'=>'2026-03'],new \DateTimeImmutable('2026-09-23',new \DateTimeZone('Europe/Moscow')));
    check($month['from']==='2026-06-01'&&$month['to']==='2026-09-23'&&$month['group']==='month'&&$month['previous_from']==='2026-02-01'&&$month['previous_to']==='2026-05-23','current and three previous months');
    $removed=Statistics::normalizeFilters(['mode'=>'custom','from'=>'2020-01-01','to'=>'2030-01-01','manager_ids'=>[102],'compare'=>'1'],new \DateTimeImmutable('2026-09-23',new \DateTimeZone('Europe/Moscow')));
    check($removed['mode']==='week'&&$removed['from']==='2026-08-03'&&$removed['manager_ids']===[]&&$removed['compare']===false,'removed settings cannot change the fixed report');

    // Full route/controller/view/layout startup on the same disposable tenant.
    $pdo->exec("CREATE TEMPORARY TABLE companies(id INT PRIMARY KEY,name VARCHAR(255),status VARCHAR(20),db_identifier VARCHAR(255));");
    $companyRow=$pdo->prepare("INSERT INTO companies VALUES(25,'ООО Тест','active',?)");
    $companyRow->execute([$database]);
    $pdo->exec('UPDATE company_memberships SET role_id=1 WHERE id=102');
    require_once $root.'/app/Support/http_runtime.php';
    $appConfig['database']=['host'=>$host,'port'=>$port,'database'=>$database,'username'=>$user,'password'=>$pass,'charset'=>'utf8mb4'];
    $centralDb=new class($appConfig['database'],$pdo) extends \App\Core\Database {
        public function __construct(array $config,private \PDO $fixturePdo) { parent::__construct($config); }
        public function connection(): \PDO { return $this->fixturePdo; }
    };
    $_SERVER['REQUEST_METHOD']='GET';
    $_SERVER['REQUEST_URI']='/company/statistics/work';
    $_SERVER['SCRIPT_NAME']='/index.php';
    $_SESSION=['company_id'=>25,'user_id'=>1,'membership_id'=>101,'role_code'=>'logist',
        'full_name'=>'Анна Новая','auth_type'=>'company_user'];
    $_GET=['mode'=>'week','metric'=>'trips','warehouse'=>'1','manager_ids'=>[102]];
    $registry=new \App\Security\PageRegistry();
    $access=\App\Security\EffectiveAccess::member(101,25,['company.statistics.work'=>true],[],'logist');
    \App\Security\AccessRuntime::setCurrent($access);
    $router=new \App\Http\Router($registry);
    $config=$appConfig;
    $db=$centralDb;
    require $root.'/app/Http/Routes/company_statistics.php';
    $gate=new \ReflectionProperty($router,'effectiveAccess');
    $gate->setValue($router,$access);
    ob_start();
    $router->dispatch('GET','/company/statistics/work');
    $html=(string)ob_get_clean();
    check(http_response_code()!==403 && str_contains($html,'Статистика работы')
        && str_contains($html,'Нед. 38 · 14.09')
        && str_contains($html,'Мои показатели')
        && str_contains($html,'Компания')
        && str_contains($html,'class="nav-label">Статистика работы</span>'),
        'real route/controller/service/view/layout renders personal report from week 38');
    check(!str_contains($html,'Борис Серов') && !str_contains($html,'Вера Тихая'),
        'real personal HTML never exposes another logist name');
    $gate->setValue($router,\App\Security\EffectiveAccess::member(101,25,[],[],'logist'));
    http_response_code(200);
    ob_start();
    $router->dispatch('GET','/company/statistics/work');
    $denied=(string)ob_get_clean();
    check(http_response_code()===403 && !str_contains($denied,'Статистика работы'),
        'real route denies access without the dedicated page grant');
    \App\Security\AccessRuntime::clear();
    echo "MANAGER_STATISTICS_MYSQL=PASS\n";
} finally {
    if($fixed!=='')cleanCompanyPoolDatabase($pdo);else $admin->exec("DROP DATABASE `$database`");
}
}
