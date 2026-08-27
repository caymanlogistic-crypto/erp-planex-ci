<?php

use App\Service\FinanceOperationService;
use App\Service\FinanceAllocationService;

$operation = $operation ?? [];
$invoices = $invoices ?? [];
$routes = $routes ?? [];
$fmtDate = static fn($d) => ($d && $d !== '—') ? date('d.m.Y', strtotime((string) $d) ?: time()) : '—';
?>
<?php if ($error): ?>
<div class="form-alert alert-error"><?= e($error) ?></div>
<?php elseif ($operation): ?>
<form action="<?= app_url('/company/finance/operations/' . (int) $operation['id'] . '/allocate') ?>"
      method="post" data-operation-allocation-form
      data-operation-id="<?= (int) $operation['id'] ?>"
      data-remaining="<?= e((string) $operation['remaining_amount']) ?>"
      data-operation-type="<?= e((string) $operation['operation_type']) ?>">
    <?= csrfField() ?>
    <input type="hidden" name="request_token" value="<?= e($requestToken ?? '') ?>">
    <div class="modal-body">
        <div class="finance-allocation-summary">
            <div><span>Операция</span><b>№<?= (int) $operation['id'] ?></b></div>
            <div><span>Дата</span><b><?= $fmtDate($operation['operation_date'] ?? '') ?></b></div>
            <div><span>Направление</span><b><?= e(FinanceOperationService::operationTypeLabel($operation['operation_type'] ?? null)) ?></b></div>
            <div><span>Исходная сумма</span><b><?= FinanceOperationService::formatAmount($operation['amount'] ?? null) ?> ₽</b></div>
            <div><span>Уже распределено</span><b><?= FinanceAllocationService::formatAmount($operation['allocated_amount'] ?? null) ?> ₽</b></div>
            <div><span>Доступный остаток</span><b><?= FinanceAllocationService::formatAmount($operation['remaining_amount'] ?? null) ?> ₽</b></div>
            <div class="wide"><span>Контрагент</span><b><?= e($operation['counterparty_name'] ?? '—') ?></b></div>
            <div class="wide"><span>Назначение</span><b><?= e($operation['purpose'] ?: '—') ?></b></div>
        </div>
        <div data-form-error class="form-alert alert-error" hidden></div>
        <div class="form-grid-3">
            <div class="field"><label class="field-label">Дата распределения</label><input type="date" name="allocation_date" class="field-input" value="<?= e(date('Y-m-d')) ?>" required></div>
            <div class="field"><label class="field-label">Сумма</label><input type="number" name="amount" class="field-input" min="0.01" step="0.01" max="<?= e((string) $operation['remaining_amount']) ?>" required><div class="field-msg">После распределения останется: <b data-allocation-rest><?= FinanceAllocationService::formatAmount($operation['remaining_amount']) ?> ₽</b></div></div>
        </div>
        <div class="form-grid-3">
            <div class="field"><label class="field-label">Счёт</label><select name="invoice_id" class="field-select"><option value="">— Не выбран —</option><?php foreach ($invoices as $inv): ?><option value="<?= (int) $inv['id'] ?>">№<?= e($inv['number'] ?? '') ?> · <?= e($inv['counterparty_name'] ?? '') ?> · <?= FinanceAllocationService::formatAmount($inv['remaining_amount'] ?? null) ?> ₽</option><?php endforeach; ?></select><div class="field-msg">Если выбрать только счёт, сумма будет распределена по связанным обязательствам автоматически.</div></div>
            <div class="field"><label class="field-label">Рейс</label><select name="linear_route_id" class="field-select" data-allocation-route><option value="">— Не выбран —</option><?php foreach ($routes as $r): ?><option value="<?= (int) $r['id'] ?>">#<?= (int) $r['id'] ?> · <?= e($r['route_type'] ?? '') ?> · <?= e($r['client_name'] ?? '') ?></option><?php endforeach; ?></select></div>
            <div class="field"><label class="field-label">Платёжное обязательство</label><select name="obligation_id" class="field-select" data-allocation-payment><option value="">— Не выбрано —</option></select><div class="field-msg">Перевозка и дополнительные услуги используют один canonical obligation.</div></div>
        </div>
        <div class="field"><label class="field-label">Комментарий</label><textarea name="comment" class="field-textarea" rows="2"></textarea></div>
    </div>
    <div class="modal-foot is-spaced"><button type="button" class="btn btn-ghost" data-close-modal="operation-allocate-modal">Отмена</button><button type="submit" class="btn btn-primary">Создать распределение</button></div>
</form>
<?php endif; ?>
