<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap/app.php';
foreach (['FeoMapService','WasteTripService','FeoPlacementRouteNotificationService'] as $class) {
    require_once BASE_PATH . '/app/Service/' . $class . '.php';
}

(static function (): void {
    $service = new App\Service\FeoPlacementRouteNotificationService();
    $assert = static function (bool $value, string $message): void {
        if (!$value) throw new RuntimeException($message);
    };
    $selected = ['123456'=>['zayavka_id'=>'123456','mass_kg'=>60,
        'coords'=>['status'=>'ok','lat'=>55.0,'lng'=>37.0]]];
    $route = ['route_id'=>7,'business_id'=>253,'comment'=>'Новороссийск',
        'zayavka_id'=>'654321','mass_kg'=>150,'membership_id'=>10,
        'responsible_name'=>'ERP Имя','account'=>['full_name'=>'ERP Имя','max_user_id'=>'123'],
        'coords'=>['status'=>'ok','lat'=>55.0,'lng'=>37.0]];
    foreach ([null,''," \t "] as $empty) {
        $row = $route;
        $row['account']['max_user_id'] = $empty;
        foreach (['same_point','nearby','active_exclusion'] as $kind) {
            $active = $row;
            if ($kind === 'nearby') $active['coords']['lat'] = 55.001;
            if ($kind === 'active_exclusion') $active['zayavka_id'] = '123456';
            $messages = $kind === 'active_exclusion'
                ? $service->exclusionIntents($selected, [$active])
                : $service->additionIntents($selected, [$active]);
            $assert(count($messages) === 1 && $messages[0]['error'] === null, $kind.' empty ID must send');
            $text = $messages[0]['text'];
            $assert(!str_contains($text,'max://') && !str_contains($text,'ERP Имя'), $kind.' no address');
            $assert(str_contains($text,'123456') && str_contains($text,'60 кг') && str_contains($text,'253'), $kind.' complete business information');
            $assert(preg_match('/^(‼️|⚠️) /u',$text) === 1, $kind.' readable neutral opening');
        }
    }
    $unlinked = $route;
    $unlinked['account'] = null;
    $unlinked['membership_id'] = null;
    $messages = $service->additionIntents($selected, [$unlinked]);
    $assert(count($messages) === 1 && $messages[0]['error'] === null, 'unavailable identity must not block group message');
    foreach (['0','-1','abc','9223372036854775808'] as $invalid) {
        $row = $route;
        $row['account']['max_user_id'] = $invalid;
        $messages = $service->additionIntents($selected, [$row]);
        $assert($messages[0]['error'] !== null && $messages[0]['text'] === '', 'nonempty invalid ID must remain a diagnostic');
    }
    $route['account']['max_user_id'] = ' 123 ';
    $messages = $service->additionIntents($selected, [$route]);
    $assert(str_starts_with($messages[0]['text'],'‼️ [Менеджер](max://user/123) · **ДОБАВИТЬ В РЕЙС** ‼️'), 'valid ID preserves addressed wording');
    echo "MAX_FEO_OPTIONAL_ADDRESSING_CONTRACT=PASS\n";
})();
