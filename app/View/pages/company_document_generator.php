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

<?php if ($formError): ?><div class="notice warn"><?= e($formError) ?></div><?php endif; ?>

<?php if (!$company): ?>
<div class="notice warn">Компания недоступна.</div>
<?php elseif ($templates === []): ?>
<div class="notice warn">Нет активных шаблонов. Руководителю компании нужно проверить раздел «Шаблоны».</div>
<?php elseif ($executors === []): ?>
<div class="notice warn">Нет доступных активных Исполнителей рейса.</div>
<?php else: ?>
<form method="post" action="<?= app_url('/company/document-generator/preview') ?>">
    <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
    <div class="panel mb-4"><div class="panel-body">
        <div class="form-grid-2">
            <div class="field">
                <label class="field-label" for="docgen-template">Шаблон</label>
                <select id="docgen-template" name="template_id" required class="field-select">
                    <?php foreach ($templates as $template): ?>
                    <option value="<?= (int)$template['id'] ?>" <?= (int)$old['template_id'] === (int)$template['id'] ? 'selected' : '' ?>><?= e($template['name']) ?> · v<?= (int)($template['version_no'] ?? 0) ?></option>
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
        <div class="field mt-4">
            <label class="field-label" for="docgen-request-input">Номера заявок ФЭО</label>
            <textarea id="docgen-request-input" name="request_input" rows="7" placeholder="Вставьте список, Excel-столбец или markdown-таблицу" class="field-textarea"><?= e($old['request_input']) ?></textarea>
            <div class="text-muted mt-1">Используется тот же parser, что в «Заявки ФЭО → Все заявки».</div>
        </div>
    </div></div>

    <div class="panel mb-4">
        <div class="panel-head"><strong>Параметры договора-заявки</strong></div>
        <div class="panel-body">
            <div class="notice info mb-3">Номер договора присваивается автоматически только при успешном формировании: П 105, П 106 и далее. ИНН банка Арендодателя берётся из карточки Подрядчика/Перевозчика.</div>
            <div class="form-grid-2">
                <div class="field"><label class="field-label">Дата договора</label><input type="date" name="contract_date" value="<?= e($old['contract_date']) ?>" class="field-input"></div>
                <div class="field"><label class="field-label">Дата начала аренды</label><input type="date" name="start_date" value="<?= e($old['start_date']) ?>" class="field-input"></div>
                <div class="field"><label class="field-label">Время подачи</label><input type="time" name="start_time" value="<?= e($old['start_time']) ?>" class="field-input"></div>
                <div class="field"><label class="field-label">Плановая дата завершения</label><input type="date" name="finish_date" value="<?= e($old['finish_date']) ?>" class="field-input"></div>
                <div class="field"><label class="field-label">Стоимость, ₽</label><input type="text" inputmode="decimal" name="price" value="<?= e($old['price']) ?>" class="field-input"></div>
                <div class="field"><label class="field-label">НДС</label><input type="text" name="vat_mode" value="<?= e($old['vat_mode']) ?>" class="field-input" placeholder="без НДС"></div>
            </div>
        </div>
    </div>

    <div class="panel mb-4">
        <div class="panel-head"><strong>Параметры приложения к путевому листу</strong></div>
        <div class="panel-body">
            <div class="form-grid-2">
                <div class="field"><label class="field-label">Номер путевого листа</label><input type="text" name="waybill_number" value="<?= e($old['waybill_number']) ?>" class="field-input"></div>
                <div class="field"><label class="field-label">Дата путевого листа</label><input type="date" name="waybill_date" value="<?= e($old['waybill_date']) ?>" class="field-input"></div>
            </div>
            <div class="text-muted mt-2">Поля фактического прибытия/убытия в первоначальном DOCX остаются пустыми.</div>
        </div>
    </div>

    <?php if (is_array($preview)): ?>
    <div class="panel mb-4"><div class="panel-head"><strong>Проверка перед формированием</strong></div><div class="panel-body">
        <?php if ($preview['ok'] ?? false): ?><div class="notice success">Проверка пройдена. Документ готов к формированию.</div><?php else: ?><div class="notice warn">Формирование заблокировано до устранения ошибок.</div><?php endif; ?>
        <div class="kv mt-3">
            <div class="kv-row"><span class="kv-key">Шаблон</span><span class="kv-value"><?= e((string)($preview['template']['template_name'] ?? '')) ?> · v<?= (int)($preview['template']['version_no'] ?? 0) ?></span></div>
            <div class="kv-row"><span class="kv-key">Исполнитель</span><span class="kv-value"><?= e((string)($preview['snapshot']['executor']['name'] ?? '')) ?></span></div>
            <div class="kv-row"><span class="kv-key">Водитель</span><span class="kv-value"><?= e((string)($preview['snapshot']['driver']['full_name'] ?? '')) ?></span></div>
            <div class="kv-row"><span class="kv-key">Автомобиль</span><span class="kv-value"><?= e(trim((string)($preview['snapshot']['vehicle']['model'] ?? '') . ' ' . (string)($preview['snapshot']['vehicle']['plate'] ?? ''))) ?></span></div>
            <div class="kv-row"><span class="kv-key">Заявки</span><span class="kv-value">Найдено: <?= (int)($preview['found_count'] ?? 0) ?> · масса <?= e((string)($preview['total_mass_kg_formatted'] ?? '0')) ?> кг</span></div>
            <div class="kv-row"><span class="kv-key">Место выгрузки</span><span class="kv-value"><?= e((string)($preview['unload_address'] ?? '—')) ?></span></div>
            <?php $params = $preview['snapshot']['parameters'] ?? []; ?>
            <?php if (($preview['template']['document_type'] ?? '') === 'vehicle_lease_crew'): ?>
                <div class="kv-row"><span class="kv-key">Номер договора</span><span class="kv-value">будет присвоен автоматически при формировании</span></div>
                <div class="kv-row"><span class="kv-key">Дата договора</span><span class="kv-value"><?= e((string)($params['contract_date'] ?? '')) ?></span></div>
                <div class="kv-row"><span class="kv-key">Срок</span><span class="kv-value"><?= e((string)($params['start_date'] ?? '')) ?> <?= e((string)($params['start_time'] ?? '')) ?> → <?= e((string)($params['finish_date'] ?? '')) ?></span></div>
                <div class="kv-row"><span class="kv-key">Стоимость</span><span class="kv-value"><?= e((string)($params['price'] ?? '')) ?> ₽ · <?= e((string)($params['vat_mode'] ?? '')) ?></span></div>
                <div class="kv-row"><span class="kv-key">ИНН банка Арендодателя</span><span class="kv-value"><?= e((string)($preview['snapshot']['executor']['bank_inn'] ?? '—')) ?></span></div>
            <?php else: ?>
                <div class="kv-row"><span class="kv-key">Путевой лист</span><span class="kv-value">№ <?= e((string)($params['waybill_number'] ?? '')) ?> от <?= e((string)($params['waybill_date'] ?? '')) ?></span></div>
            <?php endif; ?>
        </div>
        <?php if (!empty($preview['unload_groups']) && count($preview['unload_groups']) > 1): ?><div class="mt-3"><strong>Обнаружены разные места выгрузки:</strong></div><ul><?php foreach ($preview['unload_groups'] as $address => $count): ?><li><?= e((string)$address) ?> — <?= (int)$count ?> заявок</li><?php endforeach; ?></ul><?php endif; ?>
        <?php if (!empty($preview['errors'])): ?><div class="mt-3"><strong>Ошибки:</strong></div><ul><?php foreach ($preview['errors'] as $error): ?><li><?= e((string)$error) ?></li><?php endforeach; ?></ul><?php endif; ?>
    </div></div>
    <?php endif; ?>

    <div class="form-actions">
        <button type="submit" class="btn btn-ghost" formaction="<?= app_url('/company/document-generator/preview') ?>">Проверить</button>
        <button type="submit" class="btn btn-primary" formaction="<?= app_url('/company/document-generator/generate') ?>" <?= !is_array($preview) || !($preview['ok'] ?? false) ? 'disabled' : '' ?>>Сформировать DOCX</button>
    </div>
</form>
<?php endif; ?>
