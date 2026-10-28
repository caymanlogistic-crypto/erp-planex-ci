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
    $plan = $trip[$customer ? 'planned_receipt' : 'planned_carrier_payment'] ?? null;
    $planDate = is_array($plan) ? (string)($plan['date'] ?? '') : '';
    $confirmed = is_array($plan) && !empty($plan['due_date']) && ($plan['status'] ?? '') === 'ready';
    $dueState = $planDate === '' ? '' : (!$confirmed ? 'Ожидается событие' : ($remaining !== null && $remaining <= 0 ? 'Исполнено' : ($planDate < $todayMoscow ? $overdueText($planDate, $todayMoscow) : ($planDate === $todayMoscow ? 'Сегодня' : 'Впереди'))));
    $closing = !$customer && is_array($trip['closing_payment'] ?? null) ? $trip['closing_payment'] : null;
    $paymentStatus = $closing && ($remaining === null || $remaining > 0) ? (string)($closing['status'] ?? '') : '';
    $tnIssueGroups = [];
    if ($paymentStatus === 'incomplete') {
        foreach ($closing['tn_issues'] ?? [] as $issue) {
            if (preg_match('/^Заявка №(\d+):\s*(.+)$/u', (string)$issue, $parts)) {
                $tnIssueGroups[$parts[2]] = ($tnIssueGroups[$parts[2]] ?? 0) + 1;
            } else {
                $tnIssueGroups[(string)$issue] = ($tnIssueGroups[(string)$issue] ?? 0) + 1;
            }
        }
    }
    ?>
    <section class="registry-ui-modal-grid rp-lab-side" aria-labelledby="rp-side-<?= (int)$trip['business_id'] ?>-<?= $side ?>">
        <span class="page-eyebrow"><?= $customer ? 'Заказчик' : 'Перевозчик' ?></span>
        <h3 class="registry-ui-modal-summary" id="rp-side-<?= (int)$trip['business_id'] ?>-<?= $side ?>"><?= e($party !== '' ? $party : '—') ?></h3>
        <?php if (!$customer && trim((string)($trip['driver_full_name'] ?? '')) !== ''): ?><span class="registry-ui-modal-secondary">Водитель: <?= e((string)$trip['driver_full_name']) ?></span><?php endif; ?>
        <div class="registry-ui-modal-grid rp-lab-metrics">
            <div class="rp-lab-metric"><span class="registry-ui-modal-secondary">Ставка в рейсе</span><span class="registry-ui-modal-text"><?= $values['rate'] === null ? '—' : e($money($values['rate'])) ?></span></div>
            <div class="rp-lab-metric"><span class="registry-ui-modal-secondary"><?= $customer ? 'Получено' : 'Оплачено' ?></span><span class="registry-ui-modal-text"><?= e($money($values['paid'])) ?></span></div>
            <div class="rp-lab-metric"><span class="registry-ui-modal-secondary">Остаток</span><span class="registry-ui-modal-text"><?= $remaining === null ? 'нет ставки' : e($money($remaining)) ?></span></div>
        </div>
        <div class="rp-lab-metric"><span class="registry-ui-modal-secondary"><?= $customer ? 'Срок поступления от заказчика' : 'Срок оплаты подрядчику' ?></span><span class="registry-ui-modal-text"><?= e($date($planDate)) ?><?= $dueState !== '' ? ' · ' . e($dueState) : '' ?></span></div>
        <?php if ($paymentStatus === 'incomplete'): ?>
        <div class="registry-ui-modal-grid"><span class="page-eyebrow">Что блокирует оплату</span><span class="registry-ui-status registry-ui-error">оплата заблок.</span>
            <?php if (!empty($closing['missing'])): ?><div class="rp-lab-metric"><span class="registry-ui-modal-secondary">Нет документов</span><span class="registry-ui-modal-text"><?= e(implode(', ', $closing['missing'])) ?></span></div><?php endif; ?>
            <?php if ((int)$closing['total'] === 0): ?><div class="rp-lab-metric"><span class="registry-ui-modal-secondary">ТН</span><span class="registry-ui-modal-text">Заявки по рейсу не указаны</span></div>
            <?php elseif ((int)$closing['processed'] < (int)$closing['total']): ?><div class="rp-lab-metric"><span class="registry-ui-modal-secondary">ТН</span><span class="registry-ui-modal-text">Обработано <?= (int)$closing['processed'] ?> из <?= (int)$closing['total'] ?> заявок</span></div><?php endif; ?>
            <?php foreach ($tnIssueGroups as $reason => $count): ?><div class="rp-lab-metric"><span class="registry-ui-modal-secondary">Ошибка ТН</span><span class="registry-ui-modal-text"><?= e($reason) ?><?= $count > 0 ? ' · заявок: ' . (int)$count : '' ?></span></div><?php endforeach; ?>
        </div>
        <?php elseif ($paymentStatus === 'ready'): ?>
        <div class="registry-ui-modal-grid"><span class="page-eyebrow">Состояние оплаты</span><span class="registry-ui-secondary">ожидает оплаты</span><span class="registry-ui-modal-secondary">Обязательные документы собраны; заявки и ТН обработаны.</span></div>
        <?php endif; ?>
        <div class="registry-ui-modal-grid"><span class="page-eyebrow"><?= $customer ? 'Счета заказчику' : 'Счета перевозчика' ?></span>
            <?php if (!$block['invoices']): ?><span class="registry-ui-modal-secondary">Нет счёта</span><?php endif; ?>
            <?php foreach ($block['invoices'] as $invoice): ?><div class="rp-lab-record"><span class="rp-v014-invoice-identity"><button type="button" class="rp-invoice-link" data-view-invoice="<?= (int)$invoice['id'] ?>">№ <?= e((string)$invoice['number']) ?></button><small><?= $date($invoice['invoice_date']) === '—' ? 'Дата выставления неизвестна' : e($date($invoice['invoice_date'])) ?></small></span><strong class="registry-ui-modal-text"><?= e($money($moneyCents($invoice['amount']))) ?></strong></div><?php endforeach; ?>
            <?php if ($customer && !$block['invoices'] && $partyId > 0 && ($unbilled === null || $unbilled > 0)): ?><button type="button" class="btn btn-secondary rp-detail-create" data-create-direction="OUTGOING" data-create-party="<?= $partyId ?>" data-create-trip="<?= (int)$trip['business_id'] ?>">Создать счёт заказчику…</button><?php endif; ?>
        </div>
        <div class="registry-ui-modal-grid"><span class="page-eyebrow"><?= $customer ? 'Поступления' : 'Выплаты' ?></span>
            <?php if (!$block['payments']): ?><span class="registry-ui-modal-secondary">Нет <?= $customer ? 'поступлений' : 'выплат' ?></span><?php endif; ?>
            <?php foreach ($block['payments'] as $payment):
                $number = $invoiceNumbers[(int)($payment['invoice_id'] ?? 0)] ?? null; ?>
                <div class="rp-lab-record"><span class="registry-ui-modal-secondary"><?= e($date($payment['payment_date'])) ?> · <?= $number === null ? 'Без счёта' : 'по счёту № ' . e($number) ?></span><strong class="registry-ui-modal-text"><?= e($money($moneyCents($payment['amount']))) ?></strong></div>
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
    ?><template id="rp-detail-<?= $businessId ?>" data-title="<?= e($detailTitle) ?>"><div class="modal-body rp-lab-detail-body"><div class="rp-lab-trip-meta"><span class="registry-ui-modal-secondary">Груз сдан</span><span class="registry-ui-modal-secondary">Начало <?= e($startLabel) ?> · Окончание <?= e($endLabel) ?></span></div><div class="registry-ui-modal-grid registry-ui-modal-grid--reading rp-lab-sides"><?php $renderSide($trip, 'customer'); $renderSide($trip, 'carrier'); ?></div></div></template>
