(() => {
  const page=document.querySelector('.closing-page');
  if (!page) return;
  const trips=[...page.querySelectorAll('[data-closing-trip]')];
  const search=page.querySelector('[data-closing-search]');
  const entity=page.querySelector('[data-closing-entity]');
  const quarter=page.querySelector('[data-closing-quarter]');
  const manager=page.querySelector('[data-closing-manager]');
  const sort=page.querySelector('[data-closing-sort]');
  const filters=[...page.querySelectorAll('[data-closing-filter]')];
  const count=page.querySelector('[data-closing-count]');
  const listLabel=page.querySelector('[data-closing-list-label]');
  const empty=page.querySelector('[data-closing-empty]');
  const reportGrid=page.querySelector('.lab-grid');
  const exportLink=page.querySelector('[data-closing-report-download]');
  let reportRows=[...reportGrid?.querySelectorAll('[data-report-trip-id]')||[]];
  if(!search || !count || !empty) return;
  const stateKey=page.dataset.closingStateKey;
  let savedState=null;
  try { if(stateKey) savedState=JSON.parse(localStorage.getItem(stateKey)||'null'); } catch (_) {}
  let filter='all';
  const stage=new URLSearchParams(location.search).get('stage');
  if(['all','incomplete','ready','paid_incomplete','paid_complete'].includes(stage)) filter=stage;
  if(stage && filters.some(button=>button.dataset.closingFilter===stage)) {
    page.addEventListener('registry:state',()=>filters.find(button=>button.dataset.closingFilter===stage)?.click(),{once:true});
  }
  if(!filters.some(button=>button.dataset.closingFilter===filter)) filter='all';
  filters.forEach(button=>{const active=button.dataset.closingFilter===filter;button.classList.toggle('is-active',active);button.setAttribute('aria-pressed',String(active));});
  const tabs=page.querySelector('.closing-registry-tabs');
  const activeTab=filters.find(button=>button.dataset.closingFilter===filter);
  if(tabs&&activeTab) requestAnimationFrame(()=>{const delta=activeTab.getBoundingClientRect().right-tabs.getBoundingClientRect().right;if(delta>0)tabs.scrollLeft+=delta;});
  const requestedTripId=new URLSearchParams(location.search).get('trip_id');
  const requestedTrip=trips.find(trip=>trip.dataset.tripId===requestedTripId);
  if(requestedTrip) {
    search.value=requestedTrip.dataset.businessId||'';
    page.addEventListener('registry:state',()=>{
      // The shared registry restores saved controls after this script runs.
      // An explicit trip link takes precedence over those saved filters.
      if(entity) entity.value='all';
      if(quarter) quarter.value='';
      if(manager) manager.value='';
      filters.find(button=>button.dataset.closingFilter==='all')?.click();
      search.value=requestedTrip.dataset.businessId||'';
      search.dispatchEvent(new Event('input',{bubbles:true}));
    },{once:true});
  }
  const openTripId=requestedTripId||savedState?.openTripId;
  if(openTripId || (savedState && Object.hasOwn(savedState,'openTripId'))) {
    trips.forEach(trip=>{trip.open=trip.dataset.tripId===openTripId;});
  }
  function saveState() {
    if(!stateKey) return;
    try { localStorage.setItem(stateKey,JSON.stringify({openTripId:trips.find(trip=>trip.open)?.dataset.tripId||null})); } catch (_) {}
  }
  function sortReportRows(){
    const body=reportGrid?.querySelector('tbody');
    if(!body)return;
    reportRows.sort((left,right)=>{
      if(sort?.value==='unloading'){
        const leftDate=left.dataset.unloadingDate||'';
        const rightDate=right.dataset.unloadingDate||'';
        if(!leftDate && rightDate)return -1;
        if(leftDate && !rightDate)return 1;
        const dateOrder=rightDate.localeCompare(leftDate);
        if(dateOrder)return dateOrder;
      }
      return Number(right.cells[0].textContent.replace(/\D/g,''))-Number(left.cells[0].textContent.replace(/\D/g,''));
    });
    body.append(...reportRows);
  }
  function render() {
    const query=search.value.trim().toLocaleLowerCase('ru');
    const requestedIds=query.includes(',')?query.split(',').map(part=>part.trim()).filter(Boolean).map(part=>part.match(/^(?:рейс\s*)?[№#]?(\d+)$/iu)?.[1]):null;
    const idSearch=requestedIds?.length&&requestedIds.every(Boolean)?new Set(requestedIds):null;
    const tabCounts={all:0,incomplete:0,ready:0,paid_incomplete:0,paid_complete:0};
    let visible=0;
    for (const trip of trips) {
      const state=trip.dataset.state;
      const matchesEntity=!entity||entity.value==='all'||trip.dataset.entity===entity.value;
      const actualEnd=trip.dataset.actualEnd||'';
      const actualQuarter=/^(\d{4})-(0[1-9]|1[0-2])-\d{2}$/.test(actualEnd)
        ?`${actualEnd.slice(0,4)}-Q${Math.ceil(Number(actualEnd.slice(5,7))/3)}`:'';
      const matchesQuarter=!quarter||!quarter.value||actualQuarter===quarter.value;
      const matchesManager=!manager||!manager.value||trip.dataset.managerId===manager.value;
      const matchesSearch=requestedIds&&!idSearch?false:idSearch?idSearch.has(trip.dataset.businessId):trip.dataset.search.includes(query);
      if(matchesEntity&&matchesQuarter&&matchesManager&&matchesSearch){tabCounts.all++;if(Object.hasOwn(tabCounts,state))tabCounts[state]++;}
      const matches=filter==='all'||state===filter;
      trip.hidden=!(matchesSearch&&matches&&matchesEntity&&matchesQuarter&&matchesManager);
      if (!trip.hidden) visible++;
    }
    filters.forEach(button=>{const badge=button.querySelector('[data-closing-filter-count]');if(badge)badge.textContent=String(tabCounts[button.dataset.closingFilter]??0);});
    count.textContent=String(visible);
    if(listLabel) listLabel.textContent=filter==='all'?'РЕЙСЫ В РАБОТЕ И ЗАВЕРШЁННЫЕ':filters.find(button=>button.dataset.closingFilter===filter)?.textContent.trim().toLocaleUpperCase('ru');
    empty.hidden=visible>0;
    updateReport();
  }
  function updateReport(){
    if(!reportGrid)return;
    const ids=new Set(trips.filter(trip=>!trip.hidden).map(trip=>trip.dataset.tripId));
    let visible=0;
    reportRows.forEach(row=>{row.hidden=!ids.has(row.dataset.reportTripId);if(!row.hidden)visible++;});
    if(exportLink){
      const url=new URL(exportLink.href,location.href);
      url.searchParams.set('ids',reportRows.filter(row=>!row.hidden).map(row=>row.dataset.reportTripId).join(','));
      exportLink.href=url.toString();
      exportLink.toggleAttribute('aria-disabled',visible===0);
    }
  }
  sortReportRows();
  render();
  exportLink?.addEventListener('click',event=>{if(exportLink.getAttribute('aria-disabled')==='true')event.preventDefault();});
  const docDialog=page.querySelector('[data-closing-doc-dialog]');
  const sourceList=page.querySelector('.closing-source-list');
  let docOpener=null;
  let activeTrip=null;
  const openDocuments=row=>{
    const trip=trips.find(item=>item.dataset.tripId===row.dataset.reportTripId);
    if(!trip||!docDialog||!sourceList)return;
    docOpener=row;
    activeTrip=trip;
    trip.open=true;
    docDialog.querySelector('#closing-doc-title').textContent=`Закрывающие документы рейса №${trip.dataset.businessId}`;
    docDialog.querySelector('[data-closing-doc-body]').replaceChildren(trip);
    docDialog.showModal();
    docDialog.querySelector('[data-closing-doc-close]')?.focus();
    saveState();
  };
  reportGrid?.addEventListener('dblclick',event=>{const row=event.target.closest('tbody tr[data-report-trip-id]');if(row)openDocuments(row);});
  reportGrid?.addEventListener('keydown',event=>{
    if((event.key==='Enter'||event.key===' ')&&event.target.matches('tbody tr[data-report-trip-id]')){
      event.preventDefault();openDocuments(event.target);
    }
  });
  docDialog?.querySelectorAll('[data-closing-doc-close]').forEach(button=>button.addEventListener('click',()=>docDialog.close()));
  docDialog?.addEventListener('click',event=>{if(event.target===docDialog)docDialog.close();});
  docDialog?.addEventListener('close',()=>{
    if(activeTrip){sourceList.append(activeTrip);activeTrip.open=false;activeTrip=null;}
    saveState();docOpener?.focus();docOpener=null;
  });
  if(openTripId){const row=reportRows.find(item=>item.dataset.reportTripId===openTripId&&!item.hidden);if(row)openDocuments(row);}
  if((requestedTripId && trips.some(trip=>trip.dataset.tripId===requestedTripId)) || stage) saveState();
  if(stage){const url=new URL(location.href);url.searchParams.delete('stage');history.replaceState(history.state,'',url);}
  search.addEventListener('input',()=>{render();saveState();});
  entity?.addEventListener('change',()=>{render();saveState();});
  quarter?.addEventListener('change',()=>{render();saveState();});
  sort?.addEventListener('change',()=>{sortReportRows();updateReport();saveState();});
  manager?.addEventListener('change',()=>{render();saveState();});
  filters.forEach(button=>button.addEventListener('click',()=>{
    filter=button.dataset.closingFilter;
    filters.forEach(item=>{const active=item===button;item.classList.toggle('is-active',active);item.setAttribute('aria-pressed',String(active));});
    render();
    saveState();
  }));
  page.addEventListener('toggle',event=>{
    const opened=event.target;
    if (!opened.matches?.('[data-closing-trip]')) return;
    if(opened.open) trips.forEach(item=>{if(item!==opened)item.open=false;});
    saveState();
  },true);
  page.addEventListener('change',event=>{
    const input=event.target;
    if (input.matches?.('.closing-upload-form input[type=file]')&&input.files?.length) input.form.requestSubmit();
  });
  page.addEventListener('click',event=>{
    const link=event.target.closest?.('a[data-document-preview]');
    if(!link) return;
    event.preventDefault();
    window.open(link.href,`planex-document-${Date.now()}`,'popup=yes,width=1120,height=780,resizable=yes,scrollbars=yes');
  });
  page.addEventListener('submit',async event=>{
    const uploadForm=event.target;
    if (!uploadForm.matches?.('.closing-upload-form,.closing-extra-upload,.closing-remove-form,.closing-card-form')) return;
    event.preventDefault();
    if (uploadForm.dataset.updating==='1') return;
    const trip=uploadForm.closest('[data-closing-trip]');
    const feedback=trip.querySelector('.closing-upload-feedback');
    const scrollX=window.scrollX,scrollY=window.scrollY;
    feedback.hidden=true;
    uploadForm.dataset.updating='1';
    uploadForm.setAttribute('aria-busy','true');
    try {
      const response=await fetch(uploadForm.action,{
        method:'POST',body:new FormData(uploadForm),credentials:'same-origin',redirect:'follow'
      });
      if (!response.ok) throw new Error('Не удалось загрузить документ. Повторите попытку.');
      const result=new DOMParser().parseFromString(await response.text(),'text/html');
      const error=result.querySelector('.notice.warn[role="alert"]');
      if (error) throw new Error(error.textContent.trim());
      if (!result.querySelector('[data-closing-action-result="success"]')) throw new Error('Не удалось подтвердить изменение. Обновите страницу и проверьте документы.');
      const updated=[...result.querySelectorAll('[data-closing-trip]')].find(item=>item.dataset.tripId===trip.dataset.tripId);
      if (!updated) throw new Error('Не удалось обновить строку рейса. Обновите страницу.');
      trip.dataset.state=updated.dataset.state;
      trip.replaceChildren(...updated.children);
      const updatedReport=result.querySelector('.lab-grid tbody');
      const reportBody=reportGrid?.querySelector('tbody');
      if(updatedReport&&reportBody){
        const openerTripId=docOpener?.dataset.reportTripId;
        reportBody.replaceChildren(...[...updatedReport.children].map(row=>document.importNode(row,true)));
        reportRows=[...reportBody.querySelectorAll('[data-report-trip-id]')];
        sortReportRows();
        if(openerTripId)docOpener=reportRows.find(row=>row.dataset.reportTripId===openerTripId)||null;
      }
      render();
      window.scrollTo(scrollX,scrollY);
      requestAnimationFrame(()=>window.scrollTo(scrollX,scrollY));
    } catch (error) {
      feedback.textContent=error.message||'Не удалось изменить документы. Повторите попытку.';
      feedback.hidden=false;
      window.scrollTo(scrollX,scrollY);
    } finally {
      delete uploadForm.dataset.updating;
      uploadForm.removeAttribute('aria-busy');
    }
  });
  const invoiceDialog=document.querySelector('[data-invoice-dialog]');
  if(invoiceDialog){
    const invoiceForm=invoiceDialog.querySelector('form.invoice-form');
    let invoiceOpener=null;
    page.addEventListener('click',event=>{
      const button=event.target.closest('[data-invoice-open]');
      if(!button) return;
      invoiceOpener=button;
      invoiceForm.reset();
      invoiceForm.dataset.preferredTripId=button.dataset.businessId;
      const direction=invoiceForm.querySelector('.js-inv-direction');
      direction.value='INCOMING';
      direction.dispatchEvent(new Event('change',{bubbles:true}));
      const counterparty=invoiceForm.querySelector('.js-inv-cparty-select');
      counterparty.value=button.dataset.carrierId;
      counterparty.dispatchEvent(new Event('change',{bubbles:true}));
      invoiceDialog.showModal();
      invoiceForm.querySelector('[name=number]').focus();
    });
    invoiceDialog.querySelectorAll('[data-invoice-close],[data-close-modal]').forEach(button=>button.addEventListener('click',()=>invoiceDialog.close()));
    invoiceDialog.addEventListener('close',()=>invoiceOpener?.focus());
  }
  const picker=document.querySelector('[data-invoice-picker]');
  if(picker){
    const pickerForm=picker.querySelector('[data-invoice-picker-form]');
    const pickerOptions=picker.querySelector('[data-invoice-picker-options]');
    const pickerError=picker.querySelector('.closing-picker-error');
    let pickerOpener=null;
    page.addEventListener('click',async event=>{
      const button=event.target.closest('[data-invoice-choose]');
      if(!button) return;
      pickerOpener=button;
      pickerForm.action=`${window.getErpBasePath()}/company/logistics/closing-documents/${encodeURIComponent(button.dataset.tripId)}/invoices/link`;
      pickerOptions.textContent='Загрузка…';pickerError.hidden=true;
      picker.showModal();
      try{
        const response=await fetch(`${window.getErpBasePath()}/company/logistics/closing-documents/${encodeURIComponent(button.dataset.tripId)}/invoices`,{credentials:'same-origin'});
        const result=await response.json();
        if(!response.ok) throw new Error(result.error||'Не удалось загрузить счета.');
        pickerOptions.replaceChildren();
        if(!result.items.length){pickerOptions.textContent='Доступных счетов нет.';return;}
        result.items.forEach((item,index)=>{
          const label=document.createElement('label');label.className='closing-picker-option';
          const input=document.createElement('input');input.type='radio';input.name='invoice_id';input.value=item.id;input.checked=index===0;input.required=true;
          const info=document.createElement('span');const title=document.createElement('b');title.textContent=`Счёт №${item.number}`;
          const paid=Number(item.paid_amount||0),amount=Number(item.amount||0);
          const payment=paid>=amount&&amount>0?' · Оплачен · оплата перейдёт на рейс':paid>0?' · Оплачен частично · оплата перейдёт на рейс':'';
          const meta=document.createElement('small');meta.textContent=`${item.invoice_date} · ${item.amount} ₽${payment}`;
          info.append(title,meta);label.append(input,info);pickerOptions.append(label);
        });
        pickerOptions.querySelector('input')?.focus();
      }catch(error){pickerOptions.textContent='';pickerError.textContent=error.message;pickerError.hidden=false;}
    });
    picker.querySelectorAll('[data-invoice-picker-close]').forEach(button=>button.addEventListener('click',()=>picker.close()));
    picker.addEventListener('close',()=>pickerOpener?.focus());
    pickerForm.addEventListener('submit',async event=>{
      event.preventDefault();pickerError.hidden=true;
      const submit=pickerForm.querySelector('[type=submit]');submit.disabled=true;
      try{
        const response=await fetch(pickerForm.action,{method:'POST',body:new FormData(pickerForm),credentials:'same-origin'});
        const result=await response.json();
        if(!response.ok||!result.ok) throw new Error(result.error||'Не удалось связать счёт.');
        location.href=`${window.getErpBasePath()}/company/logistics/closing-documents?trip_id=${encodeURIComponent(pickerOpener.dataset.tripId)}`;
      }catch(error){pickerError.textContent=error.message;pickerError.hidden=false;submit.disabled=false;}
    });
  }
  const editDialog=document.querySelector('[data-invoice-edit-dialog]');
  if(editDialog){
    const editBody=editDialog.querySelector('[data-invoice-edit-body]');
    let editOpener=null;
    const setForm=html=>{
      editBody.innerHTML=html;
      editBody.querySelectorAll('script').forEach(old=>{const script=document.createElement('script');script.textContent=old.textContent;old.replaceWith(script);});
      const form=editBody.querySelector('form.invoice-form');
      if(form){const hidden=document.createElement('input');hidden.type='hidden';hidden.name='return_to';hidden.value=`/company/logistics/closing-documents?trip_id=${editOpener.closest('[data-closing-trip]').dataset.tripId}`;form.append(hidden);}
    };
    page.addEventListener('click',async event=>{
      const button=event.target.closest('[data-invoice-edit]');if(!button)return;
      editOpener=button;editBody.textContent='Загрузка…';editDialog.showModal();
      try{
        const response=await fetch(`${window.getErpBasePath()}/company/finance/invoices/${encodeURIComponent(button.dataset.invoiceId)}/modal-edit`,{credentials:'same-origin'});
        if(!response.ok)throw new Error('Не удалось загрузить счёт.');
        setForm(await response.text());
      }catch(error){editBody.textContent=error.message;}
    });
    editDialog.querySelectorAll('[data-invoice-edit-close]').forEach(button=>button.addEventListener('click',()=>editDialog.close()));
    editDialog.addEventListener('close',()=>editOpener?.focus());
    editBody.addEventListener('click',event=>{if(event.target.closest('[data-close-modal]'))editDialog.close();});
    editBody.addEventListener('submit',async event=>{
      const form=event.target.closest('form.invoice-form');if(!form)return;
      event.preventDefault();
      try{
        const response=await fetch(form.action,{method:'POST',body:new FormData(form),credentials:'same-origin'});
        if(response.redirected){location.href=response.url;return;}
        setForm(await response.text());
      }catch(error){const alert=document.createElement('div');alert.className='form-alert alert-error';alert.textContent=error.message;form.prepend(alert);}
    });
  }
  const dialog=document.querySelector('[data-review-dialog]');
  if (!dialog) return;
  const form=dialog.querySelector('[data-review-form]');
  page.addEventListener('click',event=>{
    const button=event.target.closest('[data-review-open]');
    if (!button) return;
    const number=button.dataset.businessId;
    dialog.querySelector('#closing-review-title').textContent=`Передать рейс #${number} на проверку`;
    dialog.querySelector('[data-review-missing]').textContent=button.dataset.missing?
      `В комплекте не хватает документов: ${button.dataset.missing}. Проверяющий увидит этот список.`:'Комплект документов собран полностью.';
    dialog.querySelector('[data-review-recipient]').textContent=`Кому: ${button.dataset.recipient}`;
    dialog.querySelector('[data-review-subject]').textContent=`Тема: Рейс #${number} — закрывающие документы на проверку`;
    dialog.querySelector('.closing-review-archive').firstChild.textContent=`Архив документов рейса #${number} `;
    form.action=`${window.getErpBasePath()}/company/logistics/closing-documents/${encodeURIComponent(button.dataset.tripId)}/review`;
    form.querySelector('[data-review-version]').value=button.dataset.version;
    dialog.showModal();
  });
  dialog.querySelectorAll('[data-review-close]').forEach(button=>button.addEventListener('click',()=>dialog.close()));
  dialog.addEventListener('click',event=>{if(event.target===dialog)dialog.close();});
})();
