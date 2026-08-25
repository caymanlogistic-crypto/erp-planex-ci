<?php
$problemCount = count(array_filter($rows ?? [], static fn(array $row): bool => !empty($row['is_problem'])));
$renderRequirementStatus = static function (array $row, string $key): string {
    if (!empty($row['is_exempt'])) {
        return '<span class="text-muted" title="Для операции действует исключение">—</span>';
    }
    $status = $row['requirement_statuses'][$key] ?? null;
    if (!is_array($status)) {
        return '<span class="text-muted">—</span>';
    }
    if (!empty($status['exempt_for_inn'])) {
        return '<span class="text-muted" title="Документ не требуется для этого ИНН">—</span>';
    }
    if (empty($status['applicable'])) {
        return '<span class="text-muted">—</span>';
    }
    $source = (string)($status['source'] ?? '');
    if ($source === 'edo') {
        return '<span class="closing-doc-edo" title="Документ получен по ЭДО" aria-label="ЭДО">ЭДО</span>';
    }
    if ($source === 'manual') {
        return '<span class="table-check closing-doc-status closing-doc-status--manual" title="Документ отмечен как собранный вручную" aria-label="Собран вручную">✓</span>';
    }
    if (!empty($status['satisfied'])) {
        return '<span class="table-check table-check--yes closing-doc-status" title="Документ загружен" aria-label="Документ есть">✓</span>';
    }
    return '<span class="table-check table-check--no closing-doc-status" title="Документ не загружен" aria-label="Документа нет">×</span>';
};
$requiresFill = static function (array $row): bool {
    if (!empty($row['is_exempt'])) return false;
    foreach (($row['requirement_statuses'] ?? []) as $status) {
        if (is_array($status) && !empty($status['applicable'])) return true;
    }
    return false;
};
?>
<style>
.closing-documents-card{min-width:0;max-width:100%;overflow:hidden}
.closing-documents-table-frame{display:block;width:100%;max-width:100%;min-width:0;overflow:visible}
.closing-documents-table{width:100%!important;max-width:100%!important;min-width:0!important;table-layout:fixed}
.closing-documents-table th,.closing-documents-table td{min-width:0;max-width:0;white-space:normal!important;overflow:visible;text-overflow:clip;overflow-wrap:anywhere;word-break:break-word;vertical-align:top}
.closing-documents-table th.col-check,.closing-documents-table td.col-check{text-align:center;vertical-align:middle!important;padding-left:2px;padding-right:2px}
.closing-documents-table tbody tr[data-closing-transaction-id]{cursor:pointer}
.closing-documents-table tbody tr.is-problem>td{background:#fff1f1}
.closing-documents-table tbody tr.is-problem:hover>td{background:#fde7e7}
.closing-toolbar-filters{display:flex;align-items:center;gap:8px;min-width:0}
.closing-mode-select{height:30px;min-width:190px;padding:0 26px 0 9px;border:1px solid var(--line-soft);border-radius:4px;background:var(--surface);color:var(--text-main);font-size:11px}
.closing-download-btn{white-space:nowrap}
.closing-doc-status{display:inline-flex;align-items:center;justify-content:center;width:1em;height:1em;font-size:24px;font-weight:700;line-height:1;white-space:nowrap}
.closing-doc-status.table-check--yes{color:#2f7d32!important}
.closing-doc-status.table-check--no{color:#b3261e!important}
.closing-doc-status--manual{color:#2f6fb0!important}
.closing-doc-edo{display:inline-flex;align-items:center;justify-content:center;font-size:11px;font-weight:800;letter-spacing:.04em;color:#2f6fb0;white-space:nowrap}
.closing-document-modal-shell .modal-body{padding:0}
.closing-document-layout{display:grid;grid-template-columns:minmax(0,1fr) 350px;min-height:360px}
.closing-document-main{padding:16px 18px;min-width:0}
.closing-document-docs{padding:16px;border-left:1px solid var(--line-soft);background:var(--surface-form);min-width:0}
.closing-payment-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px 14px;margin-bottom:14px}
.closing-payment-field{min-width:0}
.closing-payment-value{font-size:12px;font-weight:600;overflow-wrap:anywhere}
.closing-purpose{margin:0 0 14px;padding:9px 10px;border:1px solid var(--line-soft);background:var(--surface-form);font-size:11px;line-height:1.45;overflow-wrap:anywhere}
.closing-status-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}
.closing-status-card{padding:9px 10px;border:1px solid var(--line-soft);background:var(--surface-form);min-width:0}
.closing-status-card .field-note{margin-bottom:5px}
.closing-status-card-head{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:7px}
.closing-status-option{display:flex;align-items:flex-start;gap:6px;font-size:11px;line-height:1.25;color:var(--text-main);cursor:pointer;margin-top:6px}
.closing-status-option input{margin:1px 0 0;accent-color:var(--accent)}
.closing-requirement-actions{display:flex;justify-content:flex-end;margin-top:8px}
.closing-document-docs .section-title{margin-bottom:8px}
.closing-document-docs .file-list{margin-bottom:12px}
.closing-document-docs .file-item{min-width:0}
.closing-document-docs .file-info{min-width:0}
.closing-document-docs .file-meta{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.closing-doc-form{display:grid;gap:7px;margin-top:11px;padding-top:11px;border-top:1px solid var(--line-soft)}
.closing-doc-form .field-select,.closing-doc-form .field-input{width:100%;min-width:0}
.closing-file-unlink{margin-left:auto;flex:0 0 auto}
.closing-edo-option{margin:0}
.closing-archive-modal .modal-body{display:grid;gap:12px}
.closing-archive-result{padding:10px;border:1px solid var(--line-soft);background:var(--surface-form)}
.closing-archive-result[hidden]{display:none}
.closing-archive-download-link{display:inline-flex;margin-top:7px}
@media(max-width:980px){.closing-document-layout{grid-template-columns:1fr}.closing-document-docs{border-left:0;border-top:1px solid var(--line-soft)}.closing-status-grid{grid-template-columns:1fr}.closing-toolbar-filters{width:100%;flex-wrap:wrap}.closing-mode-select{min-width:180px}}
@media(max-width:680px){.closing-payment-grid{grid-template-columns:1fr}.closing-mode-select,.closing-toolbar-filters .toolbar-search,.closing-download-btn{width:100%}}
</style>
<?php if (!empty($successFlash)): ?><div class="notice success"><?= e($successFlash) ?></div><?php endif; ?>
<?php if (!empty($errorFlash)): ?><div class="notice warn"><?= e($errorFlash) ?></div><?php endif; ?>
<?php if (!empty($dbError)): ?><div class="notice warn"><?= e($dbError) ?></div><?php endif; ?>

<div class="page-head">
    <div class="page-head-left">
        <h1 class="page-title">Закрывающие документы</h1>
        <div class="page-summary"><span>Документы по входящим и исходящим банковским платежам. Двойной клик по строке открывает управление документами.</span></div>
    </div>
    <div class="page-head-actions">
        <a class="btn btn-secondary" href="<?= e(app_url('/company/finance/closing-documents/exceptions')) ?>">Исключения по документам</a>
    </div>
</div>

<?php if (empty($schemaReady) && empty($dbError)): ?>
<div class="notice warn"><b>Для раздела требуется актуальная tenant schema.</b></div>
<?php elseif (!empty($schemaReady)): ?>
<div class="table-card table-card--standard closing-documents-card" data-closing-grid>
    <div class="table-toolbar">
        <div class="found-label">Найдено: <b data-closing-found><?= count($rows) ?></b> из <b><?= count($rows) ?></b> операций · проблемных <b><?= $problemCount ?></b></div>
        <div class="toolbar-right closing-toolbar-filters">
            <select class="closing-mode-select" data-closing-mode data-closing-problem-only aria-label="Режим отображения закрывающих документов">
                <option value="all">Показать все</option>
                <option value="requires_fill" selected>Только требующие заполнения</option>
                <option value="problems">Проблемные</option>
            </select>
            <input type="text" class="toolbar-search" data-closing-search placeholder="Поиск по таблице" autocomplete="off">
            <button type="button" class="btn btn-secondary btn-sm closing-download-btn" data-closing-download>Скачать документы</button>
        </div>
    </div>
    <div class="closing-documents-table-frame">
        <table class="table closing-documents-table">
            <colgroup><col style="width:8%"><col style="width:42%"><col style="width:20%"><col style="width:10%"><col style="width:7%"><col style="width:7%"><col style="width:6%"></colgroup>
            <thead><tr><th>Дата / №</th><th>Платёж</th><th>Контрагент</th><th>Тип</th><th class="col-check">Договор / Заявка</th><th class="col-check">Акт / УПД</th><th class="col-check">Чек НПД</th></tr></thead>
            <tbody>
            <?php if (empty($rows)): ?><tr data-closing-empty><td colspan="7"><div class="ux-empty"><strong>Нет операций для отображения</strong></div></td></tr><?php endif; ?>
            <?php foreach ($rows as $row):
                $profile = $row['profile'] ?? null;
                $inn = (string)($row['normalized_inn'] ?? '');
                $isIncoming = (string)($row['direction'] ?? 'outgoing') === 'incoming';
                $rawAmount = $isIncoming ? (float)($row['credit_amount'] ?? 0) : (float)($row['debit_amount'] ?? 0);
                $amount = ($isIncoming ? '+' : '-') . number_format($rawAmount, 2, ',', ' ') . ' ₽';
                $directionLabel = $isIncoming ? 'Входящий' : 'Исходящий';
                $searchText = mb_strtolower(implode(' ', [
                    (string)($row['operation_date'] ?? ''),
                    (string)($row['document_number'] ?? ''),
                    (string)($row['purpose'] ?? ''),
                    (string)($row['counterparty_name'] ?? ''),
                    $inn,
                    (string)($profile['label'] ?? ''),
                    $directionLabel,
                    $amount,
                ]), 'UTF-8');
            ?>
            <tr data-closing-transaction-id="<?= (int)$row['id'] ?>" data-problem="<?= !empty($row['is_problem']) ? '1' : '0' ?>" data-requires-fill="<?= $requiresFill($row) ? '1' : '0' ?>" data-search="<?= e($searchText) ?>"<?= !empty($row['is_problem']) ? ' class="is-problem"' : '' ?> title="Двойной клик — открыть документы">
                <td class="col-tight"><strong><?= e(date('d.m.Y', strtotime((string)$row['operation_date']))) ?></strong><div class="field-note">№ <?= e((string)($row['document_number'] ?: $row['id'])) ?></div></td>
                <td><strong><?= e($amount) ?></strong><div class="field-note"><?= e($directionLabel) ?> · <?= e((string)($row['purpose'] ?? '')) ?></div></td>
                <td><strong><?= e((string)($row['counterparty_name'] ?: 'Без наименования')) ?></strong><div class="field-note">ИНН <?= e($inn !== '' ? $inn : '—') ?></div></td>
                <td><?php if(!empty($row['is_exempt'])):?><span class="text-muted">—</span><?php else:?><strong><?= e((string)($profile['label'] ?? '—')) ?></strong><?php endif;?></td>
                <td class="col-check"><?= $renderRequirementStatus($row, 'agreement_or_application') ?></td>
                <td class="col-check"><?= $renderRequirementStatus($row, 'act_or_upd') ?></td>
                <td class="col-check"><?= $renderRequirementStatus($row, 'npd_receipt') ?></td>
            </tr>
            <?php endforeach; ?>
            <tr data-closing-filter-empty hidden><td colspan="7"><div class="ux-empty"><strong>Ничего не найдено</strong>Измените поиск или режим отображения.</div></td></tr>
            </tbody>
        </table>
    </div>
</div>

<div id="closing-document-modal" class="modal-overlay" role="dialog" aria-modal="true" data-close-on-overlay="1" data-close-on-escape="1">
    <div class="modal modal-lg closing-document-modal-shell">
        <div class="modal-head"><span class="modal-title">Закрывающие документы</span><button type="button" class="modal-close" data-close-modal="closing-document-modal">&times;</button></div>
        <div class="modal-body" id="closing-document-modal-body"></div>
        <div class="modal-foot is-spaced">
            <div></div>
            <div class="modal-foot-actions"><button type="button" class="btn btn-ghost" data-close-modal="closing-document-modal">Закрыть</button></div>
        </div>
    </div>
</div>

<div id="closing-archive-modal" class="modal-overlay" role="dialog" aria-modal="true" data-close-on-overlay="1" data-close-on-escape="1">
    <div class="modal closing-archive-modal">
        <div class="modal-head"><span class="modal-title">Скачать документы</span><button type="button" class="modal-close" data-close-modal="closing-archive-modal">&times;</button></div>
        <div class="modal-body">
            <div data-closing-archive-confirm-text>Будет подготовлен архив документов по отображаемым операциям.</div>
            <div class="field-note">В архив попадут только уже загруженные файлы из строк, которые сейчас видны с учётом поиска и фильтра.</div>
            <div class="closing-archive-result" data-closing-archive-result hidden></div>
        </div>
        <div class="modal-foot is-spaced">
            <button type="button" class="btn btn-ghost" data-close-modal="closing-archive-modal">Отмена</button>
            <button type="button" class="btn btn-primary" data-closing-archive-confirm>Подготовить архив</button>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var grid = document.querySelector('[data-closing-grid]');
    if (!grid) return;
    var search = grid.querySelector('[data-closing-search]');
    var mode = grid.querySelector('[data-closing-mode]');
    var downloadButton = grid.querySelector('[data-closing-download]');
    var count = grid.querySelector('[data-closing-found]');
    var rows = Array.from(grid.querySelectorAll('tbody tr[data-closing-transaction-id]'));
    var empty = grid.querySelector('[data-closing-filter-empty]');
    var archiveConfirm = document.querySelector('[data-closing-archive-confirm]');
    var archiveText = document.querySelector('[data-closing-archive-confirm-text]');
    var archiveResult = document.querySelector('[data-closing-archive-result]');
    var archiveObjectUrl = null;
    var normalize = function (value) { return String(value || '').toLocaleLowerCase('ru-RU').trim(); };
    var visibleRows = function () { return rows.filter(function (row) { return !row.hidden; }); };
    var applyFilters = function () {
        var query = normalize(search ? search.value : '');
        var selectedMode = mode ? mode.value : 'requires_fill';
        var visible = 0;
        rows.forEach(function (row) {
            var matchesSearch = query === '' || normalize(row.dataset.search).includes(query);
            var matchesMode = selectedMode === 'all'
                || (selectedMode === 'problems' && row.dataset.problem === '1')
                || (selectedMode === 'requires_fill' && row.dataset.requiresFill === '1');
            var show = matchesSearch && matchesMode;
            row.hidden = !show;
            if (show) visible++;
        });
        if (count) count.textContent = String(visible);
        if (empty) empty.hidden = visible !== 0;
    };
    if (search) search.addEventListener('input', applyFilters);
    if (mode) mode.addEventListener('change', applyFilters);
    applyFilters();

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

    if (downloadButton) {
        downloadButton.addEventListener('click', function () {
            var currentRows = visibleRows();
            if (archiveObjectUrl) { URL.revokeObjectURL(archiveObjectUrl); archiveObjectUrl = null; }
            if (archiveResult) { archiveResult.hidden = true; archiveResult.innerHTML = ''; }
            if (archiveText) archiveText.textContent = 'Будет подготовлен архив документов по ' + currentRows.length + ' отображаемым операциям.';
            if (archiveConfirm) archiveConfirm.disabled = currentRows.length === 0;
            window.openModal('closing-archive-modal');
        });
    }

    if (archiveConfirm) {
        archiveConfirm.addEventListener('click', function () {
            var currentRows = visibleRows();
            if (currentRows.length === 0) return;
            archiveConfirm.disabled = true;
            archiveConfirm.textContent = 'Подготовка...';
            if (archiveResult) { archiveResult.hidden = false; archiveResult.textContent = 'Собираем архив...'; }
            var formData = new FormData();
            currentRows.forEach(function (row) { formData.append('transaction_ids[]', row.dataset.closingTransactionId || ''); });
            fetch(window.getErpBasePath() + '/company/finance/closing-documents/archive', {
                method: 'POST', credentials: 'same-origin', body: formData
            }).then(function (response) {
                if (!response.ok) return response.text().then(function (text) { throw new Error(text || ('HTTP ' + response.status)); });
                var files = response.headers.get('X-Planex-Archive-Files') || '';
                return response.blob().then(function (blob) { return {blob: blob, files: files}; });
            }).then(function (result) {
                if (archiveObjectUrl) URL.revokeObjectURL(archiveObjectUrl);
                archiveObjectUrl = URL.createObjectURL(result.blob);
                var fileText = result.files ? 'Файлов в архиве: ' + escapeHtml(result.files) + '. ' : '';
                if (archiveResult) {
                    archiveResult.hidden = false;
                    archiveResult.innerHTML = '<strong>Архив готов.</strong><div class="field-note">' + fileText + 'Структура разбита по контрагентам и платежам.</div><a class="btn btn-primary btn-sm closing-archive-download-link" data-closing-archive-link download="closing-documents.zip">Скачать архив</a>';
                    var link = archiveResult.querySelector('[data-closing-archive-link]');
                    if (link) link.href = archiveObjectUrl;
                }
            }).catch(function (error) {
                if (archiveResult) {
                    archiveResult.hidden = false;
                    archiveResult.innerHTML = '<div class="form-alert alert-error">Не удалось подготовить архив: ' + escapeHtml(error && error.message ? error.message : 'неизвестная ошибка') + '</div>';
                }
            }).finally(function () {
                archiveConfirm.disabled = false;
                archiveConfirm.textContent = 'Подготовить архив';
            });
        });
    }

    rows.forEach(function (row) {
        row.addEventListener('dblclick', function () {
            var id = parseInt(row.dataset.closingTransactionId || '0', 10);
            if (!Number.isInteger(id) || id <= 0) return;
            var body = document.getElementById('closing-document-modal-body');
            body.innerHTML = '<div class="empty-state compact"><p>Загрузка...</p></div>';
            window.openModal('closing-document-modal');
            fetch(window.getErpBasePath() + '/company/finance/closing-documents/' + id + '/modal-view', { credentials: 'same-origin' })
                .then(function (response) { if (!response.ok) throw new Error('HTTP ' + response.status); return response.text(); })
                .then(function (html) { setRemoteHtml(body, html); })
                .catch(function (error) {
                    console.error('Closing payment modal load failed', error);
                    var detail = error && error.message ? ' (' + escapeHtml(error.message) + ')' : '';
                    body.innerHTML = '<div class="form-alert alert-error">Не удалось загрузить данные платежа' + detail + '.</div>';
                });
        });
    });
});
</script>
<?php endif; ?>
