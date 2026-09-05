<?php

    $pageTitle = 'Настройки выписок';
    $pageContext = 'Система › Настройки выписок';

    $companyId = (int)(getSessionCompanyId() ?? 0);

    if ($companyId <= 0) {
        $company = null;
        $bankSettings = [];
        $dbError = null;

        ob_start();
        require base_path('app/View/pages/company_bank_statement_settings.php');
        $content = ob_get_clean();
        require base_path('app/View/layouts/main.php');
        return;
    }

    try {
        $pdo = $db->connection();
        $stmt = $pdo->prepare('SELECT * FROM companies WHERE id = ?');
        $stmt->execute([$companyId]);
        $company = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$company) {
            $company = null;
            $bankSettings = [];
            $dbError = null;

            ob_start();
            require base_path('app/View/pages/company_bank_statement_settings.php');
            $content = ob_get_clean();
            require base_path('app/View/layouts/main.php');
            return;
        }

        $pageContext = 'Система › Настройки выписок › Компания: ' . e($company['name']);

        if ($company['status'] !== 'active') {
            $bankSettings = [];
            $dbError = null;

            ob_start();
            require base_path('app/View/pages/company_bank_statement_settings.php');
            $content = ob_get_clean();
            require base_path('app/View/layouts/main.php');
            return;
        }

        $localDbConfig = companyDatabaseConfig($config, $company);
        $localDb = new \App\Core\Database($localDbConfig);
        $localPdo = $localDb->connection();
        applyLocalMigrations($localPdo);

        $bankSettings = \App\Service\BankStatementSettingsService::getSettings($localPdo);
        $dbError = null;
    } catch (\Exception $e) {
        $company = $company ?? null;
        $bankSettings = [];
        $dbError = 'Не удалось подключиться к базе данных компании.';
    }

    ob_start();
    require base_path('app/View/pages/company_bank_statement_settings.php');
    $content = ob_get_clean();
    require base_path('app/View/layouts/main.php');
