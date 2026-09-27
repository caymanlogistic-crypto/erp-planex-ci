(function () {
    'use strict';
    var modal = document.getElementById('route-executor-delete-modal');
    if (!modal) return;
    var heading = modal.querySelector('[data-route-executor-delete-heading]');
    var summary = modal.querySelector('[data-route-executor-delete-summary]');
    var passwordWrap = modal.querySelector('[data-route-executor-delete-password-wrap]');
    var password = modal.querySelector('#route-executor-delete-password');
    var error = modal.querySelector('[data-route-executor-delete-error]');
    var confirm = modal.querySelector('[data-route-executor-delete-confirm]');
    var currentId = 0;
    var previewToken = '';
    var busy = false;
    var base = window.getErpBasePath ? window.getErpBasePath() : '';
    var csrf = document.querySelector('meta[name="csrf-token"]');

    function setError(message) {
        error.textContent = message || '';
        error.classList.toggle('is-hidden', !message);
    }
    function close() {
        if (busy) return;
        if (window.closeModal) window.closeModal('route-executor-delete-modal');
        else { modal.classList.remove('is-open'); modal.style.display = 'none'; }
        password.value = '';
        previewToken = '';
    }
    async function post(url, fields) {
        var form = new FormData();
        form.append('_csrf_token', csrf ? csrf.content : '');
        Object.keys(fields).forEach(function (key) { form.append(key, fields[key]); });
        var response = await fetch(url, { method: 'POST', body: form, credentials: 'same-origin' });
        var data = await response.json();
        if (!response.ok || !data.success) throw new Error(data.message || 'Не удалось выполнить запрос.');
        return data;
    }
    async function open(id) {
        currentId = id;
        previewToken = '';
        password.value = '';
        passwordWrap.classList.add('is-hidden');
        confirm.disabled = true;
        heading.textContent = 'Проверяем участие в рейсах…';
        summary.textContent = '';
        setError('');
        if (window.openModal) window.openModal('route-executor-delete-modal');
        else { modal.style.display = 'flex'; modal.classList.add('is-open'); }
        try {
            var data = await post(base + '/company/route-executors/' + id + '/archive', {preview: '1'});
            if (currentId !== id || !modal.classList.contains('is-open')) return;
            if (!data.can_delete) {
                var usage = data.usage || {};
                heading.textContent = 'Удаление недоступно';
                summary.textContent = 'Исполнитель участвует в рейсах: линейных — ' + (usage.linear_count || 0) + ', рейсов отходов — ' + (usage.waste_count || 0) + '. Удаление возможно только при отсутствии связей с рейсами.';
                return;
            }
            previewToken = data.preview_token || '';
            heading.textContent = 'Исполнитель рейса будет удалён из списка';
            summary.textContent = 'Подрядчик, водители, транспорт и документы сохранятся. Для подтверждения введите пароль удаления.';
            passwordWrap.classList.remove('is-hidden');
            password.focus();
        } catch (e) {
            heading.textContent = 'Не удалось проверить исполнителя';
            setError(e.message);
        }
    }
    document.addEventListener('click', function (event) {
        var trigger = event.target.closest('[data-route-executor-delete-btn]');
        if (trigger) {
            var id = Number(trigger.getAttribute('data-route-executor-delete-btn'));
            if (id > 0) open(id);
            return;
        }
        if (event.target.closest('[data-route-executor-delete-close]')) close();
    });
    password.addEventListener('input', function () {
        confirm.disabled = !previewToken || !password.value.trim() || busy;
        setError('');
    });
    confirm.addEventListener('click', async function () {
        if (!previewToken || !password.value.trim() || busy) return;
        busy = true;
        confirm.disabled = true;
        setError('');
        try {
            await post(base + '/company/route-executors/' + currentId + '/archive', {
                preview_token: previewToken,
                delete_password: password.value.trim()
            });
            window.location.href = base + '/company/route-executors';
        } catch (e) {
            setError(e.message);
            confirm.disabled = false;
        } finally {
            busy = false;
        }
    });
})();
