<?php
/** @var VehicleSetService $service */
header('Content-Type: application/json; charset=utf-8');
try{$companyId=$service->getCompanyId();if($companyId<=0)throw new \RuntimeException('Компания не найдена.');
$company=$service->loadCompany($companyId);if(!$company||($company['status']??'')!=='active')throw new \RuntimeException('Компания недоступна.');
$localPdo=$service->getLocalPdo($company);$vs=$service->getVehicleSetById($localPdo,(int)$id);if(!$vs)throw new \RuntimeException('Транспорт не найден.');
$roleCode=$_SESSION['role_code']??'';$userId=(int)($_SESSION['user_id']??0);
if(!vehicleSetAllUnitsAccessible($localPdo,$vs))throw new \RuntimeException('Нет доступа ко всем транспортным единицам этого состава.');
$service->archiveVehicleSet($localPdo,(int)$id);
$displayName=$vs['set_type']??'#' . $id;$snapshot=json_encode($vs,JSON_UNESCAPED_UNICODE);$un=$_SESSION['user_name']??'';
try{$cp=$db->connection();\App\Service\AuditService::recordDeletion($cp,$company,'vehicle_set',(int)$id,'vehicle_sets',$displayName,$userId,$roleCode,$un,null,$snapshot);}catch(\Exception$ae){error_log('Audit failed: '.$ae->getMessage());}
echo json_encode(['success'=>true],JSON_UNESCAPED_UNICODE);}
catch(\Throwable $e){http_response_code(200);echo json_encode(['success'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);}
