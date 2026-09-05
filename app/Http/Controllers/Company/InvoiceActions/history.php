<?php

use App\Core\Database;
use App\Service\FinanceOperationService;


$logs = [];
$error = null;

try {
    $companyId = (int)(getSessionCompanyId() ?? 0);
    $company = $db->fetch('SELECT * FROM companies WHERE id = ?', [$companyId]);

    if (!$company || $company['status'] !== 'active') {
        throw new \RuntimeException('Компания недоступна.');
    }

    $localConfig = companyDatabaseConfig($config, $company);
    $localDb = new Database($localConfig);
    $localPdo = $localDb->connection();
    applyLocalMigrations($localPdo);

    $invoiceId = (int) $id;
    $logs = FinanceOperationService::fetchAuditLog($localPdo, 'finance_invoice', $invoiceId);
} catch (\Throwable $e) {
    $error = $e->getMessage();
}

require base_path('app/View/partials/company_finance_history_view.php');
