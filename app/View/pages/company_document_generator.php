<?php
$selectedDocumentType='';
foreach ($templates as $template) {
    if ((int)($template['id'] ?? 0)===(int)($old['template_id'] ?? 0)) {
        $selectedDocumentType=(string)($template['document_type'] ?? '');
        break;
    }
}
$mode=(string)($old['generator_mode'] ?? 'carrier');
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
<?php elseif ($templates === [] && $mode !== 'client_package'): ?>
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
    </div><div class="text-muted mt-2">Номер договора перевозчика присваивается по действующему автоматическому правилу.</div></div>
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
<div data-docgen-mode-section="client_package" class="<?= $mode==='client_package'?'':'is-hidden' ?>">
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
          <label class="field-label" for="docgen-client-trip">Рейс</label>
          <select id="docgen-client-trip" name="trip_id" class="field-select" data-docgen-client-trip>
            <option value="">— выбрать рейс —</option>
            <?php foreach ($trips as $trip): ?>
              <?php $amount=(string)($trip['_customer_amount'] ?? ''); $label='#'.(int)$trip['business_id'].' · '.(string)($trip['client_name'] ?? '—').' · '.(string)($trip['_document_period'] ?? '').($amount!==''?' · '.number_format((float)$amount,2,',',' ').' ₽':''); ?>
              <option value="<?= (int)$trip['id'] ?>" data-client-id="<?= (int)$trip['client_id'] ?>" <?= (int)$old['trip_id']===(int)$trip['id']?'selected':'' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field"><label class="field-label" for="docgen-package-date">Дата документов</label><input id="docgen-package-date" type="date" name="package_date" value="<?= e($old['package_date']) ?>" class="field-input"></div>
      </div>
      <div class="notice info mt-3">Одной операцией будут сформированы три отдельных DOCX: <strong>Договор субаренды транспортного средства с экипажем</strong>, <strong>Счёт</strong> и <strong>Акт</strong>. Номер комплекта формируется как <strong>ГГ-ID рейса</strong>, например № 26-144.</div>
    </div>
  </div>
</div>
<?php endif; ?>

<div data-docgen-check-result aria-live="polite" aria-atomic="true"></div>
<div class="form-actions docgen-form-actions"><button type="submit" class="btn btn-primary" formaction="<?= app_url('/company/document-generator/preview') ?>" data-docgen-check>Проверить</button></div>
</form>

