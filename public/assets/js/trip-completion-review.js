(function () {
  'use strict';
  const root = document.querySelector('[data-logistics-assistant]');
  const dialog = document.querySelector('.lab-review-dialog');
  if (!root || !dialog) return;
  const reviewerMode = root.dataset.reviewerMode === '1';

  const search = document.querySelector('.logistics-filter input[name="q"]');
  const showAll = document.querySelector('.lab-show-all input');
  const reviewFilters = document.querySelector('[data-review-filters]');
  const reviewTabStrip = document.querySelector('[data-review-tabs]');
  const reviewTabs = [...document.querySelectorAll('[data-review-tab]')];
  const foundCount = reviewFilters?.querySelector('[data-review-found]');
  const resetFilter = reviewFilters?.querySelector('[data-review-reset]');
  let activeTab = 'all';
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
    const tabCounts = {all: 0, work: 0, review: 0, archive: 0};
    root.querySelectorAll('[data-review-group]').forEach((group) => {
      const enabled = reviewerMode
        ? activeTab === 'all' || group.dataset.reviewGroup === activeTab
        : group.dataset.reviewGroup === 'work' || !!showAll?.checked;
      let matches = 0;
      group.querySelectorAll('.logistics-trip').forEach((trip) => {
        const found = !term || trip.textContent.toLowerCase().includes(term);
        trip.hidden = !enabled || !found;
        if (found) matches++;
      });
      group.hidden = !enabled || (term !== '' && matches === 0);
      if (enabled) visible += matches;
      if (reviewerMode) {
        tabCounts[group.dataset.reviewGroup] = matches;
        tabCounts.all += matches;
      }
    });
    root.querySelector('.lab-review-empty').hidden = visible > 0;
    if (reviewerMode) {
      foundCount.textContent = String(visible);
      reviewTabs.forEach((button) => { button.querySelector('b').textContent = String(tabCounts[button.dataset.reviewTab]); });
      resetFilter.hidden = !term && activeTab === 'all';
    }
  };
  root.querySelectorAll('.logistics-trip[data-review-status]').forEach((trip) => {
    if (trip.dataset.reviewStatus === (reviewerMode ? 'review' : 'work')) return;
    trip.querySelectorAll('[data-autosave-form] input, [data-autosave-form] textarea, [data-upload-form] input, [data-upload-form] button, .lab-docs-remove, .lab-docs-no-tn').forEach((control) => { control.disabled = true; });
  });
  search?.addEventListener('input', refreshFilter);
  reviewFilters?.addEventListener('submit', (event) => event.preventDefault());
  showAll?.addEventListener('change', refreshFilter);
  const selectTab = (tab) => {
    activeTab = tab;
    reviewTabs.forEach((button) => {
      button.classList.toggle('is-active', button.dataset.reviewTab === tab);
      button.setAttribute('aria-pressed', String(button.dataset.reviewTab === tab));
    });
    refreshFilter();
    const activeButton = reviewTabs.find((button) => button.dataset.reviewTab === activeTab);
    const strip = reviewTabStrip.getBoundingClientRect();
    const active = activeButton.getBoundingClientRect();
    if (active.left < strip.left) reviewTabStrip.scrollLeft += active.left - strip.left;
    else if (active.right > strip.right) reviewTabStrip.scrollLeft += active.right - strip.right;
  };
  reviewTabs.forEach((button) => button.addEventListener('click', () => selectTab(activeTab === button.dataset.reviewTab && activeTab !== 'all' ? 'all' : button.dataset.reviewTab)));
  resetFilter?.addEventListener('click', () => {
    search.value = '';
    selectTab('all');
    search.focus();
  });
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
    if (action === 'restore' || action === 'return_work') {
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
      if (preview.status !== (reviewerMode ? 'review' : 'work')) throw new Error('Статус рейса изменился. Обновите страницу.');
      pending = {form, trip, preview, action};
      title.textContent = `${trip.querySelector('.lab-trip-identity b')?.textContent || 'Рейс'} · ${reviewerMode ? 'Перенос в архив' : 'Передача на проверку'}`;
      const issues = preview.problems || [];
      const list = issues.map((issue) => `<li><span>${escape(issue.label)}</span><b>${issue.ids.length}</b><small>${escape(issue.ids.join(', '))}</small></li>`).join('');
      if (reviewerMode) {
        body.innerHTML = `<p class="lab-review-intro">${issues.length ? 'В рейсе остаются замечания:' : 'Замечаний нет.'}</p>${issues.length ? `<ul class="lab-review-problems">${list}</ul>` : ''}<p class="lab-review-warning">После переноса в архив редактирование будет закрыто. При необходимости рейс можно вернуть на проверку.</p>`;
        confirm.disabled = false;
        confirm.textContent = 'Переместить в архив';
      } else {
        body.innerHTML = `<p class="lab-review-intro">${issues.length ? 'Найдены ошибки и недостающие данные:' : 'Ошибок не найдено. Рейс будет передан на проверку.'}</p>${issues.length ? `<ul class="lab-review-problems">${list}</ul><p class="lab-review-warning">Убедитесь, что всю доступную информацию внесли. Если это окончательные данные, передайте рейс на проверку.</p>` : ''}<p class="lab-review-mail">${preview.sender_ready ? `Отправитель: <b>${escape(preview.sender_email)}</b>. ` : ''}Письмо с таблицей заявок и ссылкой на комплект получат ${preview.recipient_count} адресатов.</p>${!preview.sender_ready ? `<p class="lab-review-warning">${escape(preview.sender_error || 'Настройте основной рабочий ящик в карточке пользователя.')}</p>` : ''}`;
        confirm.disabled = !preview.sender_ready || preview.recipient_count === 0;
        confirm.textContent = 'Всё верно, передать';
      }
      dialog.showModal();
    } catch (error) { window.alert(error.message); }
    finally { busy = false; button.disabled = false; }
  });

  confirm.addEventListener('click', async () => {
    if (!pending || busy) return;
    busy = true;
    const {form, trip, preview, action} = pending;
    confirm.disabled = true;
    cancel.disabled = true;
    dialog.querySelector('.lab-review-x').disabled = true;
    const progress = document.createElement('p');
    progress.className = 'lab-review-processing';
    progress.setAttribute('role', 'status');
    progress.textContent = 'Формируем отчёт…';
    body.append(progress);
    try {
      if (action === 'archive') {
        progress.textContent = 'Переносим рейс в архив…';
        const data = new FormData(form);
        data.set('version', String(preview.version));
        await responseJson(await fetch(form.getAttribute('action'), {method:'POST',credentials:'same-origin',headers:{Accept:'application/json'},body:data}));
        window.location.reload();
        return;
      }
      if (!window.TripCompletionReport?.buildPackage) throw new Error('Генератор комплекта недоступен. Обновите страницу.');
      const built = await window.TripCompletionReport.buildPackage(trip, {
        stage(index) { progress.textContent = ['Формируем отчёт…','Создаём PDF файлы…','Создаём архив…'][index] || 'Подготавливаем комплект…'; },
        pdf(done, total) { if (total) progress.textContent = `Создаём PDF файлы: ${done} из ${total}`; },
      });
      const stageUrl = form.getAttribute('action').replace(/\/review$/, '/package-stage');
      const csrf = form.querySelector('[name="_csrf_token"]')?.value || '';
      const init = new FormData(form);
      init.set('version', String(preview.version));
      init.set('snapshot_hash', preview.snapshot_hash);
      progress.textContent = 'Подготавливаем защищённую ссылку…';
      const staged = await responseJson(await fetch(stageUrl, {method:'POST', credentials:'same-origin', headers:{Accept:'application/json'}, body:init}));
      const chunkSize = 4 * 1024 * 1024;
      for (let offset = 0; offset < built.bytes.length; offset += chunkSize) {
        const chunk = built.bytes.subarray(offset, Math.min(offset + chunkSize, built.bytes.length));
        const uploaded = await responseJson(await fetch(stageUrl, {method:'POST', credentials:'same-origin',
          headers:{Accept:'application/json', 'Content-Type':'application/octet-stream', 'X-CSRF-Token':csrf,
            'X-Planex-Upload':staged.upload_token, 'X-Planex-Offset':String(offset)}, body:chunk}));
        if (uploaded.size !== offset + chunk.length) throw new Error('Комплект загрузился не полностью. Повторите попытку.');
        progress.textContent = `Загружаем комплект: ${Math.round(100 * uploaded.size / built.bytes.length)}%`;
      }
      progress.textContent = 'Отправляем письмо со ссылкой…';
      const data = new FormData(form);
      data.set('version', String(preview.version));
      data.set('snapshot_hash', preview.snapshot_hash);
      data.set('package_token', staged.upload_token);
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
