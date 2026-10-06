<?php
declare(strict_types=1);

use App\Service\FeoMapService;
use App\Service\FeoPlacementRouteNotificationService;
use App\Service\WasteTripService;

require_once dirname(__DIR__) . '/bootstrap/app.php';
require_once BASE_PATH . '/app/Support/company_database.php';
foreach (['FeoMapService','WasteTripService','FeoPlacementRouteNotificationService'] as $class) {
    require_once BASE_PATH . '/app/Service/' . $class . '.php';
}

$db = trim((string)getenv('PLANEX_TEST_FIXED_DB'));
$allow = array_filter(array_map('trim', explode(',', (string)getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST'))));
if ($db === '' || !in_array($db,$allow,true) || getenv('APP_ENV') !== 'test') {
    throw new RuntimeException('Guarded fixed test DB required.');
}
$pdo = new PDO(
    'mysql:host='.getenv('PLANEX_SCHEMA_DB_HOST').';port='.(getenv('PLANEX_SCHEMA_DB_PORT') ?: 3306).';dbname='.$db.';charset=utf8mb4',
    getenv('PLANEX_SCHEMA_DB_USER'), getenv('PLANEX_SCHEMA_DB_PASSWORD'),
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]
);
$ok = static function(bool $value,string $message): void { if(!$value) throw new RuntimeException($message); };
cleanCompanyPoolDatabase($pdo);
try {
    $pdo->exec("CREATE TABLE user_accounts(
        id BIGINT UNSIGNED PRIMARY KEY, full_name VARCHAR(255) NOT NULL, max_user_id VARCHAR(32) NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE company_memberships(
        id BIGINT UNSIGNED PRIMARY KEY, account_id BIGINT UNSIGNED NOT NULL, company_id INT UNSIGNED NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE users(
        id INT UNSIGNED PRIMARY KEY, membership_id BIGINT UNSIGNED NULL, full_name VARCHAR(255) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE feo_requests(
        id BIGINT UNSIGNED PRIMARY KEY, zayavka_id VARCHAR(64) NOT NULL UNIQUE,
        mass_netto DECIMAL(18,6) NULL, mno_d VARCHAR(64) NULL, mno_sh VARCHAR(64) NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE waste_trips(
        id BIGINT UNSIGNED PRIMARY KEY, business_id BIGINT UNSIGNED NOT NULL, comment VARCHAR(500) NULL,
        status VARCHAR(32) NOT NULL, responsible_user_id INT UNSIGNED NOT NULL, deleted_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE waste_trip_requests(
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, waste_trip_id BIGINT UNSIGNED NOT NULL,
        feo_request_id BIGINT UNSIGNED NOT NULL,is_active TINYINT(1) NOT NULL DEFAULT 1
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $statuses = [
        1=>WasteTripService::STATUS_PLANNED,
        2=>WasteTripService::STATUS_SEARCHING,
        3=>WasteTripService::STATUS_ASSIGNED,
        4=>WasteTripService::STATUS_IN_PROGRESS,
        5=>WasteTripService::STATUS_COMPLETED,
        6=>WasteTripService::STATUS_CANCELLED,
    ];
    $account = $pdo->prepare('INSERT INTO user_accounts(id,full_name,max_user_id) VALUES(?,?,?)');
    $membership = $pdo->prepare('INSERT INTO company_memberships(id,account_id,company_id) VALUES(?,?,7)');
    $user = $pdo->prepare('INSERT INTO users(id,membership_id,full_name) VALUES(?,?,?)');
    $trip = $pdo->prepare('INSERT INTO waste_trips(id,business_id,comment,status,responsible_user_id) VALUES(?,?,?,?,?)');
    $request = $pdo->prepare('INSERT INTO feo_requests(id,zayavka_id,mass_netto,mno_d,mno_sh) VALUES(?,?,?,?,?)');
    $link = $pdo->prepare('INSERT INTO waste_trip_requests(waste_trip_id,feo_request_id) VALUES(?,?)');

    foreach ($statuses as $n=>$status) {
        $account->execute([100+$n,'Менеджер '.$n,(string)(900000+$n)]);
        $membership->execute([200+$n,100+$n]);
        $user->execute([300+$n,200+$n,'Менеджер '.$n]);
        $trip->execute([$n,250+$n,'Рейс '.$n,$status,300+$n]);
        $lat = 50.0 + $n;
        $request->execute([400+$n,(string)(700000+$n),'1.000000',(string)$lat,'37']);
        $link->execute([$n,400+$n]);
        $request->execute([500+$n,(string)(800000+$n),'0.050000',(string)$lat,'37']);
    }
    $service = new FeoPlacementRouteNotificationService();
    $selected = array_map(static fn(int $n): string => (string)(800000+$n), array_keys($statuses));
    $intents = $service->intents($pdo,$pdo,7,'addition',$selected);
    $ok(count($intents)===4,'only four active trip states must match');
    $routeIds = array_column($intents,'route_id');
    sort($routeIds,SORT_NUMERIC);
    $ok($routeIds===[1,2,3,4],'planned/searching/assigned/in_progress activity contract');
    foreach ($intents as $intent) {
        $route = (int)$intent['route_id'];
        $ok($intent['kind']==='same_point','same point expected for route '.$route);
        $ok(str_contains($intent['text'],'max://user/'.(900000+$route)),'responsible MAX account link route '.$route);
        $ok(str_contains($intent['text'],'#'.(250+$route)),'business trip display id route '.$route);
        $ok(str_contains($intent['text'],'Рейс'), 'trip comment label route '.$route);
    }

    $excludedIds = array_map(static fn(int $n): string => (string)(700000+$n), array_keys($statuses));
    $excluded = $service->intents($pdo,$pdo,7,'exclusion',$excludedIds);
    $ok(count($excluded)===4,'closed/cancelled exclusions must not notify');
    foreach ($excluded as $intent) $ok($intent['kind']==='active_exclusion','exclusion intent type');
    $pdo->exec("UPDATE user_accounts SET max_user_id=NULL WHERE id=102");
    $missing = $service->intents($pdo,$pdo,7,'addition',['800002']);
    $ok(count($missing)===1 && str_contains($missing[0]['text'],'800002') && $missing[0]['error']===null && !str_contains($missing[0]['text'],'max://user/'), 'missing MAX ID full unaddressed message');

    $pdo->exec("UPDATE user_accounts SET max_user_id='900002' WHERE id=102");
    $pdo->exec("UPDATE waste_trips SET deleted_at=NOW() WHERE id=3");
    $deleted = $service->intents($pdo,$pdo,7,'addition',['800003']);
    $ok($deleted===[],'deleted active-status trip must be inactive');

    $request->execute([999,'899999','0.100000',NULL,'37']);
    $invalid = $service->intents($pdo,$pdo,7,'addition',['899999']);
    $ok($invalid===[],'invalid coordinates must be ignored without blocking');

    echo "FEO_PLACEMENT_ROUTE_NOTIFICATIONS_MYSQL=PASS\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    cleanCompanyPoolDatabase($pdo);
}
