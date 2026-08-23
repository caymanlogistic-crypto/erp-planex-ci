<?php if (!empty($successFlash)): ?><div class="notice success"><?= e($successFlash) ?></div><?php endif; ?>
<?php if (!empty($errorFlash)): ?><div class="notice warn"><?= e($errorFlash) ?></div><?php endif; ?>
<?php if (!empty($dbError)): ?><div class="notice warn"><?= e($dbError) ?></div><?php endif; ?>
<style>
.closing-exception-toolbar{display:flex;align-items:center;gap:8px;min-width:0}
.closing-recipient-results{position:relative}
.closing-recipient-help{margin-top:4px}
</style>
<div class="ux-shell" data-ux-page="closing-document-exceptions">
    <div class="page-head">
        <div class="page-head-left"><h1 class="page-title">Исключения по документам</h1><div class="page-summary">Найдите получателя по наименованию из банковской выписки или введите ИНН вручную. Для выбранного ИНН документы требоваться не будут.</div></div>
        <div class="page-head-actions"><a class="btn btn-secondary" href="<?= e(app_url('/company/finance/closing-documents')) ?>">Назад к документам</a></div>
    </div>
    <?php if (empty($schemaReady) && empty($dbError)): ?><div class="notice warn"><b>Для настроек требуется актуальная tenant schema.</b></div><?php elseif (!empty($schemaReady)): ?>
    <div class="table-card table-card--standard">
        <div class="table-toolbar"><div class="table-toolbar-left"><strong>Добавить исключение</strong></div></div>
        <form method="post" action="<?= e(app_url('/company/finance/closing-documents/exceptions/add')) ?>" class="ux-filter-body" data-exception-add-form>
            <div class="ux-filter-field is-search closing-recipient-results">
                <label>Получатель</label>
                <input class="field-input" type="text" data-recipient-search list="closing-recipient-options" placeholder="Начните вводить наименование" autocomplete="off">
                <datalist id="closing-recipient-options">
                    <?php foreach(($recipientOptions ?? []) as $recipient): ?><option value="<?= e((string)$recipient['name']) ?>" data-inn="<?= e((string)$recipient['inn']) ?>"><?= e((string)$recipient['inn']) ?></option><?php endforeach; ?>
                </datalist>
                <div class="field-note closing-recipient-help" data-recipient-hint>При выборе получателя ИНН заполнится автоматически.</div>
            </div>
            <div class="ux-filter-field"><label>ИНН</label><input class="field-input" name="inn" data-recipient-inn inputmode="numeric" maxlength="20" placeholder="ИНН" required></div>
            <div class="ux-filter-field is-search"><label>Комментарий</label><input class="field-input" name="comment" maxlength="255" placeholder="Например: банковские комиссии"></div>
            <div class="ux-filter-field"><label>&nbsp;</label><button class="btn btn-primary" type="submit">+ Добавить</button></div>
        </form>
    </div>

    <div class="table-card table-card--standard" data-exception-grid>
        <div class="table-toolbar">
            <div class="found-label">Найдено: <b data-exception-found><?= count($exceptions) ?></b> из <b><?= count($exceptions) ?></b> исключений</div>
            <div class="toolbar-right closing-exception-toolbar"><input type="text" class="toolbar-search" data-exception-search placeholder="Поиск по наименованию или ИНН" autocomplete="off"></div>
        </div>
        <?php if(empty($exceptions)):?><div class="ux-empty"><strong>Исключений пока нет</strong>По всем исходящим платежам действуют обычные правила документов.</div><?php else:?><div class="table-scroll"><table class="table"><thead><tr><th>ИНН</th><th>Получатель из выписки</th><th>Комментарий</th><th></th></tr></thead><tbody>
        <?php foreach($exceptions as $rule): $searchText=mb_strtolower(((string)($rule['counterparty_name'] ?? '')).' '.((string)$rule['inn']).' '.((string)($rule['comment'] ?? '')), 'UTF-8'); ?><tr data-exception-row data-search="<?= e($searchText) ?>"><td class="col-mono"><strong><?= e((string)$rule['inn']) ?></strong></td><td><?= e((string)($rule['counterparty_name'] ?: '—')) ?></td><td><?= e((string)($rule['comment'] ?: '—')) ?></td><td class="col-tight"><form method="post" action="<?= e(app_url('/company/finance/closing-documents/exceptions/remove')) ?>" onsubmit="return confirm('Удалить исключение? Документы по этому ИНН снова будут требоваться.');"><input type="hidden" name="id" value="<?= (int)$rule['id'] ?>"><button class="btn btn-ghost btn-sm" type="submit">Удалить</button></form></td></tr><?php endforeach;?>
        <tr data-exception-empty hidden><td colspan="4"><div class="ux-empty"><strong>Ничего не найдено</strong>Измените поисковый запрос.</div></td></tr>
        </tbody></table></div><?php endif;?>
    </div>
    <?php endif;?>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var recipientSearch = document.querySelector('[data-recipient-search]');
    var recipientInn = document.querySelector('[data-recipient-inn]');
    var options = Array.from(document.querySelectorAll('#closing-recipient-options option'));
    var normalize = function (value) { return String(value || '').toLocaleLowerCase('ru-RU').trim(); };
    var fillInn = function () {
        if (!recipientSearch || !recipientInn) return;
        var value = normalize(recipientSearch.value);
        if (!value) return;
        var exact = options.find(function (option) { return normalize(option.value) === value; });
        if (exact && exact.dataset.inn) recipientInn.value = exact.dataset.inn;
    };
    if (recipientSearch) {
        recipientSearch.addEventListener('change', fillInn);
        recipientSearch.addEventListener('input', function () {
            var value = normalize(recipientSearch.value);
            var exact = options.find(function (option) { return normalize(option.value) === value; });
            if (exact && exact.dataset.inn && recipientInn) recipientInn.value = exact.dataset.inn;
        });
    }

    var grid = document.querySelector('[data-exception-grid]');
    if (!grid) return;
    var search = grid.querySelector('[data-exception-search]');
    var count = grid.querySelector('[data-exception-found]');
    var rows = Array.from(grid.querySelectorAll('[data-exception-row]'));
    var empty = grid.querySelector('[data-exception-empty]');
    var apply = function () {
        var query = normalize(search ? search.value : '');
        var visible = 0;
        rows.forEach(function (row) {
            var show = query === '' || normalize(row.dataset.search).includes(query);
            row.hidden = !show;
            if (show) visible++;
        });
        if (count) count.textContent = String(visible);
        if (empty) empty.hidden = visible !== 0;
    };
    if (search) search.addEventListener('input', apply);
    apply();
});
</script>
