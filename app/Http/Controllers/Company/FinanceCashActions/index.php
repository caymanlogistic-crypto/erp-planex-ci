<?php

    $pageTitle = 'Касса';
    $pageContext = 'Финансы › Касса';

    $companyId = (int)(getSessionCompanyId() ?? 0);
    $cashEmployees = [];
    // Source-specific "unresolved cash" is a legacy compatibility concept.
    // New cash is fungible: invoice allocation and physical handoff are separate.
    $unresolvedCash = ['count' => 0, 'amount' => '0.00', 'account_id' => null];

    if ($companyId <= 0) {
        $company = null;
        $cashAccounts = [];
        $recentOperations = [];
        $dbError = null;

        ob_start();
        require base_path('app/View/pages/company_finance_cash.php');
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
            $cashAccounts = [];
            $recentOperations = [];
            $dbError = null;

            ob_start();
            require base_path('app/View/pages/company_finance_cash.php');
            $content = ob_get_clean();
            require base_path('app/View/layouts/main.php');
            return;
        }

        $pageContext = 'Финансы › Касса › Компания: ' . e($company['name']);

        if ($company['status'] !== 'active') {
            $cashAccounts = [];
            $recentOperations = [];
            $dbError = null;

            ob_start();
            require base_path('app/View/pages/company_finance_cash.php');
            $content = ob_get_clean();
            require base_path('app/View/layouts/main.php');
            return;
        }

        $localDbConfig = companyDatabaseConfig($config, $company);
        $localDb = new \App\Core\Database($localDbConfig);
        $localPdo = $localDb->connection();
        applyLocalMigrations($localPdo);

        $cashAccounts = \App\Service\FinanceCashService::fetchMoneyAccounts($localPdo, 'CASH', true);
        $cashEmployees = \App\Service\FinanceEmployeePaymentService::fetchActiveEmployees($localPdo, $pdo, $companyId);

        $cashPage = max(1, (int) ($_GET['page'] ?? 1));
        $cashPerPage = max(1, min(500, (int) ($_GET['per_page'] ?? 50)));
        $cashResult = \App\Service\FinanceCashLedgerService::fetchRecentMovements($localPdo, $cashPage, $cashPerPage);
        $cashRows = $cashResult['data'];
        $recentOperations = array_values(array_filter(
            $cashRows,
            static fn(array $row): bool => strtoupper((string)($row['status'] ?? '')) !== 'CANCELLED'
        ));
        // Cancelled operations remain available in the audit/operations register,
        // but they are not part of the working cash journal.
        $cancelledRowsOnPage = count($cashRows) - count($recentOperations);
        $cashTotal = max(0, (int)$cashResult['total'] - $cancelledRowsOnPage);
        $cashPages = (int) ceil($cashTotal / max(1, $cashPerPage));

        $successFlash = $_SESSION['finance_success'] ?? null;
        unset($_SESSION['finance_success']);
        $errorFlash = $_SESSION['finance_error'] ?? null;
        unset($_SESSION['finance_error']);

        $dbError = null;
    } catch (\Throwable $e) {
        $company = $company ?? null;
        $cashAccounts = [];
        $recentOperations = [];
        $cashEmployees = [];
        $unresolvedCash = ['count' => 0, 'amount' => '0.00', 'account_id' => null];
        $dbError = 'Ошибка при загрузке данных: ' . $e->getMessage();
        $successFlash = null;
        $errorFlash = null;
    }

    ob_start();
    require base_path('app/View/pages/company_finance_cash.php');
    $content = ob_get_clean();
    // Keep legacy resolution backend/history, but do not offer the old
    // "choose a source receipt -> employee" workflow in the primary UI.
    $content .= '<style>.cash-batch-bar,#cash-dispatch-employee-modal{display:none!important}</style>';
    require base_path('app/View/layouts/main.php');