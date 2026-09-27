<?php

require_once base_path('app/Support/crew_driver_helpers.php');
header('Content-Type: application/json; charset=utf-8');
try {
    $companyId = (int) (getSessionCompanyId() ?? 0);
    if ($companyId <= 0) throw new RuntimeException('Компания не найдена.');
    $companyStmt = $db->connection()->prepare('SELECT * FROM companies WHERE id = ?');
    $companyStmt->execute([$companyId]);
    $company = $companyStmt->fetch(PDO::FETCH_ASSOC);
    if (!$company || ($company['status'] ?? '') !== 'active') throw new RuntimeException('Создание исполнителя рейса недоступно.');
    if (isPostTruncated()) throw new RuntimeException('Общий размер отправки превышает серверный лимит.');
    $sizeError = validateTotalUploadSize();
    if ($sizeError !== '') throw new RuntimeException($sizeError);
    $localPdo = (new \App\Core\Database(companyDatabaseConfig($config, $company)))->connection();
    $crewId = \App\Service\RouteExecutorCombinedCreateService::create(
        $localPdo,
        $db,
        $config,
        $companyId,
        $_POST,
        $_FILES,
        (int) ($_SESSION['user_id'] ?? 0),
        (string) ($_SESSION['role_code'] ?? '')
    );
    echo json_encode(['ok' => true, 'id' => $crewId], JSON_UNESCAPED_UNICODE);
} catch (PDOException $error) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Ошибка сохранения. Повторите попытку.'], JSON_UNESCAPED_UNICODE);
} catch (RuntimeException $error) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $error->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Ошибка сохранения. Повторите попытку.'], JSON_UNESCAPED_UNICODE);
}
