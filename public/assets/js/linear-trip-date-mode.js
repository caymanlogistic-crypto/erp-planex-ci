(function () {
  'use strict';

  function isEditForm(form) {
    return !!(form && (form.id === 'linear-trip-edit-form' || /\/modal-edit(?:$|\?)/.test(String(form.action || ''))));
  }

  function value(input) {
    return input ? String(input.value || '').trim() : '';
  }

  function nativeDateValue(raw) {
    var date = String(raw || '').trim();
    if (/^\d{4}-\d{2}-\d{2}$/.test(date)) return date;
    var match = date.match(/^(\d{2})\.(\d{2})\.(\d{4})$/);
    if (match) return match[3] + '-' + match[2] + '-' + match[1];
    return '';
  }

  function ensureBackingInput(form, name) {
    var input = form.querySelector('[name="' + name + '"]');
    if (input) return input;
    input = document.createElement('input');
    input.type = 'hidden';
    input.name = name;
    input.value = '';
    input.setAttribute('data-trip-date-generated-backing', '1');
    form.appendChild(input);
    return input;
  }

  function setActive(control, kind) {
    var hidden = control.querySelector('[data-trip-date-kind-value]');
    if (hidden) hidden.value = kind || '';
    control.querySelectorAll('[data-trip-date-kind]').forEach(function (button) {
      var active = button.getAttribute('data-trip-date-kind') === kind;
      button.classList.toggle('is-active', active);
      button.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
  }

  function selectedKind(control) {
    var hidden = control.querySelector('[data-trip-date-kind-value]');
    return hidden ? String(hidden.value || '') : '';
  }

  function backingFor(control, kind) {
    var planName = control.getAttribute('data-plan-name');
    var factName = control.getAttribute('data-fact-name');
    var form = control.closest('form[data-linear-trip-form]');
    if (!form) return null;
    return form.querySelector('[name="' + (kind === 'fact' ? factName : planName) + '"]');
  }

  function syncVisibleToBacking(control) {
    var kind = selectedKind(control);
    if (!kind) return;
    var visible = control.querySelector('[data-trip-date-visible]');
    var backing = backingFor(control, kind);
    if (visible && backing) backing.value = visible.value || '';
  }

  function chooseKind(control, kind) {
    var edge = control.getAttribute('data-trip-date-control');
    var current = selectedKind(control);
    if (edge === 'end' && current === kind) {
      var form = control.closest('form[data-linear-trip-form]');
      var visible = control.querySelector('[data-trip-date-visible]');
      var plan = form ? form.querySelector('[name="planned_unloading_date"]') : null;
      var fact = form ? form.querySelector('[name="actual_unloading_date"]') : null;
      if (plan) plan.value = '';
      if (fact) fact.value = '';
      if (visible) visible.value = '';
      setActive(control, '');
      return;
    }
    syncVisibleToBacking(control);
    setActive(control, kind);
    var target = backingFor(control, kind);
    var visibleInput = control.querySelector('[data-trip-date-visible]');
    if (visibleInput) {
      visibleInput.value = nativeDateValue(value(target));
      visibleInput.focus();
    }
  }

  function createControl(form, edge, planInput, factInput, initialKind) {
    var control = document.createElement('div');
    control.className = 'field linear-trip-date-control';
    control.setAttribute('data-trip-date-control', edge);
    control.setAttribute('data-plan-name', planInput.name);
    control.setAttribute('data-fact-name', factInput.name);
    var label = edge === 'start' ? 'Начало рейса' : 'Окончание рейса';
    var required = edge === 'start' ? ' <span class="req">*</span>' : '';
    var hiddenName = edge === 'start' ? 'start_date_kind' : 'end_date_kind';
    control.innerHTML = '' +
      '<label class="field-label">' + label + required + '</label>' +
      '<div class="linear-trip-date-shell">' +
        '<input type="date" class="field-input" data-trip-date-visible' + (edge === 'start' ? ' required' : '') + '>' +
        '<div class="linear-trip-date-modes" role="group" aria-label="' + label + ': тип даты">' +
          '<button type="button" class="linear-trip-route-point-toggle" data-trip-date-kind="fact" aria-pressed="false">Факт</button>' +
          '<button type="button" class="linear-trip-route-point-toggle" data-trip-date-kind="plan" aria-pressed="false">План</button>' +
        '</div>' +
      '</div>' +
      '<input type="hidden" name="' + hiddenName + '" data-trip-date-kind-value>' +
      '<div class="field-msg" data-trip-date-message></div>';

    setActive(control, initialKind);
    var visible = control.querySelector('[data-trip-date-visible]');
    var initialBacking = initialKind ? (initialKind === 'fact' ? factInput : planInput) : null;
    if (visible && initialBacking) visible.value = nativeDateValue(value(initialBacking));
    control.querySelectorAll('[data-trip-date-kind]').forEach(function (button) {
      button.addEventListener('click', function () { chooseKind(control, button.getAttribute('data-trip-date-kind')); });
    });
    if (visible) visible.addEventListener('input', function () { syncVisibleToBacking(control); });
    return control;
  }

  function unhideCargo(form) {
    var cargo = form.querySelector('input[name="cargo_type_name"]');
    if (!cargo) return null;
    var field = cargo.closest('.field');
    if (field) {
      field.hidden = false;
      field.classList.remove('is-hidden');
      field.style.removeProperty('display');
      var label = field.querySelector('.field-label');
      if (label && label.dataset.tripCargoLabelReady !== '1') {
        label.innerHTML = 'Перевозимый груз <span class="req">*</span>';
        label.dataset.tripCargoLabelReady = '1';
      }
    }
    if (!isEditForm(form) && form.dataset.tripCargoNormalized !== '1' && value(cargo) === 'Не указан') {
      cargo.value = '';
    }
    form.dataset.tripCargoNormalized = '1';
    return field;
  }

  function initForm(form) {
    if (!form || !form.matches('form[data-linear-trip-form]')) return;
    var planStart = form.querySelector('[name="planned_loading_date"]');
    var planEnd = ensureBackingInput(form, 'planned_unloading_date');
    var factStart = ensureBackingInput(form, 'actual_loading_date');
    var factEnd = ensureBackingInput(form, 'actual_unloading_date');
    var client = form.querySelector('[name="client_id"]');
    var executor = form.querySelector('[name="route_executor_id"]');
    var primaryRow = planStart ? (planStart.closest('.linear-trip-primary-row') || form.querySelector('.linear-trip-primary-row')) : null;
    if (!planStart || !planEnd || !factStart || !factEnd || !client || !executor || !primaryRow) return;

    if (form.dataset.tripDateModeReady === '1') return;
    form.dataset.tripDateModeReady = '1';

    [planStart, planEnd, factStart, factEnd].forEach(function (input) {
      input.required = false;
      var field = input.closest('.field');
      if (field) {
        field.hidden = true;
        field.classList.add('is-hidden', 'linear-trip-date-backing');
      }
    });

    var edit = isEditForm(form);
    var startKind = value(factStart) ? 'fact' : (value(planStart) ? 'plan' : 'plan');
    var endKind = value(factEnd) ? 'fact' : (value(planEnd) ? 'plan' : '');
    if (!edit && !value(factStart) && !value(planStart)) startKind = 'plan';
    if (!edit && !value(factEnd) && !value(planEnd)) endKind = '';

    var dateRow = document.createElement('div');
    dateRow.className = 'field-row field-row-group linear-trip-date-row';
    dateRow.appendChild(createControl(form, 'start', planStart, factStart, startKind));
    dateRow.appendChild(createControl(form, 'end', planEnd, factEnd, endKind));
    primaryRow.parentNode.insertBefore(dateRow, primaryRow);

    primaryRow.classList.add('linear-trip-participant-row');

    var cargoField = unhideCargo(form);
    if (cargoField) {
      var cargoRow = document.createElement('div');
      cargoRow.className = 'field-row field-row-group linear-trip-cargo-row';
      cargoRow.appendChild(cargoField);
      var agencyToggle = primaryRow.nextElementSibling && primaryRow.nextElementSibling.classList.contains('linear-trip-agency-toggle') ? primaryRow.nextElementSibling : null;
      if (agencyToggle) agencyToggle.parentNode.insertBefore(cargoRow, agencyToggle);
      else primaryRow.parentNode.insertBefore(cargoRow, primaryRow.nextSibling);
    }

    form.addEventListener('submit', function () {
      var endControl = form.querySelector('[data-trip-date-control="end"]');
      if (endControl && !selectedKind(endControl)) {
        var endVisible = endControl.querySelector('[data-trip-date-visible]');
        if (value(endVisible)) {
          setActive(endControl, value(factEnd) ? 'fact' : 'plan');
        }
      }
      form.querySelectorAll('[data-trip-date-control]').forEach(syncVisibleToBacking);
      if (endControl && !selectedKind(endControl)) {
        planEnd.value = '';
        factEnd.value = '';
      }
      var cargo = form.querySelector('input[name="cargo_type_name"]');
      if (!edit && cargo && value(cargo) === 'Не указан') cargo.value = '';
    }, true);
  }

  function scan(root) {
    if (!root || !root.querySelectorAll) return;
    if (root.matches && root.matches('form[data-linear-trip-form]')) initForm(root);
    if (root.closest) {
      var parentForm = root.closest('form[data-linear-trip-form]');
      if (parentForm) initForm(parentForm);
    }
    root.querySelectorAll('form[data-linear-trip-form]').forEach(initForm);
  }

  var observer = new MutationObserver(function (records) {
    records.forEach(function (record) {
      record.addedNodes.forEach(function (node) {
        if (node.nodeType === Node.ELEMENT_NODE) scan(node);
      });
    });
  });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      scan(document);
      observer.observe(document.body, {childList: true, subtree: true});
    }, {once: true});
  } else {
    scan(document);
    observer.observe(document.body, {childList: true, subtree: true});
  }
})();

