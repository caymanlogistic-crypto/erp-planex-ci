<?php

verifyCsrfRequest();
try {
    $crewId = (int) $crewId;
    $companyId = (int) (getSessionCompanyId() ?? 0);
    if ($crewId <= 0 || $companyId <= 0) throw new RuntimeException('Исполнитель рейса не найден.');
    $companyStmt = $db->connection()->prepare("SELECT * FROM companies WHERE id=? AND status='active' LIMIT 1");
    $companyStmt->execute([$companyId]);
    $company = $companyStmt->fetch(PDO::FETCH_ASSOC);
    if (!$company) throw new RuntimeException('Компания недоступна.');
    $localPdo = (new \App\Core\Database(companyDatabaseConfig($config, $company)))->connection();
    $crewStmt = $localPdo->prepare('SELECT id FROM crews WHERE id=? AND deleted_at IS NULL LIMIT 1');
    $crewStmt->execute([$crewId]);
    if (!$crewStmt->fetchColumn() || !\App\Service\RouteExecutorService::canAccessCrew($localPdo, $crewId)) {
        throw new RuntimeException('Исполнитель рейса недоступен.');
    }
    $usage = \App\Service\RouteExecutorDeletionService::tripUsage($localPdo, $crewId);
    $canDelete = $usage['linear_count'] === 0 && $usage['waste_count'] === 0;
    $token = null;
    if ($canDelete) {
        $token = bin2hex(random_bytes(24));
        $_SESSION['route_executor_delete_previews'][$token] = [
            'company_id' => $companyId,
            'crew_id' => $crewId,
            'created_at' => time(),
        ];
    }
    jsonResponse(['success' => true, 'crew_id' => $crewId, 'can_delete' => $canDelete, 'usage' => $usage, 'preview_token' => $token]);
} catch (Throwable $error) {
    jsonResponse(['success' => false, 'message' => $error instanceof RuntimeException ? $error->getMessage() : 'Не удалось проверить участие в рейсах.'], 422);
}
