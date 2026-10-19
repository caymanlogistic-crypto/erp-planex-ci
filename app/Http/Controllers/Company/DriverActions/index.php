<?php
/** @var DriverService $service */
requireRole(['company_owner', 'senior_logist', 'logist']);
$pageTitle = 'Водители';
$pageStylesheets[] = '/assets/css/registry-components.css';
$pageStylesheets[] = '/assets/css/drivers-registry.css';
$pageScripts[] = '/assets/js/table-reference-select.js';
$pageScripts[] = '/assets/js/registry-components.js';
$pageContext = 'Водители › Компания';
$companyId = $service->getCompanyId();
if ($companyId <= 0) { $company=null; $drivers=[]; $dbError=null; ob_start(); require base_path('app/View/pages/company_drivers.php'); $content=ob_get_clean(); require base_path('app/View/layouts/main.php'); return; }
try {
    $company=$service->loadCompany($companyId);
    if(!$company){$company=null;$drivers=[];$dbError=null;ob_start();require base_path('app/View/pages/company_drivers.php');$content=ob_get_clean();require base_path('app/View/layouts/main.php');return;}
    $pageContext='Водители › Компания: '.$company['name'];
    //$topbarCrumbs=[['label'=>mb_strtoupper($company['name']),'url'=>'/company/dashboard'],['label'=>'Подрядчики','url'=>null],['label'=>'Список водителей','url'=>null]];
    if($company['status']!=='active'){$drivers=[];$dbError=null;ob_start();require base_path('app/View/pages/company_drivers.php');$content=ob_get_clean();require base_path('app/View/layouts/main.php');return;}
    $localPdo=$service->getLocalPdo($company);
    $service->getDocTypes($localPdo);
    $isLogist=($_SESSION['role_code']??'')==='logist';
    $drivers=$service->listDrivers($localPdo,$isLogist,(int)$_SESSION['user_id']);
    $dbError=null;
} catch(\Exception $e){$company=$company??null;$drivers=[];$dbError='Не удалось подключиться к базе данных компании.';}
ob_start();require base_path('app/View/pages/company_drivers.php');$content=ob_get_clean();require base_path('app/View/layouts/main.php');
