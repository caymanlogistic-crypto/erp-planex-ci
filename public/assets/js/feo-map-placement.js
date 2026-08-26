(function(){
'use strict';

const cfg=window.FEO_PLACEMENT_CONFIG||{};
const base=cfg.base||'';
const csrf=cfg.csrf||'';
const q=(s,r=document)=>r.querySelector(s);
const qa=(s,r=document)=>Array.from(r.querySelectorAll(s));
const fmt=n=>new Intl.NumberFormat('ru-RU',{maximumFractionDigits:3}).format(Number(n||0));
const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));

function openModal(id){const el=document.getElementById(id);if(el)el.classList.add('is-open');}
function closeModal(id){const el=document.getElementById(id);if(el)el.classList.remove('is-open');}
qa('[data-close-modal]').forEach(b=>b.addEventListener('click',()=>closeModal(b.dataset.closeModal)));
qa('.modal-overlay[data-close-on-overlay="1"]').forEach(m=>m.addEventListener('mousedown',e=>{if(e.target===m)closeModal(m.id);}));
document.addEventListener('keydown',e=>{if(e.key==='Escape')qa('.modal-overlay.is-open[data-close-on-escape="1"]').forEach(m=>closeModal(m.id));});

async function json(url,opts={}){
  const res=await fetch(url,{credentials:'same-origin',...opts});
  let data={};
  try{data=await res.json();}catch(_){data={success:false,message:'Сервер вернул некорректный ответ.'};}
  if(!res.ok||data.success===false){
    const error=new Error(data.message||'Операция не выполнена.');
    error.payload=data;
    throw error;
  }
  return data;
}
function formDataWithCsrf(form){const fd=new FormData(form);if(!fd.has('_csrf'))fd.append('_csrf',csrf);return fd;}

function extractClientRequestIds(raw){
  raw=String(raw||'');
  if(!raw.trim())return [];
  const structured=raw.indexOf('\t')!==-1||raw.indexOf('|')!==-1;
  const found=[];
  if(structured){
    raw.replace(/\r/g,'').split('\n').forEach(line=>{
      let cell='';
      if(line.indexOf('\t')!==-1)cell=(line.split('\t',1)[0]||'').trim();
      else if(line.indexOf('|')!==-1){
        const normalized=line.trim().replace(/^\|+|\|+$/g,'');
        cell=(normalized.split('|',1)[0]||'').trim();
      }
      const matches=cell.match(/(?<!\d)\d{6}(?!\d)/g)||[];
      matches.forEach(id=>found.push(id));
    });
  }else{
    (raw.match(/(?<!\d)\d{6}(?!\d)/g)||[]).forEach(id=>found.push(id));
  }
  const seen=new Set();
  return found.filter(id=>{if(seen.has(id))return false;seen.add(id);return true;});
}

function statusHtml(status){
  const applied=status==='applied';
  return `<span class="feo-placement-status ${applied?'feo-placement-status--applied':'feo-placement-status--disabled'}">${applied?'Применяется':'Отключён'}</span>`;
}

function renderMessage(el,message){
  if(!el)return;
  const text=String(message||'');
  const prefix='Повторная операция запрещена:';
  el.replaceChildren();
  if(text.startsWith(prefix)){
    const strong=document.createElement('b');
    strong.textContent=prefix;
    el.appendChild(strong);
    el.appendChild(document.createTextNode(text.slice(prefix.length)));
  }else{
    el.textContent=text;
  }
}

