<?php

$router->post('/superadmin/companies/{company_id}/users/owner/{user_id}/activate', function ($company_id, $user_id) use ($config, $db) {
    requireRole('superadmin');
    $pdo = $db->connection();
    $pdo->prepare("UPDATE company_users SET status = 'active', updated_at = NOW() WHERE id = ? AND company_id = ? AND role = 'company_owner'")
        ->execute([(int)$user_id, (int)$company_id]);
    header('Location: /superadmin/companies/' . $company_id . '/users?status_changed=1');
    exit;
});

$router->post('/superadmin/companies/{company_id}/users/owner/{user_id}/block', function ($company_id, $user_id) use ($config, $db) {
    requireRole('superadmin');
    $pdo = $db->connection();
    $pdo->prepare("UPDATE company_users SET status = 'blocked', updated_at = NOW() WHERE id = ? AND company_id = ? AND role = 'company_owner'")
        ->execute([(int)$user_id, (int)$company_id]);
    header('Location: /superadmin/companies/' . $company_id . '/users?status_changed=1');
    exit;
});

$router->post('/superadmin/companies/{company_id}/users/owner/{user_id}/archive', function ($company_id, $user_id) use ($config, $db) {
    requireRole('superadmin');
    $pdo = $db->connection();
    $pdo->prepare("UPDATE company_users SET status = 'archived', updated_at = NOW() WHERE id = ? AND company_id = ? AND role = 'company_owner'")
        ->execute([(int)$user_id, (int)$company_id]);
    header('Location: /superadmin/companies/' . $company_id . '/users?status_changed=1');
    exit;
});

$router->get('/superadmin/companies/{id}/delete', function ($id) use ($config, $db) {
    requireRole('superadmin');
    require_once base_path('app/Support/company_database.php');
    $pageTitle = 'Удаление компании';
    $pageContext = 'Реестр компаний';

    $pdo = $db->connection();
    $stmt = $pdo->prepare('SELECT * FROM companies WHERE id = ?');
    $stmt->execute([(int)$id]);
    $company = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$company) {
        ob_start();
        echo '<div class="panel"><div class="panel-body"><div class="notice warn">Компания не найдена. <a href="/superadmin/companies">< К реестру</a></div></div></div>';
        $content = ob_get_clean();
        require base_path('app/View/layouts/main.php');
        return;
    }

    $preview = [];
    $preview['company_name'] = $company['name'];
    $preview['company_inn'] = $company['inn'] ?? '—';
    $preview['company_id'] = $company['id'];
    $preview['db_identifier'] = $company['db_identifier'] ?? '—';
    $preview['storage_path'] = $company['storage_path'] ?? '—';
    $preview['status'] = $company['status'];

    $ownerStmt = $pdo->prepare("SELECT * FROM company_users WHERE company_id = ? AND role = 'company_owner'");
    $ownerStmt->execute([(int)$id]);
    $preview['owner'] = $ownerStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    $preview['logists_count'] = 0;
    $preview['clients_count'] = 0;
    $preview['contractors_count'] = 0;
    $preview['drivers_count'] = 0;
    $preview['vehicles_count'] = 0;
    $preview['crews_count'] = 0;
    $preview['documents_count'] = 0;
    $preview['storage_size'] = 'неизвестно';
    $localDbError = null;

    if (!empty($company['db_identifier'])) {
        try {
            $localDbConfig = companyDatabaseConfig($config, $company);
            $localDb = new \App\Core\Database($localDbConfig);
            $localPdo = $localDb->connection();
                applyLocalMigrations($localPdo);

            $tables = [
                'users' => 'logists_count',
                'clients' => 'clients_count',
                'contractors' => 'contractors_count',
                'drivers' => 'drivers_count',
                'vehicle_units' => 'vehicles_count',
                'crews' => 'crews_count',
                'documents' => 'documents_count'
            ];
            foreach ($tables as $table => $key) {
                $where = '';
                $preview[$key] = (int)$localPdo->query("SELECT COUNT(*) FROM `{$table}`{$where}")->fetchColumn();
            }

            $storageAbs = storage_path('companies/' . $id);
            if (is_dir($storageAbs)) {
                $size = 0;
                $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($storageAbs, RecursiveDirectoryIterator::SKIP_DOTS));
                foreach ($rii as $f) { $size += $f->getSize(); }
                $preview['storage_size'] = formatFileSize($size);
            } else {
                $preview['storage_size'] = 'папка не существует';
            }
        } catch (\Exception $e) {
            $localDbError = 'Локальная БД недоступна: ' . $e->getMessage();
        }
    }

    $dbError = null;
    $confirmError = null;
    $confirmValue = '';

    ob_start();
    require base_path('app/View/pages/superadmin_company_delete.php');
    $content = ob_get_clean();
    require base_path('app/View/layouts/main.php');
});

