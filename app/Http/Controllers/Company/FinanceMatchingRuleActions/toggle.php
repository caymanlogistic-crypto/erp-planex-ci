<?php

    verifyCsrfRequest();

    $companyId = (int)(getSessionCompanyId() ?? 0);
    if ($companyId <= 0) {
        $_SESSION['finance_matching_rules_error'] = 'Компания не найдена.';
        redirect_to('/company/finance/settings/matching-rules');
        return;
    }

    $ruleId = (int)($_POST['id'] ?? 0);
    if ($ruleId <= 0) {
        $_SESSION['finance_matching_rules_error'] = 'Не указан ID правила.';
        redirect_to('/company/finance/settings/matching-rules');
        return;
    }

    try {
        $pdo = $db->connection();
        $stmt = $pdo->prepare('SELECT * FROM companies WHERE id = ? AND status = \'active\'');
        $stmt->execute([$companyId]);
        $company = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$company) {
            $_SESSION['finance_matching_rules_error'] = 'Компания не найдена или неактивна.';
            redirect_to('/company/finance/settings/matching-rules');
            return;
        }

        $localDbConfig = companyDatabaseConfig($config, $company);
        $localDb = new \App\Core\Database($localDbConfig);
        $localPdo = $localDb->connection();
        applyLocalMigrations($localPdo);

        $rule = \App\Service\FinanceMatchingRuleService::findRule($localPdo, $ruleId);
        if (!$rule) {
            $_SESSION['finance_matching_rules_error'] = 'Правило не найдено.';
            redirect_to('/company/finance/settings/matching-rules');
            return;
        }

        $user = [
            'id' => $_SESSION['user_id'] ?? null,
            'role' => $_SESSION['role_code'] ?? null,
        ];

        $newActive = !((int)($rule['active'] ?? 0));
        \App\Service\FinanceMatchingRuleService::setActive($localPdo, $ruleId, $newActive, $user);

        $_SESSION['finance_matching_rules_success'] = 'Правило ' . ($newActive ? 'активировано' : 'деактивировано') . '.';
    } catch (\InvalidArgumentException $e) {
        $_SESSION['finance_matching_rules_error'] = $e->getMessage();
    } catch (\Throwable $e) {
        $_SESSION['finance_matching_rules_error'] = 'Ошибка при изменении статуса правила: ' . $e->getMessage();
    }

    redirect_to('/company/finance/settings/matching-rules');