function appendRepeatPanel(el,data){
  if(!el)return;
  const ids=Array.isArray(data?.repeat_ids)?data.repeat_ids:[];
  if(!ids.length)return;
  const title=document.createElement('div');
  title.className='feo-placement-repeat-title';
  title.textContent='Повторная операция запрещена:';
  el.appendChild(title);
  const list=document.createElement('div');
  list.className='feo-placement-repeat-list';
  ids.forEach(id=>{
    const item=document.createElement('div');
    item.className='feo-placement-repeat-item';
    const idEl=document.createElement('div');
    idEl.className='feo-placement-repeat-id';
    idEl.textContent='Заявка '+id;
    const blocksEl=document.createElement('div');
    blocksEl.className='feo-placement-repeat-blocks';
    const blocks=Array.isArray(data?.repeat_blocks?.[id])?data.repeat_blocks[id]:[];
    if(blocks.length){
      blocks.forEach(block=>{
        const row=document.createElement('div');
        row.className='feo-placement-repeat-block';
        const type=block.block_type==='addition'?'Добавление':'Исключение';
        row.textContent=`Активный блок #${block.id}${block.name?' «'+block.name+'»':''} · ${type}`;
        blocksEl.appendChild(row);
      });
    }else{
      const row=document.createElement('div');
      row.className='feo-placement-repeat-block';
      row.textContent=data?.block_type==='exclusion'?'Заявка отсутствует в актуальном составе.':'Заявка уже находится в актуальном составе.';
      blocksEl.appendChild(row);
    }
    item.appendChild(idEl);
    item.appendChild(blocksEl);
    list.appendChild(item);
  });
  el.appendChild(list);
}

const createForm=q('[data-placement-create-form]');
const sourceRadios=qa('input[name="source_mode"]',createForm||document);
const typeRadios=qa('input[name="block_type"]',createForm||document);
const manual=q('[data-placement-manual]');
const manualInput=q('[data-placement-ids]');
const file=q('[data-placement-file]');
const liveValidation=q('[data-placement-live-validation]');
const analyzeButton=q('[data-placement-analyze]');

let manualValidation={valid:false,timer:0,generation:0,controller:null};

function setAnalyzeAvailability(){
  if(!analyzeButton||!createForm)return;
  const mode=q('input[name="source_mode"]:checked',createForm)?.value||'manual';
  analyzeButton.disabled=mode==='manual'&&!manualValidation.valid;
}
function clearLiveValidation(){
  if(!liveValidation)return;
  liveValidation.classList.add('is-hidden');
  liveValidation.classList.remove('success','warn');
  liveValidation.replaceChildren();
}
function syncSource(){
  const mode=q('input[name="source_mode"]:checked',createForm)?.value||'manual';
  manual?.classList.toggle('is-hidden',mode!=='manual');
  file?.classList.toggle('is-active',mode==='file');
  if(mode==='manual')scheduleManualValidation();
  else{
    manualValidation.valid=true;
    clearLiveValidation();
    setAnalyzeAvailability();
  }
}
sourceRadios.forEach(r=>r.addEventListener('change',syncSource));
typeRadios.forEach(r=>r.addEventListener('change',()=>scheduleManualValidation(true)));

