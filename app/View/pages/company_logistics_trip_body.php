<?php
declare(strict_types=1);
?>
      <div class="logistics-trip-body">
        <div class="logistics-trip-toolbar">
          <div><b>Заявки рейса</b><span><?php if(!empty($trip['actual_start_date'])):?>Выезд: <?=e($fmtDate($trip['actual_start_date']))?><?php endif;?><?php if(!empty($trip['actual_end_date'])):?><?=!empty($trip['actual_start_date'])?' — ':''?>Сдача: <?=e($fmtDate($trip['actual_end_date']))?><?php endif;?></span></div>
          <div>
            <a class="btn btn-secondary" href="<?=app_url($basePath.'/'.(int)$trip['id'].'/report')?>">Скачать сводный отчёт</a>
            <a class="btn btn-secondary" href="<?=app_url($basePath.'/'.(int)$trip['id'].'/package')?>">Скачать полный комплекс</a>
            <?php if($reviewerMode&&$tripStatus==='review'):?>
              <form class="lab-review-form" method="post" action="<?=app_url($basePath.'/'.(int)$trip['id'].'/review')?>" data-review-form><?=csrfField()?><input type="hidden" name="action" value="return_work"><input type="hidden" name="version" value="<?=(int)$trip['review_version']?>"><button type="submit" class="btn btn-secondary lab-review-submit">Вернуть на доработку</button></form>
            <?php endif;?>
            <?php if(($reviewerMode&&$tripStatus!=='work')||(!$reviewerMode&&$tripStatus==='work')):?>
              <form class="lab-review-form" method="post" action="<?=app_url($basePath.'/'.(int)$trip['id'].'/review')?>" data-review-form><?=csrfField()?><input type="hidden" name="action" value="<?=$reviewerMode?($tripStatus==='archive'?'restore':'archive'):'submit'?>"><input type="hidden" name="version" value="<?=(int)$trip['review_version']?>"><button type="submit" class="btn <?=(!$reviewerMode||$tripStatus==='review')?'btn-primary':'btn-secondary'?> lab-review-submit"><?=$reviewerMode?($tripStatus==='archive'?'Вернуть из архива':'Переместить в архив'):'Передать на проверку'?></button></form>
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
