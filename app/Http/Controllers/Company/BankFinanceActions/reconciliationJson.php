<?php


    $companyId = (int)(getSessionCompanyId() ?? 0);
    $controlFromDate = '2026-07-24';

    header('Content-Type: application/json; charset=utf-8');

    if ($companyId <= 0) {
        echo json_encode([
            'status' => 'error',
            'message' => 'No company context',
            'reconciliation' => null,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        return;
    }

    try {
        $pdo = $db->connection();
        $stmt = $pdo->prepare('SELECT * FROM companies WHERE id = ?');
        $stmt->execute([$companyId]);
        $company = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$company || $company['status'] !== 'active') {
            echo json_encode([
                'status' => 'error',
                'message' => 'Company not found or inactive',
                'reconciliation' => null,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            return;
        }

        $localDbConfig = companyDatabaseConfig($config, $company);
        $localDb = new \App\Core\Database($localDbConfig);
        $localPdo = $localDb->connection();

        $reconciliation = \App\Service\FinanceBankReconciliationCutoffService::reconcileAll($localPdo, $controlFromDate);

        echo json_encode([
            'status' => 'ok',
            'message' => 'Independent bank reconciliation report',
            'reconciliation' => $reconciliation,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    } catch (\Throwable $e) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Reconciliation error: ' . $e->getMessage(),
            'reconciliation' => null,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }
