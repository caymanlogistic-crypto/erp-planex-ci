<?php

use App\Service\RouteExecutorQuickCreateService;

$quickMode = RouteExecutorQuickCreateService::normalizeMode((string)($quickMode ?? ''));
$quickErrors = is_array($quickErrors ?? null) ? $quickErrors : [];
$quickOld = is_array($quickOld ?? null) ? $quickOld : [];
$quickContractors = is_array($quickContractors ?? null) ? $quickContractors : [];
$quickStartAtDetails = !empty($quickStartAtDetails);
$value = static function (string $path, string $default = '') use ($quickOld): string {
    $current = $quickOld;
    foreach (explode('.', $path) as $part) {
        if (!is_array($current) || !array_key_exists($part, $current)) {
            return $default;
        }
        $current = $current[$part];
    }
    return is_scalar($current) ? (string)$current : $default;
};
$error = static fn(string $path): string => (string)($quickErrors[$path] ?? '');
$fieldClass = static fn(string $path): string => !empty($quickErrors[$path]) ? ' is-error' : '';
$upload = static function (string $entity, string $code, string $label): void { ?>
    <label class="quick-upload" data-quick-upload>
        <span class="quick-upload-label"><?= e($label) ?></span>
        <span class="quick-upload-state" data-quick-upload-state>Файл не выбран</span>
        <span class="btn btn-secondary quick-upload-button">Выбрать</span>
        <input type="file" name="quick_docs[<?= e($entity) ?>][<?= e($code) ?>][]" accept=".pdf,.doc,.docx,.rtf,.odt,.xls,.xlsx,.csv,.ods,.jpg,.jpeg,.png,.webp,.gif,.bmp,.tif,.tiff,.txt" multiple hidden data-quick-file-input>
    </label>
<?php };
?>

