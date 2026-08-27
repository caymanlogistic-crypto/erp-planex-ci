<?php
$root=dirname(__DIR__);
$read=static fn(string $p): string => (string)file_get_contents($root.'/'.$p);
$fail=static function(string $m): void { fwrite(STDERR,"FAIL: {$m}\n"); exit(1); };
$must=static function(bool $ok,string $m) use($fail): void { if(!$ok)$fail($m); };

$migration=$read('database/migrations-local/124_create_feo_request_costs.sql');
$service=$read('app/Service/FeoRequestCostService.php');
$controller=$read('app/Http/Controllers/Company/FeoRequestCostController.php');
$route=$read('app/Http/Routes/company_feo_request_costs.php');
$view=$read('app/View/pages/company_feo_request_costs.php');
$js=$read('public/assets/js/feo-request-costs.js');
$index=$read('public/index.php');
$layout=$read('app/View/layouts/main.php');

$must(str_contains($migration,'uk_feo_request_cost_one_price_per_request'), 'DB must enforce one cost per request');
$must(str_contains($migration,'snapshot_mass_kg') && str_contains($migration,'snapshot_total_mass_kg'), 'snapshot mass fields required');
$must(!str_contains($migration,'FOREIGN KEY (`zayavka_id`)'), 'snapshot request must survive FEO/placement removal');
$must(str_contains($service,"private const RATE_34_5 = 34.50") && str_contains($service,"private const RATE_38 = 38.00"), 'fixed rates required');
$must(str_contains($service,'feo_placement_current') && str_contains($service,'already_priced_ids'), 'current placement and duplicate pricing validation required');
$must(str_contains($service,'mass_changed') && str_contains($service,'in_current_placement'), 'snapshot drift detection required');
$must(str_contains($service,'FOR UPDATE') && str_contains($service,'1062'), 'concurrency-safe duplicate prevention required');
$must(str_contains($controller,"['company_owner','senior_logist','logist']"), 'role gate required');
$must(str_contains($route,"/company/feo/requests/costs"), 'route family required');
$must(str_contains($view,'Стоимость заявок') && str_contains($view,'Найти заявку в блоках стоимости'), 'screen/search copy required');
$must(str_contains($js,'(?<!\\d)\\d{6}(?!\\d)') && str_contains($js,"split('\\t',1)"), 'six-digit/first-column parser required');
$must(str_contains($index,"company_feo_request_costs.php"), 'entrypoint must register cost routes');
$must(str_contains($layout,"/company/feo/requests/costs") && str_contains($layout,'Стоимость заявок'), 'sidebar submenu required');

echo "FEO_REQUEST_COSTS_REGRESSION=PASS\n";
