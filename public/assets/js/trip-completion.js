(function(){
  'use strict';
  const root=document.querySelector('[data-logistics-assistant]');
  if(!root)return;
  const json=async(response)=>{
    let data={};
    try{data=await response.json();}catch(_){data={ok:false,message:'Сервер вернул некорректный ответ.'};}
    if(!response.ok||data.ok===false)throw new Error(data.message||'Не удалось сохранить данные.');
    return data;
  };
  const state=(row,kind,label)=>{
    const box=row.querySelector('[data-save-state]'),text=box&&box.querySelector('[data-save-label]');
    if(!box||!text)return;
    box.classList.toggle('is-saving',kind==='saving');
    box.classList.toggle('is-error',kind==='error');
    text.textContent=label;
  };
  const csrfData=(row)=>{
    const source=row.querySelector('input[type="hidden"][name]');
    const body=new FormData();
    if(source)body.append(source.name,source.value);
    return body;
  };
  const renderDocuments=(row,request)=>{
    const list=row.querySelector('[data-document-list]');
    if(!list)return;
    list.replaceChildren();
    (request.documents||[]).forEach(document=>{
      const wrapper=window.document.createElement('span');
      wrapper.className='logistics-document';
      wrapper.dataset.documentId=String(document.id);
      const link=window.document.createElement('a');
      link.href=document.download_url;
      link.title=document.download_name;
      link.textContent=document.download_name;
      const remove=window.document.createElement('button');
      remove.type='button';
      remove.className='logistics-document-delete';
      remove.title='Удалить файл';
      remove.dataset.deleteUrl=document.delete_url;
      remove.textContent='×';
      wrapper.append(link,remove);
      list.append(wrapper);
    });
  };
  const update=(row,data)=>{
    const request=data.request||{},trip=data.trip||{};
    row.classList.toggle('is-complete',!!request.is_complete);
    row.classList.toggle('is-problem',!request.is_complete);
    const issueBox=row.querySelector('[data-request-issues]');
    if(issueBox){
      issueBox.replaceChildren();
      (request.issues||[]).forEach(issue=>{const el=document.createElement('small');el.textContent=issue;issueBox.append(el);});
    }
    renderDocuments(row,request);
    state(row,'saved',request.is_complete?'Собрано':'Сохранено');
    const tripBox=row.closest('[data-trip-id]');
    if(tripBox){
      const progress=tripBox.querySelector('[data-trip-progress]');
      if(progress)progress.textContent=String(trip.complete_count||0)+' / '+String(trip.request_count||0);
      const documentCount=tripBox.querySelector('[data-trip-document-count]');
      if(documentCount)documentCount.textContent=String(trip.document_count||0);
      const issues=tripBox.querySelector('[data-trip-issues]');
      if(issues){
        issues.replaceChildren();
        const badge=document.createElement('span'),missing=trip.missing_summary||[];
        badge.className='badge '+(missing.length?'badge-danger':'badge-ok');
        badge.textContent=missing.length?missing.join(' · '):'Собрано';
        issues.append(badge);
      }
    }
  };
  const post=async(url,body)=>json(await fetch(url,{method:'POST',credentials:'same-origin',headers:{Accept:'application/json','X-Requested-With':'XMLHttpRequest'},body}));
  const formatInterval=value=>{
    const digits=value.replace(/\D/g,'').slice(0,14),parts=[];
    if(digits.length)parts.push(digits.slice(0,2));
    if(digits.length>2)parts.push('.'+digits.slice(2,4));
    if(digits.length>4)parts.push('.'+digits.slice(4,6));
    if(digits.length>6)parts.push(' '+digits.slice(6,8));
    if(digits.length>8)parts.push(':'+digits.slice(8,10));
    if(digits.length>10)parts.push(' - '+digits.slice(10,12));
    if(digits.length>12)parts.push(':'+digits.slice(12,14));
    return parts.join('');
  };

  root.querySelectorAll('[data-autosave-form]').forEach(form=>{
    const row=form.closest('[data-request-row]');
    let timer=0,saving=false,dirty=false;
    const flush=async()=>{
      if(saving){dirty=true;return;}
      saving=true;dirty=false;state(row,'saving','Сохранение…');
      try{update(row,await post(form.action,new FormData(form)));}
      catch(error){state(row,'error',error.message);}
      finally{saving=false;if(dirty)flush();}
    };
    const schedule=delay=>{dirty=true;window.clearTimeout(timer);timer=window.setTimeout(flush,delay);};
    form.addEventListener('submit',event=>{event.preventDefault();schedule(0);});
    form.addEventListener('change',()=>schedule(120));
    const interval=form.elements.pickup_interval;
    if(interval){
      interval.addEventListener('beforeinput',event=>{
        if(event.inputType.startsWith('insert')&&event.data!==null&&/\D/.test(event.data))event.preventDefault();
      });
      interval.addEventListener('paste',event=>{
        const value=(event.clipboardData&&event.clipboardData.getData('text'))||'';
        if(/\D/.test(value))event.preventDefault();
      });
      interval.addEventListener('input',()=>{
        const digitOffset=(interval.value.slice(0,interval.selectionStart||0).match(/\d/g)||[]).length;
        interval.value=formatInterval(interval.value);
        let caret=interval.value.length;
        if(digitOffset>0){
          let seen=0;
          for(let index=0;index<interval.value.length;index++){
            if(/\d/.test(interval.value[index]))seen++;
            if(seen===digitOffset){caret=index+1;break;}
          }
        }
        interval.setSelectionRange(caret,caret);
        const digitCount=(interval.value.match(/\d/g)||[]).length;
        if(digitCount===0||digitCount===14)schedule(350);
      });
    }
    const comment=form.elements.comment;
    if(comment)comment.addEventListener('input',()=>schedule(550));
  });

  root.querySelectorAll('[data-upload-form]').forEach(form=>{
    const input=form.querySelector('input[type="file"]'),row=form.closest('[data-request-row]');
    if(!input)return;
    input.addEventListener('change',async()=>{
      if(!input.files||input.files.length===0)return;
      state(row,'saving','Загрузка…');
      try{update(row,await post(form.action,new FormData(form)));input.value='';}
      catch(error){state(row,'error',error.message);}
    });
  });

  root.addEventListener('click',async event=>{
    const button=event.target.closest('[data-delete-url]');
    if(!button)return;
    if(!window.confirm('Удалить файл из комплекта?'))return;
    const row=button.closest('[data-request-row]');
    state(row,'saving','Удаление…');
    try{update(row,await post(button.dataset.deleteUrl,csrfData(row)));}
    catch(error){state(row,'error',error.message);}
  });

  root.querySelectorAll('.logistics-trip').forEach(trip=>{
    trip.addEventListener('toggle',()=>{
      if(!trip.open)return;
      root.querySelectorAll('.logistics-trip[open]').forEach(other=>{if(other!==trip)other.open=false;});
    });
  });
})();
