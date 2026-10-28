<?php

require_once base_path('app/Service/FinanceOperationInvoiceSettlementService.php');
require_once base_path('app/Service/FinanceCashInvoiceEventService.php');
require_once base_path('app/Service/FinanceInvoiceSettlementHistoryService.php');
require_once base_path('app/Service/FinanceInvoiceSettlementDateService.php');
require_once base_path('app/Service/FinancePayablesReportService.php');
require_once base_path('app/Service/DateCalculationService.php');
require_once base_path('app/Service/ProductionCalendarService.php');
require_once base_path('app/Service/FinanceReceivedPaidService.php');
require_once base_path('app/Service/WasteTripClosingService.php');
require_once base_path('app/Service/GeneratedClientInvoiceDocxParser.php');
require_once base_path('app/Http/Controllers/Company/FinanceInvoiceController.php');

$controller = new \App\Http\Controllers\Company\FinanceInvoiceController($config, $db);
$invoicesPage = 'company.finance.invoices';

$router->get('/company/finance/received-paid', [$controller, 'receivedPaid'], 'company.finance.received_paid');

$router->get('/company/finance/invoices', [$controller, 'index'], $invoicesPage);
$router->get('/company/finance/receivables', [$controller, 'receivables'], $invoicesPage);
$router->get('/company/finance/payables', [$controller, 'payables'], $invoicesPage);
$router->post('/company/finance/invoices/create', [$controller, 'createSubmit'], $invoicesPage);
$router->post('/company/finance/invoices/import', [$controller, 'importSubmit'], $invoicesPage);
$router->get('/company/finance/invoices/{id}/imported-file', [$controller, 'importDownload'], $invoicesPage);
$router->get('/company/finance/invoices/{id}/modal-view', [$controller, 'modalView'], $invoicesPage);
$router->get('/company/finance/invoices/{id}/modal-edit', [$controller, 'modalEditForm'], $invoicesPage);
$router->post('/company/finance/invoices/{id}/modal-edit', [$controller, 'modalEditSubmit'], $invoicesPage);
$router->post('/company/finance/invoices/{id}/modal-delete', [$controller, 'modalDelete'], $invoicesPage);

// Retired direct payment channel is fail-closed. Carrier invoices are paid from
// the bank statement or through the approved employee action «Оплатил счёт».
$router->post('/company/finance/invoices/{id}/pay-cash', static function (): void {
    verifyCsrfRequest();
    $_SESSION['invoice_error'] = 'Этот способ оплаты отключён. Используйте банковскую выписку или действие сотрудника «Оплатил счёт».';
    redirect_to('/company/finance/invoices');
}, $invoicesPage);

$router->post('/company/finance/invoices/{id}/settlements/{operationId}/date', [$controller, 'settlementDateSubmit'], $invoicesPage);
$router->get('/company/finance/invoices/obligations', [$controller, 'obligations'], $invoicesPage);
$router->get('/company/finance/invoices/{id}/history', [$controller, 'history'], $invoicesPage);
$router->get('/company/finance/invoices/route-payments', [$controller, 'routePayments'], $invoicesPage);
