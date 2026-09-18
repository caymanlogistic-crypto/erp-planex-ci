<?php
$companyId=(int)(getSessionCompanyId()??0);if($companyId<=0){http_response_code(400);echo '<div class="form-alert alert-error">Компания не найдена.</div>';return;}
try{
 $central=$db->connection();$s=$central->prepare("SELECT * FROM companies WHERE id=? AND status='active'");$s->execute([$companyId]);$company=$s->fetch(PDO::FETCH_ASSOC);if(!$company)throw new RuntimeException('Компания не найдена или неактивна.');
 $local=(new \App\Core\Database(companyDatabaseConfig($config,$company)))->connection();$tx=\App\Service\FinanceMatchingRuleService::fetchBankTransactionForClassification($local,(int)$bankTransactionId);if(!$tx)throw new RuntimeException('Банковская операция не найдена.');
 $direction=((float)($tx['credit_amount']??0)>0)?'INCOME':'EXPENSE';
 $cfu=\App\Service\FinanceMatchingRuleService::fetchCashFlowCenters($local,true);
 $dds=array_values(array_filter(\App\Service\FinanceDdsCategoryService::fetchCategories($local),static fn(array $row):bool=>!empty($row['is_active'])));
 $ddsAllowedMap=[];foreach(\App\Service\FinanceStructureService::fetchStructure($local,true) as $center){$ddsAllowedMap[(int)$center['id']]=array_values(array_map(static fn(array $article):int=>(int)$article['dds_category_id'],array_filter($center['articles']??[],static fn(array $article):bool=>!empty($article['is_active'])&&!empty($article['dds_is_active']))));}
 $bankAccounts=$local->query('SELECT id,account_number,bank_name FROM bank_accounts ORDER BY bank_name,account_number')->fetchAll(PDO::FETCH_ASSOC);
 $employeeUsers=\App\Service\FinanceEmployeePaymentService::fetchActiveEmployees($local,$central,$companyId);
 $employeeMovement=\App\Service\FinanceEmployeePaymentService::findByBankTransaction($local,(int)$bankTransactionId);
 $companyFundedAttribution=\App\Service\FinanceEmployeeCompanyFundedExpenseService::bankAttributions($local,(int)$bankTransactionId)[(int)$bankTransactionId]??null;
 $classificationRule=null;$classificationRuleId=(int)($tx['classification_rule_id']??0);if($classificationRuleId>0){$classificationRule=\App\Service\FinanceMatchingRuleService::findRule($local,$classificationRuleId);}
 require base_path('app/View/partials/company_bank_transaction_classify_form.php');
}catch(Throwable $e){http_response_code(422);echo '<div class="form-alert alert-error">'.e($e->getMessage()).'</div>';}
