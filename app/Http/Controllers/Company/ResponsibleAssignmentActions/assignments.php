<?php

use App\Core\Database;
use App\Service\AccessControlService;
use App\Service\ResponsibleAssignmentService;

if (!AccessControlService::isEffectiveSystemOwner()) {
    jsonResponse([
        'success' => false,
        'error' => 'forbidden',
        'message' => 'Просматривать закрепления может только Руководитель.',
    ], 403);
    return;
}

$companyId = (int) (getSessionCompanyId() ?? 0);
$membershipId = filter_var(
    $_GET['membership_id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);
if ($companyId <= 0) {
    jsonResponse([
        'success' => false,
        'error' => 'company_context_required',
        'message' => 'Компания не определена.',
    ], 403);
    return;
}
if ($membershipId === false || $membershipId === null) {
    jsonResponse([
        'success' => false,
        'error' => 'invalid_membership_id',
        'message' => 'Некорректный идентификатор сотрудника.',
    ], 422);
    return;
}

try {
    $central = $db->connection();
    $companyStmt = $central->prepare("SELECT * FROM companies WHERE id = ? AND status = 'active'");
    $companyStmt->execute([$companyId]);
    $company = $companyStmt->fetch(PDO::FETCH_ASSOC);
    if (!$company) {
        jsonResponse([
            'success' => false,
            'error' => 'company_not_found',
            'message' => 'Компания не найдена или отключена.',
        ], 404);
        return;
    }

    $tenant = new Database(companyDatabaseConfig($config, $company));
    $localPdo = $tenant->connection();
    applyLocalMigrations($localPdo);

    jsonResponse(ResponsibleAssignmentService::assignmentPayload(
        $central,
        $localPdo,
        $companyId,
        (int) $membershipId
    ));
} catch (DomainException $e) {
    jsonResponse([
        'success' => false,
        'error' => 'membership_not_found',
        'message' => $e->getMessage(),
    ], 404);
} catch (Throwable $e) {
    jsonResponse([
        'success' => false,
        'error' => 'assignment_load_failed',
        'message' => 'Не удалось загрузить закрепления.',
    ], 500);
}
