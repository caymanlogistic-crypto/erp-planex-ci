<?php
$isEdit = is_array($rule ?? null) && (int)($rule['id'] ?? 0) > 0;
$ruleId = $isEdit ? (int)$rule['id'] : 0;
?>
<form method="post" action="<?= e(app_url('/company/finance/closing-documents/exceptions/save')) ?>" data-closing-exception-form>
    <div class="modal-body">
        <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= $ruleId ?>"><?php endif; ?>
        <div class="form-grid form-grid--2">
            <div class="form-field">
                <label class="form-label">ИНН <span class="required">*</span></label>
                <input class="field-input" name="inn" inputmode="numeric" maxlength="20" required value="<?= e((string)($rule['inn'] ?? '')) ?>" placeholder="Например: 7702070139">
                <div class="field-note">Правило применяется только к платежам с этим ИНН.</div>
            </div>
            <div class="form-field">
                <label class="form-label">Назначение содержит</label>
                <input class="field-input" name="purpose_contains" maxlength="255" value="<?= e((string)($rule['purpose_contains'] ?? '')) ?>" placeholder="Например: комиссия">
                <div class="field-note">Необязательно. Если оставить пустым, документы не требуются по всем платежам этого ИНН.</div>
            </div>
            <div class="form-field form-field--wide">
                <label class="form-label">Комментарий</label>
                <input class="field-input" name="comment" maxlength="255" value="<?= e((string)($rule['comment'] ?? '')) ?>" placeholder="Для себя: почему документы не требуются">
            </div>
        </div>
        <div class="notice info" style="margin-top:12px">
            <strong>Как применяется правило:</strong> ИНН должен совпасть точно. Если заполнено поле «Назначение содержит», указанная фраза также должна встречаться в назначении банковского платежа.
        </div>
    </div>
    <div class="modal-foot">
        <?php if ($isEdit): ?><button type="button" class="btn btn-danger" data-delete-closing-exception>Удалить</button><?php endif; ?>
        <span style="flex:1"></span>
        <button type="button" class="btn btn-ghost" data-close-modal="<?= $isEdit ? 'closing-exception-edit-modal' : 'closing-exception-create-modal' ?>">Отмена</button>
        <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Сохранить' : 'Создать правило' ?></button>
    </div>
</form>
<?php if ($isEdit): ?>
<form method="post" action="<?= e(app_url('/company/finance/closing-documents/exceptions/remove')) ?>" id="closing-exception-delete-form-<?= $ruleId ?>" class="is-hidden">
    <input type="hidden" name="id" value="<?= $ruleId ?>">
</form>
<script>
(function(){
    var button = document.querySelector('#closing-exception-edit-body [data-delete-closing-exception]');
    if (!button) return;
    button.addEventListener('click', function () {
        if (window.confirm('Удалить это исключение? Платежи снова будут проверяться на наличие закрывающих документов.')) {
            document.getElementById('closing-exception-delete-form-<?= $ruleId ?>').submit();
        }
    });
})();
</script>
<?php endif; ?>
