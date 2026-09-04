<?php

use App\Core\Database;
use App\Service\FinanceObligationService;


$pageTitle = 'Дебиторская задолженность';
$pageContext = 'Финансы › Дебиторская задолженность';
$companyId = (int)(getSessionCompanyId() ?? 0);
$company = null;
$dbError = null;
$report = ['rows'=>[], 'summary'=>['total'=>'0.00','overdue'=>'0.00','aging_1_7'=>'0.00','aging_8_30'=>'0.00','aging_31_60'=>'0.00','aging_61_plus'=>'0.00']];

try {
    if ($companyId > 0) {
        $company = $db->fetch('SELECT * FROM companies WHERE id=?', [$companyId]);
        if (!$company || ($company['status'] ?? '') !== 'active') {
            $company = null;
            $dbError = 'Компания не найдена или неактивна.';
        } else {
            $pageContext .= ' › Компания: ' . $company['name'];
            $localPdo = (new Database(companyDatabaseConfig($config, $company)))->connection();
            applyLocalMigrations($localPdo);
            $report = FinanceObligationService::receivablesReport($localPdo);
        }
    }
} catch (Throwable $e) {
    $dbError = 'Ошибка загрузки данных: ' . $e->getMessage();
}

ob_start();
require base_path('app/View/pages/company_finance_receivables.php');
$content = ob_get_clean();
require base_path('app/View/layouts/main.php');
