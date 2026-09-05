<?php

use App\Core\Database;
use App\Service\FinancePayablesReportService;


$pageTitle = 'Кредиторская задолженность';
$pageContext = 'Финансы › Кредиторская задолженность';
$companyId = (int)(getSessionCompanyId() ?? 0);
$company = null;
$dbError = null;
$successFlash = $_SESSION['finance_success'] ?? null;
$errorFlash = $_SESSION['finance_error'] ?? null;
unset($_SESSION['finance_success'], $_SESSION['finance_error']);
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
            $report = FinancePayablesReportService::build($localPdo);
        }
    }
} catch (Throwable $e) {
    $dbError = 'Ошибка загрузки данных: ' . $e->getMessage();
}

ob_start();
require base_path('app/View/pages/company_finance_payables.php');
$content = ob_get_clean();
require base_path('app/View/layouts/main.php');
