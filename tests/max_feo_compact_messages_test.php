<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap/app.php';
foreach (['FeoMapService','WasteTripService','FeoPlacementRouteNotificationService'] as $class) {
    require_once BASE_PATH . '/app/Service/' . $class . '.php';
}

(static function (): void {
    $ok = static function (bool $value, string $message): void { if (!$value) throw new RuntimeException($message); };
    $service = new App\Service\FeoPlacementRouteNotificationService();
    $request = static fn(int $id, float $kg, float $lat): array => ['zayavka_id'=>(string)$id,'mass_kg'=>$kg,
        'coords'=>['status'=>'ok','lat'=>$lat,'lng'=>37.0]];
    $active = static fn(int $id, int $route, float $lat, string $maxId): array => $request($id, 100, $lat) + [
        'route_id'=>$route,'business_id'=>$route,'comment'=>'Тест *рейса*','membership_id'=>$route,
        'account'=>['full_name'=>'Менеджер','max_user_id'=>$maxId]];
    $selected = [];
    for ($n=1;$n<=40;$n++) $selected[(string)(900000+$n)] = $request(900000+$n, (($n-1)%10+1)*100,
        $n<=10?44.0:($n<=20?45.0:($n<=30?47.0:56.0)));
    $routes = [$active(910001,253,44,'111'),$active(910002,253,44,'111'),
        $active(910003,253,45,'111'),$active(910004,253,45,'111'),
        $active(910021,258,47,'222'),$active(910022,258,47,'222'),
        $active(910031,279,56.001,'111'),$active(910041,281,56.002,'222'),
        $active(910099,268,44.001,'222')];
    $messages = $service->additionIntents($selected,$routes);
    $ok(count($messages)===4,'40 requests: two same-point routes plus two nearby routes');
    $ok(array_column($messages,'route_id')===[253,258,279,281],'deterministic route order / global same-point priority');
    $first = $messages[0]['text'];
    $ok(substr_count($first,'**НОВЫЕ ЗАЯВКИ:**')===2,'two points must remain distinct in one route message');
    $ok(str_contains($first,"**НОВЫЕ ЗАЯВКИ:**\n900001 — 100 кг")
        &&str_contains($first,"**НАХОДЯТСЯ В ТОМ ЖЕ МЕСТЕ, ЧТО И ЗАЯВКИ, УЖЕ ВКЛЮЧЁННЫЕ В РЕЙС:**\n**910001, 910002**"),
        'first point must label new and active route requests explicitly');
    $ok(str_contains($first,"**НОВЫЕ ЗАЯВКИ:**\n900011 — 100 кг")
        &&str_contains($first,"**910003, 910004**"),'second point membership');
    $ok(str_contains($first,'**ИТОГО ДОБАВИТЬ:** 20 заявок · 11 000 кг'),'route total counts each new request once');
    $ok(!str_contains($first,'900021'),'another route must not leak');
    foreach (range(900001,900020) as $id) $ok(substr_count($first,(string)$id)===1,'same point cross-product duplication');
    foreach ([2,3] as $index) {
        $text=$messages[$index]['text'];
        $ok(str_contains($text,'**НОВЫЕ ЗАЯВКИ РЯДОМ С РЕЙСОМ, ДО 5 КМ** ⚠️')
            &&str_ends_with($text,'⚠️ **ПРОВЕРЬТЕ ВОЗМОЖНОСТЬ ДОБАВЛЕНИЯ В РЕЙС** ⚠️'),'nearby markers');
        $ok(str_contains($text,'**НОВЫЕ ЗАЯВКИ:**')&&str_contains($text,'**ИТОГО:** 10 заявок · 5 500 кг'),'nearby route total');
        foreach (range(900031,900040) as $id) $ok(substr_count($text,(string)$id)===1,'nearby member missing/duplicated');
    }
    $ok($messages===$service->additionIntents(array_reverse($selected,true),array_reverse([...$routes,$routes[0]])),
        'input order and duplicate active joins must not change output or keys');

    $excluded=$service->exclusionIntents(['910001'=>$request(910001,150,44),'910002'=>$request(910002,214,44)],$routes);
    $ok(count($excluded)===1,'exclusion remains grouped by route');
    $ok(str_contains($excluded[0]['text'],'‼️ [Менеджер](max://user/111) · **С КАРТЫ СНЯТЫ ЗАЯВКИ, УЖЕ ВКЛЮЧЁННЫЕ В РЕЙС** ‼️')
        &&str_contains($excluded[0]['text'],"**СНЯТЫЕ ЗАЯВКИ:**\n**910001 — 150 кг**\n**910002 — 214 кг**\n\n**ИТОГО СНЯТО:** 2 заявки · 364 кг\n‼️ **ПРОВЕРЬТЕ И СКОРРЕКТИРУЙТЕ СОСТАВ РЕЙСА** ‼️"),'readable active removal');

    // One point exceeds one MAX message: preserve every old/new ID, balanced bold,
    // stable point context, and deterministic delivery parts without truncation.
    $large=[];$largeActive=[];
    for($n=1;$n<=1000;$n++){
        $large[(string)(800000+$n)]=$request(800000+$n,1,55);
        $largeActive[]=$active(700000+$n,253,55,'');
    }
    $chunks=$service->additionIntents($large,$largeActive);
    $ok(count($chunks)>1,'large point must split');
    $joined=implode("\n",array_column($chunks,'text'));
    for($n=1;$n<=1000;$n++){
        $ok(substr_count($joined,(string)(800000+$n))===1,'new ID lost/duplicated across chunks');
        $ok(substr_count($joined,(string)(700000+$n))===1,'active ID lost/duplicated across chunks');
    }
    foreach($chunks as $index=>$chunk){
        $ok($chunk['part']===$index+1&&mb_strlen($chunk['text'],'UTF-8')<=4000,'MAX length and part limit');
        $ok(str_contains($chunk['text'],'**Точка 1**'),'point identity missing on continuation');
        $ok(substr_count($chunk['text'],'**')%2===0,'unbalanced bold across parts');
        $ok(!str_contains($chunk['text'],'max://'),'empty ID remains unaddressed');
    }
    $key='feo.placement.block_created';
    foreach([1=>'1 заявка',2=>'2 заявки',5=>'5 заявок',11=>'11 заявок',21=>'21 заявка',40=>'40 заявок'] as $n=>$label){
        $text=App\Service\MaxEventCatalog::render($key,App\Service\MaxFeoMessageTemplates::defaults()['main'],'plain',['action_title'=>'ДОБАВЛЕНО НА КАРТУ','request_count'=>$n,'mass_kg'=>'22 000']);
        $ok($text==="✅ **ДОБАВЛЕНО НА КАРТУ**\n**КОЛИЧЕСТВО:** ".$label."\n**ОБЩАЯ МАССА:** 22 000 кг",'readable main/count agreement');
    }
    $legacy=['feo_templates_version'=>1,'templates'=>[
        'main'=>"{{action_icon}} **{{action_title}}**\n{{count_label}} · {{mass_kg}} кг",
        'same_point'=>"‼️ {{manager}}**ДОБАВИТЬ В РЕЙС** ‼️\n**{{route}}**\n{{items}}\n**Итого добавить: {{count_label}} · {{mass_kg}} кг**",
        'nearby'=>"⚠️ {{manager}}**РЯДОМ, ДО 5 КМ** ⚠️\n**{{route}}**\n{{items}}\n**{{count_label}} · {{mass_kg}} кг**\n⚠️ Рассмотрите добавление в рейс ⚠️",
        'active_exclusion'=>"‼️ {{manager}}**СНЯТЫ С КАРТЫ** ‼️\n**{{route}}**\n{{items}}\n**{{count_label}} · {{mass_kg}} кг**\n‼️ Скорректируйте состав рейса ‼️",
        'point'=>"В точке: **{{active_ids}}**\n{{requests}}",
        'addition_row'=>'\\+ {{id}} — {{mass_kg}} кг','nearby_row'=>'{{id}} — {{mass_kg}} кг','exclusion_row'=>'− **{{id}} — {{mass_kg}} кг**',
    ]];
    $ok(App\Service\MaxFeoMessageTemplates::decode(json_encode($legacy,JSON_UNESCAPED_UNICODE))===App\Service\MaxFeoMessageTemplates::defaults(),
        'saved standard v1 templates must upgrade to readable defaults');
    $legacy['templates']['nearby']=str_replace('РЯДОМ, ДО 5 КМ','МОЙ ТЕКСТ',$legacy['templates']['nearby']);
    $ok(str_contains(App\Service\MaxFeoMessageTemplates::decode(json_encode($legacy,JSON_UNESCAPED_UNICODE))['nearby'],'МОЙ ТЕКСТ'),
        'edited v1 template must be preserved');
    $ok(str_contains(App\Service\MaxFeoMessageTemplates::encode(App\Service\MaxFeoMessageTemplates::defaults()),'"feo_templates_version":2'),
        'new template saves must use version 2');
    $ok(str_contains(App\Service\MaxEventCatalog::currentTemplate('feo.placement.block_created',
        "{{action_icon}} **{{action_title}}**\n{{count_label}} · {{mass_kg}} кг"),'**КОЛИЧЕСТВО:**'),
        'legacy standalone main template must upgrade to readable default');
    foreach(['missing','unknown','duplicated','broken'] as $case){
        $templates=App\Service\MaxFeoMessageTemplates::defaults();
        $templates['point']=match($case){'missing'=>'{{requests}}','unknown'=>'{{active_ids}} {{requests}} {{unknown}}',
            'duplicated'=>'{{active_ids}} {{requests}} {{requests}}',default=>'{{active_ids}} {{requests}} {{'};
        $rejected=false;
        try{App\Service\MaxFeoMessageTemplates::validate($templates);}catch(RuntimeException){$rejected=true;}
        $ok($rejected,'invalid editable template must be rejected: '.$case);
    }
    echo "MAX_FEO_COMPACT_40_AND_LARGE_POINT=PASS\n";
})();
