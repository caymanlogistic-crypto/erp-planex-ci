<?php

declare(strict_types=1);

$root = dirname(__DIR__);

function fail_test(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function assert_contains(string $haystack, string $needle, string $message): void
{
    if (!str_contains($haystack, $needle)) fail_test($message . " [missing: {$needle}]");
}

function assert_not_contains(string $haystack, string $needle, string $message): void
{
    if (str_contains($haystack, $needle)) fail_test($message . " [unexpected: {$needle}]");
}

function method_body(string $php, string $methodName): string
{
    $marker = 'function ' . $methodName . '(';
    $start = strpos($php, $marker);
    if ($start === false) fail_test("Method {$methodName} not found");
    $open = strpos($php, '{', $start);
    if ($open === false) fail_test("Method {$methodName} body not found");
    $depth = 0;
    $len = strlen($php);
    for ($i = $open; $i < $len; $i++) {
        if ($php[$i] === '{') $depth++;
        if ($php[$i] === '}') {
            $depth--;
            if ($depth === 0) return substr($php, $open, $i - $open + 1);
        }
    }
    fail_test("Method {$methodName} is not balanced");
}

$service = file_get_contents($root . '/app/Service/FinanceManualFactService.php');
$migration = file_get_contents($root . '/database/migrations-local/073_finance_manual_cash_and_personal_expense_facts.sql');
$form = file_get_contents($root . '/app/View/partials/company_cash_operation_form.php');
$page = file_get_contents($root . '/app/View/pages/company_finance_cash.php');
$submit = file_get_contents($root . '/app/Http/Controllers/Company/FinanceCashActions/operation_create_submit.php');
$ledger = file_get_contents($root . '/app/Service/FinanceCashLedgerService.php');
$deletion = file_get_contents($root . '/app/Service/FinanceStructureDeletionService.php');
$matchingManual = file_get_contents($root . '/app/Service/FinanceMatchingRuleManualTrait.php');
$matchingSubmit = file_get_contents($root . '/app/Http/Controllers/Company/BankFinanceActions/classifySubmit.php');
$matchingPopup = file_get_contents($root . '/app/View/partials/company_bank_transaction_classify_form.php');
$employeeBank = file_get_contents($root . '/app/Service/FinanceEmployeeBankSettlementService.php');
$matchingBatch = file_get_contents($root . '/app/Service/FinanceMatchingRuleBatchTrait.php');
$bankFilters = file_get_contents($root . '/app/Http/Controllers/Company/BankFinanceActions/indexAutoFilters.php');

foreach (compact('service','migration','form','page','submit','ledger','deletion','matchingManual','matchingSubmit','matchingPopup','employeeBank','matchingBatch','bankFilters') as $name => $value) {
    if ($value === false) fail_test("Cannot read {$name}");
}

$client = method_body($service, 'createClientCashReceipt');
assert_contains($client, "'operation_type' => 'INCOME'", 'Legacy client cash receipt writer must remain a real income operation');
assert_contains($client, "'linear_route_payment_id'", 'Legacy client cash receipt writer must preserve historical route-payment compatibility');
assert_contains($client, "finance_operation_allocations", 'Legacy client cash receipt writer must preserve manual allocation history');
assert_contains($client, "cascadeAfterAllocationCreate", 'Legacy client cash receipt writer must recalculate settlement');
assert_contains($client, "finance_cash_route_receipts", 'Legacy client cash receipt writer must keep historical route traceability');
assert_contains($client, "outflow_finance_operation_id", 'Legacy client cash receipt writer must explicitly use resolution without fake outflow');
assert_contains($client, "NULL, NULL", 'Legacy client cash resolution must not create outflow/employee movement');
assert_not_contains($client, "createCashMovement", 'Legacy client cash receipt writer must not create employee cash movement');

// Legacy 073 fact writer is preserved for historical compatibility only. The live entry point moved to Employee Payments.
$personal = method_body($service, 'createEmployeePersonalExpense');
assert_contains($personal, 'finance_employee_personal_expenses', 'Legacy personal-funded fact storage must remain readable');
assert_contains($personal, 'FinanceStructureService::assertAllowedPair', 'Legacy personal-funded fact must validate CFU/DDS pairing');
assert_not_contains($personal, 'FinanceCashService::createCashOperation', 'Legacy fact writer must not silently become a money writer');

assert_contains($migration, 'finance_cash_route_receipts', 'Migration must create cash-route receipt trace table');
assert_contains($migration, 'finance_employee_personal_expenses', 'Migration must create employee personal expense fact table');
assert_contains($migration, 'outflow_finance_operation_id` INT UNSIGNED DEFAULT NULL', 'Cash resolution must support business assignment without outflow');
assert_contains($migration, 'cash_flow_center_name_snapshot', 'Migration must preserve CFU snapshot');
assert_contains($migration, 'dds_category_name_snapshot', 'Migration must preserve DDS snapshot');

// The primary UI is invoice-centric now. Keep the legacy writer only for historical compatibility; do not expose route-first entry.
assert_contains($form, 'CLIENT_CASH_INVOICE', 'Cash UI must expose invoice-centric client cash receipt scenario');
assert_not_contains($form, '<option value="CLIENT_CASH_RECEIPT">', 'Cash UI must not expose the legacy route-first client cash scenario');
assert_not_contains($form, '<option value="EMPLOYEE_PERSONAL_EXPENSE">', 'Cash UI must not expose employee personal-funded expense entry point');
assert_not_contains($form, 'data-scenario-block="EMPLOYEE_PERSONAL_EXPENSE"', 'Cash UI must not keep a competing personal-expense form block');
assert_contains($page, 'initManualFinanceForm', 'Cash page must initialize fetched manual finance form');

// Linked personal expenses remain visible, but only in the unified cash journal.
// The old lower table was a duplicate of the same economic event and must not return.
assert_contains($ledger, 'finance_employee_personal_expenses employee_personal_receipt', 'Cash ledger must project linked personal expenses into the unified journal');
assert_contains($ledger, "return 'EMPLOYEE_PERSONAL_EXPENSE';", 'Unified cash journal must identify personal-expense lifecycle rows');
assert_contains($page, 'data-cash-lifecycle-toggle', 'Linked employee-funded expenses must be expandable in the unified cash journal');
assert_contains($page, 'cash-lifecycle-detail-row', 'Unified cash journal must expose the two linked cash legs on demand');
assert_not_contains($page, '<div class="section-title mt-section">Расходы сотрудников из личных средств</div>', 'Cash page must not duplicate personal expenses in a second history table');

assert_contains($submit, 'CLIENT_CASH_INVOICE', 'Cash submit must route client cash through invoice-centric settlement');
assert_contains($submit, 'оформляется в разделе «Выплаты сотрудникам»', 'Stale cash personal-expense submissions must fail closed and point to Employee Payments');
assert_contains($ledger, 'CASH_RESOLUTION_CLIENT_ROUTE', 'Cash ledger must preserve legacy route-assigned client cash visibility');
assert_contains($deletion, 'finance_employee_personal_expenses', 'CFU/DDS deletion must protect recorded personal expenses');

// Bank transaction popup: manual single-operation and persistent rule creation must remain separate architectures.
assert_contains($matchingManual, "\$mode!=='manual'", 'Single-operation executor must reject rule mode');
assert_not_contains($matchingManual, 'self::createRule(', 'Single-operation executor must never create a matching rule');
assert_contains($matchingManual, "classification_status='MANUAL'", 'Single-operation categorization must persist MANUAL state');
assert_contains($matchingManual, 'classification_locked=1', 'Single-operation categorization must persist manual lock');
assert_contains($matchingSubmit, 'FinanceMatchingRuleService::createRule', 'Popup rule mode must use canonical matching-rule creation');
assert_contains($matchingSubmit, 'FinanceMatchingRuleService::applyAutoMatchToOperation', 'Popup-created rule must execute through canonical rule engine');
assert_contains($matchingSubmit, 'resolveActiveEmployee', 'Popup employee action must use canonical employee resolution');
assert_contains($matchingManual, 'FinanceEmployeeBankSettlementService::settleBankTransaction', 'Manual employee action must delegate to shared BANK-EMPLOYEE service');
assert_contains($employeeBank, '$isManual = $ruleId === null', 'Employee settlement must distinguish manual execution from rule execution');
assert_contains($employeeBank, "\$classificationStatus = \$isManual ? 'MANUAL' : 'AUTO'", 'Manual employee settlement must persist MANUAL; rule execution must persist AUTO');
assert_contains($employeeBank, '$classificationLocked = $isManual ? 1 : 0', 'Manual employee settlement must lock the bank transaction');
assert_contains($employeeBank, "'BANK'", 'Employee movement must retain BANK source');
assert_contains($employeeBank, "\$movementType = \$originalType === 'EXPENSE' ? 'PAYMENT' : 'RETURN'", 'Bank debit/payment and bank credit/return directions must stay intact');
assert_not_contains($employeeBank, 'INSERT INTO finance_cash_resolutions', 'Direct BANK-EMPLOYEE flow must not write CASH resolutions');
assert_contains($matchingBatch, 'classification_locked=0', 'Batch rule engine must exclude locked bank transactions');
assert_contains($matchingBatch, "classification_status<>'MANUAL'", 'Batch rule engine must exclude MANUAL finance operations');
foreach (['action_type','target_employee_ref','target_cash_flow_center_id','target_dds_category_id','direction','counterparty_inn','purpose_contains','invoice_number_pattern','purpose_regex','bank_account_id','amount_from','amount_to','priority'] as $field) {
    assert_contains($matchingPopup, 'name="'.$field.'"', 'Bank popup must expose rule field '.$field);
}
assert_contains($matchingPopup, 'value="categorize"', 'Bank popup must expose ordinary categorization');
assert_contains($matchingPopup, 'value="employee_cash_settlement"', 'Bank popup must expose employee settlement');
assert_contains($matchingPopup, 'tx-rule-settings', 'Rule-only conditions must remain visually scoped to Create rule mode');
assert_contains($bankFilters, '.bank-row-manual>td', 'Manual row must keep whole-row green styling');
assert_contains($bankFilters, '#eff8ed', 'Manual row must keep soft green background');
assert_contains($bankFilters, '.bank-row-unallocated>td', 'Problem row must keep existing red styling');
assert_contains($bankFilters, '#fff0ed', 'Problem row must keep red background');

fwrite(STDOUT, "OK: manual finance facts regression passed\n");
