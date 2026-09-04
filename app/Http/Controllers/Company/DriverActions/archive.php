<?php
/** @var DriverService $service */
requireRole(['company_owner', 'senior_logist', 'logist']);
$pageTitle='Водитель';$pageContext='Водители › Компания';$companyId=$service->getCompanyId();$archiveError=null;
if($companyId<=0){$company=null;$driver=null;$dbError=null;ob_start();require base_path('app/View/pages/company_driver_view.php');$content=ob_get_clean();require base_path('app/View/layouts/main.php');return;}
try{$company=$service->loadCompany($companyId);if(!$company){$company=null;$driver=null;$dbError=null;ob_start();require base_path('app/View/pages/company_driver_view.php');$content=ob_get_clean();require base_path('app/View/layouts/main.php');return;}
$pageContext='Водители › Компания: '.$company['name'];if($company['status']!=='active'){$driver=null;$dbError=null;ob_start();require base_path('app/View/pages/company_driver_view.php');$content=ob_get_clean();require base_path('app/View/layouts/main.php');return;}
$localPdo=$service->getLocalPdo($company);$driver=$service->getDriverById($localPdo,(int)$id);
if(!$driver){$driver=null;$dbError=null;ob_start();require base_path('app/View/pages/company_driver_view.php');$content=ob_get_clean();require base_path('app/View/layouts/main.php');return;}
$pageTitle='Водитель: '.$driver['full_name'];
if($service->checkDriverHasCrews($localPdo,(int)$id)){$archiveError='Водитель участвует в экипажах. Сначала удалите экипажи.';$dbError=null;ob_start();require base_path('app/View/pages/company_driver_view.php');$content=ob_get_clean();require base_path('app/View/layouts/main.php');return;}
$service->archiveDriver($localPdo,(int)$id);
$displayName=$driver['full_name']??'#'.$id;$snapshot=json_encode($driver,JSON_UNESCAPED_UNICODE);$uid=(int)($_SESSION['user_id']??0);$rl=(string)($_SESSION['role_code']??'');$un=$_SESSION['user_name']??'';try{$cp=$db->connection();\App\Service\AuditService::recordDeletion($cp,$company,'driver',(int)$id,'drivers',$displayName,$uid,$rl,$un,null,$snapshot);}catch(\Exception$ae){error_log('Audit failed: '.$ae->getMessage());}
header('Location: /company/drivers');exit;}
catch(\Exception $e){$company=$company??null;$driver=null;$dbError='Ошибка архивирования: '.$e->getMessage();ob_start();require base_path('app/View/pages/company_driver_view.php');$content=ob_get_clean();require base_path('app/View/layouts/main.php');}
