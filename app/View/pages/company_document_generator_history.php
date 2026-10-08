<style>
.docgen-history-check{cursor:pointer;align-items:center}
.docgen-history-check input:focus-visible+.checkbox-mark{outline:2px solid var(--accent-deep);outline-offset:2px}
</style>
<div class="page-head">
    <div class="page-head-left">
        <h1 class="page-title">История сформированных документов</h1>
        <div class="page-summary"><span>Сохранённые DOCX и immutable snapshot использованных данных.</span></div>
    </div>
    <div class="page-head-actions">
        <a class="btn btn-primary" href="<?= app_url('/company/document-generator') ?>">Создать документ</a>
        <?php if (($_SESSION['role_code'] ?? '') === 'company_owner'): ?><a class="btn btn-ghost" href="<?= app_url('/company/document-generator/templates') ?>">Шаблоны</a><?php endif; ?>
    </div>
</div>

<?php if ($flash): ?><div class="notice success"><?= e((string)$flash) ?></div><?php endif; ?>
<?php if ($formError): ?><div class="notice warn"><?= e($formError) ?></div><?php endif; ?>

<?php if ($history === []): ?>
<div class="panel">
    <div class="panel-body">
        <div class="empty-state">
            <p class="empty-title">Сформированных документов пока нет.</p>
            <p class="empty-desc">Создайте документ — после формирования он появится здесь вместе с сохранённым снимком данных.</p>
        </div>
    </div>
</div>
<?php else: ?>
<form method="post" action="<?= app_url('/company/document-generator/history/download') ?>" data-document-archive-form>
    <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
<div class="table-card table-card--standard" data-erp-grid>
    <div class="table-toolbar">
        <div class="found-label">Найдено: <b><?= count($history) ?></b> документов</div>
        <div class="toolbar-right">
            <span class="text-muted" data-document-selected-count>Выбрано: 0</span>
            <button class="btn btn-primary" type="submit" data-document-archive-button>Скачать выбранные (ZIP)</button>
        </div>
    </div>
    <div class="table-scroll">
        <table class="table">
            <thead><tr><th class="col-check-width text-center"><label class="checkbox-item docgen-history-check"><input type="checkbox" data-document-select-all aria-label="Выбрать все показанные документы"><span class="checkbox-mark" aria-hidden="true"></span></label></th><th>ID</th><th>Документ</th><th>Исполнитель</th><th>Заявки / масса</th><th>Создан</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($history as $row): ?>
                <tr>
                    <td class="text-center"><label class="checkbox-item docgen-history-check"><input type="checkbox" name="document_ids[]" value="<?= (int)$row['id'] ?>" data-document-select aria-label="Выбрать документ #<?= (int)$row['id'] ?>"><span class="checkbox-mark" aria-hidden="true"></span></label></td>
                    <td>#<?= (int)$row['id'] ?></td>
                    <td>
                        <strong><?= e((string)($row['_template_name'] ?? $row['document_type'])) ?></strong><br>
                        <span class="text-muted">№ <?= e((string)($row['document_number'] ?? '—')) ?> · <?= e((string)($row['document_date'] ?? '—')) ?> · template v<?= (int)($row['_template_version_no'] ?? 0) ?></span>
                    </td>
                    <td><?= e((string)$row['_executor_name']) ?><br><span class="text-muted"><?= e((string)$row['_driver_name']) ?> · <?= e((string)$row['_vehicle']) ?></span></td>
                    <td><?= count(json_decode((string)$row['request_ids_json'], true) ?: []) ?> · <?= e(\App\Service\DocumentGeneratorService::formatMassKg((float)$row['total_mass_kg'])) ?> кг</td>
                    <td><?= e((string)$row['created_at']) ?></td>
                    <td class="text-right">
                        <a class="btn btn-ghost" href="<?= app_url('/company/document-generator/generated/' . (int)$row['id'] . '/download') ?>">DOCX</a>
                        <a class="btn btn-ghost" href="<?= app_url('/company/document-generator?from=' . (int)$row['id']) ?>">Создать на основе</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="table-footer">
        <span class="footer-label">Показано <b>1–<?= count($history) ?></b> из <b><?= count($history) ?></b></span>
    </div>
</div>
</form>
<script>
(() => {
    const form = document.querySelector('[data-document-archive-form]');
    if (!form) return;
    const items = Array.from(form.querySelectorAll('[data-document-select]'));
    const all = form.querySelector('[data-document-select-all]');
    const count = form.querySelector('[data-document-selected-count]');
    const button = form.querySelector('[data-document-archive-button]');
    const update = () => {
        const selected = items.filter(item => item.checked).length;
        count.textContent = `Выбрано: ${selected}`;
        button.disabled = selected === 0;
        all.checked = selected === items.length;
        all.indeterminate = selected > 0 && selected < items.length;
    };
    all.addEventListener('change', () => { items.forEach(item => { item.checked = all.checked; }); update(); });
    items.forEach(item => item.addEventListener('change', update));
    update();
})();
</script>
<?php endif; ?>
