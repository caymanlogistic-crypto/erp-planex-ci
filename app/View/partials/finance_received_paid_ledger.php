<?php
$invoiceList = static function (array $invoices) use ($date): string {
    if (!$invoices) return '<span class="rp-v010-missing">—</span>';
    $html = '<ul class="rp-v010-doc-list">';
    foreach ($invoices as $invoice) {
        $number = '№ ' . e((string)$invoice['number']);
        $invoiceDate = $date($invoice['invoice_date'] ?? null);
        $html .= '<li><strong class="rp-v013-doc-number">' . $number . '</strong><small class="rp-v012-doc-date">'
            . ($invoiceDate === '—' ? 'Дата выставления неизвестна' : e($invoiceDate)) . '</small></li>';
    }
    return $html . '</ul>';
};
$invoiceAmount = static function (array $invoices) use ($moneyCents, $money): string {
    if (!$invoices) return '<span class="rp-v010-missing">—</span>';
    return '<span>' . e($money(array_sum(array_map(static fn(array $invoice): int => $moneyCents($invoice['amount']), $invoices)))) . '</span>';
};
$balance = static fn(array $side): ?int => $side['rate'] === null ? null : max(0, $side['rate'] - $side['paid']);
$due = static function (?string $value, ?int $remaining, bool $confirmed) use ($date, $todayMoscow, $overdueText): array {
    if (!$value) return ['none', '—', ''];
    $label = $date($value);
    if (!$confirmed) return ['future', $label, 'Ожидается событие'];
    if ($remaining !== null && $remaining <= 0) return ['closed', $label, 'Исполнено'];
    if ($value < $todayMoscow) return ['overdue', $label, $overdueText($value, $todayMoscow)];
    return $value === $todayMoscow ? ['today', $label, 'Сегодня'] : ['future', $label, 'Впереди'];
};
$dueHtml = static function (array $item): string {
    if ($item[0] === 'none') return '<span class="rp-v010-missing">—</span>';
    return '<span class="rp-v010-date is-' . e($item[0]) . '"><strong>' . e($item[1]) . '</strong><small>' . e($item[2]) . '</small></span>';
};
?>
<link rel="stylesheet" href="<?= e(app_url('/assets/css/erp-ledger-table.css')) ?>?v=<?= filemtime(base_path('public/assets/css/erp-ledger-table.css')) ?>">
<link rel="stylesheet" href="<?= e(app_url('/assets/css/registry-components.css')) ?>?v=<?= filemtime(base_path('public/assets/css/registry-components.css')) ?>">
<div class="rp-lab-header-wrap rp-action-design rp-ledger-v015">
    <div class="page-head registry-ui-head"><div class="page-head-left"><span class="rp-identity-icon" aria-hidden="true"><svg xmlns="http://www.w3.org/2000/svg" width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 10h14l-4 -4"/><path d="M17 14h-14l4 4"/></svg></span><span class="rp-head-text"><h1 class="page-title">Получено/оплачено</h1><span class="page-summary">Счета и фактические оплаты по рейсам ООО «Планэкс» со статусом «Груз сдан».</span></span></div>
        <div class="page-head-actions"><button type="button" class="btn btn-primary" data-open-modal="invoice-create-modal"<?= $localPdo === null ? ' disabled' : '' ?>>Создать счёт</button></div></div>
</div>
    <?php if ($dbError !== null): ?><div class="notice warn"><?= e($dbError) ?></div><?php endif; ?>
    <?php if (!empty($_SESSION['invoice_success'])): ?><div class="notice success"><?= e((string)$_SESSION['invoice_success']) ?></div><?php unset($_SESSION['invoice_success']); endif; ?>
    <nav class="rp-v010-quick registry-ui-tabs" id="rp-filters" aria-label="Отбор рейсов">
        <button type="button" data-rp-filter="all" aria-pressed="true">Все взаиморасчёты <b></b></button>
        <button type="button" data-rp-filter="unbilled" aria-pressed="false">Все к выставлению <b></b></button>
        <button type="button" data-rp-filter="client-due" aria-pressed="false">Срок поступления от заказчика наступил <b></b></button>
        <button type="button" data-rp-filter="carrier-due" aria-pressed="false">Срок оплаты подрядчику наступил <b></b></button>
        <button type="button" data-rp-filter="paid-unreceived" aria-pressed="false">Оплатили, не получили <b></b></button>
        <button type="button" data-rp-filter="received-unpaid" aria-pressed="false">Получили, не оплатили <b></b></button>
    </nav>
