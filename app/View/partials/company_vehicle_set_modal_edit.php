<?php
$vehicleSetRules = vehicleSetTypeRules();
$currentSetType = (string) ($old['set_type'] ?? ($vehicleSet['set_type'] ?? 'single'));
$currentRule = $vehicleSetRules[$currentSetType] ?? ['units' => []];
$docsByRole = $docsByRole ?? ['primary' => [], 'secondary' => []];
$unitDefaults = [
    'plate_number' => '',
    'brand' => '',
    'model' => '',
    'vin' => '',
    'vin_absent' => 0,
    'capacity_tons' => '',
    'volume_m3' => '',
    'diagnostic_card_number' => '',
    'diagnostic_card_date' => '',
];

$normalizeDateForInput = static function ($value): string {
    if ($value === null || $value === '') {
        return '';
    }
    $value = trim((string) $value);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        $parts = explode('-', $value);
        return $parts[2] . '.' . $parts[1] . '.' . $parts[0];
    }
    return $value;
};

$unitValues = [];
foreach (['primary', 'secondary'] as $role) {
    $source = isset($old['units'][$role]) && is_array($old['units'][$role])
        ? $old['units'][$role]
        : ($unitsByRole[$role] ?? []);
    $unitValues[$role] = array_merge($unitDefaults, $source);
    $unitValues[$role]['diagnostic_card_date'] = $normalizeDateForInput($unitValues[$role]['diagnostic_card_date'] ?? '');
}

$pendingCustomDocs = ['primary' => [], 'secondary' => []];
foreach (['primary', 'secondary'] as $role) {
    $titles = $old['custom_doc_type'][$role] ?? [];
    if (!is_array($titles)) {
        continue;
    }
    foreach ($titles as $title) {
        $title = trim((string) $title);
        if ($title === '') {
            continue;
        }
        $pendingCustomDocs[$role][] = $title;
    }
}

