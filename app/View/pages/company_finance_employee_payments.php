<?php
use App\Service\FinanceCashInvoiceEventService;
use App\Service\FinanceEmployeePaymentService;

$fmt = static fn($v) => FinanceEmployeePaymentService::formatMoney($v);
$toCents = static function ($v): int {
    $s = str_replace([' ', ','], ['', '.'], trim((string)$v));
    if (!preg_match('/^-?\d+(?:\.\d{1,2})?$/D', $s)) return 0;
    $neg = str_starts_with($s, '-');
    if ($neg) $s = substr($s, 1);
    [$r, $k] = array_pad(explode('.', $s, 2), 2, '');
    $c = ((int)$r * 100) + (int)str_pad(substr($k, 0, 2), 2, '0');
    return $neg ? -$c : $c;
};
$fromCents = static fn(int $c): string => ($c < 0 ? '-' : '') . intdiv(abs($c), 100) . '.' . str_pad((string)(abs($c) % 100), 2, '0', STR_PAD_LEFT);
$monthNames = [1=>'Январь',2=>'Февраль',3=>'Март',4=>'Апрель',5=>'Май',6=>'Июнь',7=>'Июль',8=>'Август',9=>'Сентябрь',10=>'Октябрь',11=>'Ноябрь',12=>'Декабрь'];
$monthTitle = static function (string $month) use ($monthNames): string {
    $ts = strtotime($month . '-01');
    if ($ts === false) return $month;
    return ($monthNames[(int)date('n', $ts)] ?? date('m', $ts)) . ' ' . date('Y', $ts);
};
$resultClass = static fn(int $value): string => $value > 0 ? 'is-positive' : ($value < 0 ? 'is-negative' : '');

$invoiceByMovement = [];
foreach (($employeeInvoicePayments ?? []) as $event) {
    $id = (int)($event['employee_movement_id'] ?? 0);
    if ($id > 0) $invoiceByMovement[$id] = $event;
}
$personalByMovement = [];
foreach (($employeePersonalExpenses ?? []) as $event) {
    $id = (int)($event['employee_movement_id'] ?? 0);
    if ($id > 0) $personalByMovement[$id] = $event;
}
$describeRow = static function (array $row) use ($invoiceByMovement, $personalByMovement): array {
    $movementId = (int)($row['id'] ?? 0);
    if (isset($invoiceByMovement[$movementId])) {
        $event = $invoiceByMovement[$movementId];
        $number = trim((string)($event['invoice_number_snapshot'] ?? ''));
        return ['Оплатил счёт', 'Сотрудник', ($number !== '' ? 'Счёт №'.$number : 'Счёт') . (($event['counterparty_name_snapshot'] ?? '') !== '' ? ' · '.$event['counterparty_name_snapshot'] : '')];
    }
    if (isset($personalByMovement[$movementId])) {
        $event = $personalByMovement[$movementId];
        $parts = array_filter([trim((string)($event['counterparty_name'] ?? '')), trim((string)($event['purpose'] ?? '')), trim((string)($event['comment'] ?? ''))]);
        return ['Прочий расход', 'Сотрудник', $parts ? implode(' · ', $parts) : 'Расход компании'];
    }
    $source = strtoupper((string)($row['source_type'] ?? ''));
    $movement = strtoupper((string)($row['movement_type'] ?? ''));
    $basis = trim((string)($row['purpose'] ?? '')) ?: trim((string)($row['note'] ?? ''));
    if ($source === 'CLIENT') return ['Получено от клиента', 'Клиент', $basis ?: 'Оплата клиента'];
    if ($source === 'COMPANY_FUNDED') return ['Оплачено компанией за сотрудника', 'Компания', $basis ?: 'Расход сотрудника, оплаченный компанией'];
    if (!empty($row['employee_transfer'])) return [$movement === 'PAYMENT' ? 'Получено от сотрудника' : 'Передал сотруднику', 'Сотрудник', $basis ?: 'Передача между сотрудниками'];
    if ($source === 'BANK') return [$movement === 'PAYMENT' ? 'Получено с расчётного счёта' : 'Передано на расчётный счёт', 'Расчётный счёт', $basis ?: 'Взаиморасчёт с компанией'];
    return [$movement === 'PAYMENT' ? 'Историческое поступление' : 'Исторический расход', 'Средства компании', $basis ?: 'Историческая операция'];
};

