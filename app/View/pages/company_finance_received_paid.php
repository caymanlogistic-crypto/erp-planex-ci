<?php
use App\Service\FinanceInvoiceService;

$moneyCents = static function ($value): int {
    $value = trim((string)($value ?? '0'));
    if (!preg_match('/^-?\d+(?:\.\d{1,2})?$/D', $value)) return 0;
    $negative = str_starts_with($value, '-');
    if ($negative) $value = substr($value, 1);
    [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
    $cents = ((int)$whole * 100) + (int)str_pad($fraction, 2, '0');
    return $negative ? -$cents : $cents;
};
$money = static fn(int $cents): string => number_format($cents / 100, $cents % 100 === 0 ? 0 : 2, ',', ' ') . ' ₽';
$date = static function ($value): string {
    $value = trim((string)($value ?? ''));
    return preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) ? date('d.m.Y', strtotime($value)) : '—';
};
$todayMoscow = (new DateTimeImmutable('now', new DateTimeZone('Europe/Moscow')))->format('Y-m-d');
$overdueText = static function (string $value, string $today): string {
    $moscow = new DateTimeZone('Europe/Moscow');
    $days = (new DateTimeImmutable($value, $moscow))->diff(new DateTimeImmutable($today, $moscow))->days;
    $word = ($days % 100 >= 11 && $days % 100 <= 14) ? 'дней' : (($days % 10 === 1) ? 'день' : (($days % 10 >= 2 && $days % 10 <= 4) ? 'дня' : 'дней'));
    return 'Просрочено на ' . $days . ' ' . $word;
};
$sideTotal = static fn(array $items): int => array_sum(array_map(static fn(array $item): int => $moneyCents($item['amount'] ?? 0), $items));
$stats = static function (array $trip, string $side) use ($sideTotal, $moneyCents): array {
    $block = $trip['sides'][$side];
    return [
        'rate' => $block['rate'] === null ? null : $moneyCents($block['rate']),
        'billed' => $sideTotal($block['invoices']),
        'paid' => $sideTotal($block['payments']),
    ];
};
$renderSide = static function (array $trip, string $side) use ($stats, $money, $moneyCents, $date, $todayMoscow, $overdueText): void {
    $customer = $side === 'customer';
    $block = $trip['sides'][$side];
    $values = $stats($trip, $side);
    $party = trim((string)($customer ? $trip['client_name'] : $trip['carrier_name']));
    $partyId = (int)($customer ? $trip['client_id'] : $trip['carrier_id']);
    $unbilled = $values['rate'] === null ? null : max(0, $values['rate'] - $values['billed']);
    $remaining = $values['rate'] === null ? null : max(0, $values['rate'] - $values['paid']);
    $invoiceNumbers = [];
    foreach ($block['invoices'] as $invoice) $invoiceNumbers[(int)$invoice['id']] = (string)$invoice['number'];
    ?>
    <section class="rp-finance-pane" aria-labelledby="rp-side-<?= (int)$trip['business_id'] ?>-<?= $side ?>">
        <div class="rp-finance-head"><span><?= $customer ? 'Заказчик' : 'Перевозчик' ?></span><h3 id="rp-side-<?= (int)$trip['business_id'] ?>-<?= $side ?>"><?= e($party !== '' ? $party : '—') ?></h3><?php if (!$customer && trim((string)($trip['driver_full_name'] ?? '')) !== ''): ?><div class="rp-v015-detail-driver" title="<?= e((string)$trip['driver_full_name']) ?>" aria-label="Водитель: <?= e((string)$trip['driver_full_name']) ?>">Водитель: <?= e((string)$trip['driver_full_name']) ?></div><?php endif; ?></div>
        <dl class="rp-finance-metrics"><div><dt>Ставка в рейсе</dt><dd><?= $values['rate'] === null ? '—' : e($money($values['rate'])) ?></dd></div><div><dt><?= $customer ? 'Получено' : 'Оплачено' ?></dt><dd><?= e($money($values['paid'])) ?></dd></div><div><dt>Остаток</dt><dd><?= $remaining === null ? 'нет ставки' : e($money($remaining)) ?></dd></div></dl>
        <?php $plan = $trip[$customer ? 'planned_receipt' : 'planned_carrier_payment'] ?? null; $planDate = is_array($plan) ? (string)($plan['date'] ?? '') : ''; $confirmed = is_array($plan) && !empty($plan['due_date']) && ($plan['status'] ?? '') === 'ready'; ?>
        <div class="rp-v011-detail-due"><span><?= $customer ? 'Срок поступления от заказчика' : 'Срок оплаты подрядчику' ?></span><span class="rp-v010-date"><strong><?= e($date($planDate)) ?></strong><?php if ($planDate !== ''): ?><small><?= e(!$confirmed ? 'Ожидается событие' : ($remaining !== null && $remaining <= 0 ? 'Исполнено' : ($planDate < $todayMoscow ? $overdueText($planDate, $todayMoscow) : ($planDate === $todayMoscow ? 'Сегодня' : 'Впереди')))) ?></small><?php endif; ?></span></div>
        <div class="rp-finance-section">
            <?php if (!$block['invoices']): ?><p class="rp-detail-empty">Нет счёта</p><?php else: ?><h4><?= $customer ? 'Счета заказчику' : 'Счета перевозчика' ?></h4><?php endif; ?>
            <?php foreach ($block['invoices'] as $invoice): ?><div class="rp-detail-line rp-v014-detail-invoice"><span class="rp-v014-invoice-identity"><button type="button" class="rp-invoice-link" data-view-invoice="<?= (int)$invoice['id'] ?>">№ <?= e((string)$invoice['number']) ?></button><small><?= $date($invoice['invoice_date']) === '—' ? 'Дата выставления неизвестна' : e($date($invoice['invoice_date'])) ?></small></span><strong><?= e($money($moneyCents($invoice['amount']))) ?></strong></div><?php endforeach; ?>
            <?php if ($customer && !$block['invoices'] && $partyId > 0 && ($unbilled === null || $unbilled > 0)): ?><button type="button" class="btn btn-secondary rp-detail-create" data-create-direction="OUTGOING" data-create-party="<?= $partyId ?>" data-create-trip="<?= (int)$trip['business_id'] ?>">Создать счёт заказчику…</button><?php endif; ?>
        </div>
        <div class="rp-finance-section">
            <?php if (!$block['payments']): ?><p class="rp-detail-empty">Нет <?= $customer ? 'поступлений' : 'выплат' ?></p><?php else: ?><h4><?= $customer ? 'Поступления' : 'Выплаты' ?></h4><?php endif; ?>
            <?php foreach ($block['payments'] as $payment):
                $number = $invoiceNumbers[(int)($payment['invoice_id'] ?? 0)] ?? null; ?>
                <div class="rp-detail-line"><span><?= e($date($payment['payment_date'])) ?></span><span><?= $number === null ? 'Без счёта' : 'по счёту № ' . e($number) ?></span><strong><?= e($money($moneyCents($payment['amount']))) ?></strong></div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php
};
?>
<?php require base_path('app/View/partials/finance_received_paid_ledger.php'); ?>

