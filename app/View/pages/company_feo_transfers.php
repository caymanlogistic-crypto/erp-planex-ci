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
    <?php $registryHeadTitle=$pageTitle; $registryHeadSummary='Перемещайте заявки блоками. Исходные адреса и координаты сохраняются в истории.'; $registryHeadIconHtml=registry_tabler_header_icon('arrow-right-square'); $registryHeadActionsHtml=$dbError?'':'<button class="btn btn-primary" type="button" data-open-transfer>Создать блок</button>'; require base_path('app/View/partials/registry_ui_head.php'); ?>
  <?php else: ?>
    <div class="page-head"><div class="page-head-left"><h1 class="page-title"><?= e($pageTitle) ?></h1><div class="page-summary">Найдите адрес и узнайте, насколько точно определены координаты.</div></div></div>
  <?php endif; ?>
  <div class="form-alert" data-page-message role="status" hidden></div>
  <?php if ($dbError): ?><div class="form-alert alert-warning"><?= e($dbError) ?></div><?php else: ?>
  <?php if (!$keyConfigured): ?><div class="form-alert alert-warning">Геокодер пока не настроен. <?php if ($isOwner): ?>Укажите ключ в <a href="<?= e(app_url('/company/settings/services')) ?>">Настройки → Ключи и сервисы</a>.<?php else: ?>Обратитесь к руководителю для настройки Яндекс Геокодера.<?php endif; ?><?php if (!$coordinatesOnly): ?> Перемещение к другой заявке доступно.<?php endif; ?></div><?php endif; ?>

  <?php if (!$coordinatesOnly): ?>
  <?php if (class_exists(\App\Security\RegistryUiStateScope::class)): require base_path('app/View/partials/registry_ui_state_scope.php'); else: $registryUiStateScope=''; endif; ?>
  <div class="table-card table-card--standard registry-ui-card registry-ui-sticky-table registry-ui-sticky-table--wide-scroll registry-ui-scroll" data-registry-id="feo-transfers" data-registry-state-scope="<?= e($registryUiStateScope) ?>">
    <div class="table-toolbar registry-ui-toolbar">
      <div class="registry-ui-checkboxes"><div class="found-label">Найдено: <b data-history-count>0</b></div><span class="filter-sep" aria-hidden="true"></span><div class="registry-ui-checkboxes"><label class="registry-ui-checkbox"><input type="checkbox" data-show-returned data-registry-field="show-returned" data-registry-default="false"> Показать возвращённые</label></div></div>
      <div class="toolbar-right"><button type="button" class="registry-ui-reset" hidden>Сбросить фильтр</button><select class="toolbar-select" data-weight-mode data-registry-field="weight" data-registry-default="net" data-table-reference-select="feo-transfers" aria-label="Вид веса"><option value="net">Нетто</option><option value="gross">Брутто</option></select><div class="registry-ui-search-group"><input class="toolbar-search registry-ui-search" type="search" data-history-search data-registry-field="query" data-registry-default="" placeholder="Поиск на странице" aria-label="Поиск блоков"></div></div>
    </div>
    <div class="table-scroll"><table class="table registry-ui-table registry-ui-data registry-ui-table--placement registry-ui-table--placement-blocks transfer-history feo-history-table feo-history-table--transfers">
      <colgroup><col><col><col><col><col><col><col></colgroup>
      <thead><tr><th>ID</th><th>Заявки которые перемещаются</th><th>Заявок</th><th>Общий вес</th><th>Место назначения</th><th>Дата</th><th>Ответственный</th></tr></thead>
      <tbody>
      <?php if (!$batches): ?><tr><td colspan="7"><div class="transfer-empty"><b class="registry-ui-name">Перемещений пока нет</b><p class="registry-ui-secondary">Создайте блок, добавьте номера заявок и выберите новое место погрузки.</p><button class="btn btn-primary" type="button" data-open-transfer>Создать первый блок</button></div></td></tr><?php endif; ?>
      <?php foreach ($batches as $batch): $destination=json_decode($batch['destination_json'],true); $summary=$batchIndexItems[(int)$batch['id']]??['ids'=>[],'net_kg'=>null,'gross_kg'=>null]; $requestIds=implode(', ',$summary['ids']); $sourceId=($batch['mode']??'')==='request'?(string)($batch['source_request_id']??''):''; ?>
        <tr data-batch-row data-registry-row-id="<?= (int)$batch['id'] ?>" data-batch-status="<?= e($batch['status']) ?>" data-registry-search="<?= e($batch['name'].' '.$requestIds.' '.($destination['address']??'').' '.($destination['quality']??'').' '.$batch['created_by_name']) ?>" class="<?= $batch['status']==='applied'?'':'registry-ui-muted' ?>">
          <td class="registry-ui-id registry-ui-open-detail" role="button" tabindex="0" data-detail="<?= (int)$batch['id'] ?>" aria-label="Открыть историю <?= $batch['status']==='applied'?'блока':'возвращённого блока' ?> #<?= (int)$batch['id'] ?>">#<?= (int)$batch['id'] ?></td>
          <td><span class="registry-ui-primary" data-registry-full-text="<?= e($requestIds) ?>" title="<?= e($requestIds) ?>"><?= e($requestIds) ?></span></td>
          <td><span class="registry-ui-primary"><?= (int)$batch['request_count'] ?></span></td>
          <td><span class="registry-ui-primary" data-total-weight data-net-kg="<?= e($summary['net_kg']===null?'':(string)$summary['net_kg']) ?>" data-gross-kg="<?= e($summary['gross_kg']===null?'':(string)$summary['gross_kg']) ?>"></span></td>
          <td class="transfer-location"><?php if ($sourceId!==''): ?><div class="registry-ui-primary"><strong>По адресу заявки <?= e($sourceId) ?></strong></div><?php endif; ?><div class="registry-ui-primary" title="<?= e($destination['address']??'') ?>"><?= e($destination['address']??'') ?></div></td>
          <td><span class="registry-ui-primary"><?= e($fmtHistoryDate($batch['created_at'])) ?></span></td><td><span class="registry-ui-primary"><?= e($batch['created_by_name']) ?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php if ($page>1 || count($batches)===50): ?><div class="transfer-pagination"><?php if ($page>1): ?><a class="btn btn-secondary btn-sm" href="<?= e($transferBase.'?page='.($page-1)) ?>">← Новее</a><?php endif; ?><?php if (count($batches)===50): ?><a class="btn btn-secondary btn-sm" href="<?= e($transferBase.'?page='.($page+1)) ?>">Ранее →</a><?php endif; ?></div><?php endif; ?>
  </div>
  <dialog class="modal modal-lg registry-ui-modal transfer-dialog" data-create-dialog aria-labelledby="transfer-create-title">
    <div class="modal-head"><span class="modal-title" id="transfer-create-title">Новый блок перемещения</span><button type="button" class="modal-close" data-close-dialog aria-label="Закрыть">&times;</button></div>
  <?php endif; ?>
  <form data-transfer-form class="transfer-form <?= $coordinatesOnly?'panel':'registry-ui-modal-form' ?>" <?= $coordinatesOnly?'data-coordinates-only':'' ?>>
    <?= csrfField() ?>
    <?php if (!$coordinatesOnly): ?><div class="modal-body transfer-form-body"><div class="registry-ui-modal-grid"><?php endif; ?>
    <?php if (!$coordinatesOnly): ?>
      <div class="field"><label class="field-label" for="transfer-ids">Заявки</label><textarea class="field-input registry-ui-request-ids" id="transfer-ids" name="request_ids" rows="5" required placeholder="Вставьте шестизначные номера заявок"></textarea><div class="field-msg is-error" data-ids-error role="alert" hidden></div></div>
      <fieldset class="field transfer-modes"><legend class="field-label">Куда переместить</legend><div class="radio-group is-horizontal"><label class="radio-item"><input type="radio" name="mode" value="request" checked><span class="radio-mark" aria-hidden="true"></span><span class="radio-text"><span class="radio-label">К другой заявке</span></span></label><label class="radio-item"><input type="radio" name="mode" value="address"><span class="radio-mark" aria-hidden="true"></span><span class="radio-text"><span class="radio-label">По адресу</span></span></label></div></fieldset>
      <div data-request-destination class="field"><label class="field-label" for="transfer-source">Номер заявки с нужным адресом</label><input class="field-input" id="transfer-source" name="source_request_id" inputmode="numeric"><div class="field-msg is-error" data-source-error role="alert" hidden></div></div>
    <?php endif; ?>
    <div data-address-destination class="<?= $coordinatesOnly?'':'transfer-wide' ?>" <?= $coordinatesOnly?'':'hidden' ?>>
      <?php if ($coordinatesOnly): ?>
        <div class="field"><label class="field-label" for="geo-address">Адрес места погрузки</label><div class="transfer-address-search"><input class="field-input" id="geo-address" name="address" maxlength="1000" placeholder="Регион, населённый пункт, улица, дом"><button class="btn btn-secondary" type="button" data-geocode>Определить координаты</button></div><div class="field-msg is-error" data-address-error role="alert" hidden></div></div>
        <section class="transfer-geo-result" data-geo-result hidden aria-live="polite"><div class="field"><label class="field-label" for="geo-found-address">Найденный адрес</label><output class="field-input transfer-readonly" id="geo-found-address" data-geo-address></output></div><div class="form-grid-2 transfer-coordinates"><div class="field"><label class="field-label" for="geo-latitude">Широта</label><output class="field-input transfer-readonly" id="geo-latitude" data-geo-lat></output></div><div class="field"><label class="field-label" for="geo-longitude">Долгота</label><output class="field-input transfer-readonly" id="geo-longitude" data-geo-lon></output></div></div><div class="transfer-quality-result" data-geo-quality role="group" aria-label="Качество координат"><span class="badge badge-neutral" data-geo-quality-badge></span><span data-geo-quality-description></span></div><div class="inline-actions"><button class="btn btn-secondary btn-sm" type="button" data-copy-coordinates>Копировать координаты</button><a class="btn btn-secondary btn-sm" data-map-link target="_blank" rel="noopener noreferrer">Открыть на Яндекс Картах ↗</a></div></section>
      <?php else: ?>
        <div class="field"><label class="field-label" for="geo-address">Адрес места погрузки</label><div class="registry-ui-modal-address-row"><input class="field-input" id="geo-address" name="address" maxlength="1000"><button class="btn btn-secondary" type="button" data-geocode>Определить координаты</button></div><div class="field-msg is-error" data-address-error role="alert" hidden></div></div>
        <section class="registry-ui-modal-geo" data-geo-result hidden aria-live="polite"><div class="field"><span class="field-label">Найденный адрес</span><output class="registry-ui-modal-text" data-geo-address></output></div><div class="registry-ui-modal-text">Широта <output data-geo-lat></output> · Долгота <output data-geo-lon></output></div><div class="registry-ui-modal-quality" data-geo-quality role="group" aria-label="Качество координат"><span class="badge badge-neutral" data-geo-quality-badge></span><span class="registry-ui-modal-help" data-geo-quality-description></span></div></section>
      <?php endif; ?>
    </div>
    <div class="field-msg is-error" data-form-error role="alert" hidden></div>
    <section data-preview-result class="registry-ui-modal-preview" hidden aria-live="polite"><div class="registry-ui-modal-summary" data-preview-summary></div><div class="table-scroll registry-ui-compact-table-scroll"><table class="table registry-ui-table registry-ui-compact-table"><thead><tr><th>Заявка</th><th>Вес нетто</th><th>Было</th><th>Станет</th></tr></thead><tbody data-preview-rows></tbody></table></div></section>
    <?php if (!$coordinatesOnly): ?></div></div><div class="modal-foot"><div class="modal-foot-actions"><button class="btn btn-ghost" type="button" data-close-dialog>Отменить</button><button class="btn btn-primary" type="button" data-commit disabled>Создать блок и переместить заявки</button></div></div><?php endif; ?>
  </form>
  <?php if (!$coordinatesOnly): ?></dialog><?php endif; ?>
  <dialog class="modal modal-lg registry-ui-modal registry-ui-modal--reading transfer-dialog" data-detail-dialog aria-labelledby="transfer-detail-title"><div class="modal-head"><span class="modal-title" id="transfer-detail-title">Перенос заявок</span><button class="modal-close" type="button" data-close-dialog aria-label="Закрыть">&times;</button></div><div data-detail-body class="modal-body transfer-detail-body"></div><div class="modal-foot"><div class="modal-foot-actions"><button class="btn btn-ghost" type="button" data-close-dialog>Отменить</button><button class="btn btn-primary" type="button" data-revert hidden>Отменить перенос</button><button class="btn btn-danger" type="button" data-confirm-revert hidden>Выполнить возврат</button></div></div></dialog>
  <?php endif; ?>
</div>
<script src="<?= app_url('/assets/js/registry-components.js') ?>?v=<?= filemtime(base_path('public/assets/js/registry-components.js')) ?>" defer></script>
<script src="<?= app_url('/assets/js/table-reference-select.js') ?>?v=<?= filemtime(base_path('public/assets/js/table-reference-select.js')) ?>" defer></script>
<script src="<?= app_url('/assets/js/feo-transfers.js') ?>?v=<?= filemtime(__DIR__.'/../../../public/assets/js/feo-transfers.js') ?>" defer></script>
