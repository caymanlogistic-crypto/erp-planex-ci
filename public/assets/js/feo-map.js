(() => {
    'use strict';

    const page = document.getElementById('feo-map-page');
    const mapNode = document.getElementById('feo-map');
    const dataNode = document.getElementById('feo-map-data');
    if (!page || !mapNode || !dataNode) return;
    if (typeof window.L === 'undefined') {
        showRuntimeError('Не удалось загрузить компонент карты. Обновите страницу или обратитесь к администратору.');
        page.dataset.mapReady = 'error';
        return;
    }

    const state = {
        payload: parsePayload(dataNode.textContent),
        map: null,
        markerRecords: [],
        visibility: new Map(),
        tileErrors: 0,
    };

    try {
        state.map = L.map(mapNode, {zoomControl: true, preferCanvas: false}).setView([55.75, 37.62], 5);
        const tileUrlTemplate = String(page.dataset.tileUrlTemplate || '').trim();
        if (!tileUrlTemplate) throw new Error('FEO map tile URL template is missing');
        const tiles = L.tileLayer(tileUrlTemplate, {
            maxZoom: 19,
            attribution: '&copy; OpenStreetMap contributors',
        });
        tiles.on('tileerror', () => {
            state.tileErrors += 1;
            if (state.tileErrors >= 3) {
                showRuntimeWarning('Фоновая карта временно недоступна. Маркеры заявок продолжают отображаться.');
            }
        });
        tiles.on('load', () => {
            state.tileErrors = 0;
            showRuntimeWarning('');
        });
        tiles.addTo(state.map);

        initPayload(state.payload, true);
        state.map.on('zoomend', updateOverlapPositions);
        document.getElementById('feo-map-refresh')?.addEventListener('click', refreshData);
        page.dataset.mapReady = '1';
        document.dispatchEvent(new CustomEvent('feo-map-ready', {detail: {markers: state.markerRecords.length}}));
    } catch (error) {
        console.error('FEO map initialization failed', error);
        showRuntimeError('Карта не смогла инициализироваться. Обновите страницу или обратитесь к администратору.');
        page.dataset.mapReady = 'error';
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
            const marker = L.marker(displayLatLng(markerData), {
                icon: markerIcon(markerData),
                keyboard: true,
                riseOnHover: true,
            });
            marker.bindPopup(popupHtml(markerData), {maxWidth: 390});
            const record = {marker, data: markerData, layerKey};
            state.markerRecords.push(record);
            if (isMarkerVisible(record)) marker.addTo(state.map);
        }
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
        for (const layer of layers) {
            const key = String(layer.key || '');
            if (!key) continue;
            const row = document.createElement('label');
            row.className = 'feo-layer-row' + (key === 'available' ? ' is-master' : '');
            row.title = String(layer.label || key);

            const input = document.createElement('input');
            input.type = 'checkbox';
            input.checked = state.visibility.get(key) !== false;
            input.dataset.layerKey = key;
            input.addEventListener('change', () => {
                state.visibility.set(key, input.checked);
                if (key === 'available') {
                    for (const record of state.markerRecords) applyRecordVisibility(record);
                } else {
                    for (const record of state.markerRecords) {
                        if (record.layerKey === key) applyRecordVisibility(record);
                    }
                }
                updateEmptyState();
            });

            const spacer = document.createElement('span');
            spacer.textContent = '';
            const color = document.createElement('span');
            color.className = 'feo-layer-color';
            color.style.backgroundColor = normalizeColor(layer.color_hex);
            const label = document.createElement('span');
            label.className = 'feo-layer-label';
            label.textContent = String(layer.label || key);
            const count = document.createElement('span');
            count.className = 'feo-layer-count';
            count.textContent = String(Number(layer.request_count || 0));

            row.append(input, color, label, count);
            node.appendChild(row);
        }
    }

    function renderDiagnostics(diagnostics) {
        const node = document.getElementById('feo-map-diagnostics');
        if (!node) return;
        node.textContent = '';
        const title = document.createElement('b');
        title.textContent = 'Диагностика';
        node.appendChild(title);
        appendDiagnostic(node, 'Нет координат', diagnostics.missing_coordinates || []);
        appendDiagnostic(node, 'Некорректные координаты', diagnostics.invalid_coordinates || []);
        appendDiagnostic(node, 'Без утилизатора', diagnostics.unmatched_utilizer || []);
        appendDiagnostic(node, 'Утилизатор отключён', diagnostics.inactive_utilizer || []);
    }

    function appendDiagnostic(node, label, ids) {
        if (!Array.isArray(ids) || ids.length === 0) return;
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

    function applyRecordVisibility(record) {
        const present = state.map.hasLayer(record.marker);
        const should = isMarkerVisible(record);
        if (should && !present) record.marker.addTo(state.map);
        if (!should && present) state.map.removeLayer(record.marker);
    }

    function markerIcon(markerData) {
        const utilizer = markerData.utilizer || null;
        const status = String(markerData.classification_status || 'unmatched');
        const color = status === 'matched' && utilizer ? normalizeColor(utilizer.color_hex) : '#64748B';
        const shortName = utilizer ? String(utilizer.short_name || '') : status === 'inactive' ? 'Отключён' : 'Без утилизатора';
        const count = Number(markerData.request_count || 0);
        const mass = massLabel(Number(markerData.total_mass_kg || 0));
        const classes = ['feo-marker'];
        if (status !== 'matched') classes.push('is-diagnostic');
        if (status === 'inactive') classes.push('is-inactive');
        const badges = `${markerData.adr ? '<span class="adr">ADR</span>' : ''}<span>${count}</span>`;
        const html = `<div class="${classes.join(' ')}" style="--marker-color:${escapeAttr(color)}"><div class="feo-marker-main"><span class="feo-marker-mass">${escapeHtml(mass)}</span><span class="feo-marker-name">${escapeHtml(shortName)}</span></div><div class="feo-marker-badges">${badges}</div></div>`;
        return L.divIcon({className: 'feo-div-icon', html, iconSize: [110, 38], iconAnchor: [55, 19], popupAnchor: [0, -21]});
    }

    function popupHtml(markerData) {
        const utilizer = markerData.utilizer || null;
        const status = String(markerData.classification_status || 'unmatched');
        const utilizerLabel = utilizer ? `${utilizer.short_name || ''}${utilizer.full_name && utilizer.full_name !== utilizer.short_name ? ` — ${utilizer.full_name}` : ''}` : 'Не сопоставлен';
        const requests = Array.isArray(markerData.requests) ? markerData.requests : [];
        const requestRows = requests.map(request => {
            return `<div class="feo-popup-request"><b>Заявка ${escapeHtml(String(request.zayavka_id || ''))}</b> · ${escapeHtml(massLabel(Number(request.mass_kg || 0)))}<div>${escapeHtml(String(request.sender || '—'))}</div><div class="feo-popup-address">${escapeHtml(String(request.loading_address || 'Адрес погрузки не указан'))}</div></div>`;
        }).join('');
        return `<div class="feo-popup"><h3>${requests.length > 1 ? `Группа: ${requests.length} заявок` : `Заявка ${escapeHtml(String(requests[0]?.zayavka_id || ''))}`}</h3><div class="feo-popup-meta"><span>Вес</span><b>${escapeHtml(massLabel(Number(markerData.total_mass_kg || 0)))}</b><span>Утилизатор</span><b>${escapeHtml(utilizerLabel)}</b><span>ADR</span><b>${markerData.adr ? 'Да' : 'Нет'}</b><span>Состояние</span><b>${escapeHtml(statusLabel(status))}</b><span>Источник</span><b>Размещение на карте</b></div><div class="feo-popup-list">${requestRows}</div></div>`;
    }

    function displayLatLng(markerData) {
        const canonical = L.latLng(Number(markerData.canonical_lat), Number(markerData.canonical_lng));
        const count = Math.max(1, Number(markerData.overlap_count || 1));
        const index = Math.max(0, Number(markerData.overlap_index || 0));
        if (count <= 1) return canonical;
        const zoom = state.map ? state.map.getZoom() : 10;
        const point = state.map.project(canonical, zoom);
        const angle = (Math.PI * 2 * index / count) - Math.PI / 2;
        const radius = 22 + Math.floor(index / 8) * 10;
        return state.map.unproject(point.add([Math.cos(angle) * radius, Math.sin(angle) * radius]), zoom);
    }

    function updateOverlapPositions() {
        for (const record of state.markerRecords) record.marker.setLatLng(displayLatLng(record.data));
    }

    function fitMarkers() {
        const visible = state.markerRecords.filter(isMarkerVisible);
        if (visible.length === 0) return;
        const bounds = L.latLngBounds(visible.map(record => [Number(record.data.canonical_lat), Number(record.data.canonical_lng)]));
        if (bounds.isValid()) state.map.fitBounds(bounds.pad(0.12), {maxZoom: 12});
    }

    function clearMarkers() {
        for (const record of state.markerRecords) {
            if (state.map.hasLayer(record.marker)) state.map.removeLayer(record.marker);
        }
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
        if (!url || button?.disabled) return;
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

    function showRuntimeError(message) {
        const node = document.getElementById('feo-map-runtime-error');
        if (!node) return;
        const text = String(message || '').trim();
        node.textContent = text;
        node.hidden = text === '';
    }

    function showRuntimeWarning(message) {
        const node = document.getElementById('feo-map-runtime-warning');
        if (!node) return;
        const text = String(message || '').trim();
        node.textContent = text;
        node.hidden = text === '';
    }

    function massLabel(kg) {
        const value = Number.isFinite(kg) ? kg : 0;
        if (value >= 1000) return `${formatNumber(value / 1000, value >= 10000 ? 1 : 2)} т`;
        return `${formatNumber(value, value >= 100 ? 0 : 1)} кг`;
    }

    function formatNumber(value, maxFraction) {
        return new Intl.NumberFormat('ru-RU', {maximumFractionDigits: maxFraction}).format(value);
    }

    function statusLabel(status) {
        if (status === 'matched') return 'Доступна';
        if (status === 'inactive') return 'Доступна · утилизатор отключён';
        return 'Доступна · утилизатор не определён';
    }

    function normalizeColor(value) {
        const text = String(value || '').toUpperCase();
        return /^#[0-9A-F]{6}$/.test(text) ? text : '#64748B';
    }

    function setText(id, value) {
        const node = document.getElementById(id);
        if (node) node.textContent = String(value);
    }

    function escapeHtml(value) {
        return String(value).replace(/[&<>'"]/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[char]));
    }

    function escapeAttr(value) {
        return String(value).replace(/[^#A-Za-z0-9_-]/g, '');
    }
})();
