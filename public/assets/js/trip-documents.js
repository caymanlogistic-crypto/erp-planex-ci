(() => {
    'use strict';
    const root=document.querySelector('[data-document-page]'),form=root?.querySelector('[data-document-request]');
    const taxModal=root?.querySelector('#document-tax-modal');
    root?.querySelector('[data-document-tax-open]')?.addEventListener('click',()=>{taxModal.hidden=false;window.openModal(taxModal.id);taxModal.querySelector('[name=tax_system]').focus();});
    taxModal?.querySelectorAll('[data-document-tax-close]').forEach(button=>button.addEventListener('click',()=>{window.closeModal(taxModal.id);taxModal.hidden=true;}));
    const resolveModal=root?.querySelector('#document-resolve-modal');
    root?.querySelectorAll('[data-document-resolve]').forEach(button=>button.addEventListener('click',()=>{resolveModal.querySelector('form').reset();resolveModal.querySelector('[name=mail_id]').value=button.dataset.documentResolve;resolveModal.hidden=false;window.openModal(resolveModal.id);}));
    resolveModal?.querySelectorAll('[data-document-resolve-close]').forEach(button=>button.addEventListener('click',()=>{window.closeModal(resolveModal.id);resolveModal.hidden=true;}));
    root?.querySelectorAll('[data-document-editor]').forEach(link=>link.addEventListener('click',event=>{
        const editor=window.ModalShell?.get(link.dataset.documentEditor);
        if(editor){event.preventDefault();editor.loadEdit(link.dataset.entityId);}
    }));
    root?.querySelectorAll('[data-document-compose]').forEach(compose=>{
        const input=compose.querySelector('form'),preview=compose.querySelector('[data-compose-preview]'),error=compose.querySelector('[data-action-error]'),send=compose.querySelector('[data-compose-send]');
        let token=null,busy=false,revision=0;
        input.addEventListener('input',()=>{revision++;token=null;preview.hidden=true;});
        const post=async(url,data)=>{
            const response=await fetch(url,{method:'POST',body:data,headers:{Accept:'application/json'}}),result=await response.json();
            if(!response.ok||!result.ok)throw new Error(result.message||'Операция не завершена.');return result;
        };
        input.addEventListener('submit',async event=>{
            event.preventDefault();if(busy)return;busy=true;error.hidden=true;const current=revision,button=input.querySelector('[type=submit]');button.disabled=true;
            try {
                const result=await post(input.action,new FormData(input));
                if(current!==revision)throw new Error('Форма изменилась. Проверьте письмо ещё раз.');
                token=result.token;const mail=result.snapshot.message,dl=preview.querySelector('dl');dl.replaceChildren();
                for(const [label,value] of [['От',mail.from],['Кому',mail.to.join(', ')],['Копия',mail.cc.join(', ')],['Тема',mail.subject]]) {
                    const dt=document.createElement('dt'),dd=document.createElement('dd');dt.textContent=label;dd.textContent=value;dl.append(dt,dd);
                }
                preview.querySelector('pre').textContent=mail.text;
                const files=preview.querySelector('[data-compose-files]');files.replaceChildren();
                for(const file of mail.attachments||[]){const li=document.createElement('li');li.textContent=file.name+' · '+Math.ceil(file.size/1024)+' КБ';files.append(li);}
                preview.hidden=false;send.focus();
            }catch(e){error.textContent=e.message;error.hidden=false;}finally{busy=false;button.disabled=false;}
        });
        send.addEventListener('click',async()=>{
            if(busy||!token)return;busy=true;send.disabled=true;error.hidden=true;
            try {const data=new FormData(input);data.set('token',token);const result=await post(compose.dataset.sendUrl,data);window.location.assign(result.url);}
            catch(e){error.textContent=e.message;error.hidden=false;}finally{busy=false;send.disabled=false;}
        });
    });
    root?.querySelectorAll('[data-document-action]').forEach(actionForm=>{
        actionForm.addEventListener('submit',async event=>{
            event.preventDefault();
            const button=actionForm.querySelector('[type=submit]'),message=actionForm.querySelector('[data-action-error]');
            if(button.disabled)return;button.disabled=true;message.hidden=true;
            try {
                const data=new FormData(actionForm);
                if(data.has('target')) {const [type,id]=String(data.get('target')).split(':');data.set('entity_type',type);data.set('entity_id',id);}
                const response=await fetch(actionForm.action,{method:'POST',body:data,headers:{Accept:'application/json'}});
                const result=await response.json();if(!response.ok||!result.ok)throw new Error(result.message||'Операция не завершена.');
                window.location.reload();
            } catch(error) {message.textContent=error.message;message.hidden=false;} finally {button.disabled=false;}
        });
    });
    if (!form) return;
    const base=root.dataset.requestBase,preview=root.querySelector('[data-document-preview]'),error=root.querySelector('[data-document-error]');
    const send=root.querySelector('[data-document-send]');
    let token=null,busy=false,contacts=[],tripGeneration=0,revision=0;
    const showError=message=>{error.textContent=message;error.hidden=false;};
    const invalidate=()=>{revision++;token=null;preview.hidden=true;};
    async function api(path,body) {
        const headers={'Accept':'application/json'};
        const options=body?{method:'POST',body,headers}:{headers};
        const response=await fetch(base+path,options);
        const result=await response.json();
        if (!response.ok || !result.ok) throw new Error(result.message||'Не удалось выполнить операцию.');
        return result;
    }
    form.addEventListener('input',invalidate);
    form.elements.has_promise.addEventListener('change',()=>{
        const active=form.elements.has_promise.checked;
        root.querySelector('[data-promise]').hidden=!active;
        root.querySelector('[data-no-promise]').hidden=active;
        form.elements.promised_at.required=active;
    });
    form.elements.trip.addEventListener('change',async()=>{
        const generation=++tripGeneration;invalidate();error.hidden=true;
        const [type,id]=form.elements.trip.value.split(':');
        contacts=[];root.querySelector('[data-contact-choice]').hidden=true;
        if (!id) return;
        try {
            const trip=await api('/trip?type='+encodeURIComponent(type)+'&id='+encodeURIComponent(id));
            if (generation!==tripGeneration) return;
            root.querySelector('[data-trip-summary]').textContent='Ответственный Л: '+trip.responsible+' · Загрузка: '+(trip.loading_dates||'не указана');
            contacts=trip.contacts;
            const select=root.querySelector('#dc-contact');select.replaceChildren(new Option('Ввести контакт',''));
            contacts.forEach((contact,i)=>select.add(new Option([contact.contact_person,contact.email].filter(Boolean).join(' · '),String(i))));
            root.querySelector('[data-contact-choice]').hidden=!contacts.length;
            if (contacts.length) {select.value='0';fillContact(contacts[0]);}
        } catch (e) {if(generation===tripGeneration)showError(e.message);}
    });
    function fillContact(contact) {
        if (!contact) return;
        form.elements.contact_name.value=contact.contact_person||'';
        form.elements.contact_email.value=contact.email||'';
        form.elements.contact_phone.value=contact.phone||'';
        invalidate();
    }
    root.querySelector('#dc-contact').addEventListener('change',e=>{if(e.target.value!=='')fillContact(contacts[Number(e.target.value)]);});
    form.addEventListener('submit',async e=>{
        e.preventDefault();if(busy)return;busy=true;error.hidden=true;
        const submit=form.querySelector('[type=submit]');submit.disabled=true;const previewRevision=revision;
        try {
            const data=new FormData(form),[type,id]=String(data.get('trip')).split(':');data.set('trip_type',type);data.set('trip_id',id);
            const result=await api('/preview',data);
            if(previewRevision!==revision)throw new Error('Форма изменилась. Проверьте письмо ещё раз.');
            token=result.token;
            const message=result.snapshot.message,dl=root.querySelector('[data-envelope]');dl.replaceChildren();
            for(const [label,value] of [['От',message.from],['Кому',message.to.join(', ')],['Копия',message.cc.join(', ')],['Тема',message.subject]]) {
                const dt=document.createElement('dt'),dd=document.createElement('dd');dt.textContent=label;dd.textContent=value;dl.append(dt,dd);
            }
            root.querySelector('[data-letter]').textContent=message.text;preview.hidden=false;send.focus();
        } catch(e) {showError(e.message);} finally {busy=false;submit.disabled=false;}
    });
    send.addEventListener('click',async()=>{
        if(busy||!token)return;busy=true;send.disabled=true;error.hidden=true;
        try {const data=new FormData(form);data.set('token',token);const result=await api('/send',data);window.location.assign(result.url);}
        catch(e) {showError(e.message);}finally {busy=false;send.disabled=false;}
    });
    root.querySelector('[data-document-edit]').addEventListener('click',()=>{invalidate();form.elements.legal_entity_id.focus();});
})();
