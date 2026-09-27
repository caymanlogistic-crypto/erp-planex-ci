(function () {
'use strict';
  function initReadinessHeader() {
    const head = document.querySelector('#route-executor-create-modal-inner .modal-head');
    if (!head) return;
    let statuses = head.querySelector('[data-route-readiness]');
    if (!statuses) {
      statuses = document.createElement('div');
      statuses.className = 'route-readiness';
      statuses.dataset.routeReadiness = '';
      statuses.setAttribute('aria-label', 'Готовность исполнителя рейса');
      for (const [key, label] of [
        ['contract', 'Достаточно для подписания договора'],
        ['training', 'Для отправки на обучение'],
        ['vehicle', 'Для регистрации транспорта']
      ]) {
        const item = document.createElement('span');
        item.className = 'route-readiness-item';
        item.dataset.readinessKey = key;
        item.dataset.state = 'pending';
        item.textContent = label;
        item.addEventListener('animationend', () => {
          delete item.dataset.transition;
        });
        statuses.appendChild(item);
      }
      head.querySelector('.modal-title')?.after(statuses);
    }
    statuses.querySelectorAll('[data-readiness-key]').forEach(item => {
      item.dataset.state = 'pending';
      delete item.dataset.transition;
    });
  }
  function updateReadiness() {
    const contractor = document.getElementById('lab-contractor-form');
    const driver = document.getElementById('lab-driver-form');
    const vehicle = document.getElementById('lab-vehicle-form');
    if (!contractor || !driver || !vehicle) return;
    const filled = (element) => !!element && !element.disabled && String(element.value || '').trim() !== '';
    const named = (form, name) => filled(form.elements.namedItem(name));
    const hasFile = (form, name) => !!form.elements.namedItem(name)?.files?.length;

    // Every editable contractor field is required except the physical address.
    // Checkboxes describe contact roles, and contractor attachments are optional.
    const contractorFields = [...contractor.querySelectorAll('.entity-form-main input, .entity-form-main select, .entity-form-main textarea')]
      .filter(element => !['hidden', 'checkbox', 'file'].includes(element.type) && element.name !== 'physical_address');
    const contractReady = contractorFields.length > 0 && contractorFields.every(filled);
    const trainingReady = ['full_name', 'snils'].every(name => named(driver, name));

    const driverFields = [
      'full_name', 'passport_number', 'passport_department_code',
      'passport_issued_by', 'passport_issue_date', 'license_number',
      'license_issue_date', 'email'
    ];
    const driverReady = driverFields.every(name => named(driver, name)) &&
      hasFile(driver, 'predef_doc[passport][]') &&
      hasFile(driver, 'predef_doc[driver_license][]');
    const vehicleRoles = [...vehicle.querySelectorAll('.vehicle-set-unit-panel:not(.is-hidden)[data-unit-role]')];
    const vehicleReady = named(vehicle, 'set_type') && vehicleRoles.length > 0 && vehicleRoles.every(panel => {
      const role = panel.dataset.unitRole;
      const fields = ['brand', 'model', 'plate_number', 'vin', 'capacity_tons', 'volume_m3'];
      const visibleFieldsReady = fields.every(field => {
        const element = vehicle.elements.namedItem(`units[${role}][${field}]`);
        return !!element?.closest('.is-hidden') || filled(element);
      });
      return visibleFieldsReady &&
        hasFile(vehicle, `predef_doc[${role}][sts][]`) &&
        hasFile(vehicle, `predef_doc[${role}][photo][]`);
    });

    for (const [key, ready] of [
      ['contract', contractReady],
      ['training', trainingReady],
      ['vehicle', driverReady && vehicleReady]
    ]) {
      const status = document.querySelector(`[data-readiness-key="${key}"]`);
      if (!status) continue;
      const nextState = ready ? 'ready' : 'pending';
      if (status.dataset.state !== nextState) {
        status.dataset.transition = ready ? 'forward' : 'back';
        status.dataset.state = nextState;
      }
      status.setAttribute('aria-label', `${status.textContent}: ${ready ? 'готово' : 'не готово'}`);
    }
  }
  function bindReadiness() {
    const modal = document.getElementById('route-executor-create-modal');
    if (modal.dataset.readinessBound) return;
    modal.dataset.readinessBound = '1';
    modal.addEventListener('input', updateReadiness, true);
    modal.addEventListener('change', () => requestAnimationFrame(updateReadiness), true);
    modal.addEventListener('click', () => requestAnimationFrame(updateReadiness), true);
    new MutationObserver(() => requestAnimationFrame(() => {
      markRequiredDriverPhones();
      updateReadiness();
    })).observe(
      document.getElementById('route-executor-create-modal-content'),
      { subtree: true, childList: true, attributes: true, attributeFilter: ['class'] }
    );
  }
  function markRequiredDriverPhones() {
    const driver = document.getElementById('lab-driver-form');
    if (!driver) return;
    const phone = driver.elements.namedItem('phone');
    if (phone) {
      phone.required = true;
      const label = phone.closest('.field')?.querySelector('.field-label');
      if (label && !label.querySelector('.req')) {
        const mark = document.createElement('span');
        mark.className = 'req';
        mark.textContent = '*';
        label.append(' ', mark);
      }
    }
    driver.querySelectorAll('[name="extra_phones[]"]').forEach(input => {
      input.required = true;
      input.setAttribute('aria-label', 'Дополнительный телефон (обязательно)');
    });
  }

  function arrangeForms() {
    const grid = document.querySelector('#route-executor-create-modal-content .source-combined-grid');
    if (!grid || grid.dataset.arranged) return;
    grid.dataset.arranged = '1';
    for (const [key, title] of [['contractor', 'Подрядчик'], ['driver', 'Водитель'], ['vehicle', 'Комплект ТС']]) {
      const form = document.getElementById('lab-' + key + '-form');
      if (!form) continue;
      form.classList.add('source-combined-form');
      const left = document.createElement('section');
      left.className = 'source-combined-main';
      const heading = document.createElement('div');
      heading.className = 'section-title';
      heading.textContent = title;
      left.appendChild(heading);
      if (key === 'contractor') {
        const assignment = document.querySelector('#route-executor-create-modal-content > .source-combined-assignment');
        if (assignment) left.appendChild(assignment);
      }
      const right = document.createElement('section');
      right.className = 'source-combined-docs';
      if (key === 'vehicle') {
        const stack = form.querySelector('.vehicle-set-card-stack');
        if (stack) left.appendChild(stack);
        for (const role of ['primary', 'secondary']) {
          const docs = left.querySelector('[data-unit-role="' + role + '"] .vehicle-set-unit-docs');
          if (!docs) continue;
          const group = document.createElement('div');
          group.className = 'source-vehicle-doc-group';
          group.dataset.sourceDocRole = role;
          group.appendChild(docs);
          right.appendChild(group);
        }
      } else {
        const main = form.querySelector('.entity-form-main');
        const docs = form.querySelector('.entity-form-docs');
        if (main) left.appendChild(main);
        if (docs) right.appendChild(docs);
        form.querySelector('.entity-form-layout')?.remove();
      }
      const carry = [...form.children];
      form.replaceChildren(left, right, ...carry);
      if (key === 'contractor') form.querySelector('[data-field="bank_inn"]')?.remove();
    }
    window.initContactFields?.(document.getElementById('lab-contractor-form'), { fieldPrefix: 'contacts' });
    window.initLegalEntityDocuments?.(document.getElementById('lab-contractor-form'));
    const innButton = document.querySelector('#lab-contractor-form [data-inn-autofill-btn]');
    innButton?.addEventListener('click', event => {
      event.preventDefault();
      window.runLegalEntityInnLookup?.(document.getElementById('lab-contractor-form'), { button: innButton });
    });
    window.initDriverForm?.(document.getElementById('lab-driver-form'));
    markRequiredDriverPhones();
    bindReadiness();
    initReadinessHeader();
    updateReadiness();
  }

  function showFeedback(message) {
    const note = document.querySelector('#route-executor-create-modal [data-combined-feedback]');
    if (!note) return;
    note.textContent = message;
    note.classList.toggle('is-hidden', !message);
  }

  function validateBeforeCreate() {
    const contractor = document.getElementById('lab-contractor-form');
    const driver = document.getElementById('lab-driver-form');
    const vehicle = document.getElementById('lab-vehicle-form');
    const responsibleLogist = document.getElementById('route-executor-responsible-logist');
    const required = [
      [responsibleLogist, 'Выберите ответственного логиста.'],
      [contractor.elements.namedItem('name'), 'Укажите наименование подрядчика.'],
      [contractor.elements.namedItem('inn'), 'Укажите ИНН подрядчика.'],
      [driver.elements.namedItem('full_name'), 'Укажите ФИО водителя.'],
      [driver.elements.namedItem('phone'), 'Укажите телефон водителя.'],
      [vehicle.elements.namedItem('set_type'), 'Выберите тип комплекта ТС.']
    ];
    driver.querySelectorAll('[name="extra_phones[]"]').forEach(input => required.push([input, 'Укажите дополнительный телефон или удалите пустую строку.']));
    for (const [input, message] of required) {
      if (!input || String(input.value || '').trim()) continue;
      input.setCustomValidity(message);
      input.reportValidity();
      input.focus();
      input.addEventListener('input', () => input.setCustomValidity(''), { once: true });
      return false;
    }
    return true;
  }

  async function createCombined() {
    if (!validateBeforeCreate()) return;
    const button = document.querySelector('#route-executor-create-modal-foot [data-source-create-submit]');
    if (!button || button.disabled) return;
    button.disabled = true;
    button.textContent = 'Создание...';
    showFeedback('');
    const base = window.getErpBasePath?.() || '';
    try {
      const data = new FormData();
      for (const key of ['contractor', 'driver', 'vehicle']) {
        const form = document.getElementById('lab-' + key + '-form');
        for (const [name, value] of new FormData(form)) {
          if (name === 'responsible_logist_membership_id') continue;
          const targetName = value instanceof File
            ? key + '_' + name
            : key + '[' + name.replace(/\]/g, '').split('[').join('][') + ']';
          data.append(targetName, value);
        }
      }
      data.set('_route_executor_combined', '1');
      data.set('responsible_logist_membership_id', document.getElementById('route-executor-responsible-logist').value);
      const response = await fetch(base + '/company/route-executors/create', { method: 'POST', credentials: 'same-origin', body: data });
      const result = await response.json();
      if (!response.ok || !result.ok) throw new Error(result.error || 'Не удалось создать исполнителя рейса.');
      window.closeModal('route-executor-create-modal');
      window.location.reload();
    } catch (error) {
      showFeedback(error?.message || 'Не удалось создать исполнителя рейса.');
    } finally {
      button.disabled = false;
      button.textContent = 'Создать исполнителя рейса';
    }
  }

  document.addEventListener('click', event => {
    if (event.target.closest?.('[data-source-create-submit]')) createCombined();
  });
  document.addEventListener('submit', event => {
    if (!event.target.matches?.('#lab-contractor-form, #lab-driver-form, #lab-vehicle-form')) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    createCombined();
  }, true);

  window.openCombinedRouteExecutorCreate = function () {
    arrangeForms();
    window.openModal('route-executor-create-modal');
    document.querySelector('#lab-contractor-form [name="name"]')?.focus();
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', arrangeForms);
  else arrangeForms();

})();
