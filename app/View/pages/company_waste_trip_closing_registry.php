<?php
require_once base_path('app/View/components/registry_tabler_icons.php');
require_once base_path('app/Service/WasteTripRegistryPresenter.php');
require_once base_path('app/Service/RegistryManagerPresentation.php');

/** @var list<array<string,mixed>> $trips */
$canCreateInvoice=$canCreateInvoice??false;
$contractors=$contractors??[];
$clients=$clients??[];
$responsibleUsers=$responsibleUsers??[];
$canFilterManagers=$canFilterManagers??true;
$canFilterLeader=$canFilterLeader??\App\Security\AccessRuntime::current()->isSystemOwner();
$completionQuarters=$completionQuarters??[];
$reportRows=$reportRows??[];
$all=count($trips);
$tabCounts=['all'=>$all,'incomplete'=>0,'ready'=>0,'paid_incomplete'=>0,'paid_complete'=>0];
foreach($trips as $trip){$state=\App\Service\WasteTripClosingService::section($trip);if(isset($tabCounts[$state]))$tabCounts[$state]++;}
$selectedTrip=(int)($_GET['trip_id']??0);
$executorLabel=static fn(string $code):string=>match($code){'planex'=>'ООО «Планэкс»','mg_logistic'=>'ООО «МГ Логистик»',default=>'Не определён'};
$progressColor=static function(int $percent):string {
    $share=$percent<=50?$percent/50:($percent-50)/50;
    $first=$percent<=50?'var(--danger)':'var(--warning-border)';
    $second=$percent<=50?'var(--warning-border)':'var(--success)';
    return 'color-mix(in srgb, '.$first.' '.round((1-$share)*100).'%, '.$second.')';
};
require base_path('app/View/partials/registry_ui_state_scope.php');
?>
<link rel="stylesheet" href="<?= e(app_url('/assets/css/waste-trip-registry.css')) ?>?v=<?= filemtime(base_path('public/assets/css/waste-trip-registry.css')) ?>">
<link rel="stylesheet" href="<?= e(app_url('/assets/css/waste-trip-closing.css')) ?>?v=<?= filemtime(base_path('public/assets/css/waste-trip-closing.css')) ?>">
<link rel="stylesheet" href="<?= e(app_url('/assets/css/erp-ledger-table.css')) ?>?v=<?= filemtime(base_path('public/assets/css/erp-ledger-table.css')) ?>">
<link rel="stylesheet" href="<?= e(app_url('/assets/css/waste-trip-closing-registry.css')) ?>?v=<?= filemtime(base_path('public/assets/css/waste-trip-closing-registry.css')) ?>">
<link rel="stylesheet" href="<?= e(app_url('/assets/css/registry-components.css')) ?>?v=<?= filemtime(base_path('public/assets/css/registry-components.css')) ?>">
<?php if(!empty($_SESSION['invoice_success'])):?><div class="notice success" role="status"><?= e((string)$_SESSION['invoice_success']) ?></div><?php unset($_SESSION['invoice_success']);endif;?>
<?php if(in_array($success,['Документ добавлен.','Документ убран из комплекта.','Диагностическая карта добавлена.'],true)):?><span data-closing-action-result="success" hidden></span><?php elseif($success):?><div class="notice success" role="status"><?= e((string)$success) ?></div><?php endif;?>
<?php if($flashError||$error):?><div class="notice warn" role="alert"><?= e((string)($flashError?:$error)) ?></div><?php endif;?>
<div class="closing-page workspace-shell-1690" data-closing-state-key="<?= e($viewStateKey??'') ?>" data-registry-id="logistics-closing-documents" data-registry-state-scope="<?= e($registryUiStateScope) ?>" data-registry-default-tab="all">
  <?php
  $registryHeadTitle = 'Закрывающие документы';
  $registryHeadSummary = 'Рейсы и документы';
  $registryHeadIconHtml = registry_tabler_header_icon('file-text');
  $registryHeadActionsHtml = $schemaReady ? '<a class="btn btn-primary" data-closing-report-download href="' . e(app_url('/company/logistics/closing-documents/summary.xlsx')) . '">Скачать Excel</a>' : '';
  $registryHeadExtraClass = 'waste-trips-page-head';
  require base_path('app/View/partials/registry_ui_head.php');
  ?>
  <?php if(!$schemaReady):?><div class="notice warn">Раздел ожидает обновление схемы данных.</div><?php else:?>
  <div class="lab-status-tabs registry-ui-tabs" role="group" aria-label="Фильтр по состоянию">
    <button type="button" class="closing-filter is-active" data-closing-filter="all" data-registry-tab="all" aria-pressed="true">Все рейсы <b data-closing-filter-count="all"><?= $tabCounts['all'] ?></b></button>
    <button type="button" class="closing-filter" data-closing-filter="incomplete" data-registry-tab="incomplete" aria-pressed="false">Не хватает документов <b data-closing-filter-count="incomplete"><?= $tabCounts['incomplete'] ?></b></button>
    <button type="button" class="closing-filter" data-closing-filter="ready" data-registry-tab="ready" aria-pressed="false">Готово к оплате <b data-closing-filter-count="ready"><?= $tabCounts['ready'] ?></b></button>
    <button type="button" class="closing-filter" data-closing-filter="paid_complete" data-registry-tab="paid_complete" aria-pressed="false">Оплачено <b data-closing-filter-count="paid_complete"><?= $tabCounts['paid_complete'] ?></b></button>
  </div>
  <div class="closing-registry-card table-card table-card--standard lab-registry-card registry-ui-card registry-ui-sticky-table registry-ui-sticky-table--wide-scroll registry-ui-scroll" data-erp-grid><div class="table-toolbar closing-toolbar waste-trip-toolbar registry-ui-toolbar"><span class="closing-found found-label">Найдено: <span data-closing-count><?= $all ?></span></span><div class="closing-toolbar-right toolbar-right"><button type="button" class="registry-ui-reset" hidden>Сбросить фильтр</button><div class="closing-extra-filters">
    <?php if($canFilterLeader):?><label class="closing-filter-field canon-leader-company"><span class="sr-only">Компания рейса</span><select name="company" class="toolbar-select" aria-label="Компания рейса" data-table-reference-select="closing" data-closing-entity data-registry-field="company" data-registry-default="all"><option value="all">Все компании</option><option value="planex">ООО «Планэкс»</option><option value="mg_logistic">ООО «МГ Логистик»</option></select></label><label class="closing-filter-field canon-leader-quarter"><span class="sr-only">Квартал фактического окончания</span><select name="quarter" class="toolbar-select" aria-label="Квартал фактического окончания" data-table-reference-select="closing" data-closing-quarter data-registry-field="quarter" data-registry-default=""><option value="">Все кварталы</option><?php foreach($completionQuarters as $key=>$quarter):?><option value="<?= e($key) ?>" title="<?= (int)$quarter[0] ?> квартал 20<?= e((string)$quarter[1]) ?> года"><?= (int)$quarter[0] ?>К<?= e((string)$quarter[1]) ?></option><?php endforeach;?></select></label><?php endif;?>
    <?php if($canFilterManagers):?><label class="closing-filter-field"><span class="sr-only">Ответственный менеджер</span><select class="toolbar-select" data-table-reference-select="closing" data-closing-manager aria-label="Ответственный менеджер" data-registry-field="manager" data-registry-default=""><option value="">Все менеджеры</option><?php foreach($responsibleUsers as $user):?><option value="<?= (int)$user['membership_id'] ?>"><?= e(trim((string)$user['full_name'])!==''?\App\Service\WasteTripRegistryPresenter::manager((string)$user['full_name']):(string)$user['login']) ?></option><?php endforeach;?></select></label><?php endif;?>
    <label class="closing-filter-field closing-sort-field"><span class="sr-only">Сортировка рейсов</span><select class="toolbar-select" name="sort" data-table-reference-select="closing" data-closing-sort aria-label="Сортировка рейсов" data-registry-field="sort" data-registry-default="unloading"><option value="unloading" selected>Дата выгрузки</option><option value="id">ID</option></select></label>
  </div><div class="registry-ui-search-group"><label class="closing-search"><span class="sr-only">Поиск по рейсу или перевозчику; несколько номеров рейсов через запятую</span><input class="toolbar-search registry-ui-search" type="search" data-closing-search placeholder="Рейсы: 322, 310" data-registry-field="query" data-registry-default=""></label></div></div></div>
  <div class="closing-grid lab-grid table-scroll" role="region" aria-label="Таблица закрывающих документов"><table class="closing-table lab-table table erp-ledger-table registry-ui-table registry-ui-data registry-ui-data--wide registry-ui-table--documents" data-reference-columns="departures"><colgroup><?php for($column=1;$column<=14;$column++):?><col class="<?= $column===1?'registry-ui-id':($column===2?'registry-ui-trip-cell':'registry-ui-document-column') ?>"><?php endfor;?></colgroup>
    <thead><tr><?php foreach(\App\Service\WasteTripClosingReportService::REGISTRY_HEADERS as $header):?><th scope="col"><?= e($header) ?></th><?php endforeach;?></tr></thead>
    <tbody><?php foreach($reportRows as $row):$display=\App\Service\WasteTripClosingReportService::registryValues($row);$sectionState=(string)$row['section_state'];?><tr data-report-trip-id="<?= (int)$row['id'] ?>" data-unloading-date="<?= e((string)$row['unloading_date']) ?>" tabindex="0" aria-label="Открыть документы рейса №<?= e((string)$row['number']) ?>" title="Двойной щелчок или Enter — открыть документы">
      <td class="col-mono registry-id registry-ui-id" data-label="ID"><div class="registry-ui-value"><?= e($display[0]) ?></div></td>
      <td class="waste-description registry-ui-trip-cell" data-label="Описание рейса"><div class="registry-ui-value"><span class="waste-description-text registry-ui-trip" data-registry-trip><?= e($display[1]) ?></span><span class="waste-legal-entity registry-ui-secondary"><?= e($display[3]) ?></span></div></td>
      <td class="registry-unloading-date" data-label="Дата выгрузки"><div class="registry-ui-value"><span class="<?= $display[2]==='—'?'registry-ui-missing':'registry-ui-primary' ?>"><?= e($display[2]) ?></span></div></td>
      <td class="registry-executor" data-label="Подряд. / вод."><div class="registry-ui-value"><span class="registry-executor-line registry-ui-name"><?= e((string)$row['legal_entity']) ?></span><span class="registry-executor-line registry-executor-secondary registry-ui-secondary"><?= e((string)$row['driver']) ?></span></div></td>
      <?php foreach(['tn','contract','invoice','act','waybill'] as $key):$present=$row[$key]==='Есть';?><td class="closing-report-status is-<?= $present?'present':'missing' ?>"><div class="registry-ui-value"><span class="registry-ui-status registry-ui-<?= $present?'success':'error' ?>"><?= $present?'Есть':'НЕТ' ?></span></div></td><?php endforeach;?>
      <?php if(count($row['diagnostic_card_details'])>1):?><td class="closing-report-status is-<?= e((string)$row['diagnostic_card_state']) ?>"><div class="registry-ui-value"><?php foreach(explode("\n",$display[10]) as $index=>$unitLabel):$unit=$row['diagnostic_card_details'][$index];?><span class="closing-report-unit registry-ui-status registry-ui-<?= $unit['status']==='Есть'?'success':'error' ?>" title="<?= e($unit['label'].': '.$unit['status']) ?>"><?= e($unitLabel) ?></span><?php endforeach;?></div></td><?php else:$cardPresent=$row['diagnostic_card_state']==='present';?><td class="closing-report-status is-<?= e((string)$row['diagnostic_card_state']) ?>"><div class="registry-ui-value"><span class="registry-ui-status registry-ui-<?= $cardPresent?'success':'error' ?>"><?= $cardPresent?e($display[10]):'НЕТ' ?></span></div></td><?php endif;?>
      <td class="closing-report-status is-<?= $sectionState==='incomplete'?'missing':($sectionState==='ready'?'partial':'present') ?><?= $sectionState==='ready'?' registry-ui-warning':'' ?>" title="<?= e((string)$row['payment_status']) ?>"><div class="registry-ui-value"><span class="registry-ui-status <?= $sectionState==='incomplete'?'registry-ui-error':($sectionState==='ready'?'':'registry-ui-success') ?>"><?= e($display[11]) ?></span></div></td>
      <td><div class="registry-ui-value"><span class="<?= $display[12]==='—'?'registry-ui-missing':'registry-ui-primary' ?>"><?= e($display[12]) ?></span></div></td><td title="<?= e((string)$row['manager']) ?>"><div class="registry-ui-value"><span class="registry-ui-primary" aria-label="<?= e((string)$row['manager']) ?>"><?= e(\App\Service\RegistryManagerPresentation::name((string)$row['manager'])) ?></span><span hidden aria-hidden="true"> <?= e((string)$row['manager']) ?></span></div></td>
      <td title="<?= e((string)$row['section']) ?>"><div class="registry-ui-value"><?php if(str_starts_with($sectionState,'paid_')):?><span class="lab-section-paid registry-ui-status registry-ui-success">Оплачено</span><span class="lab-section-detail registry-ui-secondary"><?= $sectionState==='paid_complete'?'Полный комплект':'Неполный комплект' ?></span><?php else:?><span class="registry-ui-primary"><?= e($display[14]) ?></span><?php endif;?></div></td>
    </tr><?php endforeach;?></tbody>
  </table><p class="closing-empty" data-closing-empty<?= $reportRows?' hidden':'' ?>>Рейсы по запросу не найдены.</p></div>
  <p class="closing-table-hint lab-table-hint">Дважды щёлкните по рейсу, чтобы открыть документы. Для клавиатуры — Enter.</p>
  <section class="closing-list panel closing-source-list" aria-hidden="true"><div class="closing-list-head"><span data-closing-list-label>РЕЕСТР РЕЙСОВ</span></div>
    <?php foreach($trips as $trip):
        $docs=$trip['documents'];$bySlot=[];$extras=[];
        foreach($docs as $document){if($document['slot_code']==='extra')$extras[(string)$document['custom_title']][]=$document;else $bySlot[$document['slot_code']][]=$document;}
        $missing=[];foreach(\App\Service\WasteTripClosingService::SLOTS as $slot=>$label)if(!isset($bySlot[$slot]))$missing[]=$label;
        $transport=$trip['transport']??null;$cardUnits=$transport['units']??[];
        $missingCards=[];
        foreach($cardUnits as $unit){
            if(!empty($unit['has_card'])||!empty($unit['documents']))continue;
            $missingCards[]=$unit;
        }
        $cardComplete=$cardUnits!==[]&&$missingCards===[];
        $count=count(array_intersect(array_keys($bySlot),array_keys(\App\Service\WasteTripClosingService::SLOTS)))+(int)$cardComplete;
        $total=count(\App\Service\WasteTripClosingService::SLOTS)+1;
        $percent=(int)round($count/$total*100);
        $section=\App\Service\WasteTripClosingService::section($trip);
        $paid=$trip['paid_bank_transactions'];
        $requestCount=(int)($trip['request_count']??0);$processedCount=(int)($trip['processed_count']??0);
        $tnUnprocessed=$requestCount===0||$processedCount<$requestCount;
        $requestPercent=$requestCount>0?min(100,(int)round($processedCount/$requestCount*100)):0;
        $tnIssues=$trip['tn_issues']??[];
        $tnIssueGroups=[];
        foreach($tnIssues as $issue){
            if(preg_match('/^Заявка №(\d+):\s*(.+)$/u',(string)$issue,$parts)){
                $reason=$parts[2];$tnIssueGroups[$reason]??=[];$tnIssueGroups[$reason][]=$parts[1];
            }else{$tnIssueGroups[(string)$issue]??=[];}
        }
        $invoiceSummary=$trip['invoice_summary']??['status'=>'missing','detail'=>'—','invoice_id'=>0];
        $carrierDebt=\App\Service\WasteTripClosingService::carrierDebt($trip);
        $hasCarrierPayment=(float)($trip['carrier_paid_amount']??0)>0;
        $invoiceStatus=$hasCarrierPayment?($carrierDebt?'Оплачен частично':'Оплачен'):match($invoiceSummary['status']){'paid'=>'Оплачен','waiting'=>'Ожидает оплаты',default=>'Не введён'};
        $invoiceDetail=$hasCarrierPayment&&($trip['carrier_paid_date']??null)?'Оплата: '.date('d.m.Y',strtotime((string)$trip['carrier_paid_date'])):(string)$invoiceSummary['detail'];
        $invoiceDisplayStatus=$hasCarrierPayment?'paid':(string)$invoiceSummary['status'];
        $search=mb_strtolower($trip['business_id'].' '.$trip['carrier_name'].' '.$trip['manager_name'].' '.\App\Service\RegistryManagerPresentation::name((string)$trip['manager_name']).' '.$executorLabel((string)$trip['legal_entity_code']),'UTF-8');
    ?>
    <details class="closing-trip" name="closing-trip" data-closing-trip data-trip-id="<?= (int)$trip['id'] ?>" data-business-id="<?= e((string)$trip['business_id']) ?>" data-state="<?= e($section) ?>" data-entity="<?= e((string)$trip['legal_entity_code']) ?>" data-actual-end="<?= e((string)($trip['actual_end_date']??'')) ?>" data-manager-id="<?= (int)($trip['manager_membership_id']??0) ?>" data-search="<?= e($search) ?>"<?= (int)$trip['id']===$selectedTrip?' open':'' ?>>
      <summary class="closing-trip-summary"><span class="closing-chevron" aria-hidden="true"></span>
        <span class="closing-identity"><b>Рейс #<?= e((string)$trip['business_id']) ?></b><small>Начало: <?= e(!empty($trip['actual_start_date'])?date('d.m.Y',strtotime((string)$trip['actual_start_date'])):'—') ?></small><small>Завершён <?= e($trip['actual_end_date']?date('d.m.Y',strtotime((string)$trip['actual_end_date'])):'—') ?></small></span>
        <span class="closing-carrier"><small>ПЕРЕВОЗЧИК</small><b><?= e((string)$trip['carrier_name']) ?></b><span>Водитель: <?= e(trim((string)($trip['driver_names']??''))?:'—') ?></span><span>Менеджер: <?= e((string)$trip['manager_name']) ?></span></span>
        <?php $customerSettlement=$trip['customer_settlement']??\App\Service\WasteTripClosingService::customerSettlementDisplay($trip);?>
        <span class="closing-executor"><small>ИСПОЛНИТЕЛЬ</small><b><?= e($executorLabel((string)$trip['legal_entity_code'])) ?></b><span class="closing-customer-settlement is-<?= e($customerSettlement['status']) ?>"><?= e($customerSettlement['text']) ?></span></span>
        <span class="closing-invoice-status" data-status="<?= e($invoiceDisplayStatus) ?>"><small>СЧЕТА</small><b><?= e($invoiceStatus) ?></b><span class="closing-invoice-detail"><?= e($invoiceDetail) ?></span><?php if($hasCarrierPayment&&$carrierDebt):?><span class="closing-carrier-debt"><?= e($carrierDebt) ?></span><?php endif;?></span>
        <span class="closing-progress"><small>ДОКУМЕНТЫ</small><b><?= $count ?> из <?= $total ?> получено</b><span class="closing-track" aria-label="<?= $count ?> из <?= $total ?> документов получено"><i style="width:<?= $percent ?>%;--closing-progress-color:<?= e($progressColor($percent)) ?>"></i></span></span>
        <span class="closing-requests"><small>ОБРАБОТАНО ЗАЯВОК</small><b><?= $processedCount ?> из <?= $requestCount ?> обработано</b><span class="closing-track closing-requests-track" aria-label="<?= $processedCount ?> из <?= $requestCount ?> заявок обработано"><i style="width:<?= $requestPercent ?>%"></i></span></span>
        <span class="closing-shared-issues"><small>ОШИБКИ</small><span class="closing-shared-issues-list"><?php if($missing):?><span><b>Нет документов:</b> <?= e(implode(', ',$missing)) ?></span><?php endif;?><?php foreach($tnIssueGroups as $reason=>$requestIds):?><span><b>ТН:</b> <?= e((string)$reason) ?><?php if($requestIds):?> · заявок: <?= count($requestIds) ?><?php endif;?></span><?php endforeach;?><?php if($tnUnprocessed&&!$tnIssueGroups):?><span><b>ТН:</b> обработано <?= $processedCount ?> из <?= $requestCount ?></span><?php endif;?><?php if($missingCards||!$cardUnits):?><span class="closing-optional-card">Диагностическая карта: <?= e($missingCards?implode(', ',array_map(static fn(array $unit):string=>trim((string)$unit['label'].' '.(string)$unit['plate']),$missingCards)):'не указана') ?> (необязательно)</span><?php endif;?><?php if(!$missing&&!$tnIssues&&!$tnUnprocessed&&!$missingCards&&$cardUnits):?><span class="closing-shared-issues-clear">Обязательные документы собраны</span><?php endif;?></span></span>
      </summary>
      <div class="closing-trip-body">
        <div class="closing-body-head">
          <div class="closing-trip-facts">
            <span><small>Компания рейса</small><b><?= e(match((string)($trip['legal_entity_code']??'')){'planex'=>'ПЛАНЭКС','mg_logistic'=>'МГ Логистика',default=>'—'}) ?></b></span>
            <span><small>Название перевозчика</small><b><?= e(trim((string)($trip['carrier_name']??''))?:'—') ?></b></span>
            <span><small>ФИО водителя</small><b><?= e(trim((string)($trip['driver_names']??''))?:'—') ?></b></span>
          </div>
          <?php if(\App\Service\WasteTripClosingService::REVIEW_EMAIL_ENABLED):?><span class="closing-note">Получатель письма: <?= e((string)$trip['recipient_email']) ?></span><?php endif;?>
        </div>
        <p class="closing-upload-feedback" role="alert" hidden></p>
        <fieldset class="closing-edit-fieldset">
        <div class="closing-doc-table">
          <?php foreach(\App\Service\WasteTripClosingService::SLOTS as $slot=>$label):$files=$bySlot[$slot]??[];
            $defaultCode=$slot==='contract'?'application':($slot==='act'?'upd':$slot);
          ?><div class="closing-doc-row">
            <div><strong><?= e($label) ?></strong><small>Можно добавить несколько файлов</small><?php if($slot==='invoice'&&$canCreateInvoice&&(int)($trip['carrier_id']??0)>0):?><span class="closing-invoice-actions"><?php if((int)$invoiceSummary['invoice_id']>0):?><button type="button" class="btn btn-secondary closing-invoice-control" data-invoice-edit data-invoice-id="<?= (int)$invoiceSummary['invoice_id'] ?>">Редактировать</button><?php else:?><button type="button" class="btn btn-secondary closing-invoice-control" data-invoice-open data-carrier-id="<?= (int)$trip['carrier_id'] ?>" data-business-id="<?= e((string)$trip['business_id']) ?>">Внести счёт перевозчика</button><button type="button" class="btn btn-secondary closing-invoice-control" data-invoice-choose data-trip-id="<?= (int)$trip['id'] ?>">Выбрать из ранее внесённых</button><?php endif;?></span><?php endif;?></div>
            <div class="closing-file-list"><?php if($files):foreach($files as $document):?><div class="closing-file-entry"><a data-document-preview target="_blank" rel="noopener noreferrer" href="<?= e(app_url('/company/logistics/closing-documents/'.(int)$trip['id'].'/documents/'.(int)$document['id'])) ?>"><?= e((string)$document['original_name']) ?></a><form class="closing-remove-form" method="post" action="<?= e(app_url('/company/logistics/closing-documents/'.(int)$trip['id'].'/documents/'.(int)$document['id'].'/remove')) ?>"><?= csrfField() ?><button class="closing-file-remove" type="submit" aria-label="Удалить файл <?= e((string)$document['original_name']) ?>">×</button></form></div><?php endforeach;else:?>Файл не получен<?php endif;?></div>
            <div class="closing-doc-actions"><form class="closing-upload-form" method="post" enctype="multipart/form-data" action="<?= e(app_url('/company/logistics/closing-documents/'.(int)$trip['id'].'/documents')) ?>">
              <?= csrfField() ?><input type="hidden" name="slot_code" value="<?= e($slot) ?>"><input type="hidden" name="requirement_code" value="<?= e($defaultCode) ?>">
              <label class="btn btn-secondary closing-upload">Добавить файлы<input type="file" name="document_files[]" accept=".pdf,.jpg,.jpeg,.png,.webp,.tif,.tiff,.doc,.docx,.xls,.xlsx" multiple required></label>
            </form></div>
          </div><?php endforeach;?>
          <?php if(!$cardUnits):?><div class="closing-doc-row"><div><strong>Диагностическая карта транспорта</strong><small>Документ ТС</small></div><div class="closing-file-list">Укажите транспорт в рейсе, чтобы проверить карту</div></div><?php else:foreach($missingCards as $unit):?>
          <div class="closing-doc-row"><div><strong>Диагностическая карта транспорта</strong><small><?= e((string)$unit['label']) ?> · <?= e(trim((string)$unit['brand'].' '.(string)$unit['plate'])) ?><?= !empty($unit['can_upload'])?' · Можно добавить несколько файлов':'' ?></small></div>
            <div class="closing-file-list"><?= !empty($unit['can_upload'])?'Файл не получен':'Нет доступа к загрузке карты этого ТС' ?></div>
            <?php if(!empty($unit['can_upload'])):?><div class="closing-doc-actions"><form class="closing-upload-form closing-card-form" method="post" enctype="multipart/form-data" action="<?= e(app_url('/company/logistics/closing-documents/'.(int)$trip['id'].'/diagnostic-card')) ?>"><?= csrfField() ?><input type="hidden" name="vehicle_unit_id" value="<?= (int)$unit['id'] ?>"><label class="btn btn-secondary closing-upload">Добавить файлы<input type="file" name="card_files[]" accept=".pdf,.jpg,.jpeg,.png,.webp,.tif,.tiff,.doc,.docx" multiple required></label></form></div><?php endif;?>
          </div><?php endforeach;endif;?>
          <?php foreach($extras as $extraTitle=>$files):?><div class="closing-doc-row"><div><strong><?= e($extraTitle) ?></strong><small>Другой документ</small></div><div class="closing-file-list"><?php foreach($files as $document):?><div class="closing-file-entry"><a data-document-preview target="_blank" rel="noopener noreferrer" href="<?= e(app_url('/company/logistics/closing-documents/'.(int)$trip['id'].'/documents/'.(int)$document['id'])) ?>"><?= e((string)$document['original_name']) ?></a><form class="closing-remove-form" method="post" action="<?= e(app_url('/company/logistics/closing-documents/'.(int)$trip['id'].'/documents/'.(int)$document['id'].'/remove')) ?>"><?= csrfField() ?><button class="closing-file-remove" type="submit" aria-label="Удалить файл <?= e((string)$document['original_name']) ?>">×</button></form></div><?php endforeach;?></div><form class="closing-upload-form" method="post" enctype="multipart/form-data" action="<?= e(app_url('/company/logistics/closing-documents/'.(int)$trip['id'].'/documents')) ?>"><?= csrfField() ?><input type="hidden" name="slot_code" value="extra"><input type="hidden" name="requirement_code" value="supporting"><input type="hidden" name="custom_title" value="<?= e($extraTitle) ?>"><label class="btn btn-secondary closing-upload">Добавить файлы<input type="file" name="document_files[]" accept=".pdf,.jpg,.jpeg,.png,.webp,.tif,.tiff,.doc,.docx,.xls,.xlsx" multiple required></label></form></div><?php endforeach;?>
        </div>
        <form class="closing-extra-upload" method="post" enctype="multipart/form-data" action="<?= e(app_url('/company/logistics/closing-documents/'.(int)$trip['id'].'/documents')) ?>">
          <?= csrfField() ?><input type="hidden" name="slot_code" value="extra"><input type="hidden" name="requirement_code" value="supporting">
          <label><span class="sr-only">Название другого документа</span><input class="field-input" type="text" name="custom_title" placeholder="Название другого документа" maxlength="255" required></label>
          <label class="btn btn-secondary closing-upload">Файлы<input type="file" name="document_files[]" accept=".pdf,.jpg,.jpeg,.png,.webp,.tif,.tiff,.doc,.docx,.xls,.xlsx" multiple required></label>
          <button type="submit" class="btn btn-secondary">+ Добавить другой документ</button>
        </form>
        </fieldset>
        <?php if(\App\Service\WasteTripClosingService::REVIEW_EMAIL_ENABLED):?><div class="closing-trip-actions"><button type="button" class="btn btn-secondary closing-submit" data-review-open data-trip-id="<?= (int)$trip['id'] ?>" data-business-id="<?= e((string)$trip['business_id']) ?>" data-recipient="<?= e((string)$trip['recipient_email']) ?>" data-version="<?= (int)$trip['review_version'] ?>" data-missing="<?= e(implode(', ',$missing)) ?>"<?= $trip['review_state']==='sending'||$trip['review_state']==='unknown'?' disabled':'' ?>><?= $trip['review_state']==='review'?'Передать повторно':'Передать на проверку' ?></button></div><?php endif;?>
        <?php if($trip['review_state']==='unknown'):?><form class="closing-unknown" method="post" action="<?= e(app_url('/company/logistics/closing-documents/'.(int)$trip['id'].'/review/confirm-not-sent')) ?>">
          <?= csrfField() ?><input type="hidden" name="version" value="<?= (int)$trip['review_version'] ?>">
          <p class="closing-feedback">Результат отправки неизвестен. Проверьте исходящие письма рабочего ящика.</p>
          <label><input type="checkbox" name="confirm_not_sent" value="1" required> Подтверждаю, что письмо не было отправлено</label>
          <button type="submit" class="btn btn-secondary">Разрешить повторную отправку</button>
        </form><?php endif;?>
      </div>
    </details>
    <?php endforeach;?>
  </section></div>
  <dialog class="closing-doc-dialog lab-doc-dialog" data-closing-doc-dialog aria-labelledby="closing-doc-title"><div class="closing-dialog-head lab-dialog-head"><strong id="closing-doc-title">Документы рейса</strong><button type="button" data-closing-doc-close aria-label="Закрыть">×</button></div><div class="closing-dialog-body lab-dialog-body" data-closing-doc-body></div><div class="closing-dialog-foot lab-dialog-foot"><span></span><button type="button" class="btn btn-secondary" data-closing-doc-close>Закрыть</button></div></dialog>
  <?php endif;?>
