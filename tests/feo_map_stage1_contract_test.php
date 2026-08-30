<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/Service/FeoUtilizerService.php';
require_once __DIR__ . '/../app/Service/FeoMapService.php';

use App\Service\FeoMapService;

$pass = 0; $fail = 0;
$ok = static function (string $name, bool $value) use (&$pass, &$fail): void {
    if ($value) { $pass++; return; }
    $fail++; fwrite(STDERR, "FAIL: {$name}\n");
};
$root = dirname(__DIR__);
$read = static fn(string $path): string => file_get_contents($path) ?: '';

$rows = [
    ['zayavka_id'=>'100001','source_block_id'=>7,'mass_netto'=>'1.5','mno_sh'=>'37.61','mno_d'=>'55.75','naim_oo_gruzootpravitel'=>'A','mno_adres_pogruzki'=>'A1','nomer_opasnogo_gr'=>''],
    ['zayavka_id'=>'100002','source_block_id'=>7,'mass_netto'=>'0.5','mno_sh'=>'37.61','mno_d'=>'55.75','naim_oo_gruzootpravitel'=>'B','mno_adres_pogruzki'=>'B1','nomer_opasnogo_gr'=>'3'],
    ['zayavka_id'=>'100003','source_block_id'=>9,'mass_netto'=>'2','mno_sh'=>'37.61','mno_d'=>'55.75','naim_oo_gruzootpravitel'=>'C','mno_adres_pogruzki'=>'C1','nomer_opasnogo_gr'=>'3'],
    ['zayavka_id'=>'100004','source_block_id'=>9,'mass_netto'=>'1','mno_sh'=>'','mno_d'=>''],
    ['zayavka_id'=>'100005','source_block_id'=>9,'mass_netto'=>'1','mno_sh'=>'37','mno_d'=>'999'],
    ['zayavka_id'=>'100006','source_block_id'=>9,'mass_netto'=>'1','mno_sh'=>'38','mno_d'=>'56','nomer_opasnogo_gr'=>'3'],
    ['zayavka_id'=>'100007','source_block_id'=>9,'mass_netto'=>'1','mno_sh'=>'39','mno_d'=>'57'],
];
$u1 = ['id'=>1,'full_name'=>'U1 full','short_name'=>'U1','address'=>'','adr'=>1,'color_hex'=>'#112233','is_active'=>1,'sort_order'=>10];
$u2 = ['id'=>2,'full_name'=>'U2 full','short_name'=>'U2','address'=>'','adr'=>0,'color_hex'=>'#445566','is_active'=>1,'sort_order'=>20];
$u3 = ['id'=>3,'full_name'=>'U3 full','short_name'=>'U3','address'=>'','adr'=>0,'color_hex'=>'#778899','is_active'=>0,'sort_order'=>30];
$classifications = [
    '100001'=>['status'=>'matched','source'=>'rule','utilizer_id'=>1,'utilizer'=>$u1,'rule_id'=>11],
    '100002'=>['status'=>'matched','source'=>'rule','utilizer_id'=>1,'utilizer'=>$u1,'rule_id'=>11],
    '100003'=>['status'=>'matched','source'=>'rule','utilizer_id'=>2,'utilizer'=>$u2,'rule_id'=>12],
    '100004'=>['status'=>'matched','source'=>'rule','utilizer_id'=>1,'utilizer'=>$u1,'rule_id'=>11],
    '100005'=>['status'=>'matched','source'=>'rule','utilizer_id'=>1,'utilizer'=>$u1,'rule_id'=>11],
    '100006'=>['status'=>'unmatched','source'=>'none','utilizer_id'=>null,'utilizer'=>null,'rule_id'=>null],
    '100007'=>['status'=>'inactive','source'=>'override','utilizer_id'=>3,'utilizer'=>$u3,'rule_id'=>null],
];
$payload = FeoMapService::buildPayload($rows, $classifications);
$ok('same coordinates and same utilizer/state group', count($payload['markers']) === 4);
$u1Groups = array_values(array_filter($payload['markers'], static fn(array $m): bool => ($m['utilizer_id'] ?? null) === 1));
$ok('same utilizer group has two requests', count($u1Groups) === 1 && $u1Groups[0]['request_count'] === 2);
$ok('group mass is kg', abs((float)$u1Groups[0]['total_mass_kg'] - 2000.0) < 0.001);
$ok('ADR inherits from utilizer', $u1Groups[0]['adr'] === true);
$u2Groups = array_values(array_filter($payload['markers'], static fn(array $m): bool => ($m['utilizer_id'] ?? null) === 2));
$ok('request hazardous field cannot elevate matched utilizer ADR', count($u2Groups) === 1 && $u2Groups[0]['adr'] === false);
$unmatchedGroups = array_values(array_filter($payload['markers'], static fn(array $m): bool => $m['classification_status'] === 'unmatched'));
$ok('unmatched request does not invent request-level ADR in Stage 1', count($unmatchedGroups) === 1 && $unmatchedGroups[0]['adr'] === false);
$overlaps = array_values(array_filter($payload['markers'], static fn(array $m): bool => abs((float)$m['canonical_lat'] - 55.75) < 0.000001 && abs((float)$m['canonical_lng'] - 37.61) < 0.000001));
$ok('different utilizer at same coordinate remains separate', count($overlaps) === 2);
$ok('overlap metadata is present', $overlaps[0]['overlap_count'] === 2 && $overlaps[1]['overlap_count'] === 2);
$ok('unmatched utilizer remains drawable', count($unmatchedGroups) === 1);
$ok('inactive utilizer remains diagnostic', count(array_filter($payload['markers'], static fn(array $m): bool => $m['classification_status'] === 'inactive')) === 1);
$ok('missing coordinate is diagnosed', $payload['diagnostics']['missing_coordinates'] === ['100004']);
$ok('invalid coordinate is diagnosed', $payload['diagnostics']['invalid_coordinates'] === ['100005']);
$ok('drawable request count is correct', $payload['diagnostics']['marker_request_count'] === 5);
$layerKeys = array_column($payload['layers'], 'key');
$ok('available master layer exists', in_array('available', $layerKeys, true));
$ok('utilizer layers are independent', in_array('utilizer:1', $layerKeys, true) && in_array('utilizer:2', $layerKeys, true));
$ok('diagnostic layers are independent', in_array('unmatched', $layerKeys, true) && in_array('inactive:3', $layerKeys, true));

