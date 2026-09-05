<?php

use App\Core\Database;
use App\Service\FinanceOperationActionService;


$logs = [];
$error = null;

try {
    $companyId = (int) (getSessionCompanyId() ?? 0);
    $company = $db->fetch('SELECT * FROM companies WHERE id = ?', [$companyId]);
    if (!$company || $company['status'] !== 'active') {
        throw new \RuntimeException('Компания недоступна.');
    }

    $localDb = new Database(companyDatabaseConfig($config, $company));
    $localPdo = $localDb->connection();
    $logs = FinanceOperationActionService::fetchOperationHistory($localPdo, (int) $id);
} catch (\Throwable $e) {
    http_response_code(500);
    $error = $e instanceof \RuntimeException && !($e instanceof \PDOException)
        ? $e->getMessage()
        : 'Не удалось загрузить историю.';
}

require base_path('app/View/partials/company_finance_history_view.php');
