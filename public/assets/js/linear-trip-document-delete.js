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
