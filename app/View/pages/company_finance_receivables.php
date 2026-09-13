<?php

use App\Service\FinanceInvoiceService;
use App\Service\FinanceObligationService;

$rows = $report['rows'] ?? [];
$summary = $report['summary'] ?? [];
$money = static fn(mixed $v): string => FinanceInvoiceService::formatAmount((string)($v ?? '0.00')) . ' ₽';
?>
<style>
.receivables-page{display:block;min-height:0}.receivables-toolbar{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:12px}.receivables-toolbar__actions{display:flex;gap:8px;flex-wrap:wrap}.receivables-summary{display:grid;grid-template-columns:repeat(6,minmax(130px,1fr));gap:8px;margin-bottom:12px}.receivables-stat{border:1px solid var(--border,#d3cec3);background:var(--surface,#fff);padding:10px 12px;min-height:66px}.receivables-stat__label{font-size:11px;color:var(--muted,#746f66);text-transform:uppercase}.receivables-stat__value{font-size:18px;font-weight:700;margin-top:5px}.receivables-stat--danger .receivables-stat__value{color:var(--danger,#992e26)}.receivables-table-wrap{overflow:auto;border:1px solid var(--border,#d3cec3);background:var(--surface,#fff)}.receivables-table{width:100%;border-collapse:collapse;min-width:1100px}.receivables-table th,.receivables-table td{padding:8px 9px;border-bottom:1px solid var(--border,#e3dfd6);text-align:left;vertical-align:top}.receivables-table th{font-size:11px;text-transform:uppercase;color:var(--muted,#746f66);background:var(--surface-2,#f4f1eb);position:sticky;top:0}.receivables-table td.num,.receivables-table th.num{text-align:right;white-space:nowrap}.receivables-overdue{color:var(--danger,#992e26);font-weight:700}.receivables-paid{color:var(--success,#1f6b43);font-weight:700}.receivables-muted{color:var(--muted,#746f66)}@media(max-width:1200px){.receivables-summary{grid-template-columns:repeat(3,minmax(140px,1fr))}}@media(max-width:720px){.receivables-summary{grid-template-columns:repeat(2,minmax(130px,1fr))}.receivables-toolbar{align-items:flex-start;flex-direction:column}}
</style>
<div class="receivables-page">
    <div class="receivables-toolbar">
        <div>
            <h1 style="margin:0 0 4px;font-size:20px;">Дебиторская задолженность</h1>
            <div class="receivables-muted">Платёжные обязательства клиентов по рейсам, счетам и фактическим поступлениям.</div>
        </div>
        <div class="receivables-toolbar__actions"><a class="btn btn-secondary" href="<?= e(app_url('/company/finance/payables')) ?>">Кредиторка</a><a class="btn btn-secondary" href="<?= e(app_url('/company/finance/invoices')) ?>">← Счета</a></div>
    </div>

    <?php if (!empty($dbError)): ?><div class="alert alert-danger"><?= e($dbError) ?></div><?php endif; ?>

    <div class="receivables-summary">
        <div class="receivables-stat"><div class="receivables-stat__label">Всего к получению</div><div class="receivables-stat__value"><?= e($money($summary['total'] ?? 0)) ?></div></div>
        <div class="receivables-stat receivables-stat--danger"><div class="receivables-stat__label">Просрочено</div><div class="receivables-stat__value"><?= e($money($summary['overdue'] ?? 0)) ?></div></div>
        <div class="receivables-stat"><div class="receivables-stat__label">1–7 дней</div><div class="receivables-stat__value"><?= e($money($summary['aging_1_7'] ?? 0)) ?></div></div>
        <div class="receivables-stat"><div class="receivables-stat__label">8–30 дней</div><div class="receivables-stat__value"><?= e($money($summary['aging_8_30'] ?? 0)) ?></div></div>
        <div class="receivables-stat"><div class="receivables-stat__label">31–60 дней</div><div class="receivables-stat__value"><?= e($money($summary['aging_31_60'] ?? 0)) ?></div></div>
        <div class="receivables-stat"><div class="receivables-stat__label">61+ дней</div><div class="receivables-stat__value"><?= e($money($summary['aging_61_plus'] ?? 0)) ?></div></div>
    </div>

    <div class="receivables-table-wrap">
        <table class="receivables-table">
            <thead><tr><th>Клиент</th><th>Рейс / событие</th><th>Счета</th><th>Срок</th><th>Статус</th><th class="num">Обязательство</th><th class="num">Выставлено</th><th class="num">Оплачено</th><th class="num">Остаток</th><th class="num">Просрочка</th></tr></thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="10" class="receivables-muted" style="text-align:center;padding:24px;">Дебиторских обязательств пока нет.</td></tr>
            <?php else: foreach ($rows as $row):
                $overdueDays=(int)($row['overdue_days']??0); $status=(string)($row['status']??'');
            ?>
                <tr>
                    <td><strong><?= e((string)($row['counterparty_name'] ?? '—')) ?></strong><?php if (!empty($row['counterparty_inn'])): ?><div class="receivables-muted">ИНН <?= e((string)$row['counterparty_inn']) ?></div><?php endif; ?></td>
                    <td><strong><?= e((string)($row['parent_label'] ?? ('Рейс #'.(int)($row['source_parent_id'] ?? 0)))) ?></strong><div class="receivables-muted"><?= e((string)($row['condition_label'] ?? '—')) ?></div></td>
                    <td><?= e((string)($row['invoice_numbers'] ?: '—')) ?></td>
                    <td><?= e((string)($row['due_date'] ?? $row['forecast_due_date'] ?? '—')) ?></td>
                    <td class="<?= $overdueDays>0?'receivables-overdue':($status==='paid'?'receivables-paid':'') ?>"><?= e(FinanceObligationService::statusLabel($status)) ?></td>
                    <td class="num"><?= e($money($row['amount'] ?? 0)) ?></td>
                    <td class="num"><?= e($money($row['invoiced_amount'] ?? 0)) ?></td>
                    <td class="num"><?= e($money($row['paid_amount'] ?? 0)) ?></td>
                    <td class="num <?= $overdueDays>0?'receivables-overdue':'' ?>"><?= e($money($row['remaining_amount'] ?? 0)) ?></td>
                    <td class="num <?= $overdueDays>0?'receivables-overdue':'' ?>"><?= $overdueDays>0 ? e($overdueDays . ' дн.') : '—' ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
