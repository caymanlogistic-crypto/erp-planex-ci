(() => {
  'use strict';
  const root = document.querySelector('[data-feo-weights]'); if (!root) return;
  const find = selector => root.querySelector(selector);
  const form = find('[data-weight-form]'); if (!form) return;
  const createDialog = find('[data-create-dialog]'), detailDialog = find('[data-detail-dialog]');
  let token = '', rows = [], busy = false, revision = 0;
  const escape = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
  const kg = tonnes => tonnes === null || tonnes === '' ? '' : (Number(tonnes) * 1000).toFixed(1).replace(/\.0$/, '');
  const label = tonnes => tonnes === null || tonnes === '' ? '—' : `${kg(tonnes)} кг`;
  const pageMessage = (message, warning = false) => { const box = find('[data-page-message]'); box.textContent = message; box.className = `form-alert ${warning ? 'alert-warning' : 'alert-success'}`; box.hidden = false; };
  const formError = message => { const box = find('[data-form-error]'); box.textContent = message; box.hidden = !message; };
  const csrf = () => form.querySelector('[name="_csrf_token"]').value;
  async function request(path, data = null) {
    const options = {headers:{Accept:'application/json','X-Requested-With':'XMLHttpRequest'},credentials:'same-origin'};
    if (data) { data.set('_csrf_token', csrf()); options.method = 'POST'; options.body = data; }
    const response = await fetch(`${root.dataset.base}${path}`, options);
    let result; try { result = await response.json(); } catch { throw new Error('Сервер не ответил. Обновите страницу и проверьте историю блоков.'); }
    if (!response.ok || !result.success) throw new Error(result.message || 'Не удалось выполнить действие.');
    return result;
  }
  async function action(button, work, showError = formError) {
    if (busy) return; busy = true; button.disabled = true; showError('');
    try { await work(); } catch (error) { showError(error.message); } finally { busy = false; button.disabled = false; }
  }
  root.querySelectorAll('[data-open-weight]').forEach(button => button.addEventListener('click', () => { createDialog.showModal(); form.elements.request_ids.focus(); }));
  root.querySelectorAll('[data-close-dialog]').forEach(button => button.addEventListener('click', () => button.closest('dialog').close()));
  form.elements.request_ids.addEventListener('input', () => { token = ''; rows = []; revision++; find('[data-edit-section]').hidden = true; });
  form.addEventListener('submit', event => {
    event.preventDefault();
    action(find('[data-load]'), async () => {
      const atRevision = ++revision, data = new FormData(); data.set('request_ids', form.elements.request_ids.value);
      const result = await request('/preview', data);
      if (atRevision !== revision) return;
      token = result.preview_token; rows = result.preview.rows;
      find('[data-edit-summary]').textContent = `Загружено заявок: ${rows.length}.${result.duplicates ? ` Повторов объединено: ${result.duplicates}.` : ''} Укажите новые значения для каждой заявки.`;
      find('[data-edit-rows]').innerHTML = rows.map(row => `<tr data-request-id="${escape(row.zayavka_id)}"><td><strong>№${escape(row.zayavka_id)}</strong></td><td>${escape(label(row.mass_netto))}</td><td><input class="field-input" name="netto_kg" inputmode="decimal" aria-label="Новое нетто заявки ${escape(row.zayavka_id)}, кг" value="${escape(kg(row.mass_netto))}"></td><td>${escape(label(row.mass_brutto))}</td><td><input class="field-input" name="brutto_kg" inputmode="decimal" aria-label="Новое брутто заявки ${escape(row.zayavka_id)}, кг" value="${escape(kg(row.mass_brutto))}"></td></tr>`).join('');
      find('[data-edit-section]').hidden = false;
      find('[data-edit-section]').scrollIntoView({block:'nearest',behavior:'smooth'});
    });
  });
  function completed(result, actionName) {
    const warning = result.notification === 'failed' || result.notification === 'sending';
    const suffix = warning ? ' Уведомление МАКС не отправлено или результат неизвестен; проверьте журнал событий.' : result.notification === 'disabled' ? ' Уведомление МАКС отключено в настройках события.' : result.notification === 'sent' ? ' Уведомление МАКС отправлено.' : '';
    sessionStorage.setItem('feo-weight-result', JSON.stringify({message:`Блок #${result.batch_id} ${actionName}.${suffix}`,warning}));
    location.reload();
  }
  find('[data-commit]').addEventListener('click', event => action(event.currentTarget, async () => {
    if (!token || !rows.length) throw new Error('Загрузите заявки заново.');
    const targets = {};
    for (const tr of find('[data-edit-rows]').querySelectorAll('tr')) {
      const id = tr.dataset.requestId;
      const netto = tr.querySelector('[name="netto_kg"]').value.trim(), brutto = tr.querySelector('[name="brutto_kg"]').value.trim();
      for (const value of [netto, brutto]) if (value && !/^\d{1,13}(?:[.,]\d)?$/.test(value.replace(/\s/g, ''))) throw new Error(`Заявка №${id}: укажите неотрицательный вес с точностью до 0,1 кг.`);
      targets[id] = {netto_kg:netto, brutto_kg:brutto};
    }
    const data = new FormData(); data.set('preview_token', token); data.set('targets', JSON.stringify(targets)); data.set('name', form.elements.name.value); data.set('comment', form.elements.comment.value);
    const result = await request('/create', data); completed(result, 'сохранён');
  }));
  root.querySelectorAll('[data-detail]').forEach(button => button.addEventListener('click', () => action(button, async () => {
    const result = await request(`/${button.dataset.detail}/json`), batch = result.batch;
    find('[data-detail-body]').innerHTML = `<div class="transfer-detail-meta"><p>Блок<b>#${escape(batch.id)} · ${escape(batch.name)}</b></p><p>Автор<b>${escape(batch.created_by_name)} · ${escape(batch.created_at)}</b></p><p>Комментарий<b>${escape(batch.comment || '—')}</b></p><p>Возврат<b>${batch.reverted_at ? `${escape(batch.reverted_by_name)} · ${escape(batch.reverted_at)}` : 'Не выполнялся'}</b></p></div><div class="table-scroll"><table class="table weight-detail-table"><thead><tr><th>Заявка</th><th>Нетто до → после</th><th>Брутто до → после</th></tr></thead><tbody>${result.items.map(item => `<tr><td>№${escape(item.zayavka_id)}</td><td>${escape(label(item.before_netto))} → ${escape(label(item.after_netto))}</td><td>${escape(label(item.before_brutto))} → ${escape(label(item.after_brutto))}</td></tr>`).join('')}</tbody></table></div>`;
    detailDialog.showModal();
  }, message => pageMessage(message, true))));
  root.querySelectorAll('[data-revert]').forEach(button => button.addEventListener('click', () => {
    const id = button.dataset.revert;
    find('[data-detail-body]').innerHTML = `<p>Вернуть вес нетто и брутто всех заявок блока <strong>#${escape(id)}</strong> к прежним значениям?</p><p class="muted">Если хотя бы один вес изменился после блока, возврат всего блока остановится. История сохранится.</p><div class="form-alert alert-warning" data-revert-error hidden role="alert"></div><div class="transfer-confirm-actions"><button class="btn btn-secondary" type="button" data-cancel-revert>Отмена</button><button class="btn btn-primary" type="button" data-confirm-revert>Вернуть весь блок</button></div>`;
    find('[data-cancel-revert]').onclick = () => detailDialog.close();
    find('[data-confirm-revert]').onclick = event => action(event.currentTarget, async () => { const result = await request(`/${id}/revert`, new FormData()); completed(result, 'возвращён'); }, message => { const box = find('[data-revert-error]'); box.textContent = message; box.hidden = false; });
    detailDialog.showModal();
  }));
  find('[data-history-search]')?.addEventListener('input', event => { const query = event.target.value.trim().toLocaleLowerCase('ru'); root.querySelectorAll('[data-batch-row]').forEach(row => { const full=row.querySelector('[data-registry-full-text]')?.dataset.registryFullText||''; row.hidden = !(row.textContent+' '+full).toLocaleLowerCase('ru').includes(query); }); });
  const saved = sessionStorage.getItem('feo-weight-result');
  if (saved) { sessionStorage.removeItem('feo-weight-result'); try { const result = JSON.parse(saved); pageMessage(result.message, result.warning); } catch {} }
})();
