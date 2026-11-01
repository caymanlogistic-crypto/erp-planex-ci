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

    $localDbConfig = companyDatabaseConfig($config, $company);
    $localDb = new \App\Core\Database($localDbConfig);
    $localPdo = $localDb->connection();
    applyLocalMigrations($localPdo);

    try {
        $localPdo->query("SELECT 1 FROM users LIMIT 1")->fetch();
    } catch (\Exception $e) {
        throw new \RuntimeException('Данные компании не подготовлены. Обратитесь к администратору.', 0, $e);
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
