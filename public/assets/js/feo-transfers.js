(() => {
  'use strict';
  const root = document.querySelector('[data-feo-transfers]');
  if (!root) return;
  const find = selector => root.querySelector(selector);
  const form = find('[data-transfer-form]');
  if (!form) return;
  const base = root.dataset.base;
  const escape = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
  const weightFormat = new Intl.NumberFormat('ru-RU', {maximumFractionDigits:1});
  const weight = value => value === null || value === undefined || value === '' ? '<span class="registry-ui-missing">—</span>' : `${weightFormat.format(Number(value))} кг`;
  const idsHtml = ids => ids.map(id => `<strong class="registry-ui-entity-id">${escape(id)}</strong>`).join(', ');
  const request = async (url, data = null) => {
    const options = {headers:{Accept:'application/json','X-Requested-With':'XMLHttpRequest'},credentials:'same-origin'};
    if (data) {
      data.set('_csrf_token', form.querySelector('[name="_csrf_token"]').value);
      options.method = 'POST';
      options.body = data;
    }
    const response = await fetch(url, options);
    let result;
    try { result = await response.json(); }
    catch { throw new Error('Сервер не ответил. Обновите страницу и проверьте историю блоков.'); }
    if (!response.ok || !result.success) throw new Error(result.message || 'Не удалось выполнить действие.');
    return result;
  };
  const pageMessage = (message, tone = 'success') => {
    window.PlanexNotifications.show({tone, message});
  };
  const qualityLevels = {
    exact:['Точное','Найден запрошенный дом, включая указанный корпус или строение.','badge-ok'],
    number:['Хорошее','Номер дома совпал, но корпус или строение отличаются. Проверьте нужное здание.','badge-warning'],
    near:['Среднее','Найден другой дом с близким номером. Нужный дом не подтверждён.','badge-warning'],
    range:['Приблизительное','Определено примерное положение дома. Точная точка здания не подтверждена.','badge-warning'],
    street:['Низкое','Найдена улица, конкретный дом не определён.','badge-danger'],
    other:['Очень низкое','Найден населённый пункт, район или другой общий объект. Улица и дом не определены.','badge-danger'],
    unknown:['Не определено','Яндекс не сообщил точность найденного адреса.','badge-neutral']
  };
  const geo = find('[data-geo-result]');
  let geoToken = '', geoResult = null, revision = 0, previewToken = '', previewTimer = 0, saving = false;
  const createDialog = find('[data-create-dialog]');
  const detailDialog = find('[data-detail-dialog]');
  const commit = find('[data-commit]');
  const preview = find('[data-preview-result]');
  const showFieldError = (field, message) => {
    for (const name of ['ids','source','address','form']) {
      const box = find(`[data-${name}-error]`);
      if (!box) continue;
      box.textContent = name === field ? message : '';
      box.hidden = name !== field || !message;
      box.closest('.field')?.classList.toggle('is-error', name === field && !!message);
    }
  };
  const errorField = message => {
    if (/^Заявки не найдены|^Введите номера|^Не найдено самостоятельных|^В одном блоке|^Слишком большой список/.test(message)) return 'ids';
    if (/^Заявка не найдена|^Введите шестизначный номер|^Заявка с нужным адресом/.test(message)) return 'source';
    if (/^Сначала определите координаты|^Введите адрес|^Адрес не найден|^Адрес изменился после определения координат|^Подождите секунду/.test(message)) return 'address';
    return 'form';
  };
  const showError = message => showFieldError(errorField(message), message);
  const invalidate = () => {
    revision++;
    previewToken = '';
    if (preview) preview.hidden = true;
    if (commit) commit.disabled = true;
  };
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
  const locationCell = (address, latitude, longitude) =>
    `<span class="registry-ui-modal-text">${escape(address)}</span><span class="registry-ui-modal-secondary">${escape(latitude)}, ${escape(longitude)}</span>`;
  const rowHtml = (row, destination = null) => {
    const before = destination ? row : row.before;
    const after = destination || row.after;
    return `<tr><td class="registry-id">${escape(row.zayavka_id)}</td><td>${weight(row.net_kg)}</td><td>${locationCell(before.mno_adres_pogruzki,before.mno_d,before.mno_sh)}</td><td>${locationCell(after.address ?? after.mno_adres_pogruzki,after.latitude ?? after.mno_d,after.longitude ?? after.mno_sh)}</td></tr>`;
  };
  const qualityHtml = destination => {
    const level = Object.hasOwn(qualityLevels,destination.precision) ? qualityLevels[destination.precision] : qualityLevels.unknown;
    return `<div class="registry-ui-modal-quality"><span class="badge ${level[2]}">${level[0]}</span><span class="registry-ui-modal-help">${level[1]}</span></div>`;
  };
  async function geocode() {
    const button = find('[data-geocode]');
    if (button.disabled) return;
    invalidate(); geoToken = ''; geoResult = null; geo.hidden = true;
    showFieldError('address','');
    button.disabled = true;
    const atRevision = revision;
    try {
      const data = new FormData(); data.set('address',form.elements.address.value);
      const result = await request(`${root.dataset.geoBase}/geocode`,data);
      if (revision !== atRevision) return;
      geoToken = result.geo_token;
      geoResult = result.result;
      find('[data-geo-address]').textContent = geoResult.address;
      find('[data-geo-lat]').textContent = geoResult.latitude;
      find('[data-geo-lon]').textContent = geoResult.longitude;
      const level = Object.hasOwn(qualityLevels,geoResult.precision) ? qualityLevels[geoResult.precision] : qualityLevels.unknown;
      const badge = find('[data-geo-quality-badge]');
      badge.className = createDialog ? `badge ${level[2]}` : `badge transfer-quality-badge quality-${Object.hasOwn(qualityLevels,geoResult.precision) ? geoResult.precision : 'unknown'}`;
      badge.textContent = level[0];
      find('[data-geo-quality-description]').textContent = level[1];
      if (!createDialog) find('[data-map-link]').href = `https://yandex.ru/maps/?pt=${encodeURIComponent(geoResult.longitude)},${encodeURIComponent(geoResult.latitude)}&z=17&l=map`;
      geo.hidden = false;
      if (createDialog) schedulePreview();
    } catch (e) { if (revision === atRevision) showError(e.message); }
    finally { button.disabled = false; }
  }
  find('[data-geocode]').addEventListener('click',geocode);
  form.addEventListener('input', event => {
    if (event.target.name === 'address' && !createDialog) {
      revision++; geoToken=''; geoResult=null; geo.hidden=true;
    }
  });
  if (!createDialog) {
    form.addEventListener('submit', event => {event.preventDefault();geocode();});
    find('[data-copy-coordinates]').addEventListener('click', async event => {
      if (!geoResult || event.currentTarget.disabled) return;
      const button=event.currentTarget;
      button.disabled=true;
      try { await navigator.clipboard.writeText(`${geoResult.latitude}, ${geoResult.longitude}`); button.textContent='Скопировано'; }
      catch(e) { showError(e.message || 'Не удалось скопировать координаты.'); }
      finally {button.disabled=false;}
    });
    return;
  } // The coordinates-only page has no transfer actions.
  const idsInput = form.elements.request_ids;
  const sourceInput = form.elements.source_request_id;
  const mode = () => form.elements.mode.value;
  const updateMode = () => {
    const byAddress = mode() === 'address';
    find('[data-address-destination]').hidden = !byAddress;
    find('[data-request-destination]').hidden = byAddress;
    invalidate();
    schedulePreview();
  };
  const renderPreview = result => {
    const destination = result.preview.destination;
    find('[data-preview-rows]').innerHTML = result.preview.rows.map(row => rowHtml(row,destination)).join('');
    preview.hidden = false;
    previewToken = result.preview_token;
    commit.disabled = false;
  };
  async function fetchPreview(atRevision) {
    const data = new FormData();
    data.set('request_ids',idsInput.value);
    data.set('mode',mode());
    data.set('source_request_id',sourceInput.value.trim());
    data.set('geo_token',geoToken);
    if (mode() === 'address') data.set('address',form.elements.address.value);
    try {
      const result = await request(`${base}/preview`,data);
      if (revision === atRevision) renderPreview(result);
    } catch (e) { if (revision === atRevision) showError(e.message); }
  }
  function schedulePreview() {
    clearTimeout(previewTimer);
    invalidate();
    showFieldError('form','');
    const parsed = parseIds(idsInput.value);
    if (parsed.error) {
      if (idsInput.value.trim()) previewTimer = setTimeout(() => showFieldError('ids',parsed.error),250);
      else showFieldError('ids','');
      return;
    }
    showFieldError('ids','');
    if (mode()==='request') {
      if (!/^\d{6}$/.test(sourceInput.value.trim())) {
        previewTimer = setTimeout(() => showFieldError('source','Введите шестизначный номер заявки с нужным адресом.'),250);
        return;
      }
      showFieldError('source','');
    } else {
      if (!form.elements.address.value.trim()) return;
      if (!geoToken) {
        previewTimer = setTimeout(() => showFieldError('address','Сначала определите координаты адреса.'),250);
        return;
      }
      showFieldError('address','');
    }
    const atRevision = revision;
    previewTimer = setTimeout(() => fetchPreview(atRevision),250);
  }
  form.addEventListener('input', event => {
    if (event.target.name === 'address') {geoToken=''; geoResult=null; geo.hidden=true;}
    if (['request_ids','source_request_id','address'].includes(event.target.name)) schedulePreview();
  });
  form.addEventListener('change', event => { if (event.target.name === 'mode') updateMode(); });
  form.addEventListener('submit', event => event.preventDefault());
  idsInput.addEventListener('paste', event => {
    const pasted = event.clipboardData?.getData('text/plain');
    if (pasted === undefined) return;
    event.preventDefault();
    const before = idsInput.value.slice(0,idsInput.selectionStart);
    const after = idsInput.value.slice(idsInput.selectionEnd);
    const candidate = before + (before && !/[,\s|]$/u.test(before) ? ', ' : '') + pasted +
      (after && !/^[,\s|]/u.test(after) ? ', ' : '') + after;
    const parsed = parseIds(candidate);
    idsInput.value = parsed.error ? candidate : parsed.matches.join(', ');
    idsInput.setSelectionRange(idsInput.value.length,idsInput.value.length);
    idsInput.dispatchEvent(new Event('input',{bubbles:true}));
  });
  root.querySelectorAll('[data-open-transfer]').forEach(button => button.addEventListener('click',() => {
    createDialog.showModal(); idsInput.focus(); schedulePreview();
  }));
  root.querySelectorAll('[data-close-dialog]').forEach(button => button.addEventListener('click',() => button.closest('dialog').close()));
  function completed(result, verb) {
    const warning = result.notification === 'failed' || result.notification === 'sending';
    sessionStorage.setItem('feo-transfer-result',JSON.stringify({message:`Блок #${result.batch_id} ${verb}.${result.legacy_synced ? ' Старая база обновлена.' : ''}${warning ? ' Уведомление MAX не отправлено или результат неизвестен; проверьте журнал событий.' : result.notification === 'disabled' ? ' Уведомление MAX отключено в настройках события.' : result.notification === 'sent' ? ' Уведомление MAX отправлено.' : ''}`,warning}));
    location.reload();
  }
  commit.addEventListener('click', async () => {
    if (!previewToken || commit.disabled || saving) return;
    saving = true; commit.disabled = true;
    try {
      const data = new FormData(); data.set('preview_token',previewToken);
      const result = await request(`${base}/create`,data);
      completed(result,'сохранён');
    } catch(e) { showError(e.message); commit.disabled = !previewToken; saving = false; }
  });
  const detailBody = find('[data-detail-body]');
  const detailTitle = find('#transfer-detail-title');
  const detailRevert = find('[data-revert]');
  const confirmRevert = find('[data-confirm-revert]');
  const detailDelete = find('[data-delete-returned]');
  const confirmDelete = find('[data-confirm-delete-returned]');
  let detailId = 0, detailIds = [];
  const historyContent = result => {
    const batch = result.batch;
    let destination = null;
    try { destination = JSON.parse(batch.destination_json); } catch {}
    const requests = result.items.map(item => String(item.zayavka_id));
    const summary = `<div class="field"><span class="page-eyebrow">Заявки</span><p class="registry-ui-modal-text">${idsHtml(requests)}</p></div>`;
    let destinationHtml = '';
    if (batch.mode === 'request' && batch.source_request_id) destinationHtml = `<div class="field"><span class="page-eyebrow">Перемещены по адресу</span><p class="registry-ui-modal-text">${idsHtml([batch.source_request_id])}<br>${escape(destination?.address)}</p></div>`;
    if (batch.mode === 'address' && destination?.address) {
      destinationHtml = `<div class="registry-ui-modal-geo"><div class="field"><span class="page-eyebrow">Перемещены по адресу</span><p class="registry-ui-modal-text">${escape(destination.address)}</p></div><p class="registry-ui-modal-text">Широта ${escape(destination.latitude)} · Долгота ${escape(destination.longitude)}</p>${destination.precision ? qualityHtml(destination) : ''}</div>`;
    }
    let html = `<div class="registry-ui-modal-grid${destinationHtml ? ' registry-ui-modal-grid--reading' : ''}"><div class="registry-ui-modal-grid">${summary}</div>${destinationHtml ? `<div class="registry-ui-modal-grid">${destinationHtml}</div>` : ''}`;
    html += `<section class="registry-ui-modal-preview"><div class="table-scroll registry-ui-compact-table-scroll"><table class="table registry-ui-table registry-ui-compact-table"><thead><tr><th>Заявка</th><th>Вес нетто</th><th>Было</th><th>Стало</th></tr></thead><tbody>${result.items.map(item => rowHtml(item)).join('')}</tbody></table></div></section></div>`;
    return {html,requests};
  };
  async function openDetail(id) {
    if (saving) return;
    try {
      const result = await request(`${base}/${id}/json`);
      const view = historyContent(result);
      detailId = Number(id); detailIds = view.requests;
      detailTitle.textContent = `Перенос заявок #${id}`;
      detailBody.innerHTML = view.html;
      detailRevert.hidden = result.batch.status !== 'applied';
      confirmRevert.hidden = true;
      if (detailDelete) detailDelete.hidden = result.batch.status !== 'reverted';
      if (confirmDelete) confirmDelete.hidden = true;
      detailDialog.classList.remove('registry-ui-modal--confirmation');
      detailDialog.classList.add('registry-ui-modal--reading');
      detailDialog.showModal();
    } catch(e) { pageMessage(e.message,'danger'); }
  }
  detailRevert.addEventListener('click',() => {
    detailBody.innerHTML = `<div class="registry-ui-modal-confirm"><p class="registry-ui-modal-text">На прежние адреса и координаты вернутся заявки: ${idsHtml(detailIds)}.</p><div class="field-msg is-error" data-revert-error role="alert" hidden></div></div>`;
    detailDialog.classList.remove('registry-ui-modal--reading');
    detailDialog.classList.add('registry-ui-modal--confirmation');
    detailRevert.hidden = true; confirmRevert.hidden = false;
  });
  detailDelete?.addEventListener('click',() => {
    detailBody.innerHTML = `<div class="registry-ui-modal-confirm"><p class="registry-ui-modal-text">Удалить отключённый блок #${detailId} из списка? Заявки уже возвращены; их адреса и координаты не изменятся. История сохранится.</p><div class="field-msg is-error" data-delete-error role="alert" hidden></div></div>`;
    detailDialog.classList.remove('registry-ui-modal--reading');
    detailDialog.classList.add('registry-ui-modal--confirmation');
    detailDelete.hidden = true;
    confirmDelete.hidden = false;
  });
  confirmRevert.addEventListener('click',async () => {
    if (saving) return;
    saving = true; confirmRevert.disabled = true;
    try { const result = await request(`${base}/${detailId}/revert`,new FormData()); completed(result,'возвращён'); }
    catch(e) { const box=find('[data-revert-error]'); box.textContent=e.message;box.hidden=false; saving=false;confirmRevert.disabled=false; }
  });
  confirmDelete?.addEventListener('click',async () => {
    if (saving) return;
    saving = true; confirmDelete.disabled = true;
    try {
      const result = await request(`${base}/${detailId}/delete`,new FormData());
      sessionStorage.setItem('feo-transfer-result',JSON.stringify({message:`Отключённый блок #${result.batch_id} удалён из списка.`,warning:false}));
      location.reload();
    }
    catch(e) { const box=find('[data-delete-error]'); box.textContent=e.message;box.hidden=false; saving=false;confirmDelete.disabled=false; }
  });
  root.addEventListener('click',event => {
    const detail=event.target.closest('[data-detail]');
    if (detail) openDetail(detail.dataset.detail);
  });
  root.addEventListener('keydown',event => {
    const detail=event.target.closest('[data-detail]');
    if (detail && (event.key==='Enter' || event.key===' ')) {event.preventDefault();openDetail(detail.dataset.detail);}
  });
  root.addEventListener('dblclick',event => {
    const row=event.target.closest('[data-batch-row]');
    if (row && !event.target.closest('[data-detail]')) openDetail(row.dataset.registryRowId);
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
        const node=row.querySelector('[data-total-weight]');
        const value=node.dataset[weightMode.value==='gross'?'grossKg':'netKg'];
        node.innerHTML=weight(value);
      }
      find('[data-history-count]').textContent=String(visible);
    };
    search.addEventListener('input',update);
    returned.addEventListener('change',update);
    weightMode.addEventListener('change',update);
    update();
  }
  const saved=sessionStorage.getItem('feo-transfer-result');
  if (saved) {sessionStorage.removeItem('feo-transfer-result');try {const value=JSON.parse(saved);pageMessage(value.message,value.warning ? 'warning' : 'success');}catch {}}
})();
