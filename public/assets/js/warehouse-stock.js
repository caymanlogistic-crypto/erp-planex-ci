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
            const operation = route === 'warehouse_to_warehouse' ? `Списание со склада «${escape(src)}» и поступление на склад «${escape(dst)}»`
                : route === 'warehouse_to_utilizer' ? `Списание со склада «${escape(src)}»` : `Поступление на склад «${escape(dst)}»`;
            const weight = data.rows.reduce((sum, row) => sum + Number(row.mass_netto || 0) * 1000, 0);
            const shortages = data.stock_warnings || [];
            const absent = shortages.filter(row => row.absent).map(row => `#${escape(row.request_id)}`);
            const partial = shortages.filter(row => !row.absent).map(row => `#${escape(row.request_id)}`);
            const shortageNotice = shortages.length ? `<div class="notice warn" role="alert">
                ${absent.length ? `<p><b>В рейсе есть заявки, которых нет на складе «${escape(src)}»:</b> ${absent.join(', ')}.</p>` : ''}
                ${partial.length ? `<p><b>На складе «${escape(src)}» недостаточно массы по заявкам:</b> ${partial.join(', ')}.</p>` : ''}
                <p>Эти заявки останутся в списке рейса, но не будут списаны со склада. Оператор может проверить их происхождение отдельно.</p>
            </div>` : '';
            const movedWeight = data.rows.filter(row => row.will_move).reduce((sum, row) => sum + Number(row.mass_netto || 0) * 1000, 0);
            preview.innerHTML = `<h3 class="wh-section-title">Предварительная проверка</h3><p><b>${operation}</b> · в рейсе ${escape(data.request_count)} заявок · ${escape(Math.round(weight).toLocaleString('ru-RU'))} кг</p>
                <p>Складское движение: ${escape(data.movement_request_count)} заявок · ${escape(Math.round(movedWeight).toLocaleString('ru-RU'))} кг.</p>
                <p class="wh-correction-ids">${data.rows.map(row => '#'+escape(row.request_id)).join(', ') || '—'}</p>
                ${shortageNotice}
                ${data.errors.length ? `<div class="notice warn" role="alert"><b>Исправление пока недоступно:</b><ul>${data.errors.map(error => `<li>${escape(error)}</li>`).join('')}</ul></div>`
                    : !shortages.length ? `<div class="notice success">Проверки пройдены. Будет создано движений: ${escape(data.operation_count)}.</div>` : ''}`;
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
            preview.innerHTML = `<div class="notice success" role="status"><b>Рейс #${escape(data.flight_id)} исправлен.</b> Проведено складских движений: ${escape(data.created)}. Обновляем остатки…</div>${data.warning ? `<div class="notice warn" role="alert">${escape(data.warning)}</div>` : ''}`;
            setTimeout(() => location.reload(), 1200);
        } catch (error) {
            fingerprint.value = '';
            preview.innerHTML = `<div class="notice warn" role="alert">${escape(error.message)} Повторите предварительную проверку.</div>`;
        }
    });
})();

(function () {
    const csrf = document.querySelector('.wh-correction-modal [name="_csrf_token"]');
    document.querySelectorAll('[data-wh-cancel-correction]').forEach(button => button.addEventListener('click', async () => {
        const flightId = button.dataset.flightId;
        button.disabled = true;
        try {
            const url = button.dataset.previewUrl + '?' + new URLSearchParams({flight_id: flightId});
            const previewResponse = await fetch(url, {credentials: 'same-origin', cache: 'no-store'});
            const preview = await previewResponse.json();
            if (!previewResponse.ok || !preview.ready) throw new Error(preview.error || preview.errors?.join(' ') || 'Отмена недоступна.');
            if (!window.confirm(`Отменить ${preview.movement_count} складских движений рейса #${flightId}? Сам рейс сохранится.`)) return;
            const body = new URLSearchParams({flight_id: flightId, fingerprint: preview.fingerprint});
            if (csrf) body.set(csrf.name, csrf.value);
            const response = await fetch(button.dataset.submitUrl, {method: 'POST', credentials: 'same-origin', body});
            const result = await response.json();
            if (!response.ok) throw new Error(result.error || 'Отмена не выполнена.');
            location.reload();
        } catch (error) {
            window.alert(error.message);
        } finally {
            button.disabled = false;
        }
    }));
})();

