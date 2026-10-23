<?php

declare(strict_types=1);

function guard(bool $ok, string $message): void { if (!$ok) throw new RuntimeException('FAIL: '.$message); }
$root=dirname(__DIR__);
$service=file_get_contents($root.'/app/Service/FinanceObligationService.php');
$migration=file_get_contents($root.'/database/migrations-local/071_finance_obligations.sql');
$manifest=file_get_contents($root.'/app/Support/entrypoint_dependencies.php');
$invoiceForm=file_get_contents($root.'/app/View/partials/company_invoice_create_form.php');
$invoicePage=file_get_contents($root.'/app/View/pages/company_finance_invoices.php');
$invoiceCreate=file_get_contents($root.'/app/Http/Controllers/Company/InvoiceActions/create_submit.php');
$invoiceEdit=file_get_contents($root.'/app/Http/Controllers/Company/InvoiceActions/modal_edit_submit.php');
$bankImport=file_get_contents($root.'/app/Http/Controllers/Company/BankFinanceActions/import.php');
$tripController=file_get_contents($root.'/app/Http/Controllers/Company/LinearTripController.php');
$routes=file_get_contents($root.'/app/Http/Routes/company_finance_invoices.php');

guard(str_contains($manifest, 'app/Service/FinanceObligationService.php'), 'runtime manifest registers obligation service');
guard(str_contains($migration, 'uk_finance_obligation_source_key'), 'durable source key unique index exists');
guard(substr_count($migration, 'obligation_id') >= 4, 'invoice and allocation obligation links exist');
guard(str_contains($service, 'ProductionCalendarService::addWorkingDays'), 'working days use production calendar');
guard(!str_contains($service, 'DateCalculationService::addWorkingDays'), 'no weekday-only fallback for obligations');
guard(str_contains($service, 'linearSourceKey'), 'durable route source identity exists');
guard(str_contains($service, 'replaceInvoiceLinks'), 'invoice N:M obligation allocation exists');
guard(str_contains($service, 'autoAllocateIncomingCustomerReceipts'), 'legacy entry point remains inert');
guard(!str_contains($service, 'count($numberMatches) === 1'), 'customer receipts are not auto-matched by invoice number');
guard(!str_contains($service, 'count($exact) === 1'), 'customer receipts are not auto-matched by exact amount');
guard(str_contains($invoiceForm, 'obligation_id[]') && str_contains($invoiceForm, 'obligation_amount[]'), 'invoice form supports multiple obligations');
guard(str_contains($invoiceForm, 'syncInvoiceAmount') && str_contains($invoiceForm, 'data-auto-amount') === false, 'invoice total is synchronized from selected obligations in JS');
guard(str_contains($invoiceForm, 'width:16px;height:16px;min-width:16px;min-height:16px'), 'obligation checkbox has compact explicit dimensions');
guard(str_contains($invoiceForm, 'autocomplete="off"') && str_contains($invoiceForm, 'inputmode="decimal"'), 'invoice form prevents stale autofill and uses decimal input mode');
guard(str_contains($invoicePage, "querySelectorAll('table.table tbody tr[data-invoice-id]')"), 'invoice double-click handler is scoped to registry rows');
guard(!str_contains($invoicePage, "querySelectorAll('[data-invoice-id]')"), 'invoice form is not bound to registry double-click handler');
guard(str_contains($invoicePage, "id <= 0) return"), 'invoice double-click ignores invalid invoice identifiers');
guard(str_contains($invoiceCreate, '$obligationTotalCents') && str_contains($invoiceCreate, '$centsToMoney($obligationTotalCents)'), 'create action derives invoice amount from selected obligations');
guard(str_contains($invoiceEdit, '$obligationTotalCents') && str_contains($invoiceEdit, '$centsToMoney($obligationTotalCents)'), 'edit action derives invoice amount from selected obligations');
guard(!str_contains($bankImport, 'autoAllocateIncomingCustomerReceipts'), 'bank import leaves customer receipts for manual settlement');
guard(str_contains($tripController, 'syncFinanceObligations'), 'trip reads synchronize due/status');
guard(str_contains($routes, '/company/finance/receivables') && str_contains($routes, '/company/finance/invoices/obligations'), 'receivables and obligation endpoints routed');
echo "FINANCE_OBLIGATIONS_ARCHITECTURE_OK\n";
