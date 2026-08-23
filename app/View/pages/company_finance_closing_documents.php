<?php
$problemCount = count(array_filter($rows ?? [], static fn(array $row): bool => !empty($row['is_problem'])));
?>
<style>
.closing-documents-card{min-width:0;max-width:100%;overflow:hidden}
.closing-documents-table-frame{display:block;width:100%;max-width:100%;min-width:0;overflow:visible}
.closing-documents-table{width:100%!important;max-width:100%!important;min-width:0!important;table-layout:fixed}
.closing-documents-table th,.closing-documents-table td{min-width:0;max-width:0;white-space:normal!important;overflow:visible;text-overflow:clip;overflow-wrap:anywhere;word-break:break-word;vertical-align:top}
.closing-documents-table tbody tr[data-closing-transaction-id]{cursor:pointer}
.closing-toolbar-filters{display:flex;align-items:center;gap:8px;min-width:0}
.closing-problem-filter{display:inline-flex;align-items:center;gap:5px;white-space:nowrap;color:var(--text-muted);font-size:11px;cursor:pointer;user-select:none}
.closing-problem-filter input{margin:0;accent-color:var(--accent)}
.closing-modal-summary{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;margin-bottom:14px}
.closing-modal-summary>div{padding:8px 10px;border:1px solid var(--line-soft);background:var(--surface-form);min-width:0}
.closing-modal-section{margin-top:14px;padding-top:12px;border-top:1px solid var(--line-soft)}
.closing-modal-section h3{margin:0 0 8px;font-size:12px}
@media(max-width:860px){.closing-toolbar-filters{width:100%;flex-wrap:wrap}.closing-modal-summary{grid-template-columns:1fr}}
</style>
<?php if (!empty($successFlash)): ?><div class="notice success"><?= e($successFlash) ?></div><?php endif; ?>
<?php if (!empty($errorFlash)): ?><div class="notice warn"><?= e($errorFlash) ?></div><?php endif; ?>
<?php if (!empty($dbError)): ?><div class="notice warn"><?= e($dbError) ?></div><?php endif; ?>

<div class="page-head">
    <div class="page-head-left">
        <h1 class="page-title">Закрывающие документы</h1>
        <div class="page-summary"><span>Документы по исходящим банковским платежам. Двойной клик по строке открывает управление документами.</span></div>
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
            <label class="closing-problem-filter"><input type="checkbox" data-closing-problem-only>Показывать только проблемные</label>
            <input type="text" class="toolbar-search" data-closing-search placeholder="Поиск по таблице" autocomplete="off">
        </div>
    </div>
    <div class="closing-documents-table-frame">
        <table class="table closing-documents-table">
            <colgroup><col style="width:8%"><col style="width:36%"><col style="width:20%"><col style="width:12%"><col style="width:12%"><col style="width:12%"></colgroup>
            <thead><tr><th>Дата / №</th><th>Платёж</th><th>Получатель</th><th>Тип</th><th>Комплект</th><th>Документы</th></tr></thead>
            <tbody>
            <?php if (empty($rows)): ?><tr data-closing-empty><td colspan="6"><div class="ux-empty"><strong>Нет операций для отображения</strong></div></td></tr><?php endif; ?>
            <?php foreach ($rows as $row):
                $profile = $row['profile'] ?? null;
                $inn = (string)($row['normalized_inn'] ?? '');
                $amount = '-' . number_format((float)($row['debit_amount'] ?? 0), 2, ',', ' ') . ' ₽';
                $searchText = mb_strtolower(implode(' ', [
                    (string)($row['operation_date'] ?? ''),
                    (string)($row['document_number'] ?? ''),
                    (string)($row['purpose'] ?? ''),
                    (string)($row['counterparty_name'] ?? ''),
                    $inn,
                    (string)($profile['label'] ?? ''),
                    $amount,
                ]), 'UTF-8');
            ?>
            <tr data-closing-transaction-id="<?= (int)$row['id'] ?>" data-problem="<?= !empty($row['is_problem']) ? '1' : '0' ?>" data-search="<?= e($searchText) ?>"<?= !empty($row['is_problem']) ? ' class="is-attention"' : '' ?> title="Двойной клик — открыть документы">
                <td class="col-tight"><strong><?= e(date('d.m.Y', strtotime((string)$row['operation_date']))) ?></strong><div class="field-note">№ <?= e((string)($row['document_number'] ?: $row['id'])) ?></div></td>
                <td><strong><?= e($amount) ?></strong><div class="field-note"><?= e((string)($row['purpose'] ?? '')) ?></div></td>
                <td><strong><?= e((string)($row['counterparty_name'] ?: 'Без наименования')) ?></strong><div class="field-note">ИНН <?= e($inn !== '' ? $inn : '—') ?></div></td>
                <td><?php if(!empty($row['is_exempt'])):?><span class="badge badge-success"><span class="dot"></span>Исключение</span><?php else:?><strong><?= e((string)($profile['label'] ?? '—')) ?></strong><?php endif;?></td>
                <td><?php if(!empty($row['is_exempt'])):?><span class="text-muted">Не требуется</span><?php elseif(empty($row['is_problem'])):?><span class="badge badge-success"><span class="dot"></span>Собран</span><?php else:?><span class="badge badge-warning"><span class="dot"></span>Требует действия</span><?php foreach(($row['missing_requirements'] ?? []) as $missing):?><div class="field-note">• <?= e((string)$missing['label']) ?></div><?php endforeach;?><?php endif;?></td>
                <td><?php $visibleLinks = array_values(array_filter($row['links'] ?? [], static fn(array $link): bool => empty($link['document_deleted_at']))); ?><?php if($visibleLinks):?><strong><?= count($visibleLinks) ?></strong> привязано<?php else:?><span class="text-muted">—</span><?php endif;?></td>
            </tr>
            <?php endforeach; ?>
            <tr data-closing-filter-empty hidden><td colspan="6"><div class="ux-empty"><strong>Ничего не найдено</strong>Измените поиск или фильтр проблемных операций.</div></td></tr>
            </tbody>
        </table>
    </div>