</div>
<?php if(\App\Service\WasteTripClosingService::REVIEW_EMAIL_ENABLED):?><dialog class="closing-review-dialog" data-review-dialog aria-labelledby="closing-review-title">
  <form method="post" data-review-form><div class="closing-review-head"><strong id="closing-review-title">Передать на проверку</strong><button type="button" data-review-close aria-label="Закрыть">×</button></div>
    <div class="closing-review-body"><p data-review-missing></p><div class="closing-review-mail"><strong>Письмо при передаче</strong><span data-review-recipient></span><span data-review-subject></span><span>В письме: сводка комплекта и защищённая ссылка на архив файлов рейса.</span><span class="closing-review-archive">Архив документов рейса <small>Хранится 30 дней</small></span></div></div>
    <?= csrfField() ?><input type="hidden" name="version" data-review-version>
    <div class="closing-review-foot"><button type="button" class="btn btn-secondary" data-review-close>Отмена</button><button type="submit" class="btn btn-primary">Передать на проверку</button></div>
  </form>
</dialog><?php endif;?>
<?php if($canCreateInvoice):?><dialog class="closing-invoice-dialog" data-invoice-dialog aria-labelledby="closing-invoice-title"><div class="modal modal-lg"><div class="modal-head"><span class="modal-title" id="closing-invoice-title">Создать счёт</span><button type="button" class="modal-close" data-invoice-close aria-label="Закрыть">&times;</button></div><?php $invoice=['direction'=>'INCOMING'];$isEdit=false;$returnTo='/company/logistics/closing-documents';require base_path('app/View/partials/company_invoice_create_form.php'); ?></div></dialog><?php endif;?>
<?php if($canCreateInvoice):?><dialog class="closing-invoice-picker" data-invoice-picker aria-labelledby="closing-picker-title"><form method="post" data-invoice-picker-form><?= csrfField() ?><div class="closing-picker-head"><strong id="closing-picker-title">Выбрать счёт перевозчика</strong><button type="button" class="modal-close" data-invoice-picker-close aria-label="Закрыть">×</button></div><div class="closing-picker-body"><p>Ранее внесённые счета выбранного перевозчика</p><fieldset><legend>Счета перевозчика</legend><div data-invoice-picker-options></div></fieldset><p class="closing-picker-error" role="alert" hidden></p></div><div class="closing-picker-foot"><button type="button" class="btn btn-secondary" data-invoice-picker-close>Отмена</button><button type="submit" class="btn btn-primary">Связать с рейсом</button></div></form></dialog><dialog class="closing-invoice-dialog" data-invoice-edit-dialog aria-label="Редактировать счёт перевозчика"><div class="modal modal-lg"><div class="modal-head"><span class="modal-title">Редактировать счёт перевозчика</span><button type="button" class="modal-close" data-invoice-edit-close aria-label="Закрыть">×</button></div><div data-invoice-edit-body></div></div></dialog><?php endif;?>
<script src="<?= e(app_url('/assets/js/waste-trip-closing-registry.js')) ?>?v=<?= filemtime(base_path('public/assets/js/waste-trip-closing-registry.js')) ?>" defer></script>
<script src="<?= e(app_url('/assets/js/table-reference-select.js')) ?>?v=<?= filemtime(base_path('public/assets/js/table-reference-select.js')) ?>" defer></script>
<script src="<?= e(app_url('/assets/js/registry-components.js')) ?>?v=<?= filemtime(base_path('public/assets/js/registry-components.js')) ?>" defer></script>
