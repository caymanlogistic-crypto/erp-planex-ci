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
$sideTotal = static fn(array $items): int => array_sum(array_map(static fn(array $item): int => $moneyCents($item['amount'] ?? 0), $items));
$stats = static function (array $trip, string $side) use ($sideTotal, $moneyCents): array {
    $block = $trip['sides'][$side];
    return [
        'rate' => $block['rate'] === null ? null : $moneyCents($block['rate']),
        'billed' => $sideTotal($block['invoices']),
        'paid' => $sideTotal($block['payments']),
    ];
};
$tripStatus = static function (array $trip) use ($stats, $sideTotal, $moneyCents): string {
    foreach (['customer', 'carrier'] as $side) {
        $values = $stats($trip, $side);
        if ($values['rate'] === null || $values['rate'] <= 0 || $values['billed'] < $values['rate']) return 'no-invoice';
    }
    foreach (['customer', 'carrier'] as $side) {
        $block = $trip['sides'][$side];
        $invoiceIds = array_flip(array_map(static fn(array $invoice): int => (int)$invoice['id'], $block['invoices']));
        $invoicePayments = array_values(array_filter($block['payments'], static fn(array $payment): bool => isset($invoiceIds[(int)($payment['invoice_id'] ?? 0)])));
        if ($sideTotal($invoicePayments) < $moneyCents($block['rate'])) return 'in-progress';
    }
    return 'closed';
};
$paymentStatus = static function (array $trip) use ($stats): string {
    $customer = $stats($trip, 'customer');
    $carrier = $stats($trip, 'carrier');
    if ($customer['paid'] <= 0 && $carrier['paid'] <= 0) return 'unpaid';
    if (($customer['rate'] !== null && $customer['paid'] < $customer['rate'])
        || ($carrier['rate'] !== null && $carrier['paid'] < $carrier['rate'])) return 'underpaid';
    if ($customer['rate'] !== null && $carrier['rate'] !== null
        && ($customer['rate'] > 0 || $carrier['rate'] > 0)) return 'paid';
    return 'unknown-rate';
};
$renderSide = static function (array $trip, string $side) use ($stats, $money, $moneyCents, $date): void {
    $customer = $side === 'customer';
    $block = $trip['sides'][$side];
    $values = $stats($trip, $side);
    $party = trim((string)($customer ? $trip['client_name'] : $trip['carrier_name']));
    $partyId = (int)($customer ? $trip['client_id'] : $trip['carrier_id']);
    $unbilled = $values['rate'] === null ? null : max(0, $values['rate'] - $values['billed']);
    $invoiceNumbers = [];
    $invoicePaid = 0;
    foreach ($block['invoices'] as $invoice) $invoiceNumbers[(int)$invoice['id']] = (string)$invoice['number'];
    foreach ($block['payments'] as $payment) {
        if (isset($invoiceNumbers[(int)($payment['invoice_id'] ?? 0)])) $invoicePaid += $moneyCents($payment['amount']);
    }
    ?>
    <section class="rp-finance-pane" aria-labelledby="rp-side-<?= (int)$trip['business_id'] ?>-<?= $side ?>">
        <div class="rp-finance-head"><span><?= $customer ? 'Заказчик' : 'Перевозчик' ?></span><h3 id="rp-side-<?= (int)$trip['business_id'] ?>-<?= $side ?>"><?= e($party !== '' ? $party : '—') ?></h3></div>
        <dl class="rp-finance-metrics"><div><dt>Ставка в рейсе</dt><dd><?= $values['rate'] === null ? '—' : e($money($values['rate'])) ?></dd></div><div><dt><?= $customer ? 'Выставлено' : 'Получено счетов' ?></dt><dd><?= e($money($values['billed'])) ?></dd></div><div><dt><?= $customer ? 'Получено' : 'Оплачено' ?></dt><dd><?= e($money($values['paid'])) ?></dd></div></dl>
        <div class="rp-finance-section"><div class="rp-finance-section-head"><h4><?= $customer ? 'Счета заказчику' : 'Счета перевозчика' ?></h4>
            <?php if ($partyId > 0 && ($values['rate'] === null || $values['rate'] > $values['billed'])): ?><button type="button" class="rp-add-link" data-create-direction="<?= $customer ? 'OUTGOING' : 'INCOMING' ?>" data-create-party="<?= $partyId ?>" data-create-trip="<?= (int)$trip['business_id'] ?>">+ Добавить счёт</button><?php endif; ?></div>
            <?php if (!$block['invoices']): ?><p class="rp-detail-empty">Счёта пока нет</p><?php endif; ?>
            <?php foreach ($block['invoices'] as $invoice): ?><div class="rp-detail-line"><button type="button" class="rp-invoice-link" data-view-invoice="<?= (int)$invoice['id'] ?>">№ <?= e((string)$invoice['number']) ?></button><span><?= e($date($invoice['invoice_date'])) ?></span><strong><?= e($money($moneyCents($invoice['amount']))) ?></strong></div><?php endforeach; ?>
        </div>
        <div class="rp-finance-section"><h4><?= $customer ? 'Поступления' : 'Выплаты' ?></h4>
            <?php if (!$block['payments']): ?><p class="rp-detail-empty">Оплат пока нет</p><?php endif; ?>
            <?php foreach ($block['payments'] as $payment):
                $number = $invoiceNumbers[(int)($payment['invoice_id'] ?? 0)] ?? null; ?>
                <div class="rp-detail-line"><span><?= e($date($payment['payment_date'])) ?></span><span><?= $number === null ? 'Без счёта' : 'по счёту № ' . e($number) ?></span><strong><?= e($money($moneyCents($payment['amount']))) ?></strong></div>
            <?php endforeach; ?>
        </div>
        <div class="rp-finance-balance">
            <?php if ($unbilled === null): ?><span>Ставка не указана</span><?php elseif ($unbilled > 0): ?><span><?= $customer ? 'К выставлению' : 'Ожидается счёт' ?>: <strong><?= e($money($unbilled)) ?></strong></span><?php else: ?><span>Счета покрывают ставку</span><?php endif; ?>
            <?php if ($block['invoices']): ?><span>По счетам осталось: <strong><?= e($money(max(0, $values['billed'] - $invoicePaid))) ?></strong></span><?php endif; ?>
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
        <label class="rp-filter-field rp-payment-filter"><span>Оплаты по рейсу</span><select class="field-select" id="rp-payment-filter" title="Фактические оплаты сравниваются со ставками заказчика и перевозчика"><option value="all">Все оплаты</option><option value="paid">Обе стороны оплачены полностью</option><option value="underpaid">Есть недоплата</option><option value="unpaid">Нет оплат с обеих сторон</option><option value="unknown-rate">Не хватает ставки для сравнения</option></select></label>
        <label class="rp-filter-field rp-search"><span>Поиск</span><input class="field-input" id="rp-search" placeholder="ID, описание, контрагент или счёт"></label>
        <label class="rp-filter-field"><span>Сортировка</span><select class="field-select" id="rp-sort"><option value="new">По ID (сначала новые)</option><option value="old">По ID (сначала старые)</option></select></label></div>
    <div class="table-toolbar"><div class="found-label">Найдено: <b id="rp-count"><?= count($trips) ?></b></div><div class="rp-toolbar-note"><span class="rp-hint-desktop">Двойной щелчок по строке — подробности</span><span class="rp-hint-mobile">Нажмите на строку — подробности</span></div></div>
    <div class="rp-table-wrap"><table class="rp-compact-table"><colgroup><col class="rp-w-id"><col class="rp-w-desc"><col class="rp-w-date"><col class="rp-w-date"><col class="rp-w-party"><col class="rp-w-rate"><col class="rp-w-payment-date"><col class="rp-w-invoice"><col class="rp-w-paid"><col class="rp-w-party"><col class="rp-w-rate"><col class="rp-w-invoice"><col class="rp-w-paid"></colgroup>
        <thead><tr><th scope="col">ID</th><th scope="col">Описание рейса</th><th scope="col">Начало</th><th scope="col">Окончание</th><th scope="col">Заказчик</th><th scope="col">Ставка</th><th scope="col" class="rp-col-payment-date" title="Планируемая дата получения денег от заказчика">Оплата</th><th scope="col">Счёт клиенту</th><th scope="col">Получено</th><th scope="col">Перевозчик</th><th scope="col">Себес</th><th scope="col">Счёт перевозчика</th><th scope="col">Оплачено</th></tr></thead>
        <tbody id="rp-list">
        <?php foreach ($trips as $trip):
            $businessId = (int)$trip['business_id'];
            $start = $trip['actual_start_date'] ?: ($trip['planned_start_date'] ?: $trip['planned_start_date_from']);
            $end = $trip['actual_end_date'] ?: $trip['planned_finish_date'];
            $startLabel = $date($start);
            if (!$trip['actual_start_date'] && !$trip['planned_start_date'] && $trip['planned_start_date_from']) $startLabel = 'с ' . $startLabel;
            $endLabel = $date($end);
            $description = trim((string)$trip['comment']) ?: 'Без описания';
            $detailTitle = 'Рейс #' . $businessId . ' · ' . $description . ' · ' . $startLabel . ($endLabel === '—' ? '' : ' — ' . $endLabel);
            $customer = $stats($trip, 'customer');
            $carrier = $stats($trip, 'carrier');
            $plannedReceipt = $trip['planned_receipt'] ?? null;
            $plannedDate = is_array($plannedReceipt) ? (string)($plannedReceipt['date'] ?? '') : '';
            $customerFullyReceived = $customer['rate'] !== null && $customer['rate'] > 0 && $customer['paid'] >= $customer['rate'];
            $paymentDateState = '';
            if ($plannedDate !== '' && !empty($plannedReceipt['remaining']) && !$customerFullyReceived
                && (string)($plannedReceipt['due_date'] ?? '') === $plannedDate
                && !in_array((string)($plannedReceipt['status'] ?? ''), ['waiting_event', 'calendar_missing'], true)) {
                if ($plannedDate < $todayMoscow) $paymentDateState = ' rp-payment-overdue';
                elseif ($plannedDate === $todayMoscow) $paymentDateState = ' rp-payment-due-today';
            }
            $search = '#' . $businessId . ' ' . $description . ' ' . $trip['client_name'] . ' ' . $trip['carrier_name'] . ' ' . implode(' ', array_map(static fn(array $i): string => (string)$i['number'], array_merge($trip['sides']['customer']['invoices'], $trip['sides']['carrier']['invoices'])));
            $invoiceSummary = static function (array $invoices): string {
                if (!$invoices) return '—';
                return '№ ' . (string)$invoices[0]['number'] . (count($invoices) > 1 ? ' +' . (count($invoices) - 1) : '');
            };
            $paidClass = static function (array $values): string {
                if ($values['rate'] === null) return 'unknown';
                if ($values['paid'] >= $values['rate']) return 'full';
                return $values['paid'] > 0 ? 'partial' : 'none';
            };
            ?>
            <tr class="rp-compact-row" data-id="<?= $businessId ?>" data-status="<?= e($tripStatus($trip)) ?>" data-payment-status="<?= e($paymentStatus($trip)) ?>" data-search="<?= e(mb_strtolower($search, 'UTF-8')) ?>" tabindex="0" aria-label="<?= e($detailTitle . '. Двойной щелчок или Enter — подробности') ?>" title="Двойной щелчок — подробности рейса">
                <th scope="row" class="rp-col-id">#<?= $businessId ?></th>
                <td class="rp-col-description"><span class="rp-summary-clip" title="<?= e($description) ?>"><?= e($description) ?></span></td>
                <td class="rp-col-date"><?= e($startLabel) ?></td><td class="rp-col-date"><?= e($endLabel) ?></td>
                <td class="rp-col-party"><span class="rp-summary-clip" title="<?= e((string)$trip['client_name']) ?>"><?= e((string)$trip['client_name']) ?></span></td>
                <td class="rp-col-money"><?= $customer['rate'] === null ? '<span class="rp-summary-empty">—</span>' : e($money($customer['rate'])) ?></td>
                <td class="rp-col-payment-date<?= $paymentDateState ?>" title="<?= e($paymentDateState === ' rp-payment-overdue' ? 'Срок получения оплаты прошёл' : ($paymentDateState === ' rp-payment-due-today' ? 'Срок получения оплаты сегодня' : 'Планируемая дата получения оплаты')) ?>"><span class="rp-payment-date-text"><?= e($plannedDate === '' ? '—' : $date($plannedDate)) ?></span></td>
                <td class="rp-col-invoice"><span class="rp-summary-clip" title="<?= e(implode(', ', array_map(static fn(array $i): string => '№ ' . $i['number'], $trip['sides']['customer']['invoices']))) ?>"><?= e($invoiceSummary($trip['sides']['customer']['invoices'])) ?></span></td>
                <td class="rp-col-money rp-paid--<?= $paidClass($customer) ?>" title="Получено: <?= e($money($customer['paid'])) ?>"><?= e($money($customer['paid'])) ?></td>
                <td class="rp-col-party"><span class="rp-summary-clip" title="<?= e((string)$trip['carrier_name']) ?>"><?= e(trim((string)$trip['carrier_name']) ?: '—') ?></span></td>
                <td class="rp-col-money"><?= $carrier['rate'] === null ? '<span class="rp-summary-empty">—</span>' : e($money($carrier['rate'])) ?></td>
                <td class="rp-col-invoice"><span class="rp-summary-clip" title="<?= e(implode(', ', array_map(static fn(array $i): string => '№ ' . $i['number'], $trip['sides']['carrier']['invoices']))) ?>"><?= e($invoiceSummary($trip['sides']['carrier']['invoices'])) ?></span></td>
                <td class="rp-col-money rp-paid--<?= $paidClass($carrier) ?>" title="Оплачено: <?= e($money($carrier['paid'])) ?>"><?= e($money($carrier['paid'])) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table><div class="rp-empty-state" id="rp-empty"<?= $trips ? ' hidden' : '' ?>>По выбранным условиям рейсов нет.</div></div>
</div>

<?php foreach ($trips as $trip):
    $businessId = (int)$trip['business_id'];
    $start = $trip['actual_start_date'] ?: ($trip['planned_start_date'] ?: $trip['planned_start_date_from']);
    $end = $trip['actual_end_date'] ?: $trip['planned_finish_date'];
    $startLabel = $date($start);
    if (!$trip['actual_start_date'] && !$trip['planned_start_date'] && $trip['planned_start_date_from']) $startLabel = 'с ' . $startLabel;
    $endLabel = $date($end);
    $detailTitle = 'Рейс #' . $businessId . ' · ' . (trim((string)$trip['comment']) ?: 'Без описания') . ' · ' . $startLabel . ($endLabel === '—' ? '' : ' — ' . $endLabel);
    ?><template id="rp-detail-<?= $businessId ?>" data-title="<?= e($detailTitle) ?>"><div class="modal-body rp-modal-body rp-details-body"><div class="rp-details-sides"><?php $renderSide($trip, 'customer'); $renderSide($trip, 'carrier'); ?></div></div></template>
<?php endforeach; ?>
<div id="rp-detail-modal" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="rp-detail-title" data-close-on-overlay="1" data-close-on-escape="1"><div class="modal modal-lg">
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
<script>
(function(){
  const items=[...document.querySelectorAll('.rp-compact-row')], filter=document.getElementById('rp-filter'), paymentFilter=document.getElementById('rp-payment-filter'), search=document.getElementById('rp-search'), sort=document.getElementById('rp-sort'), list=document.getElementById('rp-list'), empty=document.getElementById('rp-empty');
  function render(){
    const q=search.value.trim().toLocaleLowerCase('ru');
    let visible=0;
    items.sort((a,b)=>sort.value==='old'?Number(a.dataset.id)-Number(b.dataset.id):Number(b.dataset.id)-Number(a.dataset.id)).forEach(item=>{
      item.hidden=(filter.value!=='all'&&item.dataset.status!==filter.value)
        ||(paymentFilter.value!=='all'&&item.dataset.paymentStatus!==paymentFilter.value)
        ||(q&&!item.dataset.search.includes(q));
      if(!item.hidden)visible++;
      list.appendChild(item);
    });
    document.getElementById('rp-count').textContent=visible;
    empty.hidden=visible>0;
  }
  [filter,paymentFilter,sort].forEach(el=>el.addEventListener('change',render));search.addEventListener('input',render);
  const details=document.getElementById('rp-detail-modal'), title=document.getElementById('rp-detail-title'), content=document.getElementById('rp-detail-content');
  let lastFocusedRow=null;
  new MutationObserver(()=>{if(!details.classList.contains('is-open')&&lastFocusedRow){lastFocusedRow.focus();lastFocusedRow=null;}}).observe(details,{attributes:true,attributeFilter:['class']});
  function openDetails(row){
    const template=document.getElementById('rp-detail-'+row.dataset.id);
    if(!template)return;
    title.textContent=template.dataset.title;
    content.replaceChildren(template.content.cloneNode(true));
    lastFocusedRow=row;
    window.openModal('rp-detail-modal');
    details.querySelector('.modal-close').focus();
  }
  items.forEach(row=>{
    row.addEventListener('dblclick',()=>openDetails(row));
    row.addEventListener('keydown',event=>{if(event.key==='Enter'||event.key===' '){event.preventDefault();openDetails(row);}});
    row.addEventListener('click',()=>{if(window.matchMedia('(max-width:900px)').matches)openDetails(row);});
  });
  document.querySelector('[data-open-modal="invoice-create-modal"]')?.addEventListener('click',()=>{const form=document.querySelector('#invoice-create-modal form.invoice-form');if(form)delete form.dataset.preferredTripId;});
  details.addEventListener('click',event=>{
    const create=event.target.closest('[data-create-trip]');
    if(create){
      const form=document.querySelector('#invoice-create-modal form.invoice-form');
      if(!form)return;
      form.dataset.preferredTripId=create.dataset.createTrip;
      const direction=form.querySelector('.js-inv-direction');
      direction.value=create.dataset.createDirection;
      direction.dispatchEvent(new Event('change',{bubbles:true}));
      const party=form.querySelector('.js-inv-cparty-select');
      party.value=create.dataset.createParty;
      party.dispatchEvent(new Event('change',{bubbles:true}));
      lastFocusedRow=null;
      window.closeModal('rp-detail-modal');
      window.openModal('invoice-create-modal');
    }
    const invoice=event.target.closest('[data-view-invoice]');
    if(invoice){
      const body=document.getElementById('invoice-view-modal-body');
      body.textContent='Загрузка…';
      window.openModal('invoice-view-modal');
      fetch(<?= json_encode(app_url('/company/finance/invoices/'), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>+encodeURIComponent(invoice.dataset.viewInvoice)+'/modal-view').then(response=>{if(!response.ok)throw new Error('Счёт недоступен');return response.text()}).then(html=>body.innerHTML=html).catch(error=>body.textContent=error.message);
    }
  });
})();
</script>
