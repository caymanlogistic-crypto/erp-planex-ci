<?php

use App\Core\Database;
use App\Service\FinanceAllocationService;

verifyCsrfRequest();
header('Content-Type: application/json; charset=utf-8');

try {
    $companyId = (int) (getSessionCompanyId() ?? 0);
    $company = $db->fetch('SELECT * FROM companies WHERE id = ?', [$companyId]);
    if (!$company || $company['status'] !== 'active') throw new \RuntimeException('Компания недоступна.');
    $localDb = new Database(companyDatabaseConfig($config, $company));
    $user = [
        'user_id' => (int) ($_SESSION['user_id'] ?? ($_SESSION['user']['id'] ?? 0)),
        'role_code' => (string) ($_SESSION['role_code'] ?? ($_SESSION['user']['role_code'] ?? '')),
    ];
    $operationId = FinanceAllocationService::cancelAllocation(
        $localDb->connection(),
        (int) $id,
        $user,
        trim((string) ($_POST['reason'] ?? 'Отменено вручную'))
    );
    echo json_encode(['ok' => true, 'message' => 'Распределение отменено.', 'operation_id' => $operationId], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    http_response_code(422);
    $message = $e instanceof \RuntimeException && !($e instanceof \PDOException) ? $e->getMessage() : 'Не удалось отменить распределение.';
    echo json_encode(['ok' => false, 'message' => $message], JSON_UNESCAPED_UNICODE);
}
