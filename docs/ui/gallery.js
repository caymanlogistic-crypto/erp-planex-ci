/* Size the preview viewport; component appearance/behavior belongs to shared assets. */
(() => {
  const root = document.querySelector('[data-specimen-root]');
  if (root && window.parent !== window) {
    root.querySelectorAll('[data-gallery-view]').forEach(view=>{
      const rows=[...view.querySelectorAll('[data-gallery-row]')],body=view.querySelector('tbody');
      const apply=()=>{
        const value=key=>view.querySelector(`[data-registry-field="${key}"]`)?.value;
        const query=(value('query')||'').toLocaleLowerCase('ru');
        const attention=view.querySelector('[data-registry-tab="attention"]')?.getAttribute('aria-pressed')==='true'||value('status')==='attention';
        const showComplete=view.querySelector('[data-registry-field="showComplete"]');
        rows.forEach(row=>{
          const full=[...row.querySelectorAll('[data-registry-full-text]')].map(item=>item.dataset.registryFullText).join(' ');
          row.hidden=!(row.textContent+' '+full).toLocaleLowerCase('ru').includes(query)||(attention&&row.dataset.attention!=='true')||(showComplete&&!showComplete.checked&&row.dataset.complete==='true');
        });
        const count=rows.filter(row=>!row.hidden).length;
        view.querySelector('[data-gallery-count]').textContent=String(count);
        view.querySelector('[data-gallery-empty]').hidden=count!==0;
        view.querySelectorAll('[data-gallery-total]').forEach(cell=>{
          const index=Number(cell.dataset.galleryTotal);
          const cents=rows.filter(row=>!row.hidden).reduce((total,row)=>{
            const raw=row.children[index].textContent.replace(/[\s₽]/g,'').replace(',','.');
            return total+(/^\d+(?:\.\d{1,2})?$/.test(raw)?Math.round(Number(raw)*100):0);
          },0);
          cell.textContent=new Intl.NumberFormat('ru-RU',{minimumFractionDigits:cents%100?2:0,maximumFractionDigits:2}).format(cents/100)+' ₽';
        });
        view.querySelectorAll('[data-gallery-total-count]').forEach(cell=>{cell.textContent='Итого · '+count;});
        const sorted=value('sort')==='name'?[...rows].sort((a,b)=>a.textContent.localeCompare(b.textContent,'ru')):rows;
        sorted.forEach(row=>body.append(row));body.append(view.querySelector('[data-gallery-empty]'));
      };
      view.querySelectorAll('[data-registry-tab]').forEach(button=>button.addEventListener('click',()=>{
        view.querySelectorAll('[data-registry-tab]').forEach(item=>item.setAttribute('aria-pressed',String(item===button)));apply();
      }));
      view.querySelectorAll('[data-registry-field]').forEach(field=>['input','change'].forEach(type=>field.addEventListener(type,apply)));
      view.querySelectorAll('[data-gallery-action]').forEach(button=>button.addEventListener('click',()=>{view.querySelector('[data-gallery-notice]').hidden=false;}));
      apply();
    });
    // Existing executor menu geometry; this does not approve a new dialog/menu.
    document.querySelector('.route-review-action-delete')?.classList.add('registry-ui-delete');
    document.addEventListener('click',event=>{
      if(!event.target.closest('.route-review-action-delete'))return;
      event.preventDefault();event.stopImmediatePropagation();
      const view=root.querySelector('[data-gallery-view=executors]');view.querySelector('[data-gallery-notice]').hidden=false;
    },true);
    let previous = 0;
    const report = () => {
      const floatingBottom = [...document.querySelectorAll('[role="listbox"],[role="menu"]')].reduce((bottom, element) => Math.max(bottom, element.getBoundingClientRect().bottom + 12), 0);
      const height = Math.ceil(Math.max(root.getBoundingClientRect().height, floatingBottom));
      if (height !== previous) {
        previous = height;
        window.parent.postMessage({type: 'planex-ui-preview-height', height}, '*');
      }
    };
    new ResizeObserver(report).observe(root);
    new MutationObserver(() => requestAnimationFrame(report)).observe(root,{subtree:true,attributes:true,childList:true});
    document.fonts.ready.then(report);
    window.addEventListener('load', report);
    window.addEventListener('message',event=>{
      if(event.source!==window.parent||event.data?.type!=='planex-ui-scene')return;
      const target=[...root.querySelectorAll('[data-gallery-view]')].find(view=>view.dataset.galleryView===event.data.scene);
      if(!target)return;
      root.querySelectorAll('[data-gallery-view]').forEach(view=>{view.hidden=view!==target;});
      window.dispatchEvent(new Event('resize'));requestAnimationFrame(report);
    });
    return;
  }
  document.querySelectorAll('[data-gallery-scene]').forEach(button=>button.addEventListener('click',()=>{
    document.querySelectorAll('[data-gallery-scene]').forEach(item=>item.setAttribute('aria-pressed',String(item===button)));
    document.querySelector('iframe[data-preview]')?.contentWindow.postMessage({type:'planex-ui-scene',scene:button.dataset.galleryScene},'*');
  }));
  window.addEventListener('message', event => {
    if (event.data?.type !== 'planex-ui-preview-height') return;
    const frame = [...document.querySelectorAll('iframe[data-preview]')].find(item => item.contentWindow === event.source);
    const height = event.data.height;
    if (frame && !frame.hasAttribute('data-fixed-preview') && Number.isFinite(height) && height > 0 && height < 10000) frame.style.height = `${Math.max(100, height)}px`;
  });
})();
