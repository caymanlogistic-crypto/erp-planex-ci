<?php
verifyCsrfRequest();
$companyId=(int)(getSessionCompanyId()??0);
$id=(int)($_POST['id']??0);
try{
 $central=$db->connection();
 $s=$central->prepare("SELECT * FROM companies WHERE id=? AND status='active'");
 $s->execute([$companyId]);
 $company=$s->fetch(PDO::FETCH_ASSOC);
 if(!$company||$id<=0)throw new RuntimeException('Правило или компания не найдены.');
 $local=(new \App\Core\Database(companyDatabaseConfig($config,$company)))->connection();
 $result=\App\Service\FinanceMatchingRuleService::deleteRule($local,$id,['id'=>$_SESSION['user_id']??0,'role'=>$_SESSION['role_code']??'company_owner']);
 $reverted=(int)($result['bank_transactions']??0);
 $_SESSION['finance_matching_rules_success']=$reverted>0
  ? 'Правило удалено. Автоматическое разнесение отменено для операций: '.$reverted.'.'
  : 'Правило удалено. Связанных автоматических разнесений не было.';
}catch(Throwable $e){
 $_SESSION['finance_matching_rules_error']=$e->getMessage();
}
redirect_to('/company/finance/settings/matching-rules');
