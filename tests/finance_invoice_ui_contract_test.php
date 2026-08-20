<?php

declare(strict_types=1);

function uiMustContain(string $path,string $needle,string $message):void{
    $text=file_get_contents(__DIR__.'/../'.$path);
    if($text===false||!str_contains($text,$needle))throw new RuntimeException('FAIL: '.$message);
}

function uiMustNotContain(string $path,string $needle,string $message):void{
    $text=file_get_contents(__DIR__.'/../'.$path);
    if($text!==false&&str_contains($text,$needle))throw new RuntimeException('FAIL: '.$message);
}

$view='app/View/pages/company_finance_invoices.php';
$css='public/assets/css/app.css';
uiMustContain($view,'$stageCards','invoice timeline is projected into stage cards');
uiMustContain($view,'Этапы оплаты','payment stages are the primary payment column');
uiMustNotContain($view,'Статус счёта','separate invoice status column remains removed');
uiMustContain($view,"$statusText = 'Оплачен';",'closed stage shows current paid fact');
uiMustNotContain($view,'Оплачен с просрочкой','closed stage hides historical lateness');
uiMustContain($view,'.invoice-stage-card.is-paid{background:transparent;border-color:var(--border)}','paid stage card no longer owns success background');
uiMustContain($view,'td:has(> .invoice-stages > .invoice-stage-card.is-paid):not(:has(> .invoice-stages > .invoice-stage-card:not(.is-paid))){background:var(--success-bg)}','fully paid invoice paints the whole payment cell');
uiMustContain($view,'tr:hover td.invoice-stages-col:has(> .invoice-stages > .invoice-stage-card.is-paid)','fully paid success cell participates in row hover');
uiMustContain($view,'color-mix(in srgb,var(--success-bg) 86%,var(--line) 14%)','paid cell hover darkens from system success background');
uiMustNotContain($view,'.invoice-stage-card.is-paid{background:var(--success-bg)','paid stage card must not be green');
uiMustContain($view,'.invoice-stages-col{width:1%;min-width:0;white-space:nowrap}','payment stages column stays shrink-to-content without trailing space');
uiMustContain($view,'.invoice-stages{display:inline-flex;flex-wrap:nowrap;gap:6px;width:max-content;min-width:0;max-width:none;vertical-align:top','payment stage strip stays on one horizontal line at intrinsic width');
uiMustContain($view,'.invoice-stage-card{display:flex;flex-direction:column;flex:0 0 190px;min-width:170px','payment stage cards keep stable side-by-side width');
uiMustNotContain($view,'flex-wrap:wrap;gap:6px;width:max-content','payment stages must not wrap into a vertical stack');
uiMustNotContain($view,'max-width:520px','payment stage strip must not retain the old wrapping cap');
uiMustContain($view,'<td class="invoice-stages-col">','payment stage body cell shares the shrink-to-content column contract');
uiMustContain($view,'data-invoice-direction','direction filter is a system select in the toolbar');
uiMustContain($view,'<option value=""','all invoices option remains available');
uiMustContain($view,'data-hide-paid checked','fully paid invoices are hidden by default');
uiMustContain($view,'Убрать полностью оплаченные счета','paid-invoice checkbox has the owner-approved label');
uiMustContain($view,'data-invoice-search','invoice search is explicit and local to the invoice grid');
uiMustContain($view,"searchInput.addEventListener('input', applyInvoiceFilters)",'search filters rows immediately while typing');
uiMustContain($view,"directionSelect.addEventListener('change', applyInvoiceFilters)",'direction select shares the combined filter function');
uiMustContain($view,"hidePaidInput.addEventListener('change', applyInvoiceFilters)",'paid checkbox shares the combined filter function');
uiMustContain($view,'var show = matchesSearch && matchesDirection && matchesPaid;','all three invoice filters combine with AND semantics');
uiMustNotContain($view,'<div class="tabs-page">','legacy invoice direction links are removed');
uiMustContain($css,'.table-card[data-invoice-grid]','invoice grid has a scoped width boundary');
uiMustContain($css,'overflow-x: auto;','invoice table overflow stays inside its scroll container');
uiMustContain($css,'width: max-content;','wide payment stages may keep intrinsic table width without expanding the page');
uiMustContain($view,'Частично · просрочка','partial overdue state remains explicit');
uiMustContain($view,"$statusClass = 'badge badge-warning';",'partial overdue uses warning badge');
uiMustContain($view,'is-overdue-remaining','overdue remainder uses dedicated styling');
uiMustContain($view,'var(--danger-text)','overdue remainder uses system danger color');
uiMustContain($view,'Ожидается событие','event-driven stage waits for event');
uiMustContain($view,'Остаток:','partial stage keeps remainder');
uiMustNotContain($view,'План / факт оплаты','old split plan/fact column remains removed');
uiMustNotContain($view,'Соблюдение сроков','old compliance block remains removed');

echo "FINANCE_INVOICE_UI_CONTRACT_OK\n";
