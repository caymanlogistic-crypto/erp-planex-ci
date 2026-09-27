(function () {
  'use strict';
  const root = document.querySelector('[data-logistics-assistant]');
  const dialog = document.querySelector('.lab-review-dialog');
  if (!root || !dialog) return;

  const search = document.querySelector('.logistics-filter input[name="q"]');
  const showAll = document.querySelector('.lab-show-all input');
  const body = dialog.querySelector('.lab-review-dialog-body');
  const confirm = dialog.querySelector('.lab-review-confirm');
  const cancel = dialog.querySelector('.lab-review-cancel');
  const title = dialog.querySelector('#lab-review-title');
  let pending = null;
  let busy = false;

  const escape = (value) => String(value).replace(/[&<>"']/g, (char) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
  const responseJson = async (response) => {
    let data;
    try { data = await response.json(); } catch (_) { throw new Error('Сервер вернул некорректный ответ.'); }
    if (!response.ok || !data.ok) throw new Error(data.message || 'Не удалось выполнить действие.');
    return data;
  };
  const refreshFilter = () => {
    const term = (search?.value || '').trim().toLowerCase();
    let visible = 0;
    root.querySelectorAll('[data-review-group]').forEach((group) => {
      const enabled = group.dataset.reviewGroup === 'work' || !!showAll?.checked;
      let matches = 0;
      group.querySelectorAll('.logistics-trip').forEach((trip) => {
        const found = !term || trip.textContent.toLowerCase().includes(term);
        trip.hidden = !enabled || !found;
        if (enabled && found) matches++;
      });
      group.hidden = !enabled || (term !== '' && matches === 0);
      visible += matches;
    });
    root.querySelector('.lab-review-empty').hidden = visible > 0;
  };
  root.querySelectorAll('.logistics-trip[data-review-status]:not([data-review-status="work"])').forEach((trip) => {
    trip.querySelectorAll('[data-autosave-form] input, [data-autosave-form] textarea, [data-upload-form] input, [data-upload-form] button, .lab-docs-remove, .lab-docs-no-tn').forEach((control) => { control.disabled = true; });
  });
  search?.addEventListener('input', refreshFilter);
  showAll?.addEventListener('change', refreshFilter);
  refreshFilter();

  const close = () => { if (!busy) dialog.close(); };
  cancel.addEventListener('click', close);
  dialog.querySelector('.lab-review-x').addEventListener('click', close);
  dialog.addEventListener('cancel', (event) => { if (busy) event.preventDefault(); });

  root.addEventListener('submit', async (event) => {
    const form = event.target.closest('[data-review-form]');
    if (!form) return;
    event.preventDefault();
    if (busy) return;
    const trip = form.closest('.logistics-trip');
    const action = form.querySelector('[name="action"]').value;
    if (action === 'return') {
      busy = true;
      const button = form.querySelector('button');
      button.disabled = true;
      try {
        await responseJson(await fetch(form.getAttribute('action'), {method:'POST', credentials:'same-origin', headers:{Accept:'application/json'}, body:new FormData(form)}));
        window.location.reload();
      } catch (error) {
        button.disabled = false;
        window.alert(error.message);
      } finally { busy = false; }
      return;
    }
    if (trip.querySelector('.is-saving, .is-save-error')) {
      window.alert('Дождитесь сохранения данных заявки и исправьте ошибки сохранения перед передачей рейса.');
      return;
    }
    busy = true;
    const button = form.querySelector('button');
    button.disabled = true;
    try {
      const preview = await responseJson(await fetch(form.getAttribute('action'), {credentials:'same-origin', headers:{Accept:'application/json'}}));
      if (preview.status !== 'work') throw new Error('Рейс уже передан. Обновите страницу.');
      pending = {form, trip, preview};
      title.textContent = `${trip.querySelector('.lab-trip-identity b')?.textContent || 'Рейс'} · Передача на проверку`;
      const issues = preview.problems || [];
      const list = issues.map((issue) => `<li><span>${escape(issue.label)}</span><b>${issue.ids.length}</b><small>${escape(issue.ids.join(', '))}</small></li>`).join('');
      body.innerHTML = `<p class="lab-review-intro">${issues.length ? 'Найдены ошибки и недостающие данные:' : 'Ошибок не найдено. Рейс будет помещён в архив.'}</p>${issues.length ? `<ul class="lab-review-problems">${list}</ul><p class="lab-review-warning">Убедитесь, что всю доступную информацию внесли. Если это окончательные данные, передайте рейс на проверку.</p>` : ''}<p class="lab-review-mail">${preview.sender_ready ? `Отправитель: <b>${escape(preview.sender_email)}</b>. ` : ''}Комплект будет отправлен ${preview.recipient_count} получателям.</p>${!preview.sender_ready ? `<p class="lab-review-warning">${escape(preview.sender_error || 'Настройте основной рабочий ящик в карточке пользователя.')}</p>` : ''}`;
      confirm.disabled = !preview.sender_ready || preview.recipient_count === 0;
      confirm.textContent = issues.length ? 'Всё верно, передать' : 'Передать в архив';
      dialog.showModal();
    } catch (error) { window.alert(error.message); }
    finally { busy = false; button.disabled = false; }
  });

  confirm.addEventListener('click', async () => {
    if (!pending || busy) return;
    busy = true;
    const {form, trip, preview} = pending;
    confirm.disabled = true;
    cancel.disabled = true;
    dialog.querySelector('.lab-review-x').disabled = true;
    const progress = document.createElement('p');
    progress.className = 'lab-review-processing';
    progress.setAttribute('role', 'status');
    progress.textContent = 'Формируем отчёт…';
    body.append(progress);
    try {
      if (!window.TripCompletionReport?.buildPackage) throw new Error('Генератор комплекта недоступен. Обновите страницу.');
      const built = await window.TripCompletionReport.buildPackage(trip, {
        stage(index) { progress.textContent = ['Формируем отчёт…','Создаём PDF файлы…','Создаём архив…'][index] || 'Подготавливаем комплект…'; },
        pdf(done, total) { if (total) progress.textContent = `Создаём PDF файлы: ${done} из ${total}`; },
      });
      progress.textContent = 'Отправляем комплект получателям…';
      const data = new FormData(form);
      data.set('version', String(preview.version));
      data.set('snapshot_hash', preview.snapshot_hash);
      data.set('package', new File([built.bytes], built.name, {type:'application/zip'}));
      const result = await responseJson(await fetch(form.getAttribute('action'), {method:'POST',credentials:'same-origin',headers:{Accept:'application/json'},body:data}));
      if (result.mail_status !== 'accepted') {
        window.alert('Статус рейса изменён, но отправка письма не подтверждена. Проверьте рабочий ящик и настройки почты.');
        window.location.reload();
        return;
      }
      window.location.reload();
    } catch (error) {
      progress.textContent = error.message;
      progress.setAttribute('role','alert');
      cancel.textContent = 'Закрыть';
    } finally {
      busy = false;
      cancel.disabled = false;
      dialog.querySelector('.lab-review-x').disabled = false;
      pending = null;
    }
  });
})();