function renderManualValidation(data){
  clearLiveValidation();
  if(!liveValidation)return;
  const duplicates=Array.isArray(data.duplicates)?data.duplicates:[];
  const missing=Array.isArray(data.missing_ids)?data.missing_ids:[];
  const repeats=Array.isArray(data.repeat_ids)?data.repeat_ids:[];
  liveValidation.classList.remove('is-hidden');
  if(duplicates.length||missing.length||repeats.length){
    liveValidation.classList.add('warn');
    if(duplicates.length){
      const row=document.createElement('div');
      const strong=document.createElement('b');
      strong.textContent='Повторы внутри вводимого блока: ';
      row.appendChild(strong);
      row.appendChild(document.createTextNode(duplicates.join(', ')+'.'));
      liveValidation.appendChild(row);
    }
    if(missing.length){
      const row=document.createElement('div');
      const strong=document.createElement('b');
      strong.textContent='Не найдены заявки ФЭО: ';
      row.appendChild(strong);
      row.appendChild(document.createTextNode(missing.join(', ')+'.'));
      liveValidation.appendChild(row);
    }
    if(repeats.length)appendRepeatPanel(liveValidation,data);
  }else{
    liveValidation.classList.add('success');
    liveValidation.textContent=`Проверка пройдена: ${Array.isArray(data.ids)?data.ids.length:0} заявок найдены в реестре ФЭО и не конфликтуют с активными блоками.`;
  }
}
async function validateManual(){
  if(!createForm||!manualInput)return;
  const mode=q('input[name="source_mode"]:checked',createForm)?.value||'manual';
  if(mode!=='manual')return;
  const raw=manualInput.value;
  if(!raw.trim()){
    manualValidation.valid=false;
    clearLiveValidation();
    setAnalyzeAvailability();
    return;
  }
  const generation=++manualValidation.generation;
  if(manualValidation.controller)manualValidation.controller.abort();
  manualValidation.controller=new AbortController();
  manualValidation.valid=false;
  setAnalyzeAvailability();
  if(liveValidation){
    liveValidation.classList.remove('is-hidden','success','warn');
    liveValidation.textContent='Проверяем заявки в реестре ФЭО и активных блоках…';
  }
  const fd=new FormData();
  fd.append('_csrf',csrf);
  fd.append('request_ids',raw);
  fd.append('block_type',q('input[name="block_type"]:checked',createForm)?.value||'addition');
  try{
    const data=await json(base+'/validate-manual',{method:'POST',body:fd,signal:manualValidation.controller.signal});
    if(generation!==manualValidation.generation)return;
    manualValidation.valid=!!data.valid;
    renderManualValidation(data);
  }catch(e){
    if(e.name==='AbortError'||generation!==manualValidation.generation)return;
    manualValidation.valid=false;
    if(liveValidation){
      liveValidation.classList.remove('is-hidden','success');
      liveValidation.classList.add('warn');
      renderMessage(liveValidation,e.message);
    }
  }finally{
    if(generation===manualValidation.generation)setAnalyzeAvailability();
  }
}
function scheduleManualValidation(immediate=false){
  clearTimeout(manualValidation.timer);
  manualValidation.timer=window.setTimeout(validateManual,immediate?0:280);
}

manualInput?.addEventListener('input',()=>scheduleManualValidation());
manualInput?.addEventListener('paste',ev=>{
  const pasted=ev.clipboardData?.getData('text/plain')||'';
  const ids=extractClientRequestIds(pasted);
  if(ids.length){
    ev.preventDefault();
    manualInput.value=ids.join('\n');
    scheduleManualValidation(true);
  }
});

q('[data-placement-open-create]')?.addEventListener('click',()=>{
  createForm?.reset();
  manualValidation.valid=false;
  clearLiveValidation();
  syncSource();
  const err=q('[data-placement-create-error]');
  if(err)err.classList.add('is-hidden');
  openModal('feo-placement-create-modal');
});
syncSource();

let pending=null;
function effectText(effect){
  const a=Number(effect?.added_count||0),r=Number(effect?.removed_count||0);
  const parts=[];
  if(a)parts.push(`будет добавлено ${a} заявок общей массой ${fmt(effect.added_mass_kg)} кг`);
  if(r)parts.push(`будет исключено ${r} заявок общей массой ${fmt(effect.removed_mass_kg)} кг`);
  return parts.length?parts.join('; '):'актуальный состав не изменится';
}
function confirmOperation(kind,token,preview,meta={}){
  pending={kind,token,preview,meta};
  const title=q('[data-placement-confirm-title]');
  const copy=q('[data-placement-confirm-copy]');
  const err=q('[data-placement-confirm-error]');
  const submit=q('[data-placement-confirm-submit]');
  if(err)err.classList.add('is-hidden');
  if(submit)submit.className=kind==='delete'?'btn btn-danger':'btn btn-primary';
  if(title){
    title.textContent=kind==='create'?'Применить блок?':kind==='status'?'Изменить статус блока?':kind==='delete'?'Удалить отключённый блок?':'Пересчитать актуальный состав?';
  }
  if(copy){
    let html='';
    if(kind==='create'){
      html+=`<div><b>${preview.block_type==='addition'?'Добавление':'Исключение'}:</b> ${preview.selected_count} заявок общей массой <b>${fmt(preview.selected_mass_kg)} кг</b>.</div>`;
    }else if(kind==='delete'){
      html+=`<div><b>Блок #${preview.block_id}${preview.name?' · '+esc(preview.name):''}</b> будет удалён без возможности восстановления.</div>`;
      html+=`<div>Состав блока: <b>${preview.request_count} заявок · ${fmt(preview.total_mass_kg)} кг</b>. Актуальный состав не изменится.</div>`;
      if(preview.source_filename)html+=`<div>Исходный файл <b>${esc(preview.source_filename)}</b> также будет удалён из хранилища.</div>`;
    }else{
      if(kind==='status')html+=`<div>Блок #${preview.block_id}: ${preview.target_status==='applied'?'включить в расчёт':'отключить из расчёта'}.</div>`;
      html+=`<div class="feo-placement-confirm-effect"><b>После операции:</b> ${esc(effectText(preview.effect))}.</div>`;
    }
    copy.innerHTML=html;
  }
  closeModal('feo-placement-create-modal');
  closeModal('feo-placement-detail-modal');
  openModal('feo-placement-confirm-modal');
}

