<?php
requireRole('company_owner');

$companyId = (int)(getSessionCompanyId() ?? 0);

if ($companyId <= 0) {
    echo '<div class="modal-body"><div class="notice warn">Компания не найдена.</div></div>';
    return;
}

try {
    $pdo = $db->connection();
    $stmt = $pdo->prepare('SELECT * FROM companies WHERE id = ?');
    $stmt->execute([$companyId]);
    $company = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$company || $company['status'] !== 'active') {
        echo '<div class="modal-body"><div class="notice warn">Компания недоступна.</div></div>';
        return;
    }

    $errors = [];
    $old = $_POST;
    $formError = null;
    $generatedPassword = null;

    $localDbConfig = companyDatabaseConfig($config, $company);
    $localDb = new \App\Core\Database($localDbConfig);
    $localPdo = $localDb->connection();
    applyLocalMigrations($localPdo);

    try { $localPdo->query("SELECT 1 FROM users LIMIT 1")->fetch(); }
    catch (\Exception $e) {
        throw new \RuntimeException('Данные компании не подготовлены. Обратитесь к администратору.', 0, $e);
    }

    $fullName = trim($_POST['full_name'] ?? '');
    $login = trim($_POST['login'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $roleCode = trim($_POST['role_code'] ?? 'logist');
    $status = trim($_POST['status'] ?? 'active');

    if ($fullName === '') { $errors['full_name'] = 'Обязательное поле'; }
    if ($login === '') { $errors['login'] = 'Обязательное поле'; }
    elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $login)) { $errors['login'] = 'Только латинские буквы, цифры и подчёркивание'; }
    if (empty($errors['login'])) {
        $checkStmt = $localPdo->prepare('SELECT COUNT(*) FROM users WHERE login = ?');
        $checkStmt->execute([$login]);
        if ($checkStmt->fetchColumn() > 0) { $errors['login'] = 'Логин уже используется в этой компании'; }
    }

    if ($password === '') { $password = generatePassword(); }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { $errors['email'] = 'Некорректный email'; }

    $allowedRoles = ['logist', 'senior_logist'];
    if (!in_array($roleCode, $allowedRoles, true)) { $errors['role_code'] = 'Недопустимая роль'; }

    $allowedStatuses = ['active', 'blocked'];
    if (!in_array($status, $allowedStatuses, true)) { $errors['status'] = 'Недопустимый статус'; }

    if (!empty($errors)) {
        $generatedPassword = generatePassword();
        require base_path('app/View/partials/company_user_create_form.php');
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
        ':status'             => $status,
        ':created_by_user_id' => (int)$_SESSION['user_id'],
        ':created_by_role'    => $_SESSION['role_code'],
    ]);

    $statusLabel = $status === 'active' ? 'Активен' : 'Заблокирован';

    echo '<div class="modal-body" data-company-user-create-success="1">
        <div class="notice success">Пользователь успешно создан.</div>
        <dl class="kv">
            <dt>ФИО</dt>
            <dd>' . e($fullName) . '</dd>
            <dt>Логин</dt>
            <dd><code>' . e($login) . '</code></dd>
            <dt>Временный пароль</dt>
            <dd><code class="code-hi">' . e($password) . '</code></dd>
            <dt>Роль</dt>
            <dd>' . e($roleCode === 'senior_logist' ? 'Логист+' : 'Логист') . '</dd>
            <dt>Статус</dt>
            <dd>' . e($statusLabel) . '</dd>
        </dl>
    </div>
    <div class="modal-foot is-spaced">
        <div class="modal-foot-actions">
            <button type="button" class="btn btn-secondary" data-company-user-create-close>Закрыть</button>
        </div>
    </div>';
} catch (\Exception $e) {
    $generatedPassword = $generatedPassword ?? generatePassword();
    $formError = 'Ошибка сохранения: ' . $e->getMessage();
    require base_path('app/View/partials/company_user_create_form.php');
}
