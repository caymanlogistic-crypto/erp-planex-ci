<?php
/** @var list<array<string,mixed>> $trips */
$all=count($trips);
$selectedTrip=(int)($_GET['trip_id']??0);
if ($selectedTrip<=0 && $trips) $selectedTrip=(int)$trips[0]['id'];
$executorLabel=static fn(string $code):string=>match($code){'planex'=>'ООО «Планэкс»','mg_logistic'=>'ООО «МГ Логистик»',default=>'Не определён'};
$progressColor=static function(int $percent):string {
    $share=$percent<=50?$percent/50:($percent-50)/50;
    $first=$percent<=50?'var(--danger)':'var(--warning-border)';
    $second=$percent<=50?'var(--warning-border)':'var(--success)';
    return 'color-mix(in srgb, '.$first.' '.round((1-$share)*100).'%, '.$second.')';
};
?>
<link rel="stylesheet" href="<?= e(app_url('/assets/css/waste-trip-closing.css')) ?>?v=<?= filemtime(base_path('public/assets/css/waste-trip-closing.css')) ?>">
<?php if($success):?><div class="notice success" role="status"><?= e((string)$success) ?></div><?php endif;?>
<?php if($flashError||$error):?><div class="notice warn" role="alert"><?= e((string)($flashError?:$error)) ?></div><?php endif;?>
<div class="closing-page">
  <div class="page-head"><div class="page-head-left"><h1 class="page-title">Сбор закрывающих документов</h1><div class="page-summary">Документы по завершённым рейсам.</div></div></div>
  <?php if(!$schemaReady):?><div class="notice warn">Раздел ожидает обновление схемы данных.</div><?php else:?>
  <div class="table-toolbar closing-toolbar"><div class="closing-filters" role="group" aria-label="Фильтр по состоянию">
    <button type="button" class="closing-filter is-active" data-closing-filter="all" aria-pressed="true">Все рейсы</button>
    <button type="button" class="closing-filter" data-closing-filter="open" aria-pressed="false">Не хватает документов</button>
    <button type="button" class="closing-filter" data-closing-filter="review" aria-pressed="false">На проверке</button>
  </div><label class="closing-search"><span class="sr-only">Поиск по рейсу или перевозчику</span><input class="toolbar-search" type="search" data-closing-search placeholder="Рейс или перевозчик"></label></div>
  <section class="closing-list panel" aria-label="Завершённые рейсы"><div class="closing-list-head">ЗАВЕРШЁННЫЕ РЕЙСЫ <span data-closing-count><?= $all ?></span></div>
    <?php foreach($trips as $trip):
        $docs=$trip['documents'];$bySlot=[];$extras=[];
        foreach($docs as $document){if($document['slot_code']==='extra')$extras[]=$document;else $bySlot[$document['slot_code']]=$document;}
        $missing=[];foreach(\App\Service\WasteTripClosingService::SLOTS as $slot=>$label)if(!isset($bySlot[$slot]))$missing[]=$label;
        $count=count($docs);$total=4+count($extras);$percent=$total?min(100,(int)round($count/$total*100)):0;
        $state=$trip['review_state']==='review'?'review':($count===0?'new':($missing?'partial':'ready'));
        $paid=$trip['paid_bank_transactions'];
        $search=mb_strtolower($trip['business_id'].' '.$trip['carrier_name'].' '.$trip['manager_name'].' '.$executorLabel((string)$trip['legal_entity_code']),'UTF-8');
    ?>
    <details class="closing-trip" name="closing-trip" data-closing-trip data-state="<?= e($state) ?>" data-search="<?= e($search) ?>"<?= (int)$trip['id']===$selectedTrip?' open':'' ?>>
      <summary class="closing-trip-summary"><span class="closing-chevron" aria-hidden="true"></span>
        <span class="closing-identity"><b>Рейс #<?= e((string)$trip['business_id']) ?></b><small>Завершён <?= e($trip['actual_end_date']?date('d.m.Y',strtotime((string)$trip['actual_end_date'])):'—') ?></small></span>
        <span class="closing-carrier"><small>ПЕРЕВОЗЧИК</small><b><?= e((string)$trip['carrier_name']) ?></b><span>Менеджер: <?= e((string)$trip['manager_name']) ?></span></span>
        <span class="closing-executor"><small>ИСПОЛНИТЕЛЬ</small><b><?= e($executorLabel((string)$trip['legal_entity_code'])) ?></b><span><?= e((string)$trip['recipient_email']) ?></span></span>
        <span class="closing-progress"><small>ДОКУМЕНТЫ</small><b><?= $count ?> из <?= $total ?> получено</b><span class="closing-track" aria-label="<?= $count ?> из <?= $total ?> документов получено"><i style="width:<?= $percent ?>%;--closing-progress-color:<?= e($progressColor($percent)) ?>"></i></span></span>
        <span class="closing-missing"><small>НЕ ХВАТАЕТ ДОКУМЕНТОВ</small><?php if($missing):?><span><?= e(implode(' · ',$missing)) ?></span><?php else:?><b>Все документы получены</b><?php endif;?></span>
      </summary>
      <div class="closing-trip-body">
        <div class="closing-body-head"><div><h2>Закрывающие документы рейса #<?= e((string)$trip['business_id']) ?></h2><p>Добавьте файлы и передайте комплект на проверку.</p></div><span class="closing-note">Получатель письма: <?= e((string)$trip['recipient_email']) ?></span></div>
        <?php if($paid):?><div class="closing-finance-handoff"><strong>Оплата отражена · <?= count($paid) ?> банковских операций</strong><span>Собранные файлы связаны с платежами в «Финансы → Закрывающие документы». Договор/заявка и акт/УПД учитываются в обязательных категориях, остальные доступны как приложения.</span></div><?php endif;?>
        <div class="closing-doc-table">
          <?php foreach(\App\Service\WasteTripClosingService::SLOTS as $slot=>$label):$document=$bySlot[$slot]??null;
            $defaultCode=$slot==='contract'?'application':($slot==='act'?'upd':$slot);
            $code=(string)($document['requirement_code']??$defaultCode);
          ?><div class="closing-doc-row">
            <div><strong><?= e($label) ?></strong><?php if($slot==='contract'||$slot==='act'):?><small><?= $slot==='contract'?'Выберите договор или заявку':'Выберите акт или УПД' ?></small><?php else:?><small>Файл PDF или изображение</small><?php endif;?></div>
            <span class="closing-file-name<?= $document?' is-present':'' ?>"><?php if($document):?><a href="<?= e(app_url('/company/trips/closing-documents/'.(int)$trip['id'].'/documents/'.(int)$document['id'])) ?>"><?= e((string)$document['original_name']) ?></a><?php else:?>Файл не получен<?php endif;?></span>
            <form class="closing-upload-form" method="post" enctype="multipart/form-data" action="<?= e(app_url('/company/trips/closing-documents/'.(int)$trip['id'].'/documents')) ?>">
              <?= csrfField() ?><input type="hidden" name="slot_code" value="<?= e($slot) ?>">
              <?php if($slot==='contract'):?><select name="requirement_code" aria-label="Вид договора или заявки"><option value="application"<?= $code==='application'?' selected':'' ?>>Заявка</option><option value="agreement"<?= $code==='agreement'?' selected':'' ?>>Договор</option></select>
              <?php elseif($slot==='act'):?><select name="requirement_code" aria-label="Вид акта или УПД"><option value="upd"<?= $code==='upd'?' selected':'' ?>>УПД</option><option value="act"<?= $code==='act'?' selected':'' ?>>Акт</option></select>
              <?php else:?><input type="hidden" name="requirement_code" value="<?= e($defaultCode) ?>"><?php endif;?>
              <label class="btn btn-secondary closing-upload"><?= $document?'Заменить':'Добавить' ?><input type="file" name="document_file" accept=".pdf,.jpg,.jpeg,.png,.webp,.tif,.tiff,.doc,.docx,.xls,.xlsx" required></label>
            </form>
          </div><?php endforeach;?>
          <?php foreach($extras as $document):?><div class="closing-doc-row"><div><strong><?= e((string)$document['custom_title']) ?></strong><small>Другой документ</small></div><span class="closing-file-name is-present"><a href="<?= e(app_url('/company/trips/closing-documents/'.(int)$trip['id'].'/documents/'.(int)$document['id'])) ?>"><?= e((string)$document['original_name']) ?></a></span><form method="post" action="<?= e(app_url('/company/trips/closing-documents/'.(int)$trip['id'].'/documents/'.(int)$document['id'].'/remove')) ?>"><?= csrfField() ?><button class="btn btn-secondary closing-upload" type="submit">Убрать</button></form></div><?php endforeach;?>
        </div>
        <form class="closing-extra-upload" method="post" enctype="multipart/form-data" action="<?= e(app_url('/company/trips/closing-documents/'.(int)$trip['id'].'/documents')) ?>">
          <?= csrfField() ?><input type="hidden" name="slot_code" value="extra"><input type="hidden" name="requirement_code" value="supporting">
          <label><span class="sr-only">Название другого документа</span><input class="field-input" type="text" name="custom_title" placeholder="Название другого документа" maxlength="255" required></label>
          <label class="btn btn-secondary closing-upload">Файл<input type="file" name="document_file" accept=".pdf,.jpg,.jpeg,.png,.webp,.tif,.tiff,.doc,.docx,.xls,.xlsx" required></label>
          <button type="submit" class="btn btn-secondary">+ Добавить другой документ</button>
        </form>
        <div class="closing-trip-actions"><button type="button" class="btn btn-primary closing-submit" data-review-open data-trip-id="<?= (int)$trip['id'] ?>" data-business-id="<?= e((string)$trip['business_id']) ?>" data-recipient="<?= e((string)$trip['recipient_email']) ?>" data-version="<?= (int)$trip['review_version'] ?>" data-missing="<?= e(implode(', ',$missing)) ?>"<?= $trip['review_state']==='sending'||$trip['review_state']==='unknown'?' disabled':'' ?>><?= $trip['review_state']==='review'?'Передать повторно':'Передать на проверку' ?></button></div>
        <?php if($trip['review_state']==='unknown'):?><form class="closing-unknown" method="post" action="<?= e(app_url('/company/trips/closing-documents/'.(int)$trip['id'].'/review/confirm-not-sent')) ?>">
          <?= csrfField() ?><input type="hidden" name="version" value="<?= (int)$trip['review_version'] ?>">
          <p class="closing-feedback">Результат отправки неизвестен. Проверьте исходящие письма рабочего ящика.</p>
          <label><input type="checkbox" name="confirm_not_sent" value="1" required> Подтверждаю, что письмо не было отправлено</label>
          <button type="submit" class="btn btn-secondary">Разрешить повторную отправку</button>
        </form><?php endif;?>
      </div>
    </details>
    <?php endforeach;?>
    <p class="closing-empty" data-closing-empty<?= $trips?' hidden':'' ?>>Рейсы по запросу не найдены.</p>
  </section>
  <?php endif;?>
</div>
<dialog class="closing-review-dialog" data-review-dialog aria-labelledby="closing-review-title">
  <form method="post" data-review-form><div class="closing-review-head"><strong id="closing-review-title">Передать на проверку</strong><button type="button" data-review-close aria-label="Закрыть">×</button></div>
    <div class="closing-review-body"><p data-review-missing></p><div class="closing-review-mail"><strong>Письмо при передаче</strong><span data-review-recipient></span><span data-review-subject></span><span>В письме: сводка комплекта и защищённая ссылка на архив файлов рейса.</span><span class="closing-review-archive">Архив документов рейса <small>Хранится 30 дней</small></span></div></div>
    <?= csrfField() ?><input type="hidden" name="version" data-review-version>
    <div class="closing-review-foot"><button type="button" class="btn btn-secondary" data-review-close>Отмена</button><button type="submit" class="btn btn-primary">Передать на проверку</button></div>
  </form>
</dialog>
<script src="<?= e(app_url('/assets/js/waste-trip-closing.js')) ?>?v=<?= filemtime(base_path('public/assets/js/waste-trip-closing.js')) ?>" defer></script>