async function analyzeCreate(e){
  e?.preventDefault();
  if(!createForm)return;
  const btn=q('[data-placement-analyze]');
  const err=q('[data-placement-create-error]');
  if(err)err.classList.add('is-hidden');
  if(btn)btn.disabled=true;
  try{
    const data=await json(createForm.action,{method:'POST',body:formDataWithCsrf(createForm)});
    confirmOperation('create',data.token,data.preview||{},{});
  }catch(e){
    if(err){
      err.replaceChildren();
      if(e.payload?.code==='repeat_operation')appendRepeatPanel(err,e.payload);
      else renderMessage(err,e.message);
      err.classList.remove('is-hidden');
    }
  }finally{
    if(btn){
      const mode=q('input[name="source_mode"]:checked',createForm)?.value||'manual';
      btn.disabled=mode==='manual'&&!manualValidation.valid;
    }
  }
}
createForm?.addEventListener('submit',analyzeCreate);
q('[data-placement-analyze]')?.addEventListener('click',e=>{
  e.preventDefault();
  if(typeof createForm?.requestSubmit==='function')createForm.requestSubmit();
  else analyzeCreate(e);
});

q('[data-placement-confirm-submit]')?.addEventListener('click',async()=>{
  if(!pending)return;
  const btn=q('[data-placement-confirm-submit]');
  const err=q('[data-placement-confirm-error]');
  btn.disabled=true;
  if(err)err.classList.add('is-hidden');
  try{
    const fd=new FormData();
    fd.append('_csrf',csrf);
    fd.append('preview_token',pending.token);
    const url=pending.kind==='create'?base+'/commit':pending.kind==='status'?base+'/status/commit':pending.kind==='delete'?base+'/delete/commit':base+'/reconcile/commit';
    await json(url,{method:'POST',body:fd});
    location.reload();
  }catch(e){
    if(err){renderMessage(err,e.message);err.classList.remove('is-hidden');}
  }finally{
    btn.disabled=false;
  }
});

qa('[data-placement-block-id]').forEach(row=>row.addEventListener('dblclick',()=>openBlock(row.dataset.placementBlockId)));

