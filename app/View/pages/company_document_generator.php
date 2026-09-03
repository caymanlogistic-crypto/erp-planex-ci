<?php
$selectedDocumentType='';
foreach ($templates as $template) {
    if ((int)($template['id'] ?? 0)===(int)($old['template_id'] ?? 0)) {
        $selectedDocumentType=(string)($template['document_type'] ?? '');
        break;
    }
}
$showContract=$selectedDocumentType==='vehicle_lease_crew';
$showWaybill=$selectedDocumentType==='waybill_driver_assignment';
?>
<style>
.docgen-form-width{max-width:980px}
.docgen-request-input{min-height:92px;max-height:160px;resize:vertical}
.docgen-form-actions{justify-content:flex-end}
.docgen-preview-modal{width:min(700px,calc(100vw - 32px));max-width:700px}
.docgen-error-list{margin:0;padding-left:20px}
@media(max-width:900px){.docgen-form-width{max-width:none}}
</style>
<div class="page-head">
    <div class="page-head-left">
        <h1 class="page-title">Создать документ</h1>
        <div class="page-summary"><span>ERP-данные → проверка → редактируемый DOCX</span></div>
    </div>
    <div class="page-head-actions">
        <a class="btn btn-ghost" href="<?= app_url('/company/document-generator/history') ?>">История</a>
        <?php if (($_SESSION['role_code'] ?? '') === 'company_owner'): ?>
            <a class="btn btn-ghost" href="<?= app_url('/company/document-generator/templates') ?>">Шаблоны</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($formError): ?><div class="notice warn docgen-form-width"><?= e($formError) ?></div><?php endif; ?>

