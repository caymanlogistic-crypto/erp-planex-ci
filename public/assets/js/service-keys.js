(() => {
  const root = document.querySelector('[data-service-keys]');
  const form = root?.querySelector('[data-key-form]');
  if (!form) return;
  const edit = root.querySelector('[data-key-edit]');
  const cancel = root.querySelector('[data-key-cancel]');
  const input = form.querySelector('[name=api_key]');
  const submit = form.querySelector('[type=submit]');
  const message = root.querySelector('[data-key-message]');
  const close = () => { form.reset(); form.hidden = true; edit.hidden = false; edit.focus(); };
  edit.addEventListener('click', () => { form.hidden = false; edit.hidden = true; message.hidden = true; input.focus(); });
  cancel.addEventListener('click', close);
  form.addEventListener('submit', async event => {
    event.preventDefault();
    if (submit.disabled) return;
    submit.disabled = true; cancel.disabled = true; submit.textContent = 'Сохранение…'; message.hidden = true;
    try {
      const response = await fetch(form.action, {method:'POST', body:new FormData(form), headers:{Accept:'application/json'}, credentials:'same-origin'});
      const result = await response.json();
      if (!response.ok || !result.success) throw new Error(result.message || 'Не удалось сохранить ключ. Попробуйте ещё раз.');
      const status = root.querySelector('[data-key-status]');
      status.className = 'badge badge-ok'; status.textContent = 'Ключ сохранён';
      cancel.hidden = false; close(); message.className = 'form-alert alert-success';
      root.querySelector('[data-key-message-text]').textContent = 'Ключ Яндекс Геокодера сохранён.';
    } catch (error) {
      message.className = 'form-alert alert-error';
      root.querySelector('[data-key-message-text]').textContent = error instanceof SyntaxError ? 'Сеанс завершён или сервер недоступен. Обновите страницу и попробуйте снова.' : error.message;
    } finally { submit.disabled = false; cancel.disabled = false; submit.textContent = 'Сохранить ключ'; message.hidden = false; }
  });
})();

(() => {
  const form=document.querySelector('[data-mail-settings-form]');
  if (!form) return;
  const button=form.querySelector('[type=submit]'), message=form.querySelector('[data-mail-settings-message]');
  form.addEventListener('submit',async event=>{
    event.preventDefault(); if(button.disabled) return;
    button.disabled=true; message.className='notice is-hidden';
    try {
      const response=await fetch(form.action,{method:'POST',body:new FormData(form),credentials:'same-origin',headers:{Accept:'application/json'}});
      const result=await response.json();
      if(!response.ok || !result.success) throw new Error(result.message||'Не удалось сохранить настройки почты.');
      message.className='notice success'; message.textContent=result.message;
      const status=document.querySelector('[data-mail-settings-status]');status.textContent='Настроено';status.className='badge badge-ok';
    } catch(error) {
      message.className='notice warn';message.textContent=error instanceof SyntaxError?'Сеанс завершён или сервер недоступен. Обновите страницу.':error.message;
    } finally {button.disabled=false;}
  });
})();
