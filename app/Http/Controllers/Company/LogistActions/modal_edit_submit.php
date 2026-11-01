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

    $localDbConfig = companyDatabaseConfig($config, $company);
    $localDb = new \App\Core\Database($localDbConfig);
    $localPdo = $localDb->connection();
    applyLocalMigrations($localPdo);

    try { $localPdo->query("SELECT 1 FROM users LIMIT 1")->fetch(); }
    catch (\Exception $e) {
        throw new \RuntimeException('Данные компании не подготовлены. Обратитесь к администратору.', 0, $e);
    }

    $userStmt = $localPdo->prepare("SELECT * FROM users WHERE id = ?");
    $userStmt->execute([(int) $id]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if (!$user) {
        echo '<div class="modal-body"><div class="notice warn">Пользователь не найден.</div></div>';
        return;
    }

    $errors = [];
    $old = $_POST;
    $formError = null;

    $fullName = trim($_POST['full_name'] ?? '');
    $login = trim($_POST['login'] ?? '');
    $roleCode = trim($_POST['role_code'] ?? $user['role_code']);

    if ($fullName === '') { $errors['full_name'] = 'Обязательное поле'; }
    if ($login === '') { $errors['login'] = 'Обязательное поле'; }
    elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $login)) { $errors['login'] = 'Только латинские буквы, цифры и подчёркивание'; }
    else {
        $dupStmt = $localPdo->prepare("SELECT COUNT(*) FROM users WHERE login = ? AND id != ?");
        $dupStmt->execute([$login, (int) $id]);
        if ($dupStmt->fetchColumn() > 0) { $errors['login'] = 'Логин уже используется в этой компании'; }
    }

    $allowedRoles = ['logist', 'senior_logist'];
    if (!in_array($roleCode, $allowedRoles, true)) { $errors['role_code'] = 'Недопустимая роль'; }

    $email = trim($_POST['email'] ?? '');
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { $errors['email'] = 'Некорректный email'; }

    if (!empty($errors)) {
        require base_path('app/View/partials/company_user_modal_edit.php');
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
        ':status'    => $_POST['status'] ?? $user['status'],
        ':id'        => (int) $id,
    ]);

    $user['full_name'] = $fullName;
    $user['login'] = $login;
    $user['email'] = $email !== '' ? $email : null;
    $user['phone'] = trim($_POST['phone'] ?? '') ?: null;
    $user['role_code'] = $roleCode;
    $user['status'] = $_POST['status'] ?? $user['status'];
    $canEdit = true;
    require base_path('app/View/partials/company_user_modal_view.php');
} catch (\Exception $e) {
    $user = $user ?? [];
    $errors = [];
    $old = $_POST;
    $formError = 'Ошибка сохранения: ' . $e->getMessage();
    require base_path('app/View/partials/company_user_modal_edit.php');
}