async function openBlock(id){
  const body=q('[data-placement-detail-body]');
  const actions=q('[data-placement-detail-actions]');
  const err=q('[data-placement-detail-error]');
  if(err)err.classList.add('is-hidden');
  if(body)body.innerHTML='Загрузка…';
  if(actions)actions.innerHTML='';
  openModal('feo-placement-detail-modal');
  try{
    const data=await json(base+'/blocks/'+encodeURIComponent(id)+'/json');
    const b=data.block;
    const reqs=b.requests||[];
    const sourceText=b.source_type==='manual'
      ? (reqs.map(r=>String(r.zayavka_id)).join(', ')||'—')
      : (b.source_filename||String(b.source_type||'').toUpperCase()||'—');
    q('[data-placement-detail-title]').textContent=`Блок #${b.id}${b.name?' · '+b.name:''}`;
    if(body)body.innerHTML=`<div class="feo-placement-detail-meta"><div><span>Тип</span><b>${b.block_type==='addition'?'Добавление':'Исключение'}</b></div><div><span>Статус</span><b>${statusHtml(b.status)}</b></div><div><span>Состав</span><b>${b.request_count} заявок · ${fmt(b.total_mass_kg)} кг</b></div><div><span>Источник</span><b>${esc(sourceText)}</b></div></div><div class="feo-placement-list"><table class="table"><thead><tr><th style="width:34%">ID заявки</th><th style="width:28%">Масса, кг</th><th style="width:38%">Состояние</th></tr></thead><tbody>${reqs.map(r=>`<tr><td>${esc(r.zayavka_id)}</td><td>${fmt(r.mass_kg)}</td><td>${r.missing?'Не найдена в основном реестре':'OK'}</td></tr>`).join('')}</tbody></table></div>`;
    if(actions){
      let html='';
      if(b.source_file_key)html+=`<a class="btn btn-secondary" href="${base}/blocks/${b.id}/download">Скачать исходный файл</a>`;
      if(b.status==='applied')html+=`<button type="button" class="btn btn-primary" data-placement-toggle>Отключить блок</button>`;
      else html+=`<button type="button" class="btn btn-primary" data-placement-toggle>Включить блок</button><button type="button" class="btn btn-danger" data-placement-delete>Удалить блок</button>`;
      actions.innerHTML=html;
      q('[data-placement-toggle]',actions)?.addEventListener('click',()=>previewStatus(b.id,b.status==='applied'?'disabled':'applied'));
      q('[data-placement-delete]',actions)?.addEventListener('click',()=>previewDelete(b.id));
    }
  }catch(e){
    if(err){renderMessage(err,e.message);err.classList.remove('is-hidden');}
  }
}

async function previewStatus(id,target){
  const err=q('[data-placement-detail-error]');
  try{
    const fd=new FormData();
    fd.append('_csrf',csrf);
    fd.append('target_status',target);
    const data=await json(base+'/blocks/'+id+'/preview-status',{method:'POST',body:fd});
    closeModal('feo-placement-detail-modal');
    confirmOperation('status',data.token,data.preview,{});
  }catch(e){
    if(err){renderMessage(err,e.message);err.classList.remove('is-hidden');}
  }
}

async function previewDelete(id){
  const err=q('[data-placement-detail-error]');
  try{
    const fd=new FormData();
    fd.append('_csrf',csrf);
    const data=await json(base+'/blocks/'+id+'/preview-delete',{method:'POST',body:fd});
    closeModal('feo-placement-detail-modal');
    confirmOperation('delete',data.token,data.preview,{});
  }catch(e){
    if(err){renderMessage(err,e.message);err.classList.remove('is-hidden');}
  }
}

q('[data-placement-reconcile]')?.addEventListener('click',async()=>{
  try{
    const fd=new FormData();
    fd.append('_csrf',csrf);
    const data=await json(base+'/reconcile/preview',{method:'POST',body:fd});
    confirmOperation('reconcile',data.token,data.preview,{});
  }catch(e){
    location.reload();
  }
});

q('[data-placement-open-current]')?.addEventListener('click',async()=>{
  const body=q('[data-placement-current-body]');
  const err=q('[data-placement-current-error]');
  if(err)err.classList.add('is-hidden');
  if(body)body.innerHTML='Загрузка…';
  openModal('feo-placement-current-modal');
  try{
    const data=await json(base+'/current');
    if(body)body.innerHTML=`<div class="feo-placement-detail-meta"><div><span>Актуальный состав</span><b>${data.summary.request_count} заявок</b></div><div><span>Общая масса</span><b>${fmt(data.summary.total_mass_kg)} кг</b></div></div><table class="table"><thead><tr><th>ID заявки</th><th>Масса, кг</th><th>Регион</th><th>МО</th><th>Адрес погрузки</th><th>Блок</th></tr></thead><tbody>${(data.rows||[]).map(r=>`<tr><td>${esc(r.zayavka_id)}</td><td>${fmt(r.mass_kg)}</td><td>${esc(r.mno_region||'—')}</td><td>${esc(r.mno_mo||'—')}</td><td>${esc(r.mno_adres_pogruzki||'—')}</td><td>#${esc(r.source_block_id)}</td></tr>`).join('')}</tbody></table>`;
  }catch(e){
    if(err){renderMessage(err,e.message);err.classList.remove('is-hidden');}
  }
});

