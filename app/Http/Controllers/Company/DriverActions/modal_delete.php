<?php
/** @var DriverService $service */
requireRole(['company_owner', 'senior_logist', 'logist']);
header('Content-Type: application/json; charset=utf-8');
$companyId=$service->getCompanyId();
if($companyId<=0){echo json_encode(['success'=>false,'error'=>'Компания не найдена.']);exit;}
try{$company=$service->loadCompany($companyId);if(!$company||$company['status']!=='active'){echo json_encode(['success'=>false,'error'=>'Компания не найдена или не активна.']);exit;}
$localPdo=$service->getLocalPdo($company);$driver=$service->getDriverById($localPdo,(int)$id);
if(!$driver){echo json_encode(['success'=>false,'error'=>'Водитель не найден.']);exit;}
$role=$_SESSION['role_code']??'';
if($service->checkDriverHasCrews($localPdo,(int)$id)){echo json_encode(['success'=>false,'error'=>'Водитель участвует в экипажах. Сначала удалите экипажи.']);exit;}
$uid=(int)($_SESSION['user_id']??0);$rl=(string)($_SESSION['role_code']??'');
$localPdo->prepare("UPDATE drivers SET deleted_at=NOW(),deleted_by_user_id=?,deleted_by_role=? WHERE id=?")->execute([$uid,$rl,(int)$id]);
$displayName=$driver['full_name']??'#'.$id;$snapshot=json_encode($driver,JSON_UNESCAPED_UNICODE);$un=$_SESSION['user_name']??'';
try{$cp=$db->connection();\App\Service\AuditService::recordDeletion($cp,$company,'driver',(int)$id,'drivers',$displayName,$uid,$rl,$un,null,$snapshot);}catch(\Exception$ae){error_log('Audit failed: '.$ae->getMessage());}
echo json_encode(['success'=>true]);exit;}
catch(\Exception $e){echo json_encode(['success'=>false,'error'=>'Ошибка: '.$e->getMessage()]);exit;}
