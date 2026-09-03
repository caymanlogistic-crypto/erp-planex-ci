<?php
require_once base_path('app/View/components/contact_fields.php');
$leEntityType      = $leEntityType ?? 'contractor';
$leFormAction      = $leFormAction ?? '/company/contractors/create';
$leFormId          = $leFormId ?? 'le-create-form';
$leOld             = $leOld ?? [];
$leErrors          = $leErrors ?? [];
$leFormError       = $leFormError ?? null;
$leDocTypes        = $leDocTypes ?? [];
$leContactValues   = $leContactValues ?? [];
$leContactErrors   = $leContactErrors ?? [];
$leIsModal         = $leIsModal ?? true;
$leShowContacts    = $leShowContacts ?? true;
$leShowBankDetails = $leShowBankDetails ?? true;
$leShowDocuments   = $leShowDocuments ?? true;
$leShowInlineActions = $leShowInlineActions ?? true;
$leShowStatus       = $leShowStatus ?? false;
$leSubmitLabel     = $leSubmitLabel ?? 'Создать';
$leHiddenFields     = $leHiddenFields ?? [];

$isCompany = $leEntityType === 'company' || $leEntityType === 'expeditor';

$leTypeFieldName = 'contractor_type';
if ($leEntityType === 'client') {
    $leTypeFieldName = 'entity_type';
}

if ($isCompany) {
    $leTitle = 'ЭКСПЕДИТОРА';
    $leLabel = 'экспедитора';
} elseif ($leEntityType === 'client') {
    $leTitle = 'КЛИЕНТА';
    $leLabel = 'клиента';
} else {
    $leTitle = 'ПЕРЕВОЗЧИКА';
    $leLabel = 'перевозчика';
}

$lePredefDocs = $lePredefDocs ?? [
    ['name' => 'Карточка предприятия', 'code' => 'company_card'],
    ['name' => 'Свидетельство ИНН', 'code' => 'inn_cert'],
    ['name' => 'Свидетельство ОГРН', 'code' => 'ogrn_cert'],
    ['name' => 'Договор', 'code' => 'contract'],
];

$leTypeOptions = [
    '' => '— Не указан —',
    'legal_entity' => 'Юридическое лицо',
    'individual' => 'Индивидуальный предприниматель',
    'self_employed' => 'Самозанятый',
    'private_person' => 'Физическое лицо',
];
?>
<form id="<?= e($leFormId) ?>" method="post" action="<?= e($leFormAction) ?>" class="" enctype="multipart/form-data" data-le-create-form="<?= e($leEntityType) ?>" data-inn-lookup-url="<?= e($leInnLookupUrl ?? app_url('/company/requisites/lookup-by-inn')) ?>">
<?php if ($leIsModal): ?>
<input type="hidden" name="is_modal" value="1">
<?php endif; ?>

<div class="entity-form-layout driver-layout<?= !$leShowDocuments ? ' driver-layout--no-docs' : '' ?>">

    <div class="entity-form-main driver-layout-main">
        <div class="driver-fields">

<?php if ($leFormError): ?>
            <div class="form-alert alert-error">
                <div class="alert-mark">
                    <svg width="11" height="11" viewBox="0 0 18 18" fill="none"><path d="M9 2L16.5 15H1.5L9 2Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><path d="M9 7V11M9 13V13.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                </div>
                <div class="alert-body">
                    <div class="alert-body-title">Ошибка при сохранении</div>
                    <div class="alert-body-sub"><?= e($leFormError) ?></div>
                </div>
            </div>
