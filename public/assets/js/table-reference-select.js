/* Native-backed dropdowns accepted for the finance and departures table toolbars. */
(() => {
  const pageKey = document.querySelector('.rp-action-design.rp-ledger-v015 > .table-toolbar') ? 'finance' : 'departures';
  const scope = pageKey === 'finance' ? '.rp-action-design.rp-ledger-v015 > .table-toolbar' : '.table-card[data-erp-grid] > .waste-trip-toolbar';
  const selects = [...new Set([...document.querySelectorAll(scope + ' .toolbar-select'), ...document.querySelectorAll('select[data-table-reference-select]')])];
  selects.forEach((native, selectIndex) => {
    if (native.closest('.lab-reference-select')) return;
    const wrap = document.createElement('span');
    wrap.className = 'lab-reference-select';
    wrap.dataset.name = native.name || 'sort';
    native.before(wrap); wrap.append(native);
    const button = document.createElement('button'); button.type='button'; button.className='lab-reference-trigger';
    button.setAttribute('aria-haspopup','listbox'); button.setAttribute('aria-expanded','false');
    const list = document.createElement('div'); list.className='lab-reference-list'; list.id='lab-'+(native.dataset.tableReferenceSelect||pageKey)+'-select-'+selectIndex;
    list.setAttribute('role','listbox'); list.setAttribute('aria-label',native.getAttribute('aria-label')||'Выбор значения');
    list.tabIndex=-1; list.hidden=true; button.setAttribute('aria-controls',list.id);
    const options=[...native.options]; let active=Math.max(0,native.selectedIndex);
    const items=options.map((choice,index)=>{const item=document.createElement('div');item.className='lab-reference-option';item.id=list.id+'-option-'+index;item.setAttribute('role','option');item.dataset.value=choice.value;item.textContent=choice.textContent.trim();item.title=item.textContent;list.append(item);return item;});
    const setActive=index=>{active=(index+items.length)%items.length;items.forEach((item,i)=>item.classList.toggle('is-active',i===active));list.setAttribute('aria-activedescendant',items[active].id);};
    const sync=()=>{const selected=Math.max(0,native.selectedIndex);button.textContent=options[selected].textContent.trim();button.title=button.textContent;button.setAttribute('aria-label',(native.getAttribute('aria-label')||'Выбор значения')+': '+button.textContent);items.forEach((item,i)=>item.setAttribute('aria-selected',String(i===selected)));setActive(selected);};
    const close=(focus=false)=>{list.hidden=true;button.setAttribute('aria-expanded','false');if(focus)button.focus();};
    const open=()=>{sync();list.hidden=false;button.setAttribute('aria-expanded','true');list.focus();};
    const choose=index=>{native.value=options[index].value;native.dispatchEvent(new Event('change',{bubbles:true}));sync();close(true);};
    button.addEventListener('click',()=>list.hidden?open():close(true));
    button.addEventListener('keydown',e=>{if(e.key==='ArrowDown'||e.key==='ArrowUp'){e.preventDefault();open();setActive(active+(e.key==='ArrowDown'?1:-1));}else if(e.key==='Enter'||e.key===' '){e.preventDefault();open();}});
    list.addEventListener('keydown',e=>{if(e.key==='ArrowDown'||e.key==='ArrowUp'){e.preventDefault();setActive(active+(e.key==='ArrowDown'?1:-1));}else if(e.key==='Home'||e.key==='End'){e.preventDefault();setActive(e.key==='Home'?0:items.length-1);}else if(e.key==='Enter'||e.key===' '){e.preventDefault();choose(active);}else if(e.key==='Escape'){e.preventDefault();close(true);}else if(e.key==='Tab'){close();}else if(e.key.length===1&&!e.ctrlKey&&!e.altKey&&!e.metaKey){const match=items.findIndex(x=>x.textContent.toLocaleLowerCase('ru').startsWith(e.key.toLocaleLowerCase('ru')));if(match>=0)setActive(match);}});
    list.addEventListener('click',e=>{const item=e.target.closest('.lab-reference-option');if(item)choose(items.indexOf(item));});
    items.forEach((item,i)=>item.addEventListener('pointerenter',()=>setActive(i)));
    document.addEventListener('pointerdown',e=>{if(!list.hidden&&!wrap.contains(e.target))close();});
    native.addEventListener('change',sync);wrap.append(button,list);wrap.classList.add('is-ready');sync();
  });
})();
