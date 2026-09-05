<?php

use App\Core\Database;
use App\Service\FinanceOperationActionService;

verifyCsrfRequest();
header('Content-Type: application/json; charset=utf-8');

try {
    $companyId = (int) (getSessionCompanyId() ?? 0);
    $company = $db->fetch('SELECT * FROM companies WHERE id = ?', [$companyId]);
    if (!$company || $company['status'] !== 'active') throw new \RuntimeException('Компания недоступна.');
    $localDb = new Database(companyDatabaseConfig($config, $company));
    $operationId = (int) $id;
    $user = [
        'user_id' => (int) ($_SESSION['user_id'] ?? ($_SESSION['user']['id'] ?? 0)),
        'role_code' => (string) ($_SESSION['role_code'] ?? ($_SESSION['user']['role_code'] ?? '')),
    ];
    $result = FinanceOperationActionService::cancelOperation($localDb->connection(), $operationId, $user, (string) ($_POST['reason'] ?? ''));
    echo json_encode([
        'ok' => true,
        'changed' => (bool) $result['changed'],
        'message' => $result['changed'] ? 'Операция отменена.' : 'Операция уже была отменена.',
        'operation_id' => $operationId,
    ], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    http_response_code(422);
    $message = $e instanceof \RuntimeException && !($e instanceof \PDOException) ? $e->getMessage() : 'Не удалось отменить операцию.';
    echo json_encode(['ok' => false, 'message' => $message], JSON_UNESCAPED_UNICODE);
}