(function () {
  var source = document.currentScript && document.currentScript.src ? document.currentScript.src : '';
  if (!source) return;
  var script = document.createElement('script');
  script.src = source.replace('linear-trip-date-mode.js', 'linear-trip-document-delete.js');
  script.defer = true;
  document.head.appendChild(script);
}());

(function () {
  'use strict';

  function ensureTitleStyle() {
    if (document.getElementById('linear-trip-modal-title-style')) return;
    var style = document.createElement('style');
    style.id = 'linear-trip-modal-title-style';
    style.textContent = '#linear-trip-create-modal .modal-title,#linear-trip-view-modal .modal-title{font-family:"IBM Plex Sans","Segoe UI",Arial,sans-serif!important;font-size:19px!important;font-weight:700!important;letter-spacing:-0.035em!important;line-height:1!important;color:var(--text-main)!important;}';
    document.head.appendChild(style);
  }

  function setTitle(shell, text) {
    if (!shell || !text) return;
    var title = shell.querySelector('.modal-title');
    if (title && title.textContent !== text) title.textContent = text;
  }

  function editRouteId(form) {
    if (!form) return '';
    var match = String(form.action || '').match(/\/linear\/(\d+)\/modal-edit(?:$|\?)/);
    if (match) return match[1];
    var idInput = form.querySelector('[name="id"]');
    return idInput ? String(idInput.value || '').trim() : '';
  }

  function syncTitles() {
    ensureTitleStyle();
    var createShell = document.getElementById('linear-trip-create-modal');
    if (createShell) setTitle(createShell, 'Создание нового рейса');

    var shell = document.getElementById('linear-trip-view-modal');
    if (!shell) return;

    var editForm = shell.querySelector('#linear-trip-edit-form');
    if (editForm) {
      var editId = editRouteId(editForm);
      setTitle(shell, 'Редактирование данных рейса' + (editId ? ' #' + editId : ''));
      return;
    }

    var marker = shell.querySelector('[data-trip-view-title]');
    if (marker) {
      var markerTitle = String(marker.getAttribute('data-trip-view-title') || '').trim();
      if (markerTitle) setTitle(shell, markerTitle);
    }
  }

  syncTitles();
  new MutationObserver(function (records) {
    for (var i = 0; i < records.length; i += 1) {
      if (records[i].type === 'childList' && records[i].addedNodes.length) {
        syncTitles();
        break;
      }
    }
  }).observe(document.body, {childList: true, subtree: true});
}());
