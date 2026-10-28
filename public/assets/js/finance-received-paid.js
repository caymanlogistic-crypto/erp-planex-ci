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
  const viewModal = document.getElementById('invoice-view-modal');
  const viewBody = document.getElementById('invoice-view-modal-body');
  const createModal = document.getElementById('invoice-create-modal');
  let lastRow = null;
  const focusBeforeOpen = new WeakMap();
  for (const overlay of [details, createModal, viewModal]) {
    if (!overlay) continue;
    let wasOpen = overlay.classList.contains('is-open');
    overlay.setAttribute('aria-hidden', wasOpen ? 'false' : 'true');
    new MutationObserver(() => {
      const isOpen = overlay.classList.contains('is-open');
      if (isOpen === wasOpen) return;
      wasOpen = isOpen;
      overlay.setAttribute('aria-hidden', isOpen ? 'false' : 'true');
      if (isOpen) {
        focusBeforeOpen.set(overlay, document.activeElement);
        overlay.querySelector('.modal-close')?.focus();
      } else {
        const previous = focusBeforeOpen.get(overlay);
        const target = overlay === details ? lastRow : previous?.isConnected && previous !== document.body && previous.getClientRects().length ? previous : lastRow;
        if (target?.isConnected && target.getClientRects().length) target.focus();
        if (overlay === details && !createModal?.classList.contains('is-open') && !viewModal?.classList.contains('is-open')) lastRow = null;
      }
    }).observe(overlay, { attributes: true, attributeFilter: ['class'] });
    overlay.addEventListener('keydown', event => {
      if (event.key !== 'Tab' || !overlay.classList.contains('is-open')) return;
      const focusable = [...overlay.querySelectorAll('button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),a[href]')]
        .filter(node => node.getClientRects().length && getComputedStyle(node).visibility !== 'hidden');
      if (!focusable.length) return;
      if (event.shiftKey && document.activeElement === focusable[0]) { event.preventDefault(); focusable.at(-1).focus(); }
      else if (!event.shiftKey && document.activeElement === focusable.at(-1)) { event.preventDefault(); focusable[0].focus(); }
    });
  }
  const createForm = createModal?.querySelector('form.invoice-form');
  if (createForm) {
    createForm.classList.add('registry-ui-modal-form');
    const formBody = createForm.querySelector('.modal-body');
    formBody?.classList.add('rp-lab-invoice-form-body');
    formBody?.querySelectorAll('.section-title').forEach(heading => heading.classList.add('page-eyebrow'));
    let field = 0;
    for (const label of createForm.querySelectorAll('.field > .field-label')) {
      const control = label.nextElementSibling;
      if (!control?.matches('input,select,textarea')) continue;
      const id = `rp-invoice-field-${++field}`;
      control.id = id;
      label.htmlFor = id;
    }
    const foot = createForm.querySelector('.modal-foot');
    if (foot) {
      const actions = document.createElement('div');
      actions.className = 'modal-foot-actions';
      actions.append(...foot.querySelectorAll('button'));
      foot.replaceChildren(actions);
      foot.classList.remove('is-spaced');
    }
  }
  function transformInvoiceView() {
    const original = viewBody?.querySelector('.driver-modal-body');
    if (!original) return;
    const heading = original.querySelector('.driver-view-name');
    if (heading) viewModal.querySelector('.modal-title').textContent = heading.textContent.trim();
    const root = document.createElement('div');
    root.className = 'registry-ui-modal-grid';
    const section = label => {
      const node = document.createElement('section');
      node.className = 'registry-ui-modal-grid';
      const eyebrow = document.createElement('span');
      eyebrow.className = 'page-eyebrow';
      eyebrow.textContent = label;
      node.append(eyebrow);
      return node;
    };
    const facts = section('Данные счёта');
    facts.classList.add('registry-ui-modal-grid--reading', 'rp-lab-facts');
    for (const row of original.querySelectorAll('.driver-view-row')) {
      const metric = document.createElement('div');
      metric.className = 'rp-lab-metric';
      const label = document.createElement('span');
      label.className = 'registry-ui-modal-secondary';
      label.textContent = row.querySelector('.driver-view-cell-label')?.textContent.trim() || '';
      const value = row.querySelector('.driver-view-cell-value');
      if (value) { value.className = 'registry-ui-modal-text'; metric.append(label, value); }
      facts.append(metric);
    }
    root.append(facts);
    for (const headingNode of [...original.querySelectorAll('.section-title')]) {
      const group = section(headingNode.querySelector('span')?.textContent.trim() || headingNode.textContent.trim());
      const button = headingNode.querySelector('button');
      if (button) {
        const header = document.createElement('div');
        header.className = 'rp-lab-section-head';
        header.append(group.firstElementChild, button);
        group.prepend(header);
      }
      let next = headingNode.nextElementSibling;
      while (next && !next.classList.contains('section-title')) {
        const following = next.nextElementSibling;
        group.append(next);
        next = following;
      }
      root.append(group);
    }
    const incomingFoot = viewBody.querySelector(':scope > .modal-foot');
    viewModal.querySelector(':scope > .modal')?.querySelector(':scope > .modal-foot')?.remove();
    if (incomingFoot) {
      incomingFoot.querySelectorAll('[data-invoice-edit-btn],[data-invoice-cancel-btn]').forEach(button => button.remove());
      incomingFoot.classList.remove('is-spaced');
      const actions = document.createElement('div');
      actions.className = 'modal-foot-actions';
      actions.append(...incomingFoot.querySelectorAll('button'));
      incomingFoot.replaceChildren(actions);
      viewModal.querySelector(':scope > .modal')?.append(incomingFoot);
    }
    const style = viewBody.querySelector(':scope > style');
    viewBody.replaceChildren(...(style ? [style] : []), root);
  }
  function setHtmlAndRunScripts(target, html) {
    target.innerHTML = html;
    target.querySelectorAll('script').forEach(source => {
      const script = document.createElement('script');
      for (const attribute of source.attributes) script.setAttribute(attribute.name, attribute.value);
      script.textContent = source.textContent;
      source.replaceWith(script);
    });
  }
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
    }
    const invoice = event.target.closest('[data-view-invoice]');
    if (invoice) {
      const body = document.getElementById('invoice-view-modal-body');
      body.textContent = 'Загрузка…';
      window.closeModal('rp-detail-modal');
      window.openModal('invoice-view-modal');
      fetch(details.dataset.invoiceBase + encodeURIComponent(invoice.dataset.viewInvoice) + '/modal-view')
        .then(response => { if (!response.ok) throw new Error('Счёт недоступен'); return response.text(); })
        .then(html => {
          setHtmlAndRunScripts(body, html);
          transformInvoiceView();
        })
        .catch(error => { body.textContent = error.message; });
    }
  });
  render();
})();
