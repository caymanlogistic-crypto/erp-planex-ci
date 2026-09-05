<?php

    $pageTitle = 'Платёжный календарь';
    $pageContext = 'Финансы › Платёжный календарь';

    $companyId = (int)(getSessionCompanyId() ?? 0);

    if ($companyId <= 0) {
        $company = null;
        $rows = [];
        $summary = [];
        $dbError = null;

        ob_start();
        require base_path('app/View/pages/company_finance_payment_calendar.php');
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
            $summary = [];
            $dbError = null;

            ob_start();
            require base_path('app/View/pages/company_finance_payment_calendar.php');
            $content = ob_get_clean();
            require base_path('app/View/layouts/main.php');
            return;
        }

        $pageContext = 'Финансы › Платёжный календарь › Компания: ' . $company['name'];

        if ($company['status'] !== 'active') {
            $rows = [];
            $summary = [];
            $dbError = null;

            ob_start();
            require base_path('app/View/pages/company_finance_payment_calendar.php');
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
        $directionFilter = $_GET['direction'] ?? '';
        $statusFilter = $_GET['status'] ?? '';
        $searchQuery = $_GET['search'] ?? '';

        if ($dateFrom !== '') {
            $filters['date_from'] = $dateFrom;
        }
        if ($dateTo !== '') {
            $filters['date_to'] = $dateTo;
        }
        if ($directionFilter !== '') {
            $filters['direction'] = $directionFilter;
        }
        if ($statusFilter !== '') {
            $filters['status'] = $statusFilter;
        }
        if ($searchQuery !== '') {
            $filters['search'] = $searchQuery;
        }

        $filters['role_code'] = $_SESSION['role_code'] ?? '';
        $filters['user_id'] = (int) ($_SESSION['user_id'] ?? 0);

        $rows = \App\Service\FinancePaymentCalendarService::fetchCalendarData($localPdo, $filters);
        $summary = \App\Service\FinancePaymentCalendarService::getSummary($localPdo, $filters);

        $dbError = null;
    } catch (\Throwable $e) {
        $company = $company ?? null;
        $rows = [];
        $summary = [];
        $dbError = 'Ошибка при загрузке данных: ' . $e->getMessage();
    }

    ob_start();
    require base_path('app/View/pages/company_finance_payment_calendar.php');
    $content = ob_get_clean();
    require base_path('app/View/layouts/main.php');