(function () {
    const modal = document.querySelector('.wh-match-modal');
    if (!modal) return;
    const preview = modal.querySelector('[data-wh-match-preview]');
    const confirm = modal.querySelector('[data-wh-match-confirm]');
    const csrf = document.querySelector('.wh-correction-modal [name="_csrf_token"]');
    const escape = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
    const format = value => Number(value || 0).toLocaleString('ru-RU');
    let opener = null;
    let parentModal = null;
    let selection = null;
    let fingerprint = '';

    function close() {
        modal.classList.remove('is-open');
        if (parentModal) parentModal.classList.add('is-open');
        else document.body.classList.remove('modal-open');
        opener?.focus();
        parentModal = null;
        fingerprint = '';
    }

    document.querySelectorAll('[data-wh-match-trip]').forEach(button => button.addEventListener('click', async event => {
        event.stopPropagation();
        opener = button;
        parentModal = button.closest('.wh-trip-modal.is-open');
        parentModal?.classList.remove('is-open');
        selection = {flight_id: button.dataset.flightId, warehouse_id: button.dataset.warehouseId, direction: button.dataset.direction};
        fingerprint = '';
        confirm.disabled = true;
        preview.textContent = 'Проверяем состав рейса и последующие складские остатки…';
        modal.classList.add('is-open');
        document.body.classList.add('modal-open');
        modal.querySelector('[data-wh-match-close]').focus();
        try {
            const response = await fetch(modal.dataset.previewUrl + '?' + new URLSearchParams(selection), {credentials:'same-origin', cache:'no-store'});
            const data = await response.json();
            if (!response.ok) throw new Error(data.error || 'Не удалось проверить рейс.');
            const ids = items => items.map(id => '#'+escape(id)).join(', ') || '—';
            preview.innerHTML = `<p><b>Рейс #${escape(data.flight_id)}.</b> Будут изменены только его складские движения. Движения других рейсов сохранятся.</p>
                <ul class="wh-match-list"><li>Сейчас на складе: ${ids(data.before_ids)}.</li><li>В рейсе ERP: ${ids(data.after_ids)}.</li>
                <li>Убрать из этого рейса: ${ids(data.remove_ids)}.</li><li>Добавить: ${ids(data.add_ids)}.</li>
                <li>Пересчитать массу: ${ids(data.change_mass_ids)}.</li></ul>
                <p>Масса движения: ${format(data.before_kg)} → <b>${format(data.after_kg)} кг</b>. Масса каждой заявки берётся из снимка рейса ERP.</p>
                ${data.warnings?.length ? `<div class="notice warn" role="alert"><b>Проверьте связанные рейсы:</b><ul>${data.warnings.map(warning => `<li>${escape(warning)}</li>`).join('')}</ul></div>` : ''}
                ${data.errors.length ? `<div class="notice warn" role="alert"><b>Сверка недоступна:</b><ul>${data.errors.map(error => `<li>${escape(error)}</li>`).join('')}</ul></div>` : ''}`;
            fingerprint = data.ready ? data.fingerprint : '';
            confirm.disabled = !data.ready;
        } catch (error) {
            preview.innerHTML = `<div class="notice warn" role="alert">${escape(error.message)}</div>`;
        }
    }));

    modal.querySelectorAll('[data-wh-match-close]').forEach(button => button.addEventListener('click', close));
    modal.addEventListener('click', event => { if (event.target === modal) close(); });
    document.addEventListener('keydown', event => { if (event.key === 'Escape' && modal.classList.contains('is-open')) close(); });
    confirm.addEventListener('click', async () => {
        if (!fingerprint || !selection) return;
        confirm.disabled = true;
        preview.textContent = 'Сверяем складские движения…';
        try {
            const body = new URLSearchParams({...selection, fingerprint});
            if (csrf) body.set(csrf.name, csrf.value);
            const response = await fetch(modal.dataset.submitUrl, {method:'POST', credentials:'same-origin', body});
            const data = await response.json();
            if (!response.ok) throw new Error(data.error || 'Сверка не выполнена.');
            preview.innerHTML = `<div class="notice success" role="status">Рейс #${escape(data.flight_id)} приведён к составу ERP. Убрано движений: ${escape(data.removed)}; добавлено: ${escape(data.created)}; пересчитано: ${escape(data.updated)}. Обновляем остатки…</div>`;
            setTimeout(() => location.reload(), 1100);
        } catch (error) {
            fingerprint = '';
            preview.innerHTML = `<div class="notice warn" role="alert">${escape(error.message)} Повторите предварительную проверку.</div>`;
        }
    });
})();

(function () {
    const modal = document.querySelector('.wh-exception-modal');
    const opener = document.querySelector('[data-wh-exceptions]');
    if (!modal || !opener) return;
    const csrf = document.querySelector('.wh-correction-modal [name="_csrf_token"]');
    const close = () => {
        modal.classList.remove('is-open');
        document.body.classList.remove('modal-open');
        opener.focus();
    };
    opener.addEventListener('click', () => {
        modal.classList.add('is-open');
        document.body.classList.add('modal-open');
        modal.querySelector('[data-wh-exceptions-close]')?.focus();
    });
    modal.querySelectorAll('[data-wh-exceptions-close]').forEach(button => button.addEventListener('click', close));
    modal.addEventListener('click', event => { if (event.target === modal) close(); });
    document.addEventListener('keydown', event => { if (event.key === 'Escape' && modal.classList.contains('is-open')) close(); });

    async function post(url, fields, button) {
        button.disabled = true;
        try {
            const body = new URLSearchParams(fields);
            if (csrf) body.set(csrf.name, csrf.value);
            const response = await fetch(url, {method: 'POST', credentials: 'same-origin', body});
            const result = await response.json();
            if (!response.ok) throw new Error(result.error || 'Изменение не сохранено.');
            location.reload();
        } catch (error) {
            window.alert(error.message);
            button.disabled = false;
        }
    }
    document.querySelectorAll('[data-wh-exclude]').forEach(button => button.addEventListener('click', () => {
        const message = button.closest('li')?.querySelector('span')?.textContent?.trim() || '';
        if (!window.confirm(`Не считать ошибкой это расхождение рейса #${button.dataset.flightId}?\n\n${message}`)) return;
        post(modal.dataset.excludeUrl, {flight_id: button.dataset.flightId, message_hash: button.dataset.messageHash}, button);
    }));
    modal.querySelectorAll('[data-wh-restore]').forEach(button => button.addEventListener('click', () => {
        post(modal.dataset.restoreUrl, {exception_id: button.dataset.exceptionId}, button);
    }));
})();
