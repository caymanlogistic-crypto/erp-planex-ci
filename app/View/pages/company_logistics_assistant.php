<?php
declare(strict_types=1);
require base_path('app/View/pages/company_logistics_helpers.php');
$reviewerMode=$reviewerMode??false;
$deferTripBodies=$deferTripBodies??false;
$basePath=$reviewerMode?'/company/tn-review':'/company/logistics-assistant';
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
<?php if($reviewerMode):
  $reviewTabCounts=['all'=>0,'work'=>0,'review'=>0,'archive'=>0];
  foreach($trips as $reviewTabTrip){
    $reviewTabStatus=(string)$reviewTabTrip['review_status'];
    if(isset($reviewTabCounts[$reviewTabStatus])){$reviewTabCounts[$reviewTabStatus]++;$reviewTabCounts['all']++;}
  }
?>
<nav class="registry-ui-tabs" aria-label="Фильтр по статусу рейса" data-review-tabs>
  <button type="button" data-review-tab="all" data-registry-tab="all" aria-pressed="true">Показать все <b><?=$reviewTabCounts['all']?></b></button>
  <button type="button" data-review-tab="work" data-registry-tab="work" aria-pressed="false">В работе <b><?=$reviewTabCounts['work']?></b></button>
  <button type="button" data-review-tab="review" data-registry-tab="review" aria-pressed="false">На проверке <b><?=$reviewTabCounts['review']?></b></button>
  <button type="button" data-review-tab="archive" data-registry-tab="archive" aria-pressed="false">Архив <b><?=$reviewTabCounts['archive']?></b></button>
</nav>
<div class="panel tn-review-shell"><?php endif;?>
<?php if(!$reviewerMode):?><div class="metric-grid logistics-metrics">
  <div class="metric-card"><div class="metric-label">Рейсов в работе</div><div class="metric-value"><?=(int)$metrics['trips']?></div></div>
  <div class="metric-card"><div class="metric-label">Заявок всего</div><div class="metric-value"><?=(int)$metrics['requests']?></div></div>
  <div class="metric-card"><div class="metric-label">Полностью собрано</div><div class="metric-value"><?=(int)$metrics['ready']?></div></div>
  <div class="metric-card"><div class="metric-label">Требуют дополнения</div><div class="metric-value"><?=(int)$metrics['problems']?></div></div>
</div><?php endif;?>
<?php if($reviewerMode):?>
<form method="get" action="<?=app_url($basePath)?>" class="table-toolbar registry-ui-toolbar logistics-filter" data-review-filters>
  <div class="found-label">Найдено: <b data-review-found><?=count($trips)?></b></div>
  <div class="toolbar-right"><button type="button" class="registry-ui-reset" hidden data-review-reset>Сбросить фильтр</button><input class="toolbar-search registry-ui-search" type="search" name="q" value="<?=e($query)?>" placeholder="Поиск" aria-label="Поиск по рейсам и заявкам"></div>
</form>
<?php else:?>
<form method="get" action="<?=app_url($basePath)?>" class="table-toolbar logistics-filter">
  <input type="hidden" name="filter" value="1">
  <label class="logistics-check lab-show-all"><input type="checkbox" name="show_all_trips" value="1"> Показать все рейсы</label>
  <div class="toolbar-right"><input class="toolbar-search" type="search" name="q" value="<?=e($query)?>" placeholder="Рейс, заявка, водитель, менеджер" aria-label="Поиск по рейсам и заявкам"></div>
