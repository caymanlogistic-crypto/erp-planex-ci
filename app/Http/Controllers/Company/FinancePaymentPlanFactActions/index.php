<?php

    $pageTitle = 'План-факт оплаты';
    $pageContext = 'Финансы › План-факт оплаты';

    $companyId = (int)(getSessionCompanyId() ?? 0);

    if ($companyId <= 0) {
        $company = null;
        $rows = [];
        $totals = [];
        $dbError = null;

        ob_start();
        require base_path('app/View/pages/company_finance_payment_plan_fact.php');
        $content = ob_get_clean();
        require base_path('app/View/layouts/main.php');
        return;
    }

    try {
        $pdo = $db->connection();
        $stmt = $pdo->prepare('SELECT * FROM companies WHERE id = ?');
        $stmt->execute([$companyId]);
        $company = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$company) {
            $company = null;
            $rows = [];
            $totals = [];
            $dbError = null;

            ob_start();
            require base_path('app/View/pages/company_finance_payment_plan_fact.php');
            $content = ob_get_clean();
            require base_path('app/View/layouts/main.php');
            return;
        }

        $pageContext = 'Финансы › План-факт оплаты › Компания: ' . $company['name'];

        if ($company['status'] !== 'active') {
            $rows = [];
            $totals = [];
            $dbError = null;

            ob_start();
            require base_path('app/View/pages/company_finance_payment_plan_fact.php');
            $content = ob_get_clean();
            require base_path('app/View/layouts/main.php');
            return;
        }

        $localDbConfig = companyDatabaseConfig($config, $company);
        $localDb = new \App\Core\Database($localDbConfig);
        $localPdo = $localDb->connection();
        applyLocalMigrations($localPdo);

        $filters = [];
        $dateFrom = $_GET['date_from'] ?? '';
        $dateTo = $_GET['date_to'] ?? '';
        $direction = $_GET['direction'] ?? '';
        $status = $_GET['status'] ?? '';
        $search = $_GET['search'] ?? '';

        if ($dateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
            $filters['date_from'] = $dateFrom;
        } else {
            $dateFrom = '';
        }
        if ($dateTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
            $filters['date_to'] = $dateTo;
        } else {
            $dateTo = '';
        }
        if ($direction !== '') {
            $filters['direction'] = $direction;
        }
        if ($status !== '') {
            $filters['status'] = $status;
        }
        if ($search !== '') {
            $filters['search'] = $search;
        }

        $rows = \App\Service\FinancePaymentPlanFactService::fetchPlanFactData($localPdo, $filters);
        $totals = \App\Service\FinancePaymentPlanFactService::getTotals($localPdo, $filters);

        $dbError = null;
    } catch (\Throwable $e) {
        $company = $company ?? null;
        $rows = [];
        $totals = [];
        $dbError = 'Ошибка при загрузке данных: ' . $e->getMessage();
    }

    ob_start();
    require base_path('app/View/pages/company_finance_payment_plan_fact.php');
    $content = ob_get_clean();
    require base_path('app/View/layouts/main.php');
