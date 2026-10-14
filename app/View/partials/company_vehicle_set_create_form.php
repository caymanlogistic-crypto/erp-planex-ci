<?php
$vehicleSetRules = function_exists('vehicleSetTypeRules') ? vehicleSetTypeRules() : [];
$predefinedSections = function_exists('vehicleSetPredefinedDocumentSections')
    ? vehicleSetPredefinedDocumentSections()
    : ['primary' => [], 'secondary' => []];
$formError = $formError ?? null;
$errors = $errors ?? [];
$old = $old ?? [];
$docErrors = $docErrors ?? [];
$docTypes = $docTypes ?? [];
$selectedSetType = (string) ($old['set_type'] ?? '');
$currentRule = $vehicleSetRules[$selectedSetType] ?? null;
$visibleRoles = !empty($currentRule['units']) && is_array($currentRule['units'])
    ? array_keys($currentRule['units'])
    : [];
$unitDefaults = [
    'plate_number' => '',
    'brand' => '',
    'model' => '',
    'vin' => '',
    'capacity_tons' => '',
    'volume_m3' => '',
    'diagnostic_card_number' => '',
    'diagnostic_card_date' => '',
    'unit_type' => '',
];
$unitValues = [];
foreach (['primary', 'secondary'] as $role) {
    $posted = isset($old['units'][$role]) && is_array($old['units'][$role])
        ? $old['units'][$role]
        : [];
    $unitValues[$role] = array_merge($unitDefaults, $posted);
}
$customDocRowsByRole = ['primary' => [], 'secondary' => []];
$oldCustomTypes = isset($old['custom_doc_type']) && is_array($old['custom_doc_type'])
    ? $old['custom_doc_type']
    : [];
$oldCustomNewTypes = isset($old['custom_doc_type_new']) && is_array($old['custom_doc_type_new'])
    ? $old['custom_doc_type_new']
    : [];
