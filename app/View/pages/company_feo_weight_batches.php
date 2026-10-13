<?php $weightBase = app_url('/company/feo/requests/weights'); ?>
<link rel="stylesheet" href="<?= app_url('/assets/css/feo-transfers.css') ?>?v=<?= filemtime(__DIR__.'/../../../public/assets/css/feo-transfers.css') ?>">
<link rel="stylesheet" href="<?= app_url('/assets/css/feo-weights.css') ?>?v=<?= filemtime(__DIR__.'/../../../public/assets/css/feo-weights.css') ?>">
<div class="feo-transfers feo-weights" data-feo-weights data-base="<?= e($weightBase) ?>">
  <div class="page-head">
    <div class="page-head-left"><h1 class="page-title">Изменение веса заявок</h1><div class="page-summary">Задайте новый вес нетто и брутто для каждой заявки. Изменения сохраняются одним блоком с историей и возможностью возврата.</div></div>
    <?php if (!$dbError): ?><div class="page-head-actions"><button class="btn btn-primary" type="button" data-open-weight>Создать блок</button></div><?php endif; ?>
  </div>
  <div class="form-alert" data-page-message role="status" hidden></div>
  <?php if ($dbError): ?><div class="form-alert alert-warning"><?= e($dbError) ?></div><?php else: ?>
  <div class="table-card table-card--standard">
    <div class="table-toolbar"><div class="found-label">История блоков <span class="muted">· страница <?= $page ?></span></div><input class="toolbar-search" type="search" data-history-search placeholder="Поиск на странице" aria-label="Поиск блоков"></div>
    <div class="table-scroll"><table class="table transfer-history"><thead><tr><th>Блок / дата</th><th>Заявок</th><th>Автор</th><th>Состояние</th><th>Действия</th></tr></thead><tbody>
      <?php if (!$batches): ?><tr><td colspan="5"><div class="transfer-empty"><b>Изменений веса пока нет</b><p>Создайте блок, добавьте номера заявок и укажите новый вес каждой.</p><button class="btn btn-primary" type="button" data-open-weight>Создать первый блок</button></div></td></tr><?php endif; ?>
      <?php foreach ($batches as $batch): ?><tr data-batch-row><td><strong>#<?= (int)$batch['id'] ?> · <?= e($batch['name']) ?></strong><div class="muted"><?= e($batch['created_at']) ?></div></td><td><?= (int)$batch['request_count'] ?></td><td><?= e($batch['created_by_name']) ?></td><td><span class="badge <?= $batch['status']==='applied'?'badge-ok':'badge-neutral' ?>"><?= $batch['status']==='applied'?'Применён':'Возвращён' ?></span></td><td><div class="inline-actions"><button class="btn btn-secondary btn-sm" type="button" data-detail="<?= (int)$batch['id'] ?>">Подробнее</button><?php if ($batch['status']==='applied'): ?><button class="btn btn-secondary btn-sm" type="button" data-revert="<?= (int)$batch['id'] ?>">Вернуть блок</button><?php endif; ?></div></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <div class="transfer-pagination"><?php if ($page>1): ?><a class="btn btn-secondary btn-sm" href="<?= e($weightBase.'?page='.($page-1)) ?>">← Новее</a><?php endif; ?><?php if (count($batches)===50): ?><a class="btn btn-secondary btn-sm" href="<?= e($weightBase.'?page='.($page+1)) ?>">Ранее →</a><?php endif; ?></div>
  </div>
  <dialog class="modal modal-lg transfer-dialog weight-dialog" data-create-dialog aria-labelledby="weight-create-title">
    <div class="modal-head"><span class="modal-title" id="weight-create-title">Новый блок изменения веса</span><button type="button" class="modal-close" data-close-dialog aria-label="Закрыть">&times;</button></div>
    <form data-weight-form>
      <?= csrfField() ?>
      <div class="modal-body weight-form-body">
        <div class="field"><label class="field-label" for="weight-name">Название, необязательно</label><input class="field-input" id="weight-name" name="name" maxlength="180"></div>
        <div class="field"><label class="field-label" for="weight-ids">Заявки</label><textarea class="field-input" id="weight-ids" name="request_ids" rows="4" required placeholder="Номера заявок через пробел, запятую или новую строку"></textarea></div>
        <button class="btn btn-secondary" type="submit" data-load>Загрузить текущий вес</button>
        <section data-edit-section hidden aria-live="polite"><div class="form-alert alert-warning" data-edit-summary></div><p class="muted">Вес указывается в килограммах с точностью до 0,1 кг. Пустое поле удаляет значение веса. Изменённые строки сохраняются в одном блоке.</p>
          <div class="table-scroll"><table class="table weight-edit-table"><thead><tr><th>Заявка</th><th>Нетто сейчас, кг</th><th>Нетто новое, кг</th><th>Брутто сейчас, кг</th><th>Брутто новое, кг</th></tr></thead><tbody data-edit-rows></tbody></table></div>
          <div class="field"><label class="field-label" for="weight-comment">Комментарий, необязательно</label><input class="field-input" id="weight-comment" name="comment" maxlength="2000"></div>
          <div class="form-alert alert-warning" data-form-error role="alert" hidden></div>
          <div class="weight-save-actions"><button class="btn btn-primary" type="button" data-commit>Проверить и сохранить блок</button></div>
        </section>
      </div><div class="modal-foot"><button class="btn btn-ghost" type="button" data-close-dialog>Закрыть</button></div>
    </form>
  </dialog>
  <dialog class="modal modal-lg transfer-dialog weight-dialog" data-detail-dialog aria-labelledby="weight-detail-title"><div class="modal-head"><span class="modal-title" id="weight-detail-title">История блока</span><button class="modal-close" type="button" data-close-dialog aria-label="Закрыть">&times;</button></div><div data-detail-body class="modal-body transfer-detail-body"></div></dialog>
  <?php endif; ?>
</div>
<script src="<?= app_url('/assets/js/feo-weights.js') ?>?v=<?= filemtime(__DIR__.'/../../../public/assets/js/feo-weights.js') ?>" defer></script>
