(() => {
    'use strict';

    const REQUEST_MARKER_BODY_WIDTH = 50;
    const REQUEST_MARKER_BODY_HEIGHT = 40;
    const REQUEST_MARKER_GLOW_PAD = 8;
    const REQUEST_MARKER_WIDTH = REQUEST_MARKER_BODY_WIDTH + REQUEST_MARKER_GLOW_PAD * 2;
    const REQUEST_MARKER_HEIGHT = REQUEST_MARKER_BODY_HEIGHT + REQUEST_MARKER_GLOW_PAD * 2;
    const REQUEST_MARKER_STACK_STEP = 36;
    const REQUEST_MARKER_ADR_EXTRA_GAP = 6;
    const DEFAULT_UTILIZER_MAIN = '#6C757D';
    const DEFAULT_UTILIZER_TEXT = '#FFFFFF';
    const DEFAULT_PRE_FLIGHT_STROKE = '#000000';

    const page = document.getElementById('feo-map-page');
    const mapNode = document.getElementById('feo-map');
    const workspace = document.getElementById('feo-map-workspace');
    const dataNode = document.getElementById('feo-map-data');
    if (!page || !mapNode || !workspace || !dataNode) return;

    const state = {
        payload: parsePayload(dataNode.textContent),
        map: null,
        markerRecords: [],
        visibility: new Map(),
        selectedKeys: new Set(),
        resizeObserver: null,
        resizeFrame: 0,
    };

    document.getElementById('feo-map-refresh')?.addEventListener('click', refreshData);
    document.getElementById('feo-map-layer-toggle')?.addEventListener('click', toggleLayerPanel);

    if (page.dataset.yandexConfigured !== '1') {
        showRuntimeError('Для карты не настроен YANDEX_MAPS_API_KEY.');
        page.dataset.mapReady = 'error';
        return;
    }
    if (typeof window.ymaps === 'undefined' || typeof window.ymaps.ready !== 'function') {
        showRuntimeError('Не удалось загрузить Яндекс.Карты. Обновите страницу или обратитесь к администратору.');
        page.dataset.mapReady = 'error';
        return;
    }

    window.ymaps.ready(initMap);

    function initMap() {
        try {
            state.map = new ymaps.Map('feo-map', {
                center: [55.751574, 37.573856],
                zoom: 5,
                controls: ['zoomControl', 'fullscreenControl'],
            }, {
                suppressMapOpenBlock: true,
            });
            initPayload(state.payload, true);
            initResizeHandling();
            installDiagnosticsSnapshot();
            page.dataset.mapEngine = 'yandex';
            page.dataset.mapReady = '1';
            document.dispatchEvent(new CustomEvent('feo-map-ready', {detail: {markers: state.markerRecords.length, engine: 'yandex'}}));
        } catch (error) {
            console.error('FEO map initialization failed', error);
            showRuntimeError('Карта не смогла инициализироваться. Обновите страницу или обратитесь к администратору.');
            page.dataset.mapReady = 'error';
        }
    }

    function parsePayload(raw) {
        try {
            const parsed = JSON.parse(raw || '{}');
            return {
                markers: Array.isArray(parsed.markers) ? parsed.markers : [],
                layers: Array.isArray(parsed.layers) ? parsed.layers : [],
                diagnostics: parsed.diagnostics && typeof parsed.diagnostics === 'object' ? parsed.diagnostics : {},
            };
        } catch (error) {
            console.error('FEO map payload parse failed', error);
            return {markers: [], layers: [], diagnostics: {}};
        }
    }

    function initPayload(payload, fit) {
        clearMarkers();
        state.payload = payload;
        syncVisibility(payload.layers);
        renderLayers(payload.layers);
        renderDiagnostics(payload.diagnostics);
        renderSummary(payload.diagnostics);

        for (const markerData of payload.markers) {
            const layerKey = markerLayerKey(markerData);
            const marker = new ymaps.Placemark(
                canonicalCoordinates(markerData),
                {
                    balloonContent: balloonHtml(markerData),
                    hintContent: markerHint(markerData),
                },
                {
                    iconLayout: 'default#image',
                    iconImageHref: transparentImage(),
                    iconImageSize: [REQUEST_MARKER_WIDTH, REQUEST_MARKER_HEIGHT],
                    iconImageOffset: [-(REQUEST_MARKER_GLOW_PAD + 25), markerOffsetY(0)],
                    balloonOffset: [0, -32],
                    visible: false,
                    zIndex: 500,
                    zIndexHover: 900,
                    hideIconOnBalloonOpen: false,
                }
            );
            const record = {marker, data: markerData, layerKey, shiftY: 0, hideTriangle: false};
            marker.events.add('click', () => toggleRecordSelection(record));
            state.markerRecords.push(record);
            state.map.geoObjects.add(marker);
        }

        pruneSelection();
        updateStackPositions();
        updateEmptyState();
        if (fit) fitMarkers();
    }

    function syncVisibility(layers) {
        const next = new Map();
        for (const layer of layers) {
            const key = String(layer.key || '');
            if (!key) continue;
            const previous = state.visibility.get(key);
            next.set(key, previous === undefined ? layer.is_active !== false : previous);
        }
        if (!next.has('available')) next.set('available', true);
        state.visibility = next;
    }

    function renderLayers(layers) {
        const node = document.getElementById('feo-layer-list');
        if (!node) return;
        node.textContent = '';

        const groups = [
            {key: 'requests', label: 'Заявки', layers: layers.filter(layer => String(layer.key || '') === 'available')},
            {key: 'utilizers', label: 'Утилизаторы', layers: layers.filter(layer => String(layer.kind || '') === 'utilizer')},
            {key: 'diagnostics', label: 'Диагностика', layers: layers.filter(layer => String(layer.kind || '') === 'diagnostic')},
        ];

        for (const group of groups) {
            if (group.layers.length === 0) continue;
            const section = document.createElement('section');
            section.className = 'feo-layer-group';
            section.dataset.layerGroup = group.key;
            const title = document.createElement('div');
            title.className = 'feo-layer-group-title';
            title.textContent = group.label;
            section.appendChild(title);
            for (const layer of group.layers) section.appendChild(layerRow(layer));
            node.appendChild(section);
        }
    }

    function layerRow(layer) {
        const key = String(layer.key || '');
        const row = document.createElement('label');
        row.className = 'feo-layer-row' + (key === 'available' ? ' is-master' : '');
        row.title = String(layer.label || key);

        const input = document.createElement('input');
        input.type = 'checkbox';
        input.checked = state.visibility.get(key) !== false;
        input.dataset.layerKey = key;
        input.addEventListener('change', () => {
            state.visibility.set(key, input.checked);
            updateStackPositions();
            updateEmptyState();
        });

        const label = document.createElement('span');
        label.className = 'feo-layer-label';
        label.textContent = String(layer.label || key);
        const count = document.createElement('span');
        count.className = 'feo-layer-count';
        count.textContent = `[${Number(layer.request_count || 0)}]`;
        const color = document.createElement('span');
        color.className = 'feo-layer-color';
        color.style.backgroundColor = normalizeColor(layer.color_hex);

        row.append(input, label, count, color);
        return row;
    }

    function renderDiagnostics(diagnostics) {
        const node = document.getElementById('feo-map-diagnostics');
        if (!node) return;
        node.textContent = '';
        const problems = [
            ['Нет координат', diagnostics.missing_coordinates || []],
            ['Некорректные координаты', diagnostics.invalid_coordinates || []],
            ['Без утилизатора', diagnostics.unmatched_utilizer || []],
            ['Утилизатор отключён', diagnostics.inactive_utilizer || []],
        ].filter(([, ids]) => Array.isArray(ids) && ids.length > 0);
        if (problems.length === 0) return;
        const title = document.createElement('b');
        title.textContent = 'Проблемы данных';
        node.appendChild(title);
        for (const [label, ids] of problems) appendDiagnostic(node, label, ids);
    }

    function appendDiagnostic(node, label, ids) {
        const details = document.createElement('details');
        const summary = document.createElement('summary');
        summary.textContent = `${label}: ${ids.length}`;
        const body = document.createElement('div');
        body.className = 'feo-diagnostic-ids';
        body.textContent = ids.join(', ');
        details.append(summary, body);
        node.appendChild(details);
    }

    function renderSummary(diagnostics) {
        setText('feo-map-request-count', diagnostics.marker_request_count || 0);
        setText('feo-map-marker-count', diagnostics.marker_count || 0);
        setText('feo-map-coordinate-problems', Number(diagnostics.missing_coordinates_count || 0) + Number(diagnostics.invalid_coordinates_count || 0));
        setText('feo-map-classification-problems', Number(diagnostics.unmatched_utilizer_count || 0) + Number(diagnostics.inactive_utilizer_count || 0));
    }

    function markerLayerKey(markerData) {
        const status = String(markerData.classification_status || 'unmatched');
        if (status === 'matched' && markerData.utilizer_id) return `utilizer:${markerData.utilizer_id}`;
        if (status === 'inactive' && markerData.utilizer_id) return `inactive:${markerData.utilizer_id}`;
        return 'unmatched';
    }

    function isMarkerVisible(record) {
        return state.visibility.get('available') !== false && state.visibility.get(record.layerKey) !== false;
    }

    function updateStackPositions() {
        const visibleByCoordinate = new Map();
        for (const record of state.markerRecords) {
            if (!isMarkerVisible(record)) {
                record.marker.options.set('visible', false);
                continue;
            }
            const key = coordinateKey(record.data);
            if (!visibleByCoordinate.has(key)) visibleByCoordinate.set(key, []);
            visibleByCoordinate.get(key).push(record);
        }

        for (const records of visibleByCoordinate.values()) {
            records.sort((a, b) => Number(a.data.overlap_index || 0) - Number(b.data.overlap_index || 0));
            let shiftY = 0;
            records.forEach((record, position) => {
                if (position > 0) {
                    const lower = records[position - 1];
                    shiftY += REQUEST_MARKER_STACK_STEP + (lower.data.adr ? REQUEST_MARKER_ADR_EXTRA_GAP : 0);
                }
                record.shiftY = shiftY;
                record.hideTriangle = position > 0;
                refreshRecordMarker(record);
                record.marker.options.set('visible', true);
            });
        }
    }

    function refreshRecordMarker(record) {
        record.marker.options.set({
            iconImageHref: requestMarkerDataUri(record.data, record.hideTriangle, state.selectedKeys.has(String(record.data.key || ''))),
            iconImageSize: [REQUEST_MARKER_WIDTH, REQUEST_MARKER_HEIGHT],
            iconImageOffset: [-(REQUEST_MARKER_GLOW_PAD + 25), markerOffsetY(record.shiftY)],
            balloonOffset: [0, -32 - record.shiftY],
        });
    }

    function toggleRecordSelection(record) {
        const key = String(record.data.key || '');
        if (!key) return;
        if (state.selectedKeys.has(key)) state.selectedKeys.delete(key);
        else state.selectedKeys.add(key);
        refreshRecordMarker(record);
    }

    function pruneSelection() {
        const live = new Set(state.markerRecords.map(record => String(record.data.key || '')).filter(Boolean));
        for (const key of state.selectedKeys) {
            if (!live.has(key)) state.selectedKeys.delete(key);
        }
    }

    function requestMarkerDataUri(markerData, hideTriangle, isSelected) {
        const svg = buildRequestMarkerSvg(markerData, hideTriangle, isSelected);
        return 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg);
    }

    function buildRequestMarkerSvg(markerData, hideTriangle, isSelected) {
        const weightInKg = Math.round(Number(markerData.total_mass_kg || 0));
        const strokeColor = isSelected ? '#1f73ff' : DEFAULT_PRE_FLIGHT_STROKE;
        const fillColor = markerBackground(weightInKg, isSelected);
        const weightTextColor = isSelected ? '#174ea6' : '#000000';
        const strokeWidth = isSelected ? 2.6 : 2;
        const utilizer = markerData.utilizer || null;
        const ribbonColor = utilizer ? normalizeColor(utilizer.color_hex) : DEFAULT_UTILIZER_MAIN;
        const ribbonTextColor = DEFAULT_UTILIZER_TEXT;
        const rawUtilizerLabel = utilizer ? String(utilizer.short_name || '') : 'БЕЗ';
        const utilizerLabel = escapeSvgText(compactUtilizerLabel(rawUtilizerLabel));
        const clipId = 'request-marker-v2-clip';
        const glowId = 'request-marker-v2-glow';
        const p = REQUEST_MARKER_GLOW_PAD;
        const bodyPath = hideTriangle
            ? 'M2 5.5 Q2 1 6.5 1 H43.5 Q48 1 48 5.5 V29 Q48 33 43.5 33 H6.5 Q2 33 2 29 Z'
            : 'M2 5.5 Q2 1 6.5 1 H43.5 Q48 1 48 5.5 V29 Q48 33 43.5 33 H29 L25 39 L21 33 H6.5 Q2 33 2 29 Z';

        let svg = `<svg width="${REQUEST_MARKER_WIDTH}" height="${REQUEST_MARKER_HEIGHT}" viewBox="0 0 ${REQUEST_MARKER_WIDTH} ${REQUEST_MARKER_HEIGHT}" xmlns="http://www.w3.org/2000/svg">`;
        svg += `<defs><clipPath id="${clipId}"><path d="${bodyPath}"/></clipPath>`;
        if (isSelected) {
            svg += `<filter id="${glowId}" x="-70%" y="-70%" width="240%" height="240%"><feGaussianBlur stdDeviation="3.4" result="blur"/><feFlood flood-color="#1976ff" flood-opacity="1"/><feComposite in2="blur" operator="in"/><feMerge><feMergeNode/><feMergeNode in="SourceGraphic"/></feMerge></filter>`;
        }
        svg += `</defs><g transform="translate(${p} ${p})">`;
        if (isSelected) {
            svg += `<path d="${bodyPath}" fill="none" stroke="#1976ff" stroke-width="8.5" stroke-linejoin="round" opacity="0.7" filter="url(#${glowId})"/>`;
        }
        svg += `<path d="${bodyPath}" fill="${fillColor}" stroke="none"/>`;
        svg += `<g clip-path="url(#${clipId})"><rect x="2" y="1" width="46" height="15" fill="${ribbonColor}"/></g>`;
        svg += `<path d="${bodyPath}" fill="none" stroke="${strokeColor}" stroke-width="${strokeWidth}" stroke-linejoin="round"/>`;
        svg += `<line x1="2" y1="16" x2="48" y2="16" stroke="${strokeColor}" stroke-width="${strokeWidth}"/>`;
        if (utilizerLabel) {
            svg += `<text x="5.3" y="11.4" font-family="Arial,sans-serif" font-size="8" font-weight="700" fill="${ribbonTextColor}" text-anchor="start">${utilizerLabel}</text>`;
        }
        svg += `<text x="25" y="28.6" font-family="Arial,sans-serif" font-size="10.5" font-weight="700" fill="${weightTextColor}" text-anchor="middle">${weightInKg}</text>`;
        if (markerData.adr) {
            svg += '<g><rect x="34.5" y="-5" width="14.5" height="8" rx="1.6" fill="#FFC107" stroke="#111111" stroke-width="1"/>';
            svg += '<text x="41.75" y="0.45" text-anchor="middle" font-family="Arial,sans-serif" font-size="5" font-weight="700" fill="#111111">ADR</text></g>';
        }
        svg += '</g></svg>';
        return svg;
    }

    function markerBackground(weightInKg, isSelected) {
        if (isSelected) return '#f1f6ff';
        if (weightInKg > 10000) return '#fd4221';
        if (weightInKg > 5000) return '#FF7400';
        if (weightInKg > 3000) return '#FFA459';
        if (weightInKg > 1000) return '#FFCBA1';
        return '#ffffff';
    }

    function markerOffsetY(shiftY) {
        return -(REQUEST_MARKER_GLOW_PAD + 39) - shiftY;
    }

    function compactUtilizerLabel(value) {
        const raw = normalizeUtilizerKey(value);
        if (!raw) return '';
        if (raw === 'ЭКО+') return raw;
        return Array.from(raw).slice(0, 3).join('');
    }

    function normalizeUtilizerKey(value) {
        return String(value || '').trim().toUpperCase().replace(/Ё/g, 'Е');
    }

    function markerHint(markerData) {
        const count = Number(markerData.request_count || 0);
        const mass = Math.round(Number(markerData.total_mass_kg || 0));
        return count > 1 ? `Группа: ${mass} кг` : `Заявка: ${mass} кг`;
    }

    function balloonHtml(markerData) {
        const requests = Array.isArray(markerData.requests) ? markerData.requests : [];
        const first = requests[0] || {};
        const totalWeightKg = Math.round(Number(markerData.total_mass_kg || 0));
        const utilizer = markerData.utilizer || null;
        const utilizerLabel = utilizer
            ? `${String(utilizer.short_name || '')}${utilizer.full_name && utilizer.full_name !== utilizer.short_name ? ` — ${String(utilizer.full_name)}` : ''}`
            : 'Не сопоставлен';
        let html = '<div class="feo-yandex-balloon">';
        if (requests.length > 1) {
            html += `<h4>Заявки <span class="feo-group-badge">${requests.length} шт.</span></h4>`;
            html += `<div class="feo-balloon-row"><span>Общий вес:</span><strong>${totalWeightKg} кг</strong></div>`;
            html += `<div class="feo-balloon-row"><span>Грузоотправитель:</span>${escapeHtml(String(first.sender || 'Не указан'))}</div>`;
            html += `<div class="feo-balloon-row"><span>Адрес:</span>${escapeHtml(String(first.loading_address || 'Не указан'))}</div>`;
            html += `<div class="feo-balloon-row"><span>Утилизатор:</span><strong>${escapeHtml(utilizerLabel)}</strong></div>`;
            html += `<div class="feo-balloon-row"><span>ADR:</span>${markerData.adr ? 'Да' : 'Нет'}</div>`;
            html += '<div class="feo-balloon-points">';
            for (const request of requests) {
                html += `<div class="feo-balloon-point"><span>#${escapeHtml(String(request.zayavka_id || ''))}</span><strong>${Math.round(Number(request.mass_kg || 0))} кг</strong></div>`;
            }
            html += '</div>';
        } else {
            html += `<h4>Заявка #${escapeHtml(String(first.zayavka_id || ''))}</h4>`;
            html += `<div class="feo-balloon-row"><span>Вес:</span><strong>${totalWeightKg} кг</strong></div>`;
            html += `<div class="feo-balloon-row"><span>Грузоотправитель:</span>${escapeHtml(String(first.sender || 'Не указан'))}</div>`;
            html += `<div class="feo-balloon-row"><span>Адрес:</span>${escapeHtml(String(first.loading_address || 'Не указан'))}</div>`;
            html += `<div class="feo-balloon-row"><span>Утилизатор:</span><strong>${escapeHtml(utilizerLabel)}</strong></div>`;
            html += `<div class="feo-balloon-row"><span>ADR:</span>${markerData.adr ? 'Да' : 'Нет'}</div>`;
            html += `<div class="feo-balloon-row"><span>Состояние:</span>${escapeHtml(statusLabel(String(markerData.classification_status || 'unmatched')))}</div>`;
        }
        html += '</div>';
        return html;
    }

    function fitMarkers() {
        const visible = state.markerRecords.filter(isMarkerVisible);
        if (visible.length === 0) return;
        const points = visible.map(record => canonicalCoordinates(record.data));
        if (points.length === 1) {
            state.map.setCenter(points[0], 12, {checkZoomRange: true});
            return;
        }
        const lats = points.map(point => point[0]);
        const lngs = points.map(point => point[1]);
        state.map.setBounds([
            [Math.min(...lats), Math.min(...lngs)],
            [Math.max(...lats), Math.max(...lngs)],
        ], {checkZoomRange: true, zoomMargin: [40, 320, 40, 40]});
    }

    function clearMarkers() {
        if (!state.map) return;
        for (const record of state.markerRecords) state.map.geoObjects.remove(record.marker);
        state.markerRecords = [];
    }

    function updateEmptyState() {
        const empty = document.getElementById('feo-map-empty');
        if (!empty) return;
        empty.hidden = state.markerRecords.some(isMarkerVisible);
    }

    async function refreshData() {
        const button = document.getElementById('feo-map-refresh');
        const url = page.dataset.refreshUrl || '';
        if (!url || button?.disabled || !state.map) return;
        if (button) button.disabled = true;
        try {
            showRuntimeError('');
            const response = await fetch(url, {headers: {'Accept': 'application/json'}, credentials: 'same-origin'});
            const json = await response.json();
            if (!response.ok || !json.success) throw new Error(json.message || 'Не удалось обновить карту.');
            initPayload({markers: json.markers || [], layers: json.layers || [], diagnostics: json.diagnostics || {}}, false);
            fitMarkers();
        } catch (error) {
            showRuntimeError(error instanceof Error ? error.message : 'Не удалось обновить карту.');
        } finally {
            if (button) button.disabled = false;
        }
    }

    function initResizeHandling() {
        const fit = () => {
            if (!state.map) return;
            if (state.resizeFrame) cancelAnimationFrame(state.resizeFrame);
            state.resizeFrame = requestAnimationFrame(() => {
                state.resizeFrame = 0;
                state.map.container.fitToViewport();
            });
        };
        if (typeof ResizeObserver !== 'undefined') {
            state.resizeObserver = new ResizeObserver(fit);
            state.resizeObserver.observe(workspace);
        }
        window.addEventListener('resize', fit, {passive: true});
        fit();
    }

    function installDiagnosticsSnapshot() {
        window.__FEO_MAP_STAGE1__ = Object.freeze({
            snapshot: () => ({
                engine: 'yandex',
                markerRenderer: 'legacy-request-marker-v2',
                markerBody: [REQUEST_MARKER_BODY_WIDTH, REQUEST_MARKER_BODY_HEIGHT],
                markerImage: [REQUEST_MARKER_WIDTH, REQUEST_MARKER_HEIGHT],
                stackStep: REQUEST_MARKER_STACK_STEP,
                adrExtraGap: REQUEST_MARKER_ADR_EXTRA_GAP,
                markers: state.markerRecords.map((record, index) => ({
                    index,
                    key: String(record.data.key || ''),
                    layerKey: record.layerKey,
                    adr: !!record.data.adr,
                    visible: isMarkerVisible(record),
                    selected: state.selectedKeys.has(String(record.data.key || '')),
                    shiftY: record.shiftY,
                    hideTriangle: record.hideTriangle,
                    offset: record.marker.options.get('iconImageOffset'),
                })),
            }),
        });
    }

    function toggleLayerPanel() {
        const panel = document.getElementById('feo-map-layer-panel');
        const button = document.getElementById('feo-map-layer-toggle');
        if (!panel || !button) return;
        const collapsed = panel.classList.toggle('is-collapsed');
        button.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        button.textContent = collapsed ? '+' : '−';
        button.title = collapsed ? 'Развернуть панель' : 'Свернуть панель';
        setTimeout(() => state.map?.container.fitToViewport(), 0);
    }

    function canonicalCoordinates(markerData) {
        return [Number(markerData.canonical_lat), Number(markerData.canonical_lng)];
    }

    function coordinateKey(markerData) {
        return `${Number(markerData.canonical_lat).toFixed(7)}|${Number(markerData.canonical_lng).toFixed(7)}`;
    }

    function transparentImage() {
        return 'data:image/gif;base64,R0lGODlhAQABAAD/ACwAAAAAAQABAAACADs=';
    }

    function showRuntimeError(message) {
        const node = document.getElementById('feo-map-runtime-error');
        if (!node) return;
        const text = String(message || '').trim();
        node.textContent = text;
        node.hidden = text === '';
    }

    function statusLabel(status) {
        if (status === 'matched') return 'Доступна';
        if (status === 'inactive') return 'Доступна · утилизатор отключён';
        return 'Доступна · утилизатор не определён';
    }

    function normalizeColor(value) {
        const text = String(value || '').toUpperCase();
        return /^#[0-9A-F]{6}$/.test(text) ? text : DEFAULT_UTILIZER_MAIN;
    }

    function setText(id, value) {
        const node = document.getElementById(id);
        if (node) node.textContent = String(value);
    }

    function escapeHtml(value) {
        return String(value).replace(/[&<>'"]/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[char]));
    }

    function escapeSvgText(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }
})();
