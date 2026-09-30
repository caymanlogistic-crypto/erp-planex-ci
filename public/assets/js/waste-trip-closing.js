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
  page.querySelectorAll('.closing-upload-form input[type=file]').forEach(input=>input.addEventListener('change',()=>{
    if(input.files?.length) input.form.requestSubmit();
  }));
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
