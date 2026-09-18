(function () {
    'use strict';
    var root = document.querySelector('[data-max-settings-root]');
    if (!root) return;
    var storageKey = 'erp.max.settings.activeTab';
    var allowedTabs = ['overview', 'settings', 'events', 'journal'];

    function setTab(tab, remember) {
        if (allowedTabs.indexOf(tab) === -1) tab = 'overview';
        root.querySelectorAll('[data-max-tab]').forEach(function (link) {
            var active = link.getAttribute('data-max-tab') === tab;
            link.classList.toggle('is-active', active);
            link.setAttribute('aria-current', active ? 'page' : 'false');
        });
        root.querySelectorAll('[data-max-panel]').forEach(function (panel) {
            panel.hidden = panel.getAttribute('data-max-panel') !== tab;
        });
        if (remember !== false) {
            try { window.sessionStorage.setItem(storageKey, tab); } catch (e) {}
        }
    }

    var initial = 'overview';
    var hash = String(window.location.hash || '').replace(/^#max-/, '');
    if (allowedTabs.indexOf(hash) !== -1) initial = hash;
    else {
        try {
            var saved = window.sessionStorage.getItem(storageKey);
            if (allowedTabs.indexOf(saved) !== -1) initial = saved;
        } catch (e) {}
    }
    setTab(initial, false);

    var catalogNode = root.querySelector('#max-event-catalog-data');
    if (catalogNode) {
        var catalog = JSON.parse(catalogNode.textContent);
        var overlay = root.querySelector('#max-event-edit-modal');
        // Keep the system modal out of the panel's overflow and stacking context.
        document.body.appendChild(overlay);
        var form = overlay.querySelector('form');
        var template = form.elements.message_template;
        var variable = form.querySelector('[data-max-event-variable]');
        var previewWrap = form.querySelector('[data-max-event-preview-wrap]');
        var previewButton = form.querySelector('[data-max-event-preview]');
        var activeEvent = null, opener = null, baseline = '', submitting = false, previewRequest = null, previewRevision = 0;
        function snapshot() { return JSON.stringify(Array.from(new FormData(form).entries())); }
        function isDirty() { return activeEvent && snapshot() !== baseline; }
        function invalidatePreview() {
            previewRevision++;
            if (previewRequest) previewRequest.abort();
            previewRequest = null;
            previewWrap.hidden = true;
            previewButton.disabled = false;
        }
        function changed() {
            invalidatePreview();
            form.elements.chat_config_id.required = form.elements.is_enabled.checked;
            form.querySelector('[data-max-event-dirty]').hidden = !isDirty();
        }
        function openEvent(index, button) {
            activeEvent = catalog.rules[index];
            if (!activeEvent) return;
            opener = button;
            form.reset();
            form.elements.event_key.value = activeEvent.event_key;
            ['chat_config_id', 'message_format', 'message_template'].forEach(function (name) {
                form.elements[name].value = activeEvent[name] == null ? '' : String(activeEvent[name]);
            });
            ['is_enabled', 'notify'].forEach(function (name) { form.elements[name].checked = String(activeEvent[name]) === '1'; });
            overlay.querySelector('#max-event-edit-title').textContent = activeEvent.title;
            form.querySelector('[data-max-event-description]').textContent = activeEvent.description;
            form.querySelector('[data-max-event-save-error]').hidden = catalog.draftKey !== activeEvent.event_key;
            variable.replaceChildren();
            Object.keys(activeEvent.variables).forEach(function (key) {
                variable.add(new Option(activeEvent.variables[key][0], '{{' + key + '}}'));
            });
            baseline = snapshot();
            changed();
            overlay.hidden = false;
            overlay.classList.add('is-open');
            overlay.setAttribute('aria-hidden', 'false');
            form.elements.is_enabled.focus();
        }
        function closeEvent() {
            if (isDirty() && !window.confirm('Закрыть без сохранения изменений?')) return;
            invalidatePreview();
            overlay.classList.remove('is-open');
            overlay.setAttribute('aria-hidden', 'true');
            overlay.hidden = true;
            activeEvent = null;
            if (opener) opener.focus();
        }
        root.querySelectorAll('[data-max-event-edit]').forEach(function (button) {
            button.addEventListener('click', function () { openEvent(Number(button.dataset.maxEventEdit), button); });
        });
        overlay.querySelectorAll('[data-max-event-close]').forEach(function (button) { button.addEventListener('click', closeEvent); });
        form.addEventListener('input', changed);
        form.addEventListener('change', changed);
        form.addEventListener('submit', function () {
            submitting = true;
            try { window.sessionStorage.setItem(storageKey, 'events'); } catch (e) {}
            form.querySelector('[type="submit"]').disabled = true;
        });
        window.addEventListener('beforeunload', function (event) {
            if (!submitting && isDirty()) { event.preventDefault(); event.returnValue = ''; }
        });
        // Own this dialog's keyboard handling before the shared document Escape handler.
        window.addEventListener('keydown', function (event) {
            if (!activeEvent || event.isComposing) return;
            if (event.key === 'Escape') {
                event.preventDefault(); event.stopImmediatePropagation(); closeEvent();
            } else if (event.key === 'Tab') {
                var focusable = Array.from(form.querySelectorAll('button,input,select,textarea')).filter(function (el) { return !el.disabled && el.type !== 'hidden' && el.getClientRects().length; });
                var first = focusable[0], last = focusable[focusable.length - 1];
                if (event.shiftKey && (document.activeElement === first || !form.contains(document.activeElement))) { event.preventDefault(); last.focus(); }
                else if (!event.shiftKey && (document.activeElement === last || !form.contains(document.activeElement))) { event.preventDefault(); first.focus(); }
            }
        }, true);
        form.querySelector('[data-max-insert]').addEventListener('click', function () {
            if (!variable.value) return;
            template.setRangeText(variable.value, template.selectionStart, template.selectionEnd, 'end');
            changed(); template.focus();
        });
        form.querySelector('[data-max-event-default]').addEventListener('click', function () {
            if (template.value !== activeEvent.template && !window.confirm('Заменить текущий текст стандартным шаблоном? Изменения вступят в силу после сохранения.')) return;
            template.value = activeEvent.template; changed(); template.focus();
        });
        previewButton.addEventListener('click', async function () {
            invalidatePreview();
            var revision = previewRevision;
            previewRequest = new AbortController();
            previewButton.disabled = true;
            previewWrap.hidden = false;
            var output = form.querySelector('[data-max-event-preview-text]');
            output.textContent = 'Подготовка примера…';
            try {
                var response = await fetch(form.dataset.previewUrl, {method: 'POST', body: new FormData(form), credentials: 'same-origin', headers: {'Accept': 'application/json'}, signal: previewRequest.signal});
                var result = await response.json();
                if (!response.ok || !result.success) throw new Error(result.message || 'Не удалось подготовить пример.');
                if (revision === previewRevision) output.textContent = result.text;
            } catch (error) {
                if (revision === previewRevision && error.name !== 'AbortError') output.textContent = error.message || 'Ошибка предпросмотра.';
            } finally { if (revision === previewRevision) { previewButton.disabled = false; previewRequest = null; } }
        });

        var eventRows = Array.from(root.querySelectorAll('[data-max-event-row]'));
        var eventSearch = root.querySelector('[data-max-event-search]');
        var eventStatus = root.querySelector('[data-max-event-status]');
        var eventChat = root.querySelector('[data-max-event-chat]');
        var currentPage = 0, pageSize = 15;
        function filterEvents(reset) {
            if (reset) currentPage = 0;
            var words = eventSearch.value.trim().toLocaleLowerCase('ru').split(/\s+/).filter(Boolean);
            var rows = eventRows.filter(function (row) {
                return (!eventStatus.value || row.dataset.eventState === eventStatus.value)
                    && (!eventChat.value || row.dataset.eventChat === eventChat.value)
                    && words.every(function (word) { return row.dataset.eventSearch.indexOf(word) !== -1; });
            });
            var pages = Math.max(1, Math.ceil(rows.length / pageSize));
            currentPage = Math.min(currentPage, pages - 1);
            eventRows.forEach(function (row) { row.hidden = true; });
            rows.slice(currentPage * pageSize, (currentPage + 1) * pageSize).forEach(function (row) { row.hidden = false; });
            root.querySelector('[data-max-event-count]').textContent = 'Найдено: ' + rows.length + ' из ' + eventRows.length;
            root.querySelector('[data-max-event-page]').textContent = (currentPage + 1) + ' / ' + pages;
            root.querySelector('[data-max-event-empty]').hidden = rows.length > 0;
            root.querySelector('[data-max-event-prev]').disabled = currentPage === 0;
            root.querySelector('[data-max-event-next]').disabled = currentPage + 1 >= pages;
        }
        eventSearch.addEventListener('input', function () { filterEvents(true); });
        [eventStatus,eventChat].forEach(function (control) { control.addEventListener('change', function () { filterEvents(true); }); });
        root.querySelector('[data-max-event-reset]').addEventListener('click', function () { eventSearch.value = ''; eventStatus.value = ''; eventChat.value = ''; filterEvents(true); });
        root.querySelector('[data-max-event-prev]').addEventListener('click', function () { currentPage--; filterEvents(false); });
        root.querySelector('[data-max-event-next]').addEventListener('click', function () { currentPage++; filterEvents(false); });
        filterEvents(true);
        var draftIndex = catalog.rules.findIndex(function (rule) { return rule.event_key === catalog.draftKey; });
        if (draftIndex !== -1) {
            setTab('events', false);
            openEvent(draftIndex, root.querySelector('[data-max-event-edit="' + draftIndex + '"]'));
        }
    }
    root.querySelectorAll('[data-max-event-retry]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (!window.confirm('Отправить сохранённое уведомление? Если предыдущая попытка завершилась без ответа MAX, проверьте чат, чтобы избежать дубля.')) event.preventDefault();
        });
    });

    root.querySelectorAll('[data-max-tab]').forEach(function (link) {
        link.addEventListener('click', function (event) {
            event.preventDefault();
            var tab = link.getAttribute('data-max-tab') || 'overview';
            setTab(tab, true);
            if (window.history && window.history.replaceState) window.history.replaceState(null, '', '#max-' + tab);
        });
    });
    document.querySelectorAll('[data-max-tab-target]').forEach(function (button) {
        button.addEventListener('click', function () {
            var tab = button.getAttribute('data-max-tab-target') || 'overview';
            setTab(tab, true);
            if (window.history && window.history.replaceState) window.history.replaceState(null, '', '#max-' + tab);
        });
    });
    document.querySelectorAll('[data-max-tab-form]').forEach(function (form) {
        form.addEventListener('submit', function () {
            try { window.sessionStorage.setItem(storageKey, form.getAttribute('data-max-tab-form') || 'overview'); } catch (e) {}
        });
    });

    document.querySelectorAll('[data-max-toggle]').forEach(function (button) {
        button.addEventListener('click', function () {
            var target = document.getElementById(button.getAttribute('data-max-toggle') || '');
            if (!target) return;
            target.hidden = !target.hidden;
            if (target.hidden) return;
            var input = target.querySelector('input:not([type="hidden"]),select,textarea');
            if (input) input.focus();
        });
    });

    document.querySelectorAll('[data-max-toast]').forEach(function (toast) {
        var close = toast.querySelector('[data-max-toast-close]');
        function remove() {
            toast.classList.add('is-removing');
            window.setTimeout(function () { if (toast.parentNode) toast.parentNode.removeChild(toast); }, 220);
        }
        if (close) close.addEventListener('click', remove);
        var timeout = parseInt(toast.getAttribute('data-max-toast-timeout') || '4000', 10);
        window.setTimeout(remove, isFinite(timeout) ? timeout : 4000);
    });

    var logs = [];
    var dataNode = document.getElementById('max-log-details-data');
    if (dataNode) {
        try { logs = JSON.parse(dataNode.textContent || '[]'); } catch (e) { logs = []; }
    }
    var logMap = {};
    logs.forEach(function (row) { logMap[String(row.id)] = row; });

    function readableText(text, format) {
        text = String(text || '');
        if (format === 'html') {
            var tmp = document.createElement('div');
            tmp.innerHTML = text.replace(/<[^>]+>/g, ' ');
            return (tmp.textContent || tmp.innerText || '').replace(/\s+/g, ' ').trim();
        }
        return text
            .replace(/\[([^\]]+)\]\([^\)]+\)/g, '$1')
            .replace(/\*\*|__|~~|\+\+|\^\^|`/g, '')
            .replace(/(^|\s)[#>]+\s*/g, '$1')
            .replace(/\s+/g, ' ')
            .trim();
    }
    function metaRow(label, value) {
        var row = document.createElement('div'); row.className = 'driver-view-row';
        var l = document.createElement('div'); l.className = 'driver-view-cell driver-view-cell-label'; l.textContent = label;
        var v = document.createElement('div'); v.className = 'driver-view-cell driver-view-cell-value'; v.textContent = value == null || value === '' ? '—' : String(value);
        row.appendChild(l); row.appendChild(v); return row;
    }
    function openLog(id) {
        var row = logMap[String(id)];
        if (!row) return;
        var modal = document.getElementById('max-log-detail-modal');
        if (!modal) return;
        var meta = modal.querySelector('[data-max-log-meta]');
        meta.innerHTML = '';
        [
            ['Время', row.created_at], ['Источник', row.source], ['Группа', row.chat_name || row.external_chat_id],
            ['Bot ID', row.bot_id], ['Chat config ID', row.chat_config_id], ['External chat ID', row.external_chat_id],
            ['Формат', row.message_format], ['Push', String(row.notify) === '1' ? 'Да' : 'Нет'], ['Статус', row.status],
            ['MAX message ID', row.max_message_id], ['User ID', row.initiated_by_user_id]
        ].forEach(function (item) { meta.appendChild(metaRow(item[0], item[1])); });
        modal.querySelector('[data-max-log-readable]').textContent = readableText(row.message_text, row.message_format);
        modal.querySelector('[data-max-log-raw]').textContent = String(row.message_text || '');
        var errorWrap = modal.querySelector('[data-max-log-error-wrap]');
        var error = modal.querySelector('[data-max-log-error]');
        error.textContent = String(row.error_message || '');
        errorWrap.hidden = !row.error_message;
        if (typeof window.openModal === 'function') window.openModal('max-log-detail-modal');
    }
    document.querySelectorAll('.max-log-row[data-max-log-id]').forEach(function (row) {
        row.addEventListener('click', function () { openLog(row.getAttribute('data-max-log-id')); });
        row.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); openLog(row.getAttribute('data-max-log-id')); }
        });
    });

    var sourceFilter = root.querySelector('[data-max-log-source]');
    var chatFilter = root.querySelector('[data-max-log-chat]');
    var statusFilter = root.querySelector('[data-max-log-status]');
    var searchFilter = root.querySelector('[data-max-log-search]');
    var logRows = Array.prototype.slice.call(root.querySelectorAll('#max-journal .max-log-row'));
    var countNode = root.querySelector('[data-max-log-count]');
    function filterLogs() {
        var source = sourceFilter ? sourceFilter.value : '';
        var chat = chatFilter ? chatFilter.value : '';
        var status = statusFilter ? statusFilter.value : '';
        var search = searchFilter ? searchFilter.value.trim().toLowerCase() : '';
        var visible = 0;
        logRows.forEach(function (row) {
            var ok = (!source || row.dataset.source === source)
                && (!chat || row.dataset.chat === chat)
                && (!status || row.dataset.status === status)
                && (!search || String(row.dataset.search || '').indexOf(search) !== -1);
            row.hidden = !ok;
            if (ok) visible++;
        });
        if (countNode) countNode.textContent = String(visible);
    }
    [sourceFilter, chatFilter, statusFilter].forEach(function (control) { if (control) control.addEventListener('change', filterLogs); });
    if (searchFilter) searchFilter.addEventListener('input', filterLogs);
})();