$swapPayload = FeoMapService::buildPayload([
    ['zayavka_id'=>'200001','source_block_id'=>10,'mass_netto'=>'1','mno_sh'=>'129.73','mno_d'=>'62.03'],
], [
    '200001'=>['status'=>'unmatched','source'=>'none','utilizer_id'=>null,'utilizer'=>null,'rule_id'=>null],
]);
$swapMarker = $swapPayload['markers'][0] ?? null;
$ok('historically swapped FEO fields map mno_d to latitude and mno_sh to longitude', is_array($swapMarker) && abs((float)$swapMarker['canonical_lat'] - 62.03) < 0.000001 && abs((float)$swapMarker['canonical_lng'] - 129.73) < 0.000001);
$ok('east longitude above 90 remains drawable after storage swap', $swapPayload['diagnostics']['marker_request_count'] === 1 && $swapPayload['diagnostics']['invalid_coordinates_count'] === 0);

$service = $read($root . '/app/Service/FeoMapService.php');
$ok('available pool starts from placement current', str_contains($service, 'FROM feo_placement_current c'));
$ok('available pool joins canonical requests', str_contains($service, 'INNER JOIN feo_requests r ON r.zayavka_id=c.zayavka_id'));
$ok('no legacy flights domain', !str_contains(strtolower($service), 'flights'));
$ok('FEO storage coordinate inversion is explicit', str_contains($service, 'self::coordinates($row[\'mno_d\'] ?? null, $row[\'mno_sh\'] ?? null)'));
$ok('group key includes coordinates state utilizer', str_contains($service, '$coordKey . \'|\' . $state . \'|\' . $utilizerKey'));
$ok('Stage 1 map has no request-level ADR helper', !str_contains($service, 'requestAdr('));

$route = $read($root . '/app/Http/Routes/company_feo_map.php');
$ok('map route is separate', str_contains($route, '/company/feo/requests/map'));
$ok('map route does not shadow placement', !str_contains($route, '/company/feo/requests/map-placement'));
$ok('utilizer admin has own route family', str_contains($route, '/company/feo/utilizers'));

$mapController = $read($root . '/app/Http/Controllers/Company/FeoMapController.php');
$adminController = $read($root . '/app/Http/Controllers/Company/FeoUtilizerController.php');
$ok('map RBAC keeps operational roles', str_contains($mapController, "requireRole(['company_owner','senior_logist','logist'])"));
$ok('map uses tenant local PDO', str_contains($mapController, '$this->requests->localPdo($company)'));
$ok('utilizer mutations are owner-only', str_contains($adminController, "requireRole(['company_owner'])"));
$ok('utilizer admin uses tenant local PDO', str_contains($adminController, '$this->requests->localPdo($company)'));

$nav = $read($root . '/app/Support/feo_freshness_navigation.php');
$ok('canonical FEO nav has placement and map', str_contains($nav, 'data-feo-placement-nav') && str_contains($nav, 'data-feo-map-nav'));
$ok('canonical FEO nav has owner-only utilizer link', str_contains($nav, 'data-feo-utilizers-nav') && str_contains($nav, "company_owner' ? 'true' : 'false'"));
$ok('map routes activate existing FEO group', str_contains($nav, "path.endsWith('/company/feo/requests/map')") && str_contains($nav, "path.endsWith('/company/feo/utilizers')") && str_contains($nav, "parent.classList.add('is-open','is-active')"));

$view = $read($root . '/app/View/pages/company_feo_map.php');
$js = $read($root . '/public/assets/js/feo-map.js');
$ok('map view has compact layer workspace', str_contains($view, 'feo-map-workspace') && str_contains($view, 'feo-layer-list'));
$ok('map view has runtime error surface', str_contains($view, 'feo-map-runtime-error'));
$ok('overlap stack does not mutate canonical coordinates', str_contains($js, 'canonicalCoordinates(markerData)') && str_contains($js, 'overlap_index') && str_contains($js, 'iconImageOffset') && !str_contains($js, 'Math.cos(') && !str_contains($js, 'Math.sin('));
$ok('refresh is read-only GET', str_contains($js, "fetch(url, {headers: {'Accept': 'application/json'}") && !str_contains($js, "method: 'POST'"));
$ok('no native browser alert/confirm', !str_contains($js, 'alert(') && !str_contains($js, 'confirm(') && str_contains($js, 'showRuntimeError'));

printf("PASS=%d FAIL=%d\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);