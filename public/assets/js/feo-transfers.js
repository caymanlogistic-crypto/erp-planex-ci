(() => {
  'use strict';
  const root = document.querySelector('[data-feo-transfers]'); if (!root) return;
  const find = s => root.querySelector(s), form = find('[data-transfer-form]'); if (!form) return;
  const createDialog = find('[data-create-dialog]'), detailDialog = find('[data-detail-dialog]');
  let geoToken = '', geoResult = null, previewToken = '', revision = 0, busy = false;
  // Product labels describe matching categories, not a distance-error guarantee.
  const qualityLevels = {
    exact: ['Точное', 'Найден запрошенный дом, включая указанный корпус или строение.'],
    number: ['Хорошее', 'Номер дома совпал, но корпус или строение отличаются. Проверьте нужное здание.'],
    near: ['Среднее', 'Найден другой дом с близким номером. Нужный дом не подтверждён.'],
    range: ['Приблизительное', 'Определено примерное положение дома. Точная точка здания не подтверждена.'],
    street: ['Низкое', 'Найдена улица, конкретный дом не определён.'],
    other: ['Очень низкое', 'Найден населённый пункт, район или другой общий объект. Улица и дом не определены.'],
  };

  const csrf = () => form.querySelector('[name="_csrf_token"]').value;
  const escape = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const error = message => { const box = find('[data-form-error]'); box.textContent = message; box.hidden = !message; };
  const pageMessage = (message, warn = false) => { const box = find('[data-page-message]'); box.textContent = message; box.className = `form-alert ${warn ? 'alert-warning' : 'alert-success'}`; box.hidden = false; };
  const invalidate = () => { revision++; previewToken = ''; find('[data-preview-result]').hidden = true; };
  async function request(url, data = null) {
    const options = {headers:{Accept:'application/json','X-Requested-With':'XMLHttpRequest'},credentials:'same-origin'};
    if (data) { data.set('_csrf_token',csrf()); options.method = 'POST'; options.body = data; }
    const response = await fetch(url,options);
    let result; try { result = await response.json(); } catch { throw new Error('Сервер не ответил. Обновите страницу и проверьте историю блоков.'); }
    if (!response.ok || !result.success) throw new Error(result.message || (response.status === 403 ? 'Доступ запрещён.' : 'Не удалось выполнить действие.'));
    return result;
  }
  async function action(button, fn, target = error) {
    if (busy) return; busy = true; button.disabled = true; error('');
    try { await fn(); } catch (e) { target(e.message); } finally { busy = false; button.disabled = false; }
  }
  form.addEventListener('input',e => {
    invalidate();
    if (e.target.name === 'address') { geoToken = ''; geoResult = null; find('[data-geo-result]').hidden = true; }
  });
  form.addEventListener('change',e => {
    invalidate();
    if (e.target.name === 'mode') { const byAddress = e.target.value === 'address'; find('[data-address-destination]').hidden = !byAddress; find('[data-request-destination]').hidden = byAddress; }
  });
  root.querySelectorAll('[data-open-transfer]').forEach(button => button.addEventListener('click',() => { createDialog.showModal(); form.querySelector('[name="request_ids"]').focus(); }));
  root.querySelectorAll('[data-close-dialog]').forEach(button => button.addEventListener('click',() => button.closest('dialog').close()));
  find('[data-geocode]').addEventListener('click',e => action(e.currentTarget, async () => {
    invalidate(); geoToken = ''; geoResult = null; find('[data-geo-result]').hidden = true;
    const atRevision = revision, data = new FormData(); data.set('address',form.elements.address.value);
    const result = await request(`${root.dataset.geoBase}/geocode`,data);
    if (revision !== atRevision) return;
    geoToken = result.geo_token; geoResult = result.result;
    find('[data-geo-address]').textContent = geoResult.address;
    find('[data-geo-lat]').textContent = geoResult.latitude; find('[data-geo-lon]').textContent = geoResult.longitude;
    const precision = Object.hasOwn(qualityLevels, geoResult.precision) ? geoResult.precision : 'unknown';
    const quality = qualityLevels[precision] || ['Не определено', 'Яндекс не сообщил точность найденного адреса.'];
    find('[data-geo-quality-badge]').textContent = quality[0];
    find('[data-geo-quality-badge]').className = `badge transfer-quality-badge quality-${precision}`;
    find('[data-geo-quality-description]').textContent = quality[1];
    find('[data-map-link]').href = `https://yandex.ru/maps/?pt=${encodeURIComponent(geoResult.longitude)},${encodeURIComponent(geoResult.latitude)}&z=17&l=map`;
    find('[data-geo-result]').hidden = false;
  }));
  find('[data-copy-coordinates]').addEventListener('click',e => action(e.currentTarget,async () => {
    if (!geoResult) return; await navigator.clipboard.writeText(`${geoResult.latitude}, ${geoResult.longitude}`);
    e.target.textContent = 'Скопировано';
  }));
  form.addEventListener('submit',e => {
    e.preventDefault();
    if (form.hasAttribute('data-coordinates-only')) { find('[data-geocode]').click(); return; }
    action(find('[data-preview]'),async () => {
      invalidate(); const atRevision = revision;
      const data = new FormData(form); data.set('geo_token',geoToken);
      const result = await request(`${root.dataset.base}/preview`,data);
      if (revision !== atRevision) return;
      previewToken = result.preview_token;
      const destination = result.preview.destination;
      find('[data-preview-summary]').textContent = `Заявок: ${result.preview.count}. ${result.duplicates ? `Повторов объединено: ${result.duplicates}. ` : ''}Качество координат: ${destination.quality}.`;
      find('[data-preview-rows]').innerHTML = result.preview.rows.map(row => `<tr><td>${escape(row.zayavka_id)}</td><td>${escape(row.mno_adres_pogruzki)}<div class="muted">${escape(row.mno_d)}, ${escape(row.mno_sh)}</div></td><td>${escape(destination.address)}<div class="muted">${escape(destination.latitude)}, ${escape(destination.longitude)}</div></td></tr>`).join('');
      find('[data-preview-result]').hidden = false; find('[data-preview-result]').scrollIntoView({block:'nearest',behavior:'smooth'});
    });
  });
  function completed(result, verb) {
    const warning = result.notification === 'failed' || result.notification === 'sending';
    sessionStorage.setItem('feo-transfer-result',JSON.stringify({message:`Блок #${result.batch_id} ${verb}.${result.legacy_synced ? ' Старая база обновлена.' : ''}${warning ? ' Уведомление MAX не отправлено или результат неизвестен; проверьте журнал событий.' : result.notification === 'disabled' ? ' Уведомление MAX отключено в настройках события.' : result.notification === 'sent' ? ' Уведомление MAX отправлено.' : ''}`,warning}));
    location.reload();
  }
  find('[data-commit]').addEventListener('click',e => action(e.currentTarget,async () => {
    if (!previewToken) throw new Error('Проверьте блок заново.');
    const data = new FormData(); data.set('preview_token',previewToken);
    const result = await request(`${root.dataset.base}/create`,data); completed(result,'сохранён');
  }));
  function confirmRevert(id) {
    const body = find('[data-detail-body]');
    body.innerHTML = `<p>Вернуть все заявки блока <strong>#${escape(id)}</strong> к адресам и координатам до этого перемещения?</p><p class="muted">Если заявки перемещены более поздним блоком, возврат остановится. История сохранится.</p><div class="form-alert alert-warning" data-revert-error hidden role="alert"></div><div class="transfer-confirm-actions"><button class="btn btn-secondary" type="button" data-cancel-revert>Отмена</button><button class="btn btn-primary" type="button" data-confirm-revert>Вернуть весь блок</button></div>`;
    find('[data-cancel-revert]').onclick = () => detailDialog.close();
    find('[data-confirm-revert]').onclick = e => action(e.currentTarget,async () => { const result = await request(`${root.dataset.base}/${id}/revert`,new FormData()); completed(result,'возвращён'); },message => { find('[data-revert-error]').textContent = message; find('[data-revert-error]').hidden = false; });
  }
  function openDetail(id) {
    if (busy) return;
    action(find('[data-detail-dialog]'),async () => {
      const result = await request(`${root.dataset.base}/${id}/json`), batch = result.batch;
      find('[data-detail-body]').innerHTML = `<div class="transfer-detail-meta"><p>Блок<b>#${escape(id)} · ${escape(batch.name)}</b></p><p>Автор<b>${escape(batch.created_by_name)} · ${escape(batch.created_at)}</b></p><p>Комментарий<b>${escape(batch.comment || '—')}</b></p><p>Возврат<b>${batch.reverted_at ? `${escape(batch.reverted_by_name)} · ${escape(batch.reverted_at)}` : 'Не выполнялся'}</b></p></div>${batch.status==='applied'?`<div class="transfer-confirm-actions"><button class="btn btn-secondary btn-sm" type="button" data-revert="${escape(id)}">Вернуть блок</button></div>`:''}<div class="table-scroll"><table class="table"><thead><tr><th>Заявка</th><th>До перемещения</th><th>После перемещения</th></tr></thead><tbody>${result.items.map(row => `<tr><td>${escape(row.zayavka_id)}</td><td>${escape(row.before.mno_adres_pogruzki)}<div class="muted">${escape(row.before.mno_d)}, ${escape(row.before.mno_sh)}</div></td><td>${escape(row.after.mno_adres_pogruzki)}<div class="muted">${escape(row.after.mno_d)}, ${escape(row.after.mno_sh)}</div></td></tr>`).join('')}</tbody></table></div><details class="transfer-settings"><summary>Передача в старую базу</summary><p>Изменения подготовлены. Подключение к старой базе ещё не настроено; данные туда не отправлялись.</p><a class="btn btn-secondary btn-sm" href="${escape(root.dataset.base)}/${escape(id)}/export">Скачать блок изменений (JSON)</a></details>`;
      detailDialog.showModal();
    },message => pageMessage(message,true));
  }
  root.addEventListener('click',event => {
    const revert=event.target.closest('[data-revert]');
    if (revert) { confirmRevert(revert.dataset.revert); return; }
    const detail=event.target.closest('[data-detail]');
    if (detail) openDetail(detail.dataset.detail);
  });
  root.addEventListener('keydown',event => {
    const detail=event.target.closest('[data-detail]');
    if (detail && (event.key==='Enter'||event.key===' ')) { event.preventDefault(); openDetail(detail.dataset.detail); }
  });
  root.addEventListener('dblclick',event => {
    const row=event.target.closest('[data-batch-row]');
    if (row && !event.target.closest('[data-detail]')) openDetail(row.dataset.registryRowId);
  });
  const historyRows=[...root.querySelectorAll('[data-batch-row]')];
  const historySearch=find('[data-history-search]'),showReturned=find('[data-show-returned]'),weightMode=find('[data-weight-mode]');
  if(historySearch&&showReturned&&weightMode){
  const weightFormatter=new Intl.NumberFormat('ru-RU',{maximumFractionDigits:1});
  function updateHistory() {
    const query=historySearch.value.trim().toLocaleLowerCase('ru');
    let visible=0;
    for(const row of historyRows) {
      row.hidden=(!showReturned.checked&&row.dataset.batchStatus==='reverted') || !(row.textContent+' '+row.dataset.registrySearch).toLocaleLowerCase('ru').includes(query);
      if(!row.hidden) visible++;
      const weight=row.querySelector('[data-total-weight]');
      const value=weight.dataset[weightMode.value==='gross'?'grossKg':'netKg'];
      weight.textContent=value===''?'—':weightFormatter.format(Number(value))+' кг';
      weight.classList.toggle('registry-ui-missing',value==='');
    }
    find('[data-history-count]').textContent=String(visible);
  }
  historySearch?.addEventListener('input',updateHistory);
  showReturned?.addEventListener('change',updateHistory);
  weightMode?.addEventListener('change',updateHistory);
  updateHistory();
  }

  const saved = sessionStorage.getItem('feo-transfer-result');
  if (saved) { sessionStorage.removeItem('feo-transfer-result'); try { const r = JSON.parse(saved); pageMessage(r.message,r.warning); } catch {} }
})();
