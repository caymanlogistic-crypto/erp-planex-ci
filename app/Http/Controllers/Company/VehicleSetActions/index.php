<?php
/** @var VehicleSetService $service */
$pageTitle='Транспорт';$pageContext='Транспорт › Компания';
$companyId=$service->getCompanyId();
if($companyId<=0){$company=null;$vehicleSets=[];$dbError=null;ob_start();require base_path('app/View/pages/company_vehicle_sets.php');$content=ob_get_clean();require base_path('app/View/layouts/main.php');return;}
try{$company=$service->loadCompany($companyId);if(!$company){$company=null;$vehicleSets=[];$dbError=null;ob_start();require base_path('app/View/pages/company_vehicle_sets.php');$content=ob_get_clean();require base_path('app/View/layouts/main.php');return;}
$pageContext='Транспорт › Компания: '.$company['name'];
//$topbarCrumbs=[['label'=>mb_strtoupper($company['name']),'url'=>'/company/dashboard'],['label'=>'Подрядчики','url'=>null],['label'=>'Список транспорта','url'=>null]];
if($company['status']!=='active'){$vehicleSets=[];$dbError=null;ob_start();require base_path('app/View/pages/company_vehicle_sets.php');$content=ob_get_clean();require base_path('app/View/layouts/main.php');return;}
$localPdo=$service->getLocalPdo($company);
$vehicleSets=$service->listVehicleSets($localPdo,false,0);
$vehicleSets=array_values(array_filter(array_map(static function(array $vehicleSet)use($localPdo):?array{
    $allowedUnitIds=vehicleSetAccessibleUnitIds($localPdo,$vehicleSet);
    return $allowedUnitIds===[]?null:redactVehicleSetUnitColumns($vehicleSet,$allowedUnitIds);
},$vehicleSets)));
$dbError=null;}catch(\Exception $e){$company=$company??null;$vehicleSets=[];$dbError='Не удалось подключиться к базе данных компании.';}
ob_start();require base_path('app/View/pages/company_vehicle_sets.php');$content=ob_get_clean();require base_path('app/View/layouts/main.php');
