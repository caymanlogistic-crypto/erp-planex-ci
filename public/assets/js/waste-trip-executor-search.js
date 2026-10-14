(() => {
  let nextId = 0;

  window.initWasteExecutorSearch = root => {
    root.querySelectorAll('select[data-waste-executor-search]').forEach(select => {
      if (select.dataset.searchReady === '1') return;
      select.dataset.searchReady = '1';

      const field = select.closest('.waste-form-field');
      const label = field.querySelector('label');
      const wrap = document.createElement('div');
      const input = document.createElement('input');
      const list = document.createElement('div');
      const listId = `waste-executor-options-${++nextId}`;
      wrap.className = 'waste-executor-picker';
      input.type = 'search';
      input.className = 'waste-executor-input';
      input.autocomplete = 'off';
      input.placeholder = 'Начните вводить подрядчика или водителя';
      input.id = `waste-executor-input-${nextId}`;
      input.setAttribute('role', 'combobox');
      input.setAttribute('aria-autocomplete', 'list');
      input.setAttribute('aria-controls', listId);
      input.setAttribute('aria-expanded', 'false');
      list.id = listId;
      list.className = 'waste-executor-options';
      list.setAttribute('role', 'listbox');
      list.hidden = true;
      label.htmlFor = input.id;
      select.before(wrap);
      wrap.append(input, list, select);
      select.classList.add('waste-executor-native');
      select.tabIndex = -1;
      select.setAttribute('aria-hidden', 'true');

      const options = Array.from(select.options);
      let visible = [];
      let active = -1;
      const selectedText = () => select.selectedOptions[0]?.value ? select.selectedOptions[0].textContent.trim() : '';
      const normalize = value => value.trim().toLocaleLowerCase('ru');
      const close = () => {
        list.hidden = true;
        input.setAttribute('aria-expanded', 'false');
        input.removeAttribute('aria-activedescendant');
        active = -1;
      };
      const setActive = index => {
        active = index;
        list.querySelectorAll('[role="option"]').forEach((item, i) => {
          item.classList.toggle('is-active', i === active);
        });
        if (active >= 0) input.setAttribute('aria-activedescendant', `${listId}-option-${active}`);
        else input.removeAttribute('aria-activedescendant');
      };
      const choose = option => {
        select.value = option.value;
        select.dispatchEvent(new Event('change', { bubbles: true }));
        input.value = selectedText();
        close();
        input.focus();
      };
      const render = query => {
        const needle = normalize(query);
        visible = options.filter(option => !needle || normalize(option.textContent).includes(needle));
        list.replaceChildren();
        visible.forEach((option, index) => {
          const item = document.createElement('button');
          item.type = 'button';
          item.id = `${listId}-option-${index}`;
          item.className = 'waste-executor-option';
          item.setAttribute('role', 'option');
          item.setAttribute('aria-selected', String(option.value === select.value));
          item.textContent = option.textContent.trim();
          item.addEventListener('pointerdown', event => event.preventDefault());
          item.addEventListener('click', () => choose(option));
          item.addEventListener('pointerenter', () => setActive(index));
          list.append(item);
        });
        if (!visible.length) {
          const empty = document.createElement('div');
          empty.className = 'waste-executor-empty';
          empty.textContent = 'Ничего не найдено';
          list.append(empty);
        }
        list.hidden = false;
        input.setAttribute('aria-expanded', 'true');
        const scroll = wrap.closest('.waste-modal-scroll');
        if (scroll) {
          const bounds = scroll.getBoundingClientRect();
          const control = input.getBoundingClientRect();
          const desired = Math.min(220, list.scrollHeight);
          list.classList.toggle('is-above', bounds.bottom - control.bottom < desired && control.top - bounds.top > bounds.bottom - control.bottom);
        }
        setActive(-1);
      };

      input.value = selectedText();
      input.addEventListener('focus', () => render(''));
      input.addEventListener('input', () => render(input.value));
      input.addEventListener('keydown', event => {
        if (event.key === 'Escape') {
          input.value = selectedText();
          close();
        } else if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
          event.preventDefault();
          if (list.hidden) render('');
          if (visible.length) setActive((active + (event.key === 'ArrowDown' ? 1 : -1) + visible.length) % visible.length);
        } else if (event.key === 'Enter' && !list.hidden) {
          event.preventDefault();
          if (active >= 0) choose(visible[active]);
        } else if (event.key === 'Tab') {
          input.value = selectedText();
          close();
        }
      });
      input.addEventListener('blur', () => {
        setTimeout(() => {
          if (!wrap.contains(document.activeElement)) {
            input.value = selectedText();
            close();
          }
        }, 0);
      });
      select.addEventListener('change', () => { input.value = selectedText(); });
    });
  };
})();
