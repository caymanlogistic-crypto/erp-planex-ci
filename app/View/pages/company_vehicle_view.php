<?php if ($company === null): ?>

<div class="notice warn">
    Компания не найдена. Укажите корректный company_id.
</div>

<?php elseif ($company['status'] !== 'active'): ?>

<div class="page-head">
    <div class="page-head-left">
        <h1 class="page-title">Транспортные единицы</h1>
        <div class="page-summary"><span>Компания: <?= e($company['name']) ?></span></div>
    </div>
</div>

<div class="notice warn">
    Компания находится в статусе «<?= e($company['status']) ?>». Просмотр транспортной единицы недоступен.
</div>

<?php elseif (isset($dbError)): ?>

<div class="page-head">
    <div class="page-head-left">
        <h1 class="page-title">Транспортные единицы</h1>
        <div class="page-summary"><span>Компания: <?= e($company['name']) ?></span></div>
    </div>
</div>

<div class="notice warn">
    <?= e($dbError) ?>
</div>

<?php elseif ($entityNotFound): ?>

<div class="page-head">
    <div class="page-head-left">
        <h1 class="page-title">Транспортная единица не найдена</h1>
        <div class="page-summary"><span>Компания: <?= e($company['name']) ?></span></div>
    </div>
    <div class="page-head-actions">
        <a href="<?= app_url('/company/vehicles') ?>" class="btn btn-ghost">← К списку</a>
    </div>
</div>

<div class="notice warn">
    Транспортная единица с ID <?= e((string)$vehicleId) ?> не найдена в этой компании.
</div>

<?php elseif (isset($accessDenied)): ?>

<div class="page-head">
    <div class="page-head-left">
        <h1 class="page-title">Доступ запрещён</h1>
        <div class="page-summary"><span>Компания: <?= e($company['name']) ?></span></div>
    </div>
    <div class="page-head-actions">
        <a href="<?= app_url('/company/vehicles') ?>" class="btn btn-ghost">← К списку</a>
    </div>
</div>

<div class="panel">
    <div class="panel-body">
        <div class="empty-state">
            <div class="empty-icon">🔒</div>
            <p class="empty-title">Доступ запрещён</p>
            <p class="empty-desc"><?= e($accessDenied) ?> Для получения доступа обратитесь к руководителю компании.</p>
            <div class="form-actions">
                <a href="<?= app_url('/company/vehicles') ?>" class="btn btn-ghost">← К списку</a>
                <a href="<?= app_url('/company/dashboard') ?>" class="btn btn-primary">На главную</a>
            </div>
        </div>
    </div>
</div>

<?php else: ?>

<div class="page-head">
    <div class="page-head-left">
        <h1 class="page-title">Транспортная единица: <?= e($vehicle['plate_number']) ?></h1>
        <div class="page-summary"><span>Компания: <?= e($company['name']) ?></span></div>
    </div>
    <div class="page-head-actions">
        <a href="/company/vehicles/<?= $vehicle['id'] ?>/edit" class="btn btn-primary">Редактировать</a>
        <a href="<?= app_url('/company/vehicles') ?>" class="btn btn-ghost">← К списку</a>
        <a href="/company/documents?entity_type=vehicle_unit&entity_id=<?= $vehicle['id'] ?>" class="btn btn-ghost">Документы</a>
    </div>
</div>

