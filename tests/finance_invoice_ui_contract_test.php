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
uiMustContain($view,'$stageCards','invoice timeline is projected into stage cards');
uiMustContain($view,'Этапы оплаты','payment stages are the primary payment column');
uiMustNotContain($view,'Статус счёта','separate invoice status column remains removed');
uiMustContain($view,"$statusText = 'Оплачен';",'closed stage shows current paid fact');
uiMustNotContain($view,'Оплачен с просрочкой','closed stage hides historical lateness');
uiMustContain($view,'.invoice-stage-card.is-paid{background:transparent;border-color:var(--border)}','paid stage card no longer owns success background');
uiMustContain($view,'td:has(> .invoice-stages > .invoice-stage-card.is-paid):not(:has(> .invoice-stages > .invoice-stage-card:not(.is-paid))){background:var(--success-bg)}','fully paid invoice paints the whole payment cell');
uiMustNotContain($view,'.invoice-stage-card.is-paid{background:var(--success-bg)','paid stage card must not be green');
uiMustContain($view,'.invoice-stages-col{width:1%;min-width:0;white-space:nowrap}','payment stages column stays shrink-to-content without trailing space');
uiMustContain($view,'.invoice-stages{display:inline-flex;flex-wrap:nowrap;gap:6px;width:max-content;min-width:0;max-width:none;vertical-align:top','payment stage strip stays on one horizontal line at intrinsic width');
uiMustContain($view,'.invoice-stage-card{display:flex;flex-direction:column;flex:0 0 190px;min-width:170px','payment stage cards keep stable side-by-side width');
uiMustNotContain($view,'flex-wrap:wrap;gap:6px;width:max-content','payment stages must not wrap into a vertical stack');
uiMustNotContain($view,'max-width:520px','payment stage strip must not retain the old wrapping cap');
uiMustContain($view,'<td class="invoice-stages-col">','payment stage body cell shares the shrink-to-content column contract');
uiMustContain($view,'Частично · просрочка','partial overdue state remains explicit');
uiMustContain($view,"$statusClass = 'badge badge-warning';",'partial overdue uses warning badge');
uiMustContain($view,'is-overdue-remaining','overdue remainder uses dedicated styling');
uiMustContain($view,'var(--danger-text)','overdue remainder uses system danger color');
uiMustContain($view,'Ожидается событие','event-driven stage waits for event');
uiMustContain($view,'Остаток:','partial stage keeps remainder');
uiMustNotContain($view,'План / факт оплаты','old split plan/fact column remains removed');
uiMustNotContain($view,'Соблюдение сроков','old compliance block remains removed');

echo "FINANCE_INVOICE_UI_CONTRACT_OK\n";
