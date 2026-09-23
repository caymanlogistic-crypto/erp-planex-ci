<?php
declare(strict_types=1);
$fmtDate=static fn(mixed $value):string=>trim((string)$value)!==''?date('d.m.Y',strtotime((string)$value)):'—';
$fmtPickupInterval=static function(mixed $arrived,mixed $departed):string{
    if(trim((string)$arrived)===''||trim((string)$departed)==='')return '';
    return date('d.m.y H:i',strtotime((string)$arrived)).' - '.date('H:i',strtotime((string)$departed));
};
$fmtMass=static fn(mixed $value):string=>number_format((float)$value,0,',',' ');
$missingSummary=static function(array $trip):array{
    $missing=[];
    if((int)$trip['missing_scan_count']>0)$missing[]='ТН '.(int)$trip['missing_scan_count'];
    if((int)$trip['missing_time_count']>0)$missing[]='время '.(int)$trip['missing_time_count'];
    if((int)$trip['missing_driver_signature_count']>0)$missing[]='подпись водителя '.(int)$trip['missing_driver_signature_count'];
    if((int)$trip['missing_sender_signature_count']>0)$missing[]='подпись отправителя '.(int)$trip['missing_sender_signature_count'];
    return $missing;
};
?>
<link rel="stylesheet" href="<?=app_url('/assets/css/trip-completion.css')?>?v=<?=filemtime(base_path('public/assets/css/trip-completion.css'))?>">
<div class="page-head"><div class="page-head-left"><h1 class="page-title">Помощник логиста</h1><div class="page-summary"><span>Комплектность завершённых рейсов и транспортных накладных.</span></div></div><div class="page-head-actions"><a class="btn btn-ghost" href="<?=app_url('/company/documents/collection')?>">Документы перевозчика</a></div></div>
<?php if($success):?><div class="notice success"><?=e((string)$success)?></div><?php endif;?>
<?php if($formError):?><div class="notice warn"><?=e((string)$formError)?></div><?php endif;?>
<?php if($error):?><div class="notice warn"><?=e((string)$error)?></div><?php else:?>
<div class="metric-grid logistics-metrics">
  <div class="metric-card"><div class="metric-label">Рейсов в работе</div><div class="metric-value"><?=(int)$metrics['trips']?></div></div>
  <div class="metric-card"><div class="metric-label">Заявок всего</div><div class="metric-value"><?=(int)$metrics['requests']?></div></div>
  <div class="metric-card"><div class="metric-label">Полностью собрано</div><div class="metric-value"><?=(int)$metrics['ready']?></div></div>
  <div class="metric-card"><div class="metric-label">Требуют дополнения</div><div class="metric-value"><?=(int)$metrics['problems']?></div></div>
</div>
<form method="get" action="<?=app_url('/company/logistics-assistant')?>" class="table-toolbar logistics-filter">
  <input type="hidden" name="filter" value="1">
  <label class="logistics-check"><input type="checkbox" name="hide_complete" value="1" <?=$hideComplete?'checked':''?>> Скрывать полностью собранные</label>
  <div class="toolbar-right"><input class="toolbar-search" type="search" name="q" value="<?=e($query)?>" placeholder="Рейс, заявка, водитель, подрядчик"><button class="btn btn-secondary" type="submit">Найти</button><?php if($query!==''||!$hideComplete):?><a class="btn btn-ghost" href="<?=app_url('/company/logistics-assistant')?>">Сбросить</a><?php endif;?></div>
</form>
<section class="logistics-workspace" aria-label="Рейсы для обработки" data-logistics-assistant>
  <div class="logistics-list-head"><div><b>Рейсы для обработки</b><span><?=count($trips)?></span></div><small>Нажмите на рейс, чтобы развернуть заявки</small></div>
  <?php if($trips===[]):?><div class="panel"><div class="panel-body"><div class="empty-state"><p class="empty-title">По выбранному фильтру рейсов нет.</p><p>Полностью собранные рейсы можно показать, сняв флажок выше.</p></div></div></div><?php else:?>
  <div class="logistics-trip-list">
  <?php foreach($trips as $trip):$missing=$missingSummary($trip);$open=(int)($openTripId??0)===(int)$trip['id'];?>
    <details class="logistics-trip panel" data-trip-id="<?=(int)$trip['id']?>" <?=$open?'open':''?>>
      <summary class="logistics-trip-summary">
        <span class="logistics-trip-chevron" aria-hidden="true"></span>
        <span class="logistics-trip-primary"><b>Рейс #<?=e((string)$trip['business_id'])?></b><small><?=e($fmtDate($trip['actual_end_date']))?></small></span>
        <span class="logistics-trip-person"><b><?=e(trim((string)$trip['driver_name'])?:'Водитель не указан')?></b><small><?=e(trim((string)$trip['contractor_name'])?:'Подрядчик не указан')?></small></span>
        <span class="logistics-trip-progress"><b data-trip-progress><?=(int)$trip['complete_count']?> / <?=(int)$trip['request_count']?></b><small><span data-trip-document-count><?=(int)$trip['document_count']?></span> файл.</small></span>
        <span class="logistics-trip-issues" data-trip-issues><span class="badge <?=empty($missing)?'badge-ok':'badge-danger'?>"><?=e($missing?implode(' · ',$missing):'Собрано')?></span></span>
      </summary>
      <div class="logistics-trip-body">
        <div class="logistics-trip-toolbar"><div><b>Заявки рейса</b><span><?=e($fmtDate($trip['actual_start_date']))?> — <?=e($fmtDate($trip['actual_end_date']))?></span></div><div><a class="btn btn-secondary" href="<?=app_url('/company/logistics-assistant/'.(int)$trip['id'].'/report')?>">Сводный отчёт</a><a class="btn btn-primary" href="<?=app_url('/company/logistics-assistant/'.(int)$trip['id'].'/package')?>">Скачать комплект</a></div></div>
        <div class="logistics-request-head" aria-hidden="true"><span>Заявка и маршрут</span><span>Загрузка: дата, приезд — уезд</span><span>Подписи</span><span>Данные</span><span>Сканы ТН</span><span>Комментарий</span></div>
        <div class="logistics-request-list">
        <?php foreach($trip['requests'] as $request):
          $hasTime=!empty($request['arrived_at'])&&!empty($request['departed_at']);
          $hasChecks=(int)$request['driver_signature_present']===1||(int)$request['sender_signature_present']===1||(int)$request['tn_datetime_missing']===1||(int)$request['data_from_tracker']===1||(int)$request['data_from_driver']===1||(int)$request['data_invented']===1;
          $rowClass=$hasTime?'has-time':(($hasChecks||!empty($request['documents']))?'has-progress':'');
        ?>
          <div class="logistics-request-row <?=$rowClass?>" data-request-row data-request-id="<?=(int)$request['id']?>">
            <div class="logistics-request-route"><div><b>№ <?=e((string)$request['zayavka_id'])?></b><span><?=e($fmtMass($request['snapshot_mass_kg']))?> кг</span></div><strong title="<?=e(trim((string)$request['pickup_address']))?>"><?=e(trim((string)$request['shipper_name'])?:'Отправитель не указан')?></strong><small title="<?=e(trim((string)$request['delivery_address']))?>">→ <?=e(trim((string)$request['consignee_name'])?:'Получатель не указан')?></small></div>
            <form method="post" action="<?=app_url('/company/logistics-assistant/'.(int)$trip['id'].'/requests/'.(int)$request['id'])?>" class="logistics-data-form" data-autosave-form><?=csrfField()?>
              <label class="field logistics-time"><span class="field-label">Дата и время загрузки</span><input class="field-input" type="text" name="pickup_interval" value="<?=e($fmtPickupInterval($request['arrived_at'],$request['departed_at']))?>" inputmode="numeric" maxlength="22" autocomplete="off" placeholder="ДД.ММ.ГГ ЧЧ:ММ - ЧЧ:ММ" aria-label="Дата и время загрузки"></label>
              <div class="logistics-signatures logistics-checkbox-column"><label class="logistics-check"><input type="checkbox" name="driver_signature_present" value="1" <?=(int)$request['driver_signature_present']===1?'checked':''?>> Подпись водителя есть</label><label class="logistics-check"><input type="checkbox" name="sender_signature_present" value="1" <?=(int)$request['sender_signature_present']===1?'checked':''?>> Подпись отправителя есть</label><label class="logistics-check"><input type="checkbox" name="tn_datetime_missing" value="1" <?=(int)$request['tn_datetime_missing']===1?'checked':''?>> Даты и времени нет в ТН</label></div>
              <div class="logistics-data-source logistics-checkbox-column"><label class="logistics-check"><input type="checkbox" name="data_from_tracker" value="1" <?=(int)$request['data_from_tracker']===1?'checked':''?>> Восстановлены по трекеру</label><label class="logistics-check"><input type="checkbox" name="data_from_driver" value="1" <?=(int)$request['data_from_driver']===1?'checked':''?>> Внесены со слов водителя</label><label class="logistics-check"><input type="checkbox" name="data_invented" value="1" <?=(int)$request['data_invented']===1?'checked':''?>> Придуманы</label></div>
              <label class="field logistics-comment"><span class="field-label">Комментарий</span><input class="field-input" name="comment" maxlength="500" value="<?=e((string)$request['completion_comment'])?>" placeholder="Если есть проблема"></label>
            </form>
            <div class="logistics-documents" data-documents>
              <div class="logistics-document-list" data-document-list><?php foreach($request['documents'] as $document):?><span class="logistics-document" data-document-id="<?=(int)$document['id']?>"><a href="<?=app_url('/company/logistics-assistant/'.(int)$trip['id'].'/documents/'.(int)$document['id'])?>" title="<?=e((string)$document['download_name'])?>"><?=e((string)$document['download_name'])?></a><button class="logistics-document-delete" type="button" title="Удалить файл" data-delete-url="<?=app_url('/company/logistics-assistant/'.(int)$trip['id'].'/requests/'.(int)$request['id'].'/documents/'.(int)$document['id'].'/delete')?>">×</button></span><?php endforeach;?></div>
              <form method="post" enctype="multipart/form-data" action="<?=app_url('/company/logistics-assistant/'.(int)$trip['id'].'/requests/'.(int)$request['id'].'/documents')?>" class="logistics-upload-form" data-upload-form><?=csrfField()?><label class="btn btn-ghost btn-sm logistics-upload"><input type="file" name="documents[]" accept=".pdf,.jpg,.jpeg,.png,.webp,.tif,.tiff" multiple>+ Добавить ТН</label></form>
            </div>
            <div class="logistics-save-state" data-save-state aria-live="polite" hidden><span data-save-label></span></div>
          </div>
        <?php endforeach;?>
        </div>
      </div>
    </details>
  <?php endforeach;?>
  </div>
  <?php endif;?>
</section>
<script src="<?=app_url('/assets/js/trip-completion.js')?>?v=<?=filemtime(base_path('public/assets/js/trip-completion.js'))?>" defer></script>
<?php endif;?>
