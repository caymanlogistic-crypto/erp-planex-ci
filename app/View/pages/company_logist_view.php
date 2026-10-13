<?php

require_once __DIR__ . '/../components/status_badge.php';

?>

<?php if ($company === null): ?>

<div class="notice warn">
    Компания не найдена. Укажите корректный company_id.
</div>

<?php elseif ($company['status'] !== 'active'): ?>

<div class="page-head">
    <div>
        <h1>Логисты</h1>
        <p class="text-muted">Компания: <?= e($company['name']) ?></p>
    </div>
    <div class="page-head-actions">
        <a href="<?= app_url('/company/logists') ?>" class="btn btn-ghost">← К списку</a>
    </div>
</div>

<div class="notice warn">
    Компания находится в статусе «<?= e($company['status']) ?>».
</div>

<?php elseif ($logist === null): ?>

<div class="page-head">
    <div>
        <h1>Пользователь</h1>
        <p class="text-muted">Компания: <?= e($company['name']) ?></p>
    </div>
    <div class="page-head-actions">
        <a href="<?= app_url('/company/logists') ?>" class="btn btn-ghost">← К списку</a>
    </div>
</div>

<div class="notice warn">
    Пользователь не найден.
</div>

<?php elseif (isset($dbError)): ?>

<div class="page-head">
    <div>
        <h1>Пользователь</h1>
        <p class="text-muted">Компания: <?= e($company['name']) ?></p>
    </div>
    <div class="page-head-actions">
        <a href="<?= app_url('/company/logists') ?>" class="btn btn-ghost">← К списку</a>
    </div>
</div>

<div class="notice danger">
    <?= e($dbError) ?>
</div>

<?php elseif ($passwordReset): ?>

<div class="page-head">
    <div>
        <h1>Пользователь: <?= e($logist['full_name']) ?></h1>
        <p class="text-muted">Компания: <?= e($company['name']) ?></p>
    </div>
    <div class="page-head-actions">
        <a href="/company/logists/<?= $logist['id'] ?>" class="btn btn-ghost">← К карточке пользователя</a>
    </div>
</div>

<div class="panel">
    <div class="panel-body">
        <div class="notice success">
            Пароль успешно сброшен.
        </div>

        <dl class="kv mt-4">
            <dt>ФИО</dt>
            <dd><?= e($logist['full_name']) ?></dd>
            <dt>Логин</dt>
            <dd><code><?= e($logist['login']) ?></code></dd>
            <dt>Новый временный пароль</dt>
            <dd><code class="code-hi"><?= e($newPassword) ?></code></dd>
        </dl>

        <div class="notice warn mt-4">
            Временный пароль показан только один раз. Сохраните его сейчас. Пароль не хранится в открытом виде и не может быть восстановлен.
        </div>

        <div class="form-actions mt-4">
            <a href="/company/logists/<?= $logist['id'] ?>" class="btn btn-ghost">← К карточке пользователя</a>
        </div>
    </div>
</div>

<?php else: ?>

<div class="page-head">
    <div>
        <h1>Пользователь: <?= e($logist['full_name']) ?></h1>
        <p class="text-muted">Компания: <?= e($company['name']) ?></p>
    </div>
    <div class="page-head-actions">
        <a href="/company/logists/<?= $logist['id'] ?>/edit" class="btn btn-primary">Редактировать</a>
        <a href="<?= app_url('/company/logists') ?>" class="btn btn-ghost">← К списку</a>
    </div>
</div>

<div class="panel">
    <div class="panel-body">

        <div class="form-section">
            <h3 class="panel-head-title">Основные данные</h3>
            <dl class="kv">
                <dt>ФИО</dt>
                <dd><?= e($logist['full_name']) ?></dd>
                <dt>Логин</dt>
                <dd><code><?= e($logist['login']) ?></code></dd>
                <dt>Email</dt>
                <dd><?= e($logist['email'] ?? '') ?: '—' ?></dd>
                <dt>Телефон</dt>
                <dd><?= e($logist['phone'] ?? '') ?: '—' ?></dd>
                <dt>Роль</dt>
                <dd><?php $rc = $logist['role_code'] ?? 'logist'; echo e($rc === 'logist' ? 'Логист' : ($rc === 'senior_logist' ? 'Логист+' : $rc)) ?></dd>
                <dt>Статус</dt>
                <dd><?= renderStatusBadge($logist['status']) ?></dd>
            </dl>
        </div>

        <?php if (($_SESSION['role_code'] ?? '') !== 'logist'): ?>
        <div class="form-section">
            <h3 class="panel-head-title">Техническая информация</h3>
            <dl class="kv">
                <dt>Дата создания</dt>
                <dd><?= e($logist['created_at'] ?? '') ?></dd>
                <dt>Дата обновления</dt>
                <dd><?= e($logist['updated_at'] ?? '') ?></dd>
            </dl>
        </div>
        <?php endif; ?>

        <div class="form-section">
            <h3 class="panel-head-title">Действия</h3>
            <form method="post" action="/company/logists/<?= $logist['id'] ?>/reset-password" class="mb-3">
                <p class="text-muted muted-copy">Вы уверены? Текущий пароль будет заменён. Новый пароль будет показан только один раз. Если пользователь состоит в нескольких компаниях, новый пароль действует для всех.</p>
                <button type="submit" class="btn btn-secondary">Сбросить пароль</button>
            </form>
            <?php if ($logist['status'] !== 'archived'): ?>
            <form method="post" action="/company/logists/<?= $logist['id'] ?>/archive" onsubmit="return confirm('Удалить запись? Запись будет удалена из списка.')">
                <button type="submit" class="btn btn-secondary">Удалить</button>
            </form>
            <?php endif; ?>
        </div>

        <div class="form-actions mt-4">
            <a href="/company/logists/<?= $logist['id'] ?>/edit" class="btn btn-primary">Редактировать</a>
            <a href="<?= app_url('/company/logists') ?>" class="btn btn-ghost">← К списку</a>
        </div>

    </div>
</div>

<?php endif; ?>
