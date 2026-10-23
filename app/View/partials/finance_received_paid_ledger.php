<?php
require_once base_path('app/View/components/registry_tabler_icons.php');

$invoiceList = static function (array $invoices) use ($date): string {
    if (!$invoices) return '<span class="rp-v010-missing registry-ui-missing">—</span>';
    $html = '<ul class="rp-v010-doc-list">';
    foreach ($invoices as $invoice) {
        $number = '№ ' . e((string)$invoice['number']);
        $invoiceDate = $date($invoice['invoice_date'] ?? null);
        $html .= '<li><strong class="rp-v013-doc-number registry-ui-primary">' . $number . '</strong><small class="rp-v012-doc-date registry-ui-secondary">'
            . ($invoiceDate === '—' ? 'Дата выставления неизвестна' : e($invoiceDate)) . '</small></li>';
    }
    return $html . '</ul>';
};
$invoiceAmount = static function (array $invoices) use ($moneyCents, $money): string {
    if (!$invoices) return '<span class="rp-v010-missing registry-ui-missing">—</span>';
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
    if ($item[0] === 'none') return '<span class="rp-v010-missing registry-ui-missing">—</span>';
    $state = ['closed' => 'complete', 'overdue' => 'overdue', 'today' => 'today'][$item[0]] ?? '';
    return '<span class="rp-v010-date registry-ui-due is-' . e($item[0]) . ($state ? ' registry-ui-due--' . $state : '') . '"><strong class="registry-ui-primary">' . e($item[1]) . '</strong><small class="registry-ui-secondary">' . e($item[2]) . '</small></span>';
};
require base_path('app/View/partials/registry_ui_state_scope.php');
?>
<link rel="stylesheet" href="<?= e(app_url('/assets/css/erp-ledger-table.css')) ?>?v=<?= filemtime(base_path('public/assets/css/erp-ledger-table.css')) ?>">
<link rel="stylesheet" href="<?= e(app_url('/assets/css/registry-components.css')) ?>?v=<?= filemtime(base_path('public/assets/css/registry-components.css')) ?>">
<section data-registry-id="finance-received-paid" data-registry-state-scope="<?= e($registryUiStateScope) ?>" data-registry-default-tab="all"><div class="rp-lab-header-wrap rp-action-design rp-ledger-v015">
    <?php
    $registryHeadTitle = 'Получено/оплачено';
    $registryHeadSummary = 'Счета и фактические оплаты по рейсам ООО «Планэкс» со статусом «Груз сдан».';
    $registryHeadIconHtml = registry_tabler_header_icon('arrows-exchange');
    $registryHeadActionsHtml = '<button type="button" class="btn btn-primary" data-open-modal="invoice-create-modal"' . ($localPdo === null ? ' disabled' : '') . '>Создать счёт</button>';
    $registryHeadExtraClass = '';
    require base_path('app/View/partials/registry_ui_head.php');
    ?>
</div>
    <?php if ($dbError !== null): ?><div class="notice warn"><?= e($dbError) ?></div><?php endif; ?>
    <?php if (!empty($_SESSION['invoice_success'])): ?><div class="notice success"><?= e((string)$_SESSION['invoice_success']) ?></div><?php unset($_SESSION['invoice_success']); endif; ?>
    <nav class="rp-v010-quick registry-ui-tabs" id="rp-filters" aria-label="Отбор рейсов">
        <button type="button" data-rp-filter="all" data-registry-tab="all" aria-pressed="true">Все взаиморасчёты <b></b></button>
        <button type="button" data-rp-filter="unbilled" data-registry-tab="unbilled" aria-pressed="false">Все к выставлению <b></b></button>
        <button type="button" data-rp-filter="client-due" data-registry-tab="client-due" aria-pressed="false">Срок поступления от заказчика наступил <b></b></button>
        <button type="button" data-rp-filter="carrier-due" data-registry-tab="carrier-due" aria-pressed="false">Срок оплаты подрядчику наступил <b></b></button>
        <button type="button" data-rp-filter="paid-unreceived" data-registry-tab="paid-unreceived" aria-pressed="false">Оплатили, не получили <b></b></button>
        <button type="button" data-rp-filter="received-unpaid" data-registry-tab="received-unpaid" aria-pressed="false">Получили, не оплатили <b></b></button>
    </nav>
