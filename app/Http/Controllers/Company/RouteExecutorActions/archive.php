<?php

verifyCsrfRequest();
try {
    $crewId = (int) $crewId;
    $companyId = (int) (getSessionCompanyId() ?? 0);
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    $role = (string) ($_SESSION['role_code'] ?? '');
    $token = trim((string) ($_POST['preview_token'] ?? ''));
    $preview = $_SESSION['route_executor_delete_previews'][$token] ?? null;
    if (!is_array($preview) || (int) ($preview['company_id'] ?? 0) !== $companyId
        || (int) ($preview['crew_id'] ?? 0) !== $crewId
        || time() - (int) ($preview['created_at'] ?? 0) > 300) {
        throw new RuntimeException('Подтверждение устарело. Повторите проверку удаления.');
    }
    if (!hash_equals('7550055', trim((string) ($_POST['delete_password'] ?? '')))) {
        throw new RuntimeException('Неверный пароль удаления.');
    }
    if ($crewId <= 0 || $companyId <= 0 || $userId <= 0) throw new RuntimeException('Исполнитель рейса не найден.');
    $centralPdo = $db->connection();
    $companyStmt = $centralPdo->prepare("SELECT * FROM companies WHERE id=? AND status='active' LIMIT 1");
    $companyStmt->execute([$companyId]);
    $company = $companyStmt->fetch(PDO::FETCH_ASSOC);
    if (!$company) throw new RuntimeException('Компания недоступна.');
    $localPdo = (new \App\Core\Database(companyDatabaseConfig($config, $company)))->connection();
    if (!\App\Service\RouteExecutorService::canAccessCrew($localPdo, $crewId)) {
        throw new RuntimeException('Исполнитель рейса недоступен.');
    }
    $crew = \App\Service\RouteExecutorDeletionService::archive($localPdo, $crewId, $userId, $role);
    unset($_SESSION['route_executor_delete_previews'][$token]);
    try {
        \App\Service\AuditService::recordDeletion(
            $centralPdo, $company, 'crew', $crewId, 'crews', 'Экипаж #' . $crewId,
            $userId, $role, (string) ($_SESSION['user_name'] ?? ''), null,
            json_encode($crew, JSON_UNESCAPED_UNICODE)
        );
    } catch (Throwable $auditError) {
        error_log('Audit record failed for crew ' . $crewId . ': ' . $auditError->getMessage());
    }
    jsonResponse(['success' => true, 'crew_id' => $crewId]);
} catch (Throwable $error) {
    $message = $error instanceof RuntimeException && !($error instanceof PDOException)
        ? $error->getMessage() : 'Не удалось удалить исполнителя рейса.';
    jsonResponse(['success' => false, 'message' => $message], 422);
}
