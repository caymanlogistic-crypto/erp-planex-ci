<?php
$diag = is_array($payload['diagnostics'] ?? null) ? $payload['diagnostics'] : [];
$markerCount = (int)($diag['marker_count'] ?? 0);
$requestCount = (int)($diag['marker_request_count'] ?? 0);
$missingCount = (int)($diag['missing_coordinates_count'] ?? 0);
$invalidCount = (int)($diag['invalid_coordinates_count'] ?? 0);
$unmatchedCount = (int)($diag['unmatched_utilizer_count'] ?? 0);
$inactiveCount = (int)($diag['inactive_utilizer_count'] ?? 0);
$mapJsUrl = app_url('/assets/js/feo-map.js') . '?v=' . filemtime(base_path('public/assets/js/feo-map.js'));
$yandexMapsApiKey = trim((string)($config['app']['yandex_maps_api_key'] ?? ''));
$yandexMapsUrl = $yandexMapsApiKey !== ''
    ? 'https://api-maps.yandex.ru/2.1.77/?apikey=' . rawurlencode($yandexMapsApiKey) . '&lang=ru_RU&csp=true'
    : '';
?>
<link rel="stylesheet" href="<?= app_url('/assets/css/feo-map.css') ?>?v=<?= filemtime(base_path('public/assets/css/feo-map.css')) ?>">

<div class="feo-map-page" id="feo-map-page" data-refresh-url="<?= e(app_url('/company/feo/requests/map/data')) ?>" data-map-ready="0" data-yandex-configured="<?= $yandexMapsUrl !== '' ? '1' : '0' ?>">
    <div class="feo-map-head">
        <div>
            <h1 class="page-title">Карта</h1>
            <div class="text-muted">Свободные заявки только из актуального «Размещения на карте».</div>
        </div>
        <div class="feo-map-head-actions">
            <button class="btn btn-secondary" type="button" id="feo-map-refresh">Обновить</button>
        </div>
    </div>

    <?php if ($dbError): ?>
    <div class="alert alert-error" role="alert"><?= e($dbError) ?></div>
    <?php endif; ?>
    <?php if ($yandexMapsUrl === ''): ?>
    <div class="alert alert-error" role="alert">Яндекс.Карты не настроены: отсутствует YANDEX_MAPS_API_KEY.</div>
    <?php endif; ?>
    <div class="alert alert-error" id="feo-map-runtime-error" role="alert" hidden></div>
    <div class="alert alert-warning" id="feo-map-runtime-warning" role="status" hidden></div>

    <div class="feo-map-summary" aria-label="Сводка карты">
        <span><b id="feo-map-request-count"><?= $requestCount ?></b> заявок</span>
        <span><b id="feo-map-marker-count"><?= $markerCount ?></b> маркеров</span>
        <span class="<?= ($missingCount + $invalidCount) > 0 ? 'is-warning' : '' ?>"><b id="feo-map-coordinate-problems"><?= $missingCount + $invalidCount ?></b> без корректных координат</span>
        <span class="<?= ($unmatchedCount + $inactiveCount) > 0 ? 'is-warning' : '' ?>"><b id="feo-map-classification-problems"><?= $unmatchedCount + $inactiveCount ?></b> требуют классификации</span>
    </div>

    <div class="feo-map-workspace" id="feo-map-workspace">
        <div id="feo-map" class="feo-map-canvas" aria-label="Географическая карта заявок ФЭО"></div>

        <aside class="feo-map-layer-panel" id="feo-map-layer-panel" aria-label="Управление слоями">
            <div class="feo-map-layer-head">
                <div>
                    <b>Управление слоями</b>
                    <span>Видимость заявок</span>
                </div>
                <button class="feo-map-layer-toggle" type="button" id="feo-map-layer-toggle" aria-expanded="true" aria-controls="feo-map-layer-content" title="Свернуть панель">−</button>
            </div>
            <div class="feo-map-layer-content" id="feo-map-layer-content">
                <div class="feo-layer-groups" id="feo-layer-list"></div>
                <div class="feo-map-diagnostics" id="feo-map-diagnostics"></div>
            </div>
        </aside>

        <div class="feo-map-empty" id="feo-map-empty" hidden>Нет заявок с корректными координатами по текущему размещению.</div>
    </div>
</div>

<script id="feo-map-data" type="application/json"><?= $mapPayloadJson ?></script>
<?php if ($yandexMapsUrl !== ''): ?>
<script src="<?= e($yandexMapsUrl) ?>"></script>
<?php endif; ?>
<script src="<?= e($mapJsUrl) ?>"></script>
