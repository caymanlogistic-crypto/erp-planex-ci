<?php

declare(strict_types=1);

$root = dirname(__DIR__);
function fcp_fail(string $message): never { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
function fcp_read(string $path): string { global $root; $v=file_get_contents($root.'/'.$path); if($v===false) fcp_fail('Cannot read '.$path); return $v; }
function fcp_has(string $text,string $needle,string $message): void { if(!str_contains($text,$needle)) fcp_fail($message.' [missing: '.$needle.']'); }
function fcp_not(string $text,string $needle,string $message): void { if(str_contains($text,$needle)) fcp_fail($message.' [unexpected: '.$needle.']'); }

$routes=fcp_read('app/Http/Routes/company_finance_invoices.php');
$controller=fcp_read('app/Http/Controllers/Company/FinanceInvoiceController.php');
$action=fcp_read('app/Http/Controllers/Company/InvoiceActions/payables.php');
$dateAction=fcp_read('app/Http/Controllers/Company/InvoiceActions/settlement_date_submit.php');
$report=fcp_read('app/Service/FinancePayablesReportService.php');
$calendar=fcp_read('app/Service/FinancePaymentCalendarService.php');
$obligations=fcp_read('app/Service/FinanceObligationService.php');
$uninvoiced=fcp_read('app/Service/FinanceUninvoicedObligationService.php');
$planFact=fcp_read('app/Service/FinancePaymentPlanFactService.php');
$managementBalance=fcp_read('app/Service/FinanceManagementBalanceService.php');
$dashboard=fcp_read('app/Service/FinanceDashboardService.php');
$cascade=fcp_read('app/Service/FinanceSettlementCascadeService.php');
$serviceFinance=fcp_read('app/Service/LinearTripServiceFinanceService.php');
$entrypointDependencies=fcp_read('app/Support/entrypoint_dependencies.php');
$history=fcp_read('app/Service/FinanceInvoiceSettlementHistoryService.php');
$dateService=fcp_read('app/Service/FinanceInvoiceSettlementDateService.php');
$view=fcp_read('app/View/pages/company_finance_payables.php');
$modal=fcp_read('app/View/partials/company_invoice_modal_view.php');
$generic=fcp_read('app/Service/FinanceOperationInvoiceSettlementService.php');
$bank=fcp_read('app/Service/FinanceBankInvoiceSettlementService.php');
$employeeDirect=fcp_read('app/Service/FinanceEmployeeDirectInvoicePaymentService.php');

foreach (['FinanceOperationInvoiceSettlementService.php','FinanceInvoiceSettlementHistoryService.php','FinanceInvoiceSettlementDateService.php','FinancePayablesReportService.php'] as $dependency) {
    fcp_has($routes, "app/Service/{$dependency}", 'Invoice route must load runtime dependency '.$dependency);
}

fcp_has($routes,"/company/finance/payables",'Payables route must exist');
fcp_has($controller,'function payables()', 'Controller must expose payables');
fcp_has($action,"requireRole(['company_owner'])",'Payables must remain owner-only');
fcp_has($report,"o.direction='PAYABLE'",'AP register must be driven by PAYABLE obligations');
fcp_has($report,"fo.status='POSTED'",'AP paid facts must only use posted money operations');
fcp_has($report,'a.cancelled_at IS NULL','Cancelled allocations must not count as paid');
fcp_has($report,'finance_invoice_links','AP must preserve invoice-obligation links');

// Additional services are first-class PAYABLE obligations and must survive every
// Finance V2 read/sync path instead of being cancelled by the legacy route-payment projection.
fcp_has($serviceFinance,"LINEAR_ROUTE_SERVICE_PAYMENT",'Additional-service payments must have a dedicated obligation source type');
fcp_has($serviceFinance,"direction='PAYABLE'",'Additional-service obligations must remain PAYABLE');
fcp_has($serviceFinance,"party_role='service'",'Additional-service obligations must retain the service role');
fcp_has($obligations,'LinearTripServiceFinanceService::syncRoute($pdo, $routeId)','Global route obligation sync must also refresh additional services');
fcp_has($obligations,"source_type='LINEAR_ROUTE_PAYMENT'",'Legacy stale cleanup must be scoped to legacy route-payment obligations only');
fcp_has($obligations,"WHERE source_type='LINEAR_ROUTE_PAYMENT'\n                AND source_parent_type='LINEAR_ROUTE'",'Legacy stale cleanup query must explicitly exclude additional-service obligations');
fcp_has($report,'FinanceObligationService::syncAllLinearRoutes','Payables must refresh all obligation families before reading');
fcp_has($calendar,'FinanceObligationService::syncAllLinearRoutes','Payment calendar must refresh all obligation families before reading');

// Reports that combine invoices with uninvoiced plan data must consume the same
// universal projection, otherwise additional-service costs disappear until an invoice exists.
fcp_has($entrypointDependencies,'FinanceUninvoicedObligationService.php','Universal uninvoiced projection must be loaded at runtime');
fcp_has($uninvoiced,'finance_obligations','Universal uninvoiced projection must be obligation-based');
fcp_has($uninvoiced,'linked_invoice_amount','Projection must subtract the part already represented by active invoices');
fcp_has($uninvoiced,'direct_paid_amount','Projection must preserve direct obligation settlements');
fcp_has($planFact,'FinanceUninvoicedObligationService::fetchOpen','Payment plan/fact must include uninvoiced additional-service obligations');
fcp_has($managementBalance,'FinanceUninvoicedObligationService::fetchOpen','Management balance must include uninvoiced additional-service obligations');
fcp_has($dashboard,'FinanceUninvoicedObligationService::fetchOpen','Finance dashboard forecast must include uninvoiced additional-service obligations');
fcp_has($planFact,'Доп. услуга · Рейс #','Payment plan/fact must visibly identify additional-service rows');
fcp_has($managementBalance,'Дополнительная услуга рейса','Management balance must identify additional-service liabilities');
fcp_has($dashboard,'DIRECTION_PAYABLE','Dashboard outflow must use universal PAYABLE obligations');

// Settlement cascade must update the source service-payment row immediately on
// payment create/cancel, without waiting for a later trip edit or report refresh.
fcp_has($cascade,'LINEAR_ROUTE_SERVICE_PAYMENT','Settlement cascade must detect additional-service obligations');
fcp_has($cascade,'LinearTripServiceFinanceService::syncRoute','Settlement cascade must refresh additional-service paid amount/status');

foreach (['Кредиторская задолженность','Всего к оплате','Просрочено','Перевозчик','Рейс / событие','Входящие счета','Оплачено','Остаток','Источник оплаты'] as $needle) fcp_has($view,$needle,'Payables UI must expose '.$needle);
foreach (['Расчётный счёт','Сотрудник'] as $channel) {
    fcp_has($view,$channel,'Payables UI must explain approved '.$channel.' settlement');
    fcp_has($modal,$channel,'Invoice modal must expose approved '.$channel.' settlement');
}
fcp_not($view,'/company/finance/cash','Payables toolbar must not expose retired navigation');
fcp_not($modal,'data-invoice-cash-pay-toggle','Invoice modal must not expose retired payment button');
fcp_not($modal,'data-invoice-cash-pay-form','Invoice modal must not expose retired payment form');
fcp_not($modal,'Основная касса','Invoice modal must not expose retired payment channel');
fcp_has($view,'data-payables-search','AP table must have client-side search');
fcp_has($view,'data-payables-status','AP table must have settlement filters');

fcp_has($generic,': FinanceInvoiceService::DIRECTION_INCOMING','EXPENSE must settle INCOMING invoices');
fcp_has($bank,"'client' : 'contractor'",'Bank expense must resolve a contractor');
fcp_has($bank,'count($entities) !== 1','Bank fallback must fail closed on ambiguous contractor INN');
fcp_has($employeeDirect,'FinanceOperationInvoiceSettlementService::allocate','Employee carrier payment must use generic settlement');
fcp_has($employeeDirect,"'RETURN','EMPLOYEE'",'Employee carrier payment must reduce employee-held balance directly');
fcp_has($employeeDirect,"'cash_account_used' => false",'Employee carrier payment must bypass retired account model');

// Old invoice payment endpoint remains only as a fail-closed compatibility endpoint.
fcp_has($routes,'/{id}/pay-cash','Retired endpoint must remain intercepted for stale clients');
fcp_has($routes,'Этот способ оплаты отключён','Retired endpoint must fail closed with business guidance');
fcp_not($routes,"[$controller, 'payCashSubmit']",'Retired endpoint must not call old mutation action');

// Historical settlement history remains readable, including old channel identification.
fcp_has($history,"fo.status='POSTED'",'Settlement history must ignore cancelled/unposted operations');
fcp_has($history,'fo.bank_transaction_id','History must identify bank settlements');
fcp_has($history,'finance_employee_invoice_payments','History must identify employee settlements');

fcp_has($routes,'/{id}/settlements/{operationId}/date','Settlement date update route must exist');
fcp_has($controller,'function settlementDateSubmit(', 'Controller must expose settlement date update');
fcp_has($dateAction,"requireRole(['company_owner'])",'Settlement date edit must remain owner-only');
fcp_has($dateAction,'verifyCsrfRequest()','Settlement date edit must require CSRF');
fcp_has($dateAction,'FinanceInvoiceSettlementDateService::updateDate','Date action must delegate to the date service');
fcp_has($dateService,'bank_transaction_id','Date service must distinguish bank-sourced dates');
fcp_has($dateService,'Дата банковского платежа берётся из банковской выписки','Bank dates must be immutable from invoice settlement UI');
fcp_has($dateService,'finance_employee_invoice_payments','Employee-funded invoice event date must be updated');
fcp_has($dateService,'SET allocation_date=?','Allocation date must follow edited actual payment date');
fcp_has($dateService,'first_paid_at','Invoice first-paid date must be synchronized');
fcp_has($dateService,'fully_paid_at','Invoice fully-paid date must be synchronized');
fcp_not($dateService,'INSERT INTO finance_operations','Editing payment date must not create a second finance operation');
fcp_not($dateService,'DELETE FROM','Editing payment date must not delete finance history');
fcp_has($modal,'data-settlement-date-edit','Manual settlement must expose an edit-date control');
fcp_has($modal,'data-settlement-date-form','Manual settlement must expose an inline date form');
fcp_has($modal,'Дата из банковской выписки','Bank settlement UI must explain why its date is not editable here');
fcp_has($modal,'window.location.reload()','Successful date edit must refresh invoice plan/fact data');

fwrite(STDOUT,"OK: carrier payables regression contract passed\n");