$badgeMeta = static function (array $doc): array {
    $name = (string) ($doc['original_name'] ?? $doc['stored_name'] ?? '');
    $mime = (string) ($doc['mime_type'] ?? '');
    $parts = explode('.', $name);
    $ext = count($parts) > 1 ? strtoupper((string) end($parts)) : '';
    if ($ext === '') {
        if (strpos($mime, 'pdf') !== false) {
            $ext = 'PDF';
        } elseif (strpos($mime, 'image') !== false) {
            $ext = 'IMG';
        }
    }

    $badgeCls = 'file-type-badge';
    $badgeTxt = '—';
    if ($ext === 'PDF') {
        $badgeCls .= ' is-pdf';
        $badgeTxt = 'PDF';
    } elseif (in_array($ext, ['DOC', 'DOCX', 'RTF', 'ODT'], true)) {
        $badgeCls .= ' is-doc';
        $badgeTxt = 'DOC';
    } elseif (in_array($ext, ['XLS', 'XLSX', 'CSV', 'ODS'], true)) {
        $badgeCls .= ' is-xls';
        $badgeTxt = 'XLS';
    } elseif (in_array($ext, ['JPG', 'JPEG', 'PNG', 'WEBP', 'GIF', 'BMP', 'TIF', 'TIFF', 'HEIC', 'HEIF'], true)) {
        $badgeCls .= ' is-img';
        $badgeTxt = 'IMG';
    } else {
        $badgeCls .= ' is-other';
        $badgeTxt = $ext !== '' ? $ext : 'FILE';
    }

    return [$badgeCls, $badgeTxt, $name !== '' ? $name : 'Файл'];
};
?>
<div class="modal-body driver-modal-body">
  <form id="vehicle-set-edit-form" method="post" action="/company/vehicle-sets/<?= (int) $vehicleSet['id'] ?>/modal-edit" class="vehicle-set-form vehicle-set-edit-form" enctype="multipart/form-data" novalidate>
    <input type="hidden" name="status" value="<?= e((string) ($old['status'] ?? $vehicleSet['status'] ?? 'active')) ?>">
    <div class="vehicle-set-card-stack">
      <?php if ($formError): ?>
      <div class="vehicle-set-form-card vehicle-set-type-card">
        <div class="form-alert alert-error">
          <div class="alert-body">
            <div class="alert-body-title">Ошибка при сохранении</div>
            <div class="alert-body-sub"><?= e($formError) ?></div>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <div class="vehicle-set-form-card vehicle-set-type-card">
        <div class="field<?= !empty($errors['set_type']) ? ' is-error' : '' ?>">
          <label class="field-label" for="vehicle_set_edit_type_select">Тип <span class="req">*</span></label>
          <select name="set_type" id="vehicle_set_edit_type_select" class="field-input" data-vehicle-set-type>
            <option value="">— Выберите тип —</option>
            <?php foreach ($vehicleSetRules as $ruleCode => $ruleMeta): ?>
              <option value="<?= e((string) $ruleCode) ?>" <?= $currentSetType === $ruleCode ? 'selected' : '' ?>><?= e((string) ($ruleMeta['label'] ?? $ruleCode)) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="field-msg"><?= e((string) ($errors['set_type'] ?? '')) ?></div>
        </div>
      </div>

      <div class="vehicle-set-card-list">
        <?php foreach (['primary', 'secondary'] as $role): ?>
          <?php $roleRule = $currentRule['units'][$role] ?? null; ?>
          <div class="vehicle-set-form-card vehicle-set-unit-panel vehicle-set-edit-panel<?= $roleRule ? '' : ' is-hidden' ?>" data-vehicle-role-panel="<?= e($role) ?>">
            <div class="section-title"><?= e($roleRule['label'] ?? ($role === 'secondary' ? 'Доп. единица' : 'Основная единица')) ?></div>
            <div class="vehicle-set-unit-card-body">
              <div class="vehicle-set-unit-fields">
                <div class="field-row field-row-group">
                  <div class="field field-w-license<?= !empty($errors['units'][$role]['brand']) ? ' is-error' : '' ?>">
                    <label class="field-label">Марка</label>
                    <input type="text" name="units[<?= e($role) ?>][brand]" class="field-input" value="<?= e((string) ($unitValues[$role]['brand'] ?? '')) ?>">
                    <div class="field-msg"><?= e((string) ($errors['units'][$role]['brand'] ?? '')) ?></div>
                  </div>

                  <div class="field field-w-license<?= !empty($errors['units'][$role]['model']) ? ' is-error' : '' ?>">
                    <label class="field-label">Модель</label>
                    <input type="text" name="units[<?= e($role) ?>][model]" class="field-input" value="<?= e((string) ($unitValues[$role]['model'] ?? '')) ?>">
                    <div class="field-msg"><?= e((string) ($errors['units'][$role]['model'] ?? '')) ?></div>
                  </div>

                  <div class="field field-w-passport<?= !empty($errors['units'][$role]['plate_number']) ? ' is-error' : '' ?>">
                    <label class="field-label">Госномер <span class="req">*</span></label>
                    <input type="text" name="units[<?= e($role) ?>][plate_number]" class="field-input" value="<?= e((string) ($unitValues[$role]['plate_number'] ?? '')) ?>" data-required-when-visible>
                    <div class="field-msg"><?= e((string) ($errors['units'][$role]['plate_number'] ?? '')) ?></div>
                  </div>
                </div>

                <div class="field-row field-row-group vin-absent-row">
                  <div class="field field-w-issued-by<?= !empty($errors['units'][$role]['vin']) ? ' is-error' : '' ?>">
                    <label class="field-label">VIN код</label>
                    <input type="text" name="units[<?= e($role) ?>][vin]" class="field-input" value="<?= e((string) ($unitValues[$role]['vin'] ?? '')) ?>">
                    <label class="vehicle-vin-absent contact-checkbox-item checkbox-item"><input type="checkbox" name="units[<?= e($role) ?>][vin_absent]" value="1" <?= !empty($unitValues[$role]['vin_absent']) ? 'checked' : '' ?>><span class="checkbox-mark" aria-hidden="true"></span><span class="contact-checkbox-label">VIN отсутствует</span></label>
                    <div class="field-msg"><?= e((string) ($errors['units'][$role]['vin'] ?? '')) ?></div>
                  </div>

                  <div class="field field-w-email field-grow<?= !empty($errors['units'][$role]['diagnostic_card_number']) ? ' is-error' : '' ?>">
                    <label class="field-label">Диагностическая карта</label>
                    <input type="text" name="units[<?= e($role) ?>][diagnostic_card_number]" class="field-input" value="<?= e((string) ($unitValues[$role]['diagnostic_card_number'] ?? '')) ?>">
                    <div class="field-msg"><?= e((string) ($errors['units'][$role]['diagnostic_card_number'] ?? '')) ?></div>
                  </div>

                  <div class="field field-w-date<?= !empty($errors['units'][$role]['diagnostic_card_date']) ? ' is-error' : '' ?>">
                    <label class="field-label">Дата получения</label>
                    <input type="text" name="units[<?= e($role) ?>][diagnostic_card_date]" class="field-input js-erp-date-picker" value="<?= e((string) ($unitValues[$role]['diagnostic_card_date'] ?? '')) ?>" placeholder="дд.мм.гггг" inputmode="numeric" autocomplete="off">
                    <div class="field-msg"><?= e((string) ($errors['units'][$role]['diagnostic_card_date'] ?? '')) ?></div>
                  </div>
                </div>

                <div class="field-row field-row-group<?= empty($roleRule['show_capacity']) && empty($roleRule['show_volume']) ? ' is-hidden' : '' ?>" data-capacity-row>
                  <div class="field field-w-code<?= !empty($errors['units'][$role]['capacity_tons']) ? ' is-error' : '' ?><?= empty($roleRule['show_capacity']) ? ' is-hidden' : '' ?>" data-role-capacity="<?= e($role) ?>">
                    <label class="field-label">Грузоподъёмность</label>
                    <input type="text" name="units[<?= e($role) ?>][capacity_tons]" class="field-input" value="<?= e((string) ($unitValues[$role]['capacity_tons'] ?? '')) ?>" inputmode="decimal">
                    <div class="field-msg"><?= e((string) ($errors['units'][$role]['capacity_tons'] ?? '')) ?></div>
                  </div>

                  <div class="field field-w-code<?= !empty($errors['units'][$role]['volume_m3']) ? ' is-error' : '' ?><?= empty($roleRule['show_volume']) ? ' is-hidden' : '' ?>" data-role-volume="<?= e($role) ?>">
                    <label class="field-label">Объём кузова</label>
                    <input type="text" name="units[<?= e($role) ?>][volume_m3]" class="field-input" value="<?= e((string) ($unitValues[$role]['volume_m3'] ?? '')) ?>" inputmode="decimal">
                    <div class="field-msg"><?= e((string) ($errors['units'][$role]['volume_m3'] ?? '')) ?></div>
                  </div>
                </div>
              </div>

              <div class="vehicle-set-unit-docs vehicle-set-edit-doc-block<?= $roleRule ? '' : ' is-hidden' ?>" data-vehicle-doc-panel="<?= e($role) ?>">
                <div class="vehicle-doc-section" data-custom-doc-panel="<?= e($role) ?>">
                  <div class="file-list">
                    <?php foreach (($docsByRole[$role] ?? []) as $doc): ?>
                      <?php [$badgeCls, $badgeTxt, $fileLabel] = $badgeMeta($doc); ?>
                      <div class="file-item file-item-predef document-file-row has-file has-existing-file driver-doc-view-item" data-doc-row="existing">
                        <div class="<?= e($badgeCls) ?>"><?= e($badgeTxt) ?></div>
                        <div class="file-info">
                          <div class="file-name"><?= e((string) ($doc['document_type'] ?? 'Документ')) ?></div>
                          <div class="file-meta"><?= e($fileLabel) ?></div>
                        </div>
                        <button type="button" class="btn btn-secondary file-action-btn" data-doc-replace-btn>
                          <span>Заменить</span>
                        </button>
                        <button type="button" class="predef-file-clear" title="Удалить файл" data-doc-remove-btn>×</button>
                        <input type="file" class="file-input-hidden" name="existing_doc_file[<?= (int) $doc['id'] ?>]" data-doc-input>
                        <input type="hidden" name="delete_existing_doc[<?= (int) $doc['id'] ?>]" value="0" data-doc-delete-flag>
                      </div>
                    <?php endforeach; ?>
                  </div>

                  <div class="form-section vehicle-custom-docs">
                    <div class="file-list" data-custom-docs-container="<?= e($role) ?>">
                      <?php foreach (($pendingCustomDocs[$role] ?? []) as $pendingTitle): ?>
                        <div class="file-item custom-doc-row document-file-row is-empty" data-doc-row="custom">
                          <div class="file-type-badge file-type-badge-empty">—</div>
                          <div class="file-info">
                            <div class="field custom-doc-title-field">
                              <input type="text" name="custom_doc_type[<?= e($role) ?>][]" class="field-input custom-doc-type-input" value="<?= e($pendingTitle) ?>" placeholder="Введите название">
                              <div class="field-msg"></div>
                            </div>
                            <div class="file-meta is-hidden"></div>
                          </div>
                          <button type="button" class="btn btn-secondary file-action-btn" data-doc-pick-btn>
                            <span>Выбрать</span>
                          </button>
                          <input type="file" class="file-input-hidden" name="custom_doc_file[<?= e($role) ?>][]" data-doc-input>
                          <button type="button" class="file-remove" title="Удалить документ" data-doc-remove-btn>×</button>
                        </div>
                      <?php endforeach; ?>
                    </div>

                    <button type="button" class="btn btn-ghost add-custom-doc-btn" data-add-custom-doc-btn="<?= e($role) ?>">+ Добавить документ</button>
                  </div>
                </div>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <div class="vehicle-set-form-card vehicle-set-comment-card">
        <div class="field field-w-comment vehicle-set-comments-field">
          <label class="field-label">Комментарий</label>
          <textarea name="comments" class="field-input field-textarea vehicle-set-textarea" rows="3" placeholder="Примечания по транспорту"><?= e((string) ($old['comments'] ?? $vehicleSet['comments'] ?? '')) ?></textarea>
          <div class="field-msg"></div>
        </div>
      </div>
    </div>
  </form>
