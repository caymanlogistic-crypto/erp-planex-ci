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
$tileService = $read('app/Service/FeoMapTileService.php');
$controller = $read('app/Http/Controllers/Company/FeoMapController.php');
$routes = $read('app/Http/Routes/company_feo_map.php');

$must(str_contains($view, "/assets/third-party/leaflet/1.9.4/leaflet.js"), 'same-origin Leaflet JS source missing');
$must(str_contains($view, "/assets/third-party/leaflet/1.9.4/leaflet.css"), 'same-origin Leaflet CSS source missing');
$must(!str_contains($view, '/assets/vendor/'), 'deploy-blocked vendor asset path must not return');
$must(!str_contains($view, 'cdn.jsdelivr.net'), 'Stage 1 map must not depend on jsDelivr under production CSP');
$must(!str_contains($view, 'unpkg.com'), 'Stage 1 map must not depend on unpkg under production CSP');
$must(!str_contains($view, 'loadLeaflet('), 'obsolete CDN Leaflet fallback loader must be removed');
$must(str_contains($view, 'data-map-ready="0"'), 'map readiness state missing');
$must(str_contains($view, 'data-tile-url-template='), 'same-origin tile template missing');
$must(str_contains($view, "app_url('/company/feo/requests/map/tiles/{z}/{x}/{y}')"), 'tile template must stay app-local');
$must(str_contains($view, "app_url('/assets/js/feo-map.js')"), 'map runtime must stay app-local');

$must(!str_contains($js, "typeof window.L === 'undefined') return"), 'silent Leaflet failure must not return without feedback');
$must(str_contains($js, "typeof window.L === 'undefined'"), 'Leaflet absence guard missing');
$must(str_contains($js, 'Не удалось загрузить компонент карты'), 'Leaflet loading failure must stay visible');
$must(str_contains($js, "page.dataset.mapReady = 'error'"), 'map failure readiness state missing');
$must(str_contains($js, "page.dataset.mapReady = '1'"), 'map success readiness state missing');
$must(str_contains($js, "page.dataset.tileUrlTemplate"), 'map must read the same-origin tile template');
$must(!str_contains($js, 'tile.openstreetmap.org'), 'browser runtime must not request OSM tiles directly under production CSP');
$must(str_contains($js, "tiles.on('loading'"), 'tile load-cycle reset hook missing');
$must(str_contains($js, "tiles.on('tileerror'"), 'tile outage handling missing');
$must(str_contains($js, "tiles.on('load'"), 'tile load completion hook missing');
$must(str_contains($js, "tiles.on('loading', () => {\n            state.tileErrors = 0;"), 'tile error counter must reset at the start of a new load cycle');
$must(str_contains($js, "if (state.tileErrors === 0) showRuntimeWarning('');"), 'tile warning must clear only after a clean load cycle');
$must(!str_contains($js, "tiles.on('load', () => {\n            state.tileErrors = 0;\n            showRuntimeWarning('');"), 'load must not erase tile errors and hide an outage warning unconditionally');
$must(str_contains($js, 'Маркеры заявок продолжают отображаться'), 'tile outage must explain that markers remain usable');
$must(str_contains($js, "document.dispatchEvent(new CustomEvent('feo-map-ready'"), 'browser readiness event missing');

$must(str_contains($routes, "/company/feo/requests/map/tiles/{z}/{x}/{y}"), 'same-origin tile route missing');
$must(str_contains($routes, "app/Service/FeoMapTileService.php"), 'tile service dependency missing');
$must(str_contains($controller, 'public function tile(string $z, string $x, string $y): void'), 'tile controller action missing');
$must(substr_count($controller, "requireRole(['company_owner','senior_logist','logist'])") >= 3, 'tile route must keep map RBAC');
$must(str_contains($tileService, "private const UPSTREAM_BASE = 'https://tile.openstreetmap.org'"), 'tile upstream must be fixed to the canonical HTTPS OSM host');
$must(str_contains($tileService, 'private const CACHE_TTL_SECONDS = 604800'), 'tile cache must keep at least seven-day TTL');
$must(str_contains($tileService, 'PLANEX-ERP-FEO-Map/1.0'), 'identifiable tile User-Agent missing');
$must(str_contains($tileService, "'If-None-Match: '"), 'conditional ETag revalidation missing');
$must(str_contains($tileService, "'If-Modified-Since: '"), 'conditional Last-Modified revalidation missing');
$must(str_contains($tileService, 'cache/feo-map-tiles'), 'local tile cache path missing');
$must(str_contains($tileService, "CURLOPT_FOLLOWLOCATION => false"), 'tile proxy must not follow redirects to arbitrary hosts');

