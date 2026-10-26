<?php
declare(strict_types=1);
$fmtDate=static fn(mixed $value):string=>trim((string)$value)!==''?date('d.m.Y',strtotime((string)$value)):'—';
$fmtPickupInterval=static function(mixed $arrived,mixed $departed):string{
    if(trim((string)$arrived)===''||trim((string)$departed)==='')return '';
    return date('d.m.y H:i',strtotime((string)$arrived)).' - '.date('H:i',strtotime((string)$departed));
};
$fmtMass=static fn(mixed $value):string=>number_format((float)$value,0,',',' ');
$reviewerMode=$reviewerMode??false;
$basePath=$reviewerMode?'/company/tn-review':'/company/logistics-assistant';
$statusChoice=static function(string $name,string $selected,array $options):void{
    foreach($options as $value=>$label):?>
      <label class="lab-choice-option"><input type="checkbox" name="<?=e($name)?>" value="<?=e($value)?>" <?=$selected===$value?'checked':''?>><?=e($label)?></label>
    <?php endforeach;
};
?>
<link rel="stylesheet" href="<?=app_url('/assets/css/trip-completion.css')?>?v=<?=filemtime(base_path('public/assets/css/trip-completion.css'))?>">
<?php if($reviewerMode):
    require_once base_path('app/View/components/registry_tabler_icons.php');
    $registryHeadTitle='Проверка ТН';
    $registryHeadSummary='Рейсы в работе и на проверке. Проверенные рейсы переносятся в архив.';
    $registryHeadIconHtml=registry_tabler_header_icon('file-text');
    $registryHeadActionsHtml='<a class="btn btn-ghost" href="'.e(app_url('/company/documents/collection')).'">Документы перевозчика</a>';
?>
<link rel="stylesheet" href="<?=app_url('/assets/css/registry-components.css')?>?v=<?=filemtime(base_path('public/assets/css/registry-components.css'))?>">
<?php require base_path('app/View/partials/registry_ui_head.php'); else:?>
<div class="page-head"><div class="page-head-left"><h1 class="page-title">Регистрация прибытия и убытия у отходообразователя</h1><div class="page-summary"><span>Дата и время загрузки по рейсам в процессе вывоза и сданным рейсам.</span></div></div><div class="page-head-actions"><a class="btn btn-ghost" href="<?=app_url('/company/documents/collection')?>">Документы перевозчика</a></div></div>
<?php endif;?>
<?php if($success):?><div class="notice success"><?=e((string)$success)?></div><?php endif;?>
<?php if($formError):?><div class="notice warn"><?=e((string)$formError)?></div><?php endif;?>
<?php if($error):?><div class="notice warn"><?=e((string)$error)?></div><?php else:?>
<?php if($reviewerMode):?><div class="panel tn-review-shell"><?php endif;?>
<div class="metric-grid logistics-metrics">
  <div class="metric-card"><div class="metric-label"><?=$reviewerMode?'Рейсов на проверке':'Рейсов в работе'?></div><div class="metric-value"><?=(int)$metrics['trips']?></div></div>
  <div class="metric-card"><div class="metric-label">Заявок всего</div><div class="metric-value"><?=(int)$metrics['requests']?></div></div>
  <div class="metric-card"><div class="metric-label">Полностью собрано</div><div class="metric-value"><?=(int)$metrics['ready']?></div></div>
  <div class="metric-card"><div class="metric-label">Требуют дополнения</div><div class="metric-value"><?=(int)$metrics['problems']?></div></div>
</div>
<form method="get" action="<?=app_url($basePath)?>" class="table-toolbar logistics-filter">
  <input type="hidden" name="filter" value="1">
  <label class="logistics-check lab-show-all"><input type="checkbox" name="show_all_trips" value="1"> <?=$reviewerMode?'Показать архив':'Показать все рейсы'?></label>
  <div class="toolbar-right"><input class="toolbar-search" type="search" name="q" value="<?=e($query)?>" placeholder="Рейс, заявка, водитель, менеджер" aria-label="Поиск по рейсам и заявкам"></div>
