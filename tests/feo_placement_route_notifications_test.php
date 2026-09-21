<?php
declare(strict_types=1);

use App\Service\FeoMapService;
use App\Service\FeoPlacementRouteNotificationService;
use App\Service\WasteTripService;

require_once dirname(__DIR__) . '/bootstrap/app.php';
foreach (['FeoMapService','WasteTripService','FeoPlacementRouteNotificationService'] as $class) {
    require_once BASE_PATH . '/app/Service/' . $class . '.php';
}

$ok = static function(bool $value, string $message): void {
    if (!$value) throw new RuntimeException($message);
};
$service = new FeoPlacementRouteNotificationService();

foreach ([
    WasteTripService::STATUS_PLANNED,
    WasteTripService::STATUS_SEARCHING,
    WasteTripService::STATUS_ASSIGNED,
    WasteTripService::STATUS_IN_PROGRESS,
] as $status) $ok(FeoPlacementRouteNotificationService::isActiveStatus($status), 'active status rejected: '.$status);
foreach ([WasteTripService::STATUS_COMPLETED,WasteTripService::STATUS_CANCELLED] as $status) {
    $ok(!FeoPlacementRouteNotificationService::isActiveStatus($status), 'closed status accepted: '.$status);
}
$coordCases = [
    [null,null,'missing'], ['',37,'invalid'], ['abc',37,'invalid'], [91,37,'invalid'],
    [0,0,'invalid'], [55.75,37.61,'ok'],
];
foreach ($coordCases as [$lat,$lng,$status]) {
    $ok(FeoMapService::coordinates($lat,$lng)['status'] === $status, 'coordinate status '.$status);
}
$coords = FeoMapService::coordinates('55.7500000','37.6100000');
$ok(FeoMapService::coordinateKey($coords)==='55.7500000|37.6100000','same-point key');
$historical = FeoMapService::coordinates('62.03','129.73');
$ok(abs((float)$historical['lat']-62.03)<1e-9 && abs((float)$historical['lng']-129.73)<1e-9,'mno_d latitude / mno_sh longitude contract');

$delta = rad2deg(5000.0 / 6371000.0);
$exact = FeoPlacementRouteNotificationService::distanceMeters(0,30,$delta,30);
$under = FeoPlacementRouteNotificationService::distanceMeters(0,30,$delta*0.999,30);
$over = FeoPlacementRouteNotificationService::distanceMeters(0,30,$delta*1.001,30);
$ok(abs($exact-5000.0)<0.01,'exact 5km distance');
$ok($under < 5000.0 && $over > 5000.0,'distance boundary');
$ok(FeoPlacementRouteNotificationService::distanceMeters(55,37,55,37)===0.0,'zero distance');

$new = static fn(string $id,float $kg,float $lat,float $lng): array => [
    'zayavka_id'=>$id,'mass_kg'=>$kg,'coords'=>['status'=>'ok','lat'=>$lat,'lng'=>$lng],
];
$active = static function(int $route,string $id,float $lat,float $lng,string $max='123456789'): array {
    return [
        'route_id'=>$route,'business_id'=>200+$route,'comment'=>'Крым '.$route,
        'zayavka_id'=>$id,'mass_kg'=>100,'membership_id'=>$route,
        'responsible_name'=>'Иван Иванов',
        'coords'=>['status'=>'ok','lat'=>$lat,'lng'=>$lng],
        'account'=>['full_name'=>'Иван Иванов','max_user_id'=>$max],
    ];
};

