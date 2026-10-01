(() => {
  const modal = document.getElementById('route-executor-create-modal');
  if (!modal) return;
  const base = window.getErpBasePath?.() || '';
  const kinds = { contractor: 'подрядчика', driver: 'водителя', vehicle: 'комплект ТС' };
  const state = Object.fromEntries(Object.keys(kinds).map(kind => [kind, { id: null, item: null, removed: new Set() }]));
  const formFor = kind => document.getElementById(`lab-${kind}-form`);
  const areaFor = kind => kind === 'vehicle' ? formFor(kind)?.querySelector('.vehicle-set-card-stack') : formFor(kind)?.querySelector('.entity-form-main');
  const rowFor = (form, field) => [...form.querySelectorAll('.source-combined-docs input[type=file]')]
    .find(input => input.name === field)?.closest('.file-item') || null;

  async function request(params) {
    const response = await fetch(`${base}/company/route-executors/review/existing?${new URLSearchParams(params)}`, { credentials: 'same-origin' });
    const result = await response.json();
    if (!response.ok || !result.ok) throw new Error(result.error || 'Не удалось загрузить справочник.');
    return result;
  }

  function unlock(kind) {
    const area = areaFor(kind);
    area?.querySelectorAll('[data-existing-locked]').forEach(control => {
      control.readOnly = false;
      control.disabled = false;
      control.removeAttribute('aria-disabled');
      control.removeAttribute('data-existing-locked');
    });
    area?.classList.remove('lab-entity-locked');
    area?.querySelector('.lab-lock-caption')?.remove();
    formFor(kind)?.querySelector('.lab-picker-trigger')?.removeAttribute('data-selected');
  }

  function lock(kind) {
    const area = areaFor(kind);
    area?.classList.add('lab-entity-locked');
    area?.querySelectorAll('input, select, textarea, button').forEach(control => {
      if (control.type === 'hidden' || control.type === 'file') return;
      control.dataset.existingLocked = '1';
      if (['INPUT','TEXTAREA'].includes(control.tagName) && !['checkbox','radio'].includes(control.type)) control.readOnly = true;
      else control.disabled = true;
      control.setAttribute('aria-disabled', 'true');
    });
    const caption = document.createElement('div');
    caption.className = 'lab-lock-caption';
    caption.textContent = 'Данные из справочника · только просмотр. Документы можно менять.';
    area?.prepend(caption);
    formFor(kind)?.querySelector('.lab-picker-trigger')?.setAttribute('data-selected', String(state[kind].id));
  }

  function clearValues(kind) {
    const form = formFor(kind);
    if (!form) return;
    if (kind === 'contractor') {
      const rows = [...form.querySelectorAll('[data-contact-row]')];
      rows.slice(1).reverse().forEach(row => row.querySelector('[data-remove-contact]')?.click());
    }
    if (kind === 'driver') {
      form.querySelectorAll('[name="extra_phones[]"]').forEach(input => input.closest('.driver-extra-phone-row')?.querySelector('.driver-extra-phone-remove')?.click());
    }
    areaFor(kind)?.querySelectorAll('input:not([type=hidden]):not([type=file]), select, textarea').forEach(control => {
      if (['checkbox','radio'].includes(control.type)) control.checked = false;
      else control.value = '';
    });
  }

  function ensureRows(kind, values) {
    const form = formFor(kind);
    if (kind === 'contractor') {
      const count = Math.max(0, ...Object.keys(values).map(name => Number(name.match(/^contacts\[(\d+)\]/)?.[1] ?? 0))) + 1;
      for (let attempt = 0; attempt < count && form.querySelectorAll('[data-contact-row]').length < count; attempt++) form.querySelector('[data-add-contact]')?.click();
    }
    if (kind === 'driver') {
      const count = values['extra_phones[]']?.length || 0;
      for (let attempt = 0; attempt < count && form.querySelectorAll('[name="extra_phones[]"]').length < count; attempt++) form.querySelector('[data-add-extra-phone-btn]')?.click();
    }
  }

  function fill(kind, item, keepUploads = false) {
    unlock(kind);
    if (!keepUploads) {
      formFor(kind)?.querySelectorAll('.source-combined-docs input[type=file]').forEach(input => { input.value = ''; });
      clearValues(kind);
    }
    ensureRows(kind, item.values);
    const assign = () => {
      const form = formFor(kind);
      for (const [name, value] of Object.entries(item.values)) {
        const values = Array.isArray(value) ? value : [value];
        const controls = [...form.elements].filter(control => control.name === name);
        values.forEach((single, index) => {
          const control = controls[index];
          if (!control) return;
          if (['checkbox','radio'].includes(control.type)) control.checked = String(single) === '1';
          else control.value = single;
          control.dispatchEvent(new Event('input', { bubbles: true }));
          control.dispatchEvent(new Event('change', { bubbles: true }));
        });
      }
      lock(kind);
      renderFiles(kind);
      modal.dispatchEvent(new Event('change', { bubbles: true }));
    };
    if (kind === 'vehicle') {
      const type = formFor(kind).elements.namedItem('set_type');
      type.value = item.values.set_type;
      type.dispatchEvent(new Event('change', { bubbles: true }));
      requestAnimationFrame(assign);
    } else assign();
  }

  function renderFiles(kind) {
    const form = formFor(kind);
    const docs = form?.querySelector('.source-combined-docs');
    if (!docs) return;
    docs.querySelectorAll('.lab-file-state, .lab-file-remove, .lab-existing-custom-row').forEach(node => node.remove());
    docs.querySelectorAll('.file-item').forEach(row => {
      row.classList.remove('lab-file-existing','lab-file-added','lab-file-replaced','lab-file-removed');
      const label = row.querySelector('.js-file-pick-btn span, label.file-action-btn span');
      if (label) label.textContent = 'Выбрать';
    });
    const item = state[kind].item;
    if (!item) return;
    for (const file of item.files) {
      const row = rowFor(form, file.field);
      const target = row || docs;
      const uploaded = row?.querySelector('input[type=file]')?.files?.length > 0;
      const removed = state[kind].removed.has(Number(file.id)) || uploaded;
      const chip = document.createElement('span');
      chip.className = 'lab-file-state';
      chip.textContent = `${uploaded ? 'Замена' : removed ? 'Убран' : 'В системе'} · ${file.original_name}`;
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'lab-file-remove';
      button.textContent = removed && !uploaded ? 'Вернуть' : 'Убрать';
      button.setAttribute('aria-label', `${button.textContent} файл ${file.original_name}`);
      button.addEventListener('click', () => {
        if (uploaded) row.querySelector('input[type=file]').value = '';
        if (!uploaded && state[kind].removed.has(Number(file.id))) state[kind].removed.delete(Number(file.id));
        else state[kind].removed.add(Number(file.id));
        renderFiles(kind);
        modal.dispatchEvent(new Event('change', { bubbles: true }));
      });
      if (row) {
        row.classList.add(uploaded ? 'lab-file-replaced' : removed ? 'lab-file-removed' : 'lab-file-existing');
        row.querySelector('.file-info')?.append(chip);
        row.append(button);
        const label = row.querySelector('.js-file-pick-btn span, label.file-action-btn span');
        if (label) label.textContent = 'Заменить';
      } else {
        const custom = document.createElement('div');
        custom.className = `file-item lab-existing-custom-row ${removed ? 'lab-file-removed' : 'lab-file-existing'}`;
        const title = document.createElement('span');
        title.textContent = file.title + ': ';
        custom.append(title, chip, button);
        docs.appendChild(custom);
      }
    }
    docs.querySelectorAll('input[type=file]').forEach(input => {
      const row = input.closest('.file-item');
      if (!row || !input.files?.length || row.querySelector('.lab-file-state')) return;
      row.classList.add('lab-file-added');
      const chip = document.createElement('span');
      chip.className = 'lab-file-state';
      chip.textContent = 'Новый файл · ' + [...input.files].map(file => file.name).join(', ');
      row.querySelector('.file-info')?.append(chip);
    });
  }

  function clear(kind) {
    unlock(kind);
    state[kind] = { id: null, item: null, removed: new Set() };
    clearValues(kind);
    formFor(kind)?.querySelectorAll('.source-combined-docs input[type=file]').forEach(input => { input.value = ''; });
    renderFiles(kind);
    modal.dispatchEvent(new Event('change', { bubbles: true }));
  }

  function init(kind) {
    const form = formFor(kind);
    const heading = form?.querySelector('.source-combined-main > .section-title');
    if (!heading || heading.querySelector('.lab-picker-trigger')) return;
    heading.classList.add('lab-entity-heading');
    const trigger = document.createElement('button');
    trigger.type = 'button';
    trigger.className = 'lab-picker-trigger';
    trigger.textContent = 'Выбрать из имеющихся';
    trigger.setAttribute('aria-label', `Выбрать ${kinds[kind]} из имеющихся`);
    trigger.setAttribute('aria-expanded', 'false');
    heading.append(trigger);
    const picker = document.createElement('div');
    picker.className = 'lab-entity-picker';
    picker.hidden = true;
    picker.innerHTML = `<div class="lab-picker-top"><label>Поиск ${kinds[kind]}<input type="search" autocomplete="off" placeholder="Начните вводить название, ФИО или номер"></label><button type="button" class="lab-picker-close" aria-label="Закрыть список">×</button></div><div class="lab-picker-results" role="listbox"></div><button type="button" class="lab-start-new" hidden>Заполнить заново</button>`;
    heading.after(picker);
    const search = picker.querySelector('input');
    const results = picker.querySelector('.lab-picker-results');
    const close = () => { picker.hidden = true; trigger.setAttribute('aria-expanded','false'); trigger.focus(); };
    let sequence = 0;
    const load = async () => {
      const current = ++sequence;
      results.textContent = 'Загрузка…';
      try {
        const { items } = await request({ kind, q: search.value });
        if (current !== sequence) return;
        results.replaceChildren();
        if (!items.length) { results.textContent = 'Ничего не найдено'; return; }
        for (const item of items) {
          const option = document.createElement('button');
          option.type = 'button'; option.setAttribute('role','option'); option.textContent = item.label;
          option.addEventListener('click', async () => {
            option.disabled = true;
            try {
              const { item: detail } = await request({ kind, id: String(item.id) });
              state[kind] = { id: detail.id, item: detail, removed: new Set() };
              fill(kind, detail);
              close();
            } catch (error) { results.textContent = error.message; option.disabled = false; }
          });
          results.append(option);
        }
      } catch (error) { if (current === sequence) results.textContent = error.message; }
    };
    trigger.addEventListener('click', () => {
      const opening = picker.hidden;
      document.querySelectorAll('.lab-entity-picker').forEach(other => { other.hidden = true; });
      document.querySelectorAll('.lab-picker-trigger').forEach(other => other.setAttribute('aria-expanded','false'));
      picker.hidden = !opening; trigger.setAttribute('aria-expanded', String(opening));
      if (opening) { search.value = ''; picker.querySelector('.lab-start-new').hidden = !state[kind].id; load(); search.focus(); }
    });
    picker.querySelector('.lab-picker-close').addEventListener('click', close);
    picker.querySelector('.lab-start-new').addEventListener('click', () => { clear(kind); close(); });
    picker.addEventListener('keydown', event => {
      if (event.key === 'Escape') { event.preventDefault(); close(); }
      if (event.key === 'Enter' && event.target === search) { event.preventDefault(); results.querySelector('[role=option]')?.click(); }
    });
    search.addEventListener('input', () => { clearTimeout(search._delay); search._delay = setTimeout(load, 180); });
    form.querySelector('.source-combined-docs')?.addEventListener('change', event => {
      if (event.target.type === 'file') { renderFiles(kind); modal.dispatchEvent(new Event('change', { bubbles: true })); }
    });
  }

  window.routeExecutorExistingParts = {
    snapshot: () => ({
      selected_entities: Object.fromEntries(Object.entries(state).filter(([, value]) => value.id).map(([kind, value]) => [kind, value.id])),
      removed_documents: Object.values(state).flatMap(value => [...value.removed]),
    }),
    hasFile: (formId, field) => Object.values(state).some(value => value.item?.formId === formId &&
      value.item.files.some(file => file.field === field && !value.removed.has(Number(file.id)) &&
        !rowFor(formFor(value.item.kind), field)?.querySelector('input[type=file]')?.files?.length)),
    fileNames: kind => Object.values(state).flatMap(value => (value.item?.files || [])
      .filter(file => kind !== 'general' || file.formId !== 'lab-contractor-form')
      .filter(file => !value.removed.has(Number(file.id)) && !rowFor(formFor(value.item.kind), file.field)?.querySelector('input[type=file]')?.files?.length)
      .map(file => file.original_name)),
    restore: async snapshot => {
      for (const [kind, id] of Object.entries(snapshot.selected_entities || {})) {
        if (!(kind in kinds)) continue;
        const { item } = await request({ kind, id: String(id) });
        state[kind] = { id: item.id, item, removed: new Set((snapshot.removed_documents || []).map(Number)) };
        fill(kind, item, true);
      }
    },
  };
  const initialize = () => Object.keys(kinds).forEach(init);
  new MutationObserver(initialize).observe(modal, { childList: true, subtree: true });
  initialize();
})();