<div class="table-card table-card--standard rp-standard-box rp-action-design rp-ledger-v010 rp-ledger-v015 registry-ui-card">
    <div class="table-toolbar rp-v015-toolbar registry-ui-toolbar"><div class="found-label">Найдено: <b id="rp-count">0</b></div><div class="toolbar-right">
        <label class="rp-v014-hide-complete"><input type="checkbox" id="rp-hide-complete" checked><span>Скрыть завершённые</span></label>
        <label class="toolbar-sort"><span class="toolbar-sort-label">Сортировка по:</span><select class="field-select toolbar-select" id="rp-sort" aria-label="Сортировка по дате выгрузки или ID"><option value="date-new">Дата выгрузки</option><option value="id-new">ID</option></select></label>
        <input class="field-input registry-ui-search" id="rp-search" type="search" placeholder="Поиск" aria-label="Поиск по рейсам и счетам">
    </div></div>
    <div class="rp-v010-table-wrap"><table class="table rp-v010-table erp-ledger-table registry-ui-table registry-ui-table--finance" id="rp-ledger-table"><colgroup><?php foreach ([4,9,7,8,9,7,8,10,7,8,9,7,7] as $width): ?><col style="width:<?= $width ?>%"> <?php endforeach; ?></colgroup>
        <thead><tr class="rp-v012-wide-head"><th>ID</th><th>ОПИСАНИЕ РЕЙСА</th><th>СЧЕТ КЛИЕНТУ</th><th>СУММА СЧЕТА</th><th>ПОЛУЧИТЬ ДО</th><th>ПОЛУЧЕНО</th><th>К ПОЛУЧЕНИЮ</th><th>ПЕРЕВОЗЧИК</th><th>СЧЕТ ПОДРЯД.</th><th>СУММА СЧЕТА</th><th>ОПЛАТИТЬ ДО</th><th>ОПЛАЧЕНО</th><th>К ВЫПЛАТЕ</th></tr>
            <tr class="rp-v012-compact-head"><th>ID</th><th>ОПИСАНИЕ РЕЙСА / ПЕРЕВОЗЧИК</th><th>Счет</th><th>Сумма счета</th><th>Срок</th><th>Получено / оплачено</th><th>Остаток</th></tr></thead>
        <tbody id="rp-list">
        <?php foreach ($trips as $trip):
            $id = (int)$trip['business_id'];
            $description = trim((string)$trip['comment']) ?: 'Без описания';
            $end = $trip['actual_end_date'] ?: $trip['planned_finish_date'];
            $customer = $stats($trip, 'customer'); $carrier = $stats($trip, 'carrier');
            $customerBalance = $balance($customer); $carrierBalance = $balance($carrier);
            $plan = $trip['planned_receipt'] ?? null;
            $customerDue = $due(is_array($plan) ? ($plan['date'] ?? null) : null, $customerBalance, is_array($plan) && !empty($plan['due_date']) && ($plan['status'] ?? '') === 'ready');
            $carrierPlan = $trip['planned_carrier_payment'] ?? null;
            $carrierDue = $due(is_array($carrierPlan) ? ($carrierPlan['date'] ?? null) : null, $carrierBalance, is_array($carrierPlan) && !empty($carrierPlan['due_date']) && ($carrierPlan['status'] ?? '') === 'ready');
            $complete = $customer['rate'] !== null && $customer['rate'] > 0 && $carrier['rate'] !== null && $carrier['rate'] > 0 && $customerBalance === 0 && $carrierBalance === 0;
            $unbilled = !$trip['sides']['customer']['invoices'];
            $search = mb_strtolower(implode(' ', array_filter([$id, $description, $trip['client_name'], $trip['carrier_name'], $trip['driver_full_name'] ?? '', ...array_column($trip['sides']['customer']['invoices'], 'number'), ...array_column($trip['sides']['carrier']['invoices'], 'number')], static fn($v) => $v !== null && $v !== '')), 'UTF-8');
            $driver = trim((string)($trip['driver_full_name'] ?? ''));
        ?>
            <tr class="rp-v010-row" tabindex="0" data-id="<?= $id ?>" data-end="<?= e((string)$end) ?>" data-search="<?= e($search) ?>" data-complete="<?= $complete ? '1' : '0' ?>" data-unbilled="<?= $unbilled ? '1' : '0' ?>" data-client-due="<?= e($customerDue[0]) ?>" data-carrier-due="<?= e($carrierDue[0]) ?>" data-customer-rate="<?= $customer['rate'] === null ? '' : $customer['rate'] ?>" data-carrier-rate="<?= $carrier['rate'] === null ? '' : $carrier['rate'] ?>" data-customer-invoices="<?= $trip['sides']['customer']['invoices'] ? $customer['billed'] : '' ?>" data-carrier-invoices="<?= $trip['sides']['carrier']['invoices'] ? $carrier['billed'] : '' ?>" data-received="<?= $customer['paid'] ?>" data-paid="<?= $carrier['paid'] ?>" data-customer-balance="<?= $customerBalance === null ? '' : $customerBalance ?>" data-carrier-balance="<?= $carrierBalance === null ? '' : $carrierBalance ?>" aria-label="Рейс #<?= $id ?> · <?= e($description) ?>. Двойной щелчок или Enter — подробности">
                <td class="rp-v010-id" data-mobile-label="ID"><strong>#<?= $id ?></strong></td>
                <td class="rp-v010-trip" data-mobile-label="Описание рейса"><span title="<?= e($description) ?>"><?= e($description) ?></span></td>
                <td data-mobile-label="Счёт клиенту"><span class="rp-v010-mobile-group">Клиент</span><?= $invoiceList($trip['sides']['customer']['invoices']) ?></td>
                <td class="rp-v010-money" data-mobile-label="Сумма счёта"><?= $invoiceAmount($trip['sides']['customer']['invoices']) ?></td>
                <td data-mobile-label="Получить до"><?= $dueHtml($customerDue) ?></td>
                <td class="rp-v010-money" data-mobile-label="Получено"><span><?= e($money($customer['paid'])) ?></span></td>
                <td class="rp-v010-money" data-mobile-label="К получению"><?= $customerBalance === null ? '<span class="rp-v010-missing">нет ставки</span>' : '<span>' . e($money($customerBalance)) . '</span>' ?></td>
                <td class="rp-v010-carrier" data-mobile-label="Перевозчик"><span class="rp-v010-mobile-group">Подрядчик</span><span class="rp-v015-carrier-name" title="<?= e((string)$trip['carrier_name']) ?>" aria-label="<?= e((string)$trip['carrier_name']) ?>"><?= e(trim((string)$trip['carrier_name']) ?: '—') ?></span><?php if ($driver !== ''): ?><span class="rp-v015-driver" title="<?= e($driver) ?>" aria-label="Водитель: <?= e($driver) ?>"><?= e($driver) ?></span><?php endif; ?></td>
                <td data-mobile-label="Счёт подрядчика"><?= $invoiceList($trip['sides']['carrier']['invoices']) ?></td>
                <td class="rp-v010-money" data-mobile-label="Сумма счёта"><?= $invoiceAmount($trip['sides']['carrier']['invoices']) ?></td>
                <td data-mobile-label="Оплатить до"><?= $dueHtml($carrierDue) ?></td>
                <td class="rp-v010-money" data-mobile-label="Оплачено"><span><?= e($money($carrier['paid'])) ?></span></td>
                <td class="rp-v010-money" data-mobile-label="К выплате"><?= $carrierBalance === null ? '<span class="rp-v010-missing">нет ставки</span>' : '<span>' . e($money($carrierBalance)) . '</span>' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody><tfoot><tr><td>Итого · <b id="rp-total-count">0</b></td><td></td><td></td><td data-total="customer-invoices" data-mobile-label="Счета клиенту"><strong></strong></td><td></td><td data-total="received" data-mobile-label="Получено"><strong></strong></td><td data-total="customer-balance" data-mobile-label="К получению"><strong></strong></td><td></td><td></td><td data-total="carrier-invoices" data-mobile-label="Счета подрядчика"><strong></strong></td><td></td><td data-total="paid" data-mobile-label="Оплачено"><strong></strong></td><td data-total="carrier-balance" data-mobile-label="К выплате"><strong></strong></td></tr></tfoot>
    </table><div class="rp-v010-empty" id="rp-empty" hidden>По выбранным условиям рейсов нет.</div></div>
</div>
