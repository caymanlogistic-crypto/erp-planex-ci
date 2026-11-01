<?php
requireRole('company_owner');

$companyId = (int)(getSessionCompanyId() ?? 0);

if ($companyId <= 0) {
    $company = null;
    $logist = null;
    $errors = [];
    $old = [];
    $formError = null;

    ob_start();
    require base_path('app/View/pages/company_logist_edit.php');
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
        $errors = [];
        $old = [];
        $formError = null;

        ob_start();
        require base_path('app/View/pages/company_logist_edit.php');
        $content = ob_get_clean();
        require base_path('app/View/layouts/main.php');
        return;
    }

    $pageTitle = 'Редактировать пользователя';
    $pageContext = 'Пользователи › Компания: ' . $company['name'];

    if ($company['status'] !== 'active') {
        $logist = null;
        $errors = [];
        $old = [];
        $formError = null;

        ob_start();
        require base_path('app/View/pages/company_logist_edit.php');
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

    $errors = [];
    $old = $logist ?: [];
    $formError = null;

    ob_start();
    require base_path('app/View/pages/company_logist_edit.php');
    $content = ob_get_clean();
    require base_path('app/View/layouts/main.php');
} catch (\Exception $e) {
    $company = $company ?? null;
    $logist = null;
    $errors = [];
    $old = [];
    $formError = 'Не удалось загрузить данные: ' . $e->getMessage();

    ob_start();
    require base_path('app/View/pages/company_logist_edit.php');
    $content = ob_get_clean();
    require base_path('app/View/layouts/main.php');
}
