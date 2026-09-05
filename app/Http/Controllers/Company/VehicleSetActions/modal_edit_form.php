<?php
/** @var VehicleSetService $service */
$companyId=$service->getCompanyId();if($companyId<=0){http_response_code(404);echo'<div class="notice warn">Компания не найдена.</div>';exit;}
try{$company=$service->loadCompany($companyId);if(!$company||($company['status']??'')!=='active'){http_response_code(404);echo'<div class="notice warn">Компания не найдена или неактивна.</div>';exit;}
$localPdo=$service->getLocalPdo($company);$vs=$service->getVehicleSetById($localPdo,(int)$id);if(!$vs){http_response_code(404);echo'<div class="notice warn">Транспорт не найден.</div>';exit;}
$canEdit=vehicleSetAllUnitsAccessible($localPdo,$vs);
if(!$canEdit){http_response_code(403);echo'<div class="notice warn">У вас нет права редактировать эту запись.</div>';exit;}
$unitIds=array_values(array_filter([(int)($vs['primary_vehicle_unit_id']??0),(int)($vs['secondary_vehicle_unit_id']??0)]));$unitsByRole=['primary'=>[],'secondary'=>[]];
if(!empty($unitIds)){$units=$service->getUnitsByIds($localPdo,$unitIds);foreach($units as $unit){$uId=(int)($unit['id']??0);if($uId===(int)($vs['primary_vehicle_unit_id']??0))$unitsByRole['primary']=$unit;elseif($uId===(int)($vs['secondary_vehicle_unit_id']??0))$unitsByRole['secondary']=$unit;}}
$docsByRole=['primary'=>[],'secondary'=>[]];if(!empty($unitIds)){$docs=$service->getDocsForUnits($localPdo,$unitIds);foreach($docs as $doc){$eId=(int)($doc['entity_id']??0);if($eId===(int)($vs['primary_vehicle_unit_id']??0))$docsByRole['primary'][]=$doc;elseif($eId===(int)($vs['secondary_vehicle_unit_id']??0))$docsByRole['secondary'][]=$doc;}}
$vehicleSet=$vs;
$old=['set_type'=>$vs['set_type']??'single','status'=>$vs['status']??'active','comments'=>$vs['comments']??'','units'=>['primary'=>$unitsByRole['primary']??[],'secondary'=>$unitsByRole['secondary']??[]]];
$errors=[];$formError=null;header('Content-Type: text/html; charset=utf-8');require base_path('app/View/partials/company_vehicle_set_modal_edit.php');exit;}
catch(\Throwable $e){http_response_code(500);echo'<div class="notice warn">Ошибка загрузки: '.e($e->getMessage()).'</div>';exit;}
