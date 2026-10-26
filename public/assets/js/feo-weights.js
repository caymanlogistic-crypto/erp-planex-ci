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
  const detailDelete = find('[data-delete-returned]');
  const confirmDelete = find('[data-confirm-delete-returned]');
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
  const totalWeight = kilograms => kilograms === ''
    ? '<span class="registry-ui-missing">—</span>'
    : `${escape(format.format(Number(kilograms)))} кг`;
  const csrf = () => form.querySelector('[name="_csrf_token"]').value;
  const setError = (selector, message) => {
    const box = find(selector);
    box.textContent = message;
    box.hidden = !message;
  };
  const pageMessage = (message, tone = 'success') => {
    window.PlanexNotifications.show({tone, message});
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
  const parseId = raw => {
    const value = raw.trim();
    if (/^\d{6}$/u.test(value)) return {id:value};
    if ((value.match(/(?<!\d)\d{6}(?!\d)/gu) || []).length > 1) return {error:'Укажите одну заявку.'};
    return {error:'Введите шестизначный номер заявки.'};
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
    return `<tr><td class="registry-ui-primary">Нетто</td><td>${weight(row.mass_netto)}</td><td><input class="field-input" name="netto_kg" inputmode="decimal" aria-label="Новое нетто заявки ${id}, кг" value="${escape(kg(row.mass_netto))}"></td></tr><tr><td class="registry-ui-primary">Брутто</td><td>${weight(row.mass_brutto)}</td><td><input class="field-input" name="brutto_kg" inputmode="decimal" aria-label="Новое брутто заявки ${id}, кг" value="${escape(kg(row.mass_brutto))}"></td></tr>`;
  }
  function updateCommitState() {
    if (!previewToken || rows.length !== 1 || saving) { commit.disabled = true; return; }
    const row = rows[0];
    const netto = editorRows.querySelector('[name="netto_kg"]')?.value ?? '';
    const brutto = editorRows.querySelector('[name="brutto_kg"]')?.value ?? '';
    commit.disabled = sameKg(netto,row.mass_netto) && sameKg(brutto,row.mass_brutto);
  }
  function renderPreview(result, atRevision) {
    if (atRevision !== revision) return;
    if (!Array.isArray(result.preview?.rows) || result.preview.rows.length !== 1) {
      setError('[data-ids-error]','Укажите одну заявку.');
      return;
    }
    rows = result.preview.rows;
    previewToken = result.preview_token;
    editorRows.innerHTML = editorHtml(rows[0]);
    section.hidden = false;
    updateCommitState();
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
    const parsed = parseId(idsInput.value);
    if (!idsInput.value.trim()) return;
    const atRevision = revision;
    if (parsed.error) {
      previewTimer = setTimeout(() => { if (atRevision === revision) setError('[data-ids-error]',parsed.error); },250);
      return;
    }
    previewTimer = setTimeout(() => fetchPreview(atRevision,idsInput.value),250);
  }
  idsInput.addEventListener('input',schedulePreview);
  editorRows.addEventListener('input',() => { setError('[data-table-error]',''); updateCommitState(); });
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
    if (!previewToken || commit.disabled || saving || rows.length !== 1) return;
    const row=rows[0], id=String(row.zayavka_id);
    const nettoInput=editorRows.querySelector('[name="netto_kg"]');
    const bruttoInput=editorRows.querySelector('[name="brutto_kg"]');
    const netto=nettoInput.value.trim(), brutto=bruttoInput.value.trim();
    for (const [value,input] of [[netto,nettoInput],[brutto,bruttoInput]]) {
      if (value && !/^\d{1,13}(?:[.,]\d)?$/.test(value.replace(/\s/g,''))) {
        setError('[data-table-error]',`Заявка №${id}: укажите неотрицательный вес с точностью до 0,1 кг.`);
        input.focus(); return;
      }
    }
    if (sameKg(netto,row.mass_netto) && sameKg(brutto,row.mass_brutto)) {
      setError('[data-table-error]','Вес заявки не изменён.'); return;
    }
    saving=true; commit.disabled=true; setError('[data-table-error]','');
    try {
      const data=new FormData();
      data.set('preview_token',previewToken); data.set('targets',JSON.stringify({[id]:{netto_kg:netto,brutto_kg:brutto}}));
      data.set('name',''); data.set('comment','');
      completed(await request('/create',data),'сохранён');
    } catch(error) { setError('[data-table-error]',error.message); saving=false; updateCommitState(); }
  });
  const detailHtml = () => detailItems.length === 1
    ? `<div class="field"><span class="page-eyebrow">ЗАЯВКА</span><p class="registry-ui-modal-text"><strong class="registry-ui-entity-id">${escape(detailItems[0].zayavka_id)}</strong></p></div><section class="registry-ui-modal-preview"><div class="table-scroll registry-ui-compact-table-scroll"><table class="table registry-ui-table registry-ui-compact-table registry-ui-compact-table--weight-single"><thead><tr><th>Вес</th><th>Было</th><th>Стало</th></tr></thead><tbody><tr><td class="registry-ui-primary">Нетто</td><td>${weight(detailItems[0].before_netto)}</td><td>${weight(detailItems[0].after_netto)}</td></tr><tr><td class="registry-ui-primary">Брутто</td><td>${weight(detailItems[0].before_brutto)}</td><td>${weight(detailItems[0].after_brutto)}</td></tr></tbody></table></div></section>`
    : `<div class="field"><span class="page-eyebrow">ЗАЯВКИ</span><p class="registry-ui-modal-text">${detailItems.map(item=>`<strong class="registry-ui-entity-id">${escape(item.zayavka_id)}</strong>`).join(', ')}</p></div><section class="registry-ui-modal-preview"><div class="table-scroll registry-ui-compact-table-scroll"><table class="table registry-ui-table registry-ui-compact-table"><thead><tr><th>Заявка</th><th>Нетто было</th><th>Нетто стало</th><th>Брутто было</th><th>Брутто стало</th></tr></thead><tbody>${detailItems.map(item=>`<tr><td class="registry-ui-entity-id">${escape(item.zayavka_id)}</td><td>${weight(item.before_netto)}</td><td>${weight(item.after_netto)}</td><td>${weight(item.before_brutto)}</td><td>${weight(item.after_brutto)}</td></tr>`).join('')}</tbody></table></div></section>`;
  function showDetail() {
    detailTitle.textContent=`Изменение веса #${detailBatch.id}`;
    detailBody.innerHTML=detailHtml();
    detailDialog.classList.remove('registry-ui-modal--confirmation');
    detailDialog.classList.add(detailItems.length === 1 ? 'registry-ui-modal--weight-single' : 'registry-ui-modal--reading');
    detailDialog.classList.remove(detailItems.length === 1 ? 'registry-ui-modal--reading' : 'registry-ui-modal--weight-single');
    detailRevert.hidden=detailBatch.status!=='applied';
    confirmRevert.hidden=true;
    detailDelete.hidden=detailBatch.status!=='reverted';
    confirmDelete.hidden=true;
    if (!detailDialog.open) detailDialog.showModal();
  }
  function showConfirm() {
    detailTitle.textContent=`Изменение веса #${detailBatch.id}`;
    detailBody.innerHTML=`<div class="registry-ui-modal-confirm"><p class="registry-ui-modal-text">К прежним значениям веса нетто и брутто вернутся заявки: ${detailItems.map(item=>`<strong class="registry-ui-entity-id">${escape(item.zayavka_id)}</strong>`).join(', ')}.</p><div class="field-msg is-error" data-revert-error role="alert" hidden></div></div>`;
    detailDialog.classList.remove('registry-ui-modal--reading','registry-ui-modal--weight-single');
    detailDialog.classList.add('registry-ui-modal--confirmation');
    detailRevert.hidden=true;
    confirmRevert.hidden=false;
    detailDelete.hidden=true;
    confirmDelete.hidden=true;
    if (!detailDialog.open) detailDialog.showModal();
  }
  function showDeleteConfirm() {
    if (!detailBatch || detailBatch.status!=='reverted') return;
    detailTitle.textContent=`Изменение веса #${detailBatch.id}`;
    detailBody.innerHTML=`<div class="registry-ui-modal-confirm"><p class="registry-ui-modal-text">Удалить отключённый блок #${escape(detailBatch.id)} из списка? Вес заявок уже возвращён; текущий вес не изменится. История сохранится.</p><div class="field-msg is-error" data-delete-error role="alert" hidden></div></div>`;
    detailDialog.classList.remove('registry-ui-modal--reading','registry-ui-modal--weight-single');
    detailDialog.classList.add('registry-ui-modal--confirmation');
    detailRevert.hidden=true;
    confirmRevert.hidden=true;
    detailDelete.hidden=true;
    confirmDelete.hidden=false;
  }
  async function loadDetail(id, confirm=false) {
    const result=await request(`/${id}/json`);
    detailBatch=result.batch; detailItems=result.items;
    if (confirm && detailBatch.status==='applied') showConfirm(); else showDetail();
  }
  root.addEventListener('click',event => {
    const detail=event.target.closest('[data-detail]');
    if (detail) loadDetail(detail.dataset.detail).catch(error=>pageMessage(error.message,'danger'));
  });
  root.addEventListener('keydown',event => {
    const detail=event.target.closest('[data-detail]');
    if (detail && (event.key==='Enter'||event.key===' ')) {
      event.preventDefault();
      loadDetail(detail.dataset.detail).catch(error=>pageMessage(error.message,'danger'));
    }
  });
  root.addEventListener('dblclick',event => {
    const row=event.target.closest('[data-batch-row]');
    if (row && !event.target.closest('[data-detail]')) loadDetail(row.dataset.registryRowId).catch(error=>pageMessage(error.message,'danger'));
  });
  detailRevert.addEventListener('click',showConfirm);
  detailDelete.addEventListener('click',showDeleteConfirm);
  confirmRevert.addEventListener('click',async () => {
    if (confirmRevert.disabled || !detailBatch) return;
    confirmRevert.disabled=true; setError('[data-revert-error]','');
    try { completed(await request(`/${detailBatch.id}/revert`,new FormData()),'возвращён'); }
    catch(error) { setError('[data-revert-error]',error.message); confirmRevert.disabled=false; }
  });
  confirmDelete.addEventListener('click',async () => {
    if (confirmDelete.disabled || !detailBatch || detailBatch.status!=='reverted') return;
    confirmDelete.disabled=true; setError('[data-delete-error]','');
    try {
      const result=await request(`/${detailBatch.id}/delete`,new FormData());
      sessionStorage.setItem('feo-weight-result',JSON.stringify({message:`Отключённый блок #${result.batch_id} удалён из списка.`,warning:false}));
      location.reload();
    } catch(error) { setError('[data-delete-error]',error.message); confirmDelete.disabled=false; }
  });
  const historyRows=[...root.querySelectorAll('[data-batch-row]')];
  const search=find('[data-history-search]'), returned=find('[data-show-returned]'), weightMode=find('[data-weight-mode]');
  if (search && returned && weightMode) {
    const update=() => {
      const query=search.value.trim().toLocaleLowerCase('ru');
      let visible=0;
      for (const row of historyRows) {
        row.hidden=(!returned.checked && row.dataset.batchStatus==='reverted') || !(row.textContent+' '+row.dataset.registrySearch).toLocaleLowerCase('ru').includes(query);
        if (!row.hidden) visible++;
        for (const node of row.querySelectorAll('[data-total-before],[data-total-after]')) {
          node.innerHTML=totalWeight(node.dataset[weightMode.value==='gross'?'grossKg':'netKg']);
        }
      }
      find('[data-history-count]').textContent=String(visible);
    };
    search.addEventListener('input',update);
    returned.addEventListener('change',update);
    weightMode.addEventListener('change',update);
    update();
  }
  const saved=sessionStorage.getItem('feo-weight-result');
  if (saved) { sessionStorage.removeItem('feo-weight-result'); try { const result=JSON.parse(saved); pageMessage(result.message,result.warning?'warning':'success'); } catch {} }
})();
