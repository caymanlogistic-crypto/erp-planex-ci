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

    $canEdit = true;
    require base_path('app/View/partials/company_user_modal_view.php');
} catch (\Exception $e) {
    echo '<div class="modal-body"><div class="notice danger">Ошибка загрузки данных: ' . e($e->getMessage()) . '</div></div>';
}
