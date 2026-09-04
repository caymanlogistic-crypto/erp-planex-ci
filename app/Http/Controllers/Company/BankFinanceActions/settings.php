<?php

    verifyCsrfRequest();

    $companyId = (int)(getSessionCompanyId() ?? 0);

    $redirectUrl = '/company/bank-statement-settings';

    if ($companyId <= 0) {
        $_SESSION['flash_error'] = 'Компания не найдена.';
        redirect_to($redirectUrl);
    }

    try {
        $pdo = $db->connection();
        $stmt = $pdo->prepare('SELECT * FROM companies WHERE id = ?');
        $stmt->execute([$companyId]);
        $company = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$company || $company['status'] !== 'active') {
            $_SESSION['flash_error'] = 'Компания недоступна.';
            redirect_to($redirectUrl);
        }

        $localDbConfig = companyDatabaseConfig($config, $company);
        $localDb = new \App\Core\Database($localDbConfig);
        $localPdo = $localDb->connection();
        applyLocalMigrations($localPdo);

        $bank = $_POST['bank_code'] ?? 'vtb';
        if (!in_array($bank, ['vtb'], true)) {
            $_SESSION['flash_error'] = 'Неподдерживаемый банк.';
            redirect_to($redirectUrl);
        }

        $host = trim($_POST['imap_host'] ?? '');
        $port = (int)($_POST['imap_port'] ?? 0);
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $senderFilter = trim($_POST['sender_filter'] ?? 'vtb-inform@vtb.ru');
        $subjectFilter = trim($_POST['subject_filter'] ?? 'Регулярная выписка');
        $isActive = !empty($_POST['is_active']);
        $settingId = (int)($_POST['setting_id'] ?? 0);

        $errors = [];

        if ($isActive) {
            if ($host === '') {
                $errors[] = 'IMAP-хост обязателен для активной настройки.';
            }
            if ($port < 1 || $port > 65535) {
                $errors[] = 'IMAP-порт должен быть от 1 до 65535.';
            }
            if ($username === '') {
                $errors[] = 'Имя пользователя обязательно для активной настройки.';
            }
            if ($password === '' && $settingId <= 0) {
                $errors[] = 'Пароль обязателен при создании активной настройки.';
            }
            if (empty($_POST['imap_ssl'])) {
                $errors[] = 'Для импорта выписок обязательно защищённое IMAP/TLS-соединение.';
            }
        }

        if (!empty($errors)) {
            $_SESSION['flash_error'] = implode(' ', $errors);
            redirect_to($redirectUrl);
        }

        $data = [
            'id' => $settingId,
            'bank_code' => $bank,
            'imap_host' => $host,
            'imap_port' => $port,
            'imap_ssl' => !empty($_POST['imap_ssl']) ? 1 : 0,
            'username' => $username,
            'password' => $password,
            'sender_filter' => $senderFilter,
            'subject_filter' => $subjectFilter,
            'is_active' => $isActive ? 1 : 0,
        ];

        \App\Service\BankStatementSettingsService::upsertSettings($localPdo, $data);

        $_SESSION['flash_success'] = 'Настройки импорта выписок сохранены.';
    } catch (\RuntimeException $e) {
        if (str_contains($e->getMessage(), 'APP_ENCRYPTION_KEY')) {
            $_SESSION['flash_error'] = 'Ключ шифрования APP_ENCRYPTION_KEY не настроен. Обратитесь к администратору.';
        } else {
            $_SESSION['flash_error'] = 'Ошибка сохранения настроек: ' . $e->getMessage();
        }
    } catch (\Exception $e) {
        $_SESSION['flash_error'] = 'Ошибка сохранения настроек: ' . $e->getMessage();
    }

    redirect_to($redirectUrl);
