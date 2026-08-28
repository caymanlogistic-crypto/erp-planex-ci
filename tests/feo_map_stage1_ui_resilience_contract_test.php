<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$fail = static function (string $message): never { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); };
$must = static function (bool $ok, string $message) use ($fail): void { if (!$ok) $fail($message); };
$read = static function (string $rel) use ($root,$fail): string {
    $v = @file_get_contents($root . '/' . $rel);
    if (!is_string($v)) $fail('cannot read ' . $rel);
    return $v;
};

$view = $read('app/View/pages/company_feo_map.php');
$js = $read('public/assets/js/feo-map.js');

$must(str_contains($view, 'cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js'), 'pinned Leaflet primary source missing');
$must(str_contains($view, 'unpkg.com/leaflet@1.9.4/dist/leaflet.js'), 'pinned Leaflet fallback source missing');
$must(str_contains($view, 'sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo='), 'official Leaflet JS SRI missing');
$must(str_contains($view, 'sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY='), 'official Leaflet CSS SRI missing');
$must(str_contains($view, 'data-map-ready="0"'), 'map readiness state missing');
$must(str_contains($view, 'Не удалось загрузить компонент карты'), 'Leaflet loading must fail visibly');
$must(str_contains($view, 'loadLeaflet(index + 1)'), 'Leaflet JS fallback chain missing');
$must(str_contains($view, "app_url('/assets/js/feo-map.js')"), 'map runtime must stay app-local');

$must(!str_contains($js, "typeof window.L === 'undefined') return"), 'silent Leaflet failure must not return without feedback');
$must(str_contains($js, "typeof window.L === 'undefined'"), 'Leaflet absence guard missing');
$must(str_contains($js, "page.dataset.mapReady = 'error'"), 'map failure readiness state missing');
$must(str_contains($js, "page.dataset.mapReady = '1'"), 'map success readiness state missing');
$must(str_contains($js, "tiles.on('tileerror'"), 'tile outage handling missing');
$must(str_contains($js, 'Маркеры заявок продолжают отображаться'), 'tile outage must explain that markers remain usable');
$must(str_contains($js, "document.dispatchEvent(new CustomEvent('feo-map-ready'"), 'browser readiness event missing');

// Marker presentation is LOCKED. These assertions protect the accepted marker contract.
$must(str_contains($js, "classes = ['feo-marker']"), 'locked marker class changed');
$must(str_contains($js, "L.divIcon({className: 'feo-div-icon', html, iconSize: [110, 38], iconAnchor: [55, 19], popupAnchor: [0, -21]})"), 'locked marker geometry changed');

echo "FEO_MAP_STAGE1_UI_RESILIENCE=PASS\n";
