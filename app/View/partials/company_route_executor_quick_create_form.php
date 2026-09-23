<?php

use App\Service\RouteExecutorQuickCreateService;

require_once base_path('app/View/components/contact_fields.php');

$quickMode = RouteExecutorQuickCreateService::normalizeMode((string)($quickMode ?? ''));
$quickErrors = is_array($quickErrors ?? null) ? $quickErrors : [];
$quickOld = is_array($quickOld ?? null) ? $quickOld : [];
$quickContractors = is_array($quickContractors ?? null) ? $quickContractors : [];
$quickStartAtDetails = !empty($quickStartAtDetails);
$value = static function (string $path, string $default = '') use ($quickOld): string {
    $current = $quickOld;
    foreach (explode('.', $path) as $part) {
        if (!is_array($current) || !array_key_exists($part, $current)) return $default;
        $current = $current[$part];
    }
    return is_scalar($current) ? (string)$current : $default;
};
$error = static fn(string $path): string => (string)($quickErrors[$path] ?? '');
$fieldClass = static fn(string $path): string => !empty($quickErrors[$path]) ? ' is-error' : '';
$upload = static function (string $entity, string $code, string $label): void {
    $id = 'route-executor-' . $entity . '-' . $code;
    ?>
    <div class="file-item file-item-predef document-file-row is-empty">
        <div class="file-type-badge file-type-badge-empty" id="<?= e($id) ?>-badge">—</div>
        <div class="file-info"><div class="file-name"><?= e($label) ?></div><div class="file-meta" id="<?= e($id) ?>-name">Файл не выбран</div></div>
        <button type="button" class="btn btn-secondary file-action-btn js-file-pick-btn" data-file-input="<?= e($id) ?>-input"><span id="<?= e($id) ?>-button">Выбрать</span></button>
        <button type="button" class="predef-file-clear is-hidden" id="<?= e($id) ?>-clear" title="Очистить файл">&times;</button>
        <input type="file" id="<?= e($id) ?>-input" class="file-input-hidden js-predef-file-input" name="quick_docs[<?= e($entity) ?>][<?= e($code) ?>][]"
               accept=".pdf,.doc,.docx,.rtf,.odt,.xls,.xlsx,.csv,.ods,.jpg,.jpeg,.png,.webp,.gif,.bmp,.tif,.tiff,.txt" multiple
               data-label="<?= e($id) ?>-name" data-badge="<?= e($id) ?>-badge" data-button-label="<?= e($id) ?>-button" data-clear="<?= e($id) ?>-clear">
    </div>
    <?php
};
$customDocument = static function (string $entity, int $index, string $title = ''): void {
    $id = 'route-executor-custom-' . $entity . '-' . $index;
    ?>
    <div class="file-item custom-doc-row document-file-row is-empty" data-quick-custom-document>
        <div class="file-type-badge file-type-badge-empty" id="<?= e($id) ?>-badge">—</div>
        <div class="file-info"><div class="field custom-doc-title-field"><input type="text" name="custom_document_types[<?= e($entity) ?>][]" class="field-input" placeholder="Введите название" value="<?= e($title) ?>"><div class="field-msg"></div></div><div class="file-meta is-hidden" id="<?= e($id) ?>-name"></div></div>
        <button type="button" class="btn btn-secondary file-action-btn js-file-pick-btn" data-file-input="<?= e($id) ?>-input"><span id="<?= e($id) ?>-button">Выбрать</span></button>
        <button type="button" class="file-remove" title="Удалить документ">×</button>
        <input type="file" id="<?= e($id) ?>-input" class="file-input-hidden js-custom-file-input" name="quick_custom_docs[<?= e($entity) ?>][]"
               accept=".pdf,.doc,.docx,.rtf,.odt,.xls,.xlsx,.csv,.ods,.jpg,.jpeg,.png,.webp,.gif,.bmp,.tif,.tiff,.txt"
               data-label="<?= e($id) ?>-name" data-badge="<?= e($id) ?>-badge" data-button-label="<?= e($id) ?>-button">
    </div>
    <?php
};
$contactErrors = [];
foreach ($quickErrors as $key => $message) {
    if (preg_match('/^contractor\.contacts\.(\d+)\.([a-z_]+)$/', (string)$key, $matches)) $contactErrors[(int)$matches[1]][$matches[2]] = $message;
}
?>

