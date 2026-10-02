/* Route Executors page-only controls — accepted Design Lab v016.
   Tabler trash SVG: @tabler/icons 3.48.0, MIT notice in
   app/View/pages/company_route_executors.tabler.LICENSE.txt. */
(() => {
  const native = document.querySelector('.route-executors-card .toolbar-sort select[data-erp-grid-sort]');
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
  list.id = 'route-executors-sort-list';
  list.setAttribute('role', 'listbox');
  list.setAttribute('aria-label', 'Сортировка исполнителей рейса');
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
    trigger.setAttribute('aria-label', `Сортировка по: ${trigger.textContent}`);
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
})();

(() => {
  const triggers = Array.from(document.querySelectorAll('.route-executors-card .route-review-delete.row-actions-btn[data-route-review-delete]'));
  if (!triggers.length || document.querySelector('.route-review-action-menu')) return;

  const menu = document.createElement('div');
  menu.className = 'route-review-action-menu';
  menu.id = 'route-review-action-menu';
  menu.setAttribute('role', 'menu');
  menu.setAttribute('aria-label', 'Действия с карточкой');
  menu.hidden = true;

  const remove = document.createElement('button');
  remove.type = 'button';
  remove.className = 'route-review-action-delete';
  remove.setAttribute('role', 'menuitem');
  remove.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false" class="icon icon-tabler icons-tabler-outline icon-tabler-trash"><path stroke="none" d="M0 0h24v24H0z" fill="none" /><path d="M4 7l16 0" /><path d="M10 11l0 6" /><path d="M14 11l0 6" /><path d="M5 7l1 12a2 2 0 0 0 2 2h8a2 2 0 0 0 2 -2l1 -12" /><path d="M9 7v-3a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v3" /></svg><span>Удалить карточку</span>`;
  menu.append(remove);
  document.body.append(menu);

  let activeTrigger = null;
  const close = (returnFocus = false) => {
    menu.hidden = true;
    if (activeTrigger) {
      activeTrigger.setAttribute('aria-expanded', 'false');
      if (returnFocus) activeTrigger.focus();
    }
    activeTrigger = null;
  };
  const open = (trigger) => {
    if (activeTrigger && activeTrigger !== trigger) close();
    activeTrigger = trigger;
    remove.dataset.routeReviewDelete = trigger.dataset.routeReviewDelete;
    trigger.setAttribute('aria-expanded', 'true');
    menu.hidden = false;
    const anchor = trigger.getBoundingClientRect();
    const width = menu.getBoundingClientRect().width;
    const height = menu.getBoundingClientRect().height;
    menu.style.left = `${Math.max(8, Math.min(anchor.right - width, window.innerWidth - width - 8))}px`;
    menu.style.top = `${anchor.bottom + height + 8 <= window.innerHeight ? anchor.bottom + 4 : anchor.top - height - 4}px`;
    remove.focus();
  };

  triggers.forEach((trigger) => {
    trigger.setAttribute('aria-haspopup', 'menu');
    trigger.setAttribute('aria-controls', menu.id);
    trigger.setAttribute('aria-expanded', 'false');
    trigger.setAttribute('aria-label', 'Действия с карточкой');
    trigger.removeAttribute('title');
    trigger.addEventListener('click', (event) => {
      event.preventDefault();
      event.stopImmediatePropagation();
      activeTrigger === trigger && !menu.hidden ? close(true) : open(trigger);
    }, true);
    trigger.addEventListener('keydown', (event) => {
      if (event.key === 'ArrowDown') {
        event.preventDefault();
        open(trigger);
      }
    });
  });
  remove.addEventListener('click', () => close(true));
  remove.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      event.preventDefault();
      close(true);
    } else if (event.key === 'Tab') {
      event.preventDefault();
      const next = event.shiftKey ? activeTrigger : triggers[triggers.indexOf(activeTrigger) + 1];
      close();
      next?.focus();
    }
  });
  document.addEventListener('pointerdown', (event) => {
    if (!menu.hidden && !menu.contains(event.target) && !activeTrigger?.contains(event.target)) close();
  });
  window.addEventListener('scroll', () => { if (!menu.hidden) close(); }, true);
  window.addEventListener('resize', () => { if (!menu.hidden) close(); });
})();
