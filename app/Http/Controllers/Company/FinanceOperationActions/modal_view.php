<?php

use App\Core\Database;
use App\Service\FinanceAllocationService;


$operation = null;
$allocations = [];
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
    $allocations = FinanceAllocationService::fetchOperationAllocations($localPdo, $operationId);
    $canAllocate = true;
} catch (\Throwable $e) {
    if (http_response_code() < 400) {
        http_response_code(500);
    }
    $error = $e instanceof \RuntimeException && !($e instanceof \PDOException)
        ? $e->getMessage()
        : 'Не удалось загрузить операцию.';
}

require base_path('app/View/partials/company_finance_operation_modal_view.php');
