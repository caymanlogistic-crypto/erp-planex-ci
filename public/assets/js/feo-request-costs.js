(function(){
'use strict';
const cfg=window.FEO_REQUEST_COST_CONFIG||{},base=cfg.base||'',csrf=cfg.csrf||'';
const q=(s,r=document)=>r.querySelector(s),qa=(s,r=document)=>Array.from(r.querySelectorAll(s));
const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
const fmt=(n,d=3)=>new Intl.NumberFormat('ru-RU',{minimumFractionDigits:0,maximumFractionDigits:d}).format(Number(n||0));
const money=n=>new Intl.NumberFormat('ru-RU',{minimumFractionDigits:2,maximumFractionDigits:2}).format(Number(n||0));
const typeLabel=t=>t==='rate_34_5'?'34,5 ₽/кг':t==='rate_38'?'38 ₽/кг':'Согласование';
const typeRate=t=>t==='rate_34_5'?34.5:t==='rate_38'?38:null;
function openModal(id){const m=q('#'+id);if(!m)return;m.classList.add('is-open');m.setAttribute('aria-hidden','false');}
function closeModal(id){const m=q('#'+id);if(!m)return;m.classList.remove('is-open');m.setAttribute('aria-hidden','true');}
qa('[data-close-modal]').forEach(b=>b.addEventListener('click',()=>closeModal(b.dataset.closeModal)));
qa('.modal-overlay[data-close-on-overlay="1"]').forEach(m=>m.addEventListener('mousedown',e=>{if(e.target===m)closeModal(m.id);}));
document.addEventListener('keydown',e=>{if(e.key==='Escape')qa('.modal-overlay.is-open[data-close-on-escape="1"]').forEach(m=>closeModal(m.id));});
async function json(url,opts={}){const res=await fetch(url,{credentials:'same-origin',...opts});let data={};try{data=await res.json();}catch(_){data={success:false,message:'Сервер вернул некорректный ответ.'};}if(!res.ok||data.success===false)throw Object.assign(new Error(data.message||'Операция не выполнена.'),{payload:data});return data;}
function fd(form){const x=new FormData(form);if(!x.has('_csrf_token'))x.append('_csrf_token',csrf);return x;}
function extractClientRequestIds(raw){
  const found=[];
  String(raw||'').replace(/\r/g,'').split('\n').forEach(line=>{
    let cell=String(line||'').trim();
    if(line.indexOf('\t')!==-1)cell=(line.split('\t',1)[0]||'').trim();
    else if(line.indexOf('|')!==-1){const normalized=line.trim().replace(/^\|+|\|+$/g,'');cell=(normalized.split('|',1)[0]||'').trim();}
    (cell.match(/(?<!\d)\d{6}(?!\d)/g)||[]).forEach(id=>found.push(id));
  });
  const seen=new Set();return found.filter(id=>{if(seen.has(id))return false;seen.add(id);return true;});
}
function setText(el,t){if(el)el.textContent=String(t??'');}
function parseAmount(value){return Number(String(value||'').replace(/\s/g,'').replace(',','.'));}
function setActiveChoice(selector,type){qa(selector).forEach(b=>b.classList.toggle('is-active',b.dataset.costTypeChoice===type||b.dataset.costEditTypeChoice===type));}

const form=q('[data-cost-create-form]'),idsInput=q('[data-cost-ids]'),validation=q('[data-cost-validation]'),analyze=q('[data-cost-analyze]'),total=q('[data-cost-total]'),typeInput=q('[data-cost-pricing-type]'),agreementField=q('[data-cost-agreement-field]');
let state={valid:false,mass:0,count:0,type:'agreement',timer:0,generation:0,controller:null};
function calculatedTotal(){const rate=typeRate(state.type);return rate===null?parseAmount(total?.value):state.mass*rate;}
function syncSummary(){
  setText(q('[data-cost-count]'),state.count);setText(q('[data-cost-mass]'),fmt(state.mass));setText(q('[data-cost-calc-mass]'),fmt(state.mass)+' кг');
  const rate=typeRate(state.type);setText(q('[data-cost-calc-rate]'),rate===null?'—':money(rate)+' ₽/кг');const amount=calculatedTotal();setText(q('[data-cost-calc-total]'),Number.isFinite(amount)&&amount>0?money(amount)+' ₽':'0,00 ₽');
  if(typeInput)typeInput.value=state.type;if(agreementField)agreementField.classList.toggle('is-hidden',state.type!=='agreement');setActiveChoice('[data-cost-type-choice]',state.type);if(analyze)analyze.disabled=!state.valid||!(amount>0);
}
function chooseType(type){if(!['rate_34_5','rate_38','agreement'].includes(type))return;state.type=type;if(type!=='agreement'&&total)total.value=money(state.mass*typeRate(type));syncSummary();}
qa('[data-cost-type-choice]').forEach(b=>b.addEventListener('click',()=>chooseType(b.dataset.costTypeChoice)));
total?.addEventListener('input',()=>{state.type='agreement';syncSummary();});
function renderValidation(data){
  if(!validation)return;validation.classList.remove('is-hidden','success','warn');validation.replaceChildren();const bad=[];
  if((data.duplicates||[]).length)bad.push('Повторы во вводе: '+data.duplicates.join(', ')+'.');
  if((data.missing_request_ids||[]).length)bad.push('Не найдены заявки: '+data.missing_request_ids.join(', ')+'.');
  if((data.missing_placement_ids||[]).length)bad.push('Нет в актуальном «Размещении на карте»: '+data.missing_placement_ids.join(', ')+'.');
  if((data.already_priced_ids||[]).length){const parts=data.already_priced_ids.map(id=>`${id} (блок #${data.already_priced_blocks?.[id]||'?'})`);bad.push('Стоимость уже назначена: '+parts.join(', ')+'.');}
  if(bad.length){validation.classList.add('warn');bad.forEach(t=>{const d=document.createElement('div');d.textContent=t;validation.appendChild(d);});}
  else{validation.classList.add('success');validation.textContent=`Выбрано: ${data.selected_count} заявок · Общий вес: ${fmt(data.selected_mass_kg)} кг.`;}
}
async function validate(){
  if(!idsInput)return;const raw=idsInput.value;if(!raw.trim()){state.valid=false;state.mass=0;state.count=0;validation?.classList.add('is-hidden');syncSummary();return;}
  const generation=++state.generation;state.controller?.abort();state.controller=new AbortController();state.valid=false;syncSummary();
  try{const x=new FormData();x.append('_csrf_token',csrf);x.append('request_ids',raw);const data=await json(base+'/validate',{method:'POST',body:x,signal:state.controller.signal});if(generation!==state.generation)return;state.valid=!!data.valid;state.mass=Number(data.selected_mass_kg||0);state.count=Number(data.selected_count||0);renderValidation(data);if(state.type!=='agreement'&&total)total.value=money(state.mass*typeRate(state.type));}
  catch(e){if(e.name==='AbortError'||generation!==state.generation)return;state.valid=false;if(validation){validation.classList.remove('is-hidden','success');validation.classList.add('warn');validation.textContent=e.message;}}
  finally{if(generation===state.generation)syncSummary();}
}
function schedule(immediate=false){clearTimeout(state.timer);state.timer=setTimeout(validate,immediate?0:280);}
idsInput?.addEventListener('input',()=>schedule());
idsInput?.addEventListener('paste',e=>{const ids=extractClientRequestIds(e.clipboardData?.getData('text/plain')||'');if(ids.length){e.preventDefault();idsInput.value=ids.join('\n');idsInput.dispatchEvent(new Event('input',{bubbles:true}));}});
q('[data-cost-open-create]')?.addEventListener('click',()=>{form?.reset();state={...state,valid:false,mass:0,count:0,type:'agreement'};validation?.classList.add('is-hidden');q('[data-cost-create-error]')?.classList.add('is-hidden');syncSummary();openModal('feo-cost-create-modal');idsInput?.focus();});

let pending=null;
function confirmOperation(kind,token,preview){
  pending={kind,token};setText(q('[data-cost-confirm-title]'),kind==='create'?'Создание стоимости':'Изменение стоимости');const copy=q('[data-cost-confirm-copy]');
  if(copy){
    let html=`<div class="feo-cost-confirm-grid"><div><span>Заявок</span><br><b>${esc(preview.selected_count)}</b></div><div><span>Общий вес</span><br><b>${fmt(preview.selected_mass_kg)} кг</b></div><div><span>Тип</span><br><b>${esc(typeLabel(preview.pricing_type))}</b></div><div><span>Итоговая стоимость</span><br><b>${money(preview.total_cost)} ₽</b></div></div>`;
    if(kind==='edit'){
      const added=preview.added_ids||[],removed=preview.removed_ids||[];
      html+=`<div class="feo-cost-confirm-diff"><div><b>Состав:</b> ${preview.composition_changed?'изменится':'без изменений'}</div><div>Добавлено: <b>${added.length?esc(added.join(', ')):'—'}</b></div><div>Удалено: <b>${removed.length?esc(removed.join(', ')):'—'}</b></div><div>Заявок: <b>${esc(preview.old_request_count)} → ${esc(preview.selected_count)}</b></div><div>Вес: <b>${fmt(preview.old_total_mass_kg)} → ${fmt(preview.selected_mass_kg)} кг</b></div><div>Стоимость: <b>${money(preview.old_total_cost)} → ${money(preview.total_cost)} ₽</b></div></div>`;
    }
    copy.innerHTML=html;
  }
  q('[data-cost-confirm-error]')?.classList.add('is-hidden');closeModal('feo-cost-create-modal');closeModal('feo-cost-detail-modal');openModal('feo-cost-confirm-modal');
}
async function analyzeCreate(){if(!form||!state.valid)return;const err=q('[data-cost-create-error]');err?.classList.add('is-hidden');try{const data=await json(form.action,{method:'POST',body:fd(form)});confirmOperation('create',data.token,data.preview);}catch(e){if(err){err.textContent=e.message;err.classList.remove('is-hidden');}}}
analyze?.addEventListener('click',analyzeCreate);
q('[data-cost-confirm-submit]')?.addEventListener('click',async()=>{if(!pending)return;const btn=q('[data-cost-confirm-submit]'),err=q('[data-cost-confirm-error]');btn.disabled=true;err?.classList.add('is-hidden');try{const x=new FormData();x.append('_csrf_token',csrf);x.append('preview_token',pending.token);await json(pending.kind==='create'?base+'/commit':base+'/edit/commit',{method:'POST',body:x});location.reload();}catch(e){if(err){err.textContent=e.message;err.classList.remove('is-hidden');}}finally{btn.disabled=false;}});

let detailBlock=null;
const editForm=q('[data-cost-edit-form]'),editIds=q('[data-cost-edit-ids]'),editValidation=q('[data-cost-edit-validation]'),editTotal=q('[data-cost-edit-total]'),editType=q('[data-cost-edit-type]'),editAgreementField=q('[data-cost-edit-agreement-field]'),editSave=q('[data-cost-edit-analyze]');
let editState={valid:false,mass:0,count:0,type:'agreement',compositionChanged:false,timer:0,generation:0,controller:null};
function editCalculatedTotal(){const rate=typeRate(editState.type);return rate===null?parseAmount(editTotal?.value):editState.mass*rate;}
function syncEditSummary(){
  setText(q('[data-cost-edit-count]'),editState.count);setText(q('[data-cost-edit-mass]'),fmt(editState.mass));setText(q('[data-cost-edit-calc-mass]'),fmt(editState.mass)+' кг');
  const rate=typeRate(editState.type);setText(q('[data-cost-edit-calc-rate]'),rate===null?'—':money(rate)+' ₽/кг');const amount=editCalculatedTotal();setText(q('[data-cost-edit-calc-total]'),Number.isFinite(amount)&&amount>0?money(amount)+' ₽':'0,00 ₽');
  if(editType)editType.value=editState.type;if(editAgreementField)editAgreementField.classList.toggle('is-hidden',editState.type!=='agreement');setActiveChoice('[data-cost-edit-type-choice]',editState.type);if(editSave)editSave.disabled=!editState.valid||!(amount>0);
}
function setEditType(type){if(!detailBlock||!['rate_34_5','rate_38','agreement'].includes(type))return;editState.type=type;if(type!=='agreement'&&editTotal)editTotal.value=money(editState.mass*typeRate(type));syncEditSummary();}
qa('[data-cost-edit-type-choice]').forEach(b=>b.addEventListener('click',()=>setEditType(b.dataset.costEditTypeChoice)));
editTotal?.addEventListener('input',()=>{editState.type='agreement';syncEditSummary();});
function renderEditValidation(data){
  if(!editValidation)return;editValidation.classList.remove('is-hidden','success','warn');editValidation.replaceChildren();const bad=[];
  if((data.duplicates||[]).length)bad.push('Повторы во вводе: '+data.duplicates.join(', ')+'.');
  if((data.missing_placement_ids||[]).length)bad.push('Добавляемые заявки отсутствуют в актуальном «Размещении на карте»: '+data.missing_placement_ids.join(', ')+'.');
  if((data.already_priced_ids||[]).length){const parts=data.already_priced_ids.map(id=>`${id} (блок #${data.already_priced_blocks?.[id]||'?'})`);bad.push('Стоимость уже назначена в другом блоке: '+parts.join(', ')+'.');}
  if(bad.length){editValidation.classList.add('warn');bad.forEach(t=>{const d=document.createElement('div');d.textContent=t;editValidation.appendChild(d);});}
  else{editValidation.classList.add('success');const added=data.added_ids||[],removed=data.removed_ids||[];editValidation.textContent=data.composition_changed?`Новый состав корректен. Добавлено: ${added.length}; удалено: ${removed.length}.`:'Состав блока без изменений.';}
}
async function validateEdit(){
  if(!detailBlock||!editIds)return;const raw=editIds.value;if(!raw.trim()){editState.valid=false;editState.mass=0;editState.count=0;syncEditSummary();return;}
  const generation=++editState.generation;editState.controller?.abort();editState.controller=new AbortController();editState.valid=false;syncEditSummary();
  try{const x=new FormData();x.append('_csrf_token',csrf);x.append('request_ids',raw);const data=await json(base+'/blocks/'+encodeURIComponent(detailBlock.id)+'/edit/validate',{method:'POST',body:x,signal:editState.controller.signal});if(generation!==editState.generation)return;editState.valid=!!data.valid;editState.mass=Number(data.selected_mass_kg||0);editState.count=Number(data.selected_count||0);editState.compositionChanged=!!data.composition_changed;renderEditValidation(data);if(editState.type!=='agreement'&&editTotal)editTotal.value=money(editState.mass*typeRate(editState.type));}
  catch(e){if(e.name==='AbortError'||generation!==editState.generation)return;editState.valid=false;if(editValidation){editValidation.classList.remove('is-hidden','success');editValidation.classList.add('warn');editValidation.textContent=e.message;}}
  finally{if(generation===editState.generation)syncEditSummary();}
}
function scheduleEdit(immediate=false){editState.valid=false;syncEditSummary();clearTimeout(editState.timer);editState.timer=setTimeout(validateEdit,immediate?0:280);}
editIds?.addEventListener('input',()=>scheduleEdit());
editIds?.addEventListener('paste',e=>{const ids=extractClientRequestIds(e.clipboardData?.getData('text/plain')||'');if(ids.length){e.preventDefault();editIds.value=ids.join('\n');editIds.dispatchEvent(new Event('input',{bubbles:true}));}});

function bindCostBlockRow(row,idKey){
  const interactive=e=>e.target.closest('a,input,textarea,select,button');
  row.addEventListener('click',e=>{if(interactive(e))return;e.stopPropagation();});
  row.addEventListener('dblclick',e=>{if(interactive(e))return;e.preventDefault();e.stopPropagation();openBlock(row.dataset[idKey]);});
}
qa('[data-cost-block-id]').forEach(row=>bindCostBlockRow(row,'costBlockId'));
function showDetailModal(){return new Promise(resolve=>requestAnimationFrame(()=>{openModal('feo-cost-detail-modal');resolve();}));}
async function openBlock(id){
  const body=q('[data-cost-detail-body]'),err=q('[data-cost-detail-error]');detailBlock=null;err?.classList.add('is-hidden');
  try{const data=await json(base+'/blocks/'+encodeURIComponent(id)+'/json');const b=data.block;detailBlock=b;setText(q('[data-cost-detail-title]'),`Блок стоимости #${b.id}`);
    const rows=(b.requests||[]).map(r=>{let stateText='Без расхождений';if(r.missing_request)stateText='Заявка отсутствует в ФЭО';else if(!r.in_current_placement)stateText='Нет в актуальном размещении';else if(r.mass_changed)stateText=`Вес изменился: ${fmt(r.snapshot_mass_kg)} → ${fmt(r.current_mass_kg)} кг`;return `<tr><td>${esc(r.zayavka_id)}</td><td>${fmt(r.snapshot_mass_kg)}</td><td>${r.current_mass_kg===null?'—':fmt(r.current_mass_kg)}</td><td class="${r.mismatch?'feo-cost-delta bad':'feo-cost-delta'}">${esc(stateText)}</td></tr>`;}).join('');
    if(body)body.innerHTML=`${b.has_integrity_issue?'<div class="notice warn"><b>Снимок расходится с текущими данными.</b> Сохранённая стоимость автоматически не изменена.</div>':''}<div class="feo-cost-detail-meta"><div><span>ID блока</span><b>#${b.id}</b></div><div><span>Тип</span><b>${esc(typeLabel(b.pricing_type))}</b></div><div><span>Сохранённый вес</span><b>${fmt(b.snapshot_total_mass_kg)} кг</b></div><div><span>Стоимость</span><b>${money(b.total_cost)} ₽</b></div><div><span>Заявок</span><b>${b.snapshot_request_count}</b></div><div><span>Ставка</span><b>${b.rate_per_kg===null?'—':money(b.rate_per_kg)+' ₽/кг'}</b></div><div><span>Создан</span><b>${esc(b.created_at||'—')}</b></div><div><span>Проверка</span><b>${b.has_integrity_issue?'Есть расхождения':'Без расхождений'}</b></div></div><div class="feo-cost-detail-comment"><span>Комментарий</span><b>${esc(b.comment||'—')}</b></div><div class="feo-cost-detail-list"><table class="table"><thead><tr><th>ID заявки</th><th>Snapshot, кг</th><th>Сейчас, кг</th><th>Проверка</th></tr></thead><tbody>${rows}</tbody></table></div>`;
    if(editIds)editIds.value=(b.requests||[]).map(r=>r.zayavka_id).join('\n');if(editTotal)editTotal.value=money(b.total_cost);const editComment=q('[data-cost-edit-comment]');if(editComment)editComment.value=b.comment||'';editValidation?.classList.add('is-hidden');editState={...editState,valid:true,mass:Number(b.snapshot_total_mass_kg||0),count:Number(b.snapshot_request_count||0),type:b.pricing_type,compositionChanged:false};syncEditSummary();await showDetailModal();
  }catch(e){if(body)body.innerHTML='';if(err){err.textContent=e.message;err.classList.remove('is-hidden');}await showDetailModal();}
}
editSave?.addEventListener('click',async()=>{if(!detailBlock||!editForm||!editState.valid)return;const err=q('[data-cost-detail-error]');err?.classList.add('is-hidden');try{const data=await json(base+'/blocks/'+encodeURIComponent(detailBlock.id)+'/edit/analyze',{method:'POST',body:fd(editForm)});confirmOperation('edit',data.token,data.preview);}catch(e){if(err){err.textContent=e.message;err.classList.remove('is-hidden');}}});

const searchInput=q('[data-cost-search]'),searchResults=q('[data-cost-search-results]');let search={timer:0,generation:0,controller:null};
function clearSearch(){searchResults?.classList.add('is-hidden');if(searchResults)searchResults.innerHTML='';}
function renderSearch(data){
  if(!searchResults)return;const results=Array.isArray(data.results)?data.results:[];const rows=[];
  results.forEach(item=>{
    const b=item.block||null;const registry=item.exists_in_feo?'Есть в ФЭО':'Нет в ФЭО';const placement=item.is_current?'В актуальном составе':'Не в актуальном составе';
    if(b){rows.push(`<tr data-cost-search-block-id="${b.block_id}"><td><b>${esc(item.zayavka_id)}</b></td><td>${esc(registry)}</td><td>${esc(placement)}</td><td>#${b.block_id}</td><td>${esc(typeLabel(b.pricing_type))}</td><td>${esc(b.snapshot_request_count)} заявок · ${fmt(b.snapshot_total_mass_kg,0)} кг</td><td>${money(b.total_cost)} ₽</td></tr>`);}
    else{rows.push(`<tr><td><b>${esc(item.zayavka_id)}</b></td><td>${esc(registry)}</td><td>${esc(placement)}</td><td>Стоимость не назначена</td><td>—</td><td>—</td><td>—</td></tr>`);}
  });
  const duplicateNote=Array.isArray(data.duplicates)&&data.duplicates.length?` Повторы во вводе удалены: ${esc(data.duplicates.join(', '))}.`:'';
  searchResults.innerHTML=`<div class="feo-cost-search-summary">Найдено по ${results.length} заявкам.${duplicateNote} Двойной щелчок по найденному блоку откроет редактирование.</div><table class="table feo-cost-search-table"><thead><tr><th>Заявка</th><th>Реестр ФЭО</th><th>Размещение</th><th>Блок</th><th>Тип</th><th>Состав блока</th><th>Стоимость</th></tr></thead><tbody>${rows.join('')}</tbody></table>`;
  searchResults.classList.remove('is-hidden');qa('[data-cost-search-block-id]',searchResults).forEach(row=>bindCostBlockRow(row,'costSearchBlockId'));
}
async function runSearch(){if(!searchInput)return;const raw=searchInput.value;if(!raw.trim()){clearSearch();return;}const generation=++search.generation;search.controller?.abort();search.controller=new AbortController();try{const data=await json(base+'/search?'+new URLSearchParams({request_ids:raw}).toString(),{signal:search.controller.signal});if(generation!==search.generation)return;renderSearch(data);}catch(e){if(e.name==='AbortError'||generation!==search.generation)return;if(searchResults){searchResults.innerHTML=`<div class="notice warn">${esc(e.message)}</div>`;searchResults.classList.remove('is-hidden');}}}
function scheduleSearch(immediate=false){clearTimeout(search.timer);search.timer=setTimeout(runSearch,immediate?0:280);}
searchInput?.addEventListener('input',()=>scheduleSearch());
searchInput?.addEventListener('paste',e=>{const ids=extractClientRequestIds(e.clipboardData?.getData('text/plain')||'');if(ids.length){e.preventDefault();searchInput.value=ids.join(' ');searchInput.dispatchEvent(new Event('input',{bubbles:true}));}});
searchInput?.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();scheduleSearch(true);}});
syncSummary();syncEditSummary();
})();