$months = [];
$totalPaid = 0;
$totalReturned = 0;
foreach (($ledger ?? []) as $row) {
    $month = substr((string)$row['operation_date'], 0, 7);
    $months[$month] ??= ['paid'=>0,'returned'=>0,'rows'=>[]];
    $months[$month]['rows'][] = $row;
    if (($row['status'] ?? '') === 'POSTED') {
        $c = $toCents($row['amount']);
        if (($row['movement_type'] ?? '') === 'PAYMENT') { $months[$month]['paid'] += $c; $totalPaid += $c; }
        else { $months[$month]['returned'] += $c; $totalReturned += $c; }
    }
}
krsort($months);
$net = $totalPaid - $totalReturned;
$transferEnabled = count($employees ?? []) >= 2;
$clientInvoices = isset($pdo) ? FinanceCashInvoiceEventService::fetchClientInvoices($pdo) : [];
?>
<style>
.employee-report{width:min(1280px,100%);margin:0 auto}.employee-report .page-head-right{display:flex;align-items:center;gap:8px;flex-wrap:wrap}.employee-report-card{display:block!important;overflow:visible!important}.employee-report-filter{margin-left:auto;display:flex;align-items:center;gap:8px}.employee-report-filter .field-select{width:300px;max-width:32vw;height:26px}.employee-report-summary{display:flex;align-items:center;gap:22px;flex-wrap:wrap;padding:10px 12px;border-bottom:1px solid var(--line-soft);background:var(--surface-strong);font-size:11px}.employee-result.is-positive{color:var(--success)}.employee-result.is-negative{color:var(--danger)}.employee-month-head{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:10px 12px;border-top:1px solid var(--line-soft);border-bottom:1px solid var(--line-soft);background:var(--surface-form);font-size:11px}.employee-month-title{font-weight:700;font-size:12px}.employee-report-table{width:100%;table-layout:fixed}.employee-report-table td,.employee-report-table th{min-width:0}.employee-report-table .employee-comment{white-space:normal;overflow-wrap:anywhere;line-height:1.25}.employee-report-table .money{text-align:right;white-space:nowrap}.employee-report-empty{padding:18px}
</style>
<div class="employee-report">
    <div class="page-head">
        <div class="page-head-left">
            <h1 class="page-title">Взаиморасчёты с сотрудниками</h1>
            <div class="page-summary"><span>Средства у сотрудника, расходы за компанию, оплаты счетов и передачи между сотрудниками.</span></div>
        </div>
        <div class="page-head-right">
            <button type="button" class="btn btn-primary btn--toolbar" id="employee-client-receipt-open" <?= $clientInvoices === [] ? 'disabled' : '' ?> title="<?= $clientInvoices === [] ? 'Нет открытых исходящих счетов клиентов' : 'Сотрудник получил оплату от клиента' ?>">Получено от клиента</button>
            <button type="button" class="btn btn-primary btn--toolbar" id="employee-transfer-open" <?= $transferEnabled ? '' : 'disabled' ?> title="<?= $transferEnabled ? 'Передать средства другому сотруднику' : 'Для передачи нужны минимум два активных сотрудника' ?>">Передать деньги</button>
        </div>
    </div>

    <?php if(!empty($successFlash)): ?><div class="notice success"><?= e($successFlash) ?></div><?php endif; ?>
    <?php if(!empty($errorFlash)): ?><div class="notice warn"><?= e($errorFlash) ?></div><?php endif; ?>

    <div class="table-card table-card--standard employee-report-card">
        <div class="table-toolbar">
            <div class="table-toolbar-left"><strong>Взаиморасчёты с сотрудником</strong></div>
            <form method="get" class="employee-report-filter">
                <span class="employee-report-filter-label">Сотрудник</span>
                <select class="field-select" name="employee_ref" onchange="this.form.submit()">
                    <option value="">— Выберите сотрудника —</option>
                    <?php foreach($employees as $employee): ?><option value="<?= e($employee['ref']) ?>" <?= ($selectedRef??'')===$employee['ref']?'selected':'' ?>><?= e($employee['full_name']) ?></option><?php endforeach; ?>
                </select>
            </form>
        </div>
        <?php if(!$selectedEmployee): ?>
            <div class="employee-report-empty">Выберите сотрудника.</div>
        <?php else: ?>
            <div class="employee-report-summary"><strong>За всё время</strong><span>Поступление: <strong><?= e($fmt($fromCents($totalPaid))) ?> ₽</strong></span><span>Расход: <strong><?= e($fmt($fromCents($totalReturned))) ?> ₽</strong></span><span>ИТОГО: <strong class="employee-result <?= e($resultClass($net)) ?>"><?= e($fmt($fromCents($net))) ?> ₽</strong></span></div>
            <?php if(empty($months)): ?><div class="employee-report-empty">Операций пока нет.</div><?php endif; ?>
            <?php foreach($months as $month=>$bucket): $monthNet=$bucket['paid']-$bucket['returned']; ?>
                <div class="employee-month-head"><div><div class="employee-month-title"><?= e($monthTitle($month)) ?></div><div><?= count($bucket['rows']) ?> операций · новые сверху</div></div><div>За месяц: Поступление <strong><?= e($fmt($fromCents($bucket['paid']))) ?> ₽</strong> · Расход <strong><?= e($fmt($fromCents($bucket['returned']))) ?> ₽</strong> · ИТОГО <strong class="employee-result <?= e($resultClass($monthNet)) ?>"><?= e($fmt($fromCents($monthNet))) ?> ₽</strong></div></div>
                <div class="table-scroll"><table class="table employee-report-table"><colgroup><col style="width:86px"><col style="width:180px"><col style="width:120px"><col><col style="width:105px"><col style="width:105px"><col style="width:110px"></colgroup><thead><tr><th>Дата</th><th>Тип платежа</th><th>Источник</th><th>Комментарий</th><th class="money">Поступление</th><th class="money">Расход</th><th class="money">Сальдо</th></tr></thead><tbody>
                <?php foreach($bucket['rows'] as $row): [$type,$source,$comment]=$describeRow($row); $cancelled=($row['status']??'')==='CANCELLED'; $payment=($row['movement_type']??'')==='PAYMENT'; $balance=$toCents($row['running_balance']??'0.00'); ?>
                    <tr class="<?= $cancelled?'is-muted':'' ?>"><td><?= e(date('d.m.Y',strtotime($row['operation_date']))) ?></td><td><?= e($type) ?></td><td><?= e($source) ?></td><td class="employee-comment" title="<?= e($comment) ?>"><?= e($comment) ?></td><td class="money"><?= $payment&&!$cancelled?e($fmt($row['amount'])).' ₽':'—' ?></td><td class="money"><?= !$payment&&!$cancelled?e($fmt($row['amount'])).' ₽':'—' ?></td><td class="money"><strong class="employee-result <?= e($resultClass($balance)) ?>"><?= e($fmt($fromCents($balance))) ?> ₽</strong></td></tr>
                <?php endforeach; ?>
                </tbody></table></div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<?php if(!empty($selectedEmployee) && ($selectedRef??'')!==''): ?>
