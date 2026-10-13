/* ERP route-executor-list-v016.js listbox component, applied to two GPS filters. */
document.querySelectorAll('.route-executors-card .toolbar-sort select[data-erp-grid-sort]').forEach((native, componentIndex) => {
  if (!native || native.parentElement.classList.contains('route-sort-enhanced')) return;

  const wrap = native.parentElement;
  const choices = Array.from(native.options);
  const trigger = document.createElement('button');
  trigger.type = 'button';
  trigger.className = 'route-sort-trigger';
  trigger.setAttribute('aria-haspopup', 'listbox');
  trigger.setAttribute('aria-expanded', 'false');

  const list = document.createElement('div');
  list.className = 'route-sort-list';
  list.id = 'gps-erp-list-' + componentIndex;
  list.setAttribute('role', 'listbox');
  list.setAttribute('aria-label', native.getAttribute('aria-label'));
  list.tabIndex = -1;
  list.hidden = true;
  trigger.setAttribute('aria-controls', list.id);

  const items = choices.map((choice, index) => {
    const item = document.createElement('div');
    item.className = 'route-sort-option';
    item.id = `${list.id}-option-${index}`;
    item.setAttribute('role', 'option');
    item.dataset.value = choice.value;
    item.textContent = choice.textContent.trim();
    list.append(item);
    return item;
  });
  let activeIndex = Math.max(0, native.selectedIndex);

  const setActive = (index) => {
    activeIndex = (index + items.length) % items.length;
    items.forEach((item, itemIndex) => item.classList.toggle('is-active', itemIndex === activeIndex));
    list.setAttribute('aria-activedescendant', items[activeIndex].id);
  };
  const sync = () => {
    const selected = Math.max(0, native.selectedIndex);
    trigger.textContent = choices[selected].textContent.trim();
    trigger.setAttribute('aria-label', `${native.getAttribute('aria-label')}: ${trigger.textContent}`);
    items.forEach((item, index) => item.setAttribute('aria-selected', String(index === selected)));
    setActive(selected);
  };
  const close = (returnFocus = false) => {
    list.hidden = true;
    trigger.setAttribute('aria-expanded', 'false');
    if (returnFocus) trigger.focus();
  };
  const open = () => {
    sync();
    list.hidden = false;
    trigger.setAttribute('aria-expanded', 'true');
    list.focus();
  };
  const choose = (index) => {
    native.value = choices[index].value;
    native.dispatchEvent(new Event('change', { bubbles: true }));
    sync();
    close(true);
  };

  trigger.addEventListener('click', () => list.hidden ? open() : close(true));
  trigger.addEventListener('keydown', (event) => {
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      event.preventDefault();
      open();
      setActive(activeIndex + (event.key === 'ArrowDown' ? 1 : -1));
    } else if (event.key === 'Enter' || event.key === ' ') {
      event.preventDefault();
      open();
    }
  });
  list.addEventListener('keydown', (event) => {
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      event.preventDefault();
      setActive(activeIndex + (event.key === 'ArrowDown' ? 1 : -1));
    } else if (event.key === 'Home' || event.key === 'End') {
      event.preventDefault();
      setActive(event.key === 'Home' ? 0 : items.length - 1);
    } else if (event.key === 'Enter' || event.key === ' ') {
      event.preventDefault();
      choose(activeIndex);
    } else if (event.key === 'Escape') {
      event.preventDefault();
      close(true);
    } else if (event.key === 'Tab') {
      close();
    } else if (event.key.length === 1 && !event.ctrlKey && !event.altKey && !event.metaKey) {
      const match = items.findIndex(item => item.textContent.toLocaleLowerCase('ru').startsWith(event.key.toLocaleLowerCase('ru')));
      if (match >= 0) setActive(match);
    }
  });
  list.addEventListener('click', (event) => {
    const item = event.target.closest('.route-sort-option');
    if (item) choose(items.indexOf(item));
  });
  items.forEach((item, index) => item.addEventListener('pointerenter', () => setActive(index)));
  document.addEventListener('pointerdown', (event) => {
    if (!list.hidden && !wrap.contains(event.target)) close();
  });
  native.addEventListener('change', sync);
  wrap.append(trigger, list);
  wrap.classList.add('route-sort-enhanced');
  sync();
});
