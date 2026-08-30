<?php
$mapJsPath = base_path('public/assets/js/feo-map.js');
$mapCssPath = base_path('public/assets/css/feo-map.css');
$assetVersion = static function (string $path): string {
    $hash = is_file($path) ? hash_file('sha256', $path) : false;
    return is_string($hash) && $hash !== '' ? substr($hash, 0, 12) : (string)filemtime($path);
};
$mapJsUrl = app_url('/assets/js/feo-map.js') . '?v=' . $assetVersion($mapJsPath);
$mapCssUrl = app_url('/assets/css/feo-map.css') . '?v=' . $assetVersion($mapCssPath);
$yandexMapsApiKey = trim((string)($config['app']['yandex_maps_api_key'] ?? ''));
$yandexMapsUrl = $yandexMapsApiKey !== ''
    ? 'https://api-maps.yandex.ru/2.1.77/?apikey=' . rawurlencode($yandexMapsApiKey) . '&lang=ru_RU&csp=true'
    : '';
?>
<link rel="stylesheet" href="<?= e($mapCssUrl) ?>">

<div class="feo-map-page" id="feo-map-page" data-refresh-url="<?= e(app_url('/company/feo/requests/map/data')) ?>" data-map-ready="0" data-yandex-configured="<?= $yandexMapsUrl !== '' ? '1' : '0' ?>">
    <div class="feo-map-workspace" id="feo-map-workspace">
        <div id="feo-map" class="feo-map-canvas" aria-label="Географическая карта заявок ФЭО"></div>

        <div class="feo-map-alert-stack" aria-live="polite">
            <?php if ($dbError): ?>
            <div class="alert alert-error" role="alert"><?= e($dbError) ?></div>
            <?php endif; ?>
            <?php if ($yandexMapsUrl === ''): ?>
            <div class="alert alert-error" role="alert">Яндекс.Карты не настроены: отсутствует YANDEX_MAPS_API_KEY.</div>
            <?php endif; ?>
            <div class="alert alert-error" id="feo-map-runtime-error" role="alert" hidden></div>
            <div class="alert alert-warning" id="feo-map-runtime-warning" role="status" hidden></div>
        </div>

        <aside class="feo-map-layer-panel" id="feo-map-layer-panel" aria-label="Управление слоями">
            <div class="feo-map-layer-head">
                <b>Слои</b>
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
<script>
(() => {
    'use strict';
    const panel = document.getElementById('feo-map-layer-panel');
    const list = document.getElementById('feo-layer-list');
    const content = document.getElementById('feo-map-layer-content');
    const head = panel?.querySelector('.feo-map-layer-head') || null;
    if (!panel || !list || !content || !head || typeof MutationObserver === 'undefined') return;

    const allowedLayerGroups = new Set(['requests', 'utilizers', 'diagnostics']);
    const forbiddenLayerLabel = 'ПРОИЗВОДСТВЕННЫЙ КАЛЕНДАРЬ';
    const structuralNodes = new Set([panel, head, content, list, document.getElementById('feo-map-diagnostics')].filter(Boolean));
    const normalize = value => String(value || '').trim().toLocaleUpperCase('ru-RU').replace(/Ё/g, 'Е');
    let scheduled = false;

    const removeInjectedLayerUi = () => {
        scheduled = false;
        for (const child of Array.from(list.children)) {
            if (!(child instanceof HTMLElement)) continue;
            if (!child.classList.contains('feo-layer-group') || !allowedLayerGroups.has(String(child.dataset.layerGroup || ''))) {
                child.remove();
            }
        }

        const candidates = Array.from(panel.querySelectorAll('*')).filter(element => {
            if (!(element instanceof HTMLElement)) return false;
            const text = normalize(element.textContent);
            if (!text.includes(forbiddenLayerLabel)) return false;
            return !Array.from(element.children).some(child => normalize(child.textContent).includes(forbiddenLayerLabel));
        });

        for (const leaf of candidates) {
            let target = leaf.closest('.feo-layer-row,[data-layer-key],li,a,button,[role="button"],.feo-layer-group');
            if (!target) {
                target = leaf;
                while (target.parentElement && !structuralNodes.has(target.parentElement)) target = target.parentElement;
            }
            if (target instanceof HTMLElement && !structuralNodes.has(target) && target.id !== 'feo-map-layer-toggle') target.remove();
        }
    };

    const scheduleGuard = () => {
        if (scheduled) return;
        scheduled = true;
        queueMicrotask(removeInjectedLayerUi);
    };

    new MutationObserver(scheduleGuard).observe(panel, {childList: true, subtree: true});
    document.addEventListener('feo-map-ready', scheduleGuard);
    scheduleGuard();
})();
</script>
<?php if ($yandexMapsUrl !== ''): ?>
<script src="<?= e($yandexMapsUrl) ?>"></script>
<?php endif; ?>
<script src="<?= e($mapJsUrl) ?>"></script>