<?php if (is_array($preview)): ?>
<?php $packagePreview=$old['generator_mode']==='client_package'; ?>
<div class="modal-overlay" id="document-generator-preview-modal" role="dialog" aria-modal="true" data-close-on-overlay="1" data-close-on-escape="1">
 <div class="modal modal-lg docgen-preview-modal">
  <div class="modal-head"><span class="modal-title"><?= $packagePreview?'Проверка комплекта клиенту':'Проверка документа' ?></span><button type="button" class="modal-close" onclick="closeModal('document-generator-preview-modal')">✕</button></div>
  <div class="modal-body">
   <?php if ($preview['ok'] ?? false): ?>
    <div class="notice success mb-3"><?= $packagePreview?'Проверка пройдена. Комплект можно сформировать.':'Проверка пройдена. Документ можно сформировать.' ?></div>
    <?php if (!empty($preview['warnings'])): ?><div class="notice warn mb-3"><strong>Внешний источник данных:</strong><ul class="docgen-error-list mt-2"><?php foreach ($preview['warnings'] as $warning): ?><li><?= e((string)$warning) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <div class="kv">
      <?php if ($packagePreview): ?>
        <div class="kv-row"><span class="kv-key">Комплект</span><span class="kv-value">Договор + Счёт + Акт</span></div>
        <div class="kv-row"><span class="kv-key">Номер</span><span class="kv-value">№ <?= e((string)($preview['package_number'] ?? '—')) ?></span></div>
        <div class="kv-row"><span class="kv-key">Клиент</span><span class="kv-value"><?= e((string)($preview['client']['name'] ?? '—')) ?></span></div>
        <div class="kv-row"><span class="kv-key">Рейс</span><span class="kv-value">#<?= (int)($preview['trip']['business_id'] ?? 0) ?></span></div>
        <div class="kv-row"><span class="kv-key">Сумма</span><span class="kv-value"><?= e((string)($preview['package_amount_formatted'] ?? '0')) ?> ₽ · Без НДС</span></div>
      <?php else: ?>
        <div class="kv-row"><span class="kv-key">Шаблон</span><span class="kv-value"><?= e((string)($preview['template']['template_name'] ?? '')) ?> · v<?= (int)($preview['template']['version_no'] ?? 0) ?></span></div>
        <div class="kv-row"><span class="kv-key">Рейс</span><span class="kv-value">#<?= (int)($preview['snapshot']['trip']['business_id'] ?? 0) ?></span></div>
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
  <div class="modal-foot is-spaced"><div></div><div class="modal-foot-actions"><button type="button" class="btn btn-ghost" onclick="closeModal('document-generator-preview-modal')"><?= ($preview['ok'] ?? false)?'Вернуться к редактированию':'Исправить данные' ?></button><?php if ($preview['ok'] ?? false): ?><button type="submit" form="document-generator-form" class="btn btn-primary" formaction="<?= app_url('/company/document-generator/generate') ?>" data-docgen-generate><?= $packagePreview?'Сформировать комплект':'Сформировать DOCX' ?></button><?php endif; ?></div></div>
 </div>