</div>

<div class="modal-foot is-spaced">
  <div class="modal-required-note"><span class="req">*</span> — обязательные поля</div>
  <div class="modal-foot-actions">
    <button type="button" class="btn btn-ghost" data-vehicle-set-cancel-edit-btn>Отмена</button>
    <button type="submit" form="vehicle-set-edit-form" class="btn btn-primary">Сохранить</button>
  </div>
</div>

<script>
(function () {
    var form = document.getElementById('vehicle-set-edit-form');
    if (!form || form.dataset.vehicleSetReady === '1') return;
    form.dataset.vehicleSetReady = '1';

    var rules = <?= json_encode(array_map(static function (array $rule): array {
        return ['units' => $rule['units'] ?? []];
    }, $vehicleSetRules), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    var setType = form.querySelector('[data-vehicle-set-type]');

    function getRule() {
        return rules[setType.value] || null;
    }

    function setFieldError(input, message) {
        var field = input.closest('.field');
        if (!field) return;
        field.classList.add('is-error');
        var msg = field.querySelector('.field-msg');
        if (msg) msg.textContent = message || '';
    }

    function clearFieldError(input) {
        var field = input.closest('.field');
        if (!field) return;
        field.classList.remove('is-error');
        var msg = field.querySelector('.field-msg');
        if (msg && !msg.dataset.serverError) msg.textContent = '';
    }

    form.querySelectorAll('.field-msg').forEach(function (msg) {
        if (msg.textContent.trim() !== '') {
            msg.dataset.serverError = '1';
        }
    });

    function togglePanels() {
        var rule = getRule();
        ['primary', 'secondary'].forEach(function (role) {
            var panel = form.querySelector('[data-vehicle-role-panel="' + role + '"]');
            var docsPanel = form.querySelector('[data-vehicle-doc-panel="' + role + '"]');
            var roleRule = rule && rule.units ? rule.units[role] : null;
            var visible = !!roleRule;
            if (panel) panel.classList.toggle('is-hidden', !visible);
            if (docsPanel) docsPanel.classList.toggle('is-hidden', !visible);
            var capacity = form.querySelector('[data-role-capacity="' + role + '"]');
            var volume = form.querySelector('[data-role-volume="' + role + '"]');
            var capacityRow = panel ? panel.querySelector('[data-capacity-row]') : null;
            if (capacityRow) capacityRow.classList.toggle('is-hidden', !roleRule || (!roleRule.show_capacity && !roleRule.show_volume));
            if (capacity) capacity.classList.toggle('is-hidden', !roleRule || !roleRule.show_capacity);
            if (volume) volume.classList.toggle('is-hidden', !roleRule || !roleRule.show_volume);
            if (visible && panel) {
                var title = panel.querySelector('.section-title');
                if (title && roleRule.label) title.textContent = roleRule.label;
            }
            if (visible && docsPanel) {
                var docsTitle = docsPanel.querySelector('.driver-doc-section-title');
                if (docsTitle && roleRule.label) docsTitle.textContent = roleRule.label;
            }
        });
    }

    function bindDocRow(row) {
        if (!row || row.dataset.ready === '1') return;
        row.dataset.ready = '1';

        var input = row.querySelector('[data-doc-input]');
        var replaceBtn = row.querySelector('[data-doc-replace-btn], [data-doc-pick-btn]');
        var removeBtn = row.querySelector('[data-doc-remove-btn]');
        var deleteFlag = row.querySelector('[data-doc-delete-flag]');
        var meta = row.querySelector('.file-meta');
        var badge = row.querySelector('.file-type-badge');
        var titleInput = row.querySelector('.custom-doc-type-input');
        var isCustom = row.getAttribute('data-doc-row') === 'custom';

        if (replaceBtn && input) {
            replaceBtn.addEventListener('click', function () {
                input.click();
            });
        }

        if (titleInput) {
            titleInput.addEventListener('input', function () {
                clearFieldError(titleInput);
            });
        }

        if (input) {
            input.addEventListener('change', function () {
                if (!input.files || !input.files.length) return;
                var file = input.files[0];
                if (meta) {
                    meta.textContent = file.name;
                    meta.classList.remove('is-hidden');
                }
                if (badge) {
                    badge.textContent = 'FILE';
                    badge.className = 'file-type-badge is-other';
                }
                if (replaceBtn) {
                    var label = replaceBtn.querySelector('span');
                    if (label) label.textContent = 'Заменить';
                }
                if (deleteFlag) deleteFlag.value = '0';
                row.classList.remove('is-empty');
                row.classList.add('has-file');
            });
        }

        if (removeBtn) {
            removeBtn.addEventListener('click', function () {
                if (deleteFlag) {
                    deleteFlag.value = '1';
                    if (input) input.value = '';
                    row.classList.add('is-empty');
                    row.classList.remove('has-file');
                    row.classList.remove('has-existing-file');
                    if (badge) { badge.className = 'file-type-badge file-type-badge-empty'; badge.textContent = '\u2014'; }
                    if (meta) { meta.textContent = '\u0424\u0430\u0439\u043b \u0443\u0434\u0430\u043b\u0451\u043d'; meta.classList.remove('is-hidden'); }
                    if (replaceBtn) { var label = replaceBtn.querySelector('span'); if (label) label.textContent = '\u0412\u044b\u0431\u0440\u0430\u0442\u044c'; }
                    return;
                }
                if (input) input.value = '';
                if (titleInput) titleInput.value = '';
                row.remove();
            });
        }
    }

    function buildCustomRow(role) {
        var container = form.querySelector('[data-custom-docs-container="' + role + '"]');
        if (!container) return;
        var row = document.createElement('div');
        row.className = 'file-item custom-doc-row document-file-row is-empty';
        row.setAttribute('data-doc-row', 'custom');
        row.innerHTML =
            '<div class="file-type-badge file-type-badge-empty">—</div>' +
            '<div class="file-info">' +
                '<div class="field custom-doc-title-field">' +
                    '<input type="text" name="custom_doc_type[' + role + '][]" class="field-input custom-doc-type-input" placeholder="Введите название">' +
                    '<div class="field-msg"></div>' +
                '</div>' +
                '<div class="file-meta is-hidden"></div>' +
            '</div>' +
            '<button type="button" class="btn btn-secondary file-action-btn" data-doc-pick-btn><span>Выбрать</span></button>' +
            '<input type="file" class="file-input-hidden" name="custom_doc_file[' + role + '][]" data-doc-input>' +
            '<button type="button" class="file-remove" title="Удалить документ" data-doc-remove-btn>×</button>';
        container.appendChild(row);
        bindDocRow(row);
    }

    form.querySelectorAll('[data-add-custom-doc-btn]').forEach(function (button) {
        button.addEventListener('click', function () {
            buildCustomRow(button.getAttribute('data-add-custom-doc-btn'));
        });
    });

    form.querySelectorAll('[data-doc-row]').forEach(bindDocRow);

    form.querySelectorAll('input[data-required-when-visible]').forEach(function (input) {
        input.addEventListener('input', function () {
            if (input.value.trim() !== '') clearFieldError(input);
        });
    });
    form.querySelectorAll('input[name$="[vin_absent]"]').forEach(function (toggle) {
        var vin = toggle.closest('.field').querySelector('input[name$="[vin]"]');
        function syncVin() {
            if (toggle.checked) vin.value = '';
            vin.disabled = toggle.checked;
            clearFieldError(vin);
        }
        toggle.addEventListener('change', syncVin);
        syncVin();
    });

    form.addEventListener('submit', function (event) {
        var valid = true;
        var rule = getRule();

        if (!rule) {
            setFieldError(setType, 'Выберите тип транспорта');
            valid = false;
        }

        ['primary', 'secondary'].forEach(function (role) {
            var panel = form.querySelector('[data-vehicle-role-panel="' + role + '"]');
            if (!panel || panel.classList.contains('is-hidden')) return;
            panel.querySelectorAll('input[data-required-when-visible]').forEach(function (input) {
                if (input.name.endsWith('[vin]') && panel.querySelector('input[name$="[vin_absent]"]')?.checked) return;
                if (input.value.trim() === '') {
                    setFieldError(input, 'Обязательное поле');
                    valid = false;
                }
            });
        });

        form.querySelectorAll('[data-doc-row="custom"]').forEach(function (row) {
            var titleInput = row.querySelector('.custom-doc-type-input');
            var input = row.querySelector('[data-doc-input]');
            var hasFile = !!(input && input.files && input.files.length);
            if (hasFile && titleInput && titleInput.value.trim() === '') {
                setFieldError(titleInput, 'Введите название документа');
                valid = false;
            }
        });

        if (!valid) {
            event.preventDefault();
        }
    });

    if (setType) {
        setType.addEventListener('change', function () {
            clearFieldError(setType);
            togglePanels();
        });
    }
    togglePanels();
})();
</script>