<div id="employee-client-receipt-modal" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="employee-client-receipt-title"><div class="modal modal-md"><div class="modal-head"><span class="modal-title" id="employee-client-receipt-title">Получено от клиента</span><button type="button" class="modal-close" data-client-receipt-close>&times;</button></div><form method="post" action="<?= app_url('/company/finance/employee-payments/client-receipt/create') ?>"><?= csrfField() ?><input type="hidden" name="employee_ref" value="<?= e($selectedRef) ?>"><div class="modal-body"><div class="field"><label class="field-label">Сотрудник</label><input class="field-input" value="<?= e($selectedEmployee['full_name']??'') ?>" readonly></div><div class="field"><label class="field-label">Счёт клиента <span class="field-required">*</span></label><select class="field-select" name="invoice_id" required><option value="">— Выберите счёт —</option><?php foreach($clientInvoices as $invoice): ?><option value="<?= (int)$invoice['id'] ?>">№<?= e((string)($invoice['number']??$invoice['id'])) ?> · <?= e((string)$invoice['counterparty_display_name']) ?> · остаток <?= e($fmt($invoice['remaining_amount']??'0')) ?> ₽</option><?php endforeach; ?></select></div><div class="form-grid two-cols"><div class="field"><label class="field-label">Дата <span class="field-required">*</span></label><input class="field-input" type="date" name="operation_date" value="<?= e(date('Y-m-d')) ?>" required></div><div class="field"><label class="field-label">Сумма <span class="field-required">*</span></label><input class="field-input" name="amount" inputmode="decimal" placeholder="0,00" required></div></div><div class="field"><label class="field-label">Комментарий</label><textarea class="field-input" name="comment" rows="3" placeholder="Необязательно"></textarea></div></div><div class="modal-foot"><button type="button" class="btn btn-secondary" data-client-receipt-close>Отмена</button><button type="submit" class="btn btn-primary">Провести</button></div></form></div></div>
<script>(function(){const modal=document.getElementById('employee-client-receipt-modal'),open=document.getElementById('employee-client-receipt-open');if(!modal||!open)return;const close=()=>modal.classList.remove('is-open');open.addEventListener('click',()=>modal.classList.add('is-open'));modal.querySelectorAll('[data-client-receipt-close]').forEach(b=>b.addEventListener('click',close));modal.addEventListener('click',e=>{if(e.target===modal)close();});})();</script>
<?php endif; ?>