const blockSearchInput=q('[data-placement-block-search]');
const blockSearchResults=q('[data-placement-block-search-results]');
let blockSearch={timer:0,generation:0,controller:null};

function clearBlockSearch(){
  if(blockSearchResults){
    blockSearchResults.classList.add('is-hidden');
    blockSearchResults.innerHTML='';
  }
}
function renderBlockSearch(data){
  if(!blockSearchResults)return;
  const results=Array.isArray(data.results)?data.results:[];
  const rows=[];
  results.forEach(item=>{
    const blocks=Array.isArray(item.blocks)?item.blocks:[];
    if(blocks.length){
      blocks.forEach((block,index)=>{
        rows.push(`<tr data-placement-search-block-id="${block.id}"><td>${index===0?`<b>${esc(item.zayavka_id)}</b>`:''}</td><td>#${block.id}${block.name?' · '+esc(block.name):''}</td><td>${block.block_type==='addition'?'Добавление':'Исключение'}</td><td>${statusHtml(block.status)}</td><td>${item.is_current?'<span class="feo-placement-status feo-placement-status--applied">В актуальном составе</span>':'—'}</td></tr>`);
      });
    }else{
      rows.push(`<tr><td><b>${esc(item.zayavka_id)}</b></td><td>Не входит ни в один блок</td><td>—</td><td>—</td><td>${item.exists_in_feo?(item.is_current?'В актуальном составе':'Не в актуальном составе'):'Нет в реестре ФЭО'}</td></tr>`);
    }
  });
  const duplicateNote=Array.isArray(data.duplicates)&&data.duplicates.length?` Повторы во вводе удалены: ${esc(data.duplicates.join(', '))}.`:'';
  blockSearchResults.innerHTML=`<div class="feo-placement-search-summary">Найдено по ${results.length} заявкам.${duplicateNote} Двойной клик по строке откроет блок.</div><table class="table feo-placement-search-table"><thead><tr><th style="width:14%">Заявка</th><th style="width:32%">Блок</th><th style="width:16%">Тип</th><th style="width:18%">Статус блока</th><th style="width:20%">Актуальный состав</th></tr></thead><tbody>${rows.join('')}</tbody></table>`;
  blockSearchResults.classList.remove('is-hidden');
  qa('[data-placement-search-block-id]',blockSearchResults).forEach(row=>row.addEventListener('dblclick',()=>openBlock(row.dataset.placementSearchBlockId)));
}
async function runBlockSearch(){
  if(!blockSearchInput)return;
  const raw=blockSearchInput.value;
  if(!raw.trim()){clearBlockSearch();return;}
  const generation=++blockSearch.generation;
  if(blockSearch.controller)blockSearch.controller.abort();
  blockSearch.controller=new AbortController();
  try{
    const data=await json(base+'/search-blocks?'+new URLSearchParams({request_ids:raw}).toString(),{signal:blockSearch.controller.signal});
    if(generation!==blockSearch.generation)return;
    renderBlockSearch(data);
  }catch(e){
    if(e.name==='AbortError'||generation!==blockSearch.generation)return;
    if(blockSearchResults){
      blockSearchResults.classList.remove('is-hidden');
      blockSearchResults.innerHTML='';
      const notice=document.createElement('div');
      notice.className='notice warn';
      renderMessage(notice,e.message);
      blockSearchResults.appendChild(notice);
    }
  }
}
function scheduleBlockSearch(immediate=false){
  clearTimeout(blockSearch.timer);
  blockSearch.timer=window.setTimeout(runBlockSearch,immediate?0:280);
}
blockSearchInput?.addEventListener('input',()=>scheduleBlockSearch());
blockSearchInput?.addEventListener('paste',ev=>{
  const pasted=ev.clipboardData?.getData('text/plain')||'';
  const ids=extractClientRequestIds(pasted);
  if(ids.length){
    ev.preventDefault();
    blockSearchInput.value=ids.join(' ');
    scheduleBlockSearch(true);
  }
});
blockSearchInput?.addEventListener('keydown',ev=>{
  if(ev.key==='Enter'){
    ev.preventDefault();
    scheduleBlockSearch(true);
  }
});

})();
