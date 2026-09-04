<?php
verifyCsrfRequest();
$companyId=(int)(getSessionCompanyId()??0);
try{
 if($companyId<=0)throw new RuntimeException('Компания не найдена.');
 $pdo=$db->connection();$stmt=$pdo->prepare("SELECT * FROM companies WHERE id=? AND status='active'");$stmt->execute([$companyId]);$company=$stmt->fetch(PDO::FETCH_ASSOC);if(!$company)throw new RuntimeException('Компания не найдена или неактивна.');
 $local=(new \App\Core\Database(companyDatabaseConfig($config,$company)))->connection();applyLocalMigrations($local);
 $cfuId=(int)($_POST['cfu_id']??0);if($cfuId<=0)throw new InvalidArgumentException('ЦФУ не указан.');
 $user=['id'=>$_SESSION['user_id']??null,'role'=>$_SESSION['role_code']??null];
 $local->beginTransaction();try{$articleId=\App\Service\FinanceDdsCategoryService::createCategory($local,$_POST,$user);\App\Service\FinanceStructureService::link($local,$cfuId,$articleId,true,(int)($_POST['sort_order']??100));$local->commit();}catch(Throwable $e){if($local->inTransaction())$local->rollBack();throw $e;}
 $_SESSION['finance_dds_success']='Статья ДДС создана и добавлена в ЦФУ.';
}catch(Throwable $e){$_SESSION['finance_dds_error']=$e->getMessage();}
redirect_to('/company/finance/settings/dds-categories');
