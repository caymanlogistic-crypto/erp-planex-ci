<?php if ($company === null): ?>

<div class="notice warn">
    Компания не найдена. Укажите корректный company_id.
</div>

<?php elseif ($company['status'] !== 'active'): ?>

<div class="page-head">
    <div>
        <h1>Редактировать транспортную единицу</h1>
        <p class="text-muted">Компания: <?= e($company['name']) ?></p>
    </div>
    <div class="page-head-actions">
        <a href="<?= app_url('/company/vehicles') ?>" class="btn btn-ghost">← К списку</a>
    </div>
</div>

<div class="notice warn">
    Компания находится в статусе «<?= e($company['status']) ?>». редактирование транспортной единицы недоступно.
</div>

<?php elseif (isset($dbError)): ?>

<div class="page-head">
    <div>
        <h1>Редактировать транспортную единицу</h1>
        <p class="text-muted">Компания: <?= e($company['name']) ?></p>
    </div>
    <div class="page-head-actions">
        <a href="<?= app_url('/company/vehicles') ?>" class="btn btn-ghost">← К списку</a>
    </div>
</div>

<div class="notice warn">
    <?= e($dbError) ?>
</div>

<?php elseif ($entityNotFound): ?>

<div class="page-head">
    <div>
        <h1>Транспортная единица не найдена</h1>
        <p class="text-muted">Компания: <?= e($company['name']) ?></p>
    </div>
    <div class="page-head-actions">
        <a href="<?= app_url('/company/vehicles') ?>" class="btn btn-ghost">← К списку</a>
    </div>
</div>

<div class="notice warn">
    Транспортная единица с ID <?= e((string)$vehicleId) ?> не найден в этой компании.
</div>

<?php elseif ($success): ?>

<div class="page-head">
    <div>
        <h1>Транспортная единица обновлена</h1>
        <p class="text-muted">Компания: <?= e($company['name']) ?></p>
    </div>
    <div class="page-head-actions">
        <a href="/company/vehicles/<?= $vehicleId ?>" class="btn btn-primary">← К просмотру</a>
    </div>
</div>

<div class="panel">
    <div class="panel-body">
        <div class="notice success">
            Транспортная единица обновлена.
        </div>

        <div class="kv mt-4">
            <div class="kv-row">
                <span class="kv-key">Госномер</span>
                <span class="kv-value"><code><?= e($vehicle['plate_number']) ?></code></span>
            </div>
            <div class="kv-row">
                <span class="kv-key">Марка</span>
                <span class="kv-value"><?= e($vehicle['brand'] ?? '') ?: '—' ?></span>
            </div>
            <div class="kv-row">
                <span class="kv-key">Модель</span>
                <span class="kv-value"><?= e($vehicle['model'] ?? '') ?: '—' ?></span>
            </div>
            <div class="kv-row">
                <span class="kv-key">Статус</span>
                <span class="kv-value">
                    <?php if ($vehicle['status'] === 'active'): ?>
                        <span class="badge badge-ok"><span class="dot"></span>Активен</span>
                    <?php elseif ($vehicle['status'] === 'archived'): ?>

                    <?php else: ?>
                        <span class="badge"><span class="dot"></span>Неактивен</span>
                    <?php endif; ?>
                </span>
            </div>
        </div>

        <div class="form-actions mt-4">
            <a href="/company/vehicles/<?= $vehicleId ?>" class="btn btn-primary">← К просмотру</a>
            <a href="<?= app_url('/company/vehicles') ?>" class="btn btn-ghost">← К списку</a>
        </div>
    </div>
</div>

<?php else: ?>

<div class="page-head">
    <div>
        <h1>Редактировать транспортную единицу</h1>
        <p class="text-muted">Компания: <?= e($company['name']) ?></p>
    </div>
    <div class="page-head-actions">
        <a href="/company/vehicles/<?= $vehicleId ?>" class="btn btn-ghost">← К просмотру</a>
    </div>
</div>

<?php if ($formError): ?>
    <div class="notice warn"><?= e($formError) ?></div>
<?php endif; ?>

