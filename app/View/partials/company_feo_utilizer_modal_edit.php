<?php
$old = $old ?? [];
$utilizerId = (int)($old['id'] ?? 0);
?>
<div class="modal-body driver-modal-body">
    <?php if (!empty($formError)): ?><div class="notice warn"><?= e((string)$formError) ?></div><?php endif; ?>
    <form id="feo-utilizer-edit-form" data-feo-utilizer-edit-form method="post" action="<?= app_url('/company/feo/utilizers/' . $utilizerId . '/modal-edit') ?>">
        <div class="feo-utilizer-form-grid">
            <label class="field feo-utilizer-span-2"><span class="field-label">Полное название <span class="req">*</span></span><input class="field-input" name="full_name" maxlength="255" required value="<?= e((string)($old['full_name'] ?? '')) ?>"></label>
            <label class="field"><span class="field-label">Короткое название <span class="req">*</span></span><input class="field-input" name="short_name" maxlength="80" required value="<?= e((string)($old['short_name'] ?? '')) ?>"></label>
            <label class="field"><span class="field-label">Порядок</span><input class="field-input" name="sort_order" type="number" min="-100000" max="100000" value="<?= (int)($old['sort_order'] ?? 100) ?>"></label>
            <label class="field feo-utilizer-span-2"><span class="field-label">Адрес</span><input class="field-input" name="address" maxlength="500" value="<?= e((string)($old['address'] ?? '')) ?>"></label>
            <label class="field"><span class="field-label">Широта</span><input class="field-input" name="latitude" inputmode="decimal" value="<?= e((string)($old['latitude'] ?? '')) ?>"></label>
            <label class="field"><span class="field-label">Долгота</span><input class="field-input" name="longitude" inputmode="decimal" value="<?= e((string)($old['longitude'] ?? '')) ?>"></label>
            <label class="field feo-utilizer-span-2"><span class="field-label">ФККО / классификация</span><input class="field-input" name="fkko_classification" maxlength="500" value="<?= e((string)($old['fkko_classification'] ?? '')) ?>"></label>
            <label class="field"><span class="field-label">Цвет маркера</span><div class="feo-utilizer-color-input"><input name="color_hex" type="color" value="<?= e((string)($old['color_hex'] ?? '#2563EB')) ?>" data-feo-color-input><span class="col-mono" data-feo-color-value><?= e((string)($old['color_hex'] ?? '#2563EB')) ?></span></div></label>
            <div class="feo-utilizer-checks"><label class="feo-utilizer-check"><input name="adr" type="checkbox" value="1" <?= (int)($old['adr'] ?? 0) === 1 ? 'checked' : '' ?>> ADR</label><label class="feo-utilizer-check"><input name="is_active" type="checkbox" value="1" <?= (int)($old['is_active'] ?? 0) === 1 ? 'checked' : '' ?>> Активен</label></div>
            <label class="field feo-utilizer-span-2"><span class="field-label">Синонимы названия</span><textarea class="field-input feo-utilizer-alias-input" name="aliases" rows="5" placeholder="По одному варианту названия на строку"><?= e((string)($old['aliases'] ?? '')) ?></textarea><span class="field-hint">Используются для точного сопоставления названия грузополучателя из заявки.</span></label>
        </div>
    </form>
</div>
<div class="modal-foot is-spaced">
    <div class="modal-required-note"><span class="req">*</span> — обязательные поля</div>
    <div class="modal-foot-actions"><button type="button" class="btn btn-secondary" data-feo-utilizer-cancel-edit-btn>Отмена</button><button type="submit" form="feo-utilizer-edit-form" class="btn btn-primary">Сохранить</button></div>
</div>