$selected = [
    '300001'=>$new('300001',56,55,37),
    '300002'=>$new('300002',44,55.11,37),
];
$matches = [
    $active(1,'200001',55,37),
    $active(2,'200002',55.10,37),
    $active(2,'200003',55.109,37),
];
$intents = $service->additionIntents($selected,$matches);
$ok(count($intents)===2,'same-point priority + nearby grouping count');
$ok($intents[0]['kind']==='same_point' && $intents[0]['route_id']===1,'same-point first');
$ok($intents[1]['kind']==='nearby' && $intents[1]['route_id']===2,'nearby second');
$ok(!str_contains($intents[1]['text'],'300001'),'same-point request leaked to nearby');
$ok(str_contains($intents[0]['text'],'](max://user/123456789)'),'real MAX mention missing');
$ok(str_contains($intents[0]['text'],'300001') && str_contains($intents[0]['text'],'200001'),'same-point IDs missing');
$oneRoute = $service->additionIntents(
    ['310001'=>$new('310001',100,55.02,37),'310002'=>$new('310002',200,55.021,37)],
    [$active(3,'210001',55,37),$active(3,'210002',55.001,37)]
);
$ok(count($oneRoute)===1 && $oneRoute[0]['kind']==='nearby','multiple requests -> one route grouping');
$ok(str_contains($oneRoute[0]['text'],'310001') && str_contains($oneRoute[0]['text'],'310002'),'grouped request IDs');
$ok(str_contains($oneRoute[0]['text'],'2 заявки · 300 кг'),'nearby group total');

foreach ([[4999.999,1],[5000.0,1],[5000.001,0]] as [$meters,$expected]) {
    $boundary = $service->additionIntents(
        ['310003'=>$new('310003',1,0,30)],
        [$active(3,'210003',rad2deg($meters/6371000),30)]
    );
    $ok(count($boundary)===$expected,'nearby classification at '.$meters.' metres');
}
foreach ([[66,179.99,66,-179.99],[89.999,0,89.999,180]] as [$lat1,$lng1,$lat2,$lng2]) {
    $near = $service->additionIntents(
        ['310004'=>$new('310004',1,$lat1,$lng1)],[$active(3,'210004',$lat2,$lng2)]
    );
    $ok(count($near)===1 && $near[0]['kind']==='nearby','antimeridian/polar proximity');
}

$multiRoute = $service->additionIntents(
    ['320001'=>$new('320001',100,55.02,37)],
    [$active(4,'220001',55,37),$active(5,'220002',55.01,37)]
);
$ok(count($multiRoute)===2,'one request near different routes must produce separate messages');

$exclusion = $service->exclusionIntents(
    ['330001'=>$new('330001',500,55,37),'330002'=>$new('330002',600,55,37)],
    [$active(6,'330001',55,37),$active(6,'330002',55,37)]
);
$ok(count($exclusion)===1 && $exclusion[0]['kind']==='active_exclusion','exclusion grouped by route');
$ok(str_contains($exclusion[0]['text'],'2') && str_contains($exclusion[0]['text'],'1 100'),'exclusion count/mass');
$missingMax = $service->additionIntents(
    ['340001'=>$new('340001',50,55,37)],
    [$active(7,'240001',55,37,'')]
);
$ok(count($missingMax)===1 && $missingMax[0]['error']===null,'missing MAX ID must not block delivery');
$ok(str_starts_with($missingMax[0]['text'],'‼️ **НЕОБХОДИМО ДОБАВИТЬ В РЕЙС** ‼️') && !str_contains($missingMax[0]['text'],'max://user/'),'missing MAX ID neutral wording');

$many = [];
for ($i=1;$i<=450;$i++) {
    $id = (string)(400000+$i);
    $many[$id] = $new($id,1,55.02,37);
}
$chunks = $service->additionIntents($many,[$active(8,'250001',55,37)]);
$ok(count($chunks)>1,'4000-char split expected');
$joined = implode("\n",array_column($chunks,'text'));
foreach (array_keys($many) as $id) $ok(str_contains($joined,(string)$id),'chunk lost request '.$id);
foreach ($chunks as $index=>$chunk) {
    $ok(mb_strlen($chunk['text'],'UTF-8')<=4000,'chunk too long');
    $ok((int)$chunk['part']===$index+1,'chunk order');
}

$ok(FeoPlacementRouteNotificationService::massText(45402.000)==='45 402','integer mass formatting');
$ok(FeoPlacementRouteNotificationService::massText(5500.125)==='5 500,125','fractional mass formatting');

echo "FEO_PLACEMENT_ROUTE_NOTIFICATIONS=PASS\n";
