<?php
$selectedDocumentType='';
foreach ($templates as $template) {
    if ((int)($template['id'] ?? 0)===(int)($old['template_id'] ?? 0)) {
        $selectedDocumentType=(string)($template['document_type'] ?? '');
        break;
    }
}
$mode=(string)($old['generator_mode'] ?? 'carrier');
$isClientPackage=in_array($mode,['client_package','client_package_upd'],true);
$isUpdPackage=$mode==='client_package_upd';
$showContract=$selectedDocumentType==='vehicle_lease_crew';
$showWaybill=$selectedDocumentType==='waybill_driver_assignment';
?>
<style>
.docgen-form-actions{justify-content:flex-end}.docgen-preview-modal{width:min(760px,calc(100vw - 32px));max-width:760px}.docgen-error-list{margin:0;padding-left:20px}.docgen-mode-note{margin-top:6px}.docgen-trip-meta{display:block;margin-top:3px;color:var(--text-muted);font-size:11px}
</style>
<div class="page-head">
    <div class="page-head-left">
        <h1 class="page-title">Создать документ</h1>
        <div class="page-summary"><span>Рейс ERP → проверка → редактируемый DOCX</span></div>
    </div>
    <div class="page-head-actions">
        <a class="btn btn-ghost" href="<?= app_url('/company/document-generator/history') ?>">История</a>
        <?php if (($_SESSION['role_code'] ?? '') === 'company_owner'): ?><a class="btn btn-ghost" href="<?= app_url('/company/document-generator/templates') ?>">Шаблоны</a><?php endif; ?>
    </div>
</div>
<?php if ($formError): ?><div class="notice danger"><?= e($formError) ?></div><?php endif; ?>
<?php if (!$company): ?>
<div class="notice warn">Компания недоступна.</div>
<?php elseif ($trips === []): ?>
<div class="notice warn">Нет доступных рейсов, по которым можно сформировать документы.</div>
<?php elseif ($templates === [] && !$isClientPackage): ?>
<div class="notice warn">Нет активных шаблонов документов перевозчику.</div>
<?php else: ?>
<form id="document-generator-form" method="post" action="<?= app_url('/company/document-generator/preview') ?>" data-docgen-form novalidate>
<input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
<div class="panel mb-3">
  <div class="panel-head"><h3 class="panel-head-title">Что сформировать</h3></div>
  <div class="panel-body">
    <div class="field">
      <label class="field-label" for="docgen-mode">Документы для</label>
      <select id="docgen-mode" name="generator_mode" class="field-select" data-docgen-mode>
        <option value="carrier" <?= $mode==='carrier'?'selected':'' ?>>Перевозчика</option>
        <?php if ($canGenerateClientPackage): ?><option value="client_package" <?= $mode==='client_package'?'selected':'' ?>>Клиента — договор + счёт + акт</option><?php endif; ?>
        <?php if ($canGenerateClientPackage): ?><option value="client_package_upd" <?= $isUpdPackage?'selected':'' ?>>Клиента — договор + счёт + УПД (статус 2)</option><?php endif; ?>
      </select>
      <div class="text-muted docgen-mode-note">Данные исполнителя и заявки ФЭО берутся из выбранного рейса и вручную не вводятся.</div>
    </div>
  </div>
</div>

