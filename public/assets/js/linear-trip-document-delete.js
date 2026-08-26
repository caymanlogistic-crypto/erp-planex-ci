(function () {
  'use strict';

  function init(form) {
    if (!form || form.id !== 'linear-trip-edit-form' || form.dataset.tripDocumentDeleteReady === '1') return;
    form.dataset.tripDocumentDeleteReady = '1';

    var docs = form.querySelector('.linear-trip-layout-docs');
    if (!docs) return;

    docs.querySelectorAll('.document-file-row.has-existing-file').forEach(function (row) {
      if (row.querySelector('[data-linear-trip-document-delete]')) return;
      var link = row.querySelector('a[href*="/company/documents/view?id="]');
      if (!link) return;
      var url = new URL(link.href, window.location.href);
      var id = parseInt(url.searchParams.get('id') || '0', 10) || 0;
      if (!id) return;

      var button = document.createElement('button');
      button.type = 'button';
      button.className = 'file-remove predef-file-clear';
      button.title = 'Удалить документ';
      button.textContent = '×';
      button.setAttribute('data-linear-trip-document-delete', String(id));
      row.appendChild(button);
    });
  }

  document.addEventListener('click', function (event) {
    var button = event.target.closest('[data-linear-trip-document-delete]');
    if (!button) return;
    var form = button.closest('#linear-trip-edit-form');
    if (!form) return;
    var id = parseInt(button.getAttribute('data-linear-trip-document-delete') || '0', 10) || 0;
    if (!id || !window.confirm('Удалить документ?')) return;

    button.disabled = true;
    var body = new URLSearchParams();
    body.set('id', String(id));
    body.set('entity_type', 'linear_route');

    window.fetch((window.getErpBasePath ? window.getErpBasePath() : '') + '/company/documents/delete', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'},
      body: body.toString()
    }).then(function (response) {
      if (!response.ok) throw new Error('HTTP ' + response.status);
      var row = button.closest('.document-file-row');
      if (row) row.remove();
    }).catch(function () {
      button.disabled = false;
      window.alert('Не удалось удалить документ. Обновите форму и попробуйте ещё раз.');
    });
  });

  function scan(root) {
    if (!root || !root.querySelectorAll) return;
    if (root.matches && root.matches('#linear-trip-edit-form')) init(root);
    root.querySelectorAll('#linear-trip-edit-form').forEach(init);
  }

  scan(document);
  new MutationObserver(function (records) {
    records.forEach(function (record) {
      record.addedNodes.forEach(function (node) {
        if (node.nodeType === 1) scan(node);
      });
    });
  }).observe(document.body, {childList: true, subtree: true});
}());

(function () {
  'use strict';

  function ensureHoverStyle() {
    if (document.getElementById('linear-trip-route-hover-style')) return;
    var style = document.createElement('style');
    style.id = 'linear-trip-route-hover-style';
    style.textContent = '.linear-trip-registry tbody tr.is-route-hover td{background:var(--table-row-hover);}';
    document.head.appendChild(style);
  }

  function routeRow(node, body) {
    if (!node || !node.closest) return null;
    var row = node.closest('.linear-trip-registry tbody tr[data-linear-route-id]');
    return row && row.parentNode === body ? row : null;
  }

  function setRouteHover(body, routeId, active) {
    if (!routeId) return;
    body.querySelectorAll('tr[data-linear-route-id]').forEach(function (row) {
      if (row.dataset.linearRouteId === routeId) row.classList.toggle('is-route-hover', active);
    });
  }

  function bindRegistry(table) {
    if (!table || table.dataset.routeHoverReady === '1') return;
    var body = table.tBodies && table.tBodies[0];
    if (!body) return;
    table.dataset.routeHoverReady = '1';
    ensureHoverStyle();

    body.addEventListener('mouseover', function (event) {
      var row = routeRow(event.target, body);
      if (!row) return;
      var from = routeRow(event.relatedTarget, body);
      if (from && from.dataset.linearRouteId === row.dataset.linearRouteId) return;
      setRouteHover(body, row.dataset.linearRouteId, true);
    });

    body.addEventListener('mouseout', function (event) {
      var row = routeRow(event.target, body);
      if (!row) return;
      var to = routeRow(event.relatedTarget, body);
      if (to && to.dataset.linearRouteId === row.dataset.linearRouteId) return;
      setRouteHover(body, row.dataset.linearRouteId, false);
    });
  }

  function scanRegistries(root) {
    if (!root || !root.querySelectorAll) return;
    if (root.matches && root.matches('.linear-trip-registry')) bindRegistry(root);
    root.querySelectorAll('.linear-trip-registry').forEach(bindRegistry);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { scanRegistries(document); }, {once: true});
  } else {
    scanRegistries(document);
  }
}());

