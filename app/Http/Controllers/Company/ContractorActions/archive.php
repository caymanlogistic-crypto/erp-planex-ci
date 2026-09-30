<?php
/** @var ContractorService $service */
requireRole(['company_owner', 'senior_logist', 'logist']);
$pageTitle = 'Перевозчик';
$pageContext = 'Перевозчики › Компания';

$companyId = $service->getCompanyId();
$archiveError = null;

if ($companyId <= 0) {
    $company = null;
    $contractor = null;
    $dbError = null;

    ob_start();
    require base_path('app/View/pages/company_contractor_view.php');
    $content = ob_get_clean();
    require base_path('app/View/layouts/main.php');
    return;
}

try {
    $company = $service->loadCompany($companyId);

    if (!$company) {
        $company = null;
        $contractor = null;
        $dbError = null;

        ob_start();
        require base_path('app/View/pages/company_contractor_view.php');
        $content = ob_get_clean();
        require base_path('app/View/layouts/main.php');
        return;
    }

    $pageContext = 'Перевозчики › Компания: ' . $company['name'];

    if ($company['status'] !== 'active') {
        $contractor = null;
        $dbError = null;

        ob_start();
        require base_path('app/View/pages/company_contractor_view.php');
        $content = ob_get_clean();
        require base_path('app/View/layouts/main.php');
        return;
    }

    $localPdo = $service->getLocalPdo($company);

    $contractor = $service->getContractorById($localPdo, (int) $id);

    if (!$contractor) {
        $contractor = null;
        $dbError = null;

        ob_start();
        require base_path('app/View/pages/company_contractor_view.php');
        $content = ob_get_clean();
        require base_path('app/View/layouts/main.php');
        return;
    }

    $pageTitle = 'Перевозчик: ' . $contractor['name'];

    try {
        $localPdo->query("SELECT 1 FROM crews LIMIT 1")->fetch();
    } catch (\Exception $e) {
        $localPdo->exec(file_get_contents(base_path('database/migrations-local/006_create_company_crews.sql')));
    }

    $crewCheck = $localPdo->prepare('SELECT COUNT(*) FROM crews WHERE contractor_id = ? AND deleted_at IS NULL');
    $crewCheck->execute([(int) $id]);
    if ($crewCheck->fetchColumn() > 0) {
        $archiveError = 'Перевозчик участвует в экипажах. Сначала удалите экипажи.';
        $dbError = null;

        ob_start();
        require base_path('app/View/pages/company_contractor_view.php');
        $content = ob_get_clean();
        require base_path('app/View/layouts/main.php');
        return;
    }

    $service->archiveContractor($localPdo, (int) $id);

    $displayName = $contractor['name'] ?? '#' . $id;
    $snapshot = json_encode($contractor, JSON_UNESCAPED_UNICODE);
    $userId = (int)($_SESSION['user_id'] ?? 0);
    $role = (string)($_SESSION['role_code'] ?? '');
    $userName = $_SESSION['user_name'] ?? '';
    try {
        $centralPdo = $db->connection();
        \App\Service\AuditService::recordDeletion($centralPdo, $company, 'contractor', (int)$id, 'contractors', $displayName, $userId, $role, $userName, null, $snapshot);
    } catch (\Exception $auditEx) {
        error_log('Audit record failed: ' . $auditEx->getMessage());
    }

    header('Location: /company/contractors');
    exit;
} catch (\Exception $e) {
    $company = $company ?? null;
    $contractor = null;
    $dbError = 'Ошибка архивирования: ' . $e->getMessage();

    ob_start();
    require base_path('app/View/pages/company_contractor_view.php');
    $content = ob_get_clean();
    require base_path('app/View/layouts/main.php');
}
