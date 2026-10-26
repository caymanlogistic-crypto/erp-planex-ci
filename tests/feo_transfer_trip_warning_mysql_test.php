<?php
declare(strict_types=1);

use App\Service\FeoTransferTripWarningService;
use App\Service\WasteTripService;

require_once dirname(__DIR__) . '/bootstrap/app.php';
require_once BASE_PATH . '/app/Support/company_database.php';
require_once BASE_PATH . '/app/Service/FeoTransferTripWarningService.php';

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
    $service = new FeoTransferTripWarningService();
    $ok($service->intents($pdo,['310500'])===[], 'tenant without trip module must have no warning');
    $pdo->exec("CREATE TABLE users(id INT PRIMARY KEY,full_name VARCHAR(255) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE feo_requests(id BIGINT PRIMARY KEY,zayavka_id VARCHAR(64) NOT NULL UNIQUE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE waste_trips(id BIGINT PRIMARY KEY,business_id BIGINT NOT NULL,comment VARCHAR(500),status VARCHAR(32) NOT NULL,responsible_user_id INT NOT NULL,deleted_at DATETIME NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE waste_trip_requests(id BIGINT AUTO_INCREMENT PRIMARY KEY,waste_trip_id BIGINT NOT NULL,feo_request_id BIGINT NOT NULL,removed_at DATETIME NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("INSERT INTO users(id,full_name) VALUES(1,'Х. Наталья'),(2,'В. Полина')");
    $trip = $pdo->prepare('INSERT INTO waste_trips(id,business_id,comment,status,responsible_user_id,deleted_at) VALUES(?,?,?,?,?,?)');
    foreach ([
        [1,376,'Дагестан *15 точ*',WasteTripService::STATUS_PLANNED,1,null],
        [2,377,'Ростов',WasteTripService::STATUS_SEARCHING,2,null],
        [3,378,'Вывоз',WasteTripService::STATUS_ASSIGNED,1,null],
        [4,379,'На маршруте',WasteTripService::STATUS_IN_PROGRESS,2,null],
        [5,380,'Груз сдан',WasteTripService::STATUS_COMPLETED,1,null],
        [6,381,'Отменён',WasteTripService::STATUS_CANCELLED,1,null],
        [7,382,'Удалён',WasteTripService::STATUS_PLANNED,1,'2026-10-25 10:00:00'],
    ] as $row) $trip->execute($row);
    $request = $pdo->prepare('INSERT INTO feo_requests(id,zayavka_id) VALUES(?,?)');
    $link = $pdo->prepare('INSERT INTO waste_trip_requests(waste_trip_id,feo_request_id,removed_at) VALUES(?,?,?)');
    for ($n=1;$n<=7;$n++) {
        $request->execute([$n,(string)(310499+$n)]);
        $link->execute([$n,$n,null]);
    }
    $link->execute([2,1,null]); // One request may appear in multiple trips.
    $link->execute([4,2,'2026-10-25 10:00:00']); // Removed links are ignored.

    $intents = $service->intents($pdo,['310500','310501','310502','310503','310504','310505','310506']);
    $ok(count($intents)===1,'ordinary warning must be one message');
    $text = $intents[0]['text'];
    $ok($intents[0]['format']==='markdown' && $intents[0]['part']===1,'prepared warning contract');
    foreach (['#376','#377','#378','#379','310500','310501','310502','310503'] as $needle) {
        $ok(str_contains($text,$needle),'missing active trip or request '.$needle);
    }
    foreach (['#380','#381','#382','310504','310505','310506'] as $needle) {
        $ok(!str_contains($text,$needle),'closed/deleted trip leaked '.$needle);
    }
    $ok(str_contains($text,'Дагестан \\*15 точ\\*'),'route markdown not escaped');
    $ok(str_contains($text,'Наталья Х\\.'),'manager short name missing');
    $ok(substr_count($text,'310500')===2,'cross-trip request must be listed under both trips');
    $weightIntents = $service->intents($pdo,['310500','310501','310504','310505','310506'],FeoTransferTripWarningService::WEIGHT_HEADER);
    $ok(count($weightIntents)===1 && $weightIntents[0]['text']==="ЗАЯВКИ С ИЗМЕНЁННЫМ ВЕСОМ\nнаходятся в следующих рейсах, будьте внимательны\n\n**\\#376 Дагестан \\*15 точ\\*** — Наталья Х\\.\nЗаявки: **310500**\n\n**\\#377 Ростов** — Полина В\\.\nЗаявки: **310500, 310501**",'Weight warning reuses transfer trip selection and exact compact message');

    // A large route exceeds one MAX body. Every request must survive deterministic splitting.
    $trip->execute([8,383,'Большой рейс',WasteTripService::STATUS_PLANNED,1,null]);
    for ($n=0;$n<480;$n++) {
        $id=1000+$n;
        $request->execute([$id,(string)(800000+$n)]);
        $link->execute([8,$id,null]);
    }
    $large = $service->intents($pdo,array_map(static fn(int $n): string => (string)(800000+$n),range(0,479)));
    $ok(count($large)>1,'oversize warning must be split');
    $all=implode("\n",array_column($large,'text'));
    for ($n=0;$n<480;$n++) $ok(substr_count($all,(string)(800000+$n))===1,'lost/duplicated request '.(800000+$n));
    foreach ($large as $i=>$intent) {
        $ok($intent['part']===$i+1 && mb_strlen($intent['text'],'UTF-8')<=4000,'invalid MAX part');
    }
    echo "FEO_TRANSFER_TRIP_WARNING_MYSQL=PASS\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    cleanCompanyPoolDatabase($pdo);
}
