(() => {
  const root = document.querySelector('[data-logist-contracts]');
  if (!root) return;
  const owner = root.dataset.owner === '1';
  const csrf = root.querySelector('[data-csrf]').value;
  const form = root.querySelector('[data-form]');
  const formDialog = root.querySelector('[data-form-dialog]');
  const detailDialog = root.querySelector('[data-detail]');
  const search = root.querySelector('[data-search]');
  const statusLabel = {pending:'На согласовании',approved:'Согласован',rejected:'Отказано'};
  const typeLabel = {lease:'Аренда',sublease:'Субаренда'};
  const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const formatDate = value => /^\d{4}-\d{2}-\d{2}$/.test(value || '') ? value.slice(8,10)+'.'+value.slice(5,7)+'.'+value.slice(0,4) : '—';
  const plusSeven = value => { const d = new Date(value+'T12:00:00'); d.setDate(d.getDate()+7); return d.toISOString().slice(0,10); };
  const rows = JSON.parse(root.querySelector('[data-initial-rows]').textContent || '[]');
  let trips = [], executors = [], current = null, assignedExecutorId = null;
  const formError = root.querySelector('[data-form-error]');
  const pageError = root.querySelector('[data-page-error]');
  const detailError = root.querySelector('[data-detail-error]');
  const showPageError = message => { pageError.textContent = message; pageError.hidden = false; pageError.classList.remove('is-hidden'); };
  function updatePendingBadges(delta) {
    if (!owner || delta === 0) return;
    if (!document.querySelector('[data-badge-provider="logist_contracts_pending"]')) {
      const link = [...document.querySelectorAll('a.nav-sub-item')].find(item => item.getAttribute('href')?.includes('/document-generator/logist-contracts'));
      const parent = link?.closest('.nav-sub')?.previousElementSibling;
      if (link) { const badge=document.createElement('span'); badge.className='nav-count'; badge.dataset.badgeProvider='logist_contracts_pending'; badge.textContent='0'; link.append(badge); }
      if (parent && !parent.querySelector('[data-badge-group="Генератор договоров"]')) { const badge=document.createElement('span'); badge.className='nav-count'; badge.dataset.badgeGroup='Генератор договоров'; badge.textContent='0'; parent.querySelector('.nav-chevron')?.before(badge); }
    }
    document.querySelectorAll('[data-badge-provider="logist_contracts_pending"], [data-badge-group="Генератор договоров"]').forEach(badge => {
      const count = Math.max(0,(Number(badge.textContent.trim().replace('+','')) || 0)+delta);
      badge.textContent = count > 999 ? '999+' : String(count);
      badge.hidden = count === 0;
    });
  }

  function rowFromRecord(record) {
    const s = record.snapshot || {};
    return {
      id:Number(record.id), trip_id:Number(record.trip_id), business_id:Number(record.business_id),
      type:record.contract_type, version:Number(record.version_no), status:record.status,
      reason:record.review_reason || '', logist:record.logist_name || '',
      description:s.service?.description || s.trip?.comment || '',
      start:s.parameters?.start_date || '', finish:s.parameters?.finish_date || '',
      contractor:s.executor?.name || '', driver:s.driver?.full_name || '',
      price:s.parameters?.price || '', vat:s.parameters?.vat_mode || ''
    };
  }
  async function api(url, options = {}) {
    const response = await fetch(url, {credentials:'same-origin', ...options});
    const body = await response.json().catch(() => ({ok:false,message:'Некорректный ответ сервера.'}));
    if (!response.ok || !body.ok) throw new Error(body.message || 'Операция не выполнена.');
    return body;
  }
  function post(url, fields = {}) {
    const data = new FormData();
    data.set('_csrf_token',csrf);
    Object.entries(fields).forEach(([key,value]) => data.set(key,String(value)));
    return api(url,{method:'POST',body:data});
  }
  function showError(message) {
    formError.textContent = message;
    formError.hidden = false;
  }
  function render() {
    const query = search.value.trim().toLocaleLowerCase('ru-RU');
    const visible = rows.filter(row => [row.business_id,row.description,row.contractor,row.driver,row.logist].join(' ').toLocaleLowerCase('ru-RU').includes(query));
    root.querySelector('[data-count]').textContent = visible.length;
    root.querySelector('[data-rows]').innerHTML = visible.length ? visible.map(row => `
      <tr tabindex="0" data-id="${row.id}" data-registry-row-id="${row.id}" class="${row.status === 'pending' ? 'registry-ui-warning' : ''}">
        <td><span class="registry-id registry-ui-name">#${row.business_id}</span><span class="registry-ui-secondary">Версия ${row.version}</span><span class="registry-ui-secondary">${statusLabel[row.status] || ''}</span></td>
        <td class="registry-ui-trip-cell"><span class="registry-ui-trip" data-registry-trip title="${escapeHtml(row.description)}">${escapeHtml(row.description || '—')}</span><span class="registry-ui-secondary">${typeLabel[row.type] || ''}</span></td>
        <td class="registry-ui-primary">${formatDate(row.start)}</td><td class="registry-ui-primary">${escapeHtml(row.contractor || '—')}</td><td class="registry-ui-primary">${escapeHtml(row.driver || '—')}</td>
        <td class="registry-ui-primary">${escapeHtml(row.price)} ₽</td><td class="registry-ui-primary">${escapeHtml(row.vat)}</td>${owner ? `<td class="registry-ui-primary">${escapeHtml(row.logist || '—')}</td>` : ''}
      </tr>`).join('') : `<tr><td colspan="${owner ? 8 : 7}" class="registry-ui-missing">Договоры не найдены.</td></tr>`;
    window.PlanexRegistry?.textRoles(root);
  }
  function tripLabel(trip) { return '#' + trip.business_id + ' — ' + (trip.comment || trip.client_name || 'Рейс'); }
  function executorLabel(executor) {
    const name = [executor.contractor_name,executor.driver_full_name].filter(Boolean).join(' — ');
    const duplicates = executors.filter(item => [item.contractor_name,item.driver_full_name].filter(Boolean).join(' — ') === name);
    return duplicates.length > 1 ? `${name} · ${executor.primary_plate || 'без госномера'} · #${executor.crew_id}` : name;
  }
  function selectedTrip() { return trips.find(trip => tripLabel(trip) === form.elements.trip_choice.value); }
  function selectedExecutor() {
    if (assignedExecutorId !== null) return executors.find(executor => Number(executor.crew_id) === assignedExecutorId);
    return executors.find(executor => executorLabel(executor) === form.elements.executor_choice.value);
  }
  function fillFromTrip() {
    const trip = selectedTrip();
    assignedExecutorId = null;
    if (!trip) { form.elements.start_date.value = ''; form.elements.finish_date.value = ''; form.elements.executor_choice.readOnly = false; return; }
    const start = String(trip.actual_start_date || trip.planned_start_date || trip.planned_start_date_from || '').slice(0,10);
    form.elements.start_date.value = start;
    form.elements.finish_date.value = start ? plusSeven(start) : '';
    const assigned = executors.find(executor => Number(executor.crew_id) === Number(trip.route_executor_id));
    if (assigned) assignedExecutorId = Number(assigned.crew_id);
    form.elements.executor_choice.value = assigned ? executorLabel(assigned) : '';
    form.elements.executor_choice.readOnly = !!assigned;
    form.elements.price.value = '';
  }
  async function openForm(previous = null) {
    pageError.hidden = true; pageError.classList.add('is-hidden');
    formError.hidden = true;
    form.reset();
    assignedExecutorId = null;
    const button = root.querySelector('[data-create]');
    button.disabled = true;
    try {
      const result = await post(root.dataset.prepareUrl);
      trips = result.trips || [];
      executors = result.executors || [];
      root.querySelector('#lc-trips').innerHTML = trips.map(trip => `<option value="${escapeHtml(tripLabel(trip))}"></option>`).join('');
      root.querySelector('#lc-executors').innerHTML = executors.map(executor => `<option value="${escapeHtml(executorLabel(executor))}"></option>`).join('');
      root.querySelector('#contract-form-title').textContent = previous ? `Новая версия договора #${previous.business_id}` : 'Новый договор';
      if (previous) {
        const trip = trips.find(item => Number(item.id) === Number(previous.trip_id));
        if (!trip) throw new Error('Рейс больше недоступен после синхронизации.');
        form.elements.trip_choice.value = tripLabel(trip);
        form.querySelector(`[name="contract_type"][value="${previous.type}"]`).checked = true;
        fillFromTrip();
        form.elements.price.value = previous.price;
        form.elements.vat_mode.value = previous.vat;
      } else {
        form.elements.executor_choice.readOnly = false;
      }
      formDialog.showModal();
      form.elements.trip_choice.focus();
    } catch (error) {
      showPageError(error.message);
    } finally { button.disabled = false; }
  }
  async function openDetail(id) {
    pageError.hidden = true; pageError.classList.add('is-hidden');
    detailError.hidden = true;
    try {
      const result = await api(root.dataset.detailBase+'/'+id);
      const record = result.row;
      const row = rowFromRecord(record);
      current = row;
      const s = record.snapshot || {};
      root.querySelector('#contract-detail-title').textContent = `Договор рейса #${row.business_id} · версия ${row.version}`;
      const history = (result.versions || []).filter(v => Number(v.id) !== row.id);
      root.querySelector('[data-detail-body]').innerHTML = `
        <div class="field"><span class="page-eyebrow">РЕЙС</span><p class="registry-ui-modal-text"><strong class="registry-ui-entity-id">#${row.business_id}</strong> · ${escapeHtml(row.description || '—')}</p></div>
        <div class="field"><span class="page-eyebrow">ДОГОВОР</span><p class="registry-ui-modal-text">${typeLabel[row.type]} · ${formatDate(row.start)} — ${formatDate(row.finish)}<br>${escapeHtml(row.contractor)} — ${escapeHtml(row.driver)}<br>${escapeHtml(row.price)} ₽ · ${escapeHtml(row.vat)}</p></div>
        <div class="field"><span class="page-eyebrow">СОГЛАСОВАНИЕ</span><p class="registry-ui-modal-text">${statusLabel[row.status]} · ${escapeHtml(row.logist || '—')}${row.reason ? '<br>Причина: '+escapeHtml(row.reason) : ''}</p></div>
        ${history.length ? `<div class="field"><span class="page-eyebrow">ПРЕЖНИЕ ВЕРСИИ</span><p class="registry-ui-modal-text">${history.map(v => `<a href="${root.dataset.detailBase}/${v.id}/download">Версия ${v.version_no}</a> — ${statusLabel[v.status]}${v.review_reason ? ': '+escapeHtml(v.review_reason) : ''}`).join('<br>')}</p></div>` : ''}
        <div class="${history.length ? 'registry-ui-modal-preview' : 'field'}"><span class="page-eyebrow">МАРШРУТ И СОСТАВ РЕЙСА</span><p class="registry-ui-modal-text">${escapeHtml(s.service?.route || s.trip?.comment || '—')}<br>${escapeHtml([s.vehicle?.model,s.vehicle?.plate,s.trailer?.model,s.trailer?.plate].filter(Boolean).join(', ') || '—')}<br>${escapeHtml((s.requests || []).map(r => r.zayavka_id).filter(Boolean).join(', ') || '—')}</p></div>
        <div class="field" data-reason hidden><label class="field-label" for="lc-reason">Причина отказа</label><input class="field-input" id="lc-reason" maxlength="1000"></div>`;
      root.querySelector('[data-actions]').innerHTML = owner ? `<button type="button" class="btn btn-ghost" data-close>Отменить</button><a class="btn btn-secondary" href="${root.dataset.detailBase}/${row.id}/download">Скачать PDF</a>${row.status === 'pending' ? '<button type="button" class="btn btn-secondary" data-reject>Отказать</button><button type="button" class="btn btn-primary" data-approve>Согласовать</button>' : ''}` : `<button type="button" class="btn btn-ghost" data-close>Отменить</button><a class="btn btn-secondary" href="${root.dataset.detailBase}/${row.id}/download">Скачать PDF</a><button type="button" class="btn btn-primary" data-edit>Редактировать</button>`;
      detailDialog.showModal();
    } catch (error) { showPageError(error.message); }
  }
  async function review(decision) {
    if (!current) return;
    const reasonField = root.querySelector('#lc-reason');
    if (decision === 'rejected' && reasonField.closest('[data-reason]').hidden) {
      reasonField.closest('[data-reason]').hidden = false; reasonField.focus(); return;
    }
    try {
      await post(root.dataset.reviewBase+'/'+current.id+'/review',{decision,reason:reasonField?.value || ''});
      const row = rows.find(item => item.id === current.id);
      if (row) { row.status = decision; row.reason = reasonField?.value || ''; }
      updatePendingBadges(-1);
      detailDialog.close(); render();
    } catch (error) { detailError.textContent = error.message; detailError.hidden = false; }
  }
  root.querySelector('[data-create]').addEventListener('click',() => openForm());
  search.addEventListener('input',render);
  form.elements.trip_choice.addEventListener('input',fillFromTrip);
  root.querySelector('[data-rows]').addEventListener('dblclick',event => { const tr=event.target.closest('tr[data-id]'); if(tr) openDetail(Number(tr.dataset.id)); });
  root.querySelector('[data-rows]').addEventListener('keydown',event => { const tr=event.target.closest('tr[data-id]'); if(tr && event.key === 'Enter') openDetail(Number(tr.dataset.id)); });
  root.addEventListener('click',event => {
    if (event.target.closest('[data-close]')) { formDialog.close(); detailDialog.close(); }
    if (event.target.closest('[data-edit]') && current) { detailDialog.close(); openForm(current); }
    if (event.target.closest('[data-approve]')) review('approved');
    if (event.target.closest('[data-reject]')) review('rejected');
  });
  form.addEventListener('submit',async event => {
    event.preventDefault(); formError.hidden = true;
    const trip = selectedTrip(), executor = selectedExecutor();
    if (!trip) { showError('Выберите рейс из списка.'); form.elements.trip_choice.focus(); return; }
    if (!executor) { showError('Выберите действующего исполнителя.'); form.elements.executor_choice.focus(); return; }
    if (!form.elements.start_date.value) { showError('У рейса не указана дата начала.'); return; }
    const fields = {
      trip_id:trip.id, route_executor_id:executor.crew_id,
      contract_type:form.elements.contract_type.value,
      start_date:form.elements.start_date.value, finish_date:form.elements.finish_date.value,
      price:form.elements.price.value, vat_mode:form.elements.vat_mode.value
    };
    const submit = form.querySelector('[type="submit"]'); submit.disabled = true;
    try {
      const result = await post(root.dataset.createUrl,fields);
      const created = rowFromRecord(result.row);
      const previous = rows.findIndex(item => item.trip_id === created.trip_id && item.type === created.type);
      const wasPending = previous >= 0 && rows[previous].status === 'pending';
      if (previous >= 0) rows.splice(previous,1);
      rows.unshift(created);
      updatePendingBadges(wasPending ? 0 : 1);
      formDialog.close(); search.value = ''; render();
      if (result.row.notification_warning) showPageError(result.row.notification_warning);
      const link = document.createElement('a'); link.href = result.download_url; link.download = ''; document.body.append(link); link.click(); link.remove();
    } catch (error) { showError(error.message); }
    finally { submit.disabled = false; }
  });
  render();
  const openId = Number(new URLSearchParams(location.search).get('open'));
  if (Number.isSafeInteger(openId) && openId > 0) openDetail(openId);
})();
