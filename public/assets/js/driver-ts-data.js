(() => {
  'use strict';
  const list=document.querySelector('[data-driver-ts-list]');
  if(!list)return;
  const tbody=list.querySelector('tbody');
  const rows=[...tbody.querySelectorAll('[data-driver-ts-id]')];
  const search=list.querySelector('[data-driver-ts-search]');
  const reset=list.querySelector('[data-driver-ts-reset]');
  const sort=list.querySelector('[data-driver-ts-sort]');
  const count=list.querySelector('[data-driver-ts-count]');
  const dialog=document.querySelector('[data-driver-ts-dialog]');
  const content=dialog.querySelector('[data-driver-ts-detail]');
  const archive=dialog.querySelector('[data-driver-ts-archive]');
  let pending=null;
  let filePage=0;
  function updateFilePage(){
    const pager=content.querySelector('[data-driver-ts-file-pages]');
    if(!pager)return;
    const fileRows=[...content.querySelectorAll('[data-driver-ts-file-row]')];
    const total=Math.ceil(fileRows.length/3);
    fileRows.forEach((fileRow,index)=>{fileRow.hidden=Math.floor(index/3)!==filePage;});
    pager.querySelector('[data-driver-ts-page-label]').textContent=`${filePage+1} / ${total}`;
    pager.querySelector('[data-driver-ts-page-prev]').disabled=filePage===0;
    pager.querySelector('[data-driver-ts-page-next]').disabled=filePage===total-1;
  }
  const update=()=>{
    const query=search.value.trim().toLocaleLowerCase('ru');
    const ordered=[...rows].sort((a,b)=>sort.value==='alpha'
      ? a.dataset.sortAlpha.localeCompare(b.dataset.sortAlpha,'ru')
      : Number(b.dataset.sortDate)-Number(a.dataset.sortDate));
    let visible=0;
    ordered.forEach(row=>{row.classList.toggle('is-hidden',query!==''&&!row.textContent.toLocaleLowerCase('ru').includes(query));if(!row.classList.contains('is-hidden'))visible++;tbody.append(row);});
    count.textContent=String(visible);
    reset.hidden=query==='';
  };
  search.addEventListener('input',update);
  sort.addEventListener('change',update);
  reset.addEventListener('click',()=>{search.value='';update();search.focus();});
  async function open(row){
    const id=row.dataset.driverTsId;
    if(pending)pending.abort();
    pending=new AbortController();
    content.textContent='Загрузка...';
    archive.href=`${location.pathname}/${encodeURIComponent(id)}/archive`;
    dialog.showModal();
    dialog.querySelector('.modal-close').focus();
    try{
      const response=await fetch(`${location.pathname}/${encodeURIComponent(id)}/detail`,{credentials:'same-origin',signal:pending.signal});
      if(!response.ok)throw new Error('detail');
      content.innerHTML=await response.text();
      filePage=0;
      updateFilePage();
    }catch(error){if(error.name!=='AbortError')content.textContent='Не удалось загрузить данные водителя и ТС.';}
  }
  tbody.addEventListener('dblclick',event=>{const row=event.target.closest('[data-driver-ts-id]');if(row)open(row);});
  tbody.addEventListener('keydown',event=>{const row=event.target.closest('[data-driver-ts-id]');if(row&&event.key==='Enter'){event.preventDefault();open(row);}});
  dialog.querySelectorAll('[data-driver-ts-close]').forEach(button=>button.addEventListener('click',()=>dialog.close()));
  dialog.addEventListener('click',event=>{if(event.target===dialog)dialog.close();});
  dialog.addEventListener('close',()=>{if(pending)pending.abort();pending=null;content.textContent='';});
  content.addEventListener('click',event=>{
    const button=event.target.closest('[data-driver-ts-page-prev],[data-driver-ts-page-next]');
    if(!button||button.disabled)return;
    filePage+=button.hasAttribute('data-driver-ts-page-next')?1:-1;
    updateFilePage();
  });
  update();
})();
