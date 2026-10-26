(() => {
  const page = document.querySelector('[data-warehouse-directory]');
  if (!page) return;
  const registry = page.querySelector('[data-registry-id="warehouse-addresses"]');
  const rows = [...page.querySelectorAll('[data-warehouse-row]')];
  const count = page.querySelector('[data-warehouse-count]');
  const applySearch = () => {
    const query = (registry.querySelector('[data-registry-field="query"]')?.value || '').trim().toLocaleLowerCase('ru');
    let visible = 0;
    rows.forEach(row => { row.hidden = !row.dataset.registrySearch.toLocaleLowerCase('ru').includes(query); if (!row.hidden) visible++; });
    if (count) count.textContent = String(visible);
  };
  registry?.addEventListener('registry:state', applySearch);
  registry?.querySelector('[data-registry-field="query"]')?.addEventListener('input', applySearch);
  applySearch();
  let rowClickTimer;
  const showWarehouse = id => {
    const dialog = page.querySelector(`[data-warehouse-dialog="${id}"]`);
    if (dialog && !dialog.open) dialog.showModal();
  };
  const numberContacts = holder => {
    holder.querySelectorAll('[data-warehouse-contact]').forEach((contact, index) => {
      contact.querySelector('legend').textContent = `Контакт ${index + 1}`;
    });
  };
  page.addEventListener('click', event => {
    const open = event.target.closest('[data-warehouse-open], [data-warehouse-row]');
    if (open) {
      const id = open.dataset.warehouseOpen || open.dataset.registryRowId;
      clearTimeout(rowClickTimer);
      if (event.detail === 0) showWarehouse(id);
      else rowClickTimer = setTimeout(() => showWarehouse(id), 220);
    }
    const close = event.target.closest('[data-warehouse-close]');
    if (close) close.closest('dialog')?.close();
    const add = event.target.closest('[data-warehouse-add-contact]');
    if (add) {
      const holder = add.closest('dialog').querySelector('[data-warehouse-contacts]');
      if (holder.querySelectorAll('[data-warehouse-contact]').length >= 20) return;
      const fragment = page.querySelector('[data-warehouse-contact-template]').content.cloneNode(true);
      holder.append(fragment);
      numberContacts(holder);
      holder.lastElementChild.querySelector('[name="contact_name[]"]')?.focus();
    }
    const remove = event.target.closest('[data-warehouse-remove-contact]');
    if (remove) {
      const holder = remove.closest('[data-warehouse-contacts]');
      remove.closest('[data-warehouse-contact]')?.remove();
      numberContacts(holder);
    }
  });
  page.addEventListener('dblclick', event => {
    const row = event.target.closest('[data-warehouse-row]');
    if (!row) return;
    clearTimeout(rowClickTimer);
    showWarehouse(row.dataset.registryRowId);
  });
  page.addEventListener('keydown', event => {
    if ((event.key === 'Enter' || event.key === ' ') && event.target.matches('[data-warehouse-open]')) {
      event.preventDefault(); event.target.click();
    }
  });
})();
