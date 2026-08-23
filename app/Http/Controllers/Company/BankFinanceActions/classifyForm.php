<?php
requireRole(['company_owner']);
$companyId=(int)(getSessionCompanyId()??0);if($companyId<=0){http_response_code(400);echo '<div class="form-alert alert-error">Компания не найдена.</div>';return;}
try{
 $central=$db->connection();$s=$central->prepare("SELECT * FROM companies WHERE id=? AND status='active'");$s->execute([$companyId]);$company=$s->fetch(PDO::FETCH_ASSOC);if(!$company)throw new RuntimeException('Компания не найдена или неактивна.');
 $local=(new \App\Core\Database(companyDatabaseConfig($config,$company)))->connection();$tx=\App\Service\FinanceMatchingRuleService::fetchBankTransactionForClassification($local,(int)$bankTransactionId);if(!$tx)throw new RuntimeException('Банковская операция не найдена.');
 $direction=((float)($tx['credit_amount']??0)>0)?'INCOME':'EXPENSE';
 $cfu=\App\Service\FinanceMatchingRuleService::fetchCashFlowCenters($local,true);
 $dds=\App\Service\FinanceDdsCategoryService::fetchActiveForDirection($local,$direction);
 $ddsAllowedMap=\App\Service\FinanceStructureService::fetchAllowedMap($local,$direction);
 $bankAccounts=$local->query('SELECT id,account_number,bank_name FROM bank_accounts ORDER BY bank_name,account_number')->fetchAll(PDO::FETCH_ASSOC);
 $employeeUsers=\App\Service\FinanceEmployeePaymentService::fetchActiveEmployees($local,$central,$companyId);
 $employeeMovement=\App\Service\FinanceEmployeePaymentService::findByBankTransaction($local,(int)$bankTransactionId);
 require base_path('app/View/partials/company_bank_transaction_classify_form.php');
}catch(Throwable $e){http_response_code(422);echo '<div class="form-alert alert-error">'.e($e->getMessage()).'</div>';}