<div data-docgen-mode-section="carrier" class="<?= $mode==='carrier'?'':'is-hidden' ?>">
  <div class="panel mb-3">
    <div class="panel-head"><h3 class="panel-head-title">Основание документа</h3></div>
    <div class="panel-body">
      <div class="form-grid-2">
        <div class="field">
          <label class="field-label" for="docgen-template">Шаблон</label>
          <select id="docgen-template" name="template_id" class="field-select" data-docgen-template>
            <?php foreach ($templates as $template): ?><option value="<?= (int)$template['id'] ?>" data-document-type="<?= e((string)($template['document_type'] ?? '')) ?>" <?= (int)$old['template_id']===(int)$template['id']?'selected':'' ?>><?= e((string)$template['name']) ?> · v<?= (int)($template['version_no'] ?? 0) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label class="field-label" for="docgen-trip">Рейс</label>
          <select id="docgen-trip" name="trip_id" class="field-select" data-docgen-carrier-trip>
            <option value="">— выбрать рейс —</option>
            <?php foreach ($trips as $trip): ?>
              <?php $tripLabel='#'.(int)$trip['business_id'].' · '.(string)($trip['client_name'] ?? '—').' · '.(string)($trip['executor_contractor_name'] ?? 'исполнитель не назначен').' · '.(string)($trip['_document_period'] ?? ''); ?>
              <option value="<?= (int)$trip['id'] ?>" <?= (int)$old['trip_id']===(int)$trip['id']?'selected':'' ?>><?= e($tripLabel) ?></option>
            <?php endforeach; ?>
          </select>
          <span class="docgen-trip-meta">Из рейса будут взяты исполнитель, водитель, ТС и все связанные заявки ФЭО.</span>
        </div>
      </div>
    </div>
  </div>
  <div class="panel mb-3 <?= $showContract?'':'is-hidden' ?>" data-docgen-section="vehicle_lease_crew">
    <div class="panel-head"><h3 class="panel-head-title">Параметры договора перевозчику</h3></div>
    <div class="panel-body"><div class="form-grid-2">
      <div class="field"><label class="field-label">Дата договора</label><input type="date" name="contract_date" value="<?= e($old['contract_date']) ?>" class="field-input"></div>
      <div class="field"><label class="field-label">Дата начала аренды</label><input type="date" name="start_date" value="<?= e($old['start_date']) ?>" class="field-input"></div>
      <div class="field"><label class="field-label">Время подачи <span class="text-muted">(необязательно)</span></label><input type="time" name="start_time" value="<?= e($old['start_time']) ?>" class="field-input"></div>
      <div class="field"><label class="field-label">Плановая дата завершения</label><input type="date" name="finish_date" value="<?= e($old['finish_date']) ?>" class="field-input"></div>
      <div class="field"><label class="field-label">Стоимость, ₽</label><input type="text" inputmode="decimal" name="price" value="<?= e($old['price']) ?>" class="field-input"></div>
      <div class="field"><label class="field-label">НДС</label><input type="text" name="vat_mode" value="<?= e($old['vat_mode']) ?>" class="field-input" placeholder="без НДС"></div>
    </div><div class="text-muted mt-2">Номер договора перевозчика: П-{ID рейса}. Файл: «Рейс {ID рейса} {фамилия водителя}.docx».</div></div>
  </div>
  <div class="panel mb-3 <?= $showWaybill?'':'is-hidden' ?>" data-docgen-section="waybill_driver_assignment">
    <div class="panel-head"><h3 class="panel-head-title">Параметры путевого листа</h3></div>
    <div class="panel-body"><div class="form-grid-2">
      <div class="field"><label class="field-label">Номер путевого листа</label><input type="text" name="waybill_number" value="<?= e($old['waybill_number']) ?>" class="field-input"></div>
      <div class="field"><label class="field-label">Дата путевого листа</label><input type="date" name="waybill_date" value="<?= e($old['waybill_date']) ?>" class="field-input"></div>
    </div></div>
  </div>
</div>