(function () {
  'use strict';

  function ensureMarginStyle() {
    if (document.getElementById('linear-trip-additional-margin-style')) return;
    var style = document.createElement('style');
    style.id = 'linear-trip-additional-margin-style';
    style.textContent = '#linear-trip-view-modal .trip-view-totals.has-additional-services{display:grid!important;grid-template-columns:repeat(4,minmax(0,1fr))!important;}#linear-trip-view-modal .trip-view-totals.has-additional-services .trip-view-total{min-width:0;}@media(max-width:900px){#linear-trip-view-modal .trip-view-totals.has-additional-services{grid-template-columns:repeat(2,minmax(0,1fr))!important;}}';
    document.head.appendChild(style);
  }

  function parseAmount(value) {
    var normalized = String(value == null ? '' : value)
      .replace(/[\s\u00a0\u202f]/g, '')
      .replace(',', '.')
      .replace(/[^0-9.-]/g, '');
    var amount = Number(normalized || 0);
    return Number.isFinite(amount) ? amount : 0;
  }

  function formatAmount(value) {
    return new Intl.NumberFormat('ru-RU', {maximumFractionDigits: 2}).format(value) + ' ₽';
  }

  function totalCardByLabel(totals, label) {
    return Array.from(totals.querySelectorAll('.trip-view-total')).find(function (card) {
      var node = card.querySelector('.trip-view-total-label');
      return node && node.textContent.trim() === label;
    }) || null;
  }

  function totalValue(card) {
    var node = card && card.querySelector('.trip-view-total-value');
    return node ? parseAmount(node.textContent) : 0;
  }

  function routeIdFromModal(modal) {
    var marker = modal.querySelector('[data-trip-view-title]');
    var title = marker ? String(marker.getAttribute('data-trip-view-title') || '') : '';
    var match = title.match(/#(\d+)/);
    return match ? match[1] : '';
  }

  function servicePaymentsTotal(services) {
    return (services || []).reduce(function (serviceTotal, service) {
      return serviceTotal + (service.payments || []).reduce(function (paymentTotal, payment) {
        return paymentTotal + parseAmount(payment.amount);
      }, 0);
    }, 0);
  }

  function addAdditionalServicesCard(totals, marginCard, amount) {
    var card = document.createElement('div');
    card.className = 'trip-view-total is-additional-services';
    card.innerHTML = '<div class="trip-view-total-label">Доп. услуги</div><div class="trip-view-total-value"></div>';
    card.querySelector('.trip-view-total-value').textContent = formatAmount(amount);
    totals.insertBefore(card, marginCard);
    totals.classList.add('has-additional-services');
    ensureMarginStyle();
  }

  function enhanceMargin(modal) {
    if (!modal) return;
    var totals = modal.querySelector('.trip-view-totals');
    if (!totals || totals.dataset.additionalServicesMarginReady === '1') return;
    var serverAdditionalCard = totalCardByLabel(totals, 'Доп. услуги');
    if (serverAdditionalCard) {
      totals.dataset.additionalServicesMarginReady = '1';
      totals.classList.add('has-additional-services');
      ensureMarginStyle();
      return;
    }
    var routeId = routeIdFromModal(modal);
    if (!routeId) return;
    var customerCard = totalCardByLabel(totals, 'Заказчик');
    var carrierCard = totalCardByLabel(totals, 'Перевозчик');
    var marginCard = totalCardByLabel(totals, 'Маржа');
    if (!customerCard || !carrierCard || !marginCard) return;

    totals.dataset.additionalServicesMarginReady = '1';
    var endpoint = (window.getErpBasePath ? window.getErpBasePath() : '') + '/company/trips/linear/service-lines?route_ids=' + encodeURIComponent(routeId);
    window.fetch(endpoint, {credentials: 'same-origin', headers: {Accept: 'application/json'}})
      .then(function (response) {
        if (!response.ok) throw new Error('HTTP ' + response.status);
        return response.json();
      })
      .then(function (payload) {
        if (!payload || !payload.success) return;
        var servicesByRoute = payload.services_by_route || {};
        var services = servicesByRoute[routeId] || [];
        if (!services.length) return;
        var additionalServicesTotal = servicePaymentsTotal(services);
        var customerTotal = totalValue(customerCard);
        var carrierTotal = totalValue(carrierCard);
        var margin = Math.max(0, customerTotal - carrierTotal - additionalServicesTotal);
        addAdditionalServicesCard(totals, marginCard, additionalServicesTotal);
        marginCard.querySelector('.trip-view-total-value').textContent = formatAmount(margin);
        marginCard.title = 'Маржа = заказчик − перевозчик − доп. услуги';
        marginCard.dataset.marginIncludesAdditionalServices = '1';
      })
      .catch(function () {
        delete totals.dataset.additionalServicesMarginReady;
      });
  }

  function scanMargins(root) {
    if (!root || !root.querySelectorAll) return;
    if (root.matches && root.matches('#linear-trip-view-modal')) enhanceMargin(root);
    root.querySelectorAll('#linear-trip-view-modal').forEach(enhanceMargin);
  }

  scanMargins(document);
  new MutationObserver(function (records) {
    records.forEach(function (record) {
      record.addedNodes.forEach(function (node) {
        if (node.nodeType === 1) scanMargins(node);
      });
    });
  }).observe(document.body, {childList: true, subtree: true});
}());
