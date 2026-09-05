<?php
verifyCsrfRequest();
$companyId=(int)(getSessionCompanyId()??0);
try{
 if($companyId<=0)throw new RuntimeException('Компания не найдена.');
 $central=$db->connection();$s=$central->prepare("SELECT * FROM companies WHERE id=? AND status='active'");$s->execute([$companyId]);$company=$s->fetch(PDO::FETCH_ASSOC);if(!$company)throw new RuntimeException('Компания не найдена или неактивна.');
 $local=(new \App\Core\Database(companyDatabaseConfig($config,$company)))->connection();applyLocalMigrations($local);
 $id=\App\Service\FinanceMatchingRuleService::saveCashFlowCenter($local,$_POST,['id'=>$_SESSION['user_id']??null,'role'=>$_SESSION['role_code']??null]);
 $_SESSION['finance_dds_success']='ЦФУ сохранён.';
}catch(Throwable $e){$_SESSION['finance_dds_error']=$e->getMessage();}
redirect_to('/company/finance/settings/dds-categories');
