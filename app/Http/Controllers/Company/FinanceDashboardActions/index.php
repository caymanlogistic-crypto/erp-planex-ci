<?php

    $pageTitle = 'Финансовый дашборд';
    $pageContext = 'Финансы › Дашборд';

    $companyId = (int)(getSessionCompanyId() ?? 0);

    if ($companyId <= 0) {
        $company = null;
        $dashboard = [];
        $dbError = null;

        ob_start();
        require base_path('app/View/pages/company_finance_dashboard.php');
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
            $dashboard = [];
            $dbError = null;

            ob_start();
            require base_path('app/View/pages/company_finance_dashboard.php');
            $content = ob_get_clean();
            require base_path('app/View/layouts/main.php');
            return;
        }

        $pageContext = 'Финансы › Дашборд › Компания: ' . $company['name'];

        if ($company['status'] !== 'active') {
            $dashboard = [];
            $dbError = null;

            ob_start();
            require base_path('app/View/pages/company_finance_dashboard.php');
            $content = ob_get_clean();
            require base_path('app/View/layouts/main.php');
            return;
        }

        $localDbConfig = companyDatabaseConfig($config, $company);
        $localDb = new \App\Core\Database($localDbConfig);
        $localPdo = $localDb->connection();
        applyLocalMigrations($localPdo);

        $dashboard = \App\Service\FinanceDashboardService::computeDashboard($localPdo);

        $dbError = null;
    } catch (\Throwable $e) {
        $company = $company ?? null;
        $dashboard = [];
        $dbError = 'Ошибка при загрузке данных: ' . $e->getMessage();
    }

    ob_start();
    require base_path('app/View/pages/company_finance_dashboard.php');
    $content = ob_get_clean();
    require base_path('app/View/layouts/main.php');
