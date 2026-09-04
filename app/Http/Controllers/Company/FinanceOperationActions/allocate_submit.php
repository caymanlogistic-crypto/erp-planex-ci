<?php

use App\Core\Database;
use App\Service\FinanceAllocationService;

verifyCsrfRequest();
header('Content-Type: application/json; charset=utf-8');

try {
    $companyId = (int) (getSessionCompanyId() ?? 0);
    $company = $db->fetch('SELECT * FROM companies WHERE id = ?', [$companyId]);
    if (!$company || $company['status'] !== 'active') throw new \RuntimeException('Компания недоступна.');

    $operationId = (int) $id;
    $requestToken = (string) ($_POST['request_token'] ?? '');
    $tokens = $_SESSION['finance_allocation_tokens'][$operationId] ?? [];
    if ($requestToken === '' || !array_key_exists($requestToken, $tokens)) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'message' => 'Запрос уже выполнен или форма устарела. Откройте её заново.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $localDb = new Database(companyDatabaseConfig($config, $company));
    $localPdo = $localDb->connection();
    $operation = FinanceAllocationService::fetchOperationCard($localPdo, $operationId);
    if (!$operation) throw new \RuntimeException('Операция не найдена.');
    if (($operation['status'] ?? '') !== 'POSTED') throw new \RuntimeException('Распределять можно только проведённые операции.');
    $amount = FinanceAllocationService::normalizeMoneyInput((string) ($_POST['amount'] ?? ''));
    if ($amount === null) throw new \RuntimeException('Сумма распределения должна быть больше нуля.');
    $amountCents = (int) round((float) $amount * 100);
    $remainingCents = (int) round((float) ($operation['remaining_amount'] ?? 0) * 100);
    if ($amountCents > $remainingCents) throw new \RuntimeException('Сумма распределения превышает остаток операции.');

    $user = [
        'user_id' => (int) ($_SESSION['user_id'] ?? ($_SESSION['user']['id'] ?? 0)),
        'role_code' => (string) ($_SESSION['role_code'] ?? ($_SESSION['user']['role_code'] ?? '')),
    ];
    FinanceAllocationService::createAllocation($localPdo, $operationId, [
        'amount' => $_POST['amount'] ?? '',
        'invoice_id' => $_POST['invoice_id'] ?? null,
        'obligation_id' => $_POST['obligation_id'] ?? null,
        'linear_route_id' => $_POST['linear_route_id'] ?? null,
        // Backward compatibility for a stale already-opened form; the service resolves it to obligation_id.
        'linear_route_payment_id' => $_POST['linear_route_payment_id'] ?? null,
        'allocation_date' => $_POST['allocation_date'] ?? date('Y-m-d'),
        'comment' => $_POST['comment'] ?? null,
    ], $user);

    unset($_SESSION['finance_allocation_tokens'][$operationId][$requestToken]);
    echo json_encode(['ok' => true, 'message' => 'Распределение создано.', 'operation_id' => $operationId], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    http_response_code(422);
    $message = $e instanceof \RuntimeException && !($e instanceof \PDOException) ? $e->getMessage() : 'Не удалось создать распределение.';
    echo json_encode(['ok' => false, 'message' => $message], JSON_UNESCAPED_UNICODE);
}