foreach (['primary', 'secondary'] as $role) {
    $roleTypes = isset($oldCustomTypes[$role]) && is_array($oldCustomTypes[$role])
        ? $oldCustomTypes[$role]
        : [];
    $roleNewTypes = isset($oldCustomNewTypes[$role]) && is_array($oldCustomNewTypes[$role])
        ? $oldCustomNewTypes[$role]
        : [];
    $rowCount = max(count($roleTypes), count($roleNewTypes));
    for ($i = 0; $i < $rowCount; $i++) {
        $selectedType = trim((string) ($roleTypes[$i] ?? ''));
        $newType = trim((string) ($roleNewTypes[$i] ?? ''));
        if ($selectedType === '' && $newType === '') {
            continue;
        }
        $customDocRowsByRole[$role][] = [
            'selected' => $selectedType,
            'new' => $newType,
            'visible' => $selectedType !== '' ? $selectedType : $newType,
        ];
    }
}
$getUnitError = static function (array $errors, string $role, string $field): string {
    return (string) ($errors['units'][$role][$field] ?? '');
};
$getDocumentDisplayTitle = static function (string $role, array $pdoc): string {
    $code = (string) ($pdoc['doc_code'] ?? '');

    return match ($code) {
        'sts', 'registration_certificate' => 'СТС',
        'diagnostic_card' => 'Диагностическая карта',
        'photo' => 'Фотография',
        default => (string) ($pdoc['name'] ?? ''),
    };
};
$getUnitPresentation = static function (string $setType, string $role, ?array $unitConfig): array {
    $label = (string) ($unitConfig['label'] ?? ($role === 'secondary' ? 'Дополнительная единица' : 'Основная единица'));
    $docsTitle = (string) ($unitConfig['docs_title'] ?? ($role === 'secondary' ? 'Документы дополнительной единицы' : 'Документы транспортной единицы'));

    if ($setType === 'single') {
        return [
            'label' => 'ТРАНСПОРТНАЯ ЕДИНИЦА',
            'docs_title' => 'Документы транспортной единицы',
            'custom_docs_title' => 'Произвольные документы транспортной единицы',
        ];
    }
    if ($setType === 'coupling') {
        return [
            'label' => $role === 'secondary' ? 'ПОЛУПРИЦЕП' : 'ТЯГАЧ',
            'docs_title' => $role === 'secondary' ? 'Документы полуприцепа' : 'Документы тягача',
            'custom_docs_title' => $role === 'secondary' ? 'Произвольные документы полуприцепа' : 'Произвольные документы тягача',
        ];
    }
    if ($setType === 'road_train') {
        return [
            'label' => $role === 'secondary' ? 'ПРИЦЕП' : 'ПЕРВАЯ ЧАСТЬ',
            'docs_title' => $role === 'secondary' ? 'Документы прицепа' : 'Документы первой части',
            'custom_docs_title' => $role === 'secondary' ? 'Произвольные документы прицепа' : 'Произвольные документы первой части',
        ];
    }

    return [
        'label' => $label,
        'docs_title' => $docsTitle,
        'custom_docs_title' => 'Произвольные документы',
    ];
};
$isCouplingSelected = $selectedSetType === 'coupling';
$vehicleSetCreateFormMode = $vehicleSetCreateFormMode ?? 'page';
$vehicleSetFormId = $vehicleSetFormId ?? 'vehicle-set-create-form';
$vehicleSetFormAction = $vehicleSetFormAction ?? '/company/vehicle-sets/create';
$vehicleSetFormClass = $vehicleSetFormClass ?? 'vehicle-set-form';
?>
<form id="<?= $vehicleSetFormId ?>" method="post" action="<?= $vehicleSetFormAction ?>" class="<?= $vehicleSetFormClass ?>" enctype="multipart/form-data" novalidate>
    <div class="vehicle-set-card-stack">
        <div class="vehicle-set-form-card vehicle-set-type-card">
            <?php if ($formError): ?>
            <div class="form-alert alert-error">
                <div class="alert-body">
                    <div class="alert-body-title">Ошибка при сохранении</div>
                    <div class="alert-body-sub"><?= e($formError) ?></div>
                </div>
            </div>
            <?php endif; ?>
            <?php if (!empty($docErrors)): ?>
            <div class="form-alert alert-warning">
                <div class="alert-body">
                    <div class="alert-body-title">Проверьте документы</div>
                    <div class="alert-body-sub"><?= e(implode(' | ', $docErrors)) ?></div>
                </div>
            </div>
            <?php endif; ?>

            <div class="field<?= !empty($errors['set_type']) ? ' is-error' : '' ?>">
                <label class="field-label" for="set_type_select">
                    Тип комплекта <span class="req">*</span>
                </label>
                <select name="set_type" id="set_type_select" class="field-input">
                    <option value="">- Выберите тип -</option>
                    <?php foreach ($vehicleSetRules as $ruleCode => $ruleMeta): ?>
                    <option value="<?= e($ruleCode) ?>" <?= $selectedSetType === $ruleCode ? 'selected' : '' ?>>
                        <?= e($ruleMeta['label']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <div class="field-msg" data-field-error="set_type">
                    <?= !empty($errors['set_type']) ? e($errors['set_type']) : '' ?>
                </div>
            </div>
        </div>

        <div id="vehicle-set-dynamic-fields" class="vehicle-set-card-list<?= empty($visibleRoles) ? ' is-hidden' : '' ?>">
            <?php foreach (['primary', 'secondary'] as $role): ?>
            <?php $unitConfig = $currentRule['units'][$role] ?? null; ?>
            <?php $unitPresentation = $getUnitPresentation($selectedSetType, $role, $unitConfig); ?>
            <div class="vehicle-set-form-card vehicle-set-unit-panel<?= $unitConfig ? '' : ' is-hidden' ?>" data-unit-role="<?= e($role) ?>">
                <div class="section-title" data-unit-title>
                    <?= e($unitPresentation['label']) ?>
                </div>
                <div class="vehicle-set-unit-card-body">
                    <div class="vehicle-set-unit-fields">
                        <input
                            type="hidden"
                            name="units[<?= e($role) ?>][unit_type]"
                            value="<?= e($unitConfig['unit_type'] ?? '') ?>"
                            data-unit-type-input
                        >

                        <div class="field-row field-row-group">
                            <div class="field field-w-license<?= $getUnitError($errors, $role, 'brand') !== '' ? ' is-error' : '' ?>">
                                <label class="field-label">Марка <span class="req" data-required-mark>*</span></label>
                                <input
                                    type="text"
                                    name="units[<?= e($role) ?>][brand]"
                                    class="field-input"
                                    value="<?= e($unitValues[$role]['brand'] ?? '') ?>"
                                    placeholder="<?= $role === 'secondary' ? 'SCHMITZ' : 'SCANIA' ?>"
                                    data-required-when-visible
                                >
                                <div class="field-msg"><?= e($getUnitError($errors, $role, 'brand')) ?></div>
                            </div>

                            <div class="field field-w-license<?= $getUnitError($errors, $role, 'model') !== '' ? ' is-error' : '' ?>">
                                <label class="field-label">Модель <span class="req" data-required-mark>*</span></label>
                                <input
                                    type="text"
                                    name="units[<?= e($role) ?>][model]"
                                    class="field-input"
                                    value="<?= e($unitValues[$role]['model'] ?? '') ?>"
                                    placeholder="<?= $role === 'secondary' ? 'S.KO' : 'R440' ?>"
                                    data-required-when-visible
                                >
                                <div class="field-msg"><?= e($getUnitError($errors, $role, 'model')) ?></div>
                            </div>

                            <div class="field field-w-passport<?= $getUnitError($errors, $role, 'plate_number') !== '' ? ' is-error' : '' ?>">
                                <label class="field-label">Госномер <span class="req" data-required-mark>*</span></label>
                                <input
                                    type="text"
                                    name="units[<?= e($role) ?>][plate_number]"
                                    class="field-input"
                                    value="<?= e($unitValues[$role]['plate_number'] ?? '') ?>"
                                    placeholder="<?= $role === 'secondary' ? 'АВ123477' : 'А123ВС77' ?>"
                                    data-required-when-visible
                                    data-plate-field
                                    data-plate-role="<?= e($role) ?>"
                                >
                                <div class="field-msg"><?= e($getUnitError($errors, $role, 'plate_number')) ?></div>
                            </div>
                        </div>

                        <div class="field-row field-row-group">
                            <div class="field field-w-issued-by<?= $getUnitError($errors, $role, 'vin') !== '' ? ' is-error' : '' ?>">
                                <label class="field-label">VIN код <span class="req" data-required-mark>*</span></label>
                                <input
                                    type="text"
                                    name="units[<?= e($role) ?>][vin]"
                                    class="field-input"
                                    value="<?= e($unitValues[$role]['vin'] ?? '') ?>"
                                    placeholder="<?= $role === 'secondary' ? 'WSM00000000000000' : 'X9F12345678901234' ?>"
                                    data-required-when-visible
                                >
                                <div class="field-msg"><?= e($getUnitError($errors, $role, 'vin')) ?></div>
                            </div>

                            <div class="field field-w-email field-grow<?= $getUnitError($errors, $role, 'diagnostic_card_number') !== '' ? ' is-error' : '' ?>">
                                <label class="field-label">Диагностическая карта <span class="req" data-required-mark>*</span></label>
                                <input
                                    type="text"
                                    name="units[<?= e($role) ?>][diagnostic_card_number]"
                                    class="field-input"
                                    value="<?= e($unitValues[$role]['diagnostic_card_number'] ?? '') ?>"
                                    placeholder="123456789012345"
                                    data-required-when-visible
                                >
                                <div class="field-msg"><?= e($getUnitError($errors, $role, 'diagnostic_card_number')) ?></div>
                            </div>

                            <div class="field field-w-date<?= $getUnitError($errors, $role, 'diagnostic_card_date') !== '' ? ' is-error' : '' ?>">
                                <label class="field-label">Дата получения <span class="req" data-required-mark>*</span></label>
                                <input
                                    type="text"
                                    name="units[<?= e($role) ?>][diagnostic_card_date]"
                                    class="field-input js-erp-date-picker"
                                    placeholder="дд.мм.гггг"
                                    inputmode="numeric"
                                    autocomplete="off"
                                    value="<?= e($unitValues[$role]['diagnostic_card_date'] ?? '') ?>"
                                    data-required-when-visible
                                >
                                <div class="field-msg"><?= e($getUnitError($errors, $role, 'diagnostic_card_date')) ?></div>
                            </div>
                        </div>

                        <div class="field-row field-row-group<?= !empty($unitConfig) && (empty($unitConfig['show_capacity']) && empty($unitConfig['show_volume'])) ? ' is-hidden' : '' ?>" data-capacity-row>
                            <div class="field field-w-code<?= $getUnitError($errors, $role, 'capacity_tons') !== '' ? ' is-error' : '' ?><?= !empty($unitConfig) && empty($unitConfig['show_capacity']) ? ' is-hidden' : '' ?>" data-capacity-field>
                                <label class="field-label">Грузоподъёмность <span class="req" data-required-mark>*</span></label>
                                <input
                                    type="text"
                                    inputmode="decimal"
                                    name="units[<?= e($role) ?>][capacity_tons]"
                                    class="field-input"
                                    value="<?= e($unitValues[$role]['capacity_tons'] ?? '') ?>"
                                    placeholder="20"
                                    data-required-when-visible
                                >
                                <div class="field-msg"><?= e($getUnitError($errors, $role, 'capacity_tons')) ?></div>
                            </div>

                            <div class="field field-w-code<?= $getUnitError($errors, $role, 'volume_m3') !== '' ? ' is-error' : '' ?><?= !empty($unitConfig) && empty($unitConfig['show_volume']) ? ' is-hidden' : '' ?>" data-volume-field>
                                <label class="field-label">Объём кузова <span class="req" data-required-mark>*</span></label>
                                <input
                                    type="text"
                                    inputmode="decimal"
                                    name="units[<?= e($role) ?>][volume_m3]"
                                    class="field-input"
                                    value="<?= e($unitValues[$role]['volume_m3'] ?? '') ?>"
                                    placeholder="86"
                                    data-required-when-visible
                                >
                                <div class="field-msg"><?= e($getUnitError($errors, $role, 'volume_m3')) ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="vehicle-set-unit-docs">
                        <div class="vehicle-doc-section" data-doc-role="<?= e($role) ?>">
                            <div class="file-list">
                                <?php foreach ($predefinedSections[$role] ?? [] as $pdoc): ?>
                                <div class="file-item file-item-predef document-file-row is-empty" data-doc-kind="predef" data-doc-role="<?= e($role) ?>" data-doc-code="<?= e($pdoc['doc_code']) ?>" data-default-badge="-">
                                    <div class="document-file-badge file-type-badge file-type-badge-empty">—</div>
                                    <div class="document-file-info file-info">
                                        <div class="document-file-title file-name"><?= e($getDocumentDisplayTitle($role, $pdoc)) ?></div>
                                        <div class="document-file-meta file-meta">Файл не выбран</div>
                                        <div class="field-msg"></div>
                                    </div>
                                    <div class="document-file-actions">
                                        <label class="btn btn-secondary file-action-btn">
                                            <input
                                                type="file"
                                                name="predef_doc[<?= e($role) ?>][<?= e($pdoc['doc_code']) ?>][]"
                                                class="document-file-input"
                                                hidden
                                                multiple
                                            >
                                            <span>Выбрать</span>
                                        </label>
                                        <button type="button" class="document-file-clear predef-file-clear is-hidden" title="Очистить файл">&times;</button>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>

                            <div class="form-section vehicle-custom-docs">
                                <div id="custom-docs-container-<?= e($role) ?>" class="file-list custom-docs-container" data-role="<?= e($role) ?>">
                                    <?php foreach ($customDocRowsByRole[$role] as $idx => $row): ?>
                                    <?php $customInputId = 'custom-doc-input-' . $role . '-' . $idx; ?>
                                    <?php $customBadgeId = 'custom-doc-badge-' . $role . '-' . $idx; ?>
                                    <?php $customMetaId = 'custom-doc-meta-' . $role . '-' . $idx; ?>
                                    <?php $customButtonId = 'custom-doc-btn-' . $role . '-' . $idx; ?>
                                    <div class="file-item custom-doc-row document-file-row is-empty" data-doc-kind="custom" data-doc-role="<?= e($role) ?>">
                                        <div class="file-type-badge file-type-badge-empty" id="<?= e($customBadgeId) ?>">—</div>
                                        <div class="file-info">
                                            <div class="field custom-doc-title-field">
                                                <input type="text" class="field-input custom-doc-type-input" value="<?= e($row['visible']) ?>" placeholder="Введите название">
                                                <div class="custom-doc-suggestions"></div>
                                                <div class="field-msg"></div>
                                            </div>
                                            <div class="file-meta is-hidden" id="<?= e($customMetaId) ?>"></div>
                                            <input type="hidden" name="custom_doc_type[<?= e($role) ?>][]" class="custom-doc-selected-type" value="<?= e($row['selected']) ?>">
                                            <input type="hidden" name="custom_doc_type_new[<?= e($role) ?>][]" class="custom-doc-new-type" value="<?= e($row['new']) ?>">
                                        </div>
                                        <button type="button" class="btn btn-secondary file-action-btn js-custom-file-pick-btn" data-file-input="<?= e($customInputId) ?>">
                                            <span id="<?= e($customButtonId) ?>">Выбрать</span>
                                        </button>
                                        <input
                                            type="file"
                                            id="<?= e($customInputId) ?>"
                                            name="custom_doc_file[<?= e($role) ?>][]"
                                            class="file-input-hidden document-file-input js-custom-file-input"
                                            data-label="<?= e($customMetaId) ?>"
                                            data-badge="<?= e($customBadgeId) ?>"
                                            data-button-label="<?= e($customButtonId) ?>"
                                        >
                                        <button type="button" class="file-remove" title="Удалить документ">&times;</button>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <button type="button" class="btn btn-ghost add-custom-doc-btn" data-role="<?= e($role) ?>">
                                    + Добавить документ
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <div id="vehicle-set-comment-card" class="vehicle-set-form-card vehicle-set-comment-card<?= empty($visibleRoles) ? ' is-hidden' : '' ?>">
            <input type="hidden" name="status" value="<?= e($old['status'] ?? 'active') ?>">
            <div class="field field-w-comment vehicle-set-comments-field">
                <label class="field-label" for="vehicle_set_comments">Комментарий</label>
                <textarea
                    name="comments"
                    id="vehicle_set_comments"
                    class="field-input field-textarea vehicle-set-textarea"
                    rows="3"
                    placeholder="Примечания по транспорту"
                ><?= e($old['comments'] ?? '') ?></textarea>
                <div class="field-msg"></div>
            </div>

            <?php if ($vehicleSetCreateFormMode === 'page'): ?>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary" id="vehicle-set-submit-btn">
                    Создать транспорт
                </button>
            </div>
            <?php endif; ?>
        </div>
    </div>
</form>
<script>
(function () {
    var form = document.getElementById('<?= $vehicleSetFormId ?>');
    if (!form) return;

    var rules = <?= json_encode($vehicleSetRules, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    var docTypes = <?= json_encode($docTypes ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
    var allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'doc', 'docx', 'rtf', 'odt', 'xls', 'xlsx', 'csv', 'ods', 'gif', 'bmp', 'tif', 'tiff', 'heic', 'heif'];
    var maxSize = 20 * 1024 * 1024;
    var setType = document.getElementById('set_type_select');
    var dynamicFields = document.getElementById('vehicle-set-dynamic-fields');
    var dynamicDocs = dynamicFields;
    var commentCard = document.getElementById('vehicle-set-comment-card');
    var submitButton = document.getElementById('vehicle-set-submit-btn');
    var monthNames = [
        'Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь',
        'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь'
    ];
    var weekdays = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];
    var activeDateInput = null;
    var viewYear = 0;
    var viewMonth = 0;
    var plateLetterMap = {
        'A': 'А',
        'B': 'В',
        'E': 'Е',
        'K': 'К',
        'M': 'М',
        'H': 'Н',
        'O': 'О',
        'P': 'Р',
        'C': 'С',
        'T': 'Т',
        'Y': 'У',
        'X': 'Х'
    };
    var tractorPlatePattern = /^[АВЕКМНОРСТУХ]\d{3}[АВЕКМНОРСТУХ]{2}\d{2,3}$/;
    var trailerPlatePattern = /^[АВЕКМНОРСТУХ]{2}\d{4}\d{2,3}$/;

    function getRule() {
        return rules[setType.value] || null;
    }

    function getUnitPresentation(role, config) {
        if (!config) return null;
        if (setType.value === 'single') {
            return {
                label: 'ТРАНСПОРТНАЯ ЕДИНИЦА',
                docs_title: 'Документы транспортной единицы'
            };
        }
        if (setType.value === 'coupling') {
            return {
                label: role === 'secondary' ? 'ПОЛУПРИЦЕП' : 'ТЯГАЧ',
                docs_title: role === 'secondary' ? 'Документы полуприцепа' : 'Документы тягача'
            };
        }
        if (setType.value === 'road_train') {
            return {
                label: role === 'secondary' ? 'ПРИЦЕП' : 'ПЕРВАЯ ЧАСТЬ',
                docs_title: role === 'secondary' ? 'Документы прицепа' : 'Документы первой части'
            };
        }
        return {
            label: config.label || '',
            docs_title: config.docs_title || ''
        };
    }

    function isCouplingMode() {
        return setType.value === 'coupling';
    }

    function getExtension(name) {
        var parts = String(name || '').toLowerCase().split('.');
        return parts.length > 1 ? parts.pop() : '';
    }

    function getKind(fileName) {
        var ext = getExtension(fileName);
        if (ext === 'pdf') return 'PDF';
        if (['doc', 'docx', 'rtf', 'odt'].indexOf(ext) !== -1) return 'DOC';
        if (['xls', 'xlsx', 'csv', 'ods'].indexOf(ext) !== -1) return 'XLS';
        if (['jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp', 'tif', 'tiff', 'heic', 'heif'].indexOf(ext) !== -1) return 'IMG';
        return '-';
    }

    function badgeClass(kind) {
        if (kind === 'PDF') return 'is-pdf';
        if (kind === 'XLS') return 'is-xls';
        if (kind === 'IMG') return 'is-img';
        if (kind === 'DOC') return 'is-doc';
        return 'file-type-badge-empty';
    }

    function summarizeFiles(files) {
        if (!files || !files.length) return 'Файл не выбран';
        return files.length === 1 ? files[0].name : 'Выбрано файлов: ' + files.length;
    }

    function validateFile(file) {
        if (!file) return 'Файл не выбран';
        var ext = getExtension(file.name);
        if (allowedExtensions.indexOf(ext) === -1) return 'Недопустимый формат файла';
        if (file.size > maxSize) return 'Файл превышает 20 МБ';
        return '';
    }

    function clearRowError(row) {
        row.classList.remove('is-error', 'has-error');
        var msg = row.querySelector('.field-msg');
        if (msg) msg.textContent = '';
    }

    function setRowError(row, message) {
        row.classList.add('is-error', 'has-error');
        var msg = row.querySelector('.field-msg');
        if (msg) msg.textContent = message || '';
    }

    function normalizePlateValue(value) {
        var normalized = String(value || '').toUpperCase().replace(/\s+/g, '');
        return normalized.replace(/[ABEKMHOPCTYX]/g, function (letter) {
            return plateLetterMap[letter] || letter;
        });
    }

    function pad2(value) {
        return String(value).padStart(2, '0');
    }

    function collapseSpaces(value) {
        return String(value || '').trim().replace(/\s+/g, ' ');
    }

    function normalizeBrandValue(value) {
        return String(value || '').trim().toUpperCase();
    }

    function normalizeModelValue(value) {
        return String(value || '').trim();
    }

    function normalizeVinValue(value) {
        return String(value || '').trim().replace(/\s+/g, '').toUpperCase();
    }

    function normalizeDigitsValue(value) {
        return String(value || '').trim().replace(/\s+/g, '');
    }

    function normalizeDecimalValue(value) {
        return String(value || '').trim().replace(/\s+/g, '').replace(',', '.');
    }

    function stripDateTail(value) {
        return collapseSpaces(value).replace(/\s*(г(?:\.|ода?|од)?)\s*$/i, '').trim();
    }

    function datePartsToObject(day, month, year) {
        var date = new Date(year, month - 1, day);
        if (date.getFullYear() !== year || date.getMonth() !== month - 1 || date.getDate() !== day) return null;
        return {
            day: day,
            month: month,
            year: year,
            date: date,
            display: pad2(day) + '.' + pad2(month) + '.' + year,
            iso: year + '-' + pad2(month) + '-' + pad2(day)
        };
    }

    function expandYear(year) {
        if (year < 100) return year <= 49 ? 2000 + year : 1900 + year;
        return year;
    }

    function parseDateValue(value) {
        var raw = stripDateTail(value);
        var match;
        if (!raw) return null;

        match = /^(\d{8})$/.exec(raw);
        if (match) {
            return datePartsToObject(
                Number(raw.slice(0, 2)),
                Number(raw.slice(2, 4)),
                Number(raw.slice(4, 8))
            );
        }

        match = /^(\d{4})[./-](\d{1,2})[./-](\d{1,2})$/.exec(raw);
        if (match) {
            return datePartsToObject(Number(match[3]), Number(match[2]), Number(match[1]));
        }

        match = /^(\d{1,2})[\s./-](\d{1,2})[\s./-](\d{2}|\d{4})$/.exec(raw);
        if (match) {
            return datePartsToObject(Number(match[1]), Number(match[2]), expandYear(Number(match[3])));
        }

        return null;
    }

    function normalizeVehicleDateInput(input, silent) {
        var value = collapseSpaces(input.value);
        if (!value) {
            input.value = '';
            delete input.dataset.isoValue;
            clearFieldError(input);
            return true;
        }

        var parsed = parseDateValue(value);
        if (!parsed) {
            delete input.dataset.isoValue;
            if (!silent) setFieldError(input, 'Укажите дату в формате дд.мм.гггг');
            return false;
        }

        input.value = parsed.display;
        input.dataset.isoValue = parsed.iso;
        clearFieldError(input);
        return true;
    }

    function getDatePopup() {
        var popup = document.querySelector('.erp-date-popover');
        if (popup) return popup;

        popup = document.createElement('div');
        popup.className = 'erp-date-popover';
        popup.innerHTML =
            '<div class="erp-date-head">' +
                '<button type="button" class="erp-date-nav" data-date-action="prev" aria-label="Предыдущий месяц">‹</button>' +
                '<div class="erp-date-title"></div>' +
                '<button type="button" class="erp-date-nav" data-date-action="next" aria-label="Следующий месяц">›</button>' +
            '</div>' +
            '<div class="erp-date-weekdays"></div>' +
            '<div class="erp-date-grid"></div>' +
            '<div class="erp-date-foot">' +
                '<button type="button" class="btn btn-ghost btn-sm" data-date-action="clear">Очистить</button>' +
                '<button type="button" class="btn btn-secondary btn-sm" data-date-action="today">Сегодня</button>' +
            '</div>';
        popup.querySelector('.erp-date-weekdays').innerHTML = weekdays.map(function (day) {
            return '<span>' + day + '</span>';
        }).join('');
        document.body.appendChild(popup);

        popup.addEventListener('mousedown', function (event) {
            event.preventDefault();
        });
        popup.addEventListener('click', function (event) {
            var action = event.target.closest('[data-date-action]');
            var day = event.target.closest('[data-date-day]');
            if (!activeDateInput) return;

            if (action) {
                var kind = action.getAttribute('data-date-action');
                if (kind === 'prev') {
                    viewMonth -= 1;
                    if (viewMonth < 0) {
                        viewMonth = 11;
                        viewYear -= 1;
                    }
                    renderDatePopup();
                } else if (kind === 'next') {
                    viewMonth += 1;
                    if (viewMonth > 11) {
                        viewMonth = 0;
                        viewYear += 1;
                    }
                    renderDatePopup();
                } else if (kind === 'today') {
                    setDateInput(activeDateInput, new Date());
                    closeDatePopup();
                } else if (kind === 'clear') {
                    activeDateInput.value = '';
                    delete activeDateInput.dataset.isoValue;
                    clearFieldError(activeDateInput);
                    closeDatePopup();
                }
                return;
            }

            if (day) {
                setDateInput(activeDateInput, new Date(Number(day.dataset.year), Number(day.dataset.month), Number(day.dataset.day)));
                closeDatePopup();
            }
        });

        return popup;
    }

    function setDateInput(input, date) {
        input.value = pad2(date.getDate()) + '.' + pad2(date.getMonth() + 1) + '.' + date.getFullYear();
        input.dataset.isoValue = date.getFullYear() + '-' + pad2(date.getMonth() + 1) + '-' + pad2(date.getDate());
        clearFieldError(input);
    }

    function renderDatePopup() {
        var popup = getDatePopup();
        var grid = popup.querySelector('.erp-date-grid');
        var title = popup.querySelector('.erp-date-title');
        var first = new Date(viewYear, viewMonth, 1);
        var daysInMonth = new Date(viewYear, viewMonth + 1, 0).getDate();
        var leading = (first.getDay() + 6) % 7;
        var selected = activeDateInput ? parseDateValue(activeDateInput.value) : null;
        var today = new Date();
        var html = '';

        title.textContent = monthNames[viewMonth] + ' ' + viewYear;

        for (var i = 0; i < leading; i++) {
            html += '<span class="erp-date-empty"></span>';
        }

        for (var day = 1; day <= daysInMonth; day++) {
            var isSelected = selected && selected.year === viewYear && selected.month === viewMonth + 1 && selected.day === day;
            var isToday = today.getFullYear() === viewYear && today.getMonth() === viewMonth && today.getDate() === day;
            html += '<button type="button" class="erp-date-day' +
                (isSelected ? ' is-selected' : '') +
                (isToday ? ' is-today' : '') +
                '" data-date-day="' + day + '" data-year="' + viewYear + '" data-month="' + viewMonth + '" data-day="' + day + '">' + day + '</button>';
        }

        grid.innerHTML = html;
    }

    function openDatePopup(input) {
        activeDateInput = input;
        var parsed = parseDateValue(input.value);
        var base = parsed ? parsed.date : new Date();
        viewYear = base.getFullYear();
        viewMonth = base.getMonth();
        renderDatePopup();

        var popup = getDatePopup();
        var rect = input.getBoundingClientRect();
        popup.style.left = Math.round(rect.left + window.scrollX) + 'px';
        popup.style.top = Math.round(rect.bottom + window.scrollY + 4) + 'px';
        popup.classList.add('is-open');
    }

    function closeDatePopup() {
        var popup = document.querySelector('.erp-date-popover');
        if (popup) popup.classList.remove('is-open');
        activeDateInput = null;
    }

    function installDatePicker(input) {
        if (input.dataset.erpDateReady === '1') return;
        input.dataset.erpDateReady = '1';

        var wrap = document.createElement('div');
        wrap.className = 'erp-date-field';
        input.parentNode.insertBefore(wrap, input);
        wrap.appendChild(input);

        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'erp-date-trigger';
        button.setAttribute('aria-label', 'Открыть календарь');
        button.innerHTML = '<svg width="14" height="14" viewBox="0 0 14 14" fill="none" aria-hidden="true"><rect x="2" y="3" width="10" height="9" stroke="currentColor" stroke-width="1.2"/><path d="M4.5 1.8V4.2M9.5 1.8V4.2M2 5.2H12" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/></svg>';
        wrap.appendChild(button);

        if (input.value) normalizeVehicleDateInput(input, true);

        input.addEventListener('focus', function () {
            openDatePopup(input);
        });
        button.addEventListener('click', function () {
            openDatePopup(input);
            input.focus();
        });
    }

    function normalizeVehicleUnitField(input) {
        if (!input) return true;
        if (!isFieldVisible(input)) return true;

        var name = String(input.name || '');
        if (name.indexOf('[brand]') !== -1) {
            input.value = normalizeBrandValue(input.value);
            clearFieldError(input);
            return true;
        }
        if (name.indexOf('[model]') !== -1) {
            input.value = normalizeModelValue(input.value);
            clearFieldError(input);
            return true;
        }
        if (name.indexOf('[vin]') !== -1) {
            input.value = normalizeVinValue(input.value);
            if (input.value !== '' && !/^[A-HJ-NPR-Z0-9]{17}$/.test(input.value)) {
                setFieldError(input, 'Формат VIN: 17 символов');
                return false;
            }
            clearFieldError(input);
            return true;
        }
        if (name.indexOf('[diagnostic_card_number]') !== -1) {
            input.value = normalizeDigitsValue(input.value);
            if (input.value !== '' && !/^\d+$/.test(input.value)) {
                setFieldError(input, 'Только цифры');
                return false;
            }
            clearFieldError(input);
            return true;
        }
        if (name.indexOf('[capacity_tons]') !== -1 || name.indexOf('[volume_m3]') !== -1) {
            input.value = normalizeDecimalValue(input.value);
            if (input.value !== '') {
                if (!/^\d+(?:\.\d+)?$/.test(input.value) || Number(input.value) <= 0) {
                    setFieldError(input, 'Введите число');
                    return false;
                }
            }
            clearFieldError(input);
            return true;
        }
        if (input.classList.contains('js-erp-date-picker')) {
            return normalizeVehicleDateInput(input, false);
        }

        return true;
    }

    function clearFieldError(input) {
        var field = input ? input.closest('.field') : null;
        var msg = field ? field.querySelector('.field-msg') : null;
        if (field) field.classList.remove('is-error');
        if (msg) msg.textContent = '';
    }

    function setFieldError(input, message) {
        var field = input ? input.closest('.field') : null;
        var msg = field ? field.querySelector('.field-msg') : null;
        if (field) field.classList.add('is-error');
        if (msg) msg.textContent = message || '';
    }

    function isFieldVisible(input) {
        return !!(input && !input.closest('.is-hidden'));
    }

    function shouldRequireField(input) {
        return !!(input && isFieldVisible(input) && input.hasAttribute('data-plate-field'));
    }

    function syncRequiredMarks(panel) {
        if (!panel) return;
        panel.querySelectorAll('.field').forEach(function (field) {
            var input = field.querySelector('[data-required-when-visible]');
            var mark = field.querySelector('[data-required-mark]');
            if (!input || !mark) return;
            mark.classList.toggle('is-hidden', !shouldRequireField(input));
        });
    }

    function validatePlateField(input) {
        if (!input || !isFieldVisible(input)) return true;
        var role = input.getAttribute('data-plate-role');
        var value = normalizePlateValue(input.value);
        var pattern = role === 'secondary' ? trailerPlatePattern : tractorPlatePattern;
        var emptyMessage = 'Укажите номер';
        var formatMessage = role === 'secondary'
            ? 'Формат: АВ123477'
            : 'Формат: А123ВС77';

        input.value = value;
        clearFieldError(input);

        if (!shouldRequireField(input) && value === '') {
            return true;
        }
        if (value === '') {
            setFieldError(input, emptyMessage);
            return false;
        }
        if (!pattern.test(value)) {
            setFieldError(input, formatMessage);
            return false;
        }
        return true;
    }

    function resetRow(row) {
        var input = row.querySelector('.document-file-input');
        var badge = row.querySelector('.document-file-badge, .file-type-badge');
        var meta = row.querySelector('.document-file-meta, .file-meta');
        var label = row.querySelector('.file-action-btn span');
        var clearBtn = row.querySelector('.predef-file-clear');
        var isCustom = row.getAttribute('data-doc-kind') === 'custom';
        if (input) input.value = '';
        if (badge) {
            badge.className = isCustom ? 'file-type-badge file-type-badge-empty' : 'document-file-badge file-type-badge file-type-badge-empty';
            badge.textContent = '—';
        }
        if (meta) {
            meta.textContent = isCustom ? '' : 'Файл не выбран';
            meta.classList.toggle('is-hidden', isCustom);
        }
        if (label) label.textContent = 'Выбрать';
        if (clearBtn) clearBtn.classList.add('is-hidden');
        clearRowError(row);
        row.classList.add('is-empty');
        row.classList.remove('has-file', 'is-loading');
    }

    function paintRow(row, input) {
        var badge = row.querySelector('.document-file-badge, .file-type-badge');
        var meta = row.querySelector('.document-file-meta, .file-meta');
        var label = row.querySelector('.file-action-btn span');
        var clearBtn = row.querySelector('.predef-file-clear');
        var isCustom = row.getAttribute('data-doc-kind') === 'custom';
        clearRowError(row);
        if (!input.files || !input.files.length) {
            resetRow(row);
            return;
        }
        var firstFile = input.files[0];
        var error = validateFile(firstFile);
        var kind = getKind(firstFile.name);
        row.classList.remove('is-empty');
        if (badge) {
            badge.className = (isCustom ? 'file-type-badge ' : 'document-file-badge file-type-badge ') + badgeClass(kind);
            badge.textContent = kind;
        }
        if (meta) {
            meta.textContent = summarizeFiles(input.files);
            meta.classList.remove('is-hidden');
        }
        if (label) label.textContent = 'Заменить';
        if (clearBtn) clearBtn.classList.remove('is-hidden');
        row.classList.add('has-file');
        row.classList.remove('is-loading');
        if (error) setRowError(row, error);
    }

    function setDocumentLoading(row) {
        var badge = row.querySelector('.document-file-badge, .file-type-badge');
        var meta = row.querySelector('.document-file-meta, .file-meta');
        var isCustom = row.getAttribute('data-doc-kind') === 'custom';
        if (badge) {
            badge.className = isCustom ? 'file-type-badge is-loading' : 'document-file-badge file-type-badge is-loading';
            badge.textContent = '';
        }
        if (meta) {
            meta.textContent = 'Загрузка...';
            meta.classList.remove('is-hidden');
        }
        row.classList.add('is-loading');
    }

    function syncCustomType(row) {
        var visible = row.querySelector('.custom-doc-type-input');
        var selected = row.querySelector('.custom-doc-selected-type');
        var created = row.querySelector('.custom-doc-new-type');
        var value = String(visible && visible.value ? visible.value : '').trim();
        var match = docTypes.find(function (item) {
            return String(item.name || '').trim().toLowerCase() === value.toLowerCase();
        });
        if (selected) selected.value = match ? String(match.name || '') : '';
        if (created) created.value = match ? '' : value;
    }

    function filterDocTypes(query) {
        if (!query) return [];
        var q = query.toLowerCase();
        var result = [];
        for (var i = 0; i < docTypes.length; i++) {
            if (String(docTypes[i].name || '').toLowerCase().indexOf(q) !== -1) result.push(docTypes[i]);
            if (result.length >= 5) break;
        }
        return result;
    }

    function bindPredefRow(row) {
        var input = row.querySelector('.document-file-input');
        var clearBtn = row.querySelector('.document-file-clear');
        if (!input) return;
        input.addEventListener('change', function () {
            paintRow(row, input);
        });
        if (clearBtn) {
            clearBtn.addEventListener('click', function () {
                resetRow(row);
            });
        }
    }

    function bindCustomRow(row) {
        var input = row.querySelector('.document-file-input');
        var clearBtn = row.querySelector('.document-file-clear, .file-remove');
        var pickBtn = row.querySelector('.js-custom-file-pick-btn');
        var titleInput = row.querySelector('.custom-doc-type-input');
        var suggestionsDiv = row.querySelector('.custom-doc-suggestions');
        if (pickBtn && input) {
            pickBtn.addEventListener('click', function () {
                input.click();
            });
        }
        if (titleInput) {
            titleInput.addEventListener('input', function () {
                if (titleInput.value.trim() !== '') clearRowError(row);
                syncCustomType(row);
                if (!suggestionsDiv) return;
                var filtered = filterDocTypes(titleInput.value.trim());
                if (filtered.length === 0) {
                    suggestionsDiv.innerHTML = '';
                    suggestionsDiv.classList.remove('is-open');
                    return;
                }
                var html = '';
                for (var i = 0; i < filtered.length; i++) {
                    html += '<div class="custom-doc-suggestion">' +
                        String(filtered[i].name || '').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;') +
                        '</div>';
                }
                suggestionsDiv.innerHTML = html;
                suggestionsDiv.classList.add('is-open');
            });
            titleInput.addEventListener('blur', function () {
                if (!suggestionsDiv) return;
                window.setTimeout(function () {
                    suggestionsDiv.classList.remove('is-open');
                }, 120);
            });
            titleInput.addEventListener('focus', function () {
                if (String(titleInput.value || '').trim() !== '') {
                    titleInput.dispatchEvent(new Event('input'));
                }
            });
        }
        if (suggestionsDiv && titleInput) {
            suggestionsDiv.addEventListener('mousedown', function (event) {
                if (!event.target.classList.contains('custom-doc-suggestion')) return;
                titleInput.value = event.target.textContent;
                clearRowError(row);
                syncCustomType(row);
                suggestionsDiv.innerHTML = '';
                suggestionsDiv.classList.remove('is-open');
            });
        }
        if (input) {
            input.addEventListener('change', function () {
                paintRow(row, input);
            });
        }
        if (clearBtn) {
            clearBtn.addEventListener('click', function () {
                var hasTitle = !!String(titleInput && titleInput.value ? titleInput.value : '').trim();
                var hasFile = !!(input && input.files && input.files.length);
                if (!hasTitle && !hasFile && row.parentNode) {
                    row.parentNode.removeChild(row);
                    return;
                }
                if (titleInput) titleInput.value = '';
                syncCustomType(row);
                resetRow(row);
            });
        }
        syncCustomType(row);
    }

    function buildCustomRow(role) {
        var container = document.getElementById('custom-docs-container-' + role);
        if (!container) return;
        var idx = container.children.length;
        var badgeId = 'custom-doc-badge-' + role + '-' + idx;
        var metaId = 'custom-doc-meta-' + role + '-' + idx;
        var inputId = 'custom-doc-input-' + role + '-' + idx;
        var buttonId = 'custom-doc-btn-' + role + '-' + idx;
        var row = document.createElement('div');
        row.className = 'file-item custom-doc-row document-file-row is-empty';
        row.setAttribute('data-doc-kind', 'custom');
        row.setAttribute('data-doc-role', role);
        row.innerHTML = '' +
            '<div class="file-type-badge file-type-badge-empty" id="' + badgeId + '">—</div>' +
            '<div class="file-info">' +
                '<div class="field custom-doc-title-field">' +
                    '<input type="text" class="field-input custom-doc-type-input" placeholder="Введите название">' +
                    '<div class="custom-doc-suggestions"></div>' +
                    '<div class="field-msg"></div>' +
                '</div>' +
                '<div class="file-meta is-hidden" id="' + metaId + '"></div>' +
                '<input type="hidden" name="custom_doc_type[' + role + '][]" class="custom-doc-selected-type" value="">' +
                '<input type="hidden" name="custom_doc_type_new[' + role + '][]" class="custom-doc-new-type" value="">' +
            '</div>' +
            '<button type="button" class="btn btn-secondary file-action-btn js-custom-file-pick-btn" data-file-input="' + inputId + '">' +
                '<span id="' + buttonId + '">Выбрать</span>' +
            '</button>' +
            '<input type="file" id="' + inputId + '" name="custom_doc_file[' + role + '][]" class="file-input-hidden document-file-input js-custom-file-input" data-label="' + metaId + '" data-badge="' + badgeId + '" data-button-label="' + buttonId + '">' +
            '<button type="button" class="file-remove" title="Удалить документ">&times;</button>';
        container.appendChild(row);
        bindCustomRow(row);
    }

    function setPanelEnabled(panel, enabled) {
        panel.querySelectorAll('input, select, textarea, button').forEach(function (el) {
            if (el === setType || el.closest('.file-action-btn')) return;
            if (el.classList.contains('add-custom-doc-btn')) return;
            if (enabled) el.removeAttribute('disabled');
            else el.setAttribute('disabled', 'disabled');
        });
        syncRequiredMarks(panel);
    }

    function toggleRole(role, config) {
        var unitPanel = document.querySelector('.vehicle-set-unit-panel[data-unit-role="' + role + '"]');
        var docSection = document.querySelector('.vehicle-doc-section[data-doc-role="' + role + '"]');
        var visible = !!config;
        var presentation = getUnitPresentation(role, config);
        [unitPanel, docSection].forEach(function (el) {
            if (!el) return;
            el.classList.toggle('is-hidden', !visible);
            setPanelEnabled(el, visible);
        });
        if (!unitPanel || !docSection || !visible) return;

        var title = unitPanel.querySelector('[data-unit-title]');
        var unitTypeInput = unitPanel.querySelector('[data-unit-type-input]');
        var capacityRow = unitPanel.querySelector('[data-capacity-row]');
        var capacityField = unitPanel.querySelector('[data-capacity-field]');
        var volumeField = unitPanel.querySelector('[data-volume-field]');
        var plateInput = unitPanel.querySelector('[data-plate-field]');

        if (title) title.textContent = presentation && presentation.label ? presentation.label : (config.label || '');
        if (unitTypeInput) unitTypeInput.value = config.unit_type || '';
        unitPanel.classList.toggle('span-2', visible);
        if (capacityRow) {
            capacityRow.classList.toggle('is-hidden', !config.show_capacity && !config.show_volume);
        }
        if (capacityField) {
            capacityField.classList.toggle('is-hidden', !config.show_capacity);
        }
        if (volumeField) {
            volumeField.classList.toggle('is-hidden', !config.show_volume);
        }
        syncRequiredMarks(unitPanel);
        if (plateInput) {
            plateInput.value = normalizePlateValue(plateInput.value);
        }
    }

    function updateUi() {
        var rule = getRule();
        var visible = !!rule;
        dynamicFields.classList.toggle('is-hidden', !visible);
        if (dynamicDocs) {
            dynamicDocs.classList.toggle('is-hidden', !visible);
        }
        if (commentCard) {
            commentCard.classList.toggle('is-hidden', !visible);
        }
        if (!visible) {
            ['primary', 'secondary'].forEach(function (role) { toggleRole(role, null); });
            return;
        }
        toggleRole('primary', rule.units && rule.units.primary ? rule.units.primary : null);
        toggleRole('secondary', rule.units && rule.units.secondary ? rule.units.secondary : null);
    }

    function validateClientFiles() {
        var rule = getRule();
        if (!rule) return false;
        var valid = true;
        Object.keys(rule.units || {}).forEach(function (role) {
            var docSection = document.querySelector('.vehicle-doc-section[data-doc-role="' + role + '"]');
            if (!docSection) return;
            docSection.querySelectorAll('.document-file-row[data-doc-kind="predef"]').forEach(function (row) {
                var input = row.querySelector('.document-file-input');
                clearRowError(row);
                if (!input || !input.files || !input.files.length) return;
                var error = validateFile(input.files[0]);
                if (error) {
                    setRowError(row, error);
                    valid = false;
                }
            });
            docSection.querySelectorAll('.document-file-row[data-doc-kind="custom"]').forEach(function (row) {
                var input = row.querySelector('.document-file-input');
                var titleInput = row.querySelector('.custom-doc-type-input');
                var title = String(titleInput && titleInput.value ? titleInput.value : '').trim();
                var hasFile = !!(input && input.files && input.files.length);
                clearRowError(row);
                syncCustomType(row);
                if (hasFile && !title) {
                    setRowError(row, 'Введите название документа');
                    valid = false;
                    return;
                }
                if (hasFile) {
                    var error = validateFile(input.files[0]);
                    if (error) {
                        setRowError(row, error);
                        valid = false;
                    }
                }
            });
        });
        return valid;
    }

    function validateVisiblePlateFields() {
        var valid = true;
        document.querySelectorAll('.vehicle-set-unit-panel:not(.is-hidden) [data-plate-field]').forEach(function (input) {
            if (!validatePlateField(input)) valid = false;
        });
        return valid;
    }

    function validateVisibleVehicleFields() {
        var valid = true;
        form.querySelectorAll('.vehicle-set-unit-panel:not(.is-hidden) input[name]:not([type="file"])').forEach(function (input) {
            if (!normalizeVehicleUnitField(input)) valid = false;
        });
        return valid;
    }

    document.querySelectorAll('.js-erp-date-picker').forEach(installDatePicker);
    document.querySelectorAll('.document-file-row[data-doc-kind="predef"]').forEach(bindPredefRow);
    document.querySelectorAll('.document-file-row[data-doc-kind="custom"]').forEach(bindCustomRow);
    document.querySelectorAll('[data-plate-field]').forEach(function (input) {
        input.addEventListener('input', function () {
            if (String(input.value || '').trim() !== '') {
                clearFieldError(input);
            }
        });
        input.addEventListener('blur', function () {
            input.value = normalizePlateValue(input.value);
            validatePlateField(input);
        });
    });
    form.addEventListener('focusin', function (event) {
        if (event.target && event.target.classList.contains('js-erp-date-picker')) {
            openDatePopup(event.target);
        }
    });
    form.addEventListener('click', function (event) {
        var trigger = event.target.closest('.erp-date-trigger');
        if (trigger) {
            var wrappedInput = trigger.closest('.erp-date-field') ? trigger.closest('.erp-date-field').querySelector('.js-erp-date-picker') : null;
            if (wrappedInput) {
                openDatePopup(wrappedInput);
                wrappedInput.focus();
            }
            return;
        }
        if (event.target && event.target.classList.contains('js-erp-date-picker')) {
            openDatePopup(event.target);
        }
    });
    form.addEventListener('focusout', function (event) {
        if (event.target && event.target.matches('input:not([type="file"])')) {
            normalizeVehicleUnitField(event.target);
        }
    });
    form.addEventListener('paste', function (event) {
        if (event.target && event.target.matches('input[name]:not([type="file"])')) {
            window.setTimeout(function () {
                normalizeVehicleUnitField(event.target);
            }, 0);
        }
    });
    document.querySelectorAll('.add-custom-doc-btn').forEach(function (button) {
        button.addEventListener('click', function (event) {
            event.preventDefault();
            buildCustomRow(button.getAttribute('data-role'));
        });
    });

    setType.addEventListener('change', updateUi);
    form.addEventListener('submit', function (event) {
        if (typeof window.erpCheckUploadSize === 'function') {
            var uc = window.erpCheckUploadSize(form);
            if (!uc.ok) { event.preventDefault(); return; }
        }
        if (!getRule()) {
            event.preventDefault();
            var setError = form.querySelector('[data-field-error="set_type"]');
            if (setError) setError.textContent = 'Выберите тип комплекта';
            return;
        }
        if (!validateVisibleVehicleFields()) {
            event.preventDefault();
            var firstNormalizedError = form.querySelector('.field.is-error input');
            if (firstNormalizedError) firstNormalizedError.focus();
            return;
        }
        if (!validateVisiblePlateFields()) {
            event.preventDefault();
            return;
        }
        document.querySelectorAll('.document-file-row[data-doc-kind="custom"]').forEach(syncCustomType);
        if (!validateClientFiles()) {
            event.preventDefault();
            return;
        }
        document.querySelectorAll('.vehicle-doc-section:not(.is-hidden) .document-file-input').forEach(function (input) {
            if (input.files && input.files.length) {
                setDocumentLoading(input.closest('.document-file-row'));
            }
        });
        if (submitButton) {
            submitButton.disabled = true;
            submitButton.textContent = 'Создание...';
        }
        form.querySelectorAll('.vehicle-set-unit-panel:not(.is-hidden) .js-erp-date-picker').forEach(function (input) {
            if (input.value.trim()) {
                var parsed = parseDateValue(input.value);
                if (parsed) input.value = parsed.iso;
            }
        });
    });

    document.addEventListener('mousedown', function (event) {
        var popup = document.querySelector('.erp-date-popover');
        if (!popup || !popup.classList.contains('is-open')) return;
        if (popup.contains(event.target)) return;
        if (event.target.closest('.erp-date-field')) return;
        closeDatePopup();
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') closeDatePopup();
    });

    updateUi();
})();
</script>
