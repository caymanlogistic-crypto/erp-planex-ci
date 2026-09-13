<?php

use App\Service\DateCalculationService;
use App\Service\FinanceInvoiceService;

$fmtDate = static function (mixed $value): string {
    $value = trim((string)$value);
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value ? $date->format('d.m.Y') : '—';
};
$canEdit = $canEdit ?? false;
$canDelete = $canDelete ?? false;
$canPayCarrier = $canPayCarrier ?? false;
$settlements = $settlements ?? [];

$effectiveStatus = (string)($invoice['status'] ?? '');
if ($effectiveStatus === 'draft') {
    $effectiveStatus = (string)($invoice['direction'] ?? '') === FinanceInvoiceService::DIRECTION_INCOMING ? 'received' : 'issued';
}
$hasOverdue = false;
$resolvedDueDates = [];
$hasUnresolvedDue = false;
foreach ($links ?? [] as $link) {
    if (in_array((string)($link['obligation_status'] ?? ''), ['overdue', 'overdue_partial'], true)) $hasOverdue = true;
    $due = trim((string)($link['due_date'] ?? ''));
    if ($due === '') $hasUnresolvedDue = true; else $resolvedDueDates[$due] = true;
}
if (!in_array($effectiveStatus, ['cancelled', 'paid'], true) && $hasOverdue) {
    $effectiveStatus = (float)($invoice['paid_amount'] ?? 0) > 0 ? 'overdue_partial' : 'overdue';
} elseif (!in_array($effectiveStatus, ['cancelled', 'paid'], true) && (float)($invoice['paid_amount'] ?? 0) > 0 && (float)($invoice['remaining_amount'] ?? 0) > 0) {
    $effectiveStatus = 'partially_paid';
}
if (($links ?? []) === []) $deadlineText = '—';
elseif ($resolvedDueDates === []) $deadlineText = 'Ожидается событие';
elseif (!$hasUnresolvedDue && count($resolvedDueDates) === 1) $deadlineText = $fmtDate((string)array_key_first($resolvedDueDates));
else $deadlineText = 'Несколько сроков';
?>
<?php if ($error): ?>
<div class="form-alert alert-error"><?= e($error) ?></div>
<?php elseif ($invoice): ?>
<style>
.invoice-pay-channels{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;margin-top:8px}.invoice-pay-channel{border:1px solid var(--border,#d3cec3);background:var(--surface-2,#f4f1eb);padding:9px 10px;min-height:74px}.invoice-pay-channel strong{display:block;margin-bottom:3px}.invoice-pay-channel span{display:block;color:var(--muted,#746f66);font-size:11px;line-height:1.35}.invoice-pay-channel .btn{margin-top:7px}.invoice-settlement-list{border:1px solid var(--border,#d3cec3);background:var(--surface,#fff)}.invoice-settlement-row{display:grid;grid-template-columns:150px 1fr 145px;gap:10px;padding:8px 10px;border-bottom:1px solid var(--border,#e3dfd6);align-items:center}.invoice-settlement-row:last-child{border-bottom:0}.invoice-settlement-date-value{font-weight:700}.invoice-settlement-date-edit{display:inline-flex;margin-top:4px;padding:2px 5px;font-size:10px;line-height:1.15}.invoice-settlement-date-form{display:none;margin-top:6px}.invoice-settlement-date-form.is-open{display:block}.invoice-settlement-date-form-row{display:flex;align-items:center;gap:5px}.invoice-settlement-date-form .field-input{height:28px;min-width:128px;padding:3px 5px;font-size:11px}.invoice-settlement-date-form .btn{padding:3px 6px;font-size:10px}.invoice-settlement-date-error{display:none;margin-top:4px;color:#8b2f22;font-size:10px;line-height:1.25}.invoice-settlement-date-error.is-visible{display:block}.invoice-settlement-date-source{display:block;margin-top:3px;color:var(--muted,#746f66);font-size:10px}.invoice-settlement-source strong{display:block}.invoice-settlement-source span{display:block;color:var(--muted,#746f66);font-size:11px}.invoice-settlement-amount{text-align:right;font-weight:700;white-space:nowrap}@media(max-width:760px){.invoice-pay-channels{grid-template-columns:1fr}.invoice-settlement-row{grid-template-columns:120px 1fr}.invoice-settlement-amount{grid-column:2}}
</style>
<div class="driver-modal-body">
    <h3 class="driver-view-name">Счёт №<?= e($invoice['number'] ?? '') ?></h3>
    <div class="driver-view-card"><div class="driver-view-grid">
        <div class="driver-view-row"><div class="driver-view-cell driver-view-cell-label">Направление</div><div class="driver-view-cell driver-view-cell-value"><?= e(FinanceInvoiceService::directionLabel($invoice['direction'] ?? null)) ?></div></div>
        <div class="driver-view-row"><div class="driver-view-cell driver-view-cell-label">Дата счёта</div><div class="driver-view-cell driver-view-cell-value"><?= e($fmtDate($invoice['invoice_date'] ?? '')) ?></div></div>
        <div class="driver-view-row"><div class="driver-view-cell driver-view-cell-label">Состояние</div><div class="driver-view-cell driver-view-cell-value"><span class="<?= e(FinanceInvoiceService::statusBadgeClass($effectiveStatus)) ?>"><span class="dot"></span><?= e(FinanceInvoiceService::statusLabel($effectiveStatus)) ?></span></div></div>
        <div class="driver-view-row"><div class="driver-view-cell driver-view-cell-label">Контрагент</div><div class="driver-view-cell driver-view-cell-value"><?= e($invoice['counterparty_name'] ?? '—') ?><?php if (!empty($invoice['counterparty_inn'])): ?><div class="driver-view-hint">ИНН <?= e($invoice['counterparty_inn']) ?></div><?php endif; ?></div></div>
        <div class="driver-view-row"><div class="driver-view-cell driver-view-cell-label">Сумма</div><div class="driver-view-cell driver-view-cell-value"><?= e(FinanceInvoiceService::formatAmount($invoice['amount'] ?? null)) ?> ₽</div></div>
        <div class="driver-view-row"><div class="driver-view-cell driver-view-cell-label">НДС</div><div class="driver-view-cell driver-view-cell-value"><?= $invoice['vat_rate'] !== null ? e(((float)$invoice['vat_rate'] == 0.0 ? '0' : rtrim(rtrim((string)$invoice['vat_rate'], '0'), '.')) . '%') : 'Без НДС' ?></div></div>
        <div class="driver-view-row"><div class="driver-view-cell driver-view-cell-label">Срок оплаты</div><div class="driver-view-cell driver-view-cell-value"><?= e($deadlineText) ?></div></div>
        <div class="driver-view-row"><div class="driver-view-cell driver-view-cell-label">Оплачено</div><div class="driver-view-cell driver-view-cell-value"><?= e(FinanceInvoiceService::formatAmount($invoice['paid_amount'] ?? null)) ?> ₽</div></div>
        <div class="driver-view-row"><div class="driver-view-cell driver-view-cell-label">Остаток</div><div class="driver-view-cell driver-view-cell-value"><?= e(FinanceInvoiceService::formatAmount($invoice['remaining_amount'] ?? null)) ?> ₽</div></div>
        <?php if (!empty($invoice['basis'])): ?><div class="driver-view-row driver-view-row-wide"><div class="driver-view-cell driver-view-cell-label">Основание</div><div class="driver-view-cell driver-view-cell-value"><?= e($invoice['basis']) ?></div></div><?php endif; ?>
        <?php if (!empty($invoice['comment'])): ?><div class="driver-view-row driver-view-row-wide"><div class="driver-view-cell driver-view-cell-label">Комментарий</div><div class="driver-view-cell driver-view-cell-value"><?= e($invoice['comment']) ?></div></div><?php endif; ?>
        <?php if (!empty($links)): ?><div class="driver-view-row driver-view-row-wide"><div class="driver-view-cell driver-view-cell-label">Платёжные обязательства</div><div class="driver-view-cell driver-view-cell-value">
            <?php foreach ($links as $link): $condition = DateCalculationService::CONDITION_LABELS[(string)($link['condition_type'] ?? '')] ?? 'Условие оплаты'; $due = trim((string)($link['due_date'] ?? '')); $forecast = trim((string)($link['forecast_due_date'] ?? '')); $deadline = $due !== '' ? $fmtDate($due) : ($forecast !== '' ? 'прогноз ' . $fmtDate($forecast) : 'ожидается событие'); ?>
            <div class="driver-view-inline-row"><div><strong><?= e((string)($link['parent_label'] ?? ('Рейс #'.(int)($link['source_parent_id'] ?? $link['linear_route_id'] ?? 0)))) ?></strong> · <?= e($condition) ?> · <?= e($deadline) ?> · <?= e(FinanceInvoiceService::formatAmount($link['amount'] ?? null)) ?> ₽</div></div>
            <?php endforeach; ?>
        </div></div><?php endif; ?>
    </div></div>

    <div class="section-title mt-section"><span>Фактические оплаты</span></div>
    <?php if (!$settlements): ?>
        <div class="empty-state compact mt-half"><p class="empty-desc">Оплат по счёту пока нет.</p></div>
    <?php else: ?>
        <div class="invoice-settlement-list mt-half">
        <?php foreach ($settlements as $payment): $operationId=(int)($payment['operation_id']??0);$bankLinked=(int)($payment['bank_transaction_id']??0)>0;$paymentDate=trim((string)($payment['actual_date']??''));$canEditPaymentDate=$canEdit&&!$bankLinked&&$operationId>0&&$paymentDate!==''; ?>
            <div class="invoice-settlement-row" data-invoice-settlement-operation="<?= $operationId ?>"><div><div class="invoice-settlement-date-value"><?= e($fmtDate($paymentDate)) ?></div>
            <?php if ($canEditPaymentDate): ?><button type="button" class="btn btn-ghost btn-sm invoice-settlement-date-edit" data-settlement-date-edit>Изменить дату</button><form class="invoice-settlement-date-form" data-settlement-date-form method="post" action="<?= e(app_url('/company/finance/invoices/'.(int)$invoice['id'].'/settlements/'.$operationId.'/date')) ?>"><?= csrfField() ?><div class="invoice-settlement-date-form-row"><input class="field-input" type="date" name="operation_date" value="<?= e($paymentDate) ?>" required><button type="submit" class="btn btn-primary btn-sm" data-settlement-date-save>Сохранить</button><button type="button" class="btn btn-ghost btn-sm" data-settlement-date-cancel>Отмена</button></div><div class="invoice-settlement-date-error" data-settlement-date-error></div></form><?php elseif ($bankLinked): ?><span class="invoice-settlement-date-source">Дата из банковской выписки</span><?php endif; ?></div>
            <div class="invoice-settlement-source"><strong><?= e((string)($payment['channel'] ?? 'Финансовая операция')) ?></strong><?php if (!empty($payment['channel_detail'])): ?><span><?= e((string)$payment['channel_detail']) ?></span><?php endif; ?></div><div class="invoice-settlement-amount"><?= e(FinanceInvoiceService::formatAmount($payment['amount'] ?? null)) ?> ₽</div></div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($canPayCarrier): ?>
    <div class="section-title mt-section"><span>Оплатить входящий счёт</span></div>
    <div class="invoice-pay-channels">
        <div class="invoice-pay-channel"><strong>Расчётный счёт</strong><span>Оплата фиксируется по исходящему платежу из банковской выписки и распределяется на этот счёт.</span><a class="btn btn-secondary btn-sm" href="<?= e(app_url('/company/finance/bank-accounts')) ?>">Открыть выписки</a></div>
        <div class="invoice-pay-channel"><strong>Сотрудник</strong><span>Если счёт оплатил сотрудник, используйте действие «Оплатил счёт» во взаиморасчётах с сотрудниками.</span><a class="btn btn-secondary btn-sm" href="<?= e(app_url('/company/finance/employee-payments')) ?>">К сотрудникам</a></div>
    </div>
    <?php endif; ?>

    <div class="section-title mt-section"><span>История изменений</span><button type="button" class="btn btn-ghost btn-sm" data-invoice-history-btn data-invoice-id="<?= (int)($invoice['id'] ?? 0) ?>">История</button></div>
    <div id="invoice-history-container" class="mt-half"><div class="empty-state compact"><p class="empty-desc">Нажмите «История» для загрузки.</p></div></div>
</div>
<div class="modal-foot is-spaced"><div class="modal-foot-actions"><?php if ($canDelete && $effectiveStatus !== 'cancelled'): ?><button type="button" class="btn btn-ghost" data-invoice-cancel-btn>Аннулировать</button><?php endif; ?></div><div class="modal-foot-actions"><button type="button" class="btn btn-ghost" data-close-modal="invoice-view-modal">Закрыть</button><?php if ($canEdit): ?><button type="button" class="btn btn-primary" data-invoice-edit-btn>Редактировать</button><?php endif; ?></div></div>
<script>
(function() {
    var modal = document.getElementById('invoice-view-modal'); if (!modal) return;
    modal.querySelectorAll('[data-settlement-date-edit]').forEach(function(button){button.addEventListener('click',function(){var form=button.parentElement.querySelector('[data-settlement-date-form]');if(!form)return;form.classList.add('is-open');button.style.display='none';var input=form.querySelector('input[name="operation_date"]');if(input)input.focus();});});
    modal.querySelectorAll('[data-settlement-date-cancel]').forEach(function(button){button.addEventListener('click',function(){var form=button.closest('[data-settlement-date-form]');if(!form)return;form.classList.remove('is-open');var editButton=form.parentElement.querySelector('[data-settlement-date-edit]');if(editButton)editButton.style.display='';var error=form.querySelector('[data-settlement-date-error]');if(error){error.textContent='';error.classList.remove('is-visible');}});});
    modal.querySelectorAll('[data-settlement-date-form]').forEach(function(form){form.addEventListener('submit',function(event){event.preventDefault();var save=form.querySelector('[data-settlement-date-save]');var error=form.querySelector('[data-settlement-date-error]');if(save)save.disabled=true;if(error){error.textContent='';error.classList.remove('is-visible');}fetch(form.action,{method:'POST',body:new FormData(form),headers:{'X-Requested-With':'XMLHttpRequest'}}).then(function(response){return response.json().catch(function(){return {ok:false,message:'Некорректный ответ сервера.'};}).then(function(data){if(!response.ok||!data.ok)throw new Error(data.message||('HTTP '+response.status));return data;});}).then(function(){window.location.reload();}).catch(function(err){if(error){error.textContent=err.message||'Не удалось изменить дату.';error.classList.add('is-visible');}if(save)save.disabled=false;});});});
    var editBtn=modal.querySelector('[data-invoice-edit-btn]');if(editBtn)editBtn.addEventListener('click',function(){var id=<?= (int)($invoice['id']??0) ?>;var editBody=document.getElementById('invoice-edit-modal-body');editBody.innerHTML='<div class="empty-state compact"><p>Загрузка...</p></div>';window.openModal('invoice-edit-modal');fetch(window.getErpBasePath()+'/company/finance/invoices/'+id+'/modal-edit').then(function(r){if(!r.ok)throw new Error('HTTP '+r.status);return r.text();}).then(function(html){if(window.planexSetHtmlAndRunScripts)window.planexSetHtmlAndRunScripts(editBody,html);else editBody.innerHTML=html;}).catch(function(){editBody.innerHTML='<div class="form-alert alert-error">Не удалось загрузить форму редактирования.</div>';});});
    var historyBtn=modal.querySelector('[data-invoice-history-btn]'),historyContainer=modal.querySelector('#invoice-history-container');if(historyBtn&&historyContainer)historyBtn.addEventListener('click',function(){historyContainer.innerHTML='<div class="empty-state compact"><p>Загрузка...</p></div>';fetch(window.getErpBasePath()+'/company/finance/invoices/'+historyBtn.dataset.invoiceId+'/history').then(function(r){if(!r.ok)throw new Error('HTTP '+r.status);return r.text();}).then(function(html){historyContainer.innerHTML=html;}).catch(function(){historyContainer.innerHTML='<div class="form-alert alert-error">Не удалось загрузить историю.</div>';});});
    var cancelBtn=modal.querySelector('[data-invoice-cancel-btn]');if(cancelBtn)cancelBtn.addEventListener('click',function(){var cancelModal=document.getElementById('invoice-cancel-modal');if(!cancelModal)return;var cancelForm=cancelModal.querySelector('.finance-cancel-form');if(cancelForm)cancelForm.action=window.getErpBasePath()+'/company/finance/invoices/<?= (int)($invoice['id']??0) ?>/modal-delete';window.openModal('invoice-cancel-modal');});
})();
</script>
<?php endif; ?>