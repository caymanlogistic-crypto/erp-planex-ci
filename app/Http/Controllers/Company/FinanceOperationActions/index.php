<?php

$pageTitle = 'Финансовые операции';
$pageContext = 'Финансы › Операции';
$companyId = (int) (getSessionCompanyId() ?? 0);
$company = null;
$operations = [];
$postedIncomeTotal = '0.00';
$postedExpenseTotal = '0.00';
$opTotal = 0;
$opPages = 1;
$currentPage = 1;
$currentPerPage = 100;
$dbError = null;

if ($companyId > 0) {
    try {
        $company = $db->fetch('SELECT * FROM companies WHERE id = ?', [$companyId]);
        if ($company && ($company['status'] ?? '') === 'active') {
            $pageContext = 'Финансы › Операции › Компания: ' . e($company['name']);
            $localDb = new \App\Core\Database(companyDatabaseConfig($config, $company));
            $localPdo = $localDb->connection();

            $filters = [
                'page' => max(1, (int) ($_GET['page'] ?? 1)),
                'per_page' => max(1, min(500, (int) ($_GET['per_page'] ?? 100))),
            ];
            foreach ([
                'date_from' => 'date_from',
                'date_to' => 'date_to',
                'type' => 'operation_type',
                'status' => 'status',
                'source' => 'source',
                'search' => 'search',
            ] as $input => $filter) {
                if (trim((string) ($_GET[$input] ?? '')) !== '') {
                    $filters[$filter] = trim((string) $_GET[$input]);
                }
            }

            $opResult = \App\Service\FinanceOperationService::fetchOperations($localPdo, $filters);
            $operations = $opResult['data'];
            $opTotal = $opResult['total'];
            $opPages = max(1, $opResult['pages']);
            $currentPage = $opResult['page'];
            $currentPerPage = $opResult['per_page'];
            $summary = \App\Service\FinanceOperationService::getSummaryTotals($localPdo, $filters);
            $postedIncomeTotal = $summary['total_income'];
            $postedExpenseTotal = $summary['total_expense'];
        }
    } catch (\Throwable $e) {
        $dbError = 'Не удалось подключиться к базе данных компании.';
    }
}

ob_start();
require base_path('app/View/pages/company_finance_operations.php');
$content = ob_get_clean();
require base_path('app/View/layouts/main.php');
