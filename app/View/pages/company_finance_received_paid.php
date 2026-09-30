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
$money = static function (int $cents): string {
    return number_format($cents / 100, $cents % 100 === 0 ? 0 : 2, ',', ' ') . ' ₽';
};
$date = static function ($value): string {
    $value = trim((string)($value ?? ''));
    return preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) ? date('d.m.Y', strtotime($value)) : '—';
};
$sideTotal = static function (array $items) use ($moneyCents): int {
    return array_sum(array_map(static fn(array $item): int => $moneyCents($item['amount'] ?? 0), $items));
};
$tripStatus = static function (array $trip) use ($sideTotal, $moneyCents): string {
    foreach (['customer', 'carrier'] as $side) {
        $block = $trip['sides'][$side];
        $rate = $block['rate'] !== null ? $moneyCents($block['rate']) : null;
        if ($rate === null || $rate <= 0 || $sideTotal($block['invoices']) < $rate) return 'no-invoice';
    }
    foreach (['customer', 'carrier'] as $side) {
        $block = $trip['sides'][$side];
        $invoiceIds = array_flip(array_map(static fn(array $invoice): int => (int)$invoice['id'], $block['invoices']));
        $invoicePayments = array_values(array_filter($block['payments'], static fn(array $payment): bool => isset($invoiceIds[(int)($payment['invoice_id'] ?? 0)])));
        if ($sideTotal($invoicePayments) < $moneyCents($block['rate'])) return 'in-progress';
    }
    return 'closed';
};
$renderSide = static function (array $trip, string $side) use ($money, $moneyCents, $date, $sideTotal): void {
    $block = $trip['sides'][$side];
    $customer = $side === 'customer';
    $party = (string)($customer ? $trip['client_name'] : $trip['carrier_name']);
    $partyId = (int)($customer ? $trip['client_id'] : $trip['carrier_id']);
    $rate = $block['rate'] === null ? null : $moneyCents($block['rate']);
    $billed = $sideTotal($block['invoices']);
    $paid = $sideTotal($block['payments']);
    $byInvoice = [];
    foreach ($block['payments'] as $payment) {
        $invoiceId = (int)($payment['invoice_id'] ?? 0);
        $byInvoice[$invoiceId] = ($byInvoice[$invoiceId] ?? 0) + $moneyCents($payment['amount']);
    }
    $invoiceIds = array_flip(array_map(static fn(array $invoice): int => (int)$invoice['id'], $block['invoices']));
    $invoicePaid = array_sum(array_intersect_key($byInvoice, $invoiceIds));
    $label = !$block['invoices'] ? 'Счёта нет' : (($billed > 0 && $invoicePaid >= $billed && ($rate === null || $billed >= $rate))
        ? ($customer ? 'Получено полностью' : 'Оплачено полностью')
        : ($invoicePaid > 0 ? ($customer ? 'Получено частично' : 'Оплачено частично') : 'Ожидает оплаты'));
    $badge = !$block['invoices'] ? 'neutral' : (($billed > 0 && $invoicePaid >= $billed && ($rate === null || $billed >= $rate)) ? 'ok' : ($invoicePaid > 0 ? 'warning' : 'danger'));
    ?>
    <section class="rp-side-card" aria-label="<?= $customer ? 'Заказчик' : 'Перевозчик' ?>">
        <div class="rp-side-head"><div class="rp-side-party"><span class="rp-side-kicker"><?= $customer ? 'Заказчик' : 'Перевозчик' ?></span><h3><?= e($party !== '' ? $party : '—') ?></h3></div>
            <div class="rp-side-rate"><span>Ставка в рейсе</span><strong><?= $rate === null ? '—' : e($money($rate)) ?></strong></div></div>
        <div class="rp-side-details">
            <div class="rp-side-detail"><h4><?= $customer ? 'Счета заказчику' : 'Счета от перевозчика' ?></h4><div class="rp-invoice-list">
                <?php if (!$block['invoices']): ?><span class="rp-empty">—</span><?php endif; ?>
                <?php foreach ($block['invoices'] as $invoice): ?>
                    <button type="button" class="rp-invoice-link" data-view-invoice="<?= (int)$invoice['id'] ?>">№ <?= e((string)$invoice['number']) ?></button>
                    <span class="rp-invoice-meta"><?= e($date($invoice['invoice_date'])) ?> · <?= e($money($moneyCents($invoice['amount']))) ?></span>
                <?php endforeach; ?>
                <span class="badge badge-<?= $badge ?>"><span class="dot"></span><?= e($label) ?></span>
                <?php if ($rate !== null && $rate > $billed): ?><span class="rp-unbilled"><?= $customer ? 'К выставлению' : 'Ожидается счёт' ?>: <?= e($money($rate - $billed)) ?></span><?php endif; ?>
                <?php if ($partyId > 0 && ($rate === null || $rate > $billed)): ?><button type="button" class="rp-add-link" data-create-direction="<?= $customer ? 'OUTGOING' : 'INCOMING' ?>" data-create-party="<?= $partyId ?>" data-create-trip="<?= (int)$trip['business_id'] ?>">+ Добавить счёт</button><?php endif; ?>
            </div></div>
            <div class="rp-side-detail"><h4><?= $customer ? 'Получено от заказчика' : 'Оплачено перевозчику' ?></h4><div class="rp-payment-list">
                <?php if (!$block['payments']): ?><span class="rp-empty">Оплат нет</span><?php endif; ?>
                <?php foreach ($block['payments'] as $payment): ?>
                    <div class="rp-payment-line"><span><?= e($date($payment['payment_date'])) ?></span><strong><?= e($money($moneyCents($payment['amount']))) ?></strong></div>
                <?php endforeach; ?>
                <?php if ($block['invoices']): ?><div class="rp-payment-total"><span><?= $customer ? 'Получено' : 'Оплачено' ?>: <?= e($money($paid)) ?></span><span>По счетам осталось: <?= e($money(max(0, $billed - $invoicePaid))) ?></span></div><?php endif; ?>
            </div></div>
        </div>
    </section>
    <?php
};
?>
<div class="table-card table-card--standard rp-standard-box">
    <div class="page-head"><div class="page-head-left"><h1 class="page-title">Получено/оплачено</h1><div class="page-summary"><span>Счета и фактические оплаты по рейсам ООО «Планэкс» со статусом «Груз сдан».</span></div></div>
        <div class="page-head-actions"><button type="button" class="btn btn-primary" data-open-modal="invoice-create-modal"<?= $localPdo === null ? ' disabled' : '' ?>>Создать счёт</button></div></div>
    <?php if ($dbError !== null): ?><div class="notice warn"><?= e($dbError) ?></div><?php endif; ?>
    <?php if (!empty($_SESSION['invoice_success'])): ?><div class="notice success"><?= e((string)$_SESSION['invoice_success']) ?></div><?php unset($_SESSION['invoice_success']); endif; ?>
    <div class="rp-filter-panel"><label class="rp-filter-field"><span>Состояние</span><select class="field-select" id="rp-filter"><option value="all">Все рейсы</option><option value="no-invoice">Не все счета выставлены</option><option value="in-progress">Оплата в процессе</option><option value="closed">Расчёты закрыты</option></select></label>
        <label class="rp-filter-field rp-search"><span>Поиск</span><input class="field-input" id="rp-search" placeholder="ID, описание, контрагент или счёт"></label>
        <label class="rp-filter-field"><span>Сортировка</span><select class="field-select" id="rp-sort"><option value="new">По ID (сначала новые)</option><option value="old">По ID (сначала старые)</option></select></label></div>
    <div class="table-toolbar"><div class="found-label">Найдено: <b id="rp-count"><?= count($trips) ?></b></div><div class="rp-toolbar-note">Только рейсы ООО «Планэкс» · груз сдан</div></div>
    <div class="rp-trip-list" id="rp-list">
        <?php foreach ($trips as $trip):
            $start = $trip['actual_start_date'] ?: ($trip['planned_start_date'] ?: $trip['planned_start_date_from']);
            $end = $trip['actual_end_date'] ?: $trip['planned_finish_date'];
            $search = '#' . $trip['business_id'] . ' ' . $trip['comment'] . ' ' . $trip['client_name'] . ' ' . $trip['carrier_name'] . ' ' . implode(' ', array_map(static fn(array $i): string => (string)$i['number'], array_merge($trip['sides']['customer']['invoices'], $trip['sides']['carrier']['invoices'])));
            ?>
        <article class="rp-trip-item" data-id="<?= (int)$trip['business_id'] ?>" data-status="<?= e($tripStatus($trip)) ?>" data-search="<?= e(mb_strtolower($search, 'UTF-8')) ?>">
            <div class="rp-trip-head"><div class="rp-trip-name"><span class="rp-trip-id">#<?= (int)$trip['business_id'] ?></span><h2><?= e(trim((string)$trip['comment']) ?: 'Без описания') ?></h2></div>
                <div class="rp-trip-dates"><div><span>Начало рейса</span><strong><?= e($date($start)) ?></strong></div><div><span>Окончание рейса</span><strong><?= e($date($end)) ?></strong></div></div></div>
            <div class="rp-trip-sides"><?php $renderSide($trip, 'customer'); $renderSide($trip, 'carrier'); ?></div>
        </article>
        <?php endforeach; ?>
        <div class="rp-empty-state" id="rp-empty"<?= $trips ? ' hidden' : '' ?>>По выбранным условиям рейсов нет.</div>
    </div>