<div class="panel">
    <div class="panel-body">

        <div class="form-section">
            <h3 class="panel-head-title">Основные данные</h3>
            <dl class="kv">
                <dt>Госномер</dt>
                <dd><code><?= e($vehicle['plate_number']) ?></code></dd>
                <dt>Марка</dt>
                <dd><?= e($vehicle['brand'] ?? '') ?: '—' ?></dd>
                <dt>Модель</dt>
                <dd><?= e($vehicle['model'] ?? '') ?: '—' ?></dd>
                <dt>Тип единицы</dt>
                <dd><?= e(ui_unit_type($vehicle['unit_type'] ?? null)) ?></dd>
                <dt>Статус</dt>
                <dd>
                    <?php if ($vehicle['status'] === 'active'): ?>
                        <span class="badge badge-ok"><span class="dot"></span>Активен</span>
                    <?php elseif ($vehicle['status'] === 'archived'): ?>

                    <?php else: ?>
                        <span class="badge"><span class="dot"></span>Неактивен</span>
                    <?php endif; ?>
                </dd>
                <dt>Комментарий</dt>
                <dd><?= e($vehicle['comments'] ?? '') ?: '—' ?></dd>
            </dl>
        </div>

        <div class="form-section">
            <h3 class="panel-head-title">Технические данные</h3>
            <dl class="kv">
                <dt>VIN</dt>
                <dd><?= !empty($vehicle['vin_absent']) ? 'Отсутствует' : '<code>' . (e($vehicle['vin'] ?? '') ?: '—') . '</code>' ?></dd>
                <dt>СТС</dt>
                <dd><?= e($vehicle['sts_number'] ?? '') ?: '—' ?></dd>
                <dt>Грузоподъёмность (т)</dt>
                <dd><?= $vehicle['capacity_tons'] !== null ? e((string)$vehicle['capacity_tons']) : '—' ?></dd>
                <dt>Объём кузова (м³)</dt>
                <dd><?= $vehicle['volume_m3'] !== null ? e((string)$vehicle['volume_m3']) : '—' ?></dd>
            </dl>
        </div>

        <div class="form-section">
            <h3 class="panel-head-title">Связанные транспортные комплекты</h3>
            <?php if (empty($relatedSets)): ?>
                <p class="text-muted">Нет связанных транспортных комплектов.</p>
            <?php else: ?>
            <div class="tbl-wrap">
                <table class="tbl">
                    <thead>
                        <tr>
                            <th>ID комплекта</th>
                            <th>Тип комплекта</th>
                            <th>Основная ед.</th>
                            <th>Доп. ед.</th>
                            <th>Статус</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($relatedSets as $rs): ?>
                    <tr>
                        <td class="col-mono"><?= $rs['id'] ?></td>
                        <td><?= e(ui_set_type($rs['set_type'] ?? null)) ?></td>
                        <td class="col-mono"><?= e($rs['primary_plate'] ?? '—') ?></td>
                        <td class="col-mono"><?= e($rs['secondary_plate'] ?? '—') ?></td>
                        <td>
                            <?php if ($rs['status'] === 'active'): ?>
                                <span class="badge badge-ok"><span class="dot"></span>Активен</span>
                            <?php else: ?>
                                <span class="badge"><span class="dot"></span><?= e($rs['status'] ?? 'Неактивен') ?></span>
<?php endif; ?>
<?php require base_path('app/View/partials/delete_confirm_modal.php'); ?>
                        </td>
                        <td class="col-actions">
                            <a href="/company/vehicle-sets/<?= $rs['id'] ?>" class="btn btn-toolbar">Просмотр</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <?php if (($_SESSION['role_code'] ?? '') !== 'logist'): ?>
        <div class="form-section">
            <h3 class="panel-head-title">Техническая информация</h3>
            <dl class="kv">
                <dt>Создан</dt>
                <dd><?= e(ui_date($vehicle['created_at'] ?? null)) ?></dd>
                <dt>Обновлён</dt>
                <dd><?= e(ui_date($vehicle['updated_at'] ?? null)) ?></dd>
            </dl>
        </div>
        <?php endif; ?>

        <?php
        $assignmentEntityType = 'vehicle_unit';
        $assignmentEntityId = (int) ($vehicle['id'] ?? 0);
        require base_path('app/View/partials/company_entity_assignment_block.php');
        ?>

        <div class="form-section">
            <h3 class="panel-head-title">Опасная зона</h3>
            <p class="text-muted mb-compact">Запись будет удалена из списка.</p>
            <form method="post" action="/company/vehicles/<?= $vehicle['id'] ?>/archive">
                <button type="button" class="btn btn-danger" onclick="window.confirmDeleteForm(this)">Удалить</button>
            </form>
        </div>

    </div>
</div>

<?php endif; ?>
