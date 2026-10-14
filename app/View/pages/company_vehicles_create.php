<?php if ($company === null): ?>

<div class="notice warn">
    Компания не найдена. Укажите корректный company_id.
</div>

<?php elseif ($company['status'] !== 'active'): ?>

<div class="page-head">
    <div>
        <h1>Добавить транспортную единицу</h1>
        <p class="text-muted">Компания: <?= e($company['name']) ?></p>
    </div>
    <div class="page-head-actions">
        <a href="<?= app_url('/company/vehicles') ?>" class="btn btn-ghost">← К списку</a>
    </div>
</div>

<div class="notice warn">
    Компания находится в статусе «<?= e($company['status']) ?>». Добавление транспорта недоступно.
</div>

<?php elseif ($success): ?>

<div class="page-head">
    <div>
        <h1>Транспортная единица добавлена</h1>
        <p class="text-muted">Компания: <?= e($company['name']) ?></p>
    </div>
    <div class="page-head-actions">
        <a href="<?= app_url('/company/vehicles') ?>" class="btn btn-primary">← К списку</a>
    </div>
</div>

<div class="panel">
    <div class="panel-body">
        <div class="notice success">
            Транспорт успешно добавлен.
        </div>

        <div class="kv mt-4">
            <div class="kv-row">
                <span class="kv-key">Госномер</span>
                <span class="kv-value"><code><?= e($createdVehicle['plate_number']) ?></code></span>
            </div>
            <div class="kv-row">
                <span class="kv-key">Марка</span>
                <span class="kv-value"><?= e($createdVehicle['brand'] ?? '—') ?></span>
            </div>
            <div class="kv-row">
                <span class="kv-key">Модель</span>
                <span class="kv-value"><?= e($createdVehicle['model'] ?? '—') ?></span>
            </div>
            <div class="kv-row">
                <span class="kv-key">Тип единицы</span>
                <span class="kv-value"><?= e(ui_unit_type($createdVehicle['unit_type'] ?? null)) ?></span>
            </div>
            <div class="kv-row">
                <span class="kv-key">VIN</span>
                <span class="kv-value"><?= e($createdVehicle['vin'] ?? '—') ?></span>
            </div>
            <div class="kv-row">
                <span class="kv-key">СТС</span>
                <span class="kv-value"><?= e($createdVehicle['sts_number'] ?? '—') ?></span>
            </div>
            <div class="kv-row">
                <span class="kv-key">Грузоподъёмность (т)</span>
                <span class="kv-value"><?= $createdVehicle['capacity_tons'] !== null ? e($createdVehicle['capacity_tons']) : '—' ?></span>
            </div>
            <div class="kv-row">
                <span class="kv-key">Объём кузова (м³)</span>
                <span class="kv-value"><?= $createdVehicle['volume_m3'] !== null ? e($createdVehicle['volume_m3']) : '—' ?></span>
            </div>
            <div class="kv-row">
                <span class="kv-key">Статус</span>
                <span class="kv-value">Активен</span>
            </div>
        </div>

        <div class="form-actions mt-4">
            <a href="<?= app_url('/company/vehicles') ?>" class="btn btn-primary">← К списку</a>
            <a href="<?= app_url('/company/vehicles/create') ?>" class="btn btn-ghost">Добавить ещё</a>
        </div>
    </div>
</div>

<?php else: ?>

<div class="page-head">
    <div>
        <h1>Добавить транспортную единицу</h1>
        <p class="text-muted">Компания: <?= e($company['name']) ?></p>
    </div>
    <div class="page-head-actions">
        <a href="<?= app_url('/company/vehicles') ?>" class="btn btn-ghost">← К списку</a>
    </div>
</div>

<?php if ($formError): ?>
    <div class="notice warn"><?= e($formError) ?></div>
<?php endif; ?>

<form method="post" action="<?= app_url('/company/vehicles/create') ?>" class="panel">
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
            <h3 class="panel-head-title">Дополнительно</h3>

            <div class="field">
                <label class="field-label">Комментарий</label>
                <textarea name="comments" class="field-textarea" rows="3"><?= e($old['comments'] ?? '') ?></textarea>
            </div>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Добавить транспортную единицу</button>
        </div>

    </div>
</form>

<?php endif; ?>
