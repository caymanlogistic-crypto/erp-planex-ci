<?php
$transferBase=app_url('/company/feo/requests/transfers');
$geoBase=app_url('/company/feo/requests/coordinates');
$fmtHistoryDate = static function ($value): string { $time = strtotime((string)$value); return $time === false ? (string)$value : date('d.m.Y H:i', $time); };
require_once base_path('app/View/components/registry_tabler_icons.php');
?>
<link rel="stylesheet" href="<?= app_url('/assets/css/registry-components.css') ?>?v=<?= filemtime(base_path('public/assets/css/registry-components.css')) ?>">
<link rel="stylesheet" href="<?= app_url('/assets/css/feo-transfers.css') ?>?v=<?= filemtime(__DIR__.'/../../../public/assets/css/feo-transfers.css') ?>">
<div class="feo-transfers" data-feo-transfers data-base="<?= e($transferBase) ?>" data-geo-base="<?= e($geoBase) ?>">
  <?php if (!$coordinatesOnly): ?>
    <?php $registryHeadTitle=$pageTitle; $registryHeadSummary='Перемещайте заявки блоками. Исходные адреса и координаты сохраняются в истории.'; $registryHeadIconHtml=registry_tabler_header_icon('truck-delivery'); $registryHeadActionsHtml=$dbError?'':'<button class="btn btn-primary" type="button" data-open-transfer>Создать блок</button>'; require base_path('app/View/partials/registry_ui_head.php'); ?>
  <?php else: ?>
    <div class="page-head"><div class="page-head-left"><h1 class="page-title"><?= e($pageTitle) ?></h1><div class="page-summary">Найдите адрес и узнайте, насколько точно определены координаты.</div></div></div>
  <?php endif; ?>
  <div class="form-alert" data-page-message role="status" hidden></div>
  <?php if ($dbError): ?><div class="form-alert alert-warning"><?= e($dbError) ?></div><?php else: ?>
  <?php if (!$keyConfigured): ?><div class="form-alert alert-warning">Геокодер пока не настроен. <?php if ($isOwner): ?>Укажите ключ в <a href="<?= e(app_url('/company/settings/services')) ?>">Настройки → Ключи и сервисы</a>.<?php else: ?>Обратитесь к руководителю для настройки Яндекс Геокодера.<?php endif; ?><?php if (!$coordinatesOnly): ?> Перемещение к другой заявке доступно.<?php endif; ?></div><?php endif; ?>

  <?php if (!$coordinatesOnly): ?>
  <?php if (class_exists(\App\Security\RegistryUiStateScope::class)): require base_path('app/View/partials/registry_ui_state_scope.php'); else: $registryUiStateScope=''; endif; ?>
  <div class="table-card table-card--standard registry-ui-card registry-ui-sticky-table registry-ui-sticky-table--wide-scroll registry-ui-scroll" data-registry-id="feo-transfers" data-registry-state-scope="<?= e($registryUiStateScope) ?>">
    <div class="table-toolbar registry-ui-toolbar"><div class="found-label registry-ui-toolbar-summary">Блоки перемещения: <b><?= count($batches) ?></b> <span>· страница <?= (int)$page ?></span></div><div class="toolbar-right"><div class="registry-ui-search-group"><input class="toolbar-search registry-ui-search" type="search" data-history-search data-registry-field="query" data-registry-default="" placeholder="Поиск на странице" aria-label="Поиск блоков"><button type="button" class="registry-ui-reset" hidden>Сбросить</button></div></div></div>
    <div class="table-scroll"><table class="table registry-ui-table registry-ui-data registry-ui-table--placement registry-ui-table--placement-blocks transfer-history feo-history-table feo-history-table--transfers">
      <colgroup><col class="registry-ui-id"><col class="feo-history-col-name"><col class="feo-history-col-destination"><col class="feo-history-col-count"><col class="feo-history-col-date"><col class="feo-history-col-author"><col class="feo-history-col-status"><col class="feo-history-col-actions"></colgroup>
      <thead><tr><th class="registry-ui-id">ID</th><th>Название</th><th>Место назначения</th><th>Заявок</th><th>Дата</th><th>Ответств.</th><th>Состояние</th><th>Действия</th></tr></thead>
      <tbody>
      <?php if (!$batches): ?><tr><td colspan="8"><div class="transfer-empty"><b class="registry-ui-name">Перемещений пока нет</b><p class="registry-ui-secondary">Создайте блок, добавьте номера заявок и выберите новое место погрузки.</p><button class="btn btn-primary" type="button" data-open-transfer>Создать первый блок</button></div></td></tr><?php endif; ?>
      <?php foreach ($batches as $batch): $destination=json_decode($batch['destination_json'],true); ?>
        <tr data-batch-row data-registry-row-id="<?= (int)$batch['id'] ?>" class="<?= $batch['status']==='applied'?'':'registry-ui-muted' ?>"><td class="registry-ui-id">#<?= (int)$batch['id'] ?></td><td><span class="registry-ui-name" title="<?= e($batch['name']) ?>" data-registry-full-text="<?= e($batch['name']) ?>"><?= e(mb_strlen((string)$batch['name'],'UTF-8')>50 ? rtrim(mb_substr((string)$batch['name'],0,49,'UTF-8')).'…' : $batch['name']) ?></span></td>
          <td class="transfer-location"><span class="registry-ui-primary" title="<?= e($destination['address']??'') ?>"><?= e($destination['address']??'') ?></span><span class="registry-ui-secondary"><?= e($destination['quality']??'Точность не подтверждена') ?></span></td>
          <td><span class="registry-ui-primary"><?= (int)$batch['request_count'] ?></span></td><td><span class="registry-ui-primary"><?= e($fmtHistoryDate($batch['created_at'])) ?></span></td><td><span class="registry-ui-primary"><?= e($batch['created_by_name']) ?></span></td>
          <td><span class="registry-ui-status <?= $batch['status']==='applied'?'registry-ui-success':'' ?>"><?= $batch['status']==='applied'?'Применён':'Возвращён' ?></span></td>
          <td><div class="inline-actions"><button class="btn btn-secondary btn-sm" type="button" data-detail="<?= (int)$batch['id'] ?>">Подробнее</button><?php if ($batch['status']==='applied'): ?><button class="btn btn-secondary btn-sm" type="button" data-revert="<?= (int)$batch['id'] ?>">Вернуть блок</button><?php endif; ?></div></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <div class="transfer-pagination"><?php if ($page>1): ?><a class="btn btn-secondary btn-sm" href="<?= e($transferBase.'?page='.($page-1)) ?>">← Новее</a><?php endif; ?><?php if (count($batches)===50): ?><a class="btn btn-secondary btn-sm" href="<?= e($transferBase.'?page='.($page+1)) ?>">Ранее →</a><?php endif; ?></div>
  </div>
  <dialog class="modal modal-lg transfer-dialog" data-create-dialog aria-labelledby="transfer-create-title">
    <div class="modal-head"><span class="modal-title" id="transfer-create-title">Новый блок перемещения</span><button type="button" class="modal-close" data-close-dialog aria-label="Закрыть">&times;</button></div>
  <?php endif; ?>
  <form data-transfer-form class="transfer-form <?= $coordinatesOnly?'panel':'' ?>" <?= $coordinatesOnly?'data-coordinates-only':'' ?>>
    <?= csrfField() ?>
    <?php if (!$coordinatesOnly): ?><div class="modal-body transfer-form-body"><div class="transfer-form-grid"><?php endif; ?>
    <?php if (!$coordinatesOnly): ?>
      <div class="field"><label class="field-label" for="transfer-name">Название, необязательно</label><input class="field-input" id="transfer-name" name="name" maxlength="180"></div>
      <fieldset class="field transfer-modes"><legend class="field-label">Куда переместить</legend><div class="transfer-radio-row"><label class="transfer-radio"><input type="radio" name="mode" value="request" checked> К другой заявке</label><label class="transfer-radio"><input type="radio" name="mode" value="address"> По адресу</label></div></fieldset>
      <div class="field transfer-wide"><label class="field-label" for="transfer-ids">Заявки</label><textarea class="field-input transfer-request-ids" id="transfer-ids" name="request_ids" rows="5" required placeholder="Вставьте любой текст с шестизначными ID. Для Excel/markdown-таблицы используется только первый столбец."></textarea></div>
      <div data-request-destination class="field transfer-wide"><label class="field-label" for="transfer-source">Заявка с нужным адресом</label><input class="field-input" id="transfer-source" name="source_request_id" inputmode="numeric" placeholder="Введите номер заявки"></div>
    <?php endif; ?>
    <div data-address-destination class="<?= $coordinatesOnly?'':'transfer-wide' ?>" <?= $coordinatesOnly?'':'hidden' ?>>
      <div class="field"><label class="field-label" for="geo-address">Адрес места погрузки</label><div class="transfer-address-search"><input class="field-input" id="geo-address" name="address" maxlength="1000" placeholder="Регион, населённый пункт, улица, дом"><button class="btn btn-secondary" type="button" data-geocode>Определить координаты</button></div></div>
      <section class="transfer-geo-result" data-geo-result hidden aria-live="polite">
        <div class="field"><label class="field-label" for="geo-found-address">Найденный адрес</label><output class="field-input transfer-readonly" id="geo-found-address" data-geo-address></output></div>
        <div class="form-grid-2 transfer-coordinates"><div class="field"><label class="field-label" for="geo-latitude">Широта</label><output class="field-input transfer-readonly" id="geo-latitude" data-geo-lat></output></div><div class="field"><label class="field-label" for="geo-longitude">Долгота</label><output class="field-input transfer-readonly" id="geo-longitude" data-geo-lon></output></div></div>
        <div class="transfer-quality-result" data-geo-quality role="group" aria-label="Качество координат"><span class="badge badge-neutral" data-geo-quality-badge></span><span data-geo-quality-description></span></div><div class="inline-actions"><button class="btn btn-secondary btn-sm" type="button" data-copy-coordinates>Копировать координаты</button><a class="btn btn-secondary btn-sm" data-map-link target="_blank" rel="noopener noreferrer">Открыть на Яндекс Картах ↗</a></div></section>
    </div>
    <?php if (!$coordinatesOnly): ?><div class="field transfer-wide"><label class="field-label" for="transfer-comment">Комментарий, необязательно</label><input class="field-input" id="transfer-comment" name="comment" maxlength="2000"></div><?php endif; ?>
    <div class="form-alert alert-warning <?= $coordinatesOnly?'':'transfer-wide' ?>" data-form-error role="alert" hidden></div>
    <section data-preview-result class="transfer-preview <?= $coordinatesOnly?'':'transfer-wide' ?>" hidden aria-live="polite"><h3>Проверьте изменения</h3><div class="form-alert alert-warning" data-preview-summary></div><div class="table-scroll"><table class="table"><thead><tr><th>Заявка</th><th>Было</th><th>Станет</th></tr></thead><tbody data-preview-rows></tbody></table></div><p class="muted">Будут изменены только адрес и координаты. Качество координат будет указано в уведомлении MAX, если событие включено.</p><button class="btn btn-primary" type="button" data-commit>Создать блок и переместить заявки</button></section>
    <?php if (!$coordinatesOnly): ?></div></div><div class="modal-foot is-spaced"><button class="btn btn-ghost" type="button" data-close-dialog>Отмена</button><button class="btn btn-primary" type="submit" data-preview>Проверить</button></div><?php endif; ?>
  </form>
  <?php if (!$coordinatesOnly): ?></dialog><?php endif; ?>
  <dialog class="modal modal-lg transfer-dialog" data-detail-dialog aria-labelledby="transfer-detail-title"><div class="modal-head"><span class="modal-title" id="transfer-detail-title">История блока</span><button class="modal-close" type="button" data-close-dialog aria-label="Закрыть">&times;</button></div><div data-detail-body class="modal-body transfer-detail-body"></div></dialog>
  <?php endif; ?>
</div>
<script src="<?= app_url('/assets/js/registry-components.js') ?>?v=<?= filemtime(base_path('public/assets/js/registry-components.js')) ?>" defer></script>
<script src="<?= app_url('/assets/js/feo-transfers.js') ?>?v=<?= filemtime(__DIR__.'/../../../public/assets/js/feo-transfers.js') ?>" defer></script>
