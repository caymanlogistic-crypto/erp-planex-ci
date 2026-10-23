<?php $weightBase = app_url('/company/feo/requests/weights'); ?>
<?php $fmtHistoryDate = static function ($value): string { $time = strtotime((string)$value); return $time === false ? (string)$value : date('d.m.Y H:i', $time); }; require_once base_path('app/View/components/registry_tabler_icons.php'); ?>
<link rel="stylesheet" href="<?= app_url('/assets/css/registry-components.css') ?>?v=<?= filemtime(base_path('public/assets/css/registry-components.css')) ?>">
<link rel="stylesheet" href="<?= app_url('/assets/css/feo-transfers.css') ?>?v=<?= filemtime(__DIR__.'/../../../public/assets/css/feo-transfers.css') ?>">
<link rel="stylesheet" href="<?= app_url('/assets/css/feo-weights.css') ?>?v=<?= filemtime(__DIR__.'/../../../public/assets/css/feo-weights.css') ?>">
<div class="feo-transfers feo-weights" data-feo-weights data-base="<?= e($weightBase) ?>">
  <?php $registryHeadTitle='Изменение веса заявок'; $registryHeadSummary='Задайте новый вес нетто и брутто для каждой заявки. Изменения сохраняются одним блоком с историей и возможностью возврата.'; $registryHeadIconHtml=registry_tabler_header_icon('arrows-exchange'); $registryHeadActionsHtml=$dbError?'':'<button class="btn btn-primary" type="button" data-open-weight>Создать блок</button>'; require base_path('app/View/partials/registry_ui_head.php'); ?>
  <div class="form-alert" data-page-message role="status" hidden></div>
  <?php if ($dbError): ?><div class="form-alert alert-warning"><?= e($dbError) ?></div><?php else: ?>
  <?php if (class_exists(\App\Security\RegistryUiStateScope::class)): require base_path('app/View/partials/registry_ui_state_scope.php'); else: $registryUiStateScope=''; endif; ?>
  <div class="table-card table-card--standard registry-ui-card registry-ui-sticky-table registry-ui-sticky-table--wide-scroll registry-ui-scroll" data-registry-id="feo-weights" data-registry-state-scope="<?= e($registryUiStateScope) ?>">
    <div class="table-toolbar registry-ui-toolbar"><div class="found-label registry-ui-toolbar-summary">Найдено: <b data-history-count><?= count($batches) ?></b> <span>· страница <?= (int)$page ?></span></div><div class="toolbar-right"><button type="button" class="registry-ui-reset" hidden>Сбросить фильтр</button><div class="registry-ui-search-group"><input class="toolbar-search registry-ui-search" type="search" data-history-search data-registry-field="query" data-registry-default="" placeholder="Поиск на странице" aria-label="Поиск блоков"></div></div></div>
    <div class="table-scroll"><table class="table registry-ui-table registry-ui-data transfer-history feo-history-table feo-history-table--weights"><colgroup><col class="registry-ui-id"><col class="feo-history-col-name"><col class="feo-history-col-count"><col class="feo-history-col-date"><col class="feo-history-col-author"><col class="feo-history-col-status"><col class="feo-history-col-actions"></colgroup><thead><tr><th class="registry-ui-id">ID</th><th>Название</th><th>Заявок</th><th>Дата</th><th>Ответств.</th><th>Состояние</th><th>Действия</th></tr></thead><tbody>
      <?php if (!$batches): ?><tr><td colspan="7"><div class="transfer-empty"><b class="registry-ui-name">Изменений веса пока нет</b><p class="registry-ui-secondary">Создайте блок, добавьте номера заявок и укажите новый вес каждой.</p><button class="btn btn-primary" type="button" data-open-weight>Создать первый блок</button></div></td></tr><?php endif; ?>
      <?php foreach ($batches as $batch): ?><tr data-batch-row data-registry-row-id="<?= (int)$batch['id'] ?>" class="<?= $batch['status']==='applied'?'':'registry-ui-muted' ?>"><td class="registry-ui-id">#<?= (int)$batch['id'] ?></td><td><span class="registry-ui-name" title="<?= e($batch['name']) ?>" data-registry-full-text="<?= e($batch['name']) ?>"><?= e(mb_strlen((string)$batch['name'],'UTF-8')>50 ? rtrim(mb_substr((string)$batch['name'],0,49,'UTF-8')).'…' : $batch['name']) ?></span></td><td><span class="registry-ui-primary"><?= (int)$batch['request_count'] ?></span></td><td><span class="registry-ui-primary"><?= e($fmtHistoryDate($batch['created_at'])) ?></span></td><td><span class="registry-ui-primary"><?= e($batch['created_by_name']) ?></span></td><td><span class="registry-ui-status <?= $batch['status']==='applied'?'registry-ui-success':'' ?>"><?= $batch['status']==='applied'?'Применён':'Возвращён' ?></span></td><td><div class="inline-actions"><button class="btn btn-secondary btn-sm" type="button" data-detail="<?= (int)$batch['id'] ?>">Подробнее</button><?php if ($batch['status']==='applied'): ?><button class="btn btn-secondary btn-sm" type="button" data-revert="<?= (int)$batch['id'] ?>">Вернуть блок</button><?php endif; ?></div></td></tr><?php endforeach; ?>
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
<script src="<?= app_url('/assets/js/registry-components.js') ?>?v=<?= filemtime(base_path('public/assets/js/registry-components.js')) ?>" defer></script>
<script src="<?= app_url('/assets/js/feo-weights.js') ?>?v=<?= filemtime(__DIR__.'/../../../public/assets/js/feo-weights.js') ?>" defer></script>
