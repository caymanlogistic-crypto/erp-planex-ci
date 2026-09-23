<?php

use App\Service\ContractorService;
use App\Service\RouteExecutorQuickCreateService;
use App\Service\RouteExecutorService;

$quickMode = RouteExecutorQuickCreateService::normalizeMode((string)($_GET['mode'] ?? ''));
$quickErrors = [];
$quickOld = [];
$quickFormError = null;
$quickContractors = [];
$quickStartAtDetails = $quickMode === RouteExecutorQuickCreateService::MODE_NEW_CONTRACTOR;

try {
    $companyId = (int)(getSessionCompanyId() ?? 0);
    if ($companyId <= 0) {
        throw new RuntimeException('Компания не найдена.');
    }
    $contractorService = new ContractorService($config, $db);
    $company = $contractorService->loadCompany($companyId);
    if (!$company || ($company['status'] ?? '') !== 'active') {
        throw new RuntimeException('Создание недоступно для неактивной компании.');
    }
    $localPdo = $contractorService->getLocalPdo($company);
    applyLocalMigrations($localPdo);
    if ($quickMode === RouteExecutorQuickCreateService::MODE_NEW_CONTRACTOR) {
        \App\Service\RegulatedDataScopeService::assertCanCreate('contractor');
    }
    \App\Service\RegulatedDataScopeService::assertCanCreate('driver');
    \App\Service\RegulatedDataScopeService::assertCanCreate('vehicle_unit');
    $quickContractors = RouteExecutorService::filterVisibleContractors(
        $localPdo,
        $localPdo->query("SELECT id,name,inn FROM contractors WHERE deleted_at IS NULL AND status='active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC)
    );
} catch (Throwable $e) {
    $quickFormError = $e->getMessage();
}

header('Content-Type: text/html; charset=utf-8');
require base_path('app/View/partials/company_route_executor_quick_create_form.php');
