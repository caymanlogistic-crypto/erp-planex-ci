(function () {
    'use strict';
    var root = document.querySelector('[data-max-settings-root]');
    if (!root) return;
    var storageKey = 'erp.max.settings.activeTab';
    var allowedTabs = ['overview', 'settings', 'journal'];

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
