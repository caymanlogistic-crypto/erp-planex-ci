(function () {
    const page = document.querySelector('.wh-page');
    const form = page?.querySelector('.wh-filter');
    if (!form) return;

    const warehouse = form.querySelector('[name="warehouse"]');
    const operation = form.querySelector('[name="operation"]');
    const search = form.querySelector('[name="q"]');
    const showAll = form.querySelector('[name="show_all"]');
    const count = page.querySelector('[data-wh-count]');
    const empty = page.querySelector('[data-wh-empty]');

    function applyFilters() {
        const query = search.value.trim().replace(/^#/, '').toLocaleLowerCase('ru');
        let visible = 0;
        page.querySelectorAll('[data-wh-warehouse]').forEach(card => {
            const showWarehouse = (warehouse.value === '0' || warehouse.value === card.dataset.whWarehouse)
                && (showAll.checked || card.dataset.whHasStock === '1');
            let rows = 0;
            card.querySelectorAll('[data-wh-trip]').forEach(row => {
                row.hidden = !showWarehouse || (operation.value !== '' && operation.value !== row.dataset.whDirection)
                    || (query !== '' && !row.dataset.whSearch.includes(query));
                if (!row.hidden) rows++;
            });
            card.hidden = rows === 0;
            visible += rows;
        });
        count.textContent = String(visible);
        empty.hidden = visible !== 0;
    }

    form.addEventListener('submit', event => { event.preventDefault(); applyFilters(); });
    search.addEventListener('input', applyFilters);
    [warehouse, operation, showAll].forEach(control => control.addEventListener('change', applyFilters));
    applyFilters();
})();

(function () {
    const modal = document.querySelector('.wh-correction-modal');
    const opener = document.querySelector('[data-wh-correct]');
    if (!modal || !opener) return;
    const form = modal.querySelector('[data-correction-form]');
    const preview = modal.querySelector('[data-correction-preview]');
    const info = modal.querySelector('[data-correction-info]');
    const confirm = modal.querySelector('[data-correction-confirm]');
    const fingerprint = form.querySelector('[name="fingerprint"]');
    const escape = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
    let pending = null;
    let timer = null;

    function close() {
        modal.classList.remove('is-open');
        document.body.classList.remove('modal-open');
        if (pending) pending.abort();
        opener.focus();
    }

    async function loadPreview() {
        clearTimeout(timer);
        if (pending) pending.abort();
        fingerprint.value = '';
        confirm.disabled = true;
        const route = form.querySelector('[name="route_type"]:checked').value;
        form.querySelector('[data-correction-source-wrap]').hidden = route === 'generator_to_warehouse';
        form.querySelector('[data-correction-destination-wrap]').hidden = route === 'warehouse_to_utilizer';
        const id = form.querySelector('[name="flight_id"]').value.trim().replace(/^#/, '');
        if (!/^\d+$/.test(id)) {
            info.textContent = id ? 'Введите числовой ID рейса.' : 'Укажите рейс для проверки.';
            preview.textContent = '';
            return;
        }
        preview.textContent = 'Проверка рейса и складского остатка…';
        const query = new URLSearchParams({
            flight_id: id, route_type: route,
            source_warehouse_id: form.querySelector('[name="source_warehouse_id"]').value,
            destination_warehouse_id: form.querySelector('[name="destination_warehouse_id"]').value,
        });
        const controller = new AbortController();
        pending = controller;
        try {
            const response = await fetch(modal.dataset.whCorrectionPreviewUrl + '?' + query, {
                credentials: 'same-origin', cache: 'no-store', signal: controller.signal,
            });
            const data = await response.json();
            if (pending !== controller) return;
            if (!response.ok) throw new Error(data.error || 'Проверка не выполнена.');
            info.innerHTML = `<b>#${escape(data.flight_id)}</b> · ${escape(data.name || 'Без наименования')}<br>Статус: ${escape(data.status)} · Заявок: ${escape(data.request_count)}<br>Сейчас: ${escape(data.current_route)}`;
            const src = data.warehouses[data.source_warehouse_id] || '—';
            const dst = data.warehouses[data.destination_warehouse_id] || '—';
            const operation = route === 'warehouse_to_warehouse' ? `Списание со склада «${escape(src)}» и приход на склад «${escape(dst)}»`
                : route === 'warehouse_to_utilizer' ? `Списание со склада «${escape(src)}»` : `Приход на склад «${escape(dst)}»`;
            const weight = data.rows.reduce((sum, row) => sum + Number(row.mass_netto || 0) * 1000, 0);
            preview.innerHTML = `<h3 class="wh-section-title">Предварительная проверка</h3><p><b>${operation}</b> · ${escape(data.request_count)} заявок · ${escape(Math.round(weight).toLocaleString('ru-RU'))} кг</p>
                <p class="wh-correction-ids">${data.rows.map(row => '#'+escape(row.request_id)).join(', ') || '—'}</p>
                ${data.errors.length ? `<div class="notice warn" role="alert"><b>Исправление пока недоступно:</b><ul>${data.errors.map(error => `<li>${escape(error)}</li>`).join('')}</ul></div>`
                    : `<div class="notice success">Проверки пройдены. Будет создано движений: ${escape(data.operation_count)}.</div>`}`;
            fingerprint.value = data.ready ? data.fingerprint : '';
            confirm.disabled = !data.ready;
        } catch (error) {
            if (error.name === 'AbortError') return;
            preview.innerHTML = `<div class="notice warn" role="alert">${escape(error.message)}</div>`;
        } finally {
            if (pending === controller) pending = null;
        }
    }

    opener.addEventListener('click', () => {
        modal.classList.add('is-open');
        document.body.classList.add('modal-open');
        form.querySelector('[name="flight_id"]').focus();
        loadPreview();
    });
    modal.querySelectorAll('[data-correction-close]').forEach(button => button.addEventListener('click', close));
    document.addEventListener('keydown', event => { if (event.key === 'Escape' && modal.classList.contains('is-open')) close(); });
    form.addEventListener('submit', event => event.preventDefault());
    form.querySelector('[name="flight_id"]').addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(loadPreview, 180); });
    form.querySelectorAll('select,input[type="radio"]').forEach(control => control.addEventListener('change', loadPreview));
    confirm.addEventListener('click', async () => {
        if (confirm.disabled || !fingerprint.value) return;
        confirm.disabled = true;
        preview.textContent = 'Проводим исправление…';
        try {
            const response = await fetch(modal.dataset.whCorrectionSubmitUrl, {
                method: 'POST', credentials: 'same-origin',
                body: new URLSearchParams(new FormData(form)),
            });
            const data = await response.json();
            if (!response.ok) throw new Error(data.error || 'Исправление не выполнено.');
            preview.innerHTML = `<div class="notice success" role="status"><b>Рейс #${escape(data.flight_id)} исправлен.</b> Создано складских движений: ${escape(data.created)}. Обновляем остатки…</div>${data.warning ? `<div class="notice warn" role="alert">${escape(data.warning)}</div>` : ''}`;
            setTimeout(() => location.reload(), 1200);
        } catch (error) {
            fingerprint.value = '';
            preview.innerHTML = `<div class="notice warn" role="alert">${escape(error.message)} Повторите предварительную проверку.</div>`;
        }
    });
})();