$router->post('/superadmin/companies/{id}/delete', function ($id) use ($config, $db) {
    requireRole('superadmin');
    require_once base_path('app/Support/company_database.php');
    $pageTitle = 'Удаление компании';
    $pageContext = 'Реестр компаний';

    $companyId = (int)$id;
    $pdo = $db->connection();

    $stmt = $pdo->prepare('SELECT * FROM companies WHERE id = ?');
    $stmt->execute([$companyId]);
    $company = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$company) {
        header('Location: /superadmin/companies');
        exit;
    }

    // === THREE-GATE CONFIRMATION ===
    $confirmCheckbox = isset($_POST['confirm_checkbox']) && $_POST['confirm_checkbox'] === '1';
    $confirmName = trim($_POST['confirm_name'] ?? '');
    $confirmPhrase = trim($_POST['confirm_phrase'] ?? '');

    $errors = [];
    if (!$confirmCheckbox) {
        $errors[] = 'Необходимо подтвердить понимание необратимости действия.';
    }
    if ($confirmName !== $company['name']) {
        $errors[] = 'Название компании не совпадает.';
    }
    if ($confirmPhrase !== 'УДАЛИТЬ НАВСЕГДА') {
        $errors[] = 'Контрольная фраза не совпадает.';
    }

    if (!empty($errors)) {
        $confirmError = implode('<br>', $errors);
        $preview = [];
        $preview['company_name'] = $company['name'];
        $preview['company_inn'] = $company['inn'] ?? '—';
        $preview['company_id'] = $company['id'];
        $preview['db_identifier'] = $company['db_identifier'] ?? '—';
        $preview['storage_path'] = $company['storage_path'] ?? '—';
        $preview['status'] = $company['status'];

        $ownerStmt = $pdo->prepare("SELECT * FROM company_users WHERE company_id = ? AND role = 'company_owner'");
        $ownerStmt->execute([$companyId]);
        $preview['owner'] = $ownerStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        $preview['logists_count'] = 0;
        $preview['clients_count'] = 0;
        $preview['contractors_count'] = 0;
        $preview['drivers_count'] = 0;
        $preview['vehicles_count'] = 0;
        $preview['crews_count'] = 0;
        $preview['documents_count'] = 0;
        $preview['storage_size'] = 'неизвестно';
        $localDbError = null;

        if (!empty($company['db_identifier'])) {
            try {
                $localDbConfig = companyDatabaseConfig($config, $company);
                $localDb = new \App\Core\Database($localDbConfig);
                $localPdo = $localDb->connection();
                applyLocalMigrations($localPdo);

                $tables = [
                    'users' => 'logists_count', 'clients' => 'clients_count',
                    'contractors' => 'contractors_count', 'drivers' => 'drivers_count',
                    'vehicle_units' => 'vehicles_count', 'crews' => 'crews_count',
                    'documents' => 'documents_count'
                ];
                foreach ($tables as $table => $key) {
                    $where = '';
                    $preview[$key] = (int)$localPdo->query("SELECT COUNT(*) FROM `{$table}`{$where}")->fetchColumn();
                }

                $storageAbs = storage_path('companies/' . $companyId);
                if (is_dir($storageAbs)) {
                    $size = 0;
                    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($storageAbs, RecursiveDirectoryIterator::SKIP_DOTS));
                    foreach ($rii as $f) { $size += $f->getSize(); }
                    $preview['storage_size'] = formatFileSize($size);
                } else {
                    $preview['storage_size'] = 'папка не существует';
                }
            } catch (\Exception $e) {
                $localDbError = 'Локальная БД недоступна: ' . $e->getMessage();
            }
        }

        $dbError = null;
        $confirmValue = '';

        ob_start();
        require base_path('app/View/pages/superadmin_company_delete.php');
        $content = ob_get_clean();
        require base_path('app/View/layouts/main.php');
        return;
    }

    // === PREFLIGHT: storage path validation ===
    $expectedStorageSuffix = 'companies/' . $companyId;
    $storageAbs = storage_path($expectedStorageSuffix);
    $storageAbsReal = realpath($storageAbs);

    $projectRoot = realpath(base_path(''));
    if ($storageAbsReal !== false) {
        if ($storageAbsReal === $projectRoot || strpos($storageAbsReal, $projectRoot . DIRECTORY_SEPARATOR) !== 0) {
            $confirmError = 'BLOCKED: Некорректный путь storage. Удаление невозможно.';
            ob_start(); echo '<div class="panel panel-danger"><div class="panel-body"><div class="notice danger">' . e($confirmError) . '</div><a href="/superadmin/companies/' . $companyId . '/delete" class="btn btn-ghost">← Назад</a></div></div>';
            $content = ob_get_clean(); require base_path('app/View/layouts/main.php');
            exit;
        }
        if (strpos($storageAbsReal, $projectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'companies' . DIRECTORY_SEPARATOR) !== 0) {
            $confirmError = 'BLOCKED: Путь storage не находится в storage/companies/. Удаление невозможно.';
            ob_start(); echo '<div class="panel panel-danger"><div class="panel-body"><div class="notice danger">' . e($confirmError) . '</div><a href="/superadmin/companies/' . $companyId . '/delete" class="btn btn-ghost">← Назад</a></div></div>';
            $content = ob_get_clean(); require base_path('app/View/layouts/main.php');
            exit;
        }
    }

    // === EXECUTE DELETION via service ===
    $result = \App\Service\CompanyDeletionService::delete($pdo, $company, $config, (int)($_SESSION['user_id'] ?? 0));

    if ($result['success']) {
        header('Location: /superadmin/companies?deleted=' . $companyId);
        exit;
    }

    // === PARTIAL FAILURE — show error page ===
    $errorMessages = implode('<br>', array_map('e', $result['errors']));
    ob_start();
    ?>
    <div class="panel panel-danger">
        <div class="panel-head">Ошибка удаления компании</div>
        <div class="panel-body">
            <div class="notice danger"><?= $errorMessages ?></div>
            <?php if (!empty($result['verification'])): ?>
            <div class="notice warn">
                <strong>Результаты проверки:</strong><br>
                <?= implode('<br>', array_map('e', $result['verification'])) ?>
            </div>
            <?php endif; ?>
            <details>
                <summary>Журнал шагов</summary>
                <pre class="code-block-scroll"><?= e(json_encode($result['steps'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
            </details>
            <div class="form-actions">
                <a href="/superadmin/companies/<?= $companyId ?>/delete" class="btn btn-ghost">← Вернуться к удалению</a>
                <a href="/superadmin/companies" class="btn btn-ghost">← К реестру компаний</a>
            </div>
        </div>
    </div>
    <?php
    $content = ob_get_clean();
    require base_path('app/View/layouts/main.php');
    exit;
});
