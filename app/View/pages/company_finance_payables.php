<?php

use App\Service\FinanceInvoiceService;
use App\Service\FinanceObligationService;

$rows = $report['rows'] ?? [];
$summary = $report['summary'] ?? [];
$money = static fn(mixed $v): string => FinanceInvoiceService::formatAmount((string)($v ?? '0.00')) . ' ₽';
$fmtDate = static function (mixed $value): string {
    $value = trim((string)$value);
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value ? $date->format('d.m.Y') : '—';
};
$displayChannel = static function (string $channel): string {
    $channel = trim($channel);
    if ($channel === '') return '';
    if (preg_match('/касс/iu', $channel)) return 'Средства компании';
    return $channel;
};
?>
<style>
.payables-page{display:block;min-height:0}.payables-toolbar{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;margin-bottom:12px}.payables-toolbar__actions{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end}.payables-muted{color:var(--muted,#746f66)}.payables-summary{display:grid;grid-template-columns:repeat(6,minmax(130px,1fr));gap:8px;margin-bottom:12px}.payables-stat{border:1px solid var(--border,#d3cec3);background:var(--surface,#fff);padding:10px 12px;min-height:66px}.payables-stat__label{font-size:11px;color:var(--muted,#746f66);text-transform:uppercase}.payables-stat__value{font-size:18px;font-weight:700;margin-top:5px}.payables-stat--danger .payables-stat__value{color:var(--danger,#992e26)}.payables-channels{display:grid;grid-template-columns:repeat(2,minmax(220px,1fr));gap:8px;margin-bottom:12px}.payables-channel{border:1px solid var(--border,#d3cec3);background:var(--surface,#fff);padding:10px 12px}.payables-channel strong{display:block;margin-bottom:3px}.payables-channel span{font-size:12px;color:var(--muted,#746f66)}.payables-filter{display:flex;gap:8px;align-items:center;margin-bottom:10px}.payables-filter .field-input,.payables-filter .field-select{min-height:34px}.payables-filter__search{min-width:260px;max-width:420px;flex:1}.payables-table-wrap{overflow:auto;border:1px solid var(--border,#d3cec3);background:var(--surface,#fff)}.payables-table{width:100%;border-collapse:collapse;min-width:1460px}.payables-table th,.payables-table td{padding:8px 9px;border-bottom:1px solid var(--border,#e3dfd6);text-align:left;vertical-align:top}.payables-table th{font-size:11px;text-transform:uppercase;color:var(--muted,#746f66);background:var(--surface-2,#f4f1eb);position:sticky;top:0;z-index:1}.payables-table td.num,.payables-table th.num{text-align:right;white-space:nowrap}.payables-overdue{color:var(--danger,#992e26);font-weight:700}.payables-paid{color:var(--success,#1f6b43);font-weight:700}.payables-channel-badges{display:flex;gap:4px;flex-wrap:wrap}.payables-channel-badge{display:inline-flex;padding:2px 6px;border:1px solid var(--border,#d3cec3);background:var(--surface-2,#f4f1eb);font-size:11px;white-space:nowrap}.payables-empty-filter{display:none;text-align:center;padding:24px;color:var(--muted,#746f66)}@media(max-width:1200px){.payables-summary{grid-template-columns:repeat(3,minmax(140px,1fr))}.payables-channels{grid-template-columns:1fr}}@media(max-width:760px){.payables-summary{grid-template-columns:repeat(2,minmax(130px,1fr))}.payables-toolbar{flex-direction:column}.payables-toolbar__actions{justify-content:flex-start}.payables-filter{align-items:stretch;flex-direction:column}.payables-filter__search{max-width:none;min-width:0}}
</style>
<div class="payables-page">
    <div class="payables-toolbar">
        <div><h1 style="margin:0 0 4px;font-size:20px;">Кредиторская задолженность</h1><div class="payables-muted">Обязательства перед перевозчиками из условий рейсов, полученные счета и фактические оплаты.</div></div>
        <div class="payables-toolbar__actions">
            <a class="btn btn-secondary" href="<?= e(app_url('/company/finance/invoices')) ?>">Счета</a>
            <a class="btn btn-secondary" href="<?= e(app_url('/company/finance/receivables')) ?>">Дебиторка</a>
            <a class="btn btn-secondary" href="<?= e(app_url('/company/finance/bank-accounts')) ?>">Выписки</a>
            <a class="btn btn-secondary" href="<?= e(app_url('/company/finance/employee-payments')) ?>">Сотрудники</a>
        </div>
    </div>

    <?php if (!empty($successFlash)): ?><div class="notice success"><?= e((string)$successFlash) ?></div><?php endif; ?>
    <?php if (!empty($errorFlash)): ?><div class="notice warn"><?= e((string)$errorFlash) ?></div><?php endif; ?>
    <?php if (!empty($dbError)): ?><div class="notice warn"><?= e($dbError) ?></div><?php endif; ?>

    <div class="payables-summary">
        <div class="payables-stat"><div class="payables-stat__label">Всего к оплате</div><div class="payables-stat__value"><?= e($money($summary['total'] ?? 0)) ?></div></div>
        <div class="payables-stat payables-stat--danger"><div class="payables-stat__label">Просрочено</div><div class="payables-stat__value"><?= e($money($summary['overdue'] ?? 0)) ?></div></div>
        <div class="payables-stat"><div class="payables-stat__label">1–7 дней</div><div class="payables-stat__value"><?= e($money($summary['aging_1_7'] ?? 0)) ?></div></div>
        <div class="payables-stat"><div class="payables-stat__label">8–30 дней</div><div class="payables-stat__value"><?= e($money($summary['aging_8_30'] ?? 0)) ?></div></div>
        <div class="payables-stat"><div class="payables-stat__label">31–60 дней</div><div class="payables-stat__value"><?= e($money($summary['aging_31_60'] ?? 0)) ?></div></div>
        <div class="payables-stat"><div class="payables-stat__label">61+ дней</div><div class="payables-stat__value"><?= e($money($summary['aging_61_plus'] ?? 0)) ?></div></div>
    </div>

    <div class="payables-channels" aria-label="Способы оплаты перевозчикам">
        <div class="payables-channel"><strong>Расчётный счёт</strong><span>Исходящий платёж из банковской выписки распределяется на входящий счёт и обязательство.</span></div>
        <div class="payables-channel"><strong>Сотрудник</strong><span>Действие «Оплатил счёт» уменьшает средства у сотрудника и закрывает входящий счёт и обязательство.</span></div>
    </div>

    <div class="payables-filter">
        <input type="search" class="field-input payables-filter__search" placeholder="Перевозчик, счёт или рейс" data-payables-search>
        <select class="field-select" data-payables-status><option value="all">Все состояния</option><option value="overdue">Просроченные</option><option value="partial">Частично оплаченные</option><option value="unpaid">Неоплаченные</option><option value="paid">Оплаченные</option></select>
    </div>

    <div class="payables-table-wrap"><table class="payables-table"><thead><tr><th>Перевозчик</th><th>Рейс / событие</th><th>Входящие счета</th><th>Срок</th><th>Статус</th><th class="num">Обязательство</th><th class="num">Получено счетов</th><th class="num">Оплачено</th><th class="num">Остаток</th><th>Источник оплаты</th><th class="num">Просрочка</th></tr></thead><tbody data-payables-body>
    <?php if (!$rows): ?><tr><td colspan="11" class="payables-muted" style="text-align:center;padding:24px;">Кредиторских обязательств пока нет.</td></tr>
    <?php else: foreach ($rows as $row):
        $overdueDays=(int)($row['overdue_days']??0);$status=(string)($row['status']??'');$paid=(float)($row['paid_amount']??0);$remaining=(float)($row['remaining_amount']??0);$filterState=$remaining<=0?'paid':($overdueDays>0?'overdue':($paid>0?'partial':'unpaid'));$searchText=mb_strtolower(implode(' ',[(string)($row['counterparty_name']??''),(string)($row['counterparty_inn']??''),(string)($row['invoice_numbers']??''),(string)($row['parent_label']??'').' рейс '.(string)($row['source_parent_id']??'')]),'UTF-8');$channels=array_values(array_unique(array_filter(array_map(static fn($c)=>$displayChannel((string)$c),array_map('trim',explode(',',(string)($row['payment_channels']??'')))))));$due=trim((string)($row['due_date']??''));$forecast=trim((string)($row['forecast_due_date']??''));$deadlineText=$due!==''?$fmtDate($due):($forecast!==''?'Прогноз '.$fmtDate($forecast):'—');
    ?>
        <tr data-payables-row data-search="<?= e($searchText) ?>" data-state="<?= e($filterState) ?>">
            <td><strong><?= e((string)($row['counterparty_name'] ?? '—')) ?></strong><?php if (!empty($row['counterparty_inn'])): ?><div class="payables-muted">ИНН <?= e((string)$row['counterparty_inn']) ?></div><?php endif; ?></td>
            <td><strong><?= e((string)($row['parent_label'] ?? ('Рейс #'.(int)($row['source_parent_id'] ?? 0)))) ?></strong><div class="payables-muted"><?= e((string)($row['condition_label'] ?? '—')) ?></div></td>
            <td><?= e((string)($row['invoice_numbers'] ?: '—')) ?></td><td><?= e($deadlineText) ?></td><td class="<?= $overdueDays>0?'payables-overdue':($remaining<=0?'payables-paid':'') ?>"><?= e(FinanceObligationService::statusLabel($status)) ?></td><td class="num"><?= e($money($row['amount'] ?? 0)) ?></td><td class="num"><?= e($money($row['invoiced_amount'] ?? 0)) ?></td><td class="num"><?= e($money($row['paid_amount'] ?? 0)) ?></td><td class="num <?= $overdueDays>0?'payables-overdue':'' ?>"><?= e($money($row['remaining_amount'] ?? 0)) ?></td>
            <td><?php if (!$channels): ?><span class="payables-muted">—</span><?php else: ?><div class="payables-channel-badges"><?php foreach ($channels as $channel): ?><span class="payables-channel-badge"><?= e($channel) ?></span><?php endforeach; ?></div><?php endif; ?></td><td class="num <?= $overdueDays>0?'payables-overdue':'' ?>"><?= $overdueDays>0 ? e($overdueDays . ' дн.') : '—' ?></td>
        </tr>
    <?php endforeach; ?><tr class="payables-empty-filter" data-payables-empty><td colspan="11">По выбранному фильтру обязательств нет.</td></tr><?php endif; ?>
    </tbody></table></div>
</div>
<script>
(function(){const search=document.querySelector('[data-payables-search]'),status=document.querySelector('[data-payables-status]'),rows=Array.from(document.querySelectorAll('[data-payables-row]')),empty=document.querySelector('[data-payables-empty]');if(!search||!status||!rows.length)return;const apply=()=>{const needle=(search.value||'').trim().toLocaleLowerCase('ru-RU'),state=status.value;let shown=0;rows.forEach(row=>{const okText=!needle||(row.dataset.search||'').includes(needle),okState=state==='all'||row.dataset.state===state;row.hidden=!(okText&&okState);if(!row.hidden)shown++;});if(empty)empty.style.display=shown===0?'table-row':'none';};search.addEventListener('input',apply);status.addEventListener('change',apply);})();
</script>