</div>

<div id="invoice-create-modal" class="modal-overlay" role="dialog" aria-modal="true" data-close-on-overlay="0" data-close-on-escape="0"><div class="modal modal-lg">
    <div class="modal-head"><span class="modal-title">Создать счёт</span><button type="button" class="modal-close" data-close-modal="invoice-create-modal">&times;</button></div>
    <?php if ($localPdo !== null): $isEdit = false; $invoice = []; $returnTo = '/company/finance/received-paid';
        $clients = FinanceInvoiceService::fetchClientsForSelect($localPdo);
        $contractors = FinanceInvoiceService::fetchContractorsForSelect($localPdo);
        require base_path('app/View/partials/company_invoice_create_form.php');
    endif; ?>
</div></div>
<div id="invoice-view-modal" class="modal-overlay" role="dialog" aria-modal="true" data-close-on-overlay="1" data-close-on-escape="1"><div class="modal modal-lg"><div class="modal-head"><span class="modal-title">Счёт</span><button type="button" class="modal-close" data-close-modal="invoice-view-modal">&times;</button></div><div class="modal-body" id="invoice-view-modal-body"></div></div></div>
<script>
(function(){
  const items=[...document.querySelectorAll('.rp-trip-item')], filter=document.getElementById('rp-filter'), search=document.getElementById('rp-search'), sort=document.getElementById('rp-sort'), list=document.getElementById('rp-list');
  function render(){const q=search.value.trim().toLocaleLowerCase('ru');let visible=0;items.sort((a,b)=>sort.value==='old'?Number(a.dataset.id)-Number(b.dataset.id):Number(b.dataset.id)-Number(a.dataset.id)).forEach(item=>{item.hidden=(filter.value!=='all'&&item.dataset.status!==filter.value)||(q&&!item.dataset.search.includes(q));if(!item.hidden)visible++;list.insertBefore(item,document.getElementById('rp-empty'));});document.getElementById('rp-count').textContent=visible;document.getElementById('rp-empty').hidden=visible>0;}
  [filter,sort].forEach(el=>el.addEventListener('change',render));search.addEventListener('input',render);
  document.querySelector('[data-open-modal="invoice-create-modal"]')?.addEventListener('click',()=>{const form=document.querySelector('#invoice-create-modal form.invoice-form');if(form)delete form.dataset.preferredTripId;});
  document.querySelectorAll('[data-create-trip]').forEach(button=>button.addEventListener('click',()=>{const form=document.querySelector('#invoice-create-modal form.invoice-form');form.dataset.preferredTripId=button.dataset.createTrip;const direction=form.querySelector('.js-inv-direction');direction.value=button.dataset.createDirection;direction.dispatchEvent(new Event('change',{bubbles:true}));const party=form.querySelector('.js-inv-cparty-select');party.value=button.dataset.createParty;party.dispatchEvent(new Event('change',{bubbles:true}));window.openModal('invoice-create-modal');}));
  document.querySelectorAll('[data-view-invoice]').forEach(button=>button.addEventListener('click',()=>{const body=document.getElementById('invoice-view-modal-body');body.textContent='Загрузка…';window.openModal('invoice-view-modal');fetch(<?= json_encode(app_url('/company/finance/invoices/'), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>+encodeURIComponent(button.dataset.viewInvoice)+'/modal-view').then(response=>{if(!response.ok)throw new Error('Счёт недоступен');return response.text()}).then(html=>body.innerHTML=html).catch(error=>body.textContent=error.message);}));
})();
</script>
