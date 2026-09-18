<?php
$transferBase=app_url('/company/feo/requests/transfers');
$geoBase=app_url('/company/feo/requests/coordinates');
?>
<link rel="stylesheet" href="<?= app_url('/assets/css/feo-transfers.css') ?>">
<div class="feo-transfers" data-feo-transfers data-base="<?= e($transferBase) ?>" data-geo-base="<?= e($geoBase) ?>">
  <div class="page-head">
    <div class="page-head-left"><h1 class="page-title"><?= e($pageTitle) ?></h1>
      <div class="page-summary"><?= $coordinatesOnly?'Найдите адрес и узнайте, насколько точно определены координаты.':'Переносите заявки пакетами. Исходные адреса и координаты сохраняются в истории.' ?></div>
    </div>
    <?php if (!$coordinatesOnly && !$dbError): ?><div class="page-head-actions"><button class="btn btn-primary" type="button" data-open-transfer>Создать пакет</button></div><?php endif; ?>
  </div>
  <div class="notice" data-page-message role="status" hidden></div>
  <?php if ($dbError): ?><div class="notice warn"><?= e($dbError) ?></div><?php else: ?>
  <?php if (!$keyConfigured): ?><div class="notice warn">Геокодер пока не настроен. <?= $isOwner?'Укажите ключ Яндекса в настройках ниже.':'Обратитесь к руководителю для настройки Яндекс Геокодера.' ?> Перенос к другой заявке доступен.</div><?php endif; ?>

  <?php if (!$coordinatesOnly): ?>
  <div class="table-card table-card--standard">
    <div class="table-toolbar"><div class="found-label">История пакетов <span class="muted">· страница <?= $page ?></span></div><input class="toolbar-search" type="search" data-history-search placeholder="Поиск на странице" aria-label="Поиск пакетов"></div>
    <div class="table-scroll"><table class="table transfer-history">
      <thead><tr><th>Пакет / дата</th><th>Место назначения</th><th>Заявок</th><th>Автор</th><th>Состояние</th><th>Действия</th></tr></thead>
      <tbody>
      <?php if (!$batches): ?><tr><td colspan="6"><div class="transfer-empty"><b>Переносов пока нет</b><p>Создайте пакет, добавьте номера заявок и выберите новое место погрузки.</p><button class="btn btn-primary" type="button" data-open-transfer>Создать первый пакет</button></div></td></tr><?php endif; ?>
      <?php foreach ($batches as $batch): $destination=json_decode($batch['destination_json'],true); ?>
        <tr data-batch-row><td><strong>#<?= (int)$batch['id'] ?> · <?= e($batch['name']) ?></strong><div class="muted"><?= e($batch['created_at']) ?></div></td>
          <td class="transfer-location"><?= e($destination['address']??'') ?><div class="transfer-quality <?= ($destination['precision']??'')==='exact'?'is-exact':'' ?>"><?= e($destination['quality']??'Точность не подтверждена') ?></div></td>
          <td><?= (int)$batch['request_count'] ?></td><td><?= e($batch['created_by_name']) ?></td>
          <td><span class="badge <?= $batch['status']==='applied'?'badge-ok':'badge-neutral' ?>"><?= $batch['status']==='applied'?'Применён':'Возвращён' ?></span></td>
          <td><div class="inline-actions"><button class="btn btn-secondary btn-sm" type="button" data-detail="<?= (int)$batch['id'] ?>">Подробнее</button><?php if ($batch['status']==='applied'): ?><button class="btn btn-secondary btn-sm" type="button" data-revert="<?= (int)$batch['id'] ?>">Вернуть пакет</button><?php endif; ?></div></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <div class="transfer-pagination"><?php if ($page>1): ?><a class="btn btn-secondary btn-sm" href="<?= e($transferBase.'?page='.($page-1)) ?>">← Новее</a><?php endif; ?><?php if (count($batches)===50): ?><a class="btn btn-secondary btn-sm" href="<?= e($transferBase.'?page='.($page+1)) ?>">Ранее →</a><?php endif; ?></div>
  </div>
  <dialog class="transfer-dialog" data-create-dialog aria-labelledby="transfer-create-title">
    <div class="transfer-dialog-head"><div><h2 id="transfer-create-title">Новый пакет переноса</h2><p class="muted">1. Заявки → 2. Место назначения → 3. Проверка</p></div><button type="button" class="btn btn-secondary btn-sm" data-close-dialog aria-label="Закрыть">✕</button></div>
  <?php endif; ?>
  <form data-transfer-form class="transfer-form" <?= $coordinatesOnly?'data-coordinates-only':'' ?>>
    <?= csrfField() ?>
    <?php if (!$coordinatesOnly): ?>
      <div class="transfer-grid"><div class="field"><label class="field-label" for="transfer-name">Название пакета</label><input class="field-input" id="transfer-name" name="name" maxlength="180" placeholder="Например, перенос на склад в Подольске"></div><div class="field"><label class="field-label" for="transfer-comment">Комментарий</label><input class="field-input" id="transfer-comment" name="comment" maxlength="2000" placeholder="Причина переноса"></div></div>
      <div class="field"><label class="field-label" for="transfer-ids">Номера заявок</label><textarea class="field-input" id="transfer-ids" name="request_ids" rows="4" required placeholder="123456&#10;123457&#10;123458"></textarea><span class="muted">Вставьте один номер или список из столбца Excel. До 1000 заявок; повторы будут объединены.</span></div>
      <fieldset class="transfer-modes"><legend>Куда переместить</legend><label><input type="radio" name="mode" value="request" checked> К другой заявке</label><label><input type="radio" name="mode" value="address"> По адресу</label></fieldset>
      <div data-request-destination class="field"><label class="field-label" for="transfer-source">Номер заявки назначения</label><input class="field-input" id="transfer-source" name="source_request_id" inputmode="numeric" placeholder="Номер заявки, откуда взять адрес и координаты"><span class="muted">Заявка назначения останется без изменений.</span></div>
    <?php endif; ?>
    <div data-address-destination <?= $coordinatesOnly?'':'hidden' ?>>
      <div class="field"><label class="field-label" for="geo-address">Адрес места погрузки</label><div class="transfer-address-search"><input class="field-input" id="geo-address" name="address" maxlength="1000" placeholder="Регион, населённый пункт, улица, дом"><button class="btn btn-secondary" type="button" data-geocode>Определить координаты</button></div></div>
      <div class="transfer-geo-result" data-geo-result hidden aria-live="polite"><div class="muted">Найденный адрес</div><strong data-geo-address></strong><div class="transfer-coordinates"><div><span>Широта</span><b data-geo-lat></b></div><div><span>Долгота</span><b data-geo-lon></b></div></div><p class="transfer-quality" data-geo-quality></p><div class="inline-actions"><button class="btn btn-secondary btn-sm" type="button" data-copy-coordinates>Копировать координаты</button><a class="btn btn-secondary btn-sm" data-map-link target="_blank" rel="noopener noreferrer">Открыть на Яндекс Картах ↗</a></div></div>
    </div>
    <div class="notice warn" data-form-error role="alert" hidden></div>
    <?php if (!$coordinatesOnly): ?><div class="transfer-form-actions"><button class="btn btn-primary" type="submit" data-preview>Проверить пакет</button></div><?php endif; ?>
    <section data-preview-result class="transfer-preview" hidden aria-live="polite"><h3>Проверьте изменения</h3><div class="notice warn" data-preview-summary></div><div class="table-scroll"><table class="table"><thead><tr><th>Заявка</th><th>Было</th><th>Станет</th></tr></thead><tbody data-preview-rows></tbody></table></div><p class="muted">Будут изменены только адрес и координаты. Качество координат будет указано в уведомлении MAX, если событие включено.</p><button class="btn btn-primary" type="button" data-commit>Создать пакет и перенести заявки</button></section>
  </form>
  <?php if (!$coordinatesOnly): ?></dialog><?php endif; ?>
  <?php if ($isOwner): ?><details class="transfer-settings"><summary>Настройка Яндекс Геокодера · <?= $keyConfigured?'ключ сохранён':'ключ не задан' ?></summary><form data-geo-settings><?= csrfField() ?><label class="field-label" for="geo-api-key">API-ключ Яндекс Геокодера</label><div class="transfer-address-search"><input class="field-input" type="password" id="geo-api-key" name="api_key" autocomplete="new-password" required placeholder="<?= $keyConfigured?'Новый ключ для замены':'Введите API-ключ' ?>"><button class="btn btn-secondary" type="submit">Сохранить ключ</button></div><p class="muted">Ключ хранится зашифрованно и используется на сервере. Настройка доступна только руководителю.</p></form></details><?php endif; ?>
  <dialog class="transfer-dialog" data-detail-dialog aria-labelledby="transfer-detail-title"><div class="transfer-dialog-head"><h2 id="transfer-detail-title">История пакета</h2><button class="btn btn-secondary btn-sm" type="button" data-close-dialog aria-label="Закрыть">✕</button></div><div data-detail-body class="transfer-detail-body"></div></dialog>
  <?php endif; ?>
</div>
<script src="<?= app_url('/assets/js/feo-transfers.js') ?>" defer></script>
