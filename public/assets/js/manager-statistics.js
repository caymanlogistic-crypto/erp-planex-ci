(() => {
  const root = document.querySelector('[data-manager-statistics]');
  if (!root) return;
  const form = root.querySelector('[data-stat-filters]');
  if (form) {
    const periodFields = [...form.querySelectorAll('[data-mode-fields]')];
    form.querySelectorAll('input[name="mode"]').forEach((input) => {
      input.addEventListener('change', () => {
        form.querySelectorAll('.manager-stats__segments label').forEach((label) => {
          label.classList.toggle('is-active', label.contains(input));
        });
        periodFields.forEach((field) => {
          const active = field.dataset.modeFields === input.value;
          field.hidden = !active;
          field.querySelectorAll('input,select').forEach((control) => { control.disabled = !active; });
        });
      });
    });
  }
  const picker = root.querySelector('[data-manager-picker]');
  if (!picker) return;
  const boxes = [...picker.querySelectorAll('input[type="checkbox"]')];
  const summary = picker.querySelector('[data-manager-summary]');
  const refresh = () => {
    const count = boxes.filter((box) => box.checked).length;
    if (summary) summary.textContent = count === 0 ? 'Все логисты' : `Выбрано: ${count}`;
  };
  picker.querySelector('[data-manager-search]')?.addEventListener('input', (event) => {
    const query = event.target.value.trim().toLocaleLowerCase('ru-RU');
    picker.querySelectorAll('[data-manager-row]').forEach((row) => {
      row.hidden = query !== '' && !row.textContent.toLocaleLowerCase('ru-RU').includes(query);
    });
  });
  picker.querySelector('[data-manager-all]')?.addEventListener('click', () => { boxes.forEach((box) => { box.checked = true; }); refresh(); });
  picker.querySelector('[data-manager-clear]')?.addEventListener('click', () => { boxes.forEach((box) => { box.checked = false; }); refresh(); });
  boxes.forEach((box) => box.addEventListener('change', refresh));
  refresh();
})();
