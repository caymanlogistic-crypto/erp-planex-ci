(() => {
  'use strict';
  const base = window.getErpBasePath?.() || '';
  const scope = document.getElementById('route-executor-create-modal')?.dataset.workflowScope || '';
  if (!scope) return;
  let lastTrigger = null;
  let fillingIndividual = false;
  let tripSearchTimer = null;
  let tripSearchSerial = 0;
  const tripChoices = new Map();

  function bindTripPicker() {
    const input = document.getElementById('route-executor-trip-search');
    const hidden = document.getElementById('route-executor-trip-id');
    const list = document.getElementById('route-executor-trip-options');
    if (!input || !hidden || !list || input.dataset.tripBound) return;
    input.dataset.tripBound = '1';
    async function search() {
      const serial = ++tripSearchSerial;
      try {
        const response = await fetch(base + '/company/route-executors/review/config?trip_search=1&q=' + encodeURIComponent(input.value), { credentials: 'same-origin' });
        const result = await response.json();
        if (!response.ok || !result.ok || serial !== tripSearchSerial) return;
        tripChoices.clear();
        list.replaceChildren();
        for (const trip of result.trips) {
          tripChoices.set(trip.label, String(trip.id));
          const option = document.createElement('option');
          option.value = trip.label;
          list.appendChild(option);
        }
        if (tripChoices.has(input.value)) hidden.value = tripChoices.get(input.value);
      } catch (error) { console.warn('Не удалось найти рейсы', error); }
    }
    input.addEventListener('input', () => {
      hidden.value = tripChoices.get(input.value) || '';
      clearTimeout(tripSearchTimer);
      tripSearchTimer = setTimeout(search, 180);
    });
    input.addEventListener('change', () => {
      hidden.value = tripChoices.get(input.value) || '';
      input.dispatchEvent(new Event('input', { bubbles: true }));
    });
    input.addEventListener('focus', search);
  }

  function flashReadiness(item) {
    item.classList.remove('lab-readiness-pulse');
    void item.offsetWidth;
    item.classList.add('lab-readiness-pulse');
  }

  function syncIndividualFields(changed) {
    const form = document.getElementById('lab-contractor-form');
    if (!form) return;
    const name = form.elements.namedItem('name');
    const inn = form.elements.namedItem('inn');
    const type = form.elements.namedItem('contractor_type');
    const director = form.elements.namedItem('director_full_name');
    const position = form.elements.namedItem('director_position');
    const kpp = form.elements.namedItem('kpp');
    if (!name || !type || !director || !position || !kpp) return;
    if (changed === type) type.dataset.labManualType = '1';
    if (!fillingIndividual && changed === director) director.dataset.labAutoIndividual = '0';
    if (!fillingIndividual && changed === position) position.dataset.labAutoIndividual = '0';
    if (changed !== type && type.dataset.labManualType !== '1' && /^\s*ИП\s+/i.test(name.value) && /^\d{12}$/.test(String(inn?.value || '').trim()) &&
        (!type.value || type.value === 'legal_entity')) type.value = 'individual';
    const isIndividual = type.value === 'individual';
    kpp.disabled = isIndividual;
    kpp.title = isIndividual ? 'КПП для ИП не требуется' : '';
    if (!isIndividual) return;
    fillingIndividual = true;
    const fullName = String(name.value || '').replace(/^\s*ИП\s+/i, '').trim();
    if (fullName && (!director.value.trim() || director.dataset.labAutoIndividual === '1')) {
      director.value = fullName;
      director.dataset.labAutoIndividual = '1';
    }
    if (!position.value.trim() || position.dataset.labAutoIndividual === '1') {
      position.value = 'ИП';
      position.dataset.labAutoIndividual = '1';
    }
    fillingIndividual = false;
  }

  const readinessTitles = {
    contract: 'Достаточно для подписания договора',
    training: 'Для отправки на обучение',
    vehicle: 'Для регистрации транспорта'
  };
  const vehicleDriverFields = {
    full_name: 'ФИО водителя', phone: 'Телефон водителя', passport_number: 'Серия и номер паспорта',
    passport_issued_by: 'Кем выдан паспорт',
    passport_issue_date: 'Дата выдачи паспорта', license_number: 'Номер водительского удостоверения',
    license_issue_date: 'Дата выдачи водительского удостоверения', email: 'Email водителя'
  };
  const vehicleUnitFields = {
    brand: 'Марка', plate_number: 'Госномер',
    vin: 'VIN код', capacity_tons: 'Грузоподъёмность', volume_m3: 'Объём'
  };
  const filled = element => !!element && (!element.disabled || element.dataset.existingLocked === '1') && !!String(element.value || '').trim();
  const hasFile = (form, name) => {
    if (window.routeExecutorExistingParts?.hasFile?.(form?.id, name)) return true;
    const files = [...(form?.elements.namedItem(name)?.files || [])];
    const vehicleFile = name.includes('[primary]') || name.includes('[secondary]');
    const allowed = vehicleFile
      ? /\.(pdf|jpe?g|png|webp|docx?|rtf|odt|xlsx?|csv|ods|gif|bmp|tiff?|heic|heif)$/i
      : /\.(pdf|jpe?g|png|webp|docx?|rtf|odt|xlsx?|csv|ods|gif|bmp|tiff?|heic|heif|txt)$/i;
    return files.length > 0 && files.every(file => file.size > 0 && file.size <= 20 * 1024 * 1024 && allowed.test(file.name));
  };

  function readinessReport(key) {
    const fields = [];
    const files = [];
    const selected = window.routeExecutorExistingParts?.snapshot().selected_entities || {};
    if (key === 'contract') {
      if (selected.contractor) return { fields, files };
      const form = document.getElementById('lab-contractor-form');
      if (!form) return { fields, files };
      const isIndividual = form.elements.namedItem('contractor_type')?.value === 'individual';
      const required = [...form.querySelectorAll('.entity-form-main input, .entity-form-main select, .entity-form-main textarea')]
        .filter(element => !['hidden', 'checkbox', 'file'].includes(element.type) &&
          !['physical_address', 'comments'].includes(element.name) &&
          !(isIndividual && element.name === 'kpp') &&
          !element.matches('[data-contact-comment]'));
      required.filter(element => !filled(element)).forEach(element => {
        const label = element.closest('.field, .contact-cell-wrap')?.querySelector('.field-label')?.textContent?.replace('*', '').trim()
          || element.getAttribute('placeholder') || element.name;
        fields.push(label);
      });
      return { fields: [...new Set(fields)], files };
    }
    const driver = document.getElementById('lab-driver-form');
    if (!driver) return { fields, files };
    if (key === 'training') {
      if (selected.driver) return { fields, files };
      for (const [name, label] of [['full_name', 'ФИО водителя'], ['snils', 'СНИЛС водителя']]) {
        if (!filled(driver.elements.namedItem(name))) fields.push(label);
      }
      return { fields, files };
    }
    const vehicle = document.getElementById('lab-vehicle-form');
    if (!selected.driver) {
      for (const [name, label] of Object.entries(vehicleDriverFields)) {
        if (!filled(driver.elements.namedItem(name))) fields.push(label);
      }
      if (!hasFile(driver, 'predef_doc[passport][]')) files.push('Паспорт водителя');
      if (!hasFile(driver, 'predef_doc[driver_license][]')) files.push('Водительское удостоверение');
    }
    if (selected.vehicle) return { fields, files };
    if (!vehicle || !filled(vehicle.elements.namedItem('set_type'))) fields.push('Тип комплекта ТС');
    const roles = [...(vehicle?.querySelectorAll('.vehicle-set-unit-panel:not(.is-hidden)[data-unit-role]') || [])];
    for (const panel of roles) {
      const role = panel.dataset.unitRole;
      const title = panel.querySelector('[data-unit-title]')?.textContent?.trim() || 'ТС';
      for (const [name, label] of Object.entries(vehicleUnitFields)) {
        const element = vehicle.elements.namedItem(`units[${role}][${name}]`);
        if (name === 'vin' && vehicle.elements.namedItem(`units[${role}][vin_absent]`)?.checked) {
          if (filled(element)) fields.push(`${title}: очистите VIN при отметке об отсутствии`);
          continue;
        }
        if (!element?.closest('.is-hidden') && !filled(element)) fields.push(`${title}: ${label}`);
      }
      if (!hasFile(vehicle, `predef_doc[${role}][sts][]`)) files.push(`${title}: СТС`);
      if (!hasFile(vehicle, `predef_doc[${role}][photo][]`)) files.push(`${title}: фотография`);
    }
    if (!roles.length) files.push('После выбора типа комплекта: СТС и фотография каждого ТС');
    return { fields, files };
  }

  function explainContractReadiness() {
    const form = document.getElementById('lab-contractor-form');
    const status = document.querySelector('[data-readiness-key="contract"]');
    if (!form || !status) return;
    const unique = readinessReport('contract').fields;
    const message = unique.length ? `Сейчас не хватает: ${unique.join(', ')}.` : 'Все данные для этого индикатора заполнены.';
    const ready = unique.length === 0;
    const nextState = ready ? 'ready' : 'pending';
    const wasReady = status.dataset.labContractReady === '1';
    if (status.dataset.state !== nextState) {
      if (ready !== wasReady) status.dataset.transition = ready ? 'forward' : 'back';
      else delete status.dataset.transition;
      status.dataset.state = nextState;
    }
    status.dataset.labContractReady = ready ? '1' : '0';
    if (ready && !wasReady) flashReadiness(status);
    status.setAttribute('aria-label', `${status.textContent}: ${ready ? 'готово' : 'не готово'}. ${message}`);
    status.title = message;
    renderReadinessDetails();
  }

  function explainVehicleReadiness() {
    const status = document.querySelector('.route-readiness-item[data-readiness-key="vehicle"]');
    if (!status || !document.getElementById('lab-vehicle-form')) return;
    const report = readinessReport('vehicle');
    const ready = report.fields.length === 0 && report.files.length === 0;
    const nextState = ready ? 'ready' : 'pending';
    if (status.dataset.state !== nextState) {
      status.dataset.transition = ready ? 'forward' : 'back';
      status.dataset.state = nextState;
    }
    status.setAttribute('aria-label', `${status.textContent}: ${ready ? 'готово' : 'не готово'}`);
    status.title = ready ? 'Все данные для регистрации транспорта заполнены' : 'Открыть список недостающих полей и файлов';
    renderReadinessDetails();
  }

  function explainTrainingReadiness() {
    const status = document.querySelector('.route-readiness-item[data-readiness-key="training"]');
    if (!status) return;
    const report = readinessReport('training');
    const ready = report.fields.length === 0;
    const next = ready ? 'ready' : 'pending';
    if (status.dataset.state !== next) status.dataset.state = next;
    status.title = ready ? 'Все данные для обучения заполнены' : 'Открыть список недостающих полей';
  }

  function renderReadinessDetails() {
    const details = document.getElementById('lab-readiness-details');
    const key = details?.dataset.labCurrentKey;
    if (!key || details.hidden) return;
    const report = readinessReport(key);
    details.replaceChildren();
    const heading = document.createElement('strong');
    heading.className = 'lab-readiness-heading';
    heading.textContent = readinessTitles[key];
    details.appendChild(heading);
    for (const [title, items, empty] of [
      ['Поля', report.fields, 'Все нужные поля заполнены'],
      ['Обязательные файлы', report.files, key === 'vehicle' ? 'Все обязательные файлы приложены' : 'Не требуются']
    ]) {
      const section = document.createElement('section');
      section.className = 'lab-readiness-section';
      const label = document.createElement('strong');
      label.textContent = title;
      section.appendChild(label);
      if (items.length) {
        const list = document.createElement('ul');
        items.forEach(item => {
          const li = document.createElement('li');
          li.textContent = item;
          list.appendChild(li);
        });
        section.appendChild(list);
      } else {
        const complete = document.createElement('p');
        complete.textContent = empty;
        section.appendChild(complete);
      }
      details.appendChild(section);
    }
  }

  function initContractExplanation() {
    initReadinessPulse();
    const statuses = [...document.querySelectorAll('#route-executor-create-modal [data-readiness-key]')];
    const header = statuses[0]?.closest('.modal-head');
    if (!header || statuses[0].dataset.labExplanationBound) return;
    const details = document.createElement('div');
    details.id = 'lab-readiness-details';
    details.className = 'lab-readiness-details';
    details.hidden = true;
    header.appendChild(details);
    const toggle = key => {
      explainContractReadiness();
      const open = details.hidden || details.dataset.labCurrentKey !== key;
      details.hidden = !open;
      details.dataset.labCurrentKey = open ? key : '';
      statuses.forEach(item => item.setAttribute('aria-expanded', String(open && item.dataset.readinessKey === key)));
      renderReadinessDetails();
    };
    statuses.forEach(status => {
      status.dataset.labExplanationBound = '1';
      status.tabIndex = 0;
      status.setAttribute('role', 'button');
      status.setAttribute('aria-expanded', 'false');
      status.setAttribute('aria-controls', 'lab-readiness-details');
      status.addEventListener('click', () => toggle(status.dataset.readinessKey));
      status.addEventListener('keydown', event => {
        if (event.key !== 'Enter' && event.key !== ' ') return;
        event.preventDefault();
        toggle(status.dataset.readinessKey);
      });
    });
    const modal = document.getElementById('route-executor-create-modal');
    const update = event => {
      syncIndividualFields(event.target);
      explainContractReadiness();
      requestAnimationFrame(() => {
        explainTrainingReadiness();
        explainVehicleReadiness();
        renderReadinessDetails();
      });
    };
    modal?.addEventListener('input', update);
    modal?.addEventListener('change', update);
    new MutationObserver(explainContractReadiness).observe(statuses[0], { attributes: true, attributeFilter: ['data-state'] });
    new MutationObserver(explainVehicleReadiness).observe(statuses[2], { attributes: true, attributeFilter: ['data-state'] });
    syncIndividualFields();
    explainContractReadiness();
    explainTrainingReadiness();
    explainVehicleReadiness();
  }

  function initReadinessPulse() {
    const container = document.querySelector('#route-executor-create-modal [data-route-readiness]');
    if (!container || container.dataset.labPulseBound) return;
    container.dataset.labPulseBound = '1';
    const items = [...container.querySelectorAll('[data-readiness-key]')];
    const previous = new Map(items.map(item => [item, item.dataset.state]));
    let frame = 0;
    items.forEach(item => item.addEventListener('animationend', event => {
      if (event.animationName === 'lab-readiness-attention') item.classList.remove('lab-readiness-pulse');
    }));
    new MutationObserver(() => {
      if (frame) return;
      frame = requestAnimationFrame(() => {
        frame = 0;
        items.forEach(item => {
          const state = item.dataset.state;
          if (state === previous.get(item)) return;
          previous.set(item, state);
          if (item.dataset.readinessKey === 'contract') return;
          item.classList.remove('lab-readiness-pulse');
          if (state === 'ready') flashReadiness(item);
        });
      });
    }).observe(container, { subtree: true, attributes: true, attributeFilter: ['data-state'] });
  }

  document.addEventListener('click', event => {
    if (event.target.closest?.('[data-source-create-open]')) requestAnimationFrame(initContractExplanation);
  }, true);
  new MutationObserver(initContractExplanation).observe(document.body, { childList: true, subtree: true });
  const DRAFT_DB = 'planex-route-executor-drafts-' + scope;
  const activeKey = 'planex-route-executor-active-' + scope;
  let DRAFT_ID = localStorage.getItem(activeKey);
  if (!DRAFT_ID || !/^[a-f0-9-]{36}$/.test(DRAFT_ID)) {
    DRAFT_ID = crypto.randomUUID();
    localStorage.setItem(activeKey, DRAFT_ID);
  }
  const DRAFT_STORE = 'cards';
  let draft = { id: DRAFT_ID, stage: 'new', revision: 0, savedAt: null, sends: [], controls: [], files: [], signature: '' };
  let draftLoaded = false;
  function showWorkflowMessage(message) {
    const notice = document.querySelector('#route-executor-create-modal [data-combined-feedback]');
    if (!notice) return;
    notice.textContent = message;
    notice.classList.toggle('is-hidden', !message);
  }
  let workflowBusy = false;
  let pendingSend = null;

  function draftDatabase() {
    return new Promise((resolve, reject) => {
      const request = indexedDB.open(DRAFT_DB, 1);
      request.onupgradeneeded = () => request.result.createObjectStore(DRAFT_STORE, { keyPath: 'id' });
      request.onsuccess = () => resolve(request.result);
      request.onerror = () => reject(request.error);
    });
  }

  async function draftOperation(mode, operation) {
    const database = await draftDatabase();
    return new Promise((resolve, reject) => {
      const transaction = database.transaction(DRAFT_STORE, mode);
      const request = operation(transaction.objectStore(DRAFT_STORE));
      let result;
      request.onsuccess = () => { result = request.result; };
      transaction.oncomplete = () => { database.close(); resolve(result); };
      transaction.onerror = () => { database.close(); reject(transaction.error); };
      transaction.onabort = () => { database.close(); reject(transaction.error); };
    });
  }

  function captureDraft() {
    const controls = [];
    const files = [];
    for (const formId of ['lab-contractor-form', 'lab-driver-form', 'lab-vehicle-form']) {
      const form = document.getElementById(formId);
      if (!form) continue;
      const occurrences = new Map();
      for (const element of form.elements) {
        if (!element.name || element.name.startsWith('_') || ['hidden', 'button', 'submit', 'reset'].includes(element.type)) continue;
        const index = occurrences.get(element.name) || 0;
        occurrences.set(element.name, index + 1);
        const target = { formId, name: element.name, index };
        if (element.type === 'file') {
          if (element.files?.length) files.push({ ...target, selected: [...element.files] });
        } else {
          const label = element.name === 'extra_phones[]' ? 'Доп контакты' : element.name === 'extra_phone_comments[]' ? 'Комментарий к доп контактам' : element.closest('.field, .contact-cell-wrap, .vehicle-set-unit-card')
            ?.querySelector('.field-label')?.textContent?.replace('*', '').trim() || element.placeholder || element.name;
          const displayValue = element.tagName === 'SELECT' ? element.selectedOptions?.[0]?.textContent?.trim() || element.value : element.value;
          controls.push({ ...target, value: element.value, displayValue, checked: !!element.checked, type: element.type, label });
        }
      }
    }
    return { controls, files, ...window.routeExecutorExistingParts?.snapshot(), logist: document.getElementById('route-executor-responsible-logist')?.value || '',
      trip_id: document.getElementById('route-executor-trip-id')?.value || '',
      trip_label: document.getElementById('route-executor-trip-search')?.value || '' };
  }

  function draftSignature(snapshot) {
    return JSON.stringify({
      controls: snapshot.controls.map(({ formId, name, index, value, checked }) => [formId, name, index, value, checked]),
      files: snapshot.files.map(({ formId, name, index, selected }) =>
        [formId, name, index, selected.map(file => [file.name, file.size, file.lastModified])]),
      logist: snapshot.logist, trip_id: snapshot.trip_id, trip_label: snapshot.trip_label,
      selected_entities: snapshot.selected_entities || {}, removed_documents: snapshot.removed_documents || []
    });
  }

  function namedControl(formId, name, index) {
    const form = document.getElementById(formId);
    return [...(form?.elements || [])].filter(element => element.name === name)[index] || null;
  }

  function restoreDraft(snapshot) {
    const contractor = document.getElementById('lab-contractor-form');
    const driver = document.getElementById('lab-driver-form');
    const contactCount = Math.max(-1, ...snapshot.controls
      .filter(item => item.formId === 'lab-contractor-form')
      .map(item => Number(item.name.match(/^contacts\[(\d+)\]/)?.[1] ?? -1))) + 1;
    for (let attempt = 0; attempt < 20 && contractor?.querySelectorAll('[data-contact-row]').length < contactCount; attempt++) {
      const before = contractor.querySelectorAll('[data-contact-row]').length;
      contractor.querySelector('[data-add-contact]')?.click();
      if (contractor.querySelectorAll('[data-contact-row]').length === before) break;
    }
    const phoneCount = snapshot.controls.filter(item => item.formId === 'lab-driver-form' && item.name === 'extra_phones[]').length;
    for (let attempt = 0; attempt < 20 && driver?.querySelectorAll('[name="extra_phones[]"]').length < phoneCount; attempt++) {
      const before = driver.querySelectorAll('[name="extra_phones[]"]').length;
      driver.querySelector('[data-add-extra-phone-btn]')?.click();
      if (driver.querySelectorAll('[name="extra_phones[]"]').length === before) break;
    }
    const logist = document.getElementById('route-executor-responsible-logist');
    if (logist) logist.value = snapshot.logist || '';
    const tripInput = document.getElementById('route-executor-trip-search');
    const tripId = document.getElementById('route-executor-trip-id');
    if (tripInput) tripInput.value = snapshot.trip_label || '';
    if (tripId) tripId.value = snapshot.trip_id || '';
    const first = snapshot.controls.filter(item => ['set_type', 'contractor_type'].includes(item.name));
    const rest = snapshot.controls.filter(item => !['set_type', 'contractor_type'].includes(item.name));
    for (const item of [...first, ...rest]) {
      const element = namedControl(item.formId, item.name, item.index);
      if (!element) continue;
      if (item.type === 'checkbox' || item.type === 'radio') element.checked = item.checked;
      else element.value = item.value;
      element.dispatchEvent(new Event('input', { bubbles: true }));
      element.dispatchEvent(new Event('change', { bubbles: true }));
    }
    const restoredFiles = new Map();
    for (const item of snapshot.files) {
      const element = namedControl(item.formId, item.name, item.index);
      if (!element || element.type !== 'file') continue;
      // Server cards store one entry per upload; several entries can belong to
      // the same multiple control. Restore its complete FileList in one pass.
      const transfer = restoredFiles.get(element) || new DataTransfer();
      item.selected.forEach(file => transfer.items.add(file));
      restoredFiles.set(element, transfer);
    }
    for (const [element, transfer] of restoredFiles) {
      element.files = transfer.files;
      element.dispatchEvent(new Event('change', { bubbles: true }));
    }
  }

  async function persistDraft(snapshot) {
    const data = new FormData();
    data.set('action', 'save');
    data.set('card_id', DRAFT_ID);
    data.set('revision', String(draft.revision));
    data.set('snapshot', JSON.stringify(snapshot, (key, value) => key === 'selected' ? undefined : value));
    if (draft.stage !== 'final_edit') for (const item of snapshot.files) for (const file of item.selected) {
      data.append('attachments[]', file, file.name);
      data.append('attachment_keys[]', item.formId + '|' + item.name + '|' + item.index);
    }
    const response = await fetch(base + '/company/route-executors/review/send', {
      method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content || '' }, body: data
    });
    const result = await response.json();
    if (!response.ok || !result.ok) throw new Error(result.error || 'Не удалось сохранить карточку.');
    const next = {
      ...draft, ...snapshot, revision: result.revision,
      savedAt: new Date().toISOString(), signature: draftSignature(snapshot),
      contractStatus: result.contract_status || draft.contractStatus,
      contractError: result.contract_error || ''
    };
    try { await draftOperation('readwrite', store => store.put(next)); }
    catch (error) { console.warn('Локальный кэш карточки недоступен', error); }
    draft = next;
    renderWorkflow();
    return result;
  }

  async function refreshReviewList() {
    const current = document.querySelector('[data-route-executors-grid]');
    if (!current) { sessionStorage.setItem('route-review-open-' + scope, DRAFT_ID); window.location.reload(); return; }
    const response = await fetch(window.location.href, { credentials: 'same-origin' });
    if (!response.ok) return;
    const page = new DOMParser().parseFromString(await response.text(), 'text/html');
    const next = page.querySelector('[data-route-executors-grid] tbody');
    if (!next) { sessionStorage.setItem('route-review-open-' + scope, DRAFT_ID); window.location.reload(); return; }
    current.querySelector('tbody')?.replaceWith(next);
    current.dispatchEvent(new Event('erp-grid-refresh'));
  }

  function readyActions() {
    return ['contract', 'training', 'vehicle'].filter(key => {
      const report = readinessReport(key);
      return report.fields.length === 0 && report.files.length === 0;
    });
  }

  function workflowStage() {
    const ready = readyActions();
    const full = ready.length === 3;
    if (!['new', 'partial', 'partial_failed', 'final_failed'].includes(draft.stage)) return null;
    if (!document.getElementById('route-executor-responsible-logist')?.value || !document.getElementById('route-executor-trip-id')?.value) return null;
    if (full) return 'final';
    if (draft.stage === 'new' || draft.stage === 'partial_failed') {
      if (ready.some(key => key === 'training' || key === 'vehicle')) return 'partial';
    }
    return ready.includes('contract') && draft.contractStatus !== 'accepted' ? 'contract' : null;
  }

  function maxEventKey(stage) {
    return stage === 'final' ? 'route_executor.full' : 'route_executor.partial';
  }

  function formatSavedAt(value) {
    return value ? new Date(value).toLocaleString('ru-RU', {
      timeZone: 'Europe/Moscow', day: '2-digit', month: '2-digit', year: 'numeric',
      hour: '2-digit', minute: '2-digit'
    }) : '';
  }

  function renderWorkflow() {
    const modal = document.getElementById('route-executor-create-modal');
    const save = modal?.querySelector('[data-source-create-submit]');
    const send = modal?.querySelector('[data-lab-send]');
    const correction = modal?.querySelector('[data-lab-final-correction]');
    if (!save || !send) return;
    const action = workflowStage();
    save.textContent = draft.stage === 'final' ? 'Карточка завершена' : draft.stage === 'final_edit' ? 'Сохранить исправления' : draft.savedAt ? 'Сохранить изменения' : 'Сохранить черновик';
    save.disabled = workflowBusy || draft.stage === 'final' || draft.stage.includes('unknown') || draft.stage.includes('sending');
    const content = modal.querySelector('#route-executor-create-modal-content');
    if (content) content.inert = draft.stage === 'final';
    if (draft.stage === 'final_edit') {
      for (const formId of ['lab-contractor-form', 'lab-driver-form', 'lab-vehicle-form']) {
        for (const field of document.getElementById(formId)?.elements || []) {
          const editable = (formId === 'lab-contractor-form' && field.name === 'bank_corr_account')
            || (formId === 'lab-driver-form' && field.name === 'phone');
          if (!editable) field.disabled = true;
        }
      }
    }
    if (correction) {
      correction.hidden = !draft.canCorrect || !['final', 'final_edit'].includes(draft.stage);
      correction.disabled = workflowBusy;
      correction.textContent = draft.stage === 'final_edit' ? 'Сохранить и завершить исправление' : 'Открыть исправление';
    }
    send.hidden = !action;
    send.disabled = workflowBusy || !action;
    if (action) send.textContent = action === 'contract' ? 'Сохранить и отправить данные для договора'
      : action === 'partial' ? 'Сохранить и отправить первичные данные (1/2)'
      : 'Сохранить и отправить полный комплект ' + (draft.stage === 'partial' ? '(2/2)' : '(1/1)');
  }

  function initWorkflow() {
    const modal = document.getElementById('route-executor-create-modal');
    if (!modal || modal.dataset.labWorkflowBound) return;
    modal.dataset.labWorkflowBound = '1';
    bindTripPicker();
    modal.querySelector('.modal-title').textContent = 'Карточка исполнителя рейса';
    const foot = modal.querySelector('.modal-foot-actions');
    const send = document.createElement('button');
    send.type = 'button';
    send.className = 'btn btn-primary';
    send.dataset.labSend = '';
    send.hidden = true;
    foot.appendChild(send);
    const correction = document.createElement('button');
    correction.type = 'button';
    correction.className = 'btn btn-secondary';
    correction.dataset.labFinalCorrection = '';
    correction.hidden = true;
    foot.appendChild(correction);
    const save = foot.querySelector('[data-source-create-submit]');
    save.classList.remove('btn-primary');
    save.classList.add('btn-secondary');
    save.addEventListener('click', event => { save.dataset.labClicked = String(Number(save.dataset.labClicked || 0) + 1); event.preventDefault(); event.stopPropagation(); saveDraft(); });
    send.addEventListener('click', event => { event.preventDefault(); event.stopPropagation(); openWorkflowPreview(send); });
    correction.addEventListener('click', event => { event.preventDefault(); event.stopPropagation(); toggleFinalCorrection(); });
    modal.querySelector('.modal-required-note').textContent = 'Черновик можно сохранить с неполными данными';
    modal.addEventListener('input', () => requestAnimationFrame(renderWorkflow));
    modal.addEventListener('change', () => requestAnimationFrame(renderWorkflow));
    const readiness = modal.querySelector('[data-route-readiness]');
    if (readiness) new MutationObserver(() => requestAnimationFrame(renderWorkflow))
      .observe(readiness, { subtree: true, attributes: true, attributeFilter: ['data-state'] });
    renderWorkflow();
    if (draftLoaded) return;
    draftLoaded = true;
    (async () => {
      let saved = null;
      try { saved = await draftOperation('readonly', store => store.get(DRAFT_ID)); }
      catch (error) { console.warn('Локальный черновик недоступен', error); }
      try {
        const response = await fetch(base + '/company/route-executors/review/status?card_id=' + encodeURIComponent(DRAFT_ID), { credentials: 'same-origin' });
        const state = await response.json();
        if (!response.ok || !state.ok) throw new Error(state.error || 'Карточка недоступна.');
        if (state.card) {
          const files = [];
          for (const item of state.card.files) {
            const fileResponse = await fetch(base + '/company/route-executors/review/config?file_card=' + encodeURIComponent(DRAFT_ID) + '&file_id=' + encodeURIComponent(item.id), { credentials: 'same-origin' });
            if (!fileResponse.ok) throw new Error('Не удалось загрузить документы карточки.');
            const file = new File([await fileResponse.blob()], item.original_name, { type: item.mime });
            files.push({ formId: item.formId, name: item.name, index: item.index, selected: [file] });
          }
          draft = { ...draft, ...state.card.snapshot, files, revision: state.card.revision,
            stage: state.stage, canCorrect: state.correction_allowed, contractStatus: state.contract_status, savedAt: state.card.updated_at, signature: '' };
          restoreDraft(draft);
          await window.routeExecutorExistingParts?.restore(draft);
          draft.signature = draftSignature(captureDraft());
        } else if (saved?.savedAt) {
          draft = { ...saved, revision: 0, stage: state.stage, contractStatus: state.contract_status };
          restoreDraft(saved);
          await window.routeExecutorExistingParts?.restore(saved);
          await persistDraft(captureDraft());
        }
      } catch (error) {
        showWorkflowMessage('Не удалось открыть карточку: ' + error.message);
        console.warn('Не удалось открыть карточку', error);
      }
      requestAnimationFrame(() => {
        syncIndividualFields();
        explainContractReadiness();
        explainTrainingReadiness();
        explainVehicleReadiness();
        renderWorkflow();
        if (sessionStorage.getItem('route-review-open-' + scope) === DRAFT_ID) {
          sessionStorage.removeItem('route-review-open-' + scope);
          window.openCombinedRouteExecutorCreate?.();
        } else if (sessionStorage.getItem('route-review-new-' + scope) === DRAFT_ID) {
          sessionStorage.removeItem('route-review-new-' + scope);
          window.openCombinedRouteExecutorCreate?.();
        }
      });
    })();
  }

  function emailPayload(stage, snapshot) {
    const partial = stage === 'partial';
    const contract = stage === 'contract';
    const number = contract ? '' : partial ? '1/2' : draft.stage === 'partial' ? '2/2' : '1/1';
    const label = contract ? 'Данные для заключения договора' : partial ? 'Первичные данные' : 'Полный комплект';
    const fields = snapshot.controls.filter(item => (contract ? item.formId === 'lab-contractor-form' : item.formId !== 'lab-contractor-form')
      && item.value?.trim() && (item.type !== 'checkbox' || (item.name.endsWith('[vin_absent]') && item.checked)) && item.type !== 'radio')
      .map(item => {
        const element = namedControl(item.formId, item.name, item.index);
        const section = { 'lab-contractor-form': 'Подрядчик', 'lab-driver-form': 'Водитель', 'lab-vehicle-form': 'Транспорт' }[item.formId];
        const special = item.name.endsWith('[vin_absent]') ? 'VIN отсутствует' : item.name === 'full_name' ? 'ФИО' : item.name === 'snils' ? 'СНИЛС' : item.name === 'set_type' ? 'Тип комплекта'
          : item.name === 'extra_phones[]' ? 'Доп контакты' : item.name === 'extra_phone_comments[]' ? 'Комментарий к доп контактам' : '';
        const contact = item.name.match(/^contacts\[\d+\]\[(contact_person|phone|email)\]$/)?.[1];
        const contactLabel = { contact_person: 'Контактное лицо', phone: 'Телефон контакта', email: 'Email контакта' }[contact];
        const field = element?.closest('.field, .contact-cell-wrap, .vehicle-set-unit-card');
        const visibleLabel = field?.querySelector('.field-label')?.textContent?.replace('*', '').trim();
        const fallback = item.name.replace(/\[|\]/g, ' ').replace(/_/g, ' ').trim();
        const name = `${section}: ${special || contactLabel || visibleLabel || element?.placeholder || fallback}`;
        const value = item.name.endsWith('[vin_absent]') ? 'Да' : element?.tagName === 'SELECT' ? element.selectedOptions[0]?.textContent?.trim() || item.value : item.value.trim();
        return { name, value };
      });
    const files = [...snapshot.files.filter(item => contract ? item.formId === 'lab-contractor-form' : item.formId !== 'lab-contractor-form')
      .flatMap(item => item.selected.map(file => file.name)),
      ...(window.routeExecutorExistingParts?.fileNames?.(contract ? 'contract' : 'general') || [])];
    const ready = readyActions().map(key => readinessTitles[key]);
    const contractMissing = readinessReport('contract').fields;
    const driver = [...new Set([...readinessReport('training').fields, ...readinessReport('vehicle').fields.filter(value => !value.includes(':') && value !== 'Тип комплекта ТС')])];
    const vehicle = readinessReport('vehicle');
    const grouped = {
      'Подрядчик': contractMissing.filter(value => !/счёт|БИК|банк|контакт|телефон|email/i.test(value)),
      'Реквизиты': contractMissing.filter(value => /счёт|БИК|банк/i.test(value)),
      'Контакт': contractMissing.filter(value => /контакт|телефон|email/i.test(value)),
      'Водитель': driver,
      'ТС': vehicle.fields.filter(value => value.includes(':') || value === 'Тип комплекта ТС'),
      'Файлы': vehicle.files
    };
    const missing = Object.entries(grouped).filter(([, values]) => values.length)
      .map(([group, values]) => `**• ${group}:** ${[...new Set(values)].join(', ')}`);
    const subject = contract ? `${snapshot.trip_label} — Данные для заключения договора`
      : `${snapshot.trip_label} — исполнитель рейса — ${label.toLowerCase()}`;
    const find = (formId, name) => snapshot.controls.find(item => item.formId === formId && item.name === name)?.value?.trim();
    const surname=find('lab-driver-form','full_name')?.split(/\s+/)[0] || '';
    const vehicleDriver=[find('lab-vehicle-form','units[primary][plate_number]'),surname ? `(${surname})` : ''].filter(Boolean).join(' ') || '—';
    const max = contract ? '' : [partial ? '🚚 Внесены частичные данные' : '🚛 ПОЛНЫЙ КОМПЛЕКТ',
      `**${snapshot.trip_label}**`, vehicleDriver, partial ? `\n**Не хватает для полного комплекта**\n${missing.join('\n')}` : '',
      'Логист: —'].filter(Boolean).join('\n');
    return { stage, number, label, subject, max, fields, files, ready, missing, signature: draftSignature(snapshot), snapshot };
  }

  function ensureWorkflowDialog() {
    let dialog = document.getElementById('lab-route-send-dialog');
    if (dialog) return dialog;
    dialog = document.createElement('dialog');
    dialog.id = 'lab-route-send-dialog';
    dialog.className = 'lab-route-send-dialog';
    dialog.innerHTML = `<div class="lab-review-head"><strong>Передача данных исполнителя рейса</strong><button type="button" class="modal-close" data-lab-review-close aria-label="Закрыть">×</button></div>
      <div class="lab-review-body"><h3 data-lab-review-title></h3><p class="lab-review-warning" data-lab-review-warning></p>
      <div class="lab-review-summary" data-lab-review-summary></div>
      <details open><summary data-lab-email-heading>Письмо</summary><div class="lab-email-preview"><strong data-lab-email-subject></strong><p data-lab-email-lead></p><table><thead><tr><th>Поле</th><th>Значение</th></tr></thead><tbody data-lab-email-fields></tbody></table><p data-lab-email-files></p></div></details>
      <details><summary data-lab-max-heading>MAX</summary><pre data-lab-max-text></pre></details>
      <p class="lab-sandbox-note">Получатели берутся из настроек «Ключи и сервисы». Текст MAX — из шаблона события.</p>
      </div>
      <div class="lab-review-foot"><p class="lab-review-result" data-lab-review-result role="status" aria-live="polite" tabindex="-1"></p><button type="button" class="btn btn-ghost" data-lab-review-close>Вернуться к форме</button><button type="button" class="btn btn-primary" data-lab-review-confirm>Всё верно, передать</button></div>`;
    document.body.appendChild(dialog);
    dialog.addEventListener('close', () => lastTrigger?.focus());
    return dialog;
  }

  async function openWorkflowPreview(trigger) {
    const stage = workflowStage();
    if (!stage || workflowBusy) return;
    if (!document.getElementById('route-executor-responsible-logist')?.value) {
      document.getElementById('route-executor-responsible-logist')?.focus();
      showWorkflowMessage('Выберите ответственного логиста перед отправкой.');
      return;
    }
    if (!document.getElementById('route-executor-trip-id')?.value) {
      document.getElementById('route-executor-trip-search')?.focus();
      showWorkflowMessage('Выберите рейс из списка перед отправкой.');
      return;
    }
    let config;
    try {
      const response = await fetch(base + '/company/route-executors/review/config', { credentials: 'same-origin' });
      config = await response.json();
      if (!response.ok || !config.ok) throw new Error(config.error || 'Настройки отправки недоступны.');
      if (stage !== 'contract') {
        if (!config.recipients.length && !config.send_responsible_logist) throw new Error('В «Ключи и сервисы» добавьте получателей проверки исполнителей рейса.');
        const event = config.max_events?.[maxEventKey(stage)];
        if (!event?.enabled || !event.chat_config_id) throw new Error('В настройках MAX включите событие «Исполнитель рейса» и выберите чат.');
      }
    } catch (error) {
      showWorkflowMessage(error.message);
      return;
    }
    const snapshot = captureDraft();
    pendingSend = emailPayload(stage, snapshot);
    lastTrigger = trigger;
    const dialog = ensureWorkflowDialog();
    dialog.querySelector('[data-lab-review-title]').textContent = pendingSend.number ? `${pendingSend.label} · ${pendingSend.number}` : pendingSend.label;
    dialog.querySelector('[data-lab-review-warning]').textContent = stage === 'contract'
      ? 'Будет отправлено только письмо для заключения договора. Общая рассылка и MAX не запускаются.'
      : stage === 'partial'
      ? 'Это первичные данные. Ожидайте полный комплект вторым письмом. После этой отправки обновления можно сохранять, но повторно отправить частичный пакет нельзя.'
      : 'Это полный комплект. После передачи карточка будет завершена.';
    const recipients = stage === 'contract' ? ['ответственный за договор по юрлицу рейса'] : [...config.recipients];
    if (stage !== 'contract' && config.send_responsible_logist) recipients.push('ответственный логист');
    dialog.querySelector('[data-lab-review-summary]').textContent = `Готово: ${pendingSend.ready.join('; ')}. Отправитель: ${config.sender}. ${stage === 'contract' ? 'Получатель' : 'Отдельное письмо каждому получателю'}: ${recipients.join(', ')}.`;
    dialog.querySelector('[data-lab-email-heading]').textContent = (stage === 'contract' ? 'Письмо · ' : 'Отдельные письма · ') + recipients.join(', ');
    const event = config.max_events?.[maxEventKey(stage)];
    dialog.querySelector('[data-lab-max-heading]').closest('details').hidden = stage === 'contract';
    dialog.querySelector('.lab-sandbox-note').hidden = stage === 'contract';
    dialog.querySelector('[data-lab-max-heading]').textContent = event?.enabled ? 'MAX · настроенное событие' : 'MAX · событие выключено в настройках';
    const find = (formId, name) => snapshot.controls.find(item => item.formId === formId && item.name === name)?.value?.trim() || '—';
    const logist = document.getElementById('route-executor-responsible-logist')?.selectedOptions?.[0]?.textContent?.trim() || '—';
    const person=logist.replace(/\s*\([^)]*\)\s*$/,'').trim().split(/\s+/);
    const variables = { trip: snapshot.trip_label,
      vehicle_driver: [find('lab-vehicle-form', 'units[primary][plate_number]'),
        `(${find('lab-driver-form', 'full_name').split(/\s+/)[0]})`].filter(value => value !== '—' && value !== '(—)').join(' ') || '—',
      responsible: person[1] || person[0] || '—', missing: pendingSend.missing.join('\n') };
    let maxTemplate = event?.template || pendingSend.max;
    pendingSend.max = maxTemplate.replace(/\{\{\s*([a-z_]+)\s*\}\}/g, (_, key) => variables[key] || '—');
    pendingSend.fields.unshift({ name: 'Рейс', value: snapshot.trip_label });
    dialog.querySelector('[data-lab-email-subject]').textContent = pendingSend.subject;
    dialog.querySelector('.lab-email-preview').classList.toggle('is-full', stage === 'final');
    dialog.querySelector('[data-lab-email-lead]').textContent = stage === 'contract'
      ? 'Передаём данные подрядчика для заключения договора.'
      : stage === 'partial'
      ? 'Передаём первичные данные исполнителя рейса. Ожидайте полный комплект вторым письмом.'
      : `Передаём все актуальные данные исполнителя рейса${draft.stage === 'partial' ? ' с учётом первого письма' : ''}.`;
    const body = dialog.querySelector('[data-lab-email-fields]');
    body.replaceChildren();
    pendingSend.fields.forEach(field => {
      const row = document.createElement('tr');
      const name = document.createElement('td');
      const value = document.createElement('td');
      name.textContent = field.name;
      value.textContent = field.value;
      row.append(name, value);
      body.appendChild(row);
    });
    dialog.querySelector('[data-lab-email-files]').textContent = pendingSend.files.length
      ? `Один архив с файлами: ${pendingSend.files.join(', ')}` : 'Файлы не приложены.';
    dialog.querySelector('[data-lab-max-text]').textContent = pendingSend.max;
    dialog.querySelector('[data-lab-review-result]').textContent = '';
    dialog.querySelector('[data-lab-review-result]').classList.remove('is-error');
    dialog.querySelector('[data-lab-review-confirm]').disabled = false;
    dialog.showModal();
    dialog.querySelector('[data-lab-review-confirm]').focus();
  }

  async function saveDraft() {
    if (workflowBusy || draft.stage === 'final') return;
    workflowBusy = true;
    renderWorkflow();
    showWorkflowMessage('Сохраняем карточку…');
    let failure = null;
    try {
      await persistDraft(captureDraft());
      try { await refreshReviewList(); } catch (error) { console.warn('Не удалось обновить список карточек', error); }
    } catch (error) {
      console.error('Route executor draft save failed', error);
      failure = error;
    } finally {
      workflowBusy = false;
      renderWorkflow();
      showWorkflowMessage(failure ? `Не удалось сохранить карточку: ${failure.message}` : 'Карточка сохранена.');
      if (!failure) closeReviewPopups();
    }
  }

  async function toggleFinalCorrection() {
    if (workflowBusy || !draft.canCorrect || !['final', 'final_edit'].includes(draft.stage)) return;
    workflowBusy = true;
    renderWorkflow();
    let failure = null;
    try {
      if (draft.stage === 'final_edit') await persistDraft(captureDraft());
      const data = new FormData();
      data.set('action', draft.stage === 'final_edit' ? 'finish_final_correction' : 'open_final_correction');
      data.set('card_id', DRAFT_ID);
      data.set('revision', String(draft.revision));
      const response = await fetch(base + '/company/route-executors/review/send', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content || '' }, body: data
      });
      const result = await response.json();
      if (!response.ok || !result.ok) throw new Error(result.error || 'Не удалось изменить режим карточки.');
      draft.stage = result.stage;
      if (result.crew_id) {
        showWorkflowMessage(result.verified ? 'Подрядчик, водитель и ТС сохранены и проверены.' : 'Карточка внесена, но проверка записей требует внимания.');
        try { await refreshReviewList(); } catch (error) { console.warn('Не удалось обновить список исполнителей', error); }
        if (!result.verified) window.alert('Карточка внесена в справочники, но проверка подрядчика, водителя и ТС не прошла. Повторно не отправляйте данные; сообщите администратору номер исполнителя ' + result.crew_id + '.');
        closeReviewPopups();
      } else {
        showWorkflowMessage('Карточка открыта. Измените корсчёт и телефон водителя, затем завершите исправление.');
      }
    } catch (error) {
      failure = error;
    } finally {
      workflowBusy = false;
      renderWorkflow();
      if (failure) showWorkflowMessage('Не удалось завершить исправление: ' + failure.message);
    }
  }

  function closeReviewPopups() {
    lastTrigger = null;
    const dialog = document.getElementById('lab-route-send-dialog');
    if (dialog?.open) dialog.close();
    for (const id of ['route-executor-create-modal', 'route-executor-existing-modal', 'route-executor-view-modal']) {
      if (document.getElementById(id)?.classList.contains('is-open')) window.closeModal?.(id);
    }
  }

  async function confirmWorkflowSend() {
    const dialog = ensureWorkflowDialog();
    const confirm = dialog.querySelector('[data-lab-review-confirm]');
    if (!pendingSend || workflowBusy) return;
    if (workflowStage() !== pendingSend.stage || draftSignature(captureDraft()) !== pendingSend.signature) {
      const result=dialog.querySelector('[data-lab-review-result]');
      result.textContent = 'Данные изменились. Закройте окно и проверьте отправку заново.';
      result.classList.add('is-error');
      result.focus();
      confirm.disabled = true;
      return;
    }
    workflowBusy = true;
    confirm.disabled = true;
    renderWorkflow();
    try {
      await persistDraft(captureDraft());
      const data = new FormData();
      data.set('card_id', DRAFT_ID);
      if (pendingSend.stage === 'contract') data.set('action', 'contract');
      else data.set('stage', pendingSend.stage);
      data.set('revision', String(draft.revision));
      const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
      const response = await fetch(base + '/company/route-executors/review/send', {
        method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': csrf }, body: data
      });
      const result = await response.json();
      if (pendingSend.stage === 'contract' && result.contract_status === 'accepted') {
        draft = { ...draft, contractStatus: 'accepted' };
      } else if (result.stage && result.stage !== 'new') {
        const sent = { at: new Date().toISOString(), ...pendingSend, mailStatus: result.mail_status, maxStatus: result.max_status };
        draft = { ...draft, stage: result.stage, sends: [...draft.sends, sent] };
        try { await draftOperation('readwrite', store => store.put(draft)); } catch (error) { console.warn('Локальный кэш недоступен', error); }
      }
      if (!response.ok || !result.ok) {
        const recipients = Object.entries(result.recipient_statuses || {})
          .map(([address, status]) => `${address}: ${status === 'accepted' ? 'принято сервером' : status === 'failed' ? 'не принято' : 'неизвестно'}`);
        throw new Error([result.error || 'Отправка не подтверждена.', ...recipients].join(' '));
      }
      const next = draft;
      try { await draftOperation('readwrite', store => store.put(next)); } catch (error) { console.warn('Локальный кэш недоступен', error); }
      draft = next;
      dialog.querySelector('[data-lab-review-result]').textContent = pendingSend.stage === 'contract'
        ? 'Письмо для договора отправлено.'
        : result.max_status === 'sent'
        ? 'Отдельные письма приняты почтовым сервером, уведомление MAX отправлено.'
        : result.max_status === 'disabled'
          ? 'Отдельные письма приняты почтовым сервером. Событие MAX выключено в настройках.'
          : 'Отдельные письма приняты почтовым сервером. Проверьте статус уведомления MAX в настройках.';
      if (result.materialization_error) {
        const notice=dialog.querySelector('[data-lab-review-result]');
        notice.textContent = result.materialization_error;
        notice.classList.add('is-error');
        notice.focus();
      }
      pendingSend = null;
      try { await refreshReviewList(); } catch (error) { console.warn('Не удалось обновить список карточек', error); }
      if (!result.materialization_error) closeReviewPopups();
    } catch (error) {
      const result=dialog.querySelector('[data-lab-review-result]');
      result.textContent = error.message;
      result.classList.add('is-error');
      result.focus();
      confirm.disabled = !workflowStage();
    } finally {
      workflowBusy = false;
      renderWorkflow();
    }
  }

  window.startNewRouteExecutorReview = function () {
    DRAFT_ID = crypto.randomUUID();
    localStorage.setItem(activeKey, DRAFT_ID);
    sessionStorage.setItem('route-review-new-' + scope, DRAFT_ID);
    window.location.reload();
  };

  function openReviewRow(row) {
    const id = row?.dataset.routeReviewCardId;
    if (!id) return;
    if (id === DRAFT_ID) { window.openCombinedRouteExecutorCreate?.(); return; }
    localStorage.setItem(activeKey, id);
    sessionStorage.setItem('route-review-open-' + scope, id);
    window.location.reload();
  }

  async function deleteReviewRow(button) {
    const id = button.dataset.routeReviewDelete;
    if (!id || !window.confirm('Удалить карточку исполнителя рейса? История отправленных писем сохранится.')) return;
    button.disabled = true;
    try {
      const data = new FormData();
      data.set('action', 'delete');
      data.set('card_id', id);
      const response = await fetch(base + '/company/route-executors/review/send', {
        method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content || '' }, body: data
      });
      const result = await response.json();
      if (!response.ok || !result.ok) throw new Error(result.error || 'Не удалось удалить карточку.');
      if (id === DRAFT_ID) localStorage.removeItem(activeKey);
      window.location.reload();
    } catch (error) { window.alert(error.message); button.disabled = false; }
  }

  async function materializeReviewRow(button) {
    const id = button.dataset.routeReviewMaterialize;
    if (!id || !window.confirm('Внести отправленный полный комплект в справочники подрядчиков, водителей, ТС и исполнителей? Письма повторно не отправляются.')) return;
    button.disabled = true;
    try {
      const data = new FormData();
      data.set('action', 'materialize');
      data.set('card_id', id);
      const response = await fetch(base + '/company/route-executors/review/send', {
        method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content || '' }, body: data
      });
      const result = await response.json();
      if (!response.ok || !result.ok) throw new Error(result.error || 'Не удалось внести данные в справочники.');
      window.location.reload();
    } catch (error) { window.alert(error.message); button.disabled = false; }
  }

  document.addEventListener('dblclick', event => {
    if (event.target.closest?.('[data-route-review-delete], [data-route-review-materialize]')) return;
    openReviewRow(event.target.closest?.('[data-route-review-card-id]'));
  });
  document.addEventListener('keydown', event => {
    if (event.key === 'Enter' && event.target.matches?.('[data-route-review-card-id]')) openReviewRow(event.target);
  });
  document.addEventListener('click', event => {
    const materialize = event.target.closest?.('[data-route-review-materialize]');
    if (materialize) { event.stopPropagation(); materializeReviewRow(materialize); return; }
    const remove = event.target.closest?.('[data-route-review-delete]');
    if (remove) { event.stopPropagation(); deleteReviewRow(remove); }
  });

  window.addEventListener('click', event => {
    const save = event.target.closest?.('#route-executor-create-modal [data-source-create-submit]');
    const send = event.target.closest?.('#route-executor-create-modal [data-lab-send]');
    if (!save && !send) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    if (save) saveDraft();
    else openWorkflowPreview(send);
  }, true);

  window.addEventListener('submit', event => {
    if (!event.target.matches?.('#lab-contractor-form, #lab-driver-form, #lab-vehicle-form')) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    saveDraft();
  }, true);

  document.addEventListener('click', event => {
    if (event.target.closest?.('[data-lab-review-close]')) ensureWorkflowDialog().close();
    if (event.target.closest?.('[data-lab-review-confirm]')) confirmWorkflowSend();
  }, true);

  initWorkflow();
  new MutationObserver(initWorkflow).observe(document.body, { childList: true, subtree: true });
})();
