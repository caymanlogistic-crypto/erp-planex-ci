<?php

    verifyCsrfRequest();

    $companyId = (int)(getSessionCompanyId() ?? 0);
    if ($companyId <= 0) {
        $_SESSION['finance_error'] = 'Компания не найдена.';
        redirect_to('/company/finance/cash');
        return;
    }

    try {
        $pdo = $db->connection();
        $stmt = $pdo->prepare('SELECT * FROM companies WHERE id = ? AND status = \'active\'');
        $stmt->execute([$companyId]);
        $company = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$company) {
            $_SESSION['finance_error'] = 'Компания не найдена или неактивна.';
            redirect_to('/company/finance/cash');
            return;
        }

        $localDbConfig = companyDatabaseConfig($config, $company);
        $localDb = new \App\Core\Database($localDbConfig);
        $localPdo = $localDb->connection();

        $user = [
            'id' => $_SESSION['user_id'] ?? null,
            'role' => $_SESSION['role_code'] ?? null,
        ];

        $result = \App\Service\FinanceCashService::createTransfer($localPdo, $_POST, $user);

        $amountFormatted = \App\Service\FinanceCashService::formatAmount($result['amount']);
        $_SESSION['finance_success'] = 'Перевод на сумму ' . $amountFormatted . ' ₽ успешно выполнен.';
    } catch (\InvalidArgumentException $e) {
        $_SESSION['finance_error'] = $e->getMessage();
    } catch (\RuntimeException $e) {
        $_SESSION['finance_error'] = $e->getMessage();
    } catch (\Throwable $e) {
        $_SESSION['finance_error'] = 'Ошибка при выполнении перевода: ' . $e->getMessage();
    }

    redirect_to('/company/finance/cash');