<?php endforeach; ?>
<div id="rp-detail-modal" class="modal-overlay" role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="rp-detail-title" data-invoice-base="<?= e(app_url('/company/finance/invoices/')) ?>" data-close-on-overlay="1" data-close-on-escape="1"><div class="modal modal-lg registry-ui-modal registry-ui-modal--reading registry-ui-modal--finance" open>
    <div class="modal-head"><span class="modal-title" id="rp-detail-title"></span><button type="button" class="modal-close" data-close-modal="rp-detail-modal" aria-label="Закрыть">&times;</button></div>
    <div id="rp-detail-content"></div><div class="modal-foot"><div class="modal-foot-actions"><button type="button" class="btn btn-ghost" data-close-modal="rp-detail-modal">Закрыть</button></div></div>
</div></div>
<div id="invoice-create-modal" class="modal-overlay" role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="invoice-create-modal-title" data-close-on-overlay="0" data-close-on-escape="0"><div class="modal modal-lg registry-ui-modal registry-ui-modal--finance" open>
    <div class="modal-head"><span class="modal-title" id="invoice-create-modal-title">Создать счёт</span><button type="button" class="modal-close" data-close-modal="invoice-create-modal" aria-label="Закрыть">&times;</button></div>
    <?php if ($localPdo !== null): $isEdit = false; $invoice = []; $returnTo = '/company/finance/received-paid';
        $clients = FinanceInvoiceService::fetchClientsForSelect($localPdo);
        $contractors = FinanceInvoiceService::fetchContractorsForSelect($localPdo);
        require base_path('app/View/partials/company_invoice_create_form.php');
    endif; ?>
</div></div>
<div id="invoice-view-modal" class="modal-overlay" role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="invoice-view-modal-title" data-close-on-overlay="1" data-close-on-escape="1"><div class="modal modal-lg registry-ui-modal registry-ui-modal--reading registry-ui-modal--finance" open><div class="modal-head"><span class="modal-title" id="invoice-view-modal-title">Счёт</span><button type="button" class="modal-close" data-close-modal="invoice-view-modal" aria-label="Закрыть">&times;</button></div><div class="modal-body" id="invoice-view-modal-body"></div></div></div>
<script src="<?= e(app_url('/assets/js/finance-received-paid.js')) ?>?v=<?= filemtime(base_path('public/assets/js/finance-received-paid.js')) ?>" defer></script>
<script src="<?= e(app_url('/assets/js/table-reference-select.js')) ?>?v=<?= filemtime(base_path('public/assets/js/table-reference-select.js')) ?>" defer></script>
<script src="<?= e(app_url('/assets/js/registry-components.js')) ?>?v=<?= filemtime(base_path('public/assets/js/registry-components.js')) ?>" defer></script>
