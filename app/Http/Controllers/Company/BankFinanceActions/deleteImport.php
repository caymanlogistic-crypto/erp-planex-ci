<?php

    verifyCsrfRequest();

    $companyId = (int)(getSessionCompanyId() ?? 0);

    if ($companyId <= 0) {
        $_SESSION['flash_error'] = 'Компания не найдена.';
        redirect_to('/company/finance/bank-accounts');
    }

    $importId = isset($_POST['import_id']) ? (int)$_POST['import_id'] : 0;
    if ($importId <= 0) {
        $_SESSION['flash_error'] = 'Неверный идентификатор импорта.';
        redirect_to('/company/finance/bank-accounts');
    }

    try {
        $pdo = $db->connection();
        $stmt = $pdo->prepare('SELECT * FROM companies WHERE id = ?');
        $stmt->execute([$companyId]);
        $company = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$company || $company['status'] !== 'active') {
            $_SESSION['flash_error'] = 'Компания недоступна.';
            redirect_to('/company/finance/bank-accounts');
        }

        $localDbConfig = companyDatabaseConfig($config, $company);
        $localDb = new \App\Core\Database($localDbConfig);
        $localPdo = $localDb->connection();
        applyLocalMigrations($localPdo);

        $import = \App\Service\BankFinanceService::getImportById($localPdo, $importId);
        if (!$import) {
            $_SESSION['flash_error'] = 'Импорт не найден.';
            redirect_to('/company/finance/bank-accounts');
        }

        $result = \App\Service\BankFinanceService::deleteImport($localPdo, $importId);

        if ($result['status'] === 'ok') {
            $_SESSION['flash_success'] = $result['message'];
        } else {
            $_SESSION['flash_error'] = $result['message'];
        }

        redirect_to('/company/finance/bank-accounts');
    } catch (\Exception $e) {
        $_SESSION['flash_error'] = 'Ошибка удаления импорта.';
        redirect_to('/company/finance/bank-accounts');
    }