<?php endif; ?>

            <div class="form-alert alert-warning is-hidden" data-inn-autofill-message>
                <div class="alert-body">
                    <div class="alert-body-title" data-inn-autofill-title></div>
                    <div class="alert-body-sub" data-inn-autofill-sub></div>
                </div>
            </div>

            <div class="driver-contact-top-row">
                <div class="field field-w-name<?= !empty($leErrors['name']) ? ' is-error' : '' ?>" data-field="name">
                    <label class="field-label">Наименование <span class="req">*</span></label>
                    <input type="text" name="name" class="field-input" placeholder='ООО "РОМАШКА"' value="<?= e($leOld['name'] ?? '') ?>">
                    <div class="field-msg"><?= !empty($leErrors['name']) ? e($leErrors['name']) : '' ?></div>
                </div>
                <div class="driver-contact-stack">
                    <div class="driver-contact-main-row">
                        <div class="field field-w-phone<?= !empty($leErrors['inn']) ? ' is-error' : '' ?>" data-field="inn">
                            <label class="field-label">ИНН <span class="req">*</span></label>
                            <input type="text" name="inn" class="field-input" inputmode="numeric" placeholder="7701234567" value="<?= e($leOld['inn'] ?? '') ?>">
                            <div class="field-msg"><?= !empty($leErrors['inn']) ? e($leErrors['inn']) : '' ?></div>
                        </div>
                        <button type="button" class="btn btn-ghost phone-add-btn" data-inn-autofill-btn>Заполнить по ИНН</button>
                    </div>
                </div>
            </div>

            <div class="form-grid-3 mt-3">
