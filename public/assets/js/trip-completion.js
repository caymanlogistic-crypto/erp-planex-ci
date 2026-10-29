(function () {
  'use strict';
  const root = document.querySelector('[data-logistics-assistant]');
  if (!root) return;
  const reviewerMode = root.dataset.reviewerMode === '1';

  const filter = document.querySelector('.logistics-filter');
  const search = filter?.querySelector('input[name="q"]');
  const hideComplete = filter?.querySelector('input[name="hide_complete"]');
  const trips = [...root.querySelectorAll('.logistics-trip')];
  const empty = document.createElement('div');
  empty.className = 'lab-search-empty';
  empty.textContent = 'По вашему запросу ничего не найдено.';
  empty.hidden = true;
  root.querySelector('.logistics-trip-list')?.after(empty);

  const make = (tag, className, text) => {
    const element = document.createElement(tag);
    element.className = className;
    element.textContent = text;
    return element;
  };
  const json = async (response) => {
    let data;
    try { data = await response.json(); } catch (_) { throw new Error('Сервер вернул некорректный ответ.'); }
    if (!response.ok || data.ok === false) throw new Error(data.message || 'Не удалось сохранить данные.');
    return data;
  };
  const post = async (url, body) => json(await fetch(url, {
    method: 'POST', credentials: 'same-origin',
    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, body,
  }));
  const saveState = (row, kind, label) => {
    const box = row.querySelector('[data-save-state]');
    if (!box) return;
    row.classList.toggle('is-saving', kind === 'saving');
    row.classList.toggle('is-save-error', kind === 'error');
    box.hidden = false;
    box.querySelector('[data-save-label]').textContent = label;
    if (kind === 'saved') setTimeout(() => {
      if (!row.classList.contains('is-saving') && !row.classList.contains('is-save-error')) box.hidden = true;
    }, 1500);
  };

  const formatInterval = (value) => {
    const digits = value.replace(/\D/g, '').slice(0, 14);
    const parts = [];
    if (digits.length) parts.push(digits.slice(0, 2));
    if (digits.length > 2) parts.push('.' + digits.slice(2, 4));
    if (digits.length > 4) parts.push('.' + digits.slice(4, 6));
    if (digits.length > 6) parts.push(' ' + digits.slice(6, 8));
    if (digits.length > 8) parts.push(':' + digits.slice(8, 10));
    if (digits.length > 10) parts.push(' - ' + digits.slice(10, 12));
    if (digits.length > 12) parts.push(':' + digits.slice(12, 14));
    return parts.join('');
  };
  const intervalState = (value) => {
    const digits = value.replace(/\D/g, '');
    if (!digits.length) return 'empty';
    const numberAt = (start) => Number(digits.slice(start, start + 2));
    if (digits.length >= 2 && (numberAt(0) < 1 || numberAt(0) > 31)) return 'invalid';
    if (digits.length >= 4 && (numberAt(2) < 1 || numberAt(2) > 12)) return 'invalid';
    if (digits.length >= 6) {
      const year = numberAt(4) <= 69 ? 2000 + numberAt(4) : 1900 + numberAt(4);
      if (numberAt(0) > new Date(Date.UTC(year, numberAt(2), 0)).getUTCDate()) return 'invalid';
    }
    if (digits.length >= 8 && numberAt(6) > 23) return 'invalid';
    if (digits.length >= 10 && numberAt(8) > 59) return 'invalid';
    if (digits.length >= 12 && numberAt(10) > 23) return 'invalid';
    if (digits.length >= 14 && numberAt(12) > 59) return 'invalid';
    if (digits.length < 14) return 'partial';
    if (numberAt(10) * 60 + numberAt(12) < numberAt(6) * 60 + numberAt(8)) return 'invalid';
    return 'valid';
  };

  const preview = document.createElement('dialog');
  preview.className = 'lab-scan-preview';
  preview.innerHTML = '<div class="lab-scan-preview-head"><strong>Просмотр скана</strong><button type="button" aria-label="Закрыть просмотр">×</button></div><div class="lab-scan-preview-body"></div>';
  preview.querySelector('button').addEventListener('click', () => preview.close());
  document.body.append(preview);
  const showPreview = (button) => {
    const body = preview.querySelector('.lab-scan-preview-body');
    body.replaceChildren();
    const url = button.dataset.previewUrl;
    const name = button.closest('.lab-docs-item')?.querySelector('.lab-docs-name')?.textContent || 'Скан ТН';
    if (/\.(jpe?g|png|webp|tiff?)$/i.test(name)) {
      const image = document.createElement('img');
      image.src = url;
      image.alt = name;
      body.append(image);
    } else {
      const frame = document.createElement('iframe');
      frame.src = url;
      frame.title = name;
      body.append(frame);
    }
    preview.showModal();
  };

  const renderDocuments = (row, documents) => {
    const card = row.querySelector('[data-documents]');
    if (!card) return;
    const list = card.querySelector('[data-document-list]');
    list.replaceChildren();
    documents.slice(0, 2).forEach((documentData) => {
      const item = make('div', 'lab-docs-item', '');
      item.dataset.documentId = documentData.id;
      const name = make('span', 'lab-docs-name', documentData.download_name);
      name.title = documentData.download_name;
      const view = make('button', 'lab-docs-view', 'Просмотр');
      view.type = 'button';
      view.dataset.previewUrl = documentData.download_url + '?preview=1';
      const download = make('a', 'lab-docs-download', '');
      download.href = documentData.download_url;
      download.title = 'Скачать ' + documentData.download_name;
      download.setAttribute('aria-label', download.title);
      download.innerHTML = '<svg aria-hidden="true" viewBox="0 0 16 16" fill="none"><path d="M8 2v8m0 0 3-3m-3 3L5 7M3 12.5h10" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>';
      const remove = make('button', 'lab-docs-remove', '×');
      remove.type = 'button';
      remove.dataset.deleteUrl = documentData.delete_url;
      remove.setAttribute('aria-label', 'Убрать ' + documentData.download_name);
      item.append(name, view, download, remove);
      list.append(item);
    });
    card.querySelector('[data-document-count]').textContent = `${documents.length}/2`;
    card.querySelector('.lab-docs-add').disabled = documents.length >= 2;
    card.dataset.scanState = documents.length ? 'added' : 'empty';
  };

  const updateTripSummary = (trip) => {
    const rows = [...trip.querySelectorAll('[data-request-row]')];
    const partial = [], unfilled = [], noTn = [], tracker = [], driverSource = [];
    let ready = 0, timeEntered = 0;
    rows.forEach((row) => {
      const id = (row.querySelector('.logistics-request-route b')?.textContent.trim() || row.dataset.requestId).replace(/^№\s*/, '');
      const scans = row.querySelectorAll('.lab-docs-item').length;
      const tnAbsent = !!row.querySelector('.lab-docs-no-tn:checked');
      const interval = row.querySelector('.logistics-time')?.dataset.intervalState;
      const date = row.querySelector('.lab-choice--date input:checked')?.value;
      const recovery = row.querySelector('.lab-choice--recovery input:checked')?.value;
      const oo = row.querySelector('.lab-choice--oo input:checked')?.value;
      const driver = row.querySelector('.lab-choice--driver input:checked')?.value;
      const comment = row.querySelector('.logistics-comment textarea')?.value.trim();
      if (recovery === 'tracker') tracker.push(id);
      if (recovery === 'driver') driverSource.push(id);
      if (tnAbsent) noTn.push(id);
      const complete = scans && !tnAbsent && interval === 'valid' && (date === 'full' || ((date === 'partial' || date === 'no') && recovery)) && oo === 'yes' && driver === 'yes';
      if (interval === 'valid' && !tnAbsent) timeEntered++;
      if (complete) ready++;
      else if (row.querySelector('.logistics-time input')?.value.trim() || date || recovery || oo || driver || scans || comment || tnAbsent) partial.push(id);
      else unfilled.push(id);
    });
    const progress = trip.querySelector('.lab-trip-progress');
    progress.querySelector('[data-trip-progress]').textContent = `${timeEntered + noTn.length} из ${rows.length} обработано`;
    const percent = rows.length ? timeEntered / rows.length * 100 : 0;
    const noTnPercent = rows.length ? noTn.length / rows.length * 100 : 0;
    const fill = progress.querySelector('.lab-trip-progress-fill');
    fill.style.width = `${percent}%`;
    const noTnFill = progress.querySelector('.lab-trip-progress-no-tn');
    noTnFill.style.width = `${noTnPercent}%`;
    noTnFill.title = `Нет ТН: ${noTn.length}`;
    const track = progress.querySelector('.lab-trip-progress-track');
    track.classList.toggle('has-no-tn', noTn.length > 0);
    track.classList.toggle('has-no-ready', timeEntered === 0);
    track.setAttribute('aria-label', `Время внесено: ${timeEntered}; нет ТН: ${noTn.length}`);
    const share = percent <= 50 ? percent / 50 : (percent - 50) / 50;
    const first = percent <= 50 ? 'var(--danger)' : 'var(--warning-border)';
    const second = percent <= 50 ? 'var(--warning-border)' : 'var(--success)';
    fill.style.setProperty('--lab-progress-color', `color-mix(in srgb, ${first} ${Math.round((1 - share) * 100)}%, ${second})`);
    trip.dataset.ready = String(ready === rows.length);
    const hasEnteredTime = rows.some((row) => /\b\d{1,2}:\d{1,2}\b/.test(row.querySelector('.logistics-time input')?.value || ''));
    const renderBlock = (block, title, categories, visible = hasEnteredTime) => {
      block.replaceChildren(make('small', 'lab-trip-label', title));
      categories.filter(([, ids]) => ids.length).forEach(([label, ids, error]) => {
        const line = make('span', `lab-trip-breakdown-line${error ? ' lab-trip-breakdown-line--error' : ''}`, '');
        const list = make('span', 'lab-trip-breakdown-ids', ids.join(', '));
        list.title = ids.join(', ');
        line.append(make('span', 'lab-trip-breakdown-caption', label), make('b', 'lab-trip-breakdown-count', String(ids.length)), list);
        block.append(line);
      });
      block.hidden = !visible || categories.every(([, ids]) => !ids.length);
    };
    renderBlock(trip.querySelector('.lab-trip-overview'), 'Качество заполнения', [
      ['Частично заполнены', hasEnteredTime ? partial : []],
      ['Полностью не заполнены', hasEnteredTime ? unfilled : []],
      ['Нет ТН', noTn, true],
    ], hasEnteredTime || noTn.length > 0);
    renderBlock(trip.querySelector('.lab-trip-restored'), 'Восстановлено', [['По трекеру', tracker], ['Со слов вод./мен', driverSource]]);
  };

  const applySearch = () => {
    const query = (search?.value || '').toLocaleLowerCase('ru').replace(/\s+/g, ' ').trim();
    let visible = 0;
    trips.forEach((trip) => {
      const summaryMatches = [...trip.querySelectorAll('.lab-trip-identity,.lab-trip-contact')]
        .map((element) => element.textContent).join(' ').toLocaleLowerCase('ru').includes(query);
      const rows = [...trip.querySelectorAll('[data-request-row]')];
      const matches = rows.filter((row) => row.querySelector('.logistics-request-route')?.textContent.toLocaleLowerCase('ru').includes(query));
      const show = (!hideComplete?.checked || trip.dataset.ready !== 'true') && (!query || summaryMatches || matches.length > 0);
      if (!reviewerMode) trip.hidden = !show;
      if (show) visible++;
      rows.forEach((row) => { row.hidden = !!query && !summaryMatches && !matches.includes(row); });
      if (show && query && matches.length && !summaryMatches) trip.open = true;
    });
    const count = root.querySelector('.logistics-list-head span');
    if (count) count.textContent = String(visible);
    empty.hidden = visible > 0;
  };
  const updateMetrics = () => {
    const rows = [...root.querySelectorAll('[data-request-row]')];
    const ready = trips.reduce((sum, trip) => sum + [...trip.querySelectorAll('[data-request-row]')].filter((row) => row.dataset.ready === 'true').length, 0);
    const values = document.querySelectorAll('.logistics-metrics .metric-value');
    if (values.length === 4) [trips.filter((trip) => trip.dataset.ready !== 'true').length, rows.length, ready, rows.length - ready].forEach((value, index) => { values[index].textContent = String(value); });
  };
  const refresh = (trip) => {
    trip.querySelectorAll('[data-request-row]').forEach((row) => {
      const hasScan = !!row.querySelector('.lab-docs-item');
      const validTime = row.querySelector('.logistics-time')?.dataset.intervalState === 'valid';
      const date = row.querySelector('.lab-choice--date input:checked')?.value;
      const recovery = row.querySelector('.lab-choice--recovery input:checked')?.value;
      row.dataset.ready = String(hasScan && !row.querySelector('.lab-docs-no-tn:checked') && validTime && (date === 'full' || ((date === 'partial' || date === 'no') && recovery))
        && row.querySelector('.lab-choice--oo input[value="yes"]')?.checked && row.querySelector('.lab-choice--driver input[value="yes"]')?.checked);
    });
    updateTripSummary(trip);
    updateMetrics();
    applySearch();
  };

  root.querySelectorAll('[data-request-row]').forEach((row) => {
    const form = row.querySelector('[data-autosave-form]');
    const trip = row.closest('.logistics-trip');
    let timer = 0;
    let saving = false;
    let dirty = false;
    const flush = async () => {
      if (saving) { dirty = true; return; }
      saving = true;
      dirty = false;
      saveState(row, 'saving', 'Сохранение…');
      try {
        const data = await post(form.action, new FormData(form));
        saveState(row, 'saved', data.request.is_complete ? 'Собрано' : 'Сохранено');
        renderDocuments(row, data.request.documents || []);
        refresh(trip);
      } catch (error) { saveState(row, 'error', error.message); }
      finally { saving = false; if (dirty) flush(); }
    };
    const schedule = (delay) => { dirty = true; clearTimeout(timer); timer = setTimeout(flush, delay); };
    const time = form.querySelector('[name="pickup_interval"]');
    const updateTime = () => {
      const status = intervalState(time.value);
      time.closest('.logistics-time').dataset.intervalState = status;
      if (status === 'invalid') time.setAttribute('aria-invalid', 'true');
      else time.removeAttribute('aria-invalid');
    };
    updateTime();
    time.addEventListener('beforeinput', (event) => {
      if (event.inputType.startsWith('insert') && event.data !== null && /\D/.test(event.data)) event.preventDefault();
    });
    time.addEventListener('paste', (event) => {
      if (/\D/.test(event.clipboardData?.getData('text') || '')) event.preventDefault();
    });
    time.addEventListener('input', () => {
      const offset = (time.value.slice(0, time.selectionStart || 0).match(/\d/g) || []).length;
      time.value = formatInterval(time.value);
      let caret = time.value.length;
      if (offset > 0) {
        let seen = 0;
        for (let index = 0; index < time.value.length; index++) {
          if (/\d/.test(time.value[index])) seen++;
          if (seen === offset) { caret = index + 1; break; }
        }
      }
      time.setSelectionRange(caret, caret);
      updateTime();
      refresh(trip);
      if (time.closest('.logistics-time').dataset.intervalState === 'valid' || time.value === '') schedule(350);
    });
    form.querySelectorAll('.lab-choice').forEach((group) => {
      group.addEventListener('change', (event) => {
        if (event.target.checked) group.querySelectorAll('input').forEach((input) => { if (input !== event.target) input.checked = false; });
        if (group.classList.contains('lab-choice--date')) syncRecovery();
        if (group.classList.contains('lab-choice--recovery')) syncRecovery();
        refresh(trip);
      });
    });
    const syncRecovery = () => {
      const date = form.querySelector('.lab-choice--date input:checked')?.value;
      const recovery = form.querySelector('.lab-choice--recovery');
      const active = date === 'partial' || date === 'no';
      recovery.querySelectorAll('input').forEach((input) => { input.disabled = !active; if (!active) input.checked = false; });
      recovery.dataset.recoveryState = active ? (recovery.querySelector('input:checked') ? 'complete' : 'pending') : 'inactive';
      recovery.setAttribute('aria-disabled', String(!active));
    };
    syncRecovery();
    form.addEventListener('submit', (event) => { event.preventDefault(); schedule(0); });
    form.addEventListener('change', () => schedule(120));
    form.querySelector('textarea[name="comment"]').addEventListener('input', () => { refresh(trip); schedule(550); });
    row.querySelector('.lab-docs-no-tn')?.addEventListener('change', () => { refresh(trip); schedule(120); });

    const upload = row.querySelector('[data-upload-form]');
    const picker = upload.querySelector('input[type="file"]');
    upload.querySelector('.lab-docs-add').addEventListener('click', () => picker.click());
    picker.addEventListener('change', async () => {
      const files = [...picker.files];
      if (!files.length) return;
      const available = 2 - row.querySelectorAll('.lab-docs-item').length;
      const message = row.querySelector('.lab-docs-message');
      if (files.length > available) { message.textContent = 'Можно добавить не более двух сканов.'; picker.value = ''; return; }
      if (files.some((file) => !/\.(pdf|jpe?g|png|webp|tiff?)$/i.test(file.name))) { message.textContent = 'Поддерживаются PDF и изображения.'; picker.value = ''; return; }
      message.textContent = '';
      saveState(row, 'saving', 'Загрузка…');
      try {
        const data = await post(upload.action, new FormData(upload));
        renderDocuments(row, data.request.documents || []);
        saveState(row, 'saved', 'Скан добавлен');
        refresh(trip);
      } catch (error) { saveState(row, 'error', error.message); message.textContent = error.message; }
      finally { picker.value = ''; }
    });
  });

  root.addEventListener('click', async (event) => {
    const view = event.target.closest('[data-preview-url]');
    if (view) { showPreview(view); return; }
    const button = event.target.closest('[data-delete-url]');
    if (!button) return;
    if (!confirm('Убрать скан из комплекта?')) return;
    const row = button.closest('[data-request-row]');
    const trip = row.closest('.logistics-trip');
    const token = row.querySelector('[data-autosave-form] input[type="hidden"][name]');
    const body = new FormData();
    if (token) body.append(token.name, token.value);
    saveState(row, 'saving', 'Удаление…');
    try {
      const data = await post(button.dataset.deleteUrl, body);
      renderDocuments(row, data.request.documents || []);
      saveState(row, 'saved', 'Скан убран');
      refresh(trip);
    } catch (error) { saveState(row, 'error', error.message); }
  });

  trips.forEach((trip) => {
    refresh(trip);
    trip.addEventListener('toggle', () => {
      if (!trip.open || search?.value.trim()) return;
      trips.forEach((other) => { if (other !== trip) other.open = false; });
    });
  });
  search?.addEventListener('input', applySearch);
  hideComplete?.addEventListener('change', applySearch);
  applySearch();
})();
