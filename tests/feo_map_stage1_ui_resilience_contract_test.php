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
$css = $read('public/assets/css/feo-map.css');
$qa = $read('.github/feo-map-stage1/production_visual.js');
$qaWorkflow = $read('.github/workflows/feo_map_stage1_production_visual_qa.yml');
$sanitizer = $read('.github/feo-map-stage1/sanitize_artifacts.js');
$config = $read('config/app.php');
$envExample = $read('.env.example');

$must(str_contains($view, 'https://api-maps.yandex.ru/2.1.77/'), 'Yandex Maps JavaScript API 2.1.77 source missing');
$must(str_contains($view, '&csp=true'), 'Yandex Maps JavaScript API 2.1 CSP mode missing');
$must(str_contains($view, "['yandex_maps_api_key']"), 'view must receive canonical config key');
$must(str_contains($config, "env('YANDEX_MAPS_API_KEY', '')"), 'Yandex key must come from runtime env');
$must(str_contains($envExample, 'YANDEX_MAPS_API_KEY='), 'Yandex key env contract missing from .env.example');
$must(!preg_match("/YANDEX_MAPS_API_KEY=\\S+/", $envExample), '.env.example must never contain a real Yandex key');
$must(!str_contains(strtolower($view), 'leaflet'), 'Leaflet runtime must be absent from the FEO map view');
$must(!str_contains($view, 'data-tile-url-template='), 'OSM tile runtime contract must be absent from the FEO map view');
$must(str_contains($view, 'data-map-ready="0"'), 'map readiness state missing');
$must(str_contains($view, 'data-yandex-configured='), 'Yandex configuration readiness state missing');
$must(str_contains($view, "app_url('/assets/js/feo-map.js')"), 'map runtime must stay app-local');

$must(str_contains($js, "typeof window.ymaps === 'undefined'"), 'Yandex API absence guard missing');
$must(str_contains($js, 'Не удалось загрузить Яндекс.Карты'), 'Yandex loading failure must stay visible');
$must(str_contains($js, "page.dataset.mapReady = 'error'"), 'map failure readiness state missing');
$must(str_contains($js, "page.dataset.mapReady = '1'"), 'map success readiness state missing');
$must(str_contains($js, "page.dataset.mapEngine = 'yandex'"), 'map engine diagnostic state missing');
$must(str_contains($js, "new ymaps.Map('feo-map'"), 'Yandex map initialization missing');
$must(str_contains($js, 'new ymaps.Placemark('), 'Yandex Placemark runtime missing');
$must(!str_contains($js, 'L.map(') && !str_contains($js, 'L.marker(') && !str_contains($js, 'L.tileLayer('), 'Leaflet runtime leaked into FEO map JS');
$must(!str_contains($js, 'tile.openstreetmap.org'), 'OSM runtime leaked into browser JS');
$must(str_contains($js, "document.dispatchEvent(new CustomEvent('feo-map-ready'"), 'browser readiness event missing');
$must(str_contains($js, 'ResizeObserver') && str_contains($js, 'state.map.container.fitToViewport()'), 'Yandex resize contract missing');
$must(str_contains($css, '.feo-map-canvas{position:absolute;inset:0;width:100%;height:100%'), 'map canvas must fill workspace');
$must(str_contains($css, '.feo-map-layer-panel{position:absolute;'), 'layer controls must overlay map canvas');

// Marker presentation source of truth: КАРТА.zip / request_markers_v2.js.
$must(str_contains($js, 'REQUEST_MARKER_BODY_WIDTH = 50'), 'legacy marker body width changed');
$must(str_contains($js, 'REQUEST_MARKER_BODY_HEIGHT = 40'), 'legacy marker body height changed');
$must(str_contains($js, 'REQUEST_MARKER_GLOW_PAD = 8'), 'legacy marker glow pad changed');
$must(str_contains($js, 'REQUEST_MARKER_STACK_STEP = 36'), 'legacy marker vertical stack step changed');
$must(str_contains($js, 'REQUEST_MARKER_ADR_EXTRA_GAP = 6'), 'legacy ADR stack gap changed');
$must(str_contains($js, "stroke-width=\"8.5\""), 'legacy selected glow geometry changed');
$must(str_contains($js, "stdDeviation=\"3.4\""), 'legacy selected glow blur changed');
$must(str_contains($js, "x=\"34.5\" y=\"-5\" width=\"14.5\" height=\"8\""), 'legacy ADR badge geometry changed');
$must(str_contains($js, 'visibleByCoordinate'), 'visible-coordinate stack grouping missing');
$must(str_contains($js, 'record.hideTriangle = position > 0'), 'upper stack markers must suppress the tail');
$must(!str_contains($js, 'Math.cos(') && !str_contains($js, 'Math.sin('), 'circular spiderfy must not return');
$must(str_contains($js, 'window.__FEO_MAP_STAGE1__ = Object.freeze'), 'read-only production diagnostics snapshot missing');

