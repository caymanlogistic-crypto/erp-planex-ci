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
$qa = $read('.github/feo-map-stage1/production_visual.js');

$must(str_contains($view, "/assets/third-party/leaflet/1.9.4/leaflet.js"), 'same-origin Leaflet JS source missing');
$must(str_contains($view, "/assets/third-party/leaflet/1.9.4/leaflet.css"), 'same-origin Leaflet CSS source missing');
$must(!str_contains($view, '/assets/vendor/'), 'deploy-blocked vendor asset path must not return');
$must(!str_contains($view, 'cdn.jsdelivr.net'), 'Stage 1 map must not depend on jsDelivr under production CSP');
$must(!str_contains($view, 'unpkg.com'), 'Stage 1 map must not depend on unpkg under production CSP');
$must(!str_contains($view, 'loadLeaflet('), 'obsolete CDN Leaflet fallback loader must be removed');
$must(str_contains($view, 'data-map-ready="0"'), 'map readiness state missing');
$must(str_contains($view, "app_url('/assets/js/feo-map.js')"), 'map runtime must stay app-local');

$must(!str_contains($js, "typeof window.L === 'undefined') return"), 'silent Leaflet failure must not return without feedback');
$must(str_contains($js, "typeof window.L === 'undefined'"), 'Leaflet absence guard missing');
$must(str_contains($js, 'Не удалось загрузить компонент карты'), 'Leaflet loading failure must stay visible');
$must(str_contains($js, "page.dataset.mapReady = 'error'"), 'map failure readiness state missing');
$must(str_contains($js, "page.dataset.mapReady = '1'"), 'map success readiness state missing');
$must(str_contains($js, "tiles.on('tileerror'"), 'tile outage handling missing');
$must(str_contains($js, 'Маркеры заявок продолжают отображаться'), 'tile outage must explain that markers remain usable');
$must(str_contains($js, "document.dispatchEvent(new CustomEvent('feo-map-ready'"), 'browser readiness event missing');

// Production CSP does not allow unsafe-eval. Browser acceptance must stay CSP-safe and recognize the same-origin Leaflet URLs.
$must(!str_contains($qa, 'waitForFunction('), 'production Playwright QA must not use waitForFunction under CSP');
$must(str_contains($qa, '#feo-map-page[data-map-ready="1"]'), 'CSP-safe map readiness locator missing');
$must(str_contains($qa, '#feo-map-runtime-warning:not([hidden])'), 'CSP-safe tile warning locator missing');
$must(str_contains($qa, 'waitForPositiveText'), 'CSP-safe refresh marker polling missing');
$must(str_contains($qa, "/assets/third-party/leaflet/1.9.4/leaflet.js"), 'production QA must recognize local Leaflet JS');
$must(str_contains($qa, "/assets/third-party/leaflet/1.9.4/leaflet.css"), 'production QA must recognize local Leaflet CSS');
$must(!str_contains($qa, 'leaflet@1.9.4'), 'production QA must not recognize obsolete CDN Leaflet URL form');

// Marker presentation is LOCKED. These assertions protect the accepted marker contract.
$must(str_contains($js, "classes = ['feo-marker']"), 'locked marker class changed');
$must(str_contains($js, "L.divIcon({className: 'feo-div-icon', html, iconSize: [110, 38], iconAnchor: [55, 19], popupAnchor: [0, -21]})"), 'locked marker geometry changed');

echo "FEO_MAP_STAGE1_UI_RESILIENCE=PASS\n";