<form id="route-executor-quick-create-form" action="<?= e(app_url('/company/route-executors/quick-create')) ?>" method="post" enctype="multipart/form-data"
      data-route-executor-quick-form data-quick-mode="<?= e($quickMode) ?>" data-inn-lookup-url="<?= e(app_url('/company/requisites/lookup-by-inn')) ?>">
    <input type="hidden" name="quick_mode" value="<?= e($quickMode) ?>">
    <?php if ($quickFormError): ?><div class="form-alert alert-error"><div class="alert-body"><div class="alert-body-title">Проверьте форму</div><div class="alert-body-sub"><?= e($quickFormError) ?></div></div></div><?php endif; ?>

    <?php if ($quickMode === RouteExecutorQuickCreateService::MODE_EXISTING_CONTRACTOR): ?>
    <div class="<?= $quickStartAtDetails ? 'is-hidden' : '' ?>" data-quick-contractor-step><div class="form-section">
        <div class="section-title">Подрядчик</div>
        <div class="field<?= $fieldClass('contractor_id') ?>"><label class="field-label" for="quick-contractor-id">Выберите подрядчика <span class="req">*</span></label>
            <select class="field-select" id="quick-contractor-id" name="contractor_id" required<?= empty($quickContractors) ? ' disabled' : '' ?>><option value="">— Выберите подрядчика —</option><?php foreach ($quickContractors as $contractor): ?><option value="<?= (int)$contractor['id'] ?>" <?= $value('contractor_id') === (string)$contractor['id'] ? 'selected' : '' ?>><?= e($contractor['name']) ?> (ИНН <?= e($contractor['inn'] ?? '—') ?>)</option><?php endforeach; ?></select>
            <?php if (empty($quickContractors)): ?><div class="field-msg is-error">Нет доступных активных подрядчиков.</div><?php endif; ?><?php if ($error('contractor_id') !== ''): ?><div class="field-msg is-error"><?= e($error('contractor_id')) ?></div><?php endif; ?>
        </div>
        <div class="form-actions"><button type="button" class="btn btn-primary" data-quick-contractor-next<?= empty($quickContractors) ? ' disabled' : '' ?>>Продолжить</button></div>
    </div></div>
    <?php endif; ?>

    <div class="<?= !$quickStartAtDetails ? 'is-hidden' : '' ?>" data-quick-details-step>
        <?php if ($quickMode === RouteExecutorQuickCreateService::MODE_NEW_CONTRACTOR): ?>
        <div class="form-section">
            <div class="section-title">Данные перевозчика</div>
            <div class="form-alert alert-warning is-hidden" data-inn-autofill-message><div class="alert-body"><div class="alert-body-title" data-inn-autofill-title></div><div class="alert-body-sub" data-inn-autofill-sub></div></div></div>
            <div class="driver-fields">
                <div class="driver-contact-top-row">
                    <div class="field field-w-name<?= $fieldClass('contractor.name') ?>" data-field="contractor[name]"><label class="field-label">Наименование <span class="req">*</span></label><input class="field-input" name="contractor[name]" value="<?= e($value('contractor.name')) ?>" placeholder='ООО "РОМАШКА"' required><div class="field-msg"><?= e($error('contractor.name')) ?></div></div>
                    <div class="driver-contact-stack"><div class="driver-contact-main-row"><div class="field field-w-phone<?= $fieldClass('contractor.inn') ?>" data-field="contractor[inn]"><label class="field-label">ИНН <span class="req">*</span></label><input class="field-input" name="contractor[inn]" inputmode="numeric" value="<?= e($value('contractor.inn')) ?>" placeholder="7701234567" required><div class="field-msg"><?= e($error('contractor.inn')) ?></div></div><button type="button" class="btn btn-ghost phone-add-btn" data-inn-autofill-btn>Заполнить по ИНН</button></div></div>
                </div>
                <div class="form-grid-3">
                    <div class="field" data-field="contractor[contractor_type]"><label class="field-label">Тип перевозчика</label><select class="field-select" name="contractor[contractor_type]"><?php foreach ([''=>'— Не указан —','legal_entity'=>'Юридическое лицо','individual'=>'Индивидуальный предприниматель','self_employed'=>'Самозанятый','private_person'=>'Физическое лицо'] as $type=>$label): ?><option value="<?= e($type) ?>" <?= $value('contractor.contractor_type') === $type ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
                    <div class="field<?= $fieldClass('contractor.kpp') ?>" data-field="contractor[kpp]"><label class="field-label">КПП</label><input class="field-input" name="contractor[kpp]" value="<?= e($value('contractor.kpp')) ?>"><div class="field-msg"><?= e($error('contractor.kpp')) ?></div></div>
                    <div class="field<?= $fieldClass('contractor.ogrn') ?>" data-field="contractor[ogrn]"><label class="field-label">ОГРН / ОГРНИП</label><input class="field-input" name="contractor[ogrn]" value="<?= e($value('contractor.ogrn')) ?>"><div class="field-msg"><?= e($error('contractor.ogrn')) ?></div></div>
                </div>
                <div class="field" data-field="contractor[legal_address]"><label class="field-label">Юридический адрес</label><input class="field-input" name="contractor[legal_address]" value="<?= e($value('contractor.legal_address')) ?>"></div>
            </div>
            <div class="section-title mt-3">Контакты перевозчика</div>
            <?php renderContactFields(['entity_type' => 'contractor', 'field_prefix' => 'contractor[contacts]', 'contacts' => $quickOld['contractor']['contacts'] ?? [], 'errors' => $contactErrors]); ?>
        </div>
        <?php endif; ?>

        <div class="form-section">
            <div class="section-title">Данные водителя</div>
            <div class="driver-fields">
                <div class="driver-contact-top-row">
                    <div class="field field-w-name<?= $fieldClass('driver.full_name') ?>"><label class="field-label">ФИО <span class="req">*</span></label><input class="field-input" name="driver[full_name]" value="<?= e($value('driver.full_name')) ?>" placeholder="Фамилия Имя Отчество" required><div class="field-msg"><?= e($error('driver.full_name')) ?></div></div>
                    <div class="driver-contact-stack"><div class="driver-contact-main-row"><div class="field field-w-phone<?= $fieldClass('driver.phone') ?>"><label class="field-label">Телефон <span class="req">*</span></label><input class="field-input" name="driver[phone]" value="<?= e($value('driver.phone')) ?>" placeholder="+7 900 000-00-00" required><div class="field-msg"><?= e($error('driver.phone')) ?></div></div><button type="button" class="btn btn-ghost phone-add-btn" data-quick-add-extra-phone>+ Доп. телефон</button></div>
                        <div class="driver-extra-phones" data-quick-extra-phones><?php foreach (($quickOld['driver']['extra_phones'] ?? []) as $index => $phone): ?><div class="driver-extra-phone-row"><div class="field driver-extra-phone-field<?= $fieldClass('driver.extra_phones.' . $index) ?>"><input type="text" name="driver[extra_phones][]" class="field-input" placeholder="+7 900 000-00-00" value="<?= e($phone) ?>"><div class="field-msg"><?= e($error('driver.extra_phones.' . $index)) ?></div></div><div class="driver-extra-phone-comment-wrap"><input type="text" name="driver[extra_phone_comments][]" class="field-input" placeholder="Комментарий к телефону" value="<?= e($quickOld['driver']['extra_phone_comments'][$index] ?? '') ?>"><button type="button" class="driver-extra-phone-remove" title="Удалить">×</button></div></div><?php endforeach; ?></div>
                    </div>
                </div>
                <div class="field-row field-row-group">
                    <div class="field field-w-passport<?= $fieldClass('driver.passport_number') ?>"><label class="field-label">Серия и номер</label><input class="field-input" name="driver[passport_number]" value="<?= e($value('driver.passport_number')) ?>" placeholder="0000 000000"><div class="field-msg"><?= e($error('driver.passport_number')) ?></div></div>
                    <div class="field field-w-code<?= $fieldClass('driver.passport_department_code') ?>"><label class="field-label">Код подразделения</label><input class="field-input" name="driver[passport_department_code]" value="<?= e($value('driver.passport_department_code')) ?>" placeholder="000-000"><div class="field-msg"><?= e($error('driver.passport_department_code')) ?></div></div>
                    <div class="field field-w-issued-by"><label class="field-label">Кем выдан</label><input class="field-input" name="driver[passport_issued_by]" value="<?= e($value('driver.passport_issued_by')) ?>"></div>
                    <div class="field field-w-date<?= $fieldClass('driver.passport_issue_date') ?>"><label class="field-label">Дата выдачи</label><input class="field-input js-erp-date-picker" name="driver[passport_issue_date]" value="<?= e($value('driver.passport_issue_date')) ?>" placeholder="дд.мм.гггг"><div class="field-msg"><?= e($error('driver.passport_issue_date')) ?></div></div>
                </div>
                <div class="field-row field-row-group">
                    <div class="field field-w-license<?= $fieldClass('driver.license_number') ?>"><label class="field-label">Номер ВУ</label><input class="field-input" name="driver[license_number]" value="<?= e($value('driver.license_number')) ?>" placeholder="0000 000000"><div class="field-msg"><?= e($error('driver.license_number')) ?></div></div>
                    <div class="field field-w-date<?= $fieldClass('driver.license_issue_date') ?>"><label class="field-label">Дата выдачи ВУ</label><input class="field-input js-erp-date-picker" name="driver[license_issue_date]" value="<?= e($value('driver.license_issue_date')) ?>" placeholder="дд.мм.гггг"><div class="field-msg"><?= e($error('driver.license_issue_date')) ?></div></div>
                    <div class="field field-w-snils<?= $fieldClass('driver.snils') ?>"><label class="field-label">СНИЛС</label><input class="field-input" name="driver[snils]" value="<?= e($value('driver.snils')) ?>" placeholder="000-000-000 00"><div class="field-msg"><?= e($error('driver.snils')) ?></div></div>
                    <div class="field field-w-email field-grow<?= $fieldClass('driver.email') ?>"><label class="field-label">Email</label><input class="field-input" name="driver[email]" value="<?= e($value('driver.email')) ?>"><div class="field-msg"><?= e($error('driver.email')) ?></div></div>
                </div>
                <div class="field field-w-comment field-row-group"><label class="field-label">Комментарий</label><textarea name="driver[comments]" class="field-textarea driver-textarea"><?= e($value('driver.comments')) ?></textarea></div>
            </div>
            <div class="section-title mt-3">Документы водителя</div>
            <div class="file-list"><?php $upload('driver','passport','Паспорт'); $upload('driver','driver_license','Водительское удостоверение'); $upload('driver','snils','СНИЛС'); ?></div>
            <div class="file-list" data-quick-custom-docs="driver"><?php foreach (($quickOld['custom_document_types']['driver'] ?? []) as $index => $title) $customDocument('driver', (int)$index, (string)$title); ?></div>
            <button type="button" class="btn btn-ghost" data-add-quick-custom-doc="driver">+ Добавить документ</button>
        </div>

        <div class="form-section">
            <div class="section-title">Транспорт</div>
            <div class="vehicle-set-card-list">
                <div class="vehicle-set-form-card vehicle-set-type-card"><div class="field<?= $fieldClass('vehicle.set_type') ?>"><label class="field-label">Тип комплекта <span class="req">*</span></label><select class="field-input" name="vehicle[set_type]" data-quick-set-type><option value="single" <?= $value('vehicle.set_type','single') === 'single' ? 'selected' : '' ?>>Одиночное ТС</option><option value="coupling" <?= $value('vehicle.set_type') === 'coupling' ? 'selected' : '' ?>>Сцепка</option><option value="road_train" <?= $value('vehicle.set_type') === 'road_train' ? 'selected' : '' ?>>Автопоезд</option></select><div class="field-msg"><?= e($error('vehicle.set_type')) ?></div></div></div>
                <?php foreach (['primary' => 'Транспортная единица', 'secondary' => 'Полуприцеп'] as $unit => $title): $secondary = $unit === 'secondary'; ?>
                <div class="vehicle-set-form-card vehicle-set-unit-panel<?= $secondary && !in_array($value('vehicle.set_type','single'), ['coupling','road_train'], true) ? ' is-hidden' : '' ?>" <?= $secondary ? 'data-quick-secondary' : '' ?>>
                    <div class="section-title" data-quick-<?= e($unit) ?>-title><?= e($title) ?></div>
                    <div class="vehicle-set-unit-card-body"><div class="vehicle-set-unit-fields">
                        <div class="field-row field-row-group"><div class="field field-w-passport<?= $fieldClass('vehicle.' . $unit . '.plate_number') ?>"><label class="field-label">Госномер <span class="req">*</span></label><input class="field-input" name="vehicle[<?= e($unit) ?>][plate_number]" value="<?= e($value('vehicle.' . $unit . '.plate_number')) ?>" <?= $secondary ? 'data-quick-secondary-required' : 'required' ?>><div class="field-msg"><?= e($error('vehicle.' . $unit . '.plate_number')) ?></div></div><div class="field field-w-license"><label class="field-label">Марка</label><input class="field-input" name="vehicle[<?= e($unit) ?>][brand]" value="<?= e($value('vehicle.' . $unit . '.brand')) ?>"></div><div class="field field-w-license"><label class="field-label">Модель</label><input class="field-input" name="vehicle[<?= e($unit) ?>][model]" value="<?= e($value('vehicle.' . $unit . '.model')) ?>"></div></div>
                        <div class="field-row field-row-group"><div class="field field-w-issued-by<?= $fieldClass('vehicle.' . $unit . '.vin') ?>"><label class="field-label">VIN код</label><input class="field-input" name="vehicle[<?= e($unit) ?>][vin]" value="<?= e($value('vehicle.' . $unit . '.vin')) ?>"><div class="field-msg"><?= e($error('vehicle.' . $unit . '.vin')) ?></div></div><div class="field field-w-email field-grow"><label class="field-label">Диагностическая карта</label><input class="field-input" name="vehicle[<?= e($unit) ?>][diagnostic_card_number]" value="<?= e($value('vehicle.' . $unit . '.diagnostic_card_number')) ?>" placeholder="123456789012345"></div><div class="field field-w-date<?= $fieldClass('vehicle.' . $unit . '.diagnostic_card_date') ?>"><label class="field-label">Дата получения</label><input class="field-input js-erp-date-picker" name="vehicle[<?= e($unit) ?>][diagnostic_card_date]" value="<?= e($value('vehicle.' . $unit . '.diagnostic_card_date')) ?>" placeholder="дд.мм.гггг"><div class="field-msg"><?= e($error('vehicle.' . $unit . '.diagnostic_card_date')) ?></div></div></div>
                        <div class="field-row field-row-group"><div class="field field-w-code<?= $fieldClass('vehicle.' . $unit . '.capacity_tons') ?>"><label class="field-label">Грузоподъёмность</label><input class="field-input" name="vehicle[<?= e($unit) ?>][capacity_tons]" value="<?= e($value('vehicle.' . $unit . '.capacity_tons')) ?>" inputmode="decimal"><div class="field-msg"><?= e($error('vehicle.' . $unit . '.capacity_tons')) ?></div></div><div class="field field-w-code<?= $fieldClass('vehicle.' . $unit . '.volume_m3') ?>"><label class="field-label">Объём кузова</label><input class="field-input" name="vehicle[<?= e($unit) ?>][volume_m3]" value="<?= e($value('vehicle.' . $unit . '.volume_m3')) ?>" inputmode="decimal"><div class="field-msg"><?= e($error('vehicle.' . $unit . '.volume_m3')) ?></div></div></div>
                    </div></div>
                    <div class="section-title mt-3">Документы <?= $secondary ? 'дополнительной единицы' : 'ТС' ?></div>
                    <div class="file-list"><?php $upload($unit,'sts','СТС'); $upload($unit,'diagnostic_card','Диагностическая карта'); $upload($unit,'photo','Фотография'); ?></div>
                    <div class="file-list" data-quick-custom-docs="<?= e($unit) ?>"><?php foreach (($quickOld['custom_document_types'][$unit] ?? []) as $index => $docTitle) $customDocument($unit, (int)$index, (string)$docTitle); ?></div>
                    <button type="button" class="btn btn-ghost" data-add-quick-custom-doc="<?= e($unit) ?>">+ Добавить документ</button>
                </div>
                <?php endforeach; ?>
            </div>
            <?php $documentErrors = array_filter($quickErrors, static fn($key): bool => str_starts_with((string)$key, 'documents.'), ARRAY_FILTER_USE_KEY); ?>
            <?php if ($documentErrors): ?><div class="notice warn"><?= e(implode(' ', array_values($documentErrors))) ?></div><?php endif; ?>
            <?php if ($quickMode === RouteExecutorQuickCreateService::MODE_EXISTING_CONTRACTOR): ?><div class="form-actions"><button type="button" class="btn btn-ghost" data-quick-contractor-back>← Сменить подрядчика</button></div><?php endif; ?>
        </div>
    </div>
</form>