<?php if ($canGenerateClientPackage): ?>
<div data-docgen-mode-section="client_package" class="<?= $isClientPackage?'':'is-hidden' ?>">
  <div class="panel mb-3">
    <div class="panel-head"><h3 class="panel-head-title">Комплект документов клиенту</h3></div>
    <div class="panel-body">
      <div class="form-grid-2">
        <div class="field">
          <label class="field-label" for="docgen-client">Клиент</label>
          <select id="docgen-client" name="client_id" class="field-select" data-docgen-client>
            <option value="">— выбрать клиента —</option>
            <?php foreach ($clients as $client): ?><option value="<?= (int)$client['id'] ?>" <?= (int)$old['client_id']===(int)$client['id']?'selected':'' ?>><?= e((string)$client['name']) ?><?= trim((string)($client['inn'] ?? ''))!==''?' · ИНН '.e((string)$client['inn']):'' ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label class="field-label" for="docgen-client-trip-search">Рейс</label>
          <input id="docgen-client-trip-search" type="search" class="field-input" list="docgen-client-trip-options" placeholder="Начните вводить номер, клиента или дату" autocomplete="off" data-docgen-client-trip-search>
          <input type="hidden" name="trip_id" value="<?= (int)$old['trip_id']>0?(int)$old['trip_id']:'' ?>" data-docgen-client-trip>
          <datalist id="docgen-client-trip-options" data-docgen-client-trip-options>
            <?php foreach ($trips as $trip): ?>
              <?php $amount=(string)($trip['_customer_amount'] ?? ''); $label='#'.(int)$trip['business_id'].' · '.(string)($trip['client_name'] ?? '—').' · '.(string)($trip['_document_period'] ?? '').($amount!==''?' · '.number_format((float)$amount,2,',',' ').' ₽':''); ?>
              <option value="<?= e($label) ?>" data-trip-id="<?= (int)$trip['id'] ?>" data-client-id="<?= (int)$trip['client_id'] ?>" data-contract-number="<?= e((string)($trip['_contract_number'] ?? '')) ?>" data-contract-date="<?= e((string)($trip['_package_dates']['contract_date'] ?? '')) ?>" data-invoice-date="<?= e((string)($trip['_package_dates']['invoice_date'] ?? '')) ?>" data-act-date="<?= e((string)($trip['_package_dates']['act_date'] ?? '')) ?>"></option>
            <?php endforeach; ?>
          </datalist>
        </div>
        <div class="field"><label class="field-label" for="docgen-client-contract-number">Номер договора</label><input id="docgen-client-contract-number" type="text" name="client_contract_number" value="<?= e($old['client_contract_number']) ?>" class="field-input" maxlength="50" autocomplete="off" placeholder="По умолчанию ГГ-ID рейса"></div>
        <div class="field"><label class="field-label" for="docgen-client-contract-date">Дата договора</label><input id="docgen-client-contract-date" type="date" name="client_contract_date" value="<?= e($old['client_contract_date']) ?>" class="field-input"></div>
        <div class="field"><label class="field-label" for="docgen-client-invoice-date">Дата счёта</label><input id="docgen-client-invoice-date" type="date" name="client_invoice_date" value="<?= e($old['client_invoice_date']) ?>" class="field-input"></div>
        <div class="field"><label class="field-label" for="docgen-client-act-date">Дата <span data-docgen-transfer-label><?= $isUpdPackage?'УПД':'акта' ?></span></label><input id="docgen-client-act-date" type="date" name="client_act_date" value="<?= e($old['client_act_date']) ?>" class="field-input"></div>
      </div>
      <div class="text-muted mt-2">Номер договора предлагается по рейсу и может быть изменён. Дата договора — начало рейса; даты счёта и УПД или акта — завершение рейса. Даты можно изменить вручную.</div>
      <div class="notice info mt-3">Одной операцией будут сформированы три отдельных DOCX: <strong>Договор субаренды транспортного средства с экипажем</strong>, <strong>Счёт</strong> и <strong data-docgen-transfer-name><?= $isUpdPackage?'УПД (статус 2)':'Акт' ?></strong>. Номера счёта и УПД или акта формируются как <strong>ГГ-ID рейса</strong>, например № 26-144. УПД со статусом 2 подтверждает передачу услуг и не является счётом-фактурой.</div>
    </div>
  </div>
</div>
<?php endif; ?>

<div data-docgen-check-result aria-live="polite" aria-atomic="true"></div>
<div class="form-actions docgen-form-actions"><button type="submit" class="btn btn-primary" formaction="<?= app_url('/company/document-generator/preview') ?>" data-docgen-check>Проверить</button></div>
</form>

