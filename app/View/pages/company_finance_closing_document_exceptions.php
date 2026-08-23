<?php if (!empty($successFlash)): ?><div class="notice success"><?= e($successFlash) ?></div><?php endif; ?>
<?php if (!empty($errorFlash)): ?><div class="notice warn"><?= e($errorFlash) ?></div><?php endif; ?>
<?php if (!empty($dbError)): ?><div class="notice warn"><?= e($dbError) ?></div><?php endif; ?>
<style>
.closing-exception-table tbody tr{cursor:pointer}
.closing-exception-table td{white-space:normal;line-height:1.3}
.closing-exception-table .rule-inn{width:170px}
.closing-exception-table .rule-purpose{width:38%}
.closing-exception-table .rule-comment{width:auto}
.closing-exception-hint{color:var(--text-faint);font-size:10.5px}
.exception-remote-form{display:contents}
</style>
<div class="ux-shell" data-ux-page="closing-document-exceptions">
    <div class="page-head">
        <div class="page-head-left">
            <h1 class="page-title">Исключения по документам</h1>
            <div class="page-summary"><span>Двойной клик по строке открывает редактирование правила.</span></div>
        </div>
        <div class="page-head-actions">
            <?php if (!empty($schemaReady)): ?><button type="button" class="btn btn-primary btn--toolbar" id="closing-exception-create-btn">+ Исключение</button><?php endif; ?>
            <a class="btn btn-secondary btn--toolbar" href="<?= e(app_url('/company/finance/closing-documents')) ?>">Назад к документам</a>
        </div>
    </div>

    <?php if (empty($schemaReady) && empty($dbError)): ?>
        <div class="notice warn"><b>Для правил исключений требуется migration 079.</b></div>
    <?php elseif (!empty($schemaReady)): ?>
        <div class="ux-filter-panel">
            <div class="ux-filter-head">
                <div class="ux-filter-title">Поиск по исключениям</div>
                <div class="closing-exception-hint">ИНН обязателен. Условие по назначению можно оставить пустым — тогда исключается весь ИНН.</div>
            </div>
            <div class="ux-filter-body">
                <div class="ux-filter-field is-search">
                    <label>ИНН, назначение или комментарий</label>
                    <input type="search" class="field-input" id="closing-exception-search" placeholder="Например: 7702070139 или комиссия">
                </div>
            </div>
        </div>

        <div class="table-card table-card--standard">
            <div class="table-toolbar">
                <div class="table-toolbar-left"><strong>Правила, для которых закрывающие документы не требуются</strong></div>
            </div>
            <?php if (empty($exceptions)): ?>
                <div class="ux-empty"><strong>Исключений пока нет</strong>Создайте правило для повторяющихся банковских платежей, по которым закрывающие документы не нужны.</div>
            <?php else: ?>
                <div class="table-scroll">
                    <table class="table closing-exception-table" id="closing-exception-list">
                        <thead><tr><th class="rule-inn">ИНН</th><th class="rule-purpose">Условие</th><th class="rule-comment">Комментарий</th></tr></thead>
                        <tbody>
                        <?php foreach ($exceptions as $rule):
                            $purpose = trim((string) ($rule['purpose_contains'] ?? ''));
                            $condition = $purpose !== '' ? 'Назначение содержит «' . $purpose . '»' : 'Все платежи этого ИНН';
                            $searchText = mb_strtolower((string)$rule['inn'] . ' ' . $condition . ' ' . (string)($rule['comment'] ?? ''), 'UTF-8');
                        ?>
                            <tr data-exception-row data-edit-id="<?= (int)$rule['id'] ?>" data-search="<?= e($searchText) ?>" title="Двойной клик — редактировать">
                                <td class="col-mono"><strong><?= e((string)$rule['inn']) ?></strong></td>
                                <td><?= e($condition) ?></td>
                                <td><?= e(trim((string)($rule['comment'] ?? '')) !== '' ? (string)$rule['comment'] : '—') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="ux-empty ux-hidden" id="closing-exception-empty"><strong>Ничего не найдено</strong>Измените поисковый запрос.</div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<div id="closing-exception-create-modal" class="modal-overlay" role="dialog" aria-modal="true" data-close-on-overlay="0" data-close-on-escape="0">
    <div class="modal modal-lg"><div class="modal-head"><span class="modal-title">Новое исключение</span><button type="button" class="modal-close" data-close-modal="closing-exception-create-modal">&times;</button></div><div class="exception-remote-form" id="closing-exception-create-body"></div></div>
</div>
<div id="closing-exception-edit-modal" class="modal-overlay" role="dialog" aria-modal="true" data-close-on-overlay="0" data-close-on-escape="0">
    <div class="modal modal-lg"><div class="modal-head"><span class="modal-title">Редактирование исключения</span><button type="button" class="modal-close" data-close-modal="closing-exception-edit-modal">&times;</button></div><div class="exception-remote-form" id="closing-exception-edit-body"></div></div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var base = function () { return window.getErpBasePath(); };
    var search = document.getElementById('closing-exception-search');
    var rows = Array.from(document.querySelectorAll('[data-exception-row]'));
    var empty = document.getElementById('closing-exception-empty');
    var normalize = function (value) { return String(value || '').toLocaleLowerCase('ru-RU').trim(); };
    var filter = function () {
        var q = normalize(search ? search.value : '');
        var shown = 0;
        rows.forEach(function (row) {
            var show = !q || normalize(row.dataset.search).includes(q);
            row.classList.toggle('ux-hidden', !show);
            if (show) shown++;
        });
        if (empty) empty.classList.toggle('ux-hidden', shown !== 0);
    };
    if (search) search.addEventListener('input', filter);

    function setRemoteHtml(body, html) {
        body.innerHTML = html;
        Array.from(body.querySelectorAll('script')).forEach(function (oldScript) {
            var newScript = document.createElement('script');
            Array.from(oldScript.attributes).forEach(function (attr) { newScript.setAttribute(attr.name, attr.value); });
            newScript.textContent = oldScript.textContent;
            oldScript.replaceWith(newScript);
        });
    }

    function escapeHtml(value) {
        return String(value || '').replace(/[&<>"']/g, function (ch) {
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch];
        });
    }

    function loadForm(modalId, bodyId, url) {
        var body = document.getElementById(bodyId);
        if (!body) return;
        body.innerHTML = '<div class="modal-body"><div class="empty-state compact"><p>Загрузка...</p></div></div>';
        window.openModal(modalId);
        fetch(base() + url, { credentials: 'same-origin' })
            .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.text(); })
            .then(function (html) { setRemoteHtml(body, html); })
            .catch(function (error) {
                console.error('Closing exception form load failed', error);
                var detail = error && error.message ? ' (' + escapeHtml(error.message) + ')' : '';
                body.innerHTML = '<div class="modal-body"><div class="form-alert alert-error">Не удалось загрузить форму' + detail + '.</div></div>';
            });
    }

    var create = document.getElementById('closing-exception-create-btn');
    if (create) create.addEventListener('click', function () { loadForm('closing-exception-create-modal', 'closing-exception-create-body', '/company/finance/closing-documents/exceptions/create'); });
    rows.forEach(function (row) {
        row.addEventListener('dblclick', function () {
            loadForm('closing-exception-edit-modal', 'closing-exception-edit-body', '/company/finance/closing-documents/exceptions/edit?id=' + encodeURIComponent(row.dataset.editId));
        });
    });
});
</script>
