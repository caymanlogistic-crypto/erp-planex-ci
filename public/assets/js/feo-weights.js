(() => {
  'use strict';
  const root = document.querySelector('[data-feo-weights]');
  if (!root) return;
  const find = selector => root.querySelector(selector);
  const form = find('[data-weight-form]');
  if (!form) return;
  const createDialog = find('[data-create-dialog]');
  const detailDialog = find('[data-detail-dialog]');
  const idsInput = find('#weight-ids');
  const section = find('[data-edit-section]');
  const editorRows = find('[data-edit-rows]');
  const commit = find('[data-commit]');
  const detailBody = find('[data-detail-body]');
  const detailTitle = find('#weight-detail-title');
  const detailRevert = find('[data-detail-revert]');
  const confirmRevert = find('[data-confirm-revert]');
  let previewToken = '';
  let rows = [];
  let revision = 0;
  let previewTimer = 0;
  let saving = false;
  let detailBatch = null;
  let detailItems = [];

  const escape = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
  const kg = tonnes => tonnes === null || tonnes === '' ? '' : (Number(tonnes) * 1000).toFixed(1).replace(/\.0$/, '');
  const format = new Intl.NumberFormat('ru-RU', {maximumFractionDigits:1});
  const weight = tonnes => tonnes === null || tonnes === ''
    ? '<span class="registry-ui-missing">—</span>'
    : `${escape(format.format(Number(tonnes) * 1000))} кг`;
  const csrf = () => form.querySelector('[name="_csrf_token"]').value;
  const setError = (selector, message) => {
    const box = find(selector);
    box.textContent = message;
    box.hidden = !message;
  };
  const pageMessage = (message, warning = false) => {
    const box = find('[data-page-message]');
    box.textContent = message;
    box.className = `form-alert ${warning ? 'alert-warning' : 'alert-success'}`;
    box.hidden = false;
  };
  const normalizePreviewError = message => /^Заявки не найдены:/u.test(message)
    ? message.replace(/^Заявки не найдены:/u, 'Заявки не найдены в системе:').replace(/ Блок не сохранён\.$/u, '')
    : message;
  async function request(path, data = null) {
    const options = {headers:{Accept:'application/json','X-Requested-With':'XMLHttpRequest'},credentials:'same-origin'};
    if (data) { data.set('_csrf_token', csrf()); options.method = 'POST'; options.body = data; }
    const response = await fetch(`${root.dataset.base}${path}`, options);
    let result;
    try { result = await response.json(); }
    catch { throw new Error('Сервер не ответил. Обновите страницу и проверьте историю блоков.'); }
    if (!response.ok || !result.success) throw new Error(result.message || 'Не удалось выполнить действие.');
    return result;
  }
  const parseIds = raw => {
    if (raw.length > 100000) return {error:'Слишком большой список заявок.'};
    if (!raw.trim()) return {error:'Введите номера заявок.'};
    const matches = [];
    for (const line of raw.split(/\r\n|\r|\n/u)) {
      let cell = line.trim();
      if (line.includes('\t')) cell = line.split('\t',1)[0].trim();
      else if (line.includes('|')) cell = line.trim().replace(/^\|+|\|+$/g,'').split('|',1)[0].trim();
      if (cell) matches.push(...(cell.match(/(?<!\d)\d{6}(?!\d)/gu) || []));
    }
    if (!matches.length) return {error:'Не найдено самостоятельных шестизначных номеров заявок.'};
    const ids = [...new Set(matches)];
    if (ids.length > 1000) return {error:'В одном блоке допускается до 1000 заявок.'};
    return {ids,matches};
  };
  function invalidate() {
    revision++;
    previewToken = '';
    rows = [];
    section.hidden = true;
    commit.disabled = true;
    setError('[data-ids-error]','');
    setError('[data-table-error]','');
  }
  function editorHtml(row) {
    const id = escape(row.zayavka_id);
    return `<tr data-request-id="${id}"><td class="registry-ui-entity-id">${id}</td><td>${weight(row.mass_netto)}</td><td><input class="field-input" name="netto_kg" inputmode="decimal" aria-label="Новое нетто заявки ${id}, кг" value="${escape(kg(row.mass_netto))}"></td><td>${weight(row.mass_brutto)}</td><td><input class="field-input" name="brutto_kg" inputmode="decimal" aria-label="Новое брутто заявки ${id}, кг" value="${escape(kg(row.mass_brutto))}"></td></tr>`;
  }
  function renderPreview(result, atRevision) {
    if (atRevision !== revision) return;
    rows = result.preview.rows;
    previewToken = result.preview_token;
    const duplicateCount = Number(result.duplicates) || 0;
    find('[data-edit-summary]').textContent = `Заявок: ${rows.length}.${duplicateCount ? ` Повторов объединено: ${duplicateCount}.` : ''}`;
    editorRows.innerHTML = rows.map(editorHtml).join('');
    section.hidden = false;
    commit.disabled = false;
  }
  async function fetchPreview(atRevision, rawIds) {
    try {
      const data = new FormData(); data.set('request_ids', rawIds);
      renderPreview(await request('/preview',data), atRevision);
    } catch (error) {
      if (atRevision === revision) setError('[data-ids-error]',normalizePreviewError(error.message));
    }
  }
  function schedulePreview() {
    clearTimeout(previewTimer);
    invalidate();
    const parsed = parseIds(idsInput.value);
    if (!idsInput.value.trim()) return;
    const atRevision = revision;
    if (parsed.error) {
      previewTimer = setTimeout(() => { if (atRevision === revision) setError('[data-ids-error]',parsed.error); },250);
      return;
    }
    previewTimer = setTimeout(() => fetchPreview(atRevision,idsInput.value),250);
  }
  idsInput.addEventListener('input',schedulePreview);
  idsInput.addEventListener('paste',event => {
    const pasted = event.clipboardData?.getData('text/plain');
    if (pasted === undefined) return;
    event.preventDefault();
    const start=idsInput.selectionStart,end=idsInput.selectionEnd;
    const before=idsInput.value.slice(0,start),after=idsInput.value.slice(end);
    const candidate=before+(before && !/[,\s|]$/u.test(before) ? ', ' : '')+pasted+
      (after && !/^[,\s|]/u.test(after) ? ', ' : '')+after;
    const parsed=parseIds(candidate);
    idsInput.value=parsed.error ? candidate : parsed.matches.join(', ');
    idsInput.dispatchEvent(new Event('input',{bubbles:true}));
  });
  editorRows.addEventListener('input',() => setError('[data-table-error]',''));
  form.addEventListener('submit',event => event.preventDefault());
  root.querySelectorAll('[data-open-weight]').forEach(button => button.addEventListener('click',() => {
    createDialog.showModal(); idsInput.focus(); schedulePreview();
  }));
  root.querySelectorAll('[data-close-dialog]').forEach(button => button.addEventListener('click',() => button.closest('dialog').close()));
  function completed(result, actionName) {
    const warning = result.notification === 'failed' || result.notification === 'sending';
    const suffix = warning ? ' Уведомление МАКС не отправлено или результат неизвестен; проверьте журнал событий.' : result.notification === 'disabled' ? ' Уведомление МАКС отключено в настройках события.' : result.notification === 'sent' ? ' Уведомление МАКС отправлено.' : '';
    sessionStorage.setItem('feo-weight-result',JSON.stringify({message:`Блок #${result.batch_id} ${actionName}.${suffix}`,warning}));
    location.reload();
  }
  function sameKg(input, tonnes) {
    const normalized=input.trim().replace(/\s/g,'').replace(',','.');
    const actual=normalized === '' ? null : (Number(normalized)/1000).toFixed(4);
    const original=tonnes === null || tonnes === '' ? null : Number(tonnes).toFixed(4);
    return actual === original;
  }
  commit.addEventListener('click',async () => {
    if (!previewToken || commit.disabled || saving) return;
    const targets={}; let changed=false;
    for (const tr of editorRows.querySelectorAll('tr')) {
      const id=tr.dataset.requestId;
      const row=rows.find(item=>String(item.zayavka_id)===id);
      const netto=tr.querySelector('[name="netto_kg"]').value.trim();
      const brutto=tr.querySelector('[name="brutto_kg"]').value.trim();
      for (const [value,input] of [[netto,tr.querySelector('[name="netto_kg"]')],[brutto,tr.querySelector('[name="brutto_kg"]')]]) {
        if (value && !/^\d{1,13}(?:[.,]\d)?$/.test(value.replace(/\s/g,''))) {
          setError('[data-table-error]',`Заявка №${id}: укажите неотрицательный вес с точностью до 0,1 кг.`);
          input.focus(); return;
        }
      }
      targets[id]={netto_kg:netto,brutto_kg:brutto};
      if (!sameKg(netto,row.mass_netto) || !sameKg(brutto,row.mass_brutto)) changed=true;
    }
    if (!changed) { setError('[data-table-error]','Вес не изменён ни у одной заявки.'); return; }
    saving=true; commit.disabled=true; setError('[data-table-error]','');
    try {
      const data=new FormData();
      data.set('preview_token',previewToken); data.set('targets',JSON.stringify(targets));
      data.set('name',''); data.set('comment','');
      completed(await request('/create',data),'сохранён');
    } catch(error) { setError('[data-table-error]',error.message); saving=false; commit.disabled=false; }
  });
  const detailHtml = () => `<div class="field"><span class="field-label">Заявки</span><p class="registry-ui-modal-text">${detailItems.map(item=>`<strong class="registry-ui-entity-id">${escape(item.zayavka_id)}</strong>`).join(', ')}</p></div><section class="registry-ui-modal-preview"><div class="registry-ui-modal-summary">Заявок: ${detailItems.length}.</div><div class="table-scroll registry-ui-compact-table-scroll"><table class="table registry-ui-table registry-ui-compact-table"><thead><tr><th>Заявка</th><th>Нетто было</th><th>Нетто стало</th><th>Брутто было</th><th>Брутто стало</th></tr></thead><tbody>${detailItems.map(item=>`<tr><td class="registry-ui-entity-id">${escape(item.zayavka_id)}</td><td>${weight(item.before_netto)}</td><td>${weight(item.after_netto)}</td><td>${weight(item.before_brutto)}</td><td>${weight(item.after_brutto)}</td></tr>`).join('')}</tbody></table></div></section>`;
  function showDetail() {
    detailTitle.textContent=`Изменение веса №${detailBatch.id}`;
    detailBody.innerHTML=detailHtml();
    detailRevert.hidden=detailBatch.status!=='applied';
    confirmRevert.hidden=true;
    if (!detailDialog.open) detailDialog.showModal();
  }
  function showConfirm() {
    detailTitle.textContent=`Изменение веса №${detailBatch.id}`;
    detailBody.innerHTML=`<div class="registry-ui-modal-confirm"><p class="registry-ui-modal-text">Заявки ${detailItems.map(item=>`<strong class="registry-ui-entity-id">${escape(item.zayavka_id)}</strong>`).join(', ')} вернутся к прежним значениям веса нетто и брутто.</p><p class="registry-ui-modal-text">Если вес хотя бы одной заявки изменился позднее, отмена всего блока остановится. История сохранится.</p></div><div class="field-msg is-error" data-revert-error role="alert" hidden></div>`;
    detailRevert.hidden=true;
    confirmRevert.hidden=false;
    if (!detailDialog.open) detailDialog.showModal();
  }
  async function loadDetail(id, confirm=false) {
    const result=await request(`/${id}/json`);
    detailBatch=result.batch; detailItems=result.items;
    if (confirm && detailBatch.status==='applied') showConfirm(); else showDetail();
  }
  root.querySelectorAll('[data-detail]').forEach(button => button.addEventListener('click',async () => {
    if (button.disabled) return; button.disabled=true;
    try { await loadDetail(button.dataset.detail); }
    catch(error) { pageMessage(error.message,true); }
    finally { button.disabled=false; }
  }));
  root.querySelectorAll('[data-revert]').forEach(button => button.addEventListener('click',async () => {
    if (button.disabled) return; button.disabled=true;
    try { await loadDetail(button.dataset.revert,true); }
    catch(error) { pageMessage(error.message,true); }
    finally { button.disabled=false; }
  }));
  detailRevert.addEventListener('click',showConfirm);
  confirmRevert.addEventListener('click',async () => {
    if (confirmRevert.disabled || !detailBatch) return;
    confirmRevert.disabled=true; setError('[data-revert-error]','');
    try { completed(await request(`/${detailBatch.id}/revert`,new FormData()),'возвращён'); }
    catch(error) { setError('[data-revert-error]',error.message); confirmRevert.disabled=false; }
  });
  find('[data-history-search]')?.addEventListener('input',event => {
    const query=event.target.value.trim().toLocaleLowerCase('ru'); let found=0;
    root.querySelectorAll('[data-batch-row]').forEach(row => {
      const full=row.querySelector('[data-registry-full-text]')?.dataset.registryFullText||'';
      row.hidden=!(row.textContent+' '+full).toLocaleLowerCase('ru').includes(query);
      if (!row.hidden) found++;
    });
    find('[data-history-count]').textContent=found;
  });
  const saved=sessionStorage.getItem('feo-weight-result');
  if (saved) { sessionStorage.removeItem('feo-weight-result'); try { const result=JSON.parse(saved); pageMessage(result.message,result.warning); } catch {} }
})();
