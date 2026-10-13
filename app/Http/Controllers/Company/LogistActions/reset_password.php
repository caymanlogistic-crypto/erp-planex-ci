<?php
requireRole('company_owner');

$companyId = (int)(getSessionCompanyId() ?? 0);

if ($companyId <= 0) {
    $company = null;
    $logist = null;
    $dbError = 'Компания не найдена';
    $passwordReset = false;
    $newPassword = null;

    ob_start();
    require base_path('app/View/pages/company_logist_view.php');
    $content = ob_get_clean();
    require base_path('app/View/layouts/main.php');
    return;
}

try {
    $pdo = $db->connection();
    $stmt = $pdo->prepare('SELECT * FROM companies WHERE id = ?');
    $stmt->execute([$companyId]);
    $company = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$company) {
        $company = null;
        $logist = null;
        $dbError = 'Компания не найдена';
        $passwordReset = false;
        $newPassword = null;

        ob_start();
        require base_path('app/View/pages/company_logist_view.php');
        $content = ob_get_clean();
        require base_path('app/View/layouts/main.php');
        return;
    }

    $pageTitle = 'Пользователь';
    $pageContext = 'Пользователи › Компания: ' . $company['name'];

    if ($company['status'] !== 'active') {
        $logist = null;
        $dbError = null;
        $passwordReset = false;
        $newPassword = null;

        ob_start();
        require base_path('app/View/pages/company_logist_view.php');
        $content = ob_get_clean();
        require base_path('app/View/layouts/main.php');
        return;
    }

    $dbIdentifier = $company['db_identifier'];

    try {
        $tempPdo = new PDO(
            sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $config['database']['host'] ?? '127.0.0.1', $config['database']['port'] ?? '3306'),
            $config['database']['username'] ?? 'root',
            $config['database']['password'] ?? ''
        );
        $tempPdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbIdentifier}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    } catch (\Exception $e) {
    }
    $tempPdo = null;

    $localDbConfig = companyDatabaseConfig($config, $company);
    $localDb = new \App\Core\Database($localDbConfig);
    $localPdo = $localDb->connection();
    applyLocalMigrations($localPdo);

    try {
        $localPdo->query("SELECT 1 FROM users LIMIT 1")->fetch();
    } catch (\Exception $e) {
        $migrationSql = file_get_contents(base_path('database/migrations-local/001_create_company_users.sql'));
        $localPdo->exec($migrationSql);
    }

    $logistStmt = $localPdo->prepare("SELECT * FROM users WHERE id = ?");
    $logistStmt->execute([(int) $id]);
    $logist = $logistStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if (!$logist) {
        $pageTitle = $logist ? 'Пользователь: ' . $logist['full_name'] : 'Пользователь';
        $dbError = null;
        $passwordReset = false;
        $newPassword = null;

        ob_start();
        require base_path('app/View/pages/company_logist_view.php');
        $content = ob_get_clean();
        require base_path('app/View/layouts/main.php');
        return;
    }

    $pageTitle = 'Пользователь: ' . $logist['full_name'];

    $newPassword = generatePassword(10);
    if (filter_var(env('ERP_ROLES_ACCESS_ENABLED', false), FILTER_VALIDATE_BOOL)) {
        require_once base_path('app/Service/IdentityNormalizer.php');
        require_once base_path('app/Service/CompanyAccountPasswordResetService.php');
        (new \App\Service\CompanyAccountPasswordResetService($pdo, $localPdo))
            ->reset($companyId, (int) $logist['id'], $newPassword);
    } else {
        $passwordHash = password_hash($newPassword, PASSWORD_BCRYPT);
        $update = $localPdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $update->execute([$passwordHash, (int) $logist['id']]);
    }

    $passwordReset = true;
    $dbError = null;

    ob_start();
    require base_path('app/View/pages/company_logist_view.php');
    $content = ob_get_clean();
    require base_path('app/View/layouts/main.php');
} catch (\Exception $e) {
    $company = $company ?? null;
    $logist = $logist ?? null;
    $dbError = 'Ошибка сброса пароля: ' . $e->getMessage();
    $passwordReset = false;
    $newPassword = null;

    ob_start();
    require base_path('app/View/pages/company_logist_view.php');
    $content = ob_get_clean();
    require base_path('app/View/layouts/main.php');
}