<?php if (is_array($preview)): ?>
<?php $packagePreview=$isClientPackage; ?>
<div class="modal-overlay" id="document-generator-preview-modal" role="dialog" aria-modal="true" data-close-on-overlay="1" data-close-on-escape="1">
 <div class="modal modal-lg docgen-preview-modal">
  <div class="modal-head"><span class="modal-title"><?= $packagePreview?'Проверка комплекта клиенту':'Проверка документа' ?></span><button type="button" class="modal-close" onclick="closeModal('document-generator-preview-modal')">✕</button></div>
  <div class="modal-body">
   <?php if ($preview['ok'] ?? false): ?>
    <div class="notice success mb-3"><?= $packagePreview?'Проверка пройдена. Комплект можно сформировать.':'Проверка пройдена. Документ можно сформировать.' ?></div>
    <?php if (!empty($preview['warnings'])): ?><div class="notice warn mb-3"><strong>Внешний источник данных:</strong><ul class="docgen-error-list mt-2"><?php foreach ($preview['warnings'] as $warning): ?><li><?= e((string)$warning) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <div class="kv">
      <?php if ($packagePreview): ?>
        <div class="kv-row"><span class="kv-key">Комплект</span><span class="kv-value">Договор + Счёт + <?= $isUpdPackage?'УПД (статус 2)':'Акт' ?></span></div>
        <div class="kv-row"><span class="kv-key">Номер счёта</span><span class="kv-value">№ <?= e((string)($preview['package_number'] ?? '—')) ?></span></div>
        <div class="kv-row"><span class="kv-key">Номер договора</span><span class="kv-value">№ <?= e((string)($preview['contract_number'] ?? '—')) ?></span></div>
        <div class="kv-row"><span class="kv-key">Номер <?= $isUpdPackage?'УПД':'акта' ?></span><span class="kv-value">№ <?= e((string)($preview['transfer_number'] ?? '—')) ?></span></div>
        <div class="kv-row"><span class="kv-key">Клиент</span><span class="kv-value"><?= e((string)($preview['client']['name'] ?? '—')) ?></span></div>
        <div class="kv-row"><span class="kv-key">Рейс</span><span class="kv-value">#<?= (int)($preview['trip']['business_id'] ?? 0) ?></span></div>
        <div class="kv-row"><span class="kv-key">Даты документов</span><span class="kv-value">Договор <?= e((string)($preview['package_dates'][$isUpdPackage?'client_upd_contract':'client_sublease_crew'] ?? '')) ?> · Счёт <?= e((string)($preview['package_dates'][$isUpdPackage?'client_upd_invoice':'client_invoice'] ?? '')) ?> · <?= $isUpdPackage?'УПД':'Акт' ?> <?= e((string)($preview['package_dates'][$isUpdPackage?'client_upd_status2':'client_act'] ?? '')) ?></span></div>
        <div class="kv-row"><span class="kv-key">Сумма</span><span class="kv-value"><?= e((string)($preview['package_amount_formatted'] ?? '0')) ?> ₽ · Без НДС</span></div>
        <?php if ($preview['package_exists'] ?? false): ?><div class="notice warn mt-3"><strong>По этому рейсу уже есть документы комплекта.</strong><br>При подтверждении договор, счёт и <?= $isUpdPackage?'УПД':'акт' ?> будут сформированы заново и заменят прежний комплект этого вида.<?php if (($preview['package_number'] ?? '') !== ($preview['transfer_number'] ?? '')): ?> Исторический электронный счёт и его оплаты сохранятся.<?php endif; ?></div><?php endif; ?>
      <?php else: ?>
        <div class="kv-row"><span class="kv-key">Шаблон</span><span class="kv-value"><?= e((string)($preview['template']['template_name'] ?? '')) ?> · v<?= (int)($preview['template']['version_no'] ?? 0) ?></span></div>
        <div class="kv-row"><span class="kv-key">Рейс</span><span class="kv-value">#<?= (int)($preview['snapshot']['trip']['business_id'] ?? 0) ?></span></div>
        <?php if ($preview['contract_exists'] ?? false): ?><div class="notice warn mt-3"><strong>Договор № <?= e((string)($preview['snapshot']['document']['number'] ?? '')) ?> уже сформирован.</strong><br>При подтверждении договор будет сформирован заново и заменит прежний документ.</div><?php endif; ?>
      <?php endif; ?>
      <div class="kv-row"><span class="kv-key">Исполнитель</span><span class="kv-value"><?= e((string)($preview['snapshot']['executor']['name'] ?? '—')) ?></span></div>
      <div class="kv-row"><span class="kv-key">Водитель</span><span class="kv-value"><?= e((string)($preview['snapshot']['driver']['full_name'] ?? '—')) ?></span></div>
      <div class="kv-row"><span class="kv-key">Заявок</span><span class="kv-value"><?= (int)($preview['found_count'] ?? 0) ?></span></div>
      <div class="kv-row"><span class="kv-key">Общая масса</span><span class="kv-value"><?= e((string)($preview['total_mass_kg_formatted'] ?? '0')) ?> кг</span></div>
      <?php if (!$packagePreview): ?><?php $params=$preview['snapshot']['parameters'] ?? []; ?><div class="kv-row"><span class="kv-key">Параметры</span><span class="kv-value"><?php if (($preview['template']['document_type'] ?? '')==='vehicle_lease_crew'): ?><?= e((string)($params['start_date'] ?? '—')) ?> → <?= e((string)($params['finish_date'] ?? '—')) ?> · <?= e((string)($params['price'] ?? '—')) ?> ₽<?php else: ?>№ <?= e((string)($params['waybill_number'] ?? '—')) ?> от <?= e((string)($params['waybill_date'] ?? '—')) ?><?php endif; ?></span></div><?php endif; ?>
    </div>
   <?php else: ?>
    <div class="notice danger mb-3"><strong><?= $packagePreview?'Комплект пока нельзя сформировать.':'Документ пока нельзя сформировать.' ?></strong></div>
    <?php if (!empty($preview['errors'])): ?><ul class="docgen-error-list"><?php foreach ($preview['errors'] as $error): ?><li><?= e((string)$error) ?></li><?php endforeach; ?></ul><?php endif; ?>
   <?php endif; ?>
  </div>
  <div class="modal-foot is-spaced"><div></div><div class="modal-foot-actions"><button type="button" class="btn btn-ghost" onclick="closeModal('document-generator-preview-modal')"><?= ($preview['ok'] ?? false)?'Вернуться к редактированию':'Исправить данные' ?></button><?php if ($preview['ok'] ?? false): ?><?php $overwritePackage=$packagePreview && ($preview['package_exists'] ?? false);$overwriteContract=!$packagePreview && ($preview['contract_exists'] ?? false); ?><button type="submit" form="document-generator-form" class="btn btn-primary" formaction="<?= app_url('/company/document-generator/generate') ?>" data-docgen-generate<?= ($overwritePackage||$overwriteContract)?' data-docgen-overwrite name="overwrite_existing" value="1" data-docgen-overwrite-kind="'.($overwriteContract?'contract':'package').'"':'' ?>><?= $overwritePackage?'Перезаписать комплект':($overwriteContract?'Перезаписать договор':($packagePreview?'Сформировать комплект':'Сформировать DOCX')) ?></button><?php endif; ?></div></div>
 </div>
