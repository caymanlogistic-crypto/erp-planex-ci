<?php
$companyId=(int)(getSessionCompanyId()??0);$cfuId=(int)($_GET['cfu_id']??0);
if($companyId<=0){echo '<div class="form-alert alert-error">Компания не найдена.</div>';return;}
try{
 $pdo=$db->connection();$stmt=$pdo->prepare("SELECT * FROM companies WHERE id=? AND status='active'");$stmt->execute([$companyId]);$company=$stmt->fetch(PDO::FETCH_ASSOC);if(!$company)throw new RuntimeException('Компания не найдена или неактивна.');
 $local=(new \App\Core\Database(companyDatabaseConfig($config,$company)))->connection();applyLocalMigrations($local);
 if($cfuId<=0)throw new InvalidArgumentException('Сначала выберите ЦФУ.');
 $centers=\App\Service\FinanceMatchingRuleService::fetchCashFlowCenters($local,false);$found=false;foreach($centers as $center){if((int)$center['id']===$cfuId){$found=true;break;}}if(!$found)throw new InvalidArgumentException('ЦФУ не найден.');
 require base_path('app/View/partials/company_dds_category_form.php');
}catch(Throwable $e){echo '<div class="form-alert alert-error">'.e($e->getMessage()).'</div>';}
