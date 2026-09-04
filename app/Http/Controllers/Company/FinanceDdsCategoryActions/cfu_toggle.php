<?php
verifyCsrfRequest();
$companyId=(int)(getSessionCompanyId()??0);
try{
 if($companyId<=0)throw new RuntimeException('Компания не найдена.');
 $central=$db->connection();$s=$central->prepare("SELECT * FROM companies WHERE id=? AND status='active'");$s->execute([$companyId]);$company=$s->fetch(PDO::FETCH_ASSOC);if(!$company)throw new RuntimeException('Компания не найдена или неактивна.');
 $local=(new \App\Core\Database(companyDatabaseConfig($config,$company)))->connection();applyLocalMigrations($local);
 $id=(int)($_POST['id']??0);$active=!empty($_POST['active']);\App\Service\FinanceMatchingRuleService::setCashFlowCenterActive($local,$id,$active,['id'=>$_SESSION['user_id']??null,'role'=>$_SESSION['role_code']??null]);
 $_SESSION['finance_dds_success']=$active?'ЦФУ восстановлен.':'ЦФУ архивирован.';
}catch(Throwable $e){$_SESSION['finance_dds_error']=$e->getMessage();}
redirect_to('/company/finance/settings/dds-categories');
