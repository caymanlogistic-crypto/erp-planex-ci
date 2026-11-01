<?php
requireRole('company_owner');
$pageTitle = 'Создать пользователя';
$pageContext = 'Пользователи › Компания';

$companyId = (int)(getSessionCompanyId() ?? 0);
$errors = [];
$old = $_POST;
$formError = null;
$success = false;
$generatedPassword = null;
$createdLogist = null;
$tempPassword = null;

if ($companyId <= 0) {
    $company = null;
    $formError = 'Компания не найдена';

    ob_start();
    require base_path('app/View/pages/company_logists_create.php');
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
        $formError = 'Компания не найдена';

        ob_start();
        require base_path('app/View/pages/company_logists_create.php');
        $content = ob_get_clean();
        require base_path('app/View/layouts/main.php');
        return;
    }

    $pageContext = 'Пользователи › Компания: ' . $company['name'];

    if ($company['status'] !== 'active') {
        $formError = 'Создание пользователей недоступно';

        ob_start();
        require base_path('app/View/pages/company_logists_create.php');
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

    $fullName = trim($_POST['full_name'] ?? '');
    $login = trim($_POST['login'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $roleCode = trim($_POST['role_code'] ?? 'logist');

    if ($fullName === '') {
        $errors['full_name'] = 'Обязательное поле';
    }

    if ($login === '') {
        $errors['login'] = 'Обязательное поле';
    } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $login)) {
        $errors['login'] = 'Только латинские буквы, цифры и подчёркивание';
    }

    if (empty($errors['login'])) {
        $checkStmt = $localPdo->prepare('SELECT COUNT(*) FROM users WHERE login = ?');
        $checkStmt->execute([$login]);
        if ($checkStmt->fetchColumn() > 0) {
            $errors['login'] = 'Логин уже используется в этой компании';
        }
    }

    if ($password === '') {
        $password = generatePassword();
    }

    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Некорректный email';
    }

    $allowedRoles = ['logist', 'senior_logist'];
    if (!in_array($roleCode, $allowedRoles, true)) {
        $errors['role_code'] = 'Недопустимая роль';
    }

    if (!empty($errors)) {
        $generatedPassword = generatePassword();

        ob_start();
        require base_path('app/View/pages/company_logists_create.php');
        $content = ob_get_clean();
        require base_path('app/View/layouts/main.php');
        return;
    }

    $passwordHash = password_hash($password, PASSWORD_BCRYPT);

    $insert = $localPdo->prepare(
        'INSERT INTO users (full_name, login, email, phone, password_hash, role_code, status, created_by_user_id, created_by_role)
         VALUES (:full_name, :login, :email, :phone, :password_hash, :role_code, :status, :created_by_user_id, :created_by_role)'
    );
    $insert->execute([
        ':full_name'          => $fullName,
        ':login'              => $login,
        ':email'              => $email !== '' ? $email : null,
        ':phone'              => $phone !== '' ? $phone : null,
        ':password_hash'      => $passwordHash,
        ':role_code'          => $roleCode,
        ':status'             => 'active',
        ':created_by_user_id' => (int)$_SESSION['user_id'],
        ':created_by_role'    => $_SESSION['role_code'],
    ]);

    $createdLogist = [
        'full_name' => $fullName,
        'login'     => $login,
        'role_code' => $roleCode,
    ];
    $tempPassword = $password;
    $success = true;
} catch (\Exception $e) {
    $company = $company ?? null;
    $formError = 'Ошибка создания пользователя: ' . $e->getMessage();
}

ob_start();
require base_path('app/View/pages/company_logists_create.php');
$content = ob_get_clean();
require base_path('app/View/layouts/main.php');
