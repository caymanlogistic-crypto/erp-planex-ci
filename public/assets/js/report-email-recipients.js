(() => {
  document.querySelectorAll('[data-recipient-settings]').forEach(root => {
    if (root.querySelector('.report-email-form') === null) return;
    const state = JSON.parse(root.dataset.state || '{}');
    const person = root.querySelector('[data-recipient-person]');
    const email = root.querySelector('[data-recipient-address]');
    const options = root.querySelector('.report-email-options');
    const list = root.querySelector('.report-email-list');
    const empty = root.querySelector('.report-email-empty');
    const count = root.querySelector('.report-email-count');
    const logist = root.querySelector('[data-recipient-logist]');
    const form = root.querySelector('[data-recipient-form]');
    const feedback = root.querySelector('.report-email-feedback');
    const saveState = root.querySelector('.report-email-save-state');
    let recipients = Array.isArray(state.recipients) ? state.recipients.map(item => ({
      account_id: item.account_id ? Number(item.account_id) : null,
      name: String(item.display_name || item.name || 'Внешний получатель'),
      email: String(item.email || '')
    })) : [];
    let selected = null;
    let searchTimer;
    let searchSerial = 0;
    logist.checked = Boolean(state.send_responsible_logist);

    function message(value, error = false) {
      feedback.hidden = !value;
      feedback.textContent = value;
      feedback.classList.toggle('is-error', error);
    }
    function dirty() { saveState.textContent = 'Есть несохранённые изменения'; message(''); }
    function closeOptions() {
      options.hidden = true;
      options.replaceChildren();
      person.setAttribute('aria-expanded', 'false');
    }
    function render() {
      list.replaceChildren();
      recipients.forEach((item, index) => {
        const row = document.createElement('li'); row.className = 'report-email-recipient';
        const icon = document.createElement('span'); icon.className = 'report-email-recipient-icon'; icon.textContent = '@';
        const info = document.createElement('span');
        const name = document.createElement('b'); name.textContent = item.name;
        const address = document.createElement('small'); address.textContent = item.email;
        info.append(name, address);
        const remove = document.createElement('button'); remove.type = 'button'; remove.className = 'btn btn-ghost report-email-remove';
        remove.textContent = 'Удалить'; remove.setAttribute('aria-label', `Удалить ${item.email}`);
        remove.addEventListener('click', () => { recipients.splice(index, 1); render(); dirty(); });
        row.append(icon, info, remove); list.append(row);
      });
      count.textContent = String(recipients.length);
      empty.hidden = recipients.length !== 0;
    }
    function showOptions(people) {
      options.replaceChildren();
      for (const item of people) {
        const button = document.createElement('button'); button.type = 'button'; button.setAttribute('role', 'option');
        const name = document.createElement('b'); name.textContent = item.name;
        const address = document.createElement('small'); address.textContent = item.email;
        button.append(name, address);
        button.addEventListener('click', () => {
          selected = item; person.value = item.name; email.value = item.email; closeOptions(); email.focus();
        });
        options.append(button);
      }
      options.hidden = people.length === 0;
      person.setAttribute('aria-expanded', people.length ? 'true' : 'false');
    }
    person.addEventListener('input', () => {
      selected = null;
      clearTimeout(searchTimer);
      const value = person.value.trim();
      if (value.includes('@')) { email.value = value; closeOptions(); return; }
      if (value.length < 2) { closeOptions(); return; }
      const serial = ++searchSerial;
      searchTimer = setTimeout(async () => {
        try {
          const response = await fetch(`${root.dataset.suggestUrl}?q=${encodeURIComponent(value)}`, {credentials: 'same-origin'});
          const data = await response.json();
          if (serial === searchSerial && person.value.trim() === value) showOptions(data.success ? data.people : []);
        } catch (_) { if (serial === searchSerial) closeOptions(); }
      }, 220);
    });
    person.addEventListener('keydown', event => {
      if (event.key === 'Escape') { closeOptions(); return; }
      if (event.key === 'ArrowDown' && !options.hidden) { event.preventDefault(); options.querySelector('button')?.focus(); }
    });
    options.addEventListener('keydown', event => {
      const buttons = [...options.querySelectorAll('button')];
      const index = buttons.indexOf(document.activeElement);
      if (event.key === 'Escape') { closeOptions(); person.focus(); }
      if (event.key === 'ArrowDown') { event.preventDefault(); buttons[Math.min(index + 1, buttons.length - 1)]?.focus(); }
      if (event.key === 'ArrowUp') { event.preventDefault(); if (index <= 0) person.focus(); else buttons[index - 1].focus(); }
    });
    document.addEventListener('click', event => { if (!root.contains(event.target) || !event.target.closest('.report-email-person')) closeOptions(); });
    root.querySelector('.report-email-add').addEventListener('click', () => {
      const address = email.value.trim().toLowerCase();
      if (!email.checkValidity() || !address) { message('Укажите корректный email получателя.', true); email.focus(); return; }
      if (recipients.length >= 50) { message('Можно указать не более 50 получателей.', true); return; }
      if (recipients.some(item => item.email.toLowerCase() === address)) { message('Этот email уже добавлен.', true); return; }
      recipients.push({account_id: selected && selected.email.toLowerCase() === address ? Number(selected.account_id) : null,
        name: selected && selected.email.toLowerCase() === address ? selected.name : 'Внешний получатель', email: address});
      selected = null; person.value = ''; email.value = ''; closeOptions(); render(); dirty(); person.focus();
    });
    logist.addEventListener('change', dirty);
    form.addEventListener('submit', async event => {
      event.preventDefault();
      form.elements.recipients.value = JSON.stringify(recipients);
      form.elements.send_responsible_logist.value = logist.checked ? '1' : '0';
      const button = form.querySelector('[type=submit]'); button.disabled = true;
      try {
        const response = await fetch(form.action, {method: 'POST', body: new FormData(form), credentials: 'same-origin'});
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.message || 'Не удалось сохранить получателей.');
        saveState.textContent = 'Сохранено'; message(result.message || 'Получатели сохранены.');
      } catch (error) { message(error.message || 'Ошибка сохранения.', true); }
      finally { button.disabled = false; }
    });
    render();
  });
})();
