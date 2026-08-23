<?php
requireRole(['company_owner']);
verifyCsrfRequest();
$companyId=(int)(getSessionCompanyId()??0);
if($companyId<=0){$_SESSION['bank_finance_error']='Компания не найдена.';redirect_to('/company/finance/bank-accounts');return;}
try{
 $central=$db->connection();
 $s=$central->prepare("SELECT * FROM companies WHERE id=? AND status='active'");$s->execute([$companyId]);$company=$s->fetch(PDO::FETCH_ASSOC);if(!$company)throw new RuntimeException('Компания не найдена или неактивна.');
 $local=(new \App\Core\Database(companyDatabaseConfig($config,$company)))->connection();
 $tx=\App\Service\FinanceMatchingRuleService::fetchBankTransactionForClassification($local,(int)$bankTransactionId);if(!$tx)throw new RuntimeException('Банковская операция не найдена.');
 $mode=strtolower(trim((string)($_POST['distribution_mode']??'manual')));if(!in_array($mode,['manual','rule'],true))throw new InvalidArgumentException('Неизвестный режим разнесения.');
 $data=$_POST;
 $data['distribution_mode']=$mode;
 $action=(string)($data['action_type']??'categorize');
 if($action==='employee_cash_settlement'){
  $employee=\App\Service\FinanceEmployeePaymentService::resolveActiveEmployee($local,$central,$companyId,trim((string)($data['target_employee_ref']??'')));
  $data['target_employee_identity_type']=$employee['identity_type'];$data['target_employee_identity_id']=$employee['identity_id'];$data['target_employee_name_snapshot']=$employee['full_name'];$data['target_employee_role_snapshot']=$employee['role_code'];
 }
 $user=['id'=>$_SESSION['user_id']??0,'role'=>$_SESSION['role_code']??'company_owner'];
 if($mode==='rule'){
  $data['name']=trim((string)($data['name']??''));if($data['name']==='')$data['name']='Правило из банковской операции #'.(int)$bankTransactionId;
  $data['target_cash_flow_center_id']=$data['target_cash_flow_center_id']??$data['cash_flow_center_id']??null;
  $data['target_dds_category_id']=$data['target_dds_category_id']??$data['dds_category_id']??null;
  $data['auto_apply']=1;
  $ruleId=\App\Service\FinanceMatchingRuleService::createRule($local,$data,$user);
  $opStmt=$local->prepare("SELECT id FROM finance_operations WHERE bank_transaction_id=? AND status!='CANCELLED' ORDER BY id LIMIT 1");$opStmt->execute([(int)$bankTransactionId]);$opId=(int)($opStmt->fetchColumn()?:0);
  $applied=$opId>0?\App\Service\FinanceMatchingRuleService::applyAutoMatchToOperation($local,$opId):['matched'=>false,'reason'=>'Финансовая операция не найдена.'];
  $_SESSION['bank_finance_success']='Правило #'.$ruleId.' создано в общем реестре.'.(!empty($applied['matched'])?' Текущая операция обработана rule engine.':'');
 }else{
  $data['cash_flow_center_id']=$data['cash_flow_center_id']??$data['target_cash_flow_center_id']??null;
  $data['dds_category_id']=$data['dds_category_id']??$data['target_dds_category_id']??null;
  $result=\App\Service\FinanceMatchingRuleService::manualClassifyBankTransaction($local,(int)$bankTransactionId,$data,$user);
  $_SESSION['bank_finance_success']=($result['action_type']??'categorize')==='employee_cash_settlement'?'Операция вручную зафиксирована как взаиморасчёт с сотрудником.':'Операция сохранена как ручное исключение.';
 }
}catch(Throwable $e){$_SESSION['bank_finance_error']=$e->getMessage();}
redirect_to('/company/finance/bank-accounts');
