<?php if ($company === null): ?>

<div class="notice warn">
    Компания не найдена. Укажите корректный company_id.
</div>

<?php elseif ($company['status'] !== 'active'): ?>

<div class="page-head">
    <div>
        <h1>Исполнитель рейса</h1>
        <p class="text-muted">Компания: <?= e($company['name']) ?></p>
    </div>
</div>

<div class="notice warn">
    Компания находится в статусе «<?= e($company['status']) ?>». Просмотр исполнителя рейса недоступен.
</div>

<?php elseif (isset($dbError)): ?>

<div class="page-head">
    <div>
        <h1>Исполнитель рейса</h1>
        <p class="text-muted">Компания: <?= e($company['name']) ?></p>
    </div>
</div>

<div class="notice warn">
    <?= e($dbError) ?>
</div>

<?php elseif ($entityNotFound): ?>

<div class="page-head">
    <div>
        <h1>Исполнитель рейса не найден</h1>
        <p class="text-muted">Компания: <?= e($company['name']) ?></p>
    </div>
    <div class="page-head-actions">
        <a href="<?= app_url('/company/route-executors') ?>" class="btn btn-ghost">← К списку</a>
    </div>
</div>

<div class="notice warn">
    Исполнитель рейса с ID <?= e((string)$crewId) ?> не найден в этой компании.
</div>

<?php elseif (isset($accessDenied)): ?>

<div class="page-head">
    <div>
        <h1>Доступ запрещён</h1>
        <p class="text-muted">Компания: <?= e($company['name']) ?></p>
    </div>
    <div class="page-head-actions">
        <a href="<?= app_url('/company/route-executors') ?>" class="btn btn-ghost">← К списку</a>
    </div>
</div>

<div class="panel">
    <div class="panel-body">
        <div class="empty-state">
            <p class="empty-title">Доступ запрещён</p>
            <p class="empty-desc"><?= e($accessDenied) ?> Для получения доступа обратитесь к руководителю компании.</p>
            <div class="form-actions">
                <a href="<?= app_url('/company/route-executors') ?>" class="btn btn-ghost">← К списку исполнителей рейса</a>
                <a href="<?= app_url('/company/dashboard') ?>" class="btn btn-primary">На главную</a>
            </div>
        </div>
    </div>
</div>

<?php else: ?>

<div class="page-head">
    <div>
        <div class="page-eyebrow">ИСПОЛНИТЕЛИ РЕЙСА / <?= e(mb_strtoupper($company['name'])) ?></div>
        <h1>Исполнитель рейса #<?= $crew['id'] ?></h1>
        <p class="text-muted">Компания: <?= e($company['name']) ?></p>
    </div>
    <div class="page-head-actions">
        <a href="/company/route-executors/<?= $crew['id'] ?>/edit" class="btn btn-primary">Редактировать</a>
        <a href="<?= app_url('/company/route-executors') ?>" class="btn btn-ghost">← К списку</a>
    </div>
</div>

