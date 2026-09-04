<?php
require_once base_path('app/Service/FinanceOperationInvoiceSettlementService.php');
require_once base_path('app/Service/FinanceCashInvoiceEventService.php');
require_once base_path('app/Service/FinanceEmployeeInvoicePaymentEventService.php');
require_once base_path('app/Service/FinanceEmployeePersonalExpenseEventService.php');
require_once base_path('app/Service/FinanceEmployeeMoneyAccountService.php');
require_once base_path('app/Service/FinanceEmployeeBankSettlementService.php');
require_once base_path('app/Service/FinanceEmployeeDirectTransferService.php');
require_once base_path('app/Service/FinanceEmployeeDirectTransferEditService.php');
require_once base_path('app/Service/FinanceEmployeeDirectExpenseService.php');
require_once base_path('app/Service/FinanceEmployeeDirectInvoicePaymentService.php');
require_once base_path('app/Service/FinanceEmployeeClientReceiptService.php');
require_once base_path('app/Service/FinanceEmployeeClientReceiptEditService.php');
require_once base_path('app/Http/Controllers/Company/FinanceEmployeePaymentsController.php');
require_once base_path('app/Http/Controllers/Company/FinanceEmployeeInvoicePaymentController.php');
require_once base_path('app/Http/Controllers/Company/FinanceEmployeePersonalExpenseController.php');

$controller = new \App\Http\Controllers\Company\FinanceEmployeePaymentsController($config, $db);
$invoicePaymentController = new \App\Http\Controllers\Company\FinanceEmployeeInvoicePaymentController($config, $db);
$personalExpenseController = new \App\Http\Controllers\Company\FinanceEmployeePersonalExpenseController($config, $db);
$employeePaymentsPage = 'company.finance.employee_payments';

$router->get('/company/finance/employee-payments', [$controller, 'index'], $employeePaymentsPage);

// Retired generic form: the workspace exposes exactly four business operations.
$router->get('/company/finance/employee-payments/create', static function (): void {
    $_SESSION['employee_payments_success'] = 'Выберите операцию: получено от клиента, передача сотруднику, оплата счёта или прочий расход.';
    redirect_to('/company/finance/employee-payments');
}, $employeePaymentsPage);
$router->post('/company/finance/employee-payments/create', static function (): void {
    verifyCsrfRequest();
    $_SESSION['employee_payments_error'] = 'Устаревший универсальный способ отключён. Используйте одну из четырёх бизнес-операций.';
    redirect_to('/company/finance/employee-payments');
}, $employeePaymentsPage);

$router->post('/company/finance/employee-payments/client-receipt/create', static function () use ($config, $db): void {
    require base_path('app/Http/Controllers/Company/FinanceEmployeeDirectActions/client_receipt_create.php');
}, $employeePaymentsPage);
$router->post('/company/finance/employee-payments/client-receipt/update', static function () use ($config, $db): void {
    require base_path('app/Http/Controllers/Company/FinanceEmployeeDirectActions/client_receipt_update.php');
}, $employeePaymentsPage);
$router->post('/company/finance/employee-payments/client-receipt/cancel', static function () use ($config, $db): void {
    require base_path('app/Http/Controllers/Company/FinanceEmployeeDirectActions/client_receipt_cancel.php');
}, $employeePaymentsPage);

$router->post('/company/finance/employee-payments/transfer', static function () use ($config, $db): void {
    require base_path('app/Http/Controllers/Company/FinanceEmployeeDirectActions/transfer_submit.php');
}, $employeePaymentsPage);
$router->post('/company/finance/employee-payments/transfer/update', static function () use ($config, $db): void {
    require base_path('app/Http/Controllers/Company/FinanceEmployeeDirectActions/transfer_update.php');
}, $employeePaymentsPage);
$router->post('/company/finance/employee-payments/transfer/delete', static function () use ($config, $db): void {
    require base_path('app/Http/Controllers/Company/FinanceEmployeeDirectActions/transfer_cancel.php');
}, $employeePaymentsPage);

$router->get('/company/finance/employee-payments/employee/{type}/{id}', [$controller, 'employeeDetail'], $employeePaymentsPage);
$router->post('/company/finance/employee-payments/movements/{id}/reassign', [$controller, 'reassignMovement'], $employeePaymentsPage);

$router->post('/company/finance/employee-payments/bank-link', static function () use ($config, $db): void {
    require base_path('app/Http/Controllers/Company/FinanceEmployeeDirectActions/bank_link.php');
}, $employeePaymentsPage);
$router->post('/company/finance/employee-payments/bank-unlink', static function (): void {
    verifyCsrfRequest();
    $_SESSION['bank_finance_error'] = 'Прямой перевод банк ↔ сотрудник нельзя частично отвязать. Для корректировки используйте компенсирующую операцию.';
    redirect_to('/company/finance/bank-accounts');
}, $employeePaymentsPage);

$router->post('/company/finance/employee-payments/invoice-payment/create', static function () use ($config, $db): void {
    require base_path('app/Http/Controllers/Company/FinanceEmployeeDirectActions/invoice_payment_create.php');
}, $employeePaymentsPage);
$router->post('/company/finance/employee-payments/invoice-payment/update', [$invoicePaymentController, 'updateSubmit'], $employeePaymentsPage);
$router->post('/company/finance/employee-payments/invoice-payment/cancel', [$invoicePaymentController, 'cancelSubmit'], $employeePaymentsPage);

$router->get('/company/finance/employee-payments/personal-expenses', [$personalExpenseController, 'listForEmployee'], $employeePaymentsPage);
$router->get('/company/finance/employee-payments/personal-expense/create', [$personalExpenseController, 'createForm'], $employeePaymentsPage);
$router->post('/company/finance/employee-payments/personal-expense/create', static function () use ($config, $db): void {
    require base_path('app/Http/Controllers/Company/FinanceEmployeeDirectActions/personal_expense_create.php');
}, $employeePaymentsPage);
$router->get('/company/finance/employee-payments/personal-expense/{id}/edit', [$personalExpenseController, 'editForm'], $employeePaymentsPage);
$router->post('/company/finance/employee-payments/personal-expense/{id}/update', [$personalExpenseController, 'updateSubmit'], $employeePaymentsPage);
$router->post('/company/finance/employee-payments/personal-expense/{id}/cancel', [$personalExpenseController, 'cancelSubmit'], $employeePaymentsPage);
