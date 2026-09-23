<?php

use App\Service\ContractorService;
use App\Service\DriverService;
use App\Service\RouteExecutorQuickCreateService;
use App\Service\RouteExecutorQuickCreateValidationException;
use App\Service\RouteExecutorService;

$quickMode = RouteExecutorQuickCreateService::normalizeMode((string)($_POST['quick_mode'] ?? ''));
$quickErrors = [];
$quickOld = $_POST;
$quickFormError = null;
$quickContractors = [];
$quickStartAtDetails = true;

try {
    $companyId = (int)(getSessionCompanyId() ?? 0);
    if ($companyId <= 0) {
        throw new RuntimeException('Компания не найдена.');
    }
    $contractorService = new ContractorService($config, $db);
    $driverService = new DriverService($config, $db);
    $company = $contractorService->loadCompany($companyId);
    if (!$company || ($company['status'] ?? '') !== 'active') {
        throw new RuntimeException('Создание недоступно для неактивной компании.');
    }
    $localPdo = $contractorService->getLocalPdo($company);
    applyLocalMigrations($localPdo);
    $quickContractors = RouteExecutorService::filterVisibleContractors(
        $localPdo,
        $localPdo->query("SELECT id,name,inn FROM contractors WHERE deleted_at IS NULL AND status='active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC)
    );

    if (isPostTruncated()) {
        throw new RuntimeException('Общий размер отправки превышает серверный лимит. Уменьшите количество файлов.');
    }
    $totalSizeError = validateTotalUploadSize();
    if ($totalSizeError !== '') {
        throw new RuntimeException($totalSizeError);
    }

    $result = RouteExecutorQuickCreateService::create(
        $localPdo,
        $companyId,
        $quickOld,
        $_FILES,
        $quickMode,
        (int)($_SESSION['user_id'] ?? 0),
        (string)($_SESSION['role_code'] ?? ''),
        $contractorService,
        $driverService
    );
    header('Content-Type: text/html; charset=utf-8');
    echo '<div data-route-executor-quick-create-success="1" data-crew-id="' . (int)$result['crew_id'] . '"></div>';
    return;
} catch (RouteExecutorQuickCreateValidationException $e) {
    $quickErrors = $e->errors();
    $quickOld = $e->input();
    $quickFormError = $e->getMessage();
} catch (Throwable $e) {
    $quickFormError = 'Не удалось создать исполнителя рейса: ' . $e->getMessage();
}

header('Content-Type: text/html; charset=utf-8');
require base_path('app/View/partials/company_route_executor_quick_create_form.php');