</div>

<div id="closing-document-modal" class="modal-overlay" role="dialog" aria-modal="true" data-close-on-overlay="1" data-close-on-escape="1">
    <div class="modal modal-lg">
        <div class="modal-head"><span class="modal-title">Закрывающие документы</span><button type="button" class="modal-close" data-close-modal="closing-document-modal">&times;</button></div>
        <div class="modal-body" id="closing-document-modal-body"></div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var grid = document.querySelector('[data-closing-grid]');
    if (!grid) return;
    var search = grid.querySelector('[data-closing-search]');
    var problemOnly = grid.querySelector('[data-closing-problem-only]');
    var count = grid.querySelector('[data-closing-found]');
    var rows = Array.from(grid.querySelectorAll('tbody tr[data-closing-transaction-id]'));
    var empty = grid.querySelector('[data-closing-filter-empty]');
    var normalize = function (value) { return String(value || '').toLocaleLowerCase('ru-RU').trim(); };
    var applyFilters = function () {
        var query = normalize(search ? search.value : '');
        var onlyProblems = !!(problemOnly && problemOnly.checked);
        var visible = 0;
        rows.forEach(function (row) {
            var matchesSearch = query === '' || normalize(row.dataset.search).includes(query);
            var matchesProblem = !onlyProblems || row.dataset.problem === '1';
            var show = matchesSearch && matchesProblem;
            row.hidden = !show;
            if (show) visible++;
        });
        if (count) count.textContent = String(visible);
        if (empty) empty.hidden = visible !== 0;
    };
    if (search) search.addEventListener('input', applyFilters);
    if (problemOnly) problemOnly.addEventListener('change', applyFilters);
    applyFilters();

    rows.forEach(function (row) {
        row.addEventListener('dblclick', function () {
            var id = parseInt(row.dataset.closingTransactionId || '0', 10);
            if (!Number.isInteger(id) || id <= 0) return;
            var body = document.getElementById('closing-document-modal-body');
            body.innerHTML = '<div class="empty-state compact"><p>Загрузка...</p></div>';
            window.openModal('closing-document-modal');
            fetch(window.getErpBasePath() + '/company/finance/closing-documents/' + id + '/modal-view')
                .then(function (response) { if (!response.ok) throw new Error('HTTP ' + response.status); return response.text(); })
                .then(function (html) { window.planexSetHtmlAndRunScripts(body, html); })
                .catch(function () { body.innerHTML = '<div class="form-alert alert-error">Не удалось загрузить данные платежа.</div>'; });
        });
    });
});
</script>
<?php endif; ?>
