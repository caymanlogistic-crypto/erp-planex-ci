<?php
requireRole('company_owner');

$companyId = (int)(getSessionCompanyId() ?? 0);

if ($companyId <= 0) {
    $company = null;
    $logist = null;
    $errors = [];
    $old = $_POST;
    $formError = 'Компания не найдена';

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
        $old = $_POST;
        $formError = 'Компания не найдена';

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
        $old = $_POST;
        $formError = 'Редактирование пользователей недоступно';

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

    if (!$logist) {
        $errors = [];
        $old = $_POST;
        $formError = 'Пользователь не найден';

        ob_start();
        require base_path('app/View/pages/company_logist_edit.php');
        $content = ob_get_clean();
        require base_path('app/View/layouts/main.php');
        return;
    }

    $errors = [];
    $old = $_POST;
    $formError = null;

    $fullName = trim($_POST['full_name'] ?? '');
    $login = trim($_POST['login'] ?? '');
    $roleCode = trim($_POST['role_code'] ?? $logist['role_code']);

    if ($fullName === '') {
        $errors['full_name'] = 'Обязательное поле';
    }

    if ($login === '') {
        $errors['login'] = 'Обязательное поле';
    } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $login)) {
        $errors['login'] = 'Только латинские буквы, цифры и подчёркивание';
    } else {
        $dupStmt = $localPdo->prepare("SELECT COUNT(*) FROM users WHERE login = ? AND id != ?");
        $dupStmt->execute([$login, (int) $id]);
        if ($dupStmt->fetchColumn() > 0) {
            $errors['login'] = 'Логин уже используется в этой компании';
        }
    }

    $allowedRoles = ['logist', 'senior_logist'];
    if (!in_array($roleCode, $allowedRoles, true)) {
        $errors['role_code'] = 'Недопустимая роль';
    }

    $email = trim($_POST['email'] ?? '');
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Некорректный email';
    }

    if (!empty($errors)) {
        ob_start();
        require base_path('app/View/pages/company_logist_edit.php');
        $content = ob_get_clean();
        require base_path('app/View/layouts/main.php');
        return;
    }

    $update = $localPdo->prepare(
        "UPDATE users SET
            full_name = :full_name,
            login = :login,
            email = :email,
            phone = :phone,
            role_code = :role_code,
            status = :status
         WHERE id = :id"
    );

    $update->execute([
        ':full_name' => $fullName,
        ':login'     => $login,
        ':email'     => $email !== '' ? $email : null,
        ':phone'     => trim($_POST['phone'] ?? '') ?: null,
        ':role_code' => $roleCode,
        ':status'    => $_POST['status'] ?? $logist['status'],
        ':id'        => (int) $id,
    ]);

    header('Location: /company/logists/' . $id);
    exit;
} catch (\Exception $e) {
    $company = $company ?? null;
    $logist = $logist ?? null;
    $errors = [];
    $old = $_POST;
    $formError = 'Ошибка сохранения: ' . $e->getMessage();

    ob_start();
    require base_path('app/View/pages/company_logist_edit.php');
    $content = ob_get_clean();
    require base_path('app/View/layouts/main.php');
}