</form>
<?php endif;?>
<section class="logistics-workspace" aria-label="Рейсы для обработки" data-logistics-assistant data-reviewer-mode="<?=$reviewerMode?'1':'0'?>">
  <?php foreach($reviewerMode?['all'=>'']:['work'=>'В работе','review'=>'На проверке','archive'=>'Проверено / архив'] as $groupStatus=>$groupName):
    $groupTrips=$reviewerMode?$trips:array_values(array_filter($trips,static fn(array $trip):bool=>$trip['review_status']===$groupStatus));?>
  <section class="lab-review-group lab-review-group--<?=e($groupStatus)?>" data-review-group="<?=e($groupStatus)?>" <?=(!$reviewerMode&&$groupStatus!=='work')?'hidden':''?>>
    <?php if(!$reviewerMode):?><div class="lab-review-group-head"><h2><?=e($groupName)?></h2><span class="lab-review-group-count" aria-label="Количество рейсов"><?=count($groupTrips)?></span></div><?php endif;?>
    <div class="logistics-trip-list">
  <?php foreach($groupTrips as $trip):$tripStatus=(string)$trip['review_status'];$open=(int)($openTripId??0)===(int)$trip['id'];$noTnCount=count(array_filter($trip['requests'],static fn(array $request):bool=>(int)($request['tn_absent']??0)===1));$timeCount=count(array_filter($trip['requests'],static fn(array $request):bool=>(int)($request['tn_absent']??0)!==1&&!empty($request['arrived_at'])&&!empty($request['departed_at'])));$processedCount=$timeCount+$noTnCount;
    $summaryRows=$deferTripBodies?array_map(static fn(array $request):array=>[
      'id'=>(string)$request['zayavka_id'],'scans'=>(int)($request['document_count']??count($request['documents']??[])),'tnAbsent'=>(int)$request['tn_absent']===1,
      'time'=>!empty($request['arrived_at'])&&!empty($request['departed_at'])?$fmtPickupInterval($request['arrived_at'],$request['departed_at']):'',
      'date'=>(string)($request['tn_datetime_status']??''),'recovery'=>(string)($request['recovery_source']??''),
      'oo'=>(string)($request['sender_signature_status']??''),'driver'=>(string)($request['driver_signature_status']??''),
      'comment'=>(string)$request['completion_comment']],$trip['requests']):[];
    $searchText=$deferTripBodies?implode(' ',array_merge([(string)$trip['business_id'],(string)$trip['manager_name'],(string)$trip['manager_phone'],(string)$trip['driver_name'],(string)$trip['driver_phone'],(string)($trip['comment']??'')],array_map(static fn(array $request):string=>implode(' ',[(string)$request['zayavka_id'],$fmtMass($request['snapshot_mass_kg']),(string)$request['shipper_name'],(string)$request['pickup_address'],(string)$request['consignee_name'],(string)$request['delivery_address'],(string)$request['completion_comment'],implode(' ',$request['document_names']??array_column($request['documents']??[],'download_name'))]),$trip['requests']))):'';?>
    <details class="logistics-trip panel" data-trip-id="<?=(int)$trip['id']?>" data-review-status="<?=e($tripStatus)?>" data-review-version="<?=(int)$trip['review_version']?>" <?=$deferTripBodies?'data-summary="'.e(json_encode($summaryRows,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)).'" data-search="'.e($searchText).'"':''?> <?=$open?'open':''?>>
      <summary class="logistics-trip-summary">
        <span class="logistics-trip-chevron" aria-hidden="true"></span>
        <span class="lab-trip-identity"><b>Рейс #<?=e((string)$trip['business_id'])?></b><strong class="lab-trip-status <?=($trip['status']??'')==='in_progress'?'is-progress':'is-delivered'?>"><?=($trip['status']??'')==='in_progress'?'Идёт вывоз':'Груз сдан'?></strong><?php if(!empty($trip['actual_start_date'])):?><small title="Фактическая дата начала рейса">Выезд: <?=e($fmtDate($trip['actual_start_date']))?></small><?php endif;?><?php if(!empty($trip['actual_end_date'])):?><small title="Фактическая дата сдачи груза">Сдача: <?=e($fmtDate($trip['actual_end_date']))?></small><?php endif;?></span>
        <span class="lab-trip-contact lab-trip-manager"><small class="lab-trip-label">Менеджер</small><b class="lab-trip-name"><?=e(trim((string)($trip['manager_name']??''))?:'Не указан')?></b><span class="lab-trip-contact-line">Контакт: <?=e(trim((string)($trip['manager_phone']??''))?:'телефон не указан')?></span></span>
        <span class="lab-trip-contact"><small class="lab-trip-label">Водитель</small><b class="lab-trip-name"><?=e(trim((string)$trip['driver_name'])?:'Не указан')?></b><span class="lab-trip-contact-line"><?=e(trim((string)($trip['driver_phone']??''))?:'Телефон не указан')?></span></span>
        <span class="lab-trip-progress"><small class="lab-trip-label">Обработано заявок</small><b class="lab-trip-progress-count" data-trip-progress><?=$processedCount?> из <?=(int)$trip['request_count']?> обработано</b><span class="lab-trip-progress-track <?=$noTnCount?'has-no-tn':''?> <?=$timeCount===0?'has-no-ready':''?>" aria-label="Время внесено: <?=$timeCount?>; нет ТН: <?=$noTnCount?>"><span class="lab-trip-progress-fill" style="width:<?=$trip['request_count']?round(100*$timeCount/$trip['request_count'],2):0?>%"></span><span class="lab-trip-progress-no-tn" style="width:<?=$trip['request_count']?round(100*$noTnCount/$trip['request_count'],2):0?>%" title="Нет ТН: <?=$noTnCount?>"></span></span></span>
        <span class="lab-trip-overview" hidden></span><span class="lab-trip-restored" hidden></span>
      </summary>
      <?php if(!empty($deferTripBodies)):?>
      <div class="logistics-trip-body" data-trip-body-url="<?=app_url($basePath.'/'.(int)$trip['id'].'/body')?>"></div>
      <?php else:require base_path('app/View/pages/company_logistics_trip_body.php');endif;?>
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
