<?php
requireRole('company_owner');
$pageTitle = 'Пользователи';
$pageContext = 'Пользователи › Компания';

$companyId = (int)(getSessionCompanyId() ?? 0);

if ($companyId <= 0) {
    $company = null;
    $logists = [];
    $dbError = null;

    ob_start();
    require base_path('app/View/pages/company_logists.php');
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
        $logists = [];
        $dbError = null;

        ob_start();
        require base_path('app/View/pages/company_logists.php');
        $content = ob_get_clean();
        require base_path('app/View/layouts/main.php');
        return;
    }

    $pageContext = 'Пользователи › Компания: ' . $company['name'];

    if ($company['status'] !== 'active') {
        $logists = [];
        $dbError = null;

        ob_start();
        require base_path('app/View/pages/company_logists.php');
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

    try {
        $localPdo->query("SELECT created_by_user_id FROM users LIMIT 1")->fetch();
    } catch (\Exception $e) {
        throw new \RuntimeException('Данные компании не подготовлены. Обратитесь к администратору.', 0, $e);
    }

    try {
        $localPdo->query("SELECT 1 FROM entity_access_grants LIMIT 1")->fetch();
    } catch (\Exception $e) {
        throw new \RuntimeException('Данные компании не подготовлены. Обратитесь к администратору.', 0, $e);
    }

    try {
        $localPdo->query("SELECT position FROM users LIMIT 1")->fetch();
    } catch (\Exception $e) {
        throw new \RuntimeException('Данные компании не подготовлены. Обратитесь к администратору.', 0, $e);
    }

    $isLogist = ($_SESSION['role_code'] ?? '') === 'logist';
    if ($isLogist) {
        $userId = (int)$_SESSION['user_id'];
        $logistStmt = $localPdo->prepare(
            "SELECT * FROM users WHERE deleted_at IS NULL AND (created_by_user_id = ? OR id IN (SELECT entity_id FROM entity_access_grants WHERE entity_type = 'logist' AND granted_to_user_id = ? AND access_level = 'view')) ORDER BY created_at DESC"
        );
        $logistStmt->execute([$userId, $userId]);
        $logists = $logistStmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $logistStmt = $localPdo->query("SELECT * FROM users WHERE deleted_at IS NULL ORDER BY created_at DESC");
        $logists = $logistStmt->fetchAll(PDO::FETCH_ASSOC);
    }
    $dbError = null;
} catch (\Exception $e) {
    $company = $company ?? null;
    $logists = [];
    $dbError = 'Не удалось подключиться к базе данных компании. Проверьте, что локальная БД создана.';
}

ob_start();
require base_path('app/View/pages/company_logists.php');
$content = ob_get_clean();
require base_path('app/View/layouts/main.php');
