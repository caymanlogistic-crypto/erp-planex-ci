<?php

    verifyCsrfRequest();

    $companyId = (int)(getSessionCompanyId() ?? 0);
    if ($companyId <= 0) {
        $_SESSION['flash_error'] = 'Компания не найдена.';
        redirect_to('/company/finance/bank-accounts');
    }

    $fileStore = null;
    $newFile = false;
    $fileHash = null;
    try {
        $pdo = $db->connection();
        $stmt = $pdo->prepare('SELECT * FROM companies WHERE id = ?');
        $stmt->execute([$companyId]);
        $company = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$company || $company['status'] !== 'active') {
            $_SESSION['flash_error'] = 'Компания недоступна.';
            redirect_to('/company/finance/bank-accounts');
        }
        if (!isset($_FILES['bank_statement']) || $_FILES['bank_statement']['error'] !== UPLOAD_ERR_OK) {
            $_SESSION['flash_error'] = 'Ошибка загрузки файла.';
            redirect_to('/company/finance/bank-accounts');
        }

        $tmpPath = $_FILES['bank_statement']['tmp_name'];
        $originalName = $_FILES['bank_statement']['name'];
        if (!is_uploaded_file($tmpPath) || (int)($_FILES['bank_statement']['size'] ?? 0) <= 0 || (int)($_FILES['bank_statement']['size'] ?? 0) > ERP_MAX_FILE_SIZE) {
            $_SESSION['flash_error'] = 'Файл не прошёл проверку или превышает 20 МБ.';
            redirect_to('/company/finance/bank-accounts');
        }
        if (strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) !== 'xlsx') {
            $_SESSION['flash_error'] = 'Можно загружать только файлы в формате XLSX.';
            redirect_to('/company/finance/bank-accounts');
        }
        $fileHash = hash_file('sha256', $tmpPath);
        if ($fileHash === false) {
            $_SESSION['flash_error'] = 'Не удалось прочитать файл.';
            redirect_to('/company/finance/bank-accounts');
        }

        $localDb = new \App\Core\Database(companyDatabaseConfig($config, $company));
        $localPdo = $localDb->connection();
        applyLocalMigrations($localPdo);
        $parser = new \App\Service\BankStatementXlsxParser();
        $parsed = $parser->parse($tmpPath);
        if (empty($parsed['account_number']) && preg_match('/(?<!\d)(\d{20})(?!\d)/', $originalName, $m)) $parsed['account_number'] = $m[1];
        if (empty($parsed['account_number'])) {
            $_SESSION['flash_error'] = 'Не удалось определить номер счёта в файле. Проверьте формат выписки.';
            redirect_to('/company/finance/bank-accounts');
        }

        $fileStore = new \App\Service\BankStatementFileStore(company_storage_path($company['storage_path'] ?? null, $companyId));
        $newFile = !$fileStore->has($fileHash);
        $fileStore->save($tmpPath, $fileHash);
        $result = \App\Service\BankFinanceService::importParsedData($localPdo, $parsed, 'manual', null, $originalName, $fileHash);
        if (($result['status'] ?? '') === 'error' && $newFile) {
            $fileStore->remove($fileHash);
            $newFile = false;
        }
        if ($result['status'] === 'skipped') {
            $_SESSION['flash_info'] = 'Этот файл уже был импортирован ранее. Исходный XLSX сохранён для скачивания.';
        } elseif ($result['status'] === 'error') {
            $_SESSION['flash_error'] = $result['message'];
        } else {
            $_SESSION['flash_success'] = $result['message'];
        }
        redirect_to('/company/finance/bank-accounts');
    } catch (\Exception $e) {
        if ($newFile && $fileStore !== null && is_string($fileHash)) {
            try { $fileStore->remove($fileHash); } catch (\Throwable $cleanupError) { error_log('Bank statement file cleanup failed: ' . $cleanupError->getMessage()); }
        }
        $_SESSION['flash_error'] = 'Ошибка импорта: ' . $e->getMessage();
        redirect_to('/company/finance/bank-accounts');
    }