<form id="route-executor-quick-create-form"
      action="<?= e(app_url('/company/route-executors/quick-create')) ?>"
      method="post"
      enctype="multipart/form-data"
      data-route-executor-quick-form
      data-quick-mode="<?= e($quickMode) ?>">
    <input type="hidden" name="quick_mode" value="<?= e($quickMode) ?>">

    <?php if ($quickFormError): ?>
    <div class="form-alert alert-error quick-create-alert">
        <div class="alert-body">
            <div class="alert-body-title">Проверьте форму</div>
            <div class="alert-body-sub"><?= e($quickFormError) ?></div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($quickMode === RouteExecutorQuickCreateService::MODE_EXISTING_CONTRACTOR): ?>
    <section class="quick-contractor-step<?= $quickStartAtDetails ? ' is-hidden' : '' ?>" data-quick-contractor-step>
        <div class="quick-step-copy">
            <span class="quick-step-kicker">Шаг 1 из 2</span>
            <h3>Выберите старого подрядчика</h3>
            <p>Водитель и транспорт будут созданы и сразу привязаны к нему.</p>
        </div>
        <div class="field<?= $fieldClass('contractor_id') ?>">
            <label class="field-label" for="quick-contractor-id">Подрядчик <span class="req">*</span></label>
            <select class="field-input" id="quick-contractor-id" name="contractor_id" required<?= empty($quickContractors) ? ' disabled' : '' ?>>
                <option value="">— Выберите подрядчика —</option>
                <?php foreach ($quickContractors as $contractor): ?>
                <option value="<?= (int)$contractor['id'] ?>" <?= $value('contractor_id') === (string)$contractor['id'] ? 'selected' : '' ?>>
                    <?= e($contractor['name']) ?> · ИНН <?= e($contractor['inn'] ?? '—') ?>
                </option>
                <?php endforeach; ?>
            </select>
            <?php if (empty($quickContractors)): ?><div class="field-msg is-error">Нет доступных активных подрядчиков.</div><?php endif; ?>
            <?php if ($error('contractor_id') !== ''): ?><div class="field-msg is-error"><?= e($error('contractor_id')) ?></div><?php endif; ?>
        </div>
        <div class="quick-step-actions">
            <button type="button" class="btn btn-primary" data-quick-contractor-next<?= empty($quickContractors) ? ' disabled' : '' ?>>Продолжить</button>
        </div>
    </section>
    <?php endif; ?>

    <div class="quick-create-details<?= !$quickStartAtDetails ? ' is-hidden' : '' ?>" data-quick-details-step>
        <div class="quick-create-heading">
            <div>
                <span class="quick-step-kicker"><?= $quickMode === RouteExecutorQuickCreateService::MODE_EXISTING_CONTRACTOR ? 'Шаг 2 из 2' : 'Быстрое создание' ?></span>
                <h3><?= $quickMode === RouteExecutorQuickCreateService::MODE_NEW_CONTRACTOR ? 'Новый подрядчик, водитель и ТС' : 'Новый водитель и ТС' ?></h3>
            </div>
            <?php if ($quickMode === RouteExecutorQuickCreateService::MODE_EXISTING_CONTRACTOR): ?>
            <button type="button" class="btn btn-ghost btn-sm" data-quick-contractor-back>Сменить подрядчика</button>
            <?php endif; ?>
        </div>

        <div class="quick-create-grid<?= $quickMode === RouteExecutorQuickCreateService::MODE_EXISTING_CONTRACTOR ? ' quick-create-grid--two' : '' ?>">
            <?php if ($quickMode === RouteExecutorQuickCreateService::MODE_NEW_CONTRACTOR): ?>
            <section class="quick-entity-card quick-entity-card--contractor">
                <div class="quick-entity-head"><span class="quick-entity-index">1</span><div><h4>Подрядчик</h4><p>Реквизиты и контакт</p></div></div>
                <div class="quick-fields">
                    <div class="field<?= $fieldClass('contractor.name') ?>">
                        <label class="field-label">Наименование <span class="req">*</span></label>
                        <input class="field-input" name="contractor[name]" value="<?= e($value('contractor.name')) ?>" placeholder='ООО «ТРАНС»' required>
                        <?php if ($error('contractor.name') !== ''): ?><div class="field-msg is-error"><?= e($error('contractor.name')) ?></div><?php endif; ?>
                    </div>
                    <div class="quick-row quick-row--3">
                        <div class="field<?= $fieldClass('contractor.inn') ?>"><label class="field-label">ИНН <span class="req">*</span></label><input class="field-input" name="contractor[inn]" inputmode="numeric" value="<?= e($value('contractor.inn')) ?>" required><?php if ($error('contractor.inn') !== ''): ?><div class="field-msg is-error"><?= e($error('contractor.inn')) ?></div><?php endif; ?></div>
                        <div class="field<?= $fieldClass('contractor.kpp') ?>"><label class="field-label">КПП</label><input class="field-input" name="contractor[kpp]" inputmode="numeric" value="<?= e($value('contractor.kpp')) ?>"><?php if ($error('contractor.kpp') !== ''): ?><div class="field-msg is-error"><?= e($error('contractor.kpp')) ?></div><?php endif; ?></div>
                        <div class="field<?= $fieldClass('contractor.ogrn') ?>"><label class="field-label">ОГРН / ОГРНИП</label><input class="field-input" name="contractor[ogrn]" inputmode="numeric" value="<?= e($value('contractor.ogrn')) ?>"><?php if ($error('contractor.ogrn') !== ''): ?><div class="field-msg is-error"><?= e($error('contractor.ogrn')) ?></div><?php endif; ?></div>
                    </div>
                    <div class="quick-row quick-row--2">
                        <div class="field"><label class="field-label">Тип</label><select class="field-input" name="contractor[contractor_type]"><option value="">Не указан</option><?php foreach (['legal_entity'=>'Юрлицо','individual'=>'ИП','self_employed'=>'Самозанятый','private_person'=>'Физлицо'] as $type=>$label): ?><option value="<?= e($type) ?>" <?= $value('contractor.contractor_type') === $type ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
                        <div class="field"><label class="field-label">Юридический адрес</label><input class="field-input" name="contractor[legal_address]" value="<?= e($value('contractor.legal_address')) ?>"></div>
                    </div>
                    <div class="quick-row quick-row--2">
                        <div class="field<?= $fieldClass('contractor.contact_phone') ?>"><label class="field-label">Телефон</label><input class="field-input" name="contractor[contact_phone]" value="<?= e($value('contractor.contact_phone')) ?>" placeholder="+7 900 000-00-00"><?php if ($error('contractor.contact_phone') !== ''): ?><div class="field-msg is-error"><?= e($error('contractor.contact_phone')) ?></div><?php endif; ?></div>
                        <div class="field<?= $fieldClass('contractor.contact_email') ?>"><label class="field-label">Email для документов</label><input class="field-input" name="contractor[contact_email]" value="<?= e($value('contractor.contact_email')) ?>" placeholder="docs@example.ru"><?php if ($error('contractor.contact_email') !== ''): ?><div class="field-msg is-error"><?= e($error('contractor.contact_email')) ?></div><?php endif; ?></div>
                    </div>
                </div>
                <details class="quick-docs"><summary>Документы подрядчика <span>необязательно</span></summary><div class="quick-upload-list"><?php $upload('contractor','company_card','Карточка предприятия'); $upload('contractor','inn_cert','ИНН'); $upload('contractor','ogrn_cert','ОГРН'); $upload('contractor','contract','Договор'); ?></div></details>
            </section>
            <?php endif; ?>

            <section class="quick-entity-card quick-entity-card--driver">
                <div class="quick-entity-head"><span class="quick-entity-index"><?= $quickMode === RouteExecutorQuickCreateService::MODE_NEW_CONTRACTOR ? '2' : '1' ?></span><div><h4>Водитель</h4><p>Основные данные и документы</p></div></div>
                <div class="quick-fields">
                    <div class="field<?= $fieldClass('driver.full_name') ?>"><label class="field-label">ФИО <span class="req">*</span></label><input class="field-input" name="driver[full_name]" value="<?= e($value('driver.full_name')) ?>" placeholder="Фамилия Имя Отчество" required><?php if ($error('driver.full_name') !== ''): ?><div class="field-msg is-error"><?= e($error('driver.full_name')) ?></div><?php endif; ?></div>
                    <div class="quick-row quick-row--2">
                        <div class="field<?= $fieldClass('driver.phone') ?>"><label class="field-label">Телефон <span class="req">*</span></label><input class="field-input" name="driver[phone]" value="<?= e($value('driver.phone')) ?>" placeholder="+7 900 000-00-00" required><?php if ($error('driver.phone') !== ''): ?><div class="field-msg is-error"><?= e($error('driver.phone')) ?></div><?php endif; ?></div>
                        <div class="field<?= $fieldClass('driver.email') ?>"><label class="field-label">Email</label><input class="field-input" name="driver[email]" value="<?= e($value('driver.email')) ?>"><?php if ($error('driver.email') !== ''): ?><div class="field-msg is-error"><?= e($error('driver.email')) ?></div><?php endif; ?></div>
                    </div>
                    <div class="quick-row quick-row--2">
                        <div class="field<?= $fieldClass('driver.passport_number') ?>"><label class="field-label">Паспорт</label><input class="field-input" name="driver[passport_number]" value="<?= e($value('driver.passport_number')) ?>" placeholder="0000 000000"><?php if ($error('driver.passport_number') !== ''): ?><div class="field-msg is-error"><?= e($error('driver.passport_number')) ?></div><?php endif; ?></div>
                        <div class="field<?= $fieldClass('driver.license_number') ?>"><label class="field-label">Водительское удостоверение</label><input class="field-input" name="driver[license_number]" value="<?= e($value('driver.license_number')) ?>" placeholder="0000 000000"><?php if ($error('driver.license_number') !== ''): ?><div class="field-msg is-error"><?= e($error('driver.license_number')) ?></div><?php endif; ?></div>
                    </div>
                </div>
                <details class="quick-docs"><summary>Документы водителя <span>необязательно</span></summary><div class="quick-upload-list"><?php $upload('driver','passport','Паспорт'); $upload('driver','driver_license','Водительское удостоверение'); $upload('driver','snils','СНИЛС'); ?></div></details>
            </section>

            <section class="quick-entity-card quick-entity-card--vehicle">
                <div class="quick-entity-head"><span class="quick-entity-index"><?= $quickMode === RouteExecutorQuickCreateService::MODE_NEW_CONTRACTOR ? '3' : '2' ?></span><div><h4>Транспорт</h4><p>ТС или комплект</p></div></div>
                <div class="quick-fields">
                    <div class="field<?= $fieldClass('vehicle.set_type') ?>"><label class="field-label">Тип <span class="req">*</span></label><select class="field-input" name="vehicle[set_type]" data-quick-set-type><option value="single" <?= $value('vehicle.set_type','single') === 'single' ? 'selected' : '' ?>>Одиночное ТС</option><option value="coupling" <?= $value('vehicle.set_type') === 'coupling' ? 'selected' : '' ?>>Сцепка</option><option value="road_train" <?= $value('vehicle.set_type') === 'road_train' ? 'selected' : '' ?>>Автопоезд</option></select><?php if ($error('vehicle.set_type') !== ''): ?><div class="field-msg is-error"><?= e($error('vehicle.set_type')) ?></div><?php endif; ?></div>
                    <div class="quick-unit-block">
                        <span class="quick-unit-title" data-quick-primary-title>Транспортная единица</span>
                        <div class="quick-row quick-row--2"><div class="field<?= $fieldClass('vehicle.primary.plate_number') ?>"><label class="field-label">Госномер <span class="req">*</span></label><input class="field-input" name="vehicle[primary][plate_number]" value="<?= e($value('vehicle.primary.plate_number')) ?>" required><?php if ($error('vehicle.primary.plate_number') !== ''): ?><div class="field-msg is-error"><?= e($error('vehicle.primary.plate_number')) ?></div><?php endif; ?></div><div class="field<?= $fieldClass('vehicle.primary.vin') ?>"><label class="field-label">VIN</label><input class="field-input" name="vehicle[primary][vin]" value="<?= e($value('vehicle.primary.vin')) ?>"><?php if ($error('vehicle.primary.vin') !== ''): ?><div class="field-msg is-error"><?= e($error('vehicle.primary.vin')) ?></div><?php endif; ?></div></div>
                        <div class="quick-row quick-row--2"><div class="field"><label class="field-label">Марка</label><input class="field-input" name="vehicle[primary][brand]" value="<?= e($value('vehicle.primary.brand')) ?>"></div><div class="field"><label class="field-label">Модель</label><input class="field-input" name="vehicle[primary][model]" value="<?= e($value('vehicle.primary.model')) ?>"></div></div>
                        <div class="quick-row quick-row--2"><div class="field<?= $fieldClass('vehicle.primary.capacity_tons') ?>"><label class="field-label">Грузоподъёмность, т</label><input class="field-input" name="vehicle[primary][capacity_tons]" value="<?= e($value('vehicle.primary.capacity_tons')) ?>" inputmode="decimal"><?php if ($error('vehicle.primary.capacity_tons') !== ''): ?><div class="field-msg is-error"><?= e($error('vehicle.primary.capacity_tons')) ?></div><?php endif; ?></div><div class="field<?= $fieldClass('vehicle.primary.volume_m3') ?>"><label class="field-label">Объём, м³</label><input class="field-input" name="vehicle[primary][volume_m3]" value="<?= e($value('vehicle.primary.volume_m3')) ?>" inputmode="decimal"><?php if ($error('vehicle.primary.volume_m3') !== ''): ?><div class="field-msg is-error"><?= e($error('vehicle.primary.volume_m3')) ?></div><?php endif; ?></div></div>
                        <details class="quick-docs"><summary>Документы основной части <span>необязательно</span></summary><div class="quick-upload-list"><?php $upload('primary','sts','СТС'); $upload('primary','diagnostic_card','Диагностическая карта'); $upload('primary','photo','Фотография'); ?></div></details>
                    </div>
                    <div class="quick-unit-block quick-secondary<?= in_array($value('vehicle.set_type','single'), ['coupling','road_train'], true) ? '' : ' is-hidden' ?>" data-quick-secondary>
                        <span class="quick-unit-title" data-quick-secondary-title>Полуприцеп</span>
                        <div class="quick-row quick-row--2"><div class="field<?= $fieldClass('vehicle.secondary.plate_number') ?>"><label class="field-label">Госномер <span class="req">*</span></label><input class="field-input" name="vehicle[secondary][plate_number]" value="<?= e($value('vehicle.secondary.plate_number')) ?>" data-quick-secondary-required><?php if ($error('vehicle.secondary.plate_number') !== ''): ?><div class="field-msg is-error"><?= e($error('vehicle.secondary.plate_number')) ?></div><?php endif; ?></div><div class="field<?= $fieldClass('vehicle.secondary.vin') ?>"><label class="field-label">VIN</label><input class="field-input" name="vehicle[secondary][vin]" value="<?= e($value('vehicle.secondary.vin')) ?>"><?php if ($error('vehicle.secondary.vin') !== ''): ?><div class="field-msg is-error"><?= e($error('vehicle.secondary.vin')) ?></div><?php endif; ?></div></div>
                        <div class="quick-row quick-row--2"><div class="field"><label class="field-label">Марка</label><input class="field-input" name="vehicle[secondary][brand]" value="<?= e($value('vehicle.secondary.brand')) ?>"></div><div class="field"><label class="field-label">Модель</label><input class="field-input" name="vehicle[secondary][model]" value="<?= e($value('vehicle.secondary.model')) ?>"></div></div>
                        <div class="quick-row quick-row--2"><div class="field<?= $fieldClass('vehicle.secondary.capacity_tons') ?>"><label class="field-label">Грузоподъёмность, т</label><input class="field-input" name="vehicle[secondary][capacity_tons]" value="<?= e($value('vehicle.secondary.capacity_tons')) ?>" inputmode="decimal"><?php if ($error('vehicle.secondary.capacity_tons') !== ''): ?><div class="field-msg is-error"><?= e($error('vehicle.secondary.capacity_tons')) ?></div><?php endif; ?></div><div class="field<?= $fieldClass('vehicle.secondary.volume_m3') ?>"><label class="field-label">Объём, м³</label><input class="field-input" name="vehicle[secondary][volume_m3]" value="<?= e($value('vehicle.secondary.volume_m3')) ?>" inputmode="decimal"><?php if ($error('vehicle.secondary.volume_m3') !== ''): ?><div class="field-msg is-error"><?= e($error('vehicle.secondary.volume_m3')) ?></div><?php endif; ?></div></div>
                        <details class="quick-docs"><summary>Документы дополнительной части <span>необязательно</span></summary><div class="quick-upload-list"><?php $upload('secondary','sts','СТС'); $upload('secondary','diagnostic_card','Диагностическая карта'); $upload('secondary','photo','Фотография'); ?></div></details>
                    </div>
                </div>
            </section>
        </div>

        <?php $documentErrors = array_filter($quickErrors, static fn($key): bool => str_starts_with((string)$key, 'documents.'), ARRAY_FILTER_USE_KEY); ?>
        <?php if ($documentErrors): ?><div class="notice warn quick-document-errors"><?= e(implode(' ', array_values($documentErrors))) ?></div><?php endif; ?>
    </div>
</form>
