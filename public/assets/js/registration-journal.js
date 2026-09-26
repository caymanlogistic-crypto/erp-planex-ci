(() => {
  const form = document.querySelector('[data-journal-filter]');
  if (!form) return;
  let timer;
  form.addEventListener('input', event => {
    if (event.target.name !== 'q') return;
    clearTimeout(timer);
    timer = setTimeout(() => form.requestSubmit(), 300);
  });
  form.addEventListener('change', event => {
    if (event.target.name === 'q') return;
    clearTimeout(timer);
    form.requestSubmit();
  });
})();
