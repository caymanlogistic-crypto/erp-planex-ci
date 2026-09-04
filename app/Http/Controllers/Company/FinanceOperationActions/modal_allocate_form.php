<?php

use App\Core\Database;
use App\Service\FinanceAllocationService;


$operation = null;
$error = null;

try {
    $companyId = (int) (getSessionCompanyId() ?? 0);
    $company = $db->fetch('SELECT * FROM companies WHERE id = ?', [$companyId]);
    if (!$company || $company['status'] !== 'active') {
        throw new \RuntimeException('Компания недоступна.');
    }

    $localDb = new Database(companyDatabaseConfig($config, $company));
    $localPdo = $localDb->connection();
    $operationId = (int) $id;
    $operation = FinanceAllocationService::fetchOperationCard($localPdo, $operationId);
    if (!$operation) {
        http_response_code(404);
        throw new \RuntimeException('Операция не найдена.');
    }
    if (($operation['status'] ?? '') !== 'POSTED') {
        throw new \RuntimeException('Распределять можно только проведённые операции.');
    }
    if ((float) ($operation['remaining_amount'] ?? 0) <= 0) {
        throw new \RuntimeException('Операция уже полностью распределена.');
    }

    $user = [
        'user_id' => (int) ($_SESSION['user_id'] ?? ($_SESSION['user']['id'] ?? 0)),
        'role_code' => (string) ($_SESSION['role_code'] ?? ($_SESSION['user']['role_code'] ?? '')),
    ];
    $invoices = FinanceAllocationService::fetchInvoicesForAllocation(
        $localPdo,
        (string) $operation['operation_type'],
        $operation['counterparty_inn'] ?? null
    );
    $routes = FinanceAllocationService::fetchRoutesForAllocation($localPdo, $user);
    $routePayments = [];

    $requestToken = bin2hex(random_bytes(24));
    $_SESSION['finance_allocation_tokens'] ??= [];
    $_SESSION['finance_allocation_tokens'][$operationId] ??= [];
    $_SESSION['finance_allocation_tokens'][$operationId][$requestToken] = time();
    foreach ($_SESSION['finance_allocation_tokens'][$operationId] as $token => $createdAt) {
        if ((int) $createdAt < time() - 1800) {
            unset($_SESSION['finance_allocation_tokens'][$operationId][$token]);
        }
    }
} catch (\Throwable $e) {
    if (http_response_code() < 400) {
        http_response_code(422);
    }
    $error = $e instanceof \RuntimeException && !($e instanceof \PDOException)
        ? $e->getMessage()
        : 'Не удалось подготовить форму распределения.';
}

require base_path('app/View/partials/company_finance_operation_allocate_form.php');
