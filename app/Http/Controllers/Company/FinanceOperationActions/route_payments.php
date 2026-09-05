<?php

use App\Core\Database;
use App\Service\FinanceAllocationService;

header('Content-Type: application/json; charset=utf-8');

try {
    $companyId = (int) (getSessionCompanyId() ?? 0);
    $company = $db->fetch('SELECT * FROM companies WHERE id = ?', [$companyId]);
    if (!$company || $company['status'] !== 'active') {
        throw new \RuntimeException('Компания недоступна.');
    }

    $routeId = (int) ($_GET['route_id'] ?? 0);
    $operationId = (int) ($_GET['operation_id'] ?? 0);
    if ($routeId <= 0 || $operationId <= 0) {
        echo '[]';
        exit;
    }

    $user = [
        'user_id' => (int) ($_SESSION['user_id'] ?? ($_SESSION['user']['id'] ?? 0)),
        'role_code' => (string) ($_SESSION['role_code'] ?? ($_SESSION['user']['role_code'] ?? '')),
    ];
    $localDb = new Database(companyDatabaseConfig($config, $company));
    $obligations = FinanceAllocationService::fetchObligationsForAllocation(
        $localDb->connection(),
        $operationId,
        $routeId,
        $user
    );

    $result = array_map(static fn(array $o): array => [
        'id' => (int) $o['id'],
        'label' => (string) ($o['allocation_label'] ?? ('Обязательство #' . (int) $o['id'])),
        'source_type' => (string) ($o['source_type'] ?? ''),
        'remaining_amount' => (string) ($o['remaining_amount'] ?? '0.00'),
    ], $obligations);
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    http_response_code(422);
    $message = $e instanceof \RuntimeException && !($e instanceof \PDOException)
        ? $e->getMessage()
        : 'Не удалось загрузить платёжные обязательства.';
    echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
}
