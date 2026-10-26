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
  page.addEventListener('click', event => {
    const open = event.target.closest('[data-warehouse-open]');
    if (open) page.querySelector(`[data-warehouse-dialog="${open.dataset.warehouseOpen}"]`)?.showModal();
    const close = event.target.closest('[data-warehouse-close]');
    if (close) close.closest('dialog')?.close();
    const add = event.target.closest('[data-warehouse-add-contact]');
    if (add) {
      const holder = add.closest('dialog').querySelector('[data-warehouse-contacts]');
      if (holder.querySelectorAll('[data-warehouse-contact]').length >= 20) return;
      const fragment = page.querySelector('[data-warehouse-contact-template]').content.cloneNode(true);
      fragment.querySelector('legend').textContent = `Контакт ${holder.querySelectorAll('[data-warehouse-contact]').length + 1}`;
      holder.append(fragment);
      holder.lastElementChild.querySelector('[name="contact_name[]"]')?.focus();
    }
    const remove = event.target.closest('[data-warehouse-remove-contact]');
    if (remove) remove.closest('[data-warehouse-contact]')?.remove();
  });
  page.addEventListener('keydown', event => {
    if ((event.key === 'Enter' || event.key === ' ') && event.target.matches('[data-warehouse-open]')) {
      event.preventDefault(); event.target.click();
    }
  });
})();
