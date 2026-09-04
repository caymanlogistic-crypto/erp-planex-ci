<?php

    $pageTitle = 'Управленческий баланс';
    $pageContext = 'Финансы › Управленческий баланс';

    $companyId = (int)(getSessionCompanyId() ?? 0);

    if ($companyId <= 0) {
        $company = null;
        $balanceData = null;
        $dbError = null;

        ob_start();
        require base_path('app/View/pages/company_finance_management_balance.php');
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
            $balanceData = null;
            $dbError = null;

            ob_start();
            require base_path('app/View/pages/company_finance_management_balance.php');
            $content = ob_get_clean();
            require base_path('app/View/layouts/main.php');
            return;
        }

        $pageContext = 'Финансы › Управленческий баланс › Компания: ' . $company['name'];

        if ($company['status'] !== 'active') {
            $balanceData = null;
            $dbError = null;

            ob_start();
            require base_path('app/View/pages/company_finance_management_balance.php');
            $content = ob_get_clean();
            require base_path('app/View/layouts/main.php');
            return;
        }

        $localDbConfig = companyDatabaseConfig($config, $company);
        $localDb = new \App\Core\Database($localDbConfig);
        $localPdo = $localDb->connection();
        applyLocalMigrations($localPdo);

        $asOfDate = $_GET['as_of'] ?? date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOfDate)) {
            $asOfDate = date('Y-m-d');
        }

        $balanceData = \App\Service\FinanceManagementBalanceService::computeBalance($localPdo, $asOfDate);

        $dbError = null;
    } catch (\Throwable $e) {
        $company = $company ?? null;
        $balanceData = null;
        $dbError = 'Ошибка при загрузке данных: ' . $e->getMessage();
    }

    ob_start();
    require base_path('app/View/pages/company_finance_management_balance.php');
    $content = ob_get_clean();
    require base_path('app/View/layouts/main.php');
