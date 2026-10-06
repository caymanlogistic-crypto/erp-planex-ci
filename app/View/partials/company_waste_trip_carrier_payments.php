<?php

use App\Service\LinearRouteService;

$paymentRows = array_values((array) ($paymentRows ?? []));
if ($paymentRows === []) {
    $paymentRows = [[
        'amount' => '',
        'payment_method' => 'cashless',
        'vat_rate' => '',
        'condition_type' => 'after_end',
        'days_count' => '7',
        'days_kind' => 'calendar',
        'specific_due_date' => '',
        'condition_comment' => '',
    ]];
}
$vatRateOptions = ['', '0', '5', '7', '20', '22'];
?>
<div class="waste-form-field span-2 waste-carrier-payment-block">
    <div class="linear-trip-payment-card">
        <div class="linear-trip-block-head">
            <div>
                <div class="linear-trip-block-title">Себес · оплата подрядчику</div>
                <label class="waste-cost-toggle"><input type="checkbox" name="no_markup" value="1" data-waste-no-markup <?= !empty($noMarkup) ? 'checked' : '' ?> <?= !empty($noMarkupDisabled) ? 'disabled' : '' ?>> Без наценки</label>
                <?php if (!empty($noMarkupDisabled)): ?><div class="field-msg">Недоступно для рейса с сохранёнными историческими расчётами.</div><?php endif; ?>
                <div class="field-msg waste-no-markup-preview" data-waste-no-markup-preview hidden>Себес равен продаже: <strong data-waste-no-markup-total>—</strong></div>
            </div>
            <button type="button" class="btn btn-ghost" data-add-payment-row="carrier_payments">+ Добавить оплату</button>
        </div>
        <div class="linear-trip-payment-rows" data-payment-container="carrier_payments" data-default-days="7">
            <?php foreach ($paymentRows as $paymentIndex => $payment): ?>
                <?php
                $fieldBase = 'carrier_payments[' . $paymentIndex . ']';
                $conditionType = (string) ($payment['condition_type'] ?? '');
                $showDays = LinearRouteService::conditionTypeShowDays($conditionType);
                $showSpecificDate = LinearRouteService::conditionTypeShowSpecificDate($conditionType);
                $paymentMethod = (string) ($payment['payment_method'] ?? 'cashless');
                $vatRate = array_key_exists('vat_rate', $payment) && $payment['vat_rate'] !== null ? (string) $payment['vat_rate'] : '';
                ?>
                <div class="linear-trip-payment-row" data-payment-row>
                    <div class="field">
                        <label class="field-label">Сумма <span class="req">*</span></label>
                        <input type="text" name="<?= e($fieldBase) ?>[amount]" value="<?= e((string) ($payment['amount'] ?? '')) ?>" class="field-input" inputmode="numeric" placeholder="125000">
                        <div class="field-msg"></div>
                    </div>
                    <div class="field">
                        <label class="field-label">Способ оплаты <span class="req">*</span></label>
                        <select name="<?= e($fieldBase) ?>[payment_method]" class="field-input">
                            <?php if($paymentMethod===''||$paymentMethod==='unknown'):?><option value="unknown" selected>Не указан</option><?php endif;?>
                            <?php foreach (LinearRouteService::PAYMENT_METHODS as $value => $label): ?>
                                <option value="<?= e($value) ?>" <?= $paymentMethod === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="field-msg"></div>
                    </div>
                    <div class="field">
                        <label class="field-label">НДС <span class="req">*</span></label>
                        <select name="<?= e($fieldBase) ?>[vat_rate]" class="field-input">
                            <?php if(($payment['payment_type']??null)===''||$vatRate==='unknown'):?><option value="unknown" selected>Не указан</option><?php endif;?>
                            <?php foreach ($vatRateOptions as $value): ?>
                                <option value="<?= e($value) ?>" <?= $vatRate === $value && ($payment['payment_type']??null)!=='' ? 'selected' : '' ?>><?= e($value === '' ? 'Без НДС' : 'НДС ' . $value . '%') ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="field-msg"></div>
                    </div>
                    <div class="field">
                        <label class="field-label">Срок оплаты <span class="req">*</span></label>
                        <select name="<?= e($fieldBase) ?>[condition_type]" class="field-input" data-condition-type>
                            <option value="">— Выберите срок —</option>
                            <?php foreach (\App\Service\DateCalculationService::CONDITION_LABELS as $value => $label): ?>
                                <option value="<?= e($value) ?>" <?= $conditionType === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="field-msg"></div>
                    </div>
                    <div class="field<?= $showDays ? '' : ' is-hidden' ?>" data-days-count-wrapper>
                        <label class="field-label">Дней <span class="req">*</span></label>
                        <input type="number" min="1" name="<?= e($fieldBase) ?>[days_count]" value="<?= e((string) ($payment['days_count'] ?? '')) ?>" class="field-input">
                        <div class="field-msg"></div>
                    </div>
                    <div class="field<?= $showDays ? '' : ' is-hidden' ?>" data-days-kind-wrapper>
                        <label class="field-label">Тип дней <span class="req">*</span></label>
                        <select name="<?= e($fieldBase) ?>[days_kind]" class="field-input">
                            <option value="">— Выберите тип —</option>
                            <?php foreach (LinearRouteService::PAYMENT_DUE_DAYS_KINDS as $value => $label): ?>
                                <option value="<?= e($value) ?>" <?= (string) ($payment['days_kind'] ?? '') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="field-msg"></div>
                    </div>
                    <div class="field<?= $showSpecificDate ? '' : ' is-hidden' ?>" data-specific-date-wrapper>
                        <label class="field-label">Конкретная дата <span class="req">*</span></label>
                        <input type="text" name="<?= e($fieldBase) ?>[specific_due_date]" value="<?= e((string) ($payment['specific_due_date'] ?? '')) ?>" class="field-input js-erp-date-picker" placeholder="дд.мм.гггг" inputmode="numeric" autocomplete="off">
                        <div class="field-msg"></div>
                    </div>
                    <div class="field">
                        <label class="field-label">Комментарий к сроку</label>
                        <input type="text" name="<?= e($fieldBase) ?>[condition_comment]" value="<?= e((string) ($payment['condition_comment'] ?? '')) ?>" class="field-input" placeholder="Опциональный комментарий">
                        <div class="field-msg"></div>
                    </div>
                    <button type="button" class="linear-trip-row-remove<?= count($paymentRows) > 1 ? '' : ' is-hidden' ?>" data-remove-payment-row aria-label="Удалить оплату">×</button>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
