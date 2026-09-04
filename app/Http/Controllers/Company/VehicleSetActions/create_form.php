<?php
/** @var VehicleSetService $service */
$pageTitle='Создать транспорт';$pageContext='Транспорт > Компания';
$companyId=$service->getCompanyId();$company=null;$success=false;$errors=[];$old=[];$formError=null;$createdVehicleSet=null;$docErrors=[];$uploadedDocs=[];$docTypes=[];
if($companyId<=0){ob_start();require base_path('app/View/pages/company_vehicle_sets_create.php');$content=ob_get_clean();require base_path('app/View/layouts/main.php');return;}
try{$company=$service->loadCompany($companyId);if(!$company){goto renderCreate;}$pageContext='Транспорт > Компания: '.$company['name'];
$localPdo=$service->getLocalPdo($company);\App\Service\RegulatedDataScopeService::assertCanCreate('vehicle_unit');$docTypes=$service->getDocTypes($localPdo);}catch(\Exception$e){$company=null;$formError='Ошибка: '.$e->getMessage();}
renderCreate: ob_start();require base_path('app/View/pages/company_vehicle_sets_create.php');$content=ob_get_clean();require base_path('app/View/layouts/main.php');
