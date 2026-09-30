<?php

use App\Core\Database;
use App\Service\FinanceInvoiceService;
use App\Service\FinanceObligationService;
use App\Service\FinanceReceivedPaidService;

$pageTitle = 'Получено/оплачено';
$pageContext = 'Финансы › Получено/оплачено';
$pageStylesheets = ['/assets/css/finance-received-paid.css'];
$company = null;
$localPdo = null;
$dbError = null;
$trips = [];

try {
    $companyId = (int)(getSessionCompanyId() ?? 0);
    $company = $companyId > 0 ? $db->fetch('SELECT * FROM companies WHERE id=?', [$companyId]) : null;
    if (!$company || ($company['status'] ?? '') !== 'active') {
        throw new RuntimeException('Компания недоступна.');
    }
    $localPdo = (new Database(companyDatabaseConfig($config, $company)))->connection();
    if (!FinanceObligationService::schemaReady($localPdo)) {
        throw new RuntimeException('Финансовые данные пока недоступны.');
    }
    $trips = FinanceReceivedPaidService::fetch($localPdo);
} catch (Throwable $exception) {
    $dbError = 'Ошибка загрузки данных: ' . $exception->getMessage();
    $localPdo = null;
}

ob_start();
require base_path('app/View/pages/company_finance_received_paid.php');
$content = ob_get_clean();
require base_path('app/View/layouts/main.php');
