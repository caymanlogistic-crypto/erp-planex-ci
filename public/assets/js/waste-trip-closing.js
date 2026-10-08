(() => {
  const page=document.querySelector('.closing-page');
  if (!page) return;
  const trips=[...page.querySelectorAll('[data-closing-trip]')];
  const search=page.querySelector('[data-closing-search]');
  const filters=[...page.querySelectorAll('[data-closing-filter]')];
  const count=page.querySelector('[data-closing-count]');
  const empty=page.querySelector('[data-closing-empty]');
  let filter='all';
  function render() {
    const query=search.value.trim().toLocaleLowerCase('ru');
    let visible=0;
    for (const trip of trips) {
      const state=trip.dataset.state;
      const matches=filter==='all'||(filter==='review'?state==='review':state==='new'||state==='partial');
      trip.hidden=!(matches&&trip.dataset.search.includes(query));
      if (!trip.hidden) visible++;
    }
    count.textContent=String(visible);
    empty.hidden=visible>0;
  }
  search.addEventListener('input',render);
  filters.forEach(button=>button.addEventListener('click',()=>{
    filter=button.dataset.closingFilter;
    filters.forEach(item=>{const active=item===button;item.classList.toggle('is-active',active);item.setAttribute('aria-pressed',String(active));});
    render();
  }));
  page.addEventListener('toggle',event=>{
    const opened=event.target;
    if (!opened.matches?.('[data-closing-trip][open]')) return;
    trips.forEach(item=>{if(item!==opened)item.open=false;});
  },true);
  page.addEventListener('change',event=>{
    const input=event.target;
    if (input.matches?.('.closing-upload-form input[type=file]')&&input.files?.length) input.form.requestSubmit();
  });
  page.addEventListener('submit',async event=>{
    const uploadForm=event.target;
    if (!uploadForm.matches?.('.closing-upload-form,.closing-extra-upload,.closing-remove-form')) return;
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
    form.action=`${window.getErpBasePath()}/company/trips/closing-documents/${encodeURIComponent(button.dataset.tripId)}/review`;
    form.querySelector('[data-review-version]').value=button.dataset.version;
    dialog.showModal();
  });
  dialog.querySelectorAll('[data-review-close]').forEach(button=>button.addEventListener('click',()=>dialog.close()));
  dialog.addEventListener('click',event=>{if(event.target===dialog)dialog.close();});
})();