<div class="table-card table-card--standard rp-standard-box rp-action-design rp-ledger-v010 rp-ledger-v015 registry-ui-card registry-ui-sticky-table registry-ui-sticky-table--finance registry-ui-scroll">
    <div class="table-toolbar rp-v015-toolbar registry-ui-toolbar"><div class="registry-ui-checkboxes"><div class="found-label">Найдено: <b id="rp-count">0</b></div><span class="filter-sep" aria-hidden="true"></span><label class="registry-ui-checkbox"><input type="checkbox" id="rp-hide-complete" data-registry-field="showComplete" data-registry-default="false"><span>Показать завершённые</span></label></div><div class="toolbar-right">
        <button type="button" class="registry-ui-reset" hidden>Сбросить фильтр</button>
        <label class="toolbar-sort"><select class="field-select toolbar-select" id="rp-sort" aria-label="Сортировка по дате выгрузки или ID" data-registry-field="sort" data-registry-default="date-new"><option value="date-new">Дата выгрузки</option><option value="id-new">ID</option></select></label>
        <div class="registry-ui-search-group"><input class="field-input registry-ui-search" id="rp-search" type="search" placeholder="Поиск" aria-label="Поиск по рейсам и счетам" data-registry-field="query" data-registry-default=""></div>
    </div></div>
    <div class="rp-v010-table-wrap"><table class="table rp-v010-table erp-ledger-table registry-ui-table registry-ui-data registry-ui-data--wide registry-ui-table--finance" id="rp-ledger-table" data-registry-cards-at="1050"><colgroup><?php for ($index = 0; $index < 13; $index++): ?><col<?= $index === 0 ? ' class="registry-ui-id"' : ($index === 1 ? ' class="registry-ui-trip-cell"' : '') ?>> <?php endfor; ?></colgroup>
        <thead><tr class="rp-v012-wide-head registry-ui-wide-head"><th class="registry-ui-id">ID</th><th class="registry-ui-trip-cell">ОПИСАНИЕ РЕЙСА</th><th>СЧЕТ КЛИЕНТУ</th><th>СУММА СЧЕТА</th><th>ПОЛУЧИТЬ ДО</th><th>ПОЛУЧЕНО</th><th>К ПОЛУЧЕНИЮ</th><th>ПЕРЕВОЗЧИК</th><th>СЧЕТ ПОДРЯД.</th><th>СУММА СЧЕТА</th><th>ОПЛАТИТЬ ДО</th><th>ОПЛАЧЕНО</th><th>К ВЫПЛАТЕ</th></tr>
            <tr class="rp-v012-compact-head registry-ui-compact-head"><th>ID</th><th>ОПИСАНИЕ РЕЙСА / ПЕРЕВОЗЧИК</th><th>Счет</th><th>Сумма счета</th><th>Срок</th><th>Получено / оплачено</th><th>Остаток</th></tr></thead>
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
                <td class="rp-v010-id registry-ui-id" data-mobile-label="ID" data-label="ID"><div class="registry-ui-value">#<?= $id ?></div></td>
                <td class="rp-v010-trip registry-ui-trip-cell" data-mobile-label="Описание рейса" data-label="Описание рейса"><div class="registry-ui-value"><span class="registry-ui-trip" data-registry-trip><?= e($description) ?></span></div></td>
                <td data-mobile-label="Счёт клиенту" data-label="Счёт клиенту"><div class="registry-ui-value"><span class="rp-v010-mobile-group">Клиент</span><?= $invoiceList($trip['sides']['customer']['invoices']) ?></div></td>
                <td class="rp-v010-money registry-ui-money" data-mobile-label="Сумма счёта" data-label="Сумма счёта"><div class="registry-ui-value"><?= $invoiceAmount($trip['sides']['customer']['invoices']) ?></div></td>
                <td data-mobile-label="Получить до" data-label="Получить до"><div class="registry-ui-value"><?= $dueHtml($customerDue) ?></div></td>
                <td class="rp-v010-money registry-ui-money" data-mobile-label="Получено" data-label="Получено"><div class="registry-ui-value"><?= e($money($customer['paid'])) ?></div></td>
                <td class="rp-v010-money<?= $customerBalance === null ? '' : ' registry-ui-money' ?>" data-mobile-label="К получению" data-label="К получению"><div class="registry-ui-value"><?= $customerBalance === null ? '<span class="rp-v010-missing registry-ui-reason">нет ставки</span>' : e($money($customerBalance)) ?></div></td>
                <td class="rp-v010-carrier" data-mobile-label="Перевозчик" data-label="Перевозчик"><div class="registry-ui-value"><span class="rp-v010-mobile-group registry-ui-secondary">Подрядчик</span><span class="rp-v015-carrier-name registry-ui-name"><?= e(trim((string)$trip['carrier_name']) ?: '—') ?></span><?php if ($driver !== ''): ?><span class="rp-v015-driver registry-ui-secondary"><?= e($driver) ?></span><?php endif; ?></div></td>
                <td data-mobile-label="Счёт подрядчика" data-label="Счёт подрядчика"><div class="registry-ui-value"><?= $invoiceList($trip['sides']['carrier']['invoices']) ?></div></td>
                <td class="rp-v010-money registry-ui-money" data-mobile-label="Сумма счёта" data-label="Сумма счёта"><div class="registry-ui-value"><?= $invoiceAmount($trip['sides']['carrier']['invoices']) ?></div></td>
                <td data-mobile-label="Оплатить до" data-label="Оплатить до"><div class="registry-ui-value"><?= $dueHtml($carrierDue) ?></div></td>
                <td class="rp-v010-money registry-ui-money" data-mobile-label="Оплачено" data-label="Оплачено"><div class="registry-ui-value"><?= e($money($carrier['paid'])) ?></div></td>
                <td class="rp-v010-money<?= $carrierBalance === null ? '' : ' registry-ui-money' ?>" data-mobile-label="К выплате" data-label="К выплате"><div class="registry-ui-value"><?= $carrierBalance === null ? '<span class="rp-v010-missing registry-ui-reason">нет ставки</span>' : e($money($carrierBalance)) ?></div></td>
            </tr>
        <?php endforeach; ?>
        </tbody><tfoot><tr><td>Итого · <b id="rp-total-count">0</b></td><td></td><td></td><td class="registry-ui-money" data-total="customer-invoices" data-registry-total="customer-invoices" data-mobile-label="Счета клиенту" data-label="Счета клиенту"><strong></strong></td><td></td><td class="registry-ui-money" data-total="received" data-registry-total="received" data-mobile-label="Получено" data-label="Получено"><strong></strong></td><td class="registry-ui-money" data-total="customer-balance" data-registry-total="customer-balance" data-mobile-label="К получению" data-label="К получению"><strong></strong></td><td></td><td></td><td class="registry-ui-money" data-total="carrier-invoices" data-registry-total="carrier-invoices" data-mobile-label="Счета подрядчика" data-label="Счета подрядчика"><strong></strong></td><td></td><td class="registry-ui-money" data-total="paid" data-registry-total="paid" data-mobile-label="Оплачено" data-label="Оплачено"><strong></strong></td><td class="registry-ui-money" data-total="carrier-balance" data-registry-total="carrier-balance" data-mobile-label="К выплате" data-label="К выплате"><strong></strong></td></tr></tfoot>
    </table><div class="rp-v010-empty" id="rp-empty" hidden>По выбранным условиям рейсов нет.</div></div>
</div></section>
