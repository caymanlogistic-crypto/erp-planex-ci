<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$view = file_get_contents($root . '/app/View/pages/company_feo_map.php') ?: '';
$js = file_get_contents($root . '/public/assets/js/feo-map.js') ?: '';
$css = file_get_contents($root . '/public/assets/css/feo-map.css') ?: '';

$pass = 0; $fail = 0;
$ok = static function (string $name, bool $value) use (&$pass, &$fail): void {
    if ($value) { $pass++; return; }
    $fail++; fwrite(STDERR, "FAIL: {$name}\n");
};

$ok('view loads Yandex Maps 2.1', str_contains($view, 'https://api-maps.yandex.ru/2.1/'));
$ok('view uses runtime Yandex key', str_contains($view, "['yandex_maps_api_key']") && str_contains($view, 'YANDEX_MAPS_API_KEY'));
$ok('view has no Leaflet runtime', !str_contains($view, 'leaflet') && !str_contains($view, 'tile-url-template'));
$ok('view has overlay layer panel', str_contains($view, 'feo-map-layer-panel') && str_contains($view, 'feo-map-workspace'));
$ok('Yandex map engine initialized', str_contains($js, "new ymaps.Map('feo-map'") && str_contains($js, 'new ymaps.Placemark('));
$ok('no Leaflet engine in JS', !str_contains($js, 'L.map(') && !str_contains($js, 'L.marker(') && !str_contains($js, 'L.tileLayer('));
$ok('legacy marker body 50x40', str_contains($js, 'REQUEST_MARKER_BODY_WIDTH = 50') && str_contains($js, 'REQUEST_MARKER_BODY_HEIGHT = 40'));
$ok('legacy marker glow pad 8', str_contains($js, 'REQUEST_MARKER_GLOW_PAD = 8'));
$ok('legacy stack step 36', str_contains($js, 'REQUEST_MARKER_STACK_STEP = 36'));
$ok('legacy ADR gap 6', str_contains($js, 'REQUEST_MARKER_ADR_EXTRA_GAP = 6'));
$ok('legacy exact anchor offset', str_contains($js, 'iconImageOffset: [-(REQUEST_MARKER_GLOW_PAD + 25), markerOffsetY(record.shiftY)]'));
$ok('legacy exact balloon offset', str_contains($js, 'balloonOffset: [0, -32 - record.shiftY]'));
$ok('legacy bottom marker keeps tail', str_contains($js, 'record.hideTriangle = position > 0'));
$ok('visible coordinate stack not circular spiderfy', str_contains($js, 'visibleByCoordinate') && !str_contains($js, 'Math.cos(') && !str_contains($js, 'Math.sin(') && !str_contains($js, 'radius ='));
$ok('mass thresholds match source', str_contains($js, 'weightInKg > 10000') && str_contains($js, 'weightInKg > 5000') && str_contains($js, 'weightInKg > 3000') && str_contains($js, 'weightInKg > 1000'));
$ok('utilizer ribbon geometry matches source', str_contains($js, 'x="2" y="1" width="46" height="15"'));
$ok('ADR badge geometry matches source', str_contains($js, 'x="34.5" y="-5" width="14.5" height="8"'));
$ok('selected glow matches source', str_contains($js, 'stroke-width="8.5"') && str_contains($js, 'stdDeviation="3.4"') && str_contains($js, '#1976ff'));
$ok('layer groups preserve Stage 1 canonical scope', str_contains($js, "label: 'Заявки'") && str_contains($js, "label: 'Утилизаторы'") && str_contains($js, "label: 'Диагностика'"));
$ok('layer counts use legacy square brackets', str_contains($js, 'count.textContent = `['));
$ok('layer visibility recomputes stack', str_contains($js, 'state.visibility.set(key, input.checked)') && str_contains($js, 'updateStackPositions();'));
$ok('selection changes marker presentation', str_contains($js, 'toggleRecordSelection') && str_contains($js, 'state.selectedKeys'));
$ok('balloon is Yandex property content', str_contains($js, 'balloonContent: balloonHtml(markerData)') && str_contains($js, 'feo-yandex-balloon'));
$ok('refresh remains read-only GET', str_contains($js, "fetch(url, {headers: {'Accept': 'application/json'}") && !str_contains($js, "method: 'POST'"));
$ok('resize fits Yandex viewport', str_contains($js, 'ResizeObserver') && str_contains($js, 'state.map.container.fitToViewport()'));
$ok('canvas occupies full workspace', str_contains($css, '.feo-map-canvas{position:absolute;inset:0;width:100%;height:100%'));
$ok('layer panel overlays canvas', str_contains($css, '.feo-map-layer-panel{position:absolute;'));

printf("FEO_MAP_YANDEX_LEGACY_CONTRACT=PASS checks=%d FAIL=%d\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
