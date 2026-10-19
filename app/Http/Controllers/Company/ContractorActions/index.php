<?php
/** @var ContractorService $service */
requireRole(['company_owner', 'senior_logist', 'logist']);
$pageTitle = 'Перевозчики';
$pageStylesheets[] = '/assets/css/registry-components.css';
$pageStylesheets[] = '/assets/css/contractors-registry.css';
$pageScripts[] = '/assets/js/table-reference-select.js';
$pageScripts[] = '/assets/js/registry-components.js';
$pageContext = 'Перевозчики › Компания';

$companyId = $service->getCompanyId();

if ($companyId <= 0) {
    $company = null;
    $contractors = [];
    $dbError = null;

    ob_start();
    require base_path('app/View/pages/company_contractors.php');
    $content = ob_get_clean();
    require base_path('app/View/layouts/main.php');
    return;
}

try {
    $company = $service->loadCompany($companyId);

    if (!$company) {
        $company = null;
        $contractors = [];
        $dbError = null;

        ob_start();
        require base_path('app/View/pages/company_contractors.php');
        $content = ob_get_clean();
        require base_path('app/View/layouts/main.php');
        return;
    }

    $pageContext = 'Перевозчики › Компания: ' . $company['name'];
    //$topbarCrumbs = [
    //    ['label' => mb_strtoupper($company['name']), 'url' => '/company/dashboard'],
    //    ['label' => 'Подрядчики', 'url' => null],
    //    ['label' => 'Список перевозчиков', 'url' => null],
    //];

    if ($company['status'] !== 'active') {
        $contractors = [];
        $dbError = null;

        ob_start();
        require base_path('app/View/pages/company_contractors.php');
        $content = ob_get_clean();
        require base_path('app/View/layouts/main.php');
        return;
    }

    $localPdo = $service->getLocalPdo($company);

    $isLogist = ($_SESSION['role_code'] ?? '') === 'logist';
    $contractors = $service->listContractors($localPdo, $isLogist, (int)$_SESSION['user_id']);
    $dbError = null;
} catch (\Exception $e) {
    $company = $company ?? null;
    $contractors = [];
    $dbError = 'Не удалось подключиться к базе данных компании.';
}

ob_start();
require base_path('app/View/pages/company_contractors.php');
$content = ob_get_clean();
require base_path('app/View/layouts/main.php');
