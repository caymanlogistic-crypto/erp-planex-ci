<?php // $composeKind, $composeMailId, $request are provided by the request detail view. ?>
<div data-document-compose data-send-url="<?= e(app_url('/company/documents/collection/'.$request['id'].'/send')) ?>">
<form method="post" action="<?= e(app_url('/company/documents/collection/'.$request['id'].'/preview')) ?>"><?= csrfField() ?>
<input type="hidden" name="kind" value="<?= e($composeKind) ?>">
<?php if ($composeKind==='retry'): ?><input type="hidden" name="mail_id" value="<?= (int)$composeMailId ?>">
<?php elseif ($composeKind==='reminder'): ?><div class="field"><label class="field-label">Следующая проверка<input class="field-input" name="next_check_at" type="datetime-local" required></label></div><?php endif; ?>
<button class="btn btn-primary" type="submit">Проверить письмо</button>
</form>
<div class="notice danger" role="alert" data-action-error hidden></div>
<section class="trip-documents__preview" data-compose-preview hidden aria-label="Предпросмотр письма">
<dl class="trip-documents__envelope"></dl><pre class="trip-documents__letter"></pre><ul data-compose-files></ul>
<button class="btn btn-primary" type="button" data-compose-send>Отправить письмо</button>
</section></div>