<?php foreach ($trips as $trip):
    $businessId = (int)$trip['business_id'];
    $start = $trip['actual_start_date'] ?: ($trip['planned_start_date'] ?: $trip['planned_start_date_from']);
    $end = $trip['actual_end_date'] ?: $trip['planned_finish_date'];
    $startLabel = $date($start);
    if (!$trip['actual_start_date'] && !$trip['planned_start_date'] && $trip['planned_start_date_from']) $startLabel = 'с ' . $startLabel;
    $endLabel = $date($end);
    $detailTitle = 'Рейс #' . $businessId . ' · ' . (trim((string)$trip['comment']) ?: 'Без описания');
    ?><template id="rp-detail-<?= $businessId ?>" data-title="<?= e($detailTitle) ?>"><div class="modal-body rp-modal-body rp-details-body"><div class="rp-details-meta"><span>Груз сдан</span><span>Начало <?= e($startLabel) ?> · Окончание <?= e($endLabel) ?></span></div><div class="rp-details-sides"><?php $renderSide($trip, 'customer'); $renderSide($trip, 'carrier'); ?></div></div></template>
<?php endforeach; ?>
<div id="rp-detail-modal" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="rp-detail-title" data-invoice-base="<?= e(app_url('/company/finance/invoices/')) ?>" data-close-on-overlay="1" data-close-on-escape="1"><div class="modal modal-lg">
    <div class="modal-head"><span class="modal-title" id="rp-detail-title"></span><button type="button" class="modal-close" data-close-modal="rp-detail-modal" aria-label="Закрыть">&times;</button></div>
    <div id="rp-detail-content"></div><div class="modal-foot is-spaced"><span></span><button type="button" class="btn btn-ghost" data-close-modal="rp-detail-modal">Закрыть</button></div>
</div></div>
<div id="invoice-create-modal" class="modal-overlay" role="dialog" aria-modal="true" data-close-on-overlay="0" data-close-on-escape="0"><div class="modal modal-lg">
    <div class="modal-head"><span class="modal-title">Создать счёт</span><button type="button" class="modal-close" data-close-modal="invoice-create-modal">&times;</button></div>
    <?php if ($localPdo !== null): $isEdit = false; $invoice = []; $returnTo = '/company/finance/received-paid';
        $clients = FinanceInvoiceService::fetchClientsForSelect($localPdo);
        $contractors = FinanceInvoiceService::fetchContractorsForSelect($localPdo);
        require base_path('app/View/partials/company_invoice_create_form.php');
    endif; ?>
</div></div>
<div id="invoice-view-modal" class="modal-overlay" role="dialog" aria-modal="true" data-close-on-overlay="1" data-close-on-escape="1"><div class="modal modal-lg"><div class="modal-head"><span class="modal-title">Счёт</span><button type="button" class="modal-close" data-close-modal="invoice-view-modal">&times;</button></div><div class="modal-body" id="invoice-view-modal-body"></div></div></div>
<script src="<?= e(app_url('/assets/js/finance-received-paid.js')) ?>?v=<?= filemtime(base_path('public/assets/js/finance-received-paid.js')) ?>" defer></script>
<script src="<?= e(app_url('/assets/js/table-reference-select.js')) ?>?v=<?= filemtime(base_path('public/assets/js/table-reference-select.js')) ?>" defer></script>
