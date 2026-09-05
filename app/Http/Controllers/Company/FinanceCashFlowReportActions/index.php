<?php

    $pageTitle = 'БДДС';
    $pageContext = 'Финансы › БДДС';

    $companyId = (int)(getSessionCompanyId() ?? 0);

    if ($companyId <= 0) {
        $company = null;
        $rows = [];
        $summary = [];
        $reportData = [];
        $dbError = null;

        ob_start();
        require base_path('app/View/pages/company_finance_cash_flow_report.php');
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
            $reportData = [];
            $dbError = null;

            ob_start();
            require base_path('app/View/pages/company_finance_cash_flow_report.php');
            $content = ob_get_clean();
            require base_path('app/View/layouts/main.php');
            return;
        }

        $pageContext = 'Финансы › БДДС › Компания: ' . $company['name'];

        if ($company['status'] !== 'active') {
            $rows = [];
            $summary = [];
            $reportData = [];
            $dbError = null;

            ob_start();
            require base_path('app/View/pages/company_finance_cash_flow_report.php');
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
        $moneyAccountId = $_GET['money_account_id'] ?? '';
        $directionFilter = $_GET['direction'] ?? 'all';
        $ddsCategoryId = $_GET['dds_category_id'] ?? '';
        $searchQuery = $_GET['search'] ?? '';

        if ($dateFrom !== '') {
            $filters['date_from'] = $dateFrom;
        }
        if ($dateTo !== '') {
            $filters['date_to'] = $dateTo;
        }
        if ($moneyAccountId !== '') {
            $filters['money_account_id'] = $moneyAccountId;
        }
        if ($directionFilter !== '') {
            $filters['direction'] = $directionFilter;
        }
        if ($ddsCategoryId !== '') {
            $filters['dds_category_id'] = $ddsCategoryId;
        }
        if ($searchQuery !== '') {
            $filters['search'] = $searchQuery;
        }

        $reportData = \App\Service\FinanceCashFlowReportService::fetchReportData($localPdo, $filters);
        $summary = \App\Service\FinanceCashFlowReportService::getSummary($localPdo, $filters);

        $rows = $reportData['rows'];

        $maStmt = $localPdo->query("SELECT id, name, type FROM finance_money_accounts WHERE is_active = 1 ORDER BY type, name");
        $moneyAccounts = $maStmt->fetchAll(\PDO::FETCH_ASSOC);

        $dcStmt = $localPdo->query("SELECT id, code, name, direction FROM finance_dds_categories WHERE is_active = 1 ORDER BY sort_order, code");
        $ddsCategories = $dcStmt->fetchAll(\PDO::FETCH_ASSOC);

        $dbError = null;
    } catch (\Throwable $e) {
        $company = $company ?? null;
        $rows = [];
        $summary = [];
        $reportData = [];
        $moneyAccounts = [];
        $ddsCategories = [];
        $dbError = 'Ошибка при загрузке данных: ' . $e->getMessage();
    }

    ob_start();
    require base_path('app/View/pages/company_finance_cash_flow_report.php');
    $content = ob_get_clean();
    require base_path('app/View/layouts/main.php');
