<?php

verifyCsrfRequest();
$companyId=(int)(getSessionCompanyId()??0);
if($companyId<=0){$_SESSION['bank_finance_error']='Компания не найдена.';redirect_to('/company/finance/bank-accounts');}

try{
    $central=$db->connection();
    $stmt=$central->prepare("SELECT * FROM companies WHERE id=? AND status='active'");
    $stmt->execute([$companyId]);
    $company=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!$company)throw new RuntimeException('Компания не найдена или неактивна.');
    $local=(new \App\Core\Database(companyDatabaseConfig($config,$company)))->connection();

    $invoiceIds=is_array($_POST['invoice_id']??null)?$_POST['invoice_id']:[];
    $amounts=is_array($_POST['invoice_amount']??null)?$_POST['invoice_amount']:[];
    $rows=[];
    foreach($invoiceIds as $idx=>$invoiceId){
        $rows[]=['invoice_id'=>(int)$invoiceId,'amount'=>(string)($amounts[$idx]??'')];
    }
    $result=\App\Service\FinanceBankInvoiceSettlementService::manualAllocate(
        $local,
        (int)$bankTransactionId,
        $rows,
        $_SESSION['user']??[]
    );
    $settlementSync=\App\Service\FinanceSettlementStateService::syncPersistedStatuses($local);
    $_SESSION['bank_finance_success']=sprintf(
        'Платёж вручную распределён по счетам: %s ₽. Остаток банковской операции: %s ₽. Обновлено счетов: %d.',
        number_format((float)$result['allocated_amount'],2,',',' '),
        number_format((float)$result['remaining_amount'],2,',',' '),
        (int)$settlementSync['invoices']
    );
}catch(Throwable $e){
    error_log('Bank invoice settlement submit error: '.$e->getMessage());
    $_SESSION['bank_finance_error']='Не удалось распределить платёж по счетам: '.$e->getMessage();
}
redirect_to('/company/finance/bank-accounts');