</div>
<?php endif; ?>
<script>
(function(){
 const form=document.querySelector('[data-docgen-form]'),mode=document.querySelector('[data-docgen-mode]'),template=document.querySelector('[data-docgen-template]'),result=document.querySelector('[data-docgen-check-result]'),check=document.querySelector('[data-docgen-check]'),client=document.querySelector('[data-docgen-client]'),clientTrip=document.querySelector('[data-docgen-client-trip]');
 if(!form||!mode||!result||!check)return;let checking=false;
 const control=(n)=>Array.from(form.elements).find((x)=>x.name===n&&!x.disabled);const field=(n)=>control(n)?.closest('.field')||null;
 const clear=()=>{form.querySelectorAll('.field.is-error').forEach(n=>n.classList.remove('is-error'));form.querySelectorAll('[aria-invalid=true]').forEach(n=>n.removeAttribute('aria-invalid'));};
 const mark=(n)=>{const c=control(n),f=field(n);if(f)f.classList.add('is-error');if(c)c.setAttribute('aria-invalid','true');};
 const currentType=()=>template?.options[template.selectedIndex]?.dataset.documentType||'';
 const syncMode=()=>{const m=mode.value;form.querySelectorAll('[data-docgen-mode-section]').forEach(s=>{const active=s.dataset.docgenModeSection===m;s.classList.toggle('is-hidden',!active);s.querySelectorAll('input,select,textarea').forEach(n=>n.disabled=!active);});syncType();filterTrips();};
 const syncType=()=>{if(mode.value!=='carrier')return;const t=currentType();form.querySelectorAll('[data-docgen-section]').forEach(s=>{const active=s.dataset.docgenSection===t;s.classList.toggle('is-hidden',!active);s.querySelectorAll('input,select,textarea').forEach(n=>n.disabled=!active);});};
 const filterTrips=()=>{if(!client||!clientTrip)return;const cid=String(client.value||'');Array.from(clientTrip.options).forEach((o,i)=>{if(i===0)return;o.hidden=cid!==''&&o.dataset.clientId!==cid;});const selected=clientTrip.options[clientTrip.selectedIndex];if(selected&&selected.hidden)clientTrip.value='';};
 const errors=()=>{clear();const out=[],add=(n,m)=>{if(!String(control(n)?.value||'').trim()){out.push(m);mark(n);}};if(mode.value==='client_package'){add('client_id','Не выбран клиент.');add('trip_id','Не выбран рейс.');add('package_date','Не указана дата документов.');}else{add('template_id','Не выбран шаблон.');add('trip_id','Не выбран рейс.');if(currentType()==='vehicle_lease_crew'){add('contract_date','Не указана дата договора.');add('start_date','Не указана дата начала.');add('finish_date','Не указана дата завершения.');add('price','Не указана стоимость.');add('vat_mode','Не указан режим НДС.');}else if(currentType()==='waybill_driver_assignment'){add('waybill_number','Не указан номер путевого листа.');add('waybill_date','Не указана дата путевого листа.');}}return out;};
 const notice=(msgs,title='Проверка не пройдена')=>{const n=document.createElement('div');n.className='notice danger';const b=document.createElement('strong');b.textContent=title;n.appendChild(b);if(msgs.length){const ul=document.createElement('ul');ul.className='docgen-error-list mt-2';msgs.forEach(x=>{const li=document.createElement('li');li.textContent=x;ul.appendChild(li)});n.appendChild(ul)}result.replaceChildren(n);result.dataset.docgenState='error';result.scrollIntoView({behavior:'smooth',block:'nearest'});};
 const setBusy=(v)=>{checking=v;check.disabled=v;check.setAttribute('aria-busy',v?'true':'false');check.textContent=v?'Проверяем…':'Проверить';if(v)result.dataset.docgenState='checking';};
 const run=async()=>{if(checking)return;const e=errors();if(e.length){notice(e);return}setBusy(true);try{const r=await fetch(check.formAction||form.action,{method:'POST',body:new FormData(form),credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}});if(!r.ok)throw new Error('HTTP '+r.status);const doc=new DOMParser().parseFromString(await r.text(),'text/html'),modal=doc.querySelector('#document-generator-preview-modal');if(!modal)throw new Error('preview missing');const serverErrors=Array.from(modal.querySelectorAll('.docgen-error-list li')).map(x=>x.textContent.trim()).filter(Boolean);if(serverErrors.length||modal.querySelector('.notice.danger')){notice(serverErrors.length?serverErrors:['Документ пока нельзя сформировать.']);return}const body=modal.querySelector('.modal-body'),generate=modal.querySelector('[data-docgen-generate]');if(!body||!generate)throw new Error('preview incomplete');const panel=document.createElement('div');panel.className='panel';panel.innerHTML='<div class="panel-head"><h3 class="panel-head-title">Результат проверки</h3></div>';const pb=document.createElement('div');pb.className='panel-body';Array.from(body.childNodes).forEach(n=>pb.appendChild(n.cloneNode(true)));const a=document.createElement('div');a.className='form-actions';a.appendChild(generate.cloneNode(true));pb.appendChild(a);panel.appendChild(pb);result.replaceChildren(panel);result.dataset.docgenState='success';result.scrollIntoView({behavior:'smooth',block:'nearest'});}catch(err){console.error('Document generator preview failed',err);notice([],'Не удалось выполнить проверку');}finally{setBusy(false)}};
 const resetResult=()=>{if(result.childNodes.length)result.replaceChildren();result.dataset.docgenState='';};form.addEventListener('submit',e=>{if(!e.submitter?.matches('[data-docgen-check]'))return;e.preventDefault();run();});form.addEventListener('input',e=>{e.target?.closest?.('.field')?.classList.remove('is-error');e.target?.removeAttribute?.('aria-invalid');resetResult();});mode.addEventListener('change',()=>{syncMode();clear();resetResult();});template?.addEventListener('change',()=>{syncType();clear();resetResult();});client?.addEventListener('change',()=>{filterTrips();resetResult();});syncMode();
 <?php if (is_array($preview)): ?>if(typeof openModal==='function')openModal('document-generator-preview-modal');<?php endif; ?>
})();
</script>
<?php endif; ?>
