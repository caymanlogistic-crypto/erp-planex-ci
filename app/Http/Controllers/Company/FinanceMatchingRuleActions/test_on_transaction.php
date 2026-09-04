<?php


    $companyId = (int)(getSessionCompanyId() ?? 0);
    if ($companyId <= 0) {
        jsonResponse(['error' => 'Company not found'], 400);
        return;
    }

    try {
        $pdo = $db->connection();
        $stmt = $pdo->prepare('SELECT * FROM companies WHERE id = ? AND status = \'active\'');
        $stmt->execute([$companyId]);
        $company = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$company) {
            jsonResponse(['error' => 'Company not found or inactive'], 400);
            return;
        }

        $localDbConfig = companyDatabaseConfig($config, $company);
        $localDb = new \App\Core\Database($localDbConfig);
        $localPdo = $localDb->connection();
        applyLocalMigrations($localPdo);

        $ruleId = (int)($_GET['rule_id'] ?? 0);
        $txId = (int)($_GET['transaction_id'] ?? 0);

        if ($ruleId <= 0 || $txId <= 0) {
            jsonResponse(['error' => 'rule_id and transaction_id required'], 400);
            return;
        }

        $rule = \App\Service\FinanceMatchingRuleService::findRule($localPdo, $ruleId);
        if (!$rule) {
            jsonResponse(['error' => 'Rule not found'], 404);
            return;
        }

        $result = \App\Service\FinanceMatchingRuleService::testRuleOnTransaction($localPdo, $rule, $txId);

        jsonResponse([
            'rule_id' => $ruleId,
            'transaction_id' => $txId,
            'result' => $result,
        ]);
    } catch (\Throwable $e) {
        jsonResponse(['error' => $e->getMessage()], 500);
    }
