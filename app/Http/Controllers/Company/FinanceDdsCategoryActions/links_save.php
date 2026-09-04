<?php
verifyCsrfRequest();
$companyId=(int)(getSessionCompanyId()??0);
try{
 if($companyId<=0)throw new RuntimeException('Компания не найдена.');
 $central=$db->connection();$s=$central->prepare("SELECT * FROM companies WHERE id=? AND status='active'");$s->execute([$companyId]);$company=$s->fetch(PDO::FETCH_ASSOC);if(!$company)throw new RuntimeException('Компания не найдена или неактивна.');
 $local=(new \App\Core\Database(companyDatabaseConfig($config,$company)))->connection();applyLocalMigrations($local);
 $cfuId=(int)($_POST['cash_flow_center_id']??0);$ids=$_POST['dds_category_ids']??[];if(!is_array($ids))$ids=[];
 \App\Service\FinanceStructureService::saveLinks($local,$cfuId,$ids);
 $_SESSION['finance_dds_success']='Состав статей ЦФУ сохранён.';
}catch(Throwable $e){$_SESSION['finance_dds_error']=$e->getMessage();}
redirect_to('/company/finance/settings/dds-categories');
