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

        $ruleId = (int)($_GET['id'] ?? 0);
        if ($ruleId <= 0) {
            jsonResponse(['error' => 'Invalid rule id'], 400);
            return;
        }

        $rule = \App\Service\FinanceMatchingRuleService::findRule($localPdo, $ruleId);
        if (!$rule) {
            jsonResponse(['error' => 'Rule not found'], 404);
            return;
        }

        $matches = \App\Service\FinanceMatchingRuleService::previewRule($localPdo, $rule, 20);

        $results = [];
        foreach ($matches as $tx) {
            $result = \App\Service\FinanceMatchingRuleService::applyRuleToTransaction($localPdo, $rule, $tx);
            $results[] = [
                'transaction_id' => (int)$tx['id'],
                'operation_date' => $tx['operation_date'],
                'counterparty_name' => $tx['counterparty_name'] ?? '—',
                'counterparty_inn' => $tx['counterparty_inn'] ?? '—',
                'amount' => (string)($tx['credit_amount'] > 0 ? $tx['credit_amount'] : $tx['debit_amount']),
                'purpose' => mb_substr((string)($tx['purpose'] ?? '—'), 0, 100),
                'confidence' => $result['confidence'],
                'result' => $result['result'],
                'reason' => $result['reason'],
            ];
        }

        jsonResponse([
            'rule_id' => $ruleId,
            'rule_name' => $rule['name'],
            'total_matches' => count($results),
            'results' => $results,
        ]);
    } catch (\Throwable $e) {
        jsonResponse(['error' => $e->getMessage()], 500);
    }
