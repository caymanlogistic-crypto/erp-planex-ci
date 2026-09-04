<?php
$pageTitle='Правила разнесения';
$pageContext='Финансы › Выписки со счёта › Правила разнесения';
$companyId=(int)(getSessionCompanyId()??0);
$company=null;$rules=[];$bankAccounts=[];$ddsCategories=[];$cashFlowCenters=[];$cashAccounts=[];$dbError=null;
$successFlash=$_SESSION['finance_matching_rules_success']??null;
$errorFlash=$_SESSION['finance_matching_rules_error']??null;
unset($_SESSION['finance_matching_rules_success'],$_SESSION['finance_matching_rules_error']);
if($companyId>0){
 try{
  $central=$db->connection();
  $s=$central->prepare('SELECT * FROM companies WHERE id=?');
  $s->execute([$companyId]);
  $company=$s->fetch(PDO::FETCH_ASSOC)?:null;
  if($company&&($company['status']??'')==='active'){
   $local=(new \App\Core\Database(companyDatabaseConfig($config,$company)))->connection();
   $rules=\App\Service\FinanceMatchingRuleService::fetchRules($local);
   $bankAccounts=$local->query('SELECT id,account_number,bank_name FROM bank_accounts ORDER BY bank_name,account_number')->fetchAll(PDO::FETCH_ASSOC);
   $ddsCategories=\App\Service\FinanceDdsCategoryService::fetchCategories($local);
   $cashFlowCenters=\App\Service\FinanceMatchingRuleService::fetchCashFlowCenters($local,false);
   $cashAccounts=\App\Service\FinanceCashService::fetchMoneyAccounts($local,'CASH',true);
  }
 }catch(Throwable $e){
  $dbError='Ошибка при загрузке данных: '.$e->getMessage();
 }
}
ob_start();
require base_path('app/View/pages/company_finance_matching_rules.php');
$content=ob_get_clean();
require base_path('app/View/layouts/main.php');