</form>
<section class="logistics-workspace" aria-label="Рейсы для обработки" data-logistics-assistant data-reviewer-mode="<?=$reviewerMode?'1':'0'?>">
  <?php foreach(['work'=>'В работе','review'=>'На проверке','archive'=>'Проверено / архив'] as $groupStatus=>$groupName):
    $groupTrips=array_values(array_filter($trips,static fn(array $trip):bool=>$trip['review_status']===$groupStatus));?>
  <section class="lab-review-group lab-review-group--<?=e($groupStatus)?>" data-review-group="<?=e($groupStatus)?>" <?=($groupStatus==='archive'||(!$reviewerMode&&$groupStatus!=='work'))?'hidden':''?>>
    <div class="lab-review-group-head"><h2><?=e($groupName)?></h2><span class="lab-review-group-count" aria-label="Количество рейсов"><?=count($groupTrips)?></span></div>
    <div class="logistics-trip-list">
  <?php foreach($groupTrips as $trip):$open=(int)($openTripId??0)===(int)$trip['id'];$noTnCount=count(array_filter($trip['requests'],static fn(array $request):bool=>(int)($request['tn_absent']??0)===1));$timeCount=count(array_filter($trip['requests'],static fn(array $request):bool=>(int)($request['tn_absent']??0)!==1&&!empty($request['arrived_at'])&&!empty($request['departed_at'])));$processedCount=$timeCount+$noTnCount;?>
    <details class="logistics-trip panel" data-trip-id="<?=(int)$trip['id']?>" data-review-status="<?=e($groupStatus)?>" data-review-version="<?=(int)$trip['review_version']?>" <?=$open?'open':''?>>
      <summary class="logistics-trip-summary">
        <span class="logistics-trip-chevron" aria-hidden="true"></span>
        <span class="lab-trip-identity"><b>Рейс #<?=e((string)$trip['business_id'])?></b><strong class="lab-trip-status <?=($trip['status']??'')==='in_progress'?'is-progress':'is-delivered'?>"><?=($trip['status']??'')==='in_progress'?'Идёт вывоз':'Груз сдан'?></strong><?php if(!empty($trip['actual_start_date'])):?><small title="Фактическая дата начала рейса">Выезд: <?=e($fmtDate($trip['actual_start_date']))?></small><?php endif;?><?php if(!empty($trip['actual_end_date'])):?><small title="Фактическая дата сдачи груза">Сдача: <?=e($fmtDate($trip['actual_end_date']))?></small><?php endif;?></span>
        <span class="lab-trip-contact lab-trip-manager"><small class="lab-trip-label">Менеджер</small><b class="lab-trip-name"><?=e(trim((string)($trip['manager_name']??''))?:'Не указан')?></b><span class="lab-trip-contact-line">Контакт: <?=e(trim((string)($trip['manager_phone']??''))?:'телефон не указан')?></span></span>
        <span class="lab-trip-contact"><small class="lab-trip-label">Водитель</small><b class="lab-trip-name"><?=e(trim((string)$trip['driver_name'])?:'Не указан')?></b><span class="lab-trip-contact-line"><?=e(trim((string)($trip['driver_phone']??''))?:'Телефон не указан')?></span></span>
        <span class="lab-trip-progress"><small class="lab-trip-label">Обработано заявок</small><b class="lab-trip-progress-count" data-trip-progress><?=$processedCount?> из <?=(int)$trip['request_count']?> обработано</b><span class="lab-trip-progress-track <?=$noTnCount?'has-no-tn':''?> <?=$timeCount===0?'has-no-ready':''?>" aria-label="Время внесено: <?=$timeCount?>; нет ТН: <?=$noTnCount?>"><span class="lab-trip-progress-fill" style="width:<?=$trip['request_count']?round(100*$timeCount/$trip['request_count'],2):0?>%"></span><span class="lab-trip-progress-no-tn" style="width:<?=$trip['request_count']?round(100*$noTnCount/$trip['request_count'],2):0?>%" title="Нет ТН: <?=$noTnCount?>"></span></span></span>
        <span class="lab-trip-overview" hidden></span><span class="lab-trip-restored" hidden></span>
      </summary>
      <div class="logistics-trip-body">
        <div class="logistics-trip-toolbar">
          <div><b>Заявки рейса</b><span><?php if(!empty($trip['actual_start_date'])):?>Выезд: <?=e($fmtDate($trip['actual_start_date']))?><?php endif;?><?php if(!empty($trip['actual_end_date'])):?><?=!empty($trip['actual_start_date'])?' — ':''?>Сдача: <?=e($fmtDate($trip['actual_end_date']))?><?php endif;?></span></div>
          <div>
            <a class="btn btn-secondary" href="<?=app_url($basePath.'/'.(int)$trip['id'].'/report')?>">Скачать сводный отчёт</a>
            <a class="btn btn-secondary" href="<?=app_url($basePath.'/'.(int)$trip['id'].'/package')?>">Скачать полный комплекс</a>
            <?php if($reviewerMode&&$groupStatus==='review'):?>
              <form class="lab-review-form" method="post" action="<?=app_url($basePath.'/'.(int)$trip['id'].'/review')?>" data-review-form><?=csrfField()?><input type="hidden" name="action" value="return_work"><input type="hidden" name="version" value="<?=(int)$trip['review_version']?>"><button type="submit" class="btn btn-secondary lab-review-submit">Вернуть на доработку</button></form>
            <?php endif;?>
            <?php if(($reviewerMode&&$groupStatus!=='work')||(!$reviewerMode&&$groupStatus==='work')):?>
              <form class="lab-review-form" method="post" action="<?=app_url($basePath.'/'.(int)$trip['id'].'/review')?>" data-review-form><?=csrfField()?><input type="hidden" name="action" value="<?=$reviewerMode?($groupStatus==='archive'?'restore':'archive'):'submit'?>"><input type="hidden" name="version" value="<?=(int)$trip['review_version']?>"><button type="submit" class="btn <?=(!$reviewerMode||$groupStatus==='review')?'btn-primary':'btn-secondary'?> lab-review-submit"><?=$reviewerMode?($groupStatus==='archive'?'Вернуть из архива':'Переместить в архив'):'Передать на проверку'?></button></form>
            <?php endif;?>
          </div>
        </div>
        <div class="logistics-request-head" aria-hidden="true"><span>Заявка и маршрут</span><span>Дата и время прибытия/убытия</span><span>Подпись ОО</span><span>Подпись водителя</span><span>Дата и время указаны</span><span>Данные восстановлены</span><span>Сканы ТН</span><span>Комментарий</span></div>
        <div class="logistics-request-list">
        <?php foreach($trip['requests'] as $request):
          $hasTime=!empty($request['arrived_at'])&&!empty($request['departed_at']);
          $hasChecks=(int)$request['driver_signature_present']===1||(int)$request['sender_signature_present']===1||(int)$request['tn_datetime_missing']===1||(int)$request['tn_absent']===1||(int)$request['data_from_tracker']===1||(int)$request['data_from_driver']===1||(int)$request['data_invented']===1;
          $rowClass=$hasTime?'has-time':(($hasChecks||!empty($request['documents']))?'has-progress':'');
        ?>
          <div class="logistics-request-row <?=$rowClass?>" data-request-row data-request-id="<?=(int)$request['id']?>">
            <div class="logistics-request-route"><div><b>№ <?=e((string)$request['zayavka_id'])?></b><span><?=e($fmtMass($request['snapshot_mass_kg']))?> кг</span></div><strong title="<?=e(trim((string)$request['pickup_address']))?>"><?=e(trim((string)$request['shipper_name'])?:'Отправитель не указан')?></strong><?php if(trim((string)$request['pickup_address'])!==''):?><small class="lab-pickup-address" title="<?=e(trim((string)$request['pickup_address']))?>"><?=e(trim((string)$request['pickup_address']))?></small><?php endif;?><small title="<?=e(trim((string)$request['delivery_address']))?>">→ <?=e(trim((string)$request['consignee_name'])?:'Получатель не указан')?></small></div>
            <form id="trip-completion-request-<?=(int)$request['id']?>" method="post" action="<?=app_url($basePath.'/'.(int)$trip['id'].'/requests/'.(int)$request['id'])?>" class="logistics-data-form" data-autosave-form><?=csrfField()?>
              <label class="field logistics-time"><span class="field-label">Дата и время прибытия/убытия</span><input class="field-input" type="text" name="pickup_interval" value="<?=e($fmtPickupInterval($request['arrived_at'],$request['departed_at']))?>" inputmode="numeric" maxlength="22" autocomplete="off" placeholder="ДД.ММ.ГГ ЧЧ:ММ - ЧЧ:ММ" aria-label="Дата и время прибытия/убытия"></label>
              <div class="lab-choice lab-choice--oo" role="group" aria-label="Подпись ОО"><span class="lab-choice-title">Подпись ОО</span><div class="lab-choice-options"><?php $statusChoice('sender_signature_status',(string)($request['sender_signature_status']??''),['yes'=>'Есть','no'=>'Нет']);?></div></div>
              <div class="lab-choice lab-choice--driver" role="group" aria-label="Подпись водителя"><span class="lab-choice-title">Подпись водителя</span><div class="lab-choice-options"><?php $statusChoice('driver_signature_status',(string)($request['driver_signature_status']??''),['yes'=>'Есть','no'=>'Нет']);?></div></div>
              <div class="lab-choice lab-choice--date" role="group" aria-label="Дата и время указаны"><span class="lab-choice-title">Дата и время указаны</span><div class="lab-choice-options"><?php $statusChoice('tn_datetime_status',(string)($request['tn_datetime_status']??''),['full'=>'Полностью','partial'=>'Частично','no'=>'Нет']);?></div></div>
              <div class="lab-choice lab-choice--recovery" role="group" aria-label="Данные восстановлены"><span class="lab-choice-title">Данные восстановлены</span><div class="lab-choice-options"><?php $statusChoice('recovery_source',(string)($request['recovery_source']??''),['tracker'=>'По трекеру','driver'=>'Со слов вод./мен']);?></div></div>
              <label class="field logistics-comment"><span class="field-label">Комментарий</span><textarea class="field-input" name="comment" maxlength="500" rows="2" placeholder="Если есть проблема" aria-label="Если есть проблема"><?=e((string)$request['completion_comment'])?></textarea></label>
            </form>
            <div class="logistics-documents lab-docs" data-documents data-scan-state="<?=empty($request['documents'])?'empty':'added'?>">
              <div class="lab-docs-head"><span>Сканы ТН</span><label class="lab-docs-no-tn-label"><input class="lab-docs-no-tn" type="checkbox" form="trip-completion-request-<?=(int)$request['id']?>" name="tn_absent" value="1" <?=(int)$request['tn_absent']===1?'checked':''?>>Нет ТН</label><small data-document-count><?=count($request['documents'])?>/2</small></div>
              <div class="lab-docs-list" data-document-list><?php foreach($request['documents'] as $document):?><div class="lab-docs-item" data-document-id="<?=(int)$document['id']?>"><span class="lab-docs-name" title="<?=e((string)$document['download_name'])?>"><?=e((string)$document['download_name'])?></span><button class="lab-docs-view" type="button" data-preview-url="<?=app_url($basePath.'/'.(int)$trip['id'].'/documents/'.(int)$document['id'].'?preview=1')?>">Просмотр</button><a class="lab-docs-download" href="<?=app_url($basePath.'/'.(int)$trip['id'].'/documents/'.(int)$document['id'])?>" title="Скачать <?=e((string)$document['download_name'])?>" aria-label="Скачать <?=e((string)$document['download_name'])?>"><svg aria-hidden="true" viewBox="0 0 16 16" fill="none"><path d="M8 2v8m0 0 3-3m-3 3L5 7M3 12.5h10" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg></a><button class="lab-docs-remove" type="button" aria-label="Убрать <?=e((string)$document['download_name'])?>" data-delete-url="<?=app_url($basePath.'/'.(int)$trip['id'].'/requests/'.(int)$request['id'].'/documents/'.(int)$document['id'].'/delete')?>">×</button></div><?php endforeach;?></div>
              <form method="post" enctype="multipart/form-data" action="<?=app_url($basePath.'/'.(int)$trip['id'].'/requests/'.(int)$request['id'].'/documents')?>" class="logistics-upload-form" data-upload-form><?=csrfField()?><input class="lab-docs-input" type="file" name="documents[]" accept=".pdf,.jpg,.jpeg,.png,.webp,.tif,.tiff" multiple aria-label="Выбрать сканы ТН"><button class="lab-docs-add" type="button" <?=count($request['documents'])>=2?'disabled':''?>>+ Добавить скан</button></form><div class="lab-docs-message" role="status"></div>
            </div>
            <div class="logistics-save-state" data-save-state aria-live="polite" hidden><span data-save-label></span></div>
          </div>
        <?php endforeach;?>
        </div>
      </div>
    </details>
  <?php endforeach;?>
    </div>
  </section>
  <?php endforeach;?>
  <p class="lab-review-empty" hidden>Рейсы по запросу не найдены.</p>
</section>
<?php if($reviewerMode):?></div><?php endif;?>
<dialog class="lab-review-dialog" aria-labelledby="lab-review-title"><div class="lab-review-dialog-head"><strong id="lab-review-title"></strong><button type="button" class="lab-review-x" aria-label="Закрыть">×</button></div><div class="lab-review-dialog-body"></div><div class="lab-review-dialog-foot"><button type="button" class="btn btn-secondary lab-review-cancel">Отмена</button><button type="button" class="btn btn-primary lab-review-confirm">Передать на проверку</button></div></dialog>
<script src="<?=app_url('/assets/js/trip-completion-report.js')?>?v=<?=filemtime(base_path('public/assets/js/trip-completion-report.js'))?>" defer></script>
<script src="<?=app_url('/assets/js/trip-completion.js')?>?v=<?=filemtime(base_path('public/assets/js/trip-completion.js'))?>" defer></script>
<script src="<?=app_url('/assets/js/trip-completion-review.js')?>?v=<?=filemtime(base_path('public/assets/js/trip-completion-review.js'))?>" defer></script>
<?php endif;?>