// Production browser QA must prove the Yandex engine, visual marker contract, vertical stack, layers, balloon, refresh and resize.
$must(!str_contains($qa, 'waitForFunction('), 'production Playwright QA must remain CSP-safe');
$must(str_contains($qa, '#feo-map-page[data-map-ready="1"][data-map-engine="yandex"]'), 'Yandex readiness locator missing');
$must(str_contains($qa, 'api-maps.yandex.ru/2.1.77/'), 'production QA must observe the Yandex API response');
$must(str_contains($qa, 'Leaflet/OSM runtime request detected'), 'production QA must forbid Leaflet/OSM runtime traffic');
$must(str_contains($qa, "markerRenderer==='legacy-request-marker-v2'"), 'production QA must verify legacy marker renderer');
$must(str_contains($qa, "JSON.stringify(snap.markerBody)==='[50,40]'"), 'production QA must verify marker body geometry');
$must(str_contains($qa, "JSON.stringify(snap.markerImage)==='[66,56]'"), 'production QA must verify marker image geometry');
$must(str_contains($qa, 'production data has no coincident-coordinate vertical stack'), 'production QA must require a real vertical stack');
$must(str_contains($qa, 'production data has no ADR marker for visual QA'), 'production QA must require a real ADR marker');
$must(str_contains($qa, '#feo-map img[src^="data:image/svg+xml"]'), 'production QA must inspect real Yandex marker images');
$must(str_contains($qa, '.feo-yandex-balloon'), 'production QA must verify Yandex balloon content');
$must(str_contains($qa, '#feo-map-layer-toggle'), 'production QA must verify layer panel collapse/expand');
$must(str_contains($qa, "x.url().includes('/company/feo/requests/map/data')&&x.request().method()==='GET'"), 'production QA must verify read-only refresh');
$must(str_contains($qa, 'map canvas does not fill workspace') && str_contains($qa, 'Yandex map resize drifted'), 'production QA must verify full workspace and resize');
$must(str_contains($qa, "page.route('https://api-maps.yandex.ru/**'"), 'Yandex API outage resilience QA missing');
$must(str_contains($qa, 'missing visible Yandex load failure'), 'Yandex outage must surface a visible error');

// Evidence artifacts must scrub temporary sessions, CSRF and the browser-visible Yandex API key.
$must(str_contains($qaWorkflow, 'Sanitize production evidence'), 'production evidence sanitization step missing');
$must(str_contains($qaWorkflow, 'node .github/feo-map-stage1/sanitize_artifacts.js artifacts'), 'production evidence sanitizer is not executed before upload');
$must(str_contains($sanitizer, 'QA_LOGIST_SESSION_ID'), 'sanitizer must scrub the logist QA session id');
$must(str_contains($sanitizer, 'QA_OWNER_SESSION_ID'), 'sanitizer must scrub the owner QA session id');
$must(str_contains($sanitizer, 'QA_LOGIST_CSRF_TOKEN'), 'sanitizer must scrub the QA CSRF token');
$must(str_contains($sanitizer, 'ERPV2SESSID='), 'sanitizer must scrub ERP session cookies');
$must(str_contains($sanitizer, '[?&]apikey='), 'sanitizer must scrub Yandex API key query values');
$must(str_contains($sanitizer, 'FEO_MAP_STAGE1_ARTIFACT_SANITIZE=PASS'), 'sanitizer must emit an auditable PASS marker');

echo "FEO_MAP_STAGE1_UI_RESILIENCE=PASS\n";
