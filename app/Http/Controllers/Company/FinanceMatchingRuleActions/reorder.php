<?php

    verifyCsrfRequest();

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

        $body = requestJsonBody();
        $ruleId = (int)($body['id'] ?? 0);
        $newPriority = (int)($body['priority'] ?? 0);

        if ($ruleId <= 0 || $newPriority <= 0) {
            jsonResponse(['error' => 'Invalid id or priority'], 400);
            return;
        }

        $user = [
            'id' => $_SESSION['user_id'] ?? null,
            'role' => $_SESSION['role_code'] ?? null,
        ];

        \App\Service\FinanceMatchingRuleService::setPriority($localPdo, $ruleId, $newPriority, $user);

        jsonResponse(['success' => true]);
    } catch (\Throwable $e) {
        jsonResponse(['error' => $e->getMessage()], 500);
    }
