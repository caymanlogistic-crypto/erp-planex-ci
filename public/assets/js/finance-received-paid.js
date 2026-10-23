(() => {
  const list = document.getElementById('rp-list');
  if (!list) return;
  const rows = [...list.querySelectorAll('tr[data-id]')];
  const buttons = [...document.querySelectorAll('[data-rp-filter]')];
  const filters = document.getElementById('rp-filters');
  const search = document.getElementById('rp-search');
  const sort = document.getElementById('rp-sort');
  const showComplete = document.getElementById('rp-hide-complete');
  const money = cents => new Intl.NumberFormat('ru-RU', { minimumFractionDigits: cents % 100 ? 2 : 0, maximumFractionDigits: 2 }).format(cents / 100) + ' ₽';
  const amount = (row, key) => row.dataset[key] === '' ? null : Number(row.dataset[key]);
  let selected = 'all';
  const revealSelected = () => {
    const active = buttons.find(button => button.dataset.rpFilter === selected);
    if (!active) return;
    const strip = filters.getBoundingClientRect();
    const tab = active.getBoundingClientRect();
    if (tab.left < strip.left) filters.scrollLeft += tab.left - strip.left;
    else if (tab.right > strip.right) filters.scrollLeft += tab.right - strip.right;
  };
  const matches = (row, filter) => {
    const customerRate = amount(row, 'customerRate');
    const carrierRate = amount(row, 'carrierRate');
    const received = amount(row, 'received');
    const paid = amount(row, 'paid');
    switch (filter) {
      case 'unbilled': return row.dataset.unbilled === '1';
      case 'client-due': return ['overdue', 'today'].includes(row.dataset.clientDue);
      case 'carrier-due': return ['overdue', 'today'].includes(row.dataset.carrierDue);
      case 'paid-unreceived': return carrierRate !== null && carrierRate > 0 && paid >= carrierRate && customerRate !== null && customerRate > 0 && received < customerRate;
      case 'received-unpaid': return customerRate !== null && customerRate > 0 && received >= customerRate && carrierRate !== null && carrierRate > 0 && paid < carrierRate;
      default: return true;
    }
  };
  function render() {
    const query = search.value.trim().toLocaleLowerCase('ru');
    const eligible = rows.filter(row => (showComplete.checked || row.dataset.complete !== '1') && (!query || row.dataset.search.includes(query)));
    for (const button of buttons) {
      const filter = button.dataset.rpFilter;
      button.querySelector('b').textContent = String(eligible.filter(row => matches(row, filter)).length);
      button.classList.toggle('is-active', filter === selected);
      button.setAttribute('aria-pressed', String(filter === selected));
    }
    const visible = eligible.filter(row => matches(row, selected)).sort((a, b) => {
      if (sort.value === 'date-new' && a.dataset.end !== b.dataset.end) return b.dataset.end.localeCompare(a.dataset.end);
      return Number(b.dataset.id) - Number(a.dataset.id);
    });
    for (const row of rows) row.hidden = !visible.includes(row);
    for (const row of visible) list.append(row);
    document.getElementById('rp-count').textContent = String(visible.length);
    document.getElementById('rp-total-count').textContent = String(visible.length);
    document.getElementById('rp-empty').hidden = visible.length > 0;
    for (const [name, key] of [['customer-invoices', 'customerInvoices'], ['received', 'received'], ['customer-balance', 'customerBalance'], ['carrier-invoices', 'carrierInvoices'], ['paid', 'paid'], ['carrier-balance', 'carrierBalance']]) {
      const values = visible.map(row => amount(row, key)).filter(value => value !== null);
      const cell = document.querySelector(`[data-total="${name}"]`);
      cell.querySelector('strong').textContent = values.length ? money(values.reduce((sum, value) => sum + value, 0)) : '—';
      cell.title = values.length < visible.length ? 'Сумма только по известным значениям' : '';
    }
  }
  filters.addEventListener('click', event => {
    const button = event.target.closest('[data-rp-filter]');
    if (!button) return;
    selected = selected === button.dataset.rpFilter && selected !== 'all' ? 'all' : button.dataset.rpFilter;
    render();
    revealSelected();
  });
  window.addEventListener('resize', revealSelected);
  showComplete.addEventListener('change', render);
  sort.addEventListener('change', render);
  search.addEventListener('input', render);
  const details = document.getElementById('rp-detail-modal');
  const title = document.getElementById('rp-detail-title');
  const content = document.getElementById('rp-detail-content');
  let lastRow = null;
  new MutationObserver(() => {
    if (!details.classList.contains('is-open') && lastRow?.isConnected) {
      lastRow.focus();
      lastRow = null;
    }
  }).observe(details, { attributes: true, attributeFilter: ['class'] });
  function openDetails(row) {
    if (!row) return;
    const template = document.getElementById('rp-detail-' + row.dataset.id);
    if (!template) return;
    lastRow = row;
    title.textContent = template.dataset.title;
    content.replaceChildren(template.content.cloneNode(true));
    window.openModal('rp-detail-modal');
    details.querySelector('.modal-close')?.focus();
  }
  list.addEventListener('dblclick', event => openDetails(event.target.closest('tr[data-id]')));
  list.addEventListener('keydown', event => {
    if (event.target.matches('tr[data-id]') && (event.key === 'Enter' || event.key === ' ')) {
      event.preventDefault(); openDetails(event.target);
    }
  });
  list.addEventListener('click', event => {
    if (event.pointerType === 'touch' || event.pointerType === 'pen' || window.matchMedia('(hover:none) and (pointer:coarse)').matches) openDetails(event.target.closest('tr[data-id]'));
  });
  document.querySelector('[data-open-modal="invoice-create-modal"]')?.addEventListener('click', () => {
    const form = document.querySelector('#invoice-create-modal form.invoice-form');
    if (form) delete form.dataset.preferredTripId;
  });
  details.addEventListener('click', event => {
    const create = event.target.closest('[data-create-trip]');
    if (create) {
      const form = document.querySelector('#invoice-create-modal form.invoice-form');
      if (!form) return;
      form.dataset.preferredTripId = create.dataset.createTrip;
      const direction = form.querySelector('.js-inv-direction');
      direction.value = create.dataset.createDirection;
      direction.dispatchEvent(new Event('change', { bubbles: true }));
      const party = form.querySelector('.js-inv-cparty-select');
      party.value = create.dataset.createParty;
      party.dispatchEvent(new Event('change', { bubbles: true }));
      window.closeModal('rp-detail-modal');
      window.openModal('invoice-create-modal');
      lastRow = null;
    }
    const invoice = event.target.closest('[data-view-invoice]');
    if (invoice) {
      const body = document.getElementById('invoice-view-modal-body');
      body.textContent = 'Загрузка…';
      window.closeModal('rp-detail-modal');
      window.openModal('invoice-view-modal');
      fetch(details.dataset.invoiceBase + encodeURIComponent(invoice.dataset.viewInvoice) + '/modal-view')
        .then(response => { if (!response.ok) throw new Error('Счёт недоступен'); return response.text(); })
        .then(html => { body.innerHTML = html; })
        .catch(error => { body.textContent = error.message; });
    }
  });
  render();
})();