<form method="post" action="/company/vehicles/<?= $vehicleId ?>/edit" class="panel">
    <div class="panel-body">

        <div class="form-section">
            <h3 class="panel-head-title">Основные данные</h3>

            <div class="field">
                <label class="field-label">Госномер <span class="req">*</span></label>
                <input type="text" name="plate_number" class="field-input" required
                       value="<?= e($old['plate_number'] ?? '') ?>">
                <?php if (!empty($errors['plate_number'])): ?>
                    <div class="field-msg is-error"><?= e($errors['plate_number']) ?></div>
                <?php endif; ?>
            </div>
        </div>

        <div class="form-section">
            <h3 class="panel-head-title">Характеристики ТС</h3>

            <div class="field">
                <label class="field-label">Марка</label>
                <input type="text" name="brand" class="field-input"
                       value="<?= e($old['brand'] ?? '') ?>">
            </div>

            <div class="field">
                <label class="field-label">Модель</label>
                <input type="text" name="model" class="field-input"
                       value="<?= e($old['model'] ?? '') ?>">
            </div>

            <div class="field">
                <label class="field-label">Тип единицы <span class="req">*</span></label>
                <select name="unit_type" class="field-input" required>
                    <option value="">Укажите тип (обязательно)</option>
                    <option value="single" <?= ($old['unit_type'] ?? '') === 'single' ? 'selected' : '' ?>>Одиночное ТС</option>
                    <option value="tractor" <?= ($old['unit_type'] ?? '') === 'tractor' ? 'selected' : '' ?>>Тягач</option>
                    <option value="semi_trailer" <?= ($old['unit_type'] ?? '') === 'semi_trailer' ? 'selected' : '' ?>>Полуприцеп</option>
                    <option value="truck" <?= ($old['unit_type'] ?? '') === 'truck' ? 'selected' : '' ?>>Грузовик</option>
                    <option value="trailer" <?= ($old['unit_type'] ?? '') === 'trailer' ? 'selected' : '' ?>>Прицеп</option>
                </select>
                <?php if (!empty($errors['unit_type'])): ?>
                    <div class="field-msg is-error"><?= e($errors['unit_type']) ?></div>
                <?php endif; ?>
            </div>

            <div class="field">
                <label class="field-label">Грузоподъёмность (т)</label>
                <input type="number" name="capacity_tons" class="field-input" step="0.01"
                       value="<?= e($old['capacity_tons'] ?? '') ?>">
            </div>

            <div class="field">
                <label class="field-label">Объём кузова (м³)</label>
                <input type="number" name="volume_m3" class="field-input" step="0.01"
                       value="<?= e($old['volume_m3'] ?? '') ?>">
            </div>
        </div>

        <div class="form-section">
            <h3 class="panel-head-title">Документы ТС</h3>

            <div class="field">
                <label class="field-label">VIN</label>
                <input type="text" name="vin" class="field-input"
                       value="<?= e($old['vin'] ?? '') ?>">
            </div>

            <div class="field">
                <label class="field-label">СТС</label>
                <input type="text" name="sts_number" class="field-input"
                       value="<?= e($old['sts_number'] ?? '') ?>">
            </div>
        </div>

        <div class="form-section">
            <h3 class="panel-head-title">Статус</h3>

            <div class="field">
                <label class="field-label">Статус</label>
                <select name="status" class="field-input">
                    <option value="active" <?= ($old['status'] ?? '') === 'active' ? 'selected' : '' ?>>Активен</option>
                    <option value="inactive" <?= ($old['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Неактивен</option>

                </select>
            </div>
        </div>

        <div class="form-section">
            <h3 class="panel-head-title">Дополнительно</h3>

            <div class="field">
                <label class="field-label">Комментарий</label>
                <textarea name="comments" class="field-textarea" rows="3"><?= e($old['comments'] ?? '') ?></textarea>
            </div>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Сохранить изменения</button>
            <a href="/company/vehicles/<?= $vehicleId ?>" class="btn btn-ghost">← К просмотру</a>
        </div>

    </div>
</form>

<?php endif; ?>