<?php if (!$isCompany): ?>
                <div class="field" data-field="<?= e($leTypeFieldName) ?>">
                    <label class="field-label">Тип <?= $leLabel ?></label>
                    <select name="<?= e($leTypeFieldName) ?>" class="field-select">
                        <?php foreach ($leTypeOptions as $val => $lbl): ?>
                        <option value="<?= e($val) ?>" <?= ($leOld[$leTypeFieldName] ?? '') === $val ? 'selected' : '' ?>><?= e($lbl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
<?php else: ?>
                <div class="field" data-field="">
                    <label class="field-label">Тип</label>
                    <input type="text" class="field-input" value="Экспедитор" disabled>
                </div>
<?php endif; ?>
                <div class="field<?= !empty($leErrors['kpp']) ? ' is-error' : '' ?>" data-field="kpp">
                    <label class="field-label">КПП</label>
                    <input type="text" name="kpp" class="field-input" inputmode="numeric" placeholder="770101001" value="<?= e($leOld['kpp'] ?? '') ?>">
                    <div class="field-msg"><?= !empty($leErrors['kpp']) ? e($leErrors['kpp']) : '' ?></div>
                </div>
                <div class="field<?= !empty($leErrors['ogrn']) ? ' is-error' : '' ?>" data-field="ogrn">
                    <label class="field-label">ОГРН</label>
                    <input type="text" name="ogrn" class="field-input" inputmode="numeric" placeholder="1027700132195" value="<?= e($leOld['ogrn'] ?? '') ?>">
                    <div class="field-msg"><?= !empty($leErrors['ogrn']) ? e($leErrors['ogrn']) : '' ?></div>
                </div>
            </div>

            <div class="form-grid-2 mt-3">
                <div class="field" data-field="director_full_name">
                    <label class="field-label">Руководитель</label>
                    <input type="text" name="director_full_name" class="field-input" placeholder="ФИО руководителя" value="<?= e($leOld['director_full_name'] ?? '') ?>">
                </div>
                <div class="field" data-field="director_position">
                    <label class="field-label">Должность</label>
                    <input type="text" name="director_position" class="field-input" placeholder="Должность" value="<?= e($leOld['director_position'] ?? '') ?>">
                </div>
            </div>

            <div class="form-grid-2 mt-3">
                <div class="field" data-field="legal_address">
                    <label class="field-label">Юридический адрес</label>
                    <textarea name="legal_address" class="field-textarea driver-textarea" rows="2" placeholder="Юридический адрес"><?= e($leOld['legal_address'] ?? '') ?></textarea>
                </div>
                <div class="field" data-field="physical_address">
                    <label class="field-label">Фактический адрес</label>
                    <textarea name="physical_address" class="field-textarea driver-textarea" rows="2" placeholder="Фактический адрес"><?= e($leOld['physical_address'] ?? '') ?></textarea>
                </div>
            </div>

<?php if ($leShowContacts && !$isCompany): ?>
            <div class="mt-3">
                <div class="section-title">Контакты</div>
                <?php renderContactFields([
                    'entity_type' => $leEntityType,
                    'field_prefix' => 'contacts',
                    'contacts' => $leContactValues,
                    'errors' => $leContactErrors,
                    'allow_primary' => true,
                    'allow_document_email' => true,
                ]); ?>
            </div>
<?php endif; ?>

<?php if ($leShowBankDetails): ?>
            <div class="mt-3">
                <div class="section-title">Банковские реквизиты</div>
                <div class="form-grid-4">
                    <div class="field<?= !empty($leErrors['bank_account']) ? ' is-error' : '' ?>" data-field="bank_account">
                        <label class="field-label">Расчётный счёт</label>
                        <input type="text" name="bank_account" class="field-input" inputmode="numeric" placeholder="40702810..." value="<?= e($leOld['bank_account'] ?? '') ?>">
                        <div class="field-msg"><?= !empty($leErrors['bank_account']) ? e($leErrors['bank_account']) : '' ?></div>
                    </div>
                    <div class="field<?= !empty($leErrors['bank_bik']) ? ' is-error' : '' ?>" data-field="bank_bik">
                        <label class="field-label">БИК</label>
                        <input type="text" name="bank_bik" class="field-input" inputmode="numeric" placeholder="044525225" value="<?= e($leOld['bank_bik'] ?? '') ?>">
                        <div class="field-msg"><?= !empty($leErrors['bank_bik']) ? e($leErrors['bank_bik']) : '' ?></div>
                    </div>
                    <div class="field" data-field="bank_name">
                        <label class="field-label">Банк</label>
                        <input type="text" name="bank_name" class="field-input" placeholder='АО "БАНК"' value="<?= e($leOld['bank_name'] ?? '') ?>">
                    </div>
                    <div class="field<?= !empty($leErrors['bank_corr_account']) ? ' is-error' : '' ?>" data-field="bank_corr_account">
                        <label class="field-label">Корр. счёт</label>
                        <input type="text" name="bank_corr_account" class="field-input" inputmode="numeric" placeholder="30101810..." value="<?= e($leOld['bank_corr_account'] ?? '') ?>">
                        <div class="field-msg"><?= !empty($leErrors['bank_corr_account']) ? e($leErrors['bank_corr_account']) : '' ?></div>
                    </div>
<?php if ($leEntityType === 'contractor'): ?>
                    <div class="field<?= !empty($leErrors['bank_inn']) ? ' is-error' : '' ?>" data-field="bank_inn">
                        <label class="field-label">ИНН банка</label>
                        <input type="text" name="bank_inn" class="field-input" inputmode="numeric" placeholder="7707083893" value="<?= e($leOld['bank_inn'] ?? '') ?>">
                        <div class="field-msg"><?= !empty($leErrors['bank_inn']) ? e($leErrors['bank_inn']) : '' ?></div>
                    </div>
<?php endif; ?>
                </div>
            </div>
<?php endif; ?>

<?php if ($leShowStatus): ?>
            <div class="field mt-3" data-field="status">
                <label class="field-label">Статус <span class="req">*</span></label>
                <select name="status" class="field-select">
                    <?php $currentStatus = $leOld['status'] ?? 'active'; ?>
                    <option value="active" <?= $currentStatus === 'active' ? 'selected' : '' ?>>Активен</option>
                    <option value="inactive" <?= $currentStatus === 'inactive' ? 'selected' : '' ?>>Неактивен</option>
                    <option value="blocked" <?= $currentStatus === 'blocked' ? 'selected' : '' ?>>Заблокирован</option>
                    <option value="archived" <?= $currentStatus === 'archived' ? 'selected' : '' ?>>Архивирован</option>
                    <option value="provisioning" <?= $currentStatus === 'provisioning' ? 'selected' : '' ?>>Настройка</option>
                    <option value="error" <?= $currentStatus === 'error' ? 'selected' : '' ?>>Ошибка</option>
                </select>
            </div>
<?php endif; ?>

            <div class="field mt-3" data-field="comments">
                <label class="field-label">Комментарий</label>
                <textarea name="comments" class="field-textarea driver-textarea" rows="2" placeholder="Примечания"><?= e($leOld['comments'] ?? '') ?></textarea>
            </div>

<?php if ($leShowInlineActions): ?>
            <div class="form-actions mt-4">
                <button type="submit" class="btn btn-primary"><?= e($leSubmitLabel) ?></button>
            </div>
<?php endif; ?>

        </div>
    </div>

<?php if ($leShowDocuments): ?>
    <div class="entity-form-docs driver-layout-docs">
        <div>
            <div class="section-title">Документы</div>
        </div>
        <div class="file-list">
            <?php if (!empty($leExistingDocs)): ?>
            <?php foreach ($leExistingDocs as $doc):
                $docId = (int) ($doc['id'] ?? 0);
                if ($docId <= 0) continue;
                $name = $doc['original_name'] ?? $doc['stored_name'] ?? '';
                $metaText = $name ?: 'Файл';
                $parts = explode('.', $name);
                $ext = count($parts) > 1 ? strtoupper(end($parts)) : '';
                $mime = $doc['mime_type'] ?? '';
                $badgeCls = 'file-type-badge';
                $badgeTxt = '—';
                if ($ext === '') {
                    if (strpos($mime, 'pdf') !== false) $ext = 'PDF';
                    elseif (strpos($mime, 'image') !== false) $ext = 'IMG';
                }
                if ($ext === 'PDF') { $badgeCls .= ' is-pdf'; $badgeTxt = 'PDF'; }
                elseif ($ext === 'DOC' || $ext === 'DOCX' || $ext === 'RTF' || $ext === 'ODT') { $badgeCls .= ' is-doc'; $badgeTxt = 'DOC'; }
                elseif ($ext === 'XLS' || $ext === 'XLSX' || $ext === 'CSV' || $ext === 'ODS') { $badgeCls .= ' is-xls'; $badgeTxt = 'XLS'; }
                elseif (in_array($ext, ['JPG', 'JPEG', 'PNG', 'WEBP', 'GIF', 'BMP', 'TIF', 'TIFF', 'HEIC', 'HEIF'], true)) { $badgeCls .= ' is-img'; $badgeTxt = 'IMG'; }
                else { $badgeCls .= ' is-other'; $badgeTxt = $ext ?: 'FILE'; }
                $rowTitle = $doc['type_name'] ?: $doc['document_type'] ?: 'Документ';
                $rowKey = 'existing-' . $docId;
            ?>
            <div class="file-item file-item-predef document-file-row has-file has-existing-file driver-doc-view-item"
                 id="le-frow-<?= $rowKey ?>"
                 data-file-row="predef"
                 data-file-code="<?= $rowKey ?>"
                 data-has-existing="1"
                 data-existing-badge-class="<?= $badgeCls ?>"
                 data-existing-badge-text="<?= $badgeTxt ?>"
                 data-existing-meta="<?= e($metaText) ?>"
                 data-existing-button-text="Заменить">
                <div class="<?= $badgeCls ?>" id="le-fbadge-<?= $rowKey ?>"><?= $badgeTxt ?></div>
                <div class="file-info">
                    <div class="file-name"><?= e($rowTitle) ?></div>
                    <div class="file-meta" id="le-fname-<?= $rowKey ?>"><?= e($metaText) ?></div>
                </div>
                <button type="button"
                        class="btn btn-secondary file-action-btn js-file-pick-btn"
                        data-file-input="le-predef-file-<?= $rowKey ?>">
                    <span id="le-fbtn-<?= $rowKey ?>">Заменить</span>
                </button>
                <button type="button"
                        class="predef-file-clear"
                        id="le-fclear-<?= $rowKey ?>"
                        title="Удалить файл">×</button>
                <input type="file"
                       id="le-predef-file-<?= $rowKey ?>"
                       class="file-input-hidden js-predef-file-input"
                       name="existing_doc_file[<?= $docId ?>]"
                       data-label="le-fname-<?= $rowKey ?>"
                       data-badge="le-fbadge-<?= $rowKey ?>"
                       data-button-label="le-fbtn-<?= $rowKey ?>"
                       data-clear="le-fclear-<?= $rowKey ?>">
                <input type="hidden" name="delete_existing_doc[<?= $docId ?>]" value="0" data-delete-predef-doc>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
            <?php
            $leExistingCodes = [];
            $leExistingLabels = [];
            if (!empty($leExistingDocs)) {
                foreach ($leExistingDocs as $doc) {
                    if (!empty($doc['type_code'])) {
                        $leExistingCodes[] = $doc['type_code'];
                    }
                    $label = $doc['type_name'] ?? $doc['document_type'] ?? '';
                    $label = trim(mb_strtolower($label));
                    if ($label !== '') {
                        $leExistingLabels[] = $label;
                    }
                }
            }
            ?>
            <?php foreach ($lePredefDocs as $pdoc): ?>
            <?php if (in_array($pdoc['code'], $leExistingCodes, true)) continue; ?>
            <?php if (in_array(trim(mb_strtolower($pdoc['name'])), $leExistingLabels, true)) continue; ?>
            <div class="file-item file-item-predef document-file-row is-empty">
                <div class="file-type-badge file-type-badge-empty" id="le-fbadge-<?= e($pdoc['code']) ?>">—</div>
                <div class="file-info">
                    <div class="file-name"><?= e($pdoc['name']) ?></div>
                    <div class="file-meta" id="le-fname-<?= e($pdoc['code']) ?>">Файл не выбран</div>
                </div>
                <button type="button" class="btn btn-secondary file-action-btn js-file-pick-btn" data-file-input="le-predef-file-<?= e($pdoc['code']) ?>">
                    <span id="le-fbtn-<?= e($pdoc['code']) ?>">Выбрать</span>
                </button>
                <button type="button" class="predef-file-clear is-hidden" id="le-fclear-<?= e($pdoc['code']) ?>" title="Очистить файл">&times;</button>
                <input type="file" id="le-predef-file-<?= e($pdoc['code']) ?>" class="file-input-hidden js-predef-file-input"
                       name="predef_doc[<?= e($pdoc['code']) ?>]"
                       accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.xls,.xlsx"
                       data-label="le-fname-<?= e($pdoc['code']) ?>"
                       data-badge="le-fbadge-<?= e($pdoc['code']) ?>"
                       data-button-label="le-fbtn-<?= e($pdoc['code']) ?>"
                       data-clear="le-fclear-<?= e($pdoc['code']) ?>">
                <input type="hidden" name="predef_doc_type[<?= e($pdoc['code']) ?>]" value="<?= e($pdoc['name']) ?>">
            </div>
            <?php endforeach; ?>
        </div>
        <div id="le-custom-docs-container" class="file-list"></div>
        <button type="button" class="btn btn-ghost" id="le-add-custom-doc-btn">+ Добавить документ</button>
    </div>
<?php endif; ?>

</div>

<?php if (!empty($leHiddenFields) && is_array($leHiddenFields)): ?>
<?php foreach ($leHiddenFields as $leHiddenName => $leHiddenValue): ?>
<input type="hidden" name="<?= e($leHiddenName) ?>" value="<?= e($leHiddenValue) ?>">
<?php endforeach; ?>
<?php endif; ?>
<script type="application/json" data-doc-types><?= json_encode($leDocTypes, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>
</form>