<div class="panel">
    <div class="panel-body">

        <div class="route-executor-view-card" data-route-executor-view-card>

            <div class="re-section">
                <h3 class="re-section-title">Подрядчик</h3>
                <div class="re-grid">
                    <div class="re-item">
                        <span class="re-label">Название</span>
                        <span class="re-value">
                            <?php if (!empty($crew['contractor_id'])): ?>
                                <a href="/company/contractors/<?= $crew['contractor_id'] ?>"><?= e($crew['contractor_name'] ?? '') ?: '—' ?></a>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="re-item">
                        <span class="re-label">ИНН</span>
                        <span class="re-value"><?= e($crew['contractor_inn'] ?? '') ?: '—' ?></span>
                    </div>
                </div>
            </div>

            <div class="re-section">
                <h3 class="re-section-title">Водитель</h3>
                <div class="re-grid">
                    <div class="re-item">
                        <span class="re-label">ФИО</span>
                        <span class="re-value">
                            <?php if (!empty($crew['driver_id'])): ?>
                                <a href="/company/drivers/<?= $crew['driver_id'] ?>"><?= e($crew['driver_name'] ?? '') ?: '—' ?></a>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="re-item">
                        <span class="re-label">Телефон</span>
                        <span class="re-value"><?= e($crew['driver_phone'] ?? '') ?: '—' ?></span>
                    </div>
                </div>
            </div>

            <div class="re-section">
                <h3 class="re-section-title">Транспорт (ТС)</h3>
                <div class="re-grid">
                    <div class="re-item">
                        <span class="re-label">Тип комплекта</span>
                        <span class="re-value"><?= e(ui_set_type($crew['set_type'] ?? null)) ?: '—' ?></span>
                    </div>
                    <div class="re-item">
                        <span class="re-label">Госномер</span>
                        <span class="re-value">
                            <?php if (!empty($crew['vehicle_set_id'])): ?>
                                <a href="/company/vehicle-sets/<?= $crew['vehicle_set_id'] ?>">
                                    <code><?= e($crew['primary_plate'] ?? '—') ?></code>
                                    <?php if (!empty($crew['secondary_plate'])): ?>
                                        + <code><?= e($crew['secondary_plate']) ?></code>
                                    <?php endif; ?>
                                </a>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </span>
                    </div>
                </div>
            </div>

            <div class="re-section">
                <h3 class="re-section-title">Статус и ответственный</h3>
                <div class="re-grid">
                    <div class="re-item">
                        <span class="re-label">Ответственный логист</span>
                        <span class="re-value"><?= e($crew['responsible_name'] ?? $crew['created_by_name'] ?? '') ?: '—' ?></span>
                    </div>
                    <div class="re-item">
                        <span class="re-label">Статус</span>
                        <span class="re-value">
                            <?php if ($crew['status'] === 'active'): ?>
                                <span class="badge badge-ok"><span class="dot"></span>Активен</span>
                            <?php elseif ($crew['status'] === 'archived'): ?>

                            <?php else: ?>
                                <span class="badge"><span class="dot"></span>Неактивен</span>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="re-item re-item--wide">
                        <span class="re-label">Комментарий</span>
                        <span class="re-value"><?= e($crew['comments'] ?? '') ?: '—' ?></span>
                    </div>
                </div>
            </div>

            <?php if (($_SESSION['role_code'] ?? '') !== 'logist'): ?>
            <div class="re-section">
                <h3 class="re-section-title">Техническая информация</h3>
                <div class="re-grid">
                    <div class="re-item">
                        <span class="re-label">Создан</span>
                        <span class="re-value"><?= e(ui_date($crew['created_at'] ?? null)) ?></span>
                    </div>
                    <div class="re-item">
                        <span class="re-label">Обновлён</span>
                        <span class="re-value"><?= e(ui_date($crew['updated_at'] ?? null)) ?></span>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <div class="re-section" data-re-section-actions>
                <h3 class="re-section-title">Действия</h3>
                <div class="form-actions">
                    <a href="/company/route-executors/<?= $crew['id'] ?>/edit" class="btn btn-primary">Редактировать</a>
                    <?php if (($crew['status'] ?? '') !== 'archived'): ?>
                    <form method="post" action="/company/route-executors/<?= $crew['id'] ?>/archive" class="inline-form">
                        <button type="button" class="btn btn-danger" onclick="window.confirmDeleteForm(this)">Удалить</button>
                    </form>
                    <?php else: ?>
                        <p class="text-muted">Исполнитель рейса уже удалён.</p>
                    <?php endif; ?>
                    <a href="<?= app_url('/company/route-executors') ?>" class="btn btn-ghost">← К списку</a>
                </div>
            </div>

        </div>

    </div>
</div>

<?php require base_path('app/View/partials/delete_confirm_modal.php'); ?>

<?php endif; ?>
