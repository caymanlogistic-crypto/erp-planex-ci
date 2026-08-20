<?php

declare(strict_types=1);

function mustContain(string $path,string $needle,string $message):void{
    $text=file_get_contents(__DIR__.'/../'.$path);
    if($text===false||!str_contains($text,$needle))throw new RuntimeException('FAIL: '.$message);
}

function mustNotContain(string $path,string $needle,string $message):void{
    $text=file_get_contents(__DIR__.'/../'.$path);
    if($text!==false&&str_contains($text,$needle))throw new RuntimeException('FAIL: '.$message);
}

mustContain('app/Support/entrypoint_dependencies.php','FinanceBankInvoiceSettlementService.php','runtime service dependency');
mustContain('app/Http/Routes/company_bank_finance.php','/bank-transactions/{id}/settlement','settlement routes');
mustContain('app/Http/Controllers/Company/BankFinanceController.php','settlementForm','settlement form controller action');
mustContain('app/Http/Controllers/Company/BankFinanceController.php','settlementSubmit','settlement submit controller action');
mustContain('app/Http/Controllers/Company/BankFinanceActions/applyRules.php','autoAllocateIncomingCustomerReceipts','apply rules also settles unambiguous client receipts');
mustContain('app/Http/Controllers/Company/BankFinanceActions/applyRules.php','FinanceCarrierBankAutoSettlementService.php','carrier bank auto-settlement service is loaded');
mustContain('app/Http/Controllers/Company/BankFinanceActions/applyRules.php','autoAllocateOutgoingCarrierPayments','apply rules also settles unambiguous carrier payments');
mustContain('app/Http/Controllers/Company/BankFinanceActions/indexAutoFilters.php','bank-invoice-settlement-modal','dedicated settlement modal');
mustContain('app/Http/Controllers/Company/BankFinanceActions/indexAutoFilters.php','settlementGenericPass','generic double-click fallback preserved');
mustContain('app/Http/Controllers/Company/BankFinanceActions/indexAutoFilters.php','/settlement','specialized settlement endpoint used');
mustContain('app/View/partials/company_bank_invoice_settlement_form.php','Открытые счета','specialized invoice list');
mustContain('app/View/partials/company_bank_invoice_settlement_form.php','invoice_id[]','multi-invoice manual allocation');
mustContain('app/Service/FinanceBankInvoiceSettlementService.php','FinanceSettlementCascadeService::cascadeAfterAllocationCreate','manual allocations use canonical cascade');
mustContain('app/Service/FinanceBankInvoiceSettlementService.php',"'manual'",'manual settlement method persisted');
mustContain('app/Service/FinanceBankInvoiceSettlementService.php','obligation_id','manual settlement closes universal obligations');
mustContain('app/Service/FinanceCarrierBankAutoSettlementService.php',"fo.operation_type='EXPENSE'",'carrier auto-settlement scans bank expenses');
mustContain('app/Service/FinanceCarrierBankAutoSettlementService.php',"i.direction='INCOMING'",'carrier auto-settlement targets incoming invoices');
mustContain('app/Service/FinanceCarrierBankAutoSettlementService.php',"o.direction='PAYABLE'",'carrier auto-settlement targets payable obligations');
mustContain('app/Service/FinanceCarrierBankAutoSettlementService.php','purposeMentionsInvoice','invoice number in bank purpose drives unambiguous matching');
mustContain('app/Service/FinanceCarrierBankAutoSettlementService.php',"'auto_exact'",'automatic carrier allocations are persisted as automatic');
mustContain('app/Service/FinanceCarrierBankAutoSettlementService.php','$opRemaining','partial carrier payment amount is allocated, not forced to equal invoice total');
mustContain('app/View/pages/company_finance_invoices.php','$stageCards','invoice timeline is projected into contractual stage cards');
mustContain('app/View/pages/company_finance_invoices.php','Этапы оплаты','invoice register exposes payment stages as the primary payment column');
mustNotContain('app/View/pages/company_finance_invoices.php','Статус счёта','separate invoice status column removed');
mustContain('app/View/pages/company_finance_invoices.php',"$statusText = 'Оплачен';",'closed stage is represented only by current paid fact');
mustNotContain('app/View/pages/company_finance_invoices.php','Оплачен с просрочкой','closed stage no longer exposes historical lateness');
mustContain('app/View/pages/company_finance_invoices.php','invoice-stage-card.is-paid','fully paid stage card receives success background');
mustContain('app/View/pages/company_finance_invoices.php','Частично · просрочка','partial overdue stage is explicit');
mustContain('app/View/pages/company_finance_invoices.php',"$statusClass = 'badge badge-warning';",'partial overdue uses warning badge');
mustContain('app/View/pages/company_finance_invoices.php','is-overdue-remaining','overdue remaining amount uses dedicated danger styling');
mustContain('app/View/pages/company_finance_invoices.php','var(--danger-text)','overdue remaining amount uses system danger color');
mustContain('app/View/pages/company_finance_invoices.php','Ожидается событие','event-driven stage is not treated as overdue before event');
mustContain('app/View/pages/company_finance_invoices.php','Остаток:','partial stage keeps its remaining amount inside the same card');
mustNotContain('app/View/pages/company_finance_invoices.php','План / факт оплаты','old split plan/fact register column removed');
mustNotContain('app/View/pages/company_finance_invoices.php','Соблюдение сроков','old parallel compliance block removed');
mustContain('app/Http/Routes/company_bank_finance.php','/bank-transactions/{id}/classify','generic classification route remains');

echo "FINANCE_BANK_INVOICE_SETTLEMENT_ARCHITECTURE_OK\n";