<?php

$companyId=(int)(getSessionCompanyId()??0);
if($companyId<=0){http_response_code(400);echo '<div class="form-alert alert-error">Компания не найдена.</div>';return;}

try{
    $central=$db->connection();
    $stmt=$central->prepare("SELECT * FROM companies WHERE id=? AND status='active'");
    $stmt->execute([$companyId]);
    $company=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!$company)throw new RuntimeException('Компания не найдена или неактивна.');
    $local=(new \App\Core\Database(companyDatabaseConfig($config,$company)))->connection();
    $settlement=\App\Service\FinanceBankInvoiceSettlementService::fetchContext($local,(int)$bankTransactionId);
    if($settlement===null){
        http_response_code(404);
        echo '<div data-bank-settlement-unavailable>Для этой операции используется стандартное разнесение.</div>';
        return;
    }
    require base_path('app/View/partials/company_bank_invoice_settlement_form.php');
}catch(Throwable $e){
    error_log('Bank invoice settlement form error: '.$e->getMessage());
    http_response_code(422);
    echo '<div class="form-alert alert-error">'.e($e->getMessage()).'</div>';
}
