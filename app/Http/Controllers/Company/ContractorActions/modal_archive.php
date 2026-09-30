<?php
/** @var ContractorService $service */
requireRole(['company_owner', 'senior_logist', 'logist']);
header('Content-Type: application/json; charset=UTF-8');

try {
    $companyId = $service->getCompanyId();
    if ($companyId <= 0) {
        throw new \RuntimeException('Компания не найдена.');
    }

    $company = $service->loadCompany($companyId);
    if (!$company || ($company['status'] ?? '') !== 'active') {
        throw new \RuntimeException('Компания недоступна.');
    }

    $localPdo = $service->getLocalPdo($company);

    try {
        $localPdo->query("SELECT 1 FROM contractors LIMIT 1")->fetch();
    } catch (\Exception $e) {
        $localPdo->exec(file_get_contents(base_path('database/migrations-local/003_create_company_contractors.sql')));
    }
    try {
        $localPdo->query("SELECT created_by_user_id FROM contractors LIMIT 1")->fetch();
    } catch (\Exception $e) {
        $localPdo->exec("ALTER TABLE contractors ADD COLUMN created_by_user_id INT UNSIGNED DEFAULT NULL, ADD COLUMN created_by_role VARCHAR(20) DEFAULT NULL");
    }
    try {
        $localPdo->query("SELECT 1 FROM crews LIMIT 1")->fetch();
    } catch (\Exception $e) {
        $localPdo->exec(file_get_contents(base_path('database/migrations-local/006_create_company_crews.sql')));
    }

    $contractor = $service->getContractorById($localPdo, (int) $id);
    if (!$contractor) {
        throw new \RuntimeException('Перевозчик не найден.');
    }

    $roleCode = (string) ($_SESSION['role_code'] ?? '');
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if ($roleCode === 'logist' && (int) ($contractor['created_by_user_id'] ?? 0) !== $userId) {
        throw new \RuntimeException('Логист может архивировать только записи, созданные им самим.');
    }

    $crewCheck = $localPdo->prepare("SELECT COUNT(*) FROM crews WHERE contractor_id = ? AND deleted_at IS NULL");
    $crewCheck->execute([(int) $id]);
    if ((int) $crewCheck->fetchColumn() > 0) {
        throw new \RuntimeException('Перевозчик участвует в экипажах. Сначала удалите экипажи.');
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

    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    http_response_code(200);
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
