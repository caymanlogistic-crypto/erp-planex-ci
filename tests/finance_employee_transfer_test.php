<?php

declare(strict_types=1);

$directServicePath = dirname(__DIR__) . '/app/Service/FinanceEmployeeDirectTransferService.php';
$directEditPath = dirname(__DIR__) . '/app/Service/FinanceEmployeeDirectTransferEditService.php';
$legacyServicePath = dirname(__DIR__) . '/app/Service/FinanceEmployeeTransferService.php';
$routePath = dirname(__DIR__) . '/app/Http/Routes/company_finance_employee_payments.php';
$viewPath = dirname(__DIR__) . '/app/View/pages/company_finance_employee_payments.php';
$editorPath = dirname(__DIR__) . '/app/View/partials/company_finance_employee_ledger_editor.php';

foreach ([$directServicePath, $directEditPath, $legacyServicePath, $routePath, $viewPath, $editorPath] as $path) {
    if (!is_file($path)) { fwrite(STDERR, "Missing file: {$path}\n"); exit(1); }
}

$direct = file_get_contents($directServicePath);
$directEdit = file_get_contents($directEditPath);
$legacy = file_get_contents($legacyServicePath);
$route = file_get_contents($routePath);
$view = file_get_contents($viewPath);
$editor = file_get_contents($editorPath);
$assert = static function (bool $condition, string $message): void { if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); } };

$assert(str_contains($direct, "'RETURN'"), 'sender must be recorded as RETURN');
$assert(str_contains($direct, "'PAYMENT'"), 'recipient must be recorded as PAYMENT');
$assert(str_contains($direct, 'FinanceEmployeeMoneyAccountService::accountId'), 'each transfer side must use an employee money account');
$assert(str_contains($direct, "'TRANSFER','POSTED','TRANSFER'"), 'employee handoff must be a paired internal transfer');
$assert(str_contains($direct, "'EMPLOYEE'"), 'employee movement provenance must be EMPLOYEE');
$assert(str_contains($direct, "'cash_account_used' => false"), 'direct transfer must record retired-account bypass');
$assert(!str_contains($direct, 'INSERT INTO finance_cash_resolutions'), 'direct transfer must never create historical resolution');
$assert(!str_contains($direct, 'FinanceCashResolutionService::MAIN_CASH_NAME'), 'direct transfer must not depend on retired main account');
$assert(str_contains($direct, 'beginTransaction()'), 'employee handoff must be atomic');
$assert(str_contains($direct, 'rollBack()'), 'employee handoff must roll back both legs on failure');

$assert(str_contains($directEdit, 'public static function update'), 'direct transfer must support correction');
$assert(str_contains($directEdit, 'public static function cancel'), 'direct transfer must support audited delete');
$assert(str_contains($directEdit, "status='CANCELLED'"), 'direct transfer delete must be soft cancellation');
$assert(!str_contains($directEdit, 'finance_cash_resolutions'), 'direct transfer correction must not recreate retired account chain');

$assert(str_contains($legacy, 'public static function decorateLedger'), 'historical ledger decoration remains available');
$assert(str_contains($legacy, 'finance_cash_resolutions'), 'historical transfer implementation remains preserved for compatibility');
$assert(str_contains($legacy, 'public static function updateTransfer'), 'historical transfer correction remains available when explicitly requested');
$assert(str_contains($legacy, 'public static function deleteTransfer'), 'historical transfer audited delete remains available when explicitly requested');

$assert(str_contains($route, "FinanceEmployeeDirectActions/transfer_submit.php"), 'new transfer route must use direct create action');
$assert(str_contains($route, "FinanceEmployeeDirectActions/transfer_update.php"), 'transfer update must dispatch by actual transfer architecture');
$assert(str_contains($route, "FinanceEmployeeDirectActions/transfer_cancel.php"), 'transfer delete must dispatch by actual transfer architecture');
$assert(!str_contains($route, '[$controller, \'transferSubmit\']'), 'active create route must not call legacy transfer controller');

$assert(str_contains($view, 'id="employee-transfer-open"'), 'page header must expose approved transfer button');
$assert(str_contains($view, '>Передать деньги</button>'), 'transfer action uses approved label');
$assert(!str_contains($view, 'Сальдо отправителя после перевода может стать отрицательным'), 'UI must not invent an unresolved negative-transfer business rule');
$assert(str_contains($editor, 'employee-transfer-edit-modal'), 'standard double-click editor must include transfer popup');
$assert(str_contains($editor, "addEventListener('dblclick'"), 'transfer rows must open editor on double click');
$assert(str_contains($editor, 'form="employee-transfer-delete-form">Удалить</button>'), 'transfer popup must expose delete action');
$assert(str_contains($view, 'Поступление: <strong>'), 'all-time incoming label uses Поступление');
$assert(str_contains($view, 'Расход: <strong>'), 'all-time outgoing label uses Расход');
$assert(str_contains($view, '>Поступление</th>'), 'incoming table heading uses Поступление');
$assert(str_contains($view, '>Расход</th>'), 'outgoing table heading uses Расход');

fwrite(STDOUT, "Employee direct transfer architecture: OK\n");