</div>
<?php endif; ?>
<script>
(function(){
 const form=document.querySelector('[data-docgen-form]'),mode=document.querySelector('[data-docgen-mode]'),template=document.querySelector('[data-docgen-template]'),result=document.querySelector('[data-docgen-check-result]'),check=document.querySelector('[data-docgen-check]'),client=document.querySelector('[data-docgen-client]'),clientTrip=document.querySelector('[data-docgen-client-trip]'),tripSearch=document.querySelector('[data-docgen-client-trip-search]'),tripList=document.querySelector('[data-docgen-client-trip-options]');
 if(!form||!mode||!result||!check)return;let checking=false;
 const control=(n)=>Array.from(form.elements).find((x)=>x.name===n&&!x.disabled);const field=(n)=>control(n)?.closest('.field')||null;
 const clear=()=>{form.querySelectorAll('.field.is-error').forEach(n=>n.classList.remove('is-error'));form.querySelectorAll('[aria-invalid=true]').forEach(n=>n.removeAttribute('aria-invalid'));};
 const mark=(n)=>{const c=control(n),f=field(n);if(f)f.classList.add('is-error');if(c)c.setAttribute('aria-invalid','true');};
 const currentType=()=>template?.options[template.selectedIndex]?.dataset.documentType||'';
 const syncMode=()=>{const m=mode.value,clientMode=m==='client_package'||m==='client_package_upd';form.querySelectorAll('[data-docgen-mode-section]').forEach(s=>{const active=s.dataset.docgenModeSection===(clientMode?'client_package':m);s.classList.toggle('is-hidden',!active);s.querySelectorAll('input,select,textarea').forEach(n=>n.disabled=!active);});form.querySelectorAll('[data-docgen-transfer-label]').forEach(n=>n.textContent=m==='client_package_upd'?'УПД':'акта');form.querySelectorAll('[data-docgen-transfer-name]').forEach(n=>n.textContent=m==='client_package_upd'?'УПД (статус 2)':'Акт');syncType();filterTrips();};
 const syncType=()=>{if(mode.value!=='carrier')return;const t=currentType();form.querySelectorAll('[data-docgen-section]').forEach(s=>{const active=s.dataset.docgenSection===t;s.classList.toggle('is-hidden',!active);s.querySelectorAll('input,select,textarea').forEach(n=>n.disabled=!active);});};
 const tripOptions=tripList?Array.from(tripList.options):[];
 const filterTrips=()=>{if(!tripList||!clientTrip)return;const cid=String(client?.value||'');const matches=tripOptions.filter(o=>cid===''||o.dataset.clientId===cid);tripList.replaceChildren(...matches.map(o=>o.cloneNode(true)));const selected=matches.find(o=>o.dataset.tripId===clientTrip.value);if(clientTrip.value&&!selected){clientTrip.value='';if(tripSearch)tripSearch.value='';fillPackageDates();}else if(selected&&tripSearch&&!tripSearch.value){tripSearch.value=selected.value;}};
 const fillPackageDates=()=>{const option=tripOptions.find(o=>o.dataset.tripId===clientTrip?.value);for(const [name,key] of [['client_contract_date','contractDate'],['client_invoice_date','invoiceDate'],['client_act_date','actDate'],['client_contract_number','contractNumber']]){const input=control(name);if(input)input.value=option?.dataset[key]||'';}};
 const selectTrip=()=>{if(!clientTrip||!tripSearch||!tripList)return;const matches=Array.from(tripList.options).filter(o=>o.value===tripSearch.value.trim());clientTrip.value=matches.length===1?matches[0].dataset.tripId:'';fillPackageDates();};
 const moveTripSuggestion=(direction)=>{if(!tripSearch||!tripList)return false;const query=String(tripSearch.dataset.query??tripSearch.value).trim().toLocaleLowerCase('ru');const matches=Array.from(tripList.options).filter(o=>o.value.toLocaleLowerCase('ru').includes(query));if(!matches.length)return false;const current=matches.findIndex(o=>o.value===tripSearch.value),next=(current+direction+matches.length)%matches.length;tripSearch.value=matches[next].value;selectTrip();clear();resetResult();return true;};
 const errors=()=>{clear();const out=[],add=(n,m)=>{if(!String(control(n)?.value||'').trim()){out.push(m);mark(n);}};if(mode.value==='client_package'||mode.value==='client_package_upd'){add('client_id','Не выбран клиент.');add('trip_id','Не выбран рейс.');if(!clientTrip?.value&&tripSearch)tripSearch.setAttribute('aria-invalid','true');add('client_contract_number','Не указан номер договора.');add('client_contract_date','Не указана дата договора.');add('client_invoice_date','Не указана дата счёта.');add('client_act_date',mode.value==='client_package_upd'?'Не указана дата УПД.':'Не указана дата акта.');}else{add('template_id','Не выбран шаблон.');add('trip_id','Не выбран рейс.');if(currentType()==='vehicle_lease_crew'){add('contract_date','Не указана дата договора.');add('start_date','Не указана дата начала.');add('finish_date','Не указана дата завершения.');add('price','Не указана стоимость.');add('vat_mode','Не указан режим НДС.');}else if(currentType()==='waybill_driver_assignment'){add('waybill_number','Не указан номер путевого листа.');add('waybill_date','Не указана дата путевого листа.');}}return out;};
 const notice=(msgs,title='Проверка не пройдена')=>{const n=document.createElement('div');n.className='notice danger';const b=document.createElement('strong');b.textContent=title;n.appendChild(b);if(msgs.length){const ul=document.createElement('ul');ul.className='docgen-error-list mt-2';msgs.forEach(x=>{const li=document.createElement('li');li.textContent=x;ul.appendChild(li)});n.appendChild(ul)}result.replaceChildren(n);result.dataset.docgenState='error';result.scrollIntoView({behavior:'smooth',block:'nearest'});};
 const setBusy=(v)=>{checking=v;check.disabled=v;check.setAttribute('aria-busy',v?'true':'false');check.textContent=v?'Проверяем…':'Проверить';if(v)result.dataset.docgenState='checking';};
 const run=async()=>{if(checking)return;const e=errors();if(e.length){notice(e);return}setBusy(true);try{const r=await fetch(check.formAction||form.action,{method:'POST',body:new FormData(form),credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}});if(!r.ok)throw new Error('HTTP '+r.status);const doc=new DOMParser().parseFromString(await r.text(),'text/html'),modal=doc.querySelector('#document-generator-preview-modal');if(!modal)throw new Error('preview missing');const serverErrors=Array.from(modal.querySelectorAll('.docgen-error-list li')).map(x=>x.textContent.trim()).filter(Boolean);if(serverErrors.length||modal.querySelector('.notice.danger')){notice(serverErrors.length?serverErrors:['Документ пока нельзя сформировать.']);return}const body=modal.querySelector('.modal-body'),generate=modal.querySelector('[data-docgen-generate]');if(!body||!generate)throw new Error('preview incomplete');const panel=document.createElement('div');panel.className='panel';panel.innerHTML='<div class="panel-head"><h3 class="panel-head-title">Результат проверки</h3></div>';const pb=document.createElement('div');pb.className='panel-body';Array.from(body.childNodes).forEach(n=>pb.appendChild(n.cloneNode(true)));const a=document.createElement('div');a.className='form-actions';a.appendChild(generate.cloneNode(true));pb.appendChild(a);panel.appendChild(pb);result.replaceChildren(panel);result.dataset.docgenState='success';result.scrollIntoView({behavior:'smooth',block:'nearest'});}catch(err){console.error('Document generator preview failed',err);notice([],'Не удалось выполнить проверку');}finally{setBusy(false)}};
 const resetResult=()=>{if(result.childNodes.length)result.replaceChildren();result.dataset.docgenState='';};form.addEventListener('submit',e=>{if(e.submitter?.matches('[data-docgen-overwrite]')){const message=e.submitter.dataset.docgenOverwriteKind==='contract'?'Договор уже был создан. Перезаписать договор?':'Комплект уже был создан. Перезаписать договор, счёт и '+(mode.value==='client_package_upd'?'УПД':'акт')+'?';if(!confirm(message)){e.preventDefault();return}}if(!e.submitter?.matches('[data-docgen-check]'))return;e.preventDefault();run();});form.addEventListener('input',e=>{e.target?.closest?.('.field')?.classList.remove('is-error');e.target?.removeAttribute?.('aria-invalid');resetResult();});mode.addEventListener('change',()=>{syncMode();clear();resetResult();});template?.addEventListener('change',()=>{syncType();clear();resetResult();});client?.addEventListener('change',()=>{if(clientTrip)clientTrip.value='';if(tripSearch){tripSearch.value='';tripSearch.dataset.query='';}filterTrips();fillPackageDates();resetResult();});tripSearch?.addEventListener('input',()=>{tripSearch.dataset.query=tripSearch.value;selectTrip();resetResult();});tripSearch?.addEventListener('keydown',e=>{if((e.key==='ArrowDown'||e.key==='ArrowUp')&&moveTripSuggestion(e.key==='ArrowDown'?1:-1))e.preventDefault();else if(e.key==='Enter'&&clientTrip?.value)e.preventDefault();});syncMode();
 <?php if (is_array($preview)): ?>if(typeof openModal==='function')openModal('document-generator-preview-modal');<?php endif; ?>
})();
</script>
<?php endif; ?>
