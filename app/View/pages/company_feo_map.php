<?php
$diag = is_array($payload['diagnostics'] ?? null) ? $payload['diagnostics'] : [];
$markerCount = (int)($diag['marker_count'] ?? 0);
$requestCount = (int)($diag['marker_request_count'] ?? 0);
$missingCount = (int)($diag['missing_coordinates_count'] ?? 0);
$invalidCount = (int)($diag['invalid_coordinates_count'] ?? 0);
$unmatchedCount = (int)($diag['unmatched_utilizer_count'] ?? 0);
$inactiveCount = (int)($diag['inactive_utilizer_count'] ?? 0);
$leafletCssPath = 'public/assets/third-party/leaflet/1.9.4/leaflet.css';
$leafletJsPath = 'public/assets/third-party/leaflet/1.9.4/leaflet.js';
$leafletCssUrl = app_url('/assets/third-party/leaflet/1.9.4/leaflet.css') . '?v=' . filemtime(base_path($leafletCssPath));
$leafletJsUrl = app_url('/assets/third-party/leaflet/1.9.4/leaflet.js') . '?v=' . filemtime(base_path($leafletJsPath));
$mapJsUrl = app_url('/assets/js/feo-map.js') . '?v=' . filemtime(base_path('public/assets/js/feo-map.js'));
$tileUrlTemplate = app_url('/company/feo/requests/map/tiles/{z}/{x}/{y}');
?>
<link rel="stylesheet" href="<?= e($leafletCssUrl) ?>">
<link rel="stylesheet" href="<?= app_url('/assets/css/feo-map.css') ?>?v=<?= filemtime(base_path('public/assets/css/feo-map.css')) ?>">

<div class="feo-map-page" id="feo-map-page" data-refresh-url="<?= e(app_url('/company/feo/requests/map/data')) ?>" data-tile-url-template="<?= e($tileUrlTemplate) ?>" data-map-ready="0">
    <div class="feo-map-head">
        <div>
            <h1 class="page-title">Карта</h1>
            <div class="text-muted">Свободные заявки только из актуального «Размещения на карте».</div>
        </div>
        <div class="feo-map-head-actions">
            <?php if (($_SESSION['role_code'] ?? '') === 'company_owner'): ?>
            <a class="btn btn-secondary" href="<?= app_url('/company/feo/utilizers') ?>">Утилизаторы</a>
            <?php endif; ?>
            <button class="btn btn-secondary" type="button" id="feo-map-refresh">Обновить</button>
        </div>
    </div>

    <nav class="feo-domain-tabs" aria-label="Разделы заявок ФЭО">
        <a href="<?= app_url('/company/feo/requests') ?>">Все заявки</a>
        <a href="<?= app_url('/company/feo/requests/map-placement') ?>">Размещение на карте</a>
        <a class="is-active" href="<?= app_url('/company/feo/requests/map') ?>" aria-current="page">Карта</a>
    </nav>

    <?php if ($dbError): ?>
    <div class="alert alert-error" role="alert"><?= e($dbError) ?></div>
    <?php endif; ?>
    <div class="alert alert-error" id="feo-map-runtime-error" role="alert" hidden></div>
    <div class="alert alert-warning" id="feo-map-runtime-warning" role="status" hidden></div>

    <div class="feo-map-summary" aria-label="Сводка карты">
        <span><b id="feo-map-request-count"><?= $requestCount ?></b> заявок</span>
        <span><b id="feo-map-marker-count"><?= $markerCount ?></b> маркеров</span>
        <span class="<?= ($missingCount + $invalidCount) > 0 ? 'is-warning' : '' ?>"><b id="feo-map-coordinate-problems"><?= $missingCount + $invalidCount ?></b> без корректных координат</span>
        <span class="<?= ($unmatchedCount + $inactiveCount) > 0 ? 'is-warning' : '' ?>"><b id="feo-map-classification-problems"><?= $unmatchedCount + $inactiveCount ?></b> требуют классификации</span>
    </div>

    <div class="feo-map-workspace">
        <aside class="feo-map-sidebar" aria-label="Слои карты">
            <div class="feo-map-sidebar-title">
                <div>
                    <b>Слои</b>
                    <span>Видимость заявок</span>
                </div>
            </div>
            <div class="feo-layer-list" id="feo-layer-list"></div>
            <div class="feo-map-diagnostics" id="feo-map-diagnostics"></div>
        </aside>
        <section class="feo-map-canvas-wrap">
            <div id="feo-map" class="feo-map-canvas" aria-label="Географическая карта заявок ФЭО"></div>
            <div class="feo-map-empty" id="feo-map-empty" hidden>Нет заявок с корректными координатами по текущему размещению.</div>
        </section>
    </div>
</div>

<script id="feo-map-data" type="application/json"><?= $mapPayloadJson ?></script>
<script src="<?= e($leafletJsUrl) ?>"></script>
<script src="<?= e($mapJsUrl) ?>"></script>