<?php if($transferEnabled): ?>
<div id="employee-transfer-modal" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="employee-transfer-title"><div class="modal modal-md"><div class="modal-head"><span class="modal-title" id="employee-transfer-title">Передать деньги сотруднику</span><button type="button" class="modal-close" data-employee-transfer-close>&times;</button></div><form method="post" action="<?= app_url('/company/finance/employee-payments/transfer') ?>"><?= csrfField() ?><div class="modal-body"><div class="form-grid two-cols"><div class="field"><label class="field-label">От сотрудника <span class="field-required">*</span></label><select class="field-select" id="employee-transfer-source" name="source_employee_ref" required><option value="">— Выберите —</option><?php foreach($employees as $employee): ?><option value="<?= e($employee['ref']) ?>" <?= ($selectedRef??'')===$employee['ref']?'selected':'' ?>><?= e($employee['full_name']) ?></option><?php endforeach; ?></select></div><div class="field"><label class="field-label">Кому <span class="field-required">*</span></label><select class="field-select" id="employee-transfer-target" name="target_employee_ref" required><option value="">— Выберите —</option><?php foreach($employees as $employee): ?><option value="<?= e($employee['ref']) ?>"><?= e($employee['full_name']) ?></option><?php endforeach; ?></select></div></div><div class="form-grid two-cols"><div class="field"><label class="field-label">Дата <span class="field-required">*</span></label><input class="field-input" type="date" name="operation_date" value="<?= e(date('Y-m-d')) ?>" required></div><div class="field"><label class="field-label">Сумма <span class="field-required">*</span></label><input class="field-input" name="amount" inputmode="decimal" placeholder="0,00" required></div></div><div class="field"><label class="field-label">Основание</label><input class="field-input" name="purpose" placeholder="Например: передача средств"></div><div class="field"><label class="field-label">Комментарий</label><textarea class="field-input" name="comment" rows="3" placeholder="Необязательно"></textarea></div></div><div class="modal-foot"><button type="button" class="btn btn-secondary" data-employee-transfer-close>Отмена</button><button type="submit" class="btn btn-primary">Передать</button></div></form></div></div>
<script>(function(){const modal=document.getElementById('employee-transfer-modal'),open=document.getElementById('employee-transfer-open'),source=document.getElementById('employee-transfer-source'),target=document.getElementById('employee-transfer-target');if(!modal||!open||!source||!target)return;const sync=()=>{Array.from(target.options).forEach(o=>{if(o.value)o.disabled=o.value===source.value;});if(target.value===source.value)target.value='';};const close=()=>modal.classList.remove('is-open');open.addEventListener('click',()=>{sync();modal.classList.add('is-open');});source.addEventListener('change',sync);modal.querySelectorAll('[data-employee-transfer-close]').forEach(b=>b.addEventListener('click',close));modal.addEventListener('click',e=>{if(e.target===modal)close();});sync();})();</script>
<?php endif; ?>