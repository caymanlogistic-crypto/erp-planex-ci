<?php
declare(strict_types=1);
$fmtDate=static fn(mixed $value):string=>trim((string)$value)!==''?date('d.m.Y',strtotime((string)$value)):'—';
$fmtDateTimeInput=static fn(mixed $value):string=>trim((string)$value)!==''?date('Y-m-d\TH:i',strtotime((string)$value)):'';
$fmtMass=static fn(mixed $value):string=>number_format((float)$value,0,',',' ');
$filterQuery=static function(int $tripId)use($query,$hideComplete):string{
    return http_build_query(array_filter(['filter'=>1,'hide_complete'=>$hideComplete?1:null,'q'=>$query!==''?$query:null,'trip_id'=>$tripId],static fn($v):bool=>$v!==null));
};
?>
<link rel="stylesheet" href="<?=app_url('/assets/css/trip-completion.css')?>?v=<?=filemtime(base_path('public/assets/css/trip-completion.css'))?>">
<div class="page-head"><div class="page-head-left"><h1 class="page-title">Помощник логиста</h1><div class="page-summary"><span>Комплектность завершённых рейсов и транспортных накладных.</span></div></div><div class="page-head-actions"><a class="btn btn-ghost" href="<?=app_url('/company/documents/collection')?>">Документы перевозчика</a><?php if($selectedTrip):?><a class="btn btn-secondary" href="<?=app_url('/company/logistics-assistant/'.(int)$selectedTrip['id'].'/report')?>">Сводный отчёт</a><a class="btn btn-primary" href="<?=app_url('/company/logistics-assistant/'.(int)$selectedTrip['id'].'/package')?>">Скачать комплект</a><?php endif;?></div></div>
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
<div class="logistics-workspace">
  <section class="table-card table-card--standard logistics-queue" aria-label="Рейсы для обработки">
    <div class="table-toolbar"><div class="found-label">Рейсы для обработки: <b><?=count($trips)?></b></div></div>
    <?php if($trips===[]):?><div class="panel-body"><div class="empty-state"><p class="empty-title">По выбранному фильтру рейсов нет.</p><p>Полностью собранные рейсы можно показать, сняв флажок выше.</p></div></div><?php else:?>
    <div class="table-scroll"><table class="table logistics-queue-table"><thead><tr><th>Рейс</th><th>Готово</th><th>Не хватает</th></tr></thead><tbody>
    <?php foreach($trips as $trip):$missing=[];if((int)$trip['missing_scan_count']>0)$missing[]='ТН '.(int)$trip['missing_scan_count'];if((int)$trip['missing_time_count']>0)$missing[]='время '.(int)$trip['missing_time_count'];if((int)$trip['missing_driver_signature_count']>0||(int)$trip['missing_sender_signature_count']>0)$missing[]='подписи '.max((int)$trip['missing_driver_signature_count'],(int)$trip['missing_sender_signature_count']);$active=(int)($selectedTrip['id']??0)===(int)$trip['id'];?>
      <tr class="<?=$active?'is-selected':''?>"><td><a class="logistics-trip-link" href="<?=app_url('/company/logistics-assistant?'.$filterQuery((int)$trip['id']))?>"><b>#<?=e((string)$trip['business_id'])?></b><span><?=e($fmtDate($trip['actual_end_date']))?></span><span><?=e(trim((string)$trip['driver_name'])?:trim((string)$trip['contractor_name'])?:'Исполнитель не указан')?></span></a></td><td><b><?=(int)$trip['complete_count']?> / <?=(int)$trip['request_count']?></b><span class="logistics-muted"><?=(int)$trip['document_count']?> файл.</span></td><td><span class="badge <?=empty($missing)?'badge-ok':'badge-danger'?>"><?=e($missing?implode(' · ',$missing):'Собрано')?></span></td></tr>
    <?php endforeach;?></tbody></table></div><?php endif;?>
  </section>
  <section class="logistics-detail">
  <?php if(!$selectedTrip):?><div class="panel"><div class="panel-body"><div class="empty-state"><p class="empty-title">Выберите рейс слева.</p></div></div></div><?php else:?>
    <div class="panel logistics-trip-head"><div class="panel-body"><div><div class="section-title">Рейс #<?=e((string)$selectedTrip['business_id'])?></div><div class="logistics-trip-meta"><span><?=e($fmtDate($selectedTrip['actual_start_date']))?> — <?=e($fmtDate($selectedTrip['actual_end_date']))?></span><span><?=e(trim((string)$selectedTrip['driver_name'])?:'Водитель не указан')?></span><span><?=e(trim((string)$selectedTrip['contractor_name'])?:'Подрядчик не указан')?></span></div></div><div class="badge <?=((int)$selectedTrip['complete_count']===(int)$selectedTrip['request_count'])?'badge-ok':'badge-warning'?>"><?=(int)$selectedTrip['complete_count']?> из <?=(int)$selectedTrip['request_count']?> заявок</div></div></div>
    <?php foreach($selectedTrip['requests'] as $requestIndex=>$request):?>
    <details class="panel logistics-request" <?=$requestIndex===0?'open':''?>>
      <summary><span><b>Заявка <?=e((string)$request['zayavka_id'])?></b><span class="logistics-request-route"><?=e(trim((string)$request['shipper_name'])?:'Отправитель не указан')?> · <?=e($fmtMass($request['snapshot_mass_kg']))?> кг</span></span><span class="logistics-issues"><?php if($request['is_complete']):?><span class="badge badge-ok">Собрано</span><?php else:foreach($request['issues'] as $issue):?><span class="badge badge-danger"><?=e((string)$issue)?></span><?php endforeach;endif;?></span></summary>
      <div class="panel-body logistics-request-body">
        <div class="logistics-route"><div><span class="field-label">Забор</span><b><?=e(trim((string)$request['shipper_name'])?:'—')?></b><span><?=e(trim((string)$request['pickup_address'])?:'Адрес не указан')?></span></div><div><span class="field-label">Выгрузка</span><b><?=e(trim((string)$request['consignee_name'])?:'—')?></b><span><?=e(trim((string)$request['delivery_address'])?:'Адрес не указан')?></span></div></div>
        <form method="post" action="<?=app_url('/company/logistics-assistant/'.(int)$selectedTrip['id'].'/requests/'.(int)$request['id'])?>" class="logistics-data-form"><?=csrfField()?>
          <label class="field"><span class="field-label">Приезд на забор</span><input class="field-input" type="datetime-local" name="arrived_at" value="<?=e($fmtDateTimeInput($request['arrived_at']))?>"></label>
          <label class="field"><span class="field-label">Уезд с забора</span><input class="field-input" type="datetime-local" name="departed_at" value="<?=e($fmtDateTimeInput($request['departed_at']))?>"></label>
          <label class="logistics-check"><input type="checkbox" name="driver_signature_present" value="1" <?=(int)$request['driver_signature_present']===1?'checked':''?>> Подпись водителя</label>
          <label class="logistics-check"><input type="checkbox" name="sender_signature_present" value="1" <?=(int)$request['sender_signature_present']===1?'checked':''?>> Подпись отправителя</label>
          <label class="field logistics-comment"><span class="field-label">Комментарий</span><input class="field-input" name="comment" maxlength="500" value="<?=e((string)$request['completion_comment'])?>" placeholder="Если есть проблема"></label>
          <button class="btn btn-primary" type="submit">Сохранить</button>
        </form>
        <div class="logistics-documents"><div><span class="field-label">Сканы ТН</span><?php if(empty($request['documents'])):?><span class="logistics-muted">Файлы не загружены.</span><?php else:?><ul><?php foreach($request['documents'] as $document):?><li><a href="<?=app_url('/company/logistics-assistant/'.(int)$selectedTrip['id'].'/documents/'.(int)$document['id'])?>"><?=e((string)$document['download_name'])?></a><span class="logistics-muted"><?=e(\App\Service\DocumentService::formatFileSize((int)$document['size_bytes']))?></span><form method="post" action="<?=app_url('/company/logistics-assistant/'.(int)$selectedTrip['id'].'/requests/'.(int)$request['id'].'/documents/'.(int)$document['id'].'/delete')?>"><?=csrfField()?><button class="btn btn-ghost btn-sm" type="submit" onclick="return confirm('Удалить файл из комплекта?')">Удалить</button></form></li><?php endforeach;?></ul><?php endif;?></div>
          <form method="post" enctype="multipart/form-data" action="<?=app_url('/company/logistics-assistant/'.(int)$selectedTrip['id'].'/requests/'.(int)$request['id'].'/documents')?>"><?=csrfField()?><input class="field-input" type="file" name="documents[]" accept=".pdf,.jpg,.jpeg,.png,.webp,.tif,.tiff" multiple required><button class="btn btn-secondary" type="submit">Загрузить ТН</button></form>
        </div>
      </div>
    </details>
    <?php endforeach;?>
  <?php endif;?>
  </section>
</div>
<?php endif;?>
