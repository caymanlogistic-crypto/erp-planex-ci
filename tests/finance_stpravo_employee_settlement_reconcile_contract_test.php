<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$scriptPath = $root . '/scripts/finance_stpravo_employee_settlement_reconcile.php';
$productionPath = $root . '/.github/workflows/finance_stpravo_employee_settlement_reconcile_production.yml';
$requestPath = $root . '/.github/workflows/finance_stpravo_employee_settlement_reconcile_agent_request.yml';

foreach ([$scriptPath, $productionPath, $requestPath] as $path) {
    if (!is_file($path)) throw new RuntimeException('Missing STPRAVO reconciliation artifact: ' . $path);
}
$script = (string)file_get_contents($scriptPath);
$production = (string)file_get_contents($productionPath);
$request = (string)file_get_contents($requestPath);

$ok = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

foreach ([
    "'account_number' => '40702810500810141833'",
    "'operation_date' => '2026-09-10'",
    "'document_number' => '19'",
    "'counterparty_inn' => '9705228628'",
    "'debit_amount' => '50000.00'",
    "APPLY_FINANCE_STPRAVO_RECONCILE",
    "Expected exactly one STPRAVO target transaction across active tenants",
    "movement_foreign_references",
    "counterpart_foreign_references",
    "bank_allocation_count",
    "bank_matching_result_count",
    "DELETE FROM finance_employee_movements",
    "status='CANCELLED'",
    "operation_type='EXPENSE',source='BANK_STATEMENT'",
    "is_internal_transfer=0,linked_cash_transaction_id=NULL",
    "owner_reconcile_employee_settlement",
    "ClosingDocumentComplianceService::reportRows",
    "profile_code",
    "legal_entity",
    "outgoing",
    "schema_migrations",
] as $needle) {
    $ok(str_contains($script, $needle), 'Script contract missing: ' . $needle);
}

$ok(str_contains($production, 'workflow_dispatch:'), 'Production reconciliation must be manual-only');
$ok(!preg_match('/^\s*(push|pull_request|schedule|workflow_run)\s*:/m', $production), 'Production reconciliation has an automatic trigger');
foreach ([
    'Verify exact canonical source',
    'Require successful exact canonical ERP PLANEX CI',
    'Verify deployed runtime compatibility',
    'FINANCE_STPRAVO_MODE=verify',
    'FINANCE_STPRAVO_MODE=apply',
    'APPLY_FINANCE_STPRAVO_RECONCILE',
    'DEPLOY_MARKER_UNCHANGED=YES',
] as $needle) {
    $ok(str_contains($production, $needle), 'Production workflow contract missing: ' . $needle);
}
$ok(!str_contains($production, '/erp/'), 'Production workflow must never target legacy /erp');

$ok(str_contains($request, 'reconcile/finance-stpravo-agent'), 'Agent request must use the dedicated request branch');
$ok(str_contains($request, 'actions: write'), 'Agent request needs only workflow-dispatch capability');
$ok(!str_contains($request, 'ERPV2_SSH_PRIVATE_KEY'), 'Agent request must not own SSH credentials');
$ok(str_contains($request, 'finance_stpravo_employee_settlement_reconcile_production.yml/dispatches'), 'Agent request must dispatch the guarded production workflow');
$ok(str_contains($request, "'confirm_reconcile': 'APPLY_FINANCE_STPRAVO_RECONCILE'"), 'Agent request confirmation token mismatch');
$ok(str_contains($request, 'test "$GITHUB_SHA" = "$canonical_sha"'), 'Agent request must be exact-canonical only');

print "FINANCE_STPRAVO_RECONCILE_CONTRACT_OK\n";