<?php if (!$company): ?>
<div class="notice warn">Компания недоступна.</div>
<?php elseif ($templates === []): ?>
<div class="notice warn">Нет активных шаблонов. Руководителю компании нужно проверить раздел «Шаблоны».</div>
<?php elseif ($executors === []): ?>
<div class="notice warn">Нет доступных активных Исполнителей рейса.</div>
<?php else: ?>
<form id="document-generator-form" class="docgen-form-width" method="post" action="<?= app_url('/company/document-generator/preview') ?>" data-docgen-form>
    <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">

    <div class="panel mb-3">
        <div class="panel-head"><strong>Основное</strong></div>
        <div class="panel-body">
            <div class="form-grid-2">
                <div class="field">
                    <label class="field-label" for="docgen-template">Шаблон</label>
                    <select id="docgen-template" name="template_id" required class="field-select" data-docgen-template>
                        <?php foreach ($templates as $template): ?>
                        <option value="<?= (int)$template['id'] ?>" data-document-type="<?= e((string)($template['document_type'] ?? '')) ?>" <?= (int)$old['template_id'] === (int)$template['id'] ? 'selected' : '' ?>><?= e($template['name']) ?> · v<?= (int)($template['version_no'] ?? 0) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label class="field-label" for="docgen-executor">Исполнитель рейса</label>
                    <select id="docgen-executor" name="crew_id" required class="field-select">
                        <option value="">— выбрать —</option>
                        <?php foreach ($executors as $executor): ?>
                        <?php $executorLabel = trim((string)$executor['contractor_name'] . ' · ' . (string)$executor['driver_full_name'] . ' · ' . trim((string)$executor['primary_brand'] . ' ' . (string)$executor['primary_model'] . ' ' . (string)$executor['primary_plate'])); ?>
                        <option value="<?= (int)$executor['crew_id'] ?>" <?= (int)$old['crew_id'] === (int)$executor['crew_id'] ? 'selected' : '' ?>><?= e($executorLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="field mt-3">
                <label class="field-label" for="docgen-request-input">Заявки ФЭО</label>
                <textarea id="docgen-request-input" name="request_input" rows="4" placeholder="Вставьте список, Excel-столбец или markdown-таблицу" class="field-textarea docgen-request-input"><?= e($old['request_input']) ?></textarea>
                <div class="text-muted mt-1">Можно вставить список шестизначных ID. Используется canonical parser заявок ФЭО.</div>
            </div>
        </div>
    </div>

    <div class="panel mb-3 <?= $showContract ? '' : 'is-hidden' ?>" data-docgen-section="vehicle_lease_crew">
        <div class="panel-head"><strong>Параметры договора</strong></div>
        <div class="panel-body">
            <div class="form-grid-2">
                <div class="field"><label class="field-label">Дата договора</label><input type="date" name="contract_date" value="<?= e($old['contract_date']) ?>" class="field-input"></div>
                <div class="field"><label class="field-label">Дата начала аренды</label><input type="date" name="start_date" value="<?= e($old['start_date']) ?>" class="field-input"></div>
                <div class="field"><label class="field-label">Время подачи <span class="text-muted">(необязательно)</span></label><input type="time" name="start_time" value="<?= e($old['start_time']) ?>" class="field-input"></div>
                <div class="field"><label class="field-label">Плановая дата завершения</label><input type="date" name="finish_date" value="<?= e($old['finish_date']) ?>" class="field-input"></div>
                <div class="field"><label class="field-label">Стоимость, ₽</label><input type="text" inputmode="decimal" name="price" value="<?= e($old['price']) ?>" class="field-input"></div>
                <div class="field"><label class="field-label">НДС</label><input type="text" name="vat_mode" value="<?= e($old['vat_mode']) ?>" class="field-input" placeholder="без НДС"></div>
            </div>
            <div class="text-muted mt-2">Номер будет присвоен автоматически при формировании.</div>
        </div>
    </div>

    <div class="panel mb-3 <?= $showWaybill ? '' : 'is-hidden' ?>" data-docgen-section="waybill_driver_assignment">
        <div class="panel-head"><strong>Параметры путевого листа</strong></div>
        <div class="panel-body">
            <div class="form-grid-2">
                <div class="field"><label class="field-label">Номер путевого листа</label><input type="text" name="waybill_number" value="<?= e($old['waybill_number']) ?>" class="field-input"></div>
                <div class="field"><label class="field-label">Дата путевого листа</label><input type="date" name="waybill_date" value="<?= e($old['waybill_date']) ?>" class="field-input"></div>
            </div>
            <div class="text-muted mt-2">Поля фактического прибытия/убытия в первоначальном DOCX остаются пустыми.</div>
        </div>
    </div>

    <div class="form-actions docgen-form-actions">
        <button type="submit" class="btn btn-primary" formaction="<?= app_url('/company/document-generator/preview') ?>">Проверить</button>
    </div>
</form>

<?php if (is_array($preview)): ?>
<div class="modal-overlay" id="document-generator-preview-modal" role="dialog" aria-modal="true" data-close-on-overlay="1" data-close-on-escape="1">
    <div class="modal modal-lg docgen-preview-modal">
        <div class="modal-head">
            <span class="modal-title">Проверка документа</span>
            <button type="button" class="modal-close" onclick="closeModal('document-generator-preview-modal')">✕</button>
        </div>
        <div class="modal-body">
            <?php if ($preview['ok'] ?? false): ?>
                <div class="notice success mb-3">Проверка пройдена. Документ можно сформировать.</div>
                <div class="kv">
                    <div class="kv-row"><span class="kv-key">Шаблон</span><span class="kv-value"><?= e((string)($preview['template']['template_name'] ?? '')) ?> · v<?= (int)($preview['template']['version_no'] ?? 0) ?></span></div>
                    <div class="kv-row"><span class="kv-key">Исполнитель</span><span class="kv-value"><?= e((string)($preview['snapshot']['executor']['name'] ?? '—')) ?></span></div>
                    <div class="kv-row"><span class="kv-key">Водитель</span><span class="kv-value"><?= e((string)($preview['snapshot']['driver']['full_name'] ?? '—')) ?></span></div>
                    <div class="kv-row"><span class="kv-key">Автомобиль</span><span class="kv-value"><?= e(trim((string)($preview['snapshot']['vehicle']['model'] ?? '') . ' ' . (string)($preview['snapshot']['vehicle']['plate'] ?? '')) ?: '—') ?></span></div>
                    <div class="kv-row"><span class="kv-key">Заявок</span><span class="kv-value"><?= (int)($preview['found_count'] ?? 0) ?></span></div>
                    <div class="kv-row"><span class="kv-key">Общая масса</span><span class="kv-value"><?= e((string)($preview['total_mass_kg_formatted'] ?? '0')) ?> кг</span></div>
                    <div class="kv-row"><span class="kv-key">Выгрузка</span><span class="kv-value"><?= e((string)($preview['unload_address'] ?? '—')) ?></span></div>
                    <?php $params = $preview['snapshot']['parameters'] ?? []; ?>
                    <?php if (($preview['template']['document_type'] ?? '') === 'vehicle_lease_crew'): ?>
                        <div class="kv-row"><span class="kv-key">Дата договора</span><span class="kv-value"><?= e((string)($params['contract_date'] ?? '—')) ?></span></div>
                        <div class="kv-row"><span class="kv-key">Период</span><span class="kv-value"><?= e((string)($params['start_date'] ?? '—')) ?><?= trim((string)($params['start_time'] ?? '')) !== '' ? ' ' . e((string)$params['start_time']) : '' ?> → <?= e((string)($params['finish_date'] ?? '—')) ?></span></div>
                        <div class="kv-row"><span class="kv-key">Стоимость</span><span class="kv-value"><?= e((string)($params['price'] ?? '—')) ?> ₽</span></div>
                        <div class="kv-row"><span class="kv-key">НДС</span><span class="kv-value"><?= e((string)($params['vat_mode'] ?? '—')) ?></span></div>
                        <div class="kv-row"><span class="kv-key">Номер договора</span><span class="kv-value">Будет присвоен автоматически</span></div>
                    <?php else: ?>
                        <div class="kv-row"><span class="kv-key">Путевой лист</span><span class="kv-value">№ <?= e((string)($params['waybill_number'] ?? '—')) ?> от <?= e((string)($params['waybill_date'] ?? '—')) ?></span></div>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="notice warn mb-3"><strong>Документ пока нельзя сформировать.</strong></div>
                <?php if (!empty($preview['errors'])): ?>
                    <ul class="docgen-error-list">
                        <?php foreach ($preview['errors'] as $error): ?><li><?= e((string)$error) ?></li><?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <div class="modal-foot is-spaced">
            <div></div>
            <div class="modal-foot-actions">
                <button type="button" class="btn btn-ghost" onclick="closeModal('document-generator-preview-modal')"><?= ($preview['ok'] ?? false) ? 'Вернуться к редактированию' : 'Исправить данные' ?></button>
                <?php if ($preview['ok'] ?? false): ?>
                    <button type="submit" form="document-generator-form" class="btn btn-primary" formaction="<?= app_url('/company/document-generator/generate') ?>">Сформировать DOCX</button>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
(function () {
    const form=document.querySelector('[data-docgen-form]');
    const select=document.querySelector('[data-docgen-template]');
    if (!form || !select) return;
    const syncType=()=>{
        const type=select.options[select.selectedIndex]?.dataset.documentType || '';
        form.querySelectorAll('[data-docgen-section]').forEach((section)=>{
            const active=section.dataset.docgenSection===type;
            section.classList.toggle('is-hidden',!active);
            section.querySelectorAll('input,select,textarea').forEach((control)=>{ control.disabled=!active; });
        });
    };
    select.addEventListener('change',syncType);
    syncType();
    <?php if (is_array($preview)): ?>
    if (typeof openModal==='function') openModal('document-generator-preview-modal');
    <?php endif; ?>
})();
</script>
<?php endif; ?>