require_once $root . '/app/Service/FeoMapTileService.php';
$must(\App\Service\FeoMapTileService::normalizeCoordinates('0', '0', '0') === [0,0,0], 'valid z0 tile rejected');
$must(\App\Service\FeoMapTileService::normalizeCoordinates('5', '31', '31') === [5,31,31], 'valid z5 tile rejected');
$must(\App\Service\FeoMapTileService::normalizeCoordinates('5', '32', '0') === null, 'out-of-range x accepted');
$must(\App\Service\FeoMapTileService::normalizeCoordinates('20', '0', '0') === null, 'zoom above Stage 1 maximum accepted');
$must(\App\Service\FeoMapTileService::normalizeCoordinates('../5', '0', '0') === null, 'non-numeric tile coordinate accepted');

// Production CSP does not allow unsafe-eval. Browser acceptance must stay CSP-safe and recognize same-origin runtime assets.
$must(!str_contains($qa, 'waitForFunction('), 'production Playwright QA must not use waitForFunction under CSP');
$must(str_contains($qa, '#feo-map-page[data-map-ready="1"]'), 'CSP-safe map readiness locator missing');
$must(str_contains($qa, '#feo-map-runtime-warning:not([hidden])'), 'CSP-safe tile warning locator missing');
$must(str_contains($qa, 'waitForPositiveText'), 'CSP-safe refresh marker polling missing');
$must(str_contains($qa, "/assets/third-party/leaflet/1.9.4/leaflet.js"), 'production QA must recognize local Leaflet JS');
$must(str_contains($qa, "/assets/third-party/leaflet/1.9.4/leaflet.css"), 'production QA must recognize local Leaflet CSS');
$must(!str_contains($qa, 'leaflet@1.9.4'), 'production QA must not recognize obsolete CDN Leaflet URL form');
$must(str_contains($qa, "**/company/feo/requests/map/tiles/**"), 'tile outage QA must block same-origin proxy path');
$must(str_contains($qa, 'externalTileRequests.length===0'), 'production QA must forbid direct browser OSM traffic');
$must(str_contains($qa, 'same-origin map tile HTTP != 200'), 'production QA must require a successful proxied tile');
$must(str_contains($qa, 'blockedTileRequests>=3'), 'resilience QA must prove that same-origin tile requests were actually intercepted');
$must(str_contains($qa, 'const liveContext=await browser.newContext'), 'normal production pass needs an isolated browser context');
$must(str_contains($qa, 'const resilienceContext=await browser.newContext'), 'resilience pass needs a fresh browser context');
$liveClose = strpos($qa, 'await liveContext.close();');
$resilienceContext = strpos($qa, 'const resilienceContext=await browser.newContext');
$must($liveClose !== false && $resilienceContext !== false && $liveClose < $resilienceContext, 'resilience context must be created only after the live context/cache is closed');

// Marker presentation is LOCKED. These assertions protect the accepted marker contract.
$must(str_contains($js, "classes = ['feo-marker']"), 'locked marker class changed');
$must(str_contains($js, "L.divIcon({className: 'feo-div-icon', html, iconSize: [110, 38], iconAnchor: [55, 19], popupAnchor: [0, -21]})"), 'locked marker geometry changed');

echo "FEO_MAP_STAGE1_UI_RESILIENCE=PASS\n";
