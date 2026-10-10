<?php
$rule = $rule ?? null;
$isEdit = $rule !== null;
$bankAccounts = $bankAccounts ?? [];
$ddsCategories = $ddsCategories ?? [];
$cashFlowCenters = $cashFlowCenters ?? [];
$employees = $employees ?? [];
$ddsAllowedMap = $ddsAllowedMap ?? [];
$action = $isEdit ? app_url('/company/finance/settings/matching-rules/edit') : app_url('/company/finance/settings/matching-rules/create');
$v = static fn($k, $d = '') => $isEdit ? ($rule[$k] ?? $d) : $d;
$ruleId = (int)($rule['id'] ?? 0);
$actionType = (string)$v('action_type', 'categorize');
$isEmployee = $actionType === 'employee_cash_settlement';
$isNormal = in_array($actionType, ['categorize', 'categorize_to_cash'], true);
$isEditable = $isNormal || $isEmployee;
$employeeRef = '';
if ($isEmployee && !empty($rule['target_employee_identity_type']) && !empty($rule['target_employee_identity_id'])) {
    $employeeRef = $rule['target_employee_identity_type'] . ':' . (int)$rule['target_employee_identity_id'];
}
$allowedMapJson = json_encode($ddsAllowedMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($allowedMapJson === false) $allowedMapJson = '{}';
?>
<form action="<?= e($action) ?>" method="post" class="matching-rule-form" data-matching-rule-form>
    <?= csrfField() ?>
    <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= $ruleId ?>"><?php endif; ?>
    <input type="hidden" name="name" value="<?= e((string)$v('name', 'Правило разнесения')) ?>">
    <input type="hidden" name="auto_apply" value="1">
    <input type="hidden" name="direction" id="matching-rule-direction" value="<?= e((string)$v('direction')) ?>">
    <input type="hidden" name="purpose_regex" value="<?= e((string)$v('purpose_regex')) ?>">
    <input type="hidden" name="invoice_number_pattern" value="<?= e((string)$v('invoice_number_pattern')) ?>">
    <input type="hidden" name="target_cash_account_id" value="">

    <?php if ($isEditable): ?>
    <div class="modal-body">
        <div class="matching-rule-grid">
            <div class="field">
                <label class="field-label">Тип правила</label>
                <select class="field-select" name="action_type" onchange="var on=this.value==='employee_cash_settlement',box=this.form.querySelector('[data-employee-target]'),sel=box?box.querySelector('select'):null;if(box)box.classList.toggle('is-hidden',!on);if(sel)sel.required=on;">
                    <option value="categorize" <?= !$isEmployee ? 'selected' : '' ?>>Обычное разнесение</option>
                    <option value="employee_cash_settlement" <?= $isEmployee ? 'selected' : '' ?>>Взаиморасчёт с сотрудником</option>
                </select>
            </div>
            <div class="field <?= $isEmployee ? '' : 'is-hidden' ?>" data-employee-target>
                <label class="field-label">Сотрудник *</label>
                <select class="field-select" name="target_employee_ref" <?= $isEmployee ? 'required' : '' ?>>
                    <option value="">— Выберите сотрудника —</option>
                    <?php foreach ($employees as $employee): ?>
                    <option value="<?= e($employee['ref']) ?>" <?= $employeeRef === $employee['ref'] ? 'selected' : '' ?>><?= e($employee['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="field-note">Списание с банка = деньги переданы сотруднику; поступление на банк = сотрудник вернул деньги. Операция отражается напрямую по сотруднику.</div>
            </div>
            <div class="field">
                <label class="field-label">ЦФУ *</label>
                <select class="field-select" name="target_cash_flow_center_id" id="matching-rule-cfu" required data-dds-map="<?= e($allowedMapJson) ?>">
                    <option value="">— Выберите ЦФУ —</option>
                    <?php foreach ($cashFlowCenters as $x): ?>
                    <option value="<?= (int)$x['id'] ?>" <?= (int)$v('target_cash_flow_center_id') === (int)$x['id'] ? 'selected' : '' ?>><?= e($x['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label class="field-label">Статья *</label>
                <select class="field-select" name="target_dds_category_id" id="matching-rule-dds" required>
                    <option value="">— Выберите статью —</option>
                    <?php foreach ($ddsCategories as $x): ?>
                    <option value="<?= (int)$x['id'] ?>" data-direction="<?= e((string)($x['direction'] ?? '')) ?>" <?= (int)$v('target_dds_category_id') === (int)$x['id'] ? 'selected' : '' ?>><?= e($x['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field"><label class="field-label">ИНН</label><input class="field-input" name="counterparty_inn" value="<?= e((string)$v('counterparty_inn')) ?>"></div>
            <div class="field"><label class="field-label">Назначение содержит</label><input class="field-input" name="purpose_contains" value="<?= e((string)$v('purpose_contains')) ?>"></div>
            <div class="field">
                <label class="field-label">Банковский счёт</label>
                <select class="field-select" name="bank_account_id">
                    <option value="">Любой счёт</option>
                    <?php foreach ($bankAccounts as $x): ?>
                    <option value="<?= (int)$x['id'] ?>" <?= (int)$v('bank_account_id') === (int)$x['id'] ? 'selected' : '' ?>><?= e(($x['bank_name'] ?? '') . ' — ' . ($x['account_number'] ?? '')) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field"><label class="field-label">Сумма от</label><input class="field-input" type="number" step="0.01" min="0" name="amount_from" value="<?= e((string)$v('amount_from')) ?>"></div>
            <div class="field"><label class="field-label">Сумма до</label><input class="field-input" type="number" step="0.01" min="0" name="amount_to" value="<?= e((string)$v('amount_to')) ?>"></div>
            <div class="field"><label class="field-label">Приоритет</label><input class="field-input" type="number" name="priority" min="1" max="100000" value="<?= (int)$v('priority', 100) ?>"></div>
        </div>
    </div>
    <?php else: ?>
    <input type="hidden" name="action_type" value="<?= e($actionType) ?>">
    <div class="modal-body">Служебное правило прежнего типа сохранено только для истории. Новые операции по нему не создаются.</div>
    <?php endif; ?>

    <div class="modal-foot matching-rule-foot">
        <div>
            <?php if ($isEdit): ?>
            <button type="button" class="btn btn-ghost" data-rule-preview-from-edit="<?= $ruleId ?>">Проверить</button>
            <button type="button" class="btn btn-secondary" data-rule-toggle-from-edit="<?= $ruleId ?>" data-rule-active="<?= !empty($rule['active']) ? '1' : '0' ?>"><?= !empty($rule['active']) ? 'Отключить' : 'Включить' ?></button>
            <button type="button" class="btn btn-danger" data-rule-delete-from-edit="<?= $ruleId ?>">Удалить</button>
            <?php endif; ?>
        </div>
        <div>
            <?php if ($isEditable): ?><button type="submit" class="btn btn-primary"><?= $isEdit ? 'Сохранить' : 'Создать правило' ?></button><?php endif; ?>
            <button type="button" class="btn btn-ghost" data-close-modal="<?= $isEdit ? 'matching-rule-edit-modal' : 'matching-rule-create-modal' ?>">Отмена</button>
        </div>
    </div>
</form>
