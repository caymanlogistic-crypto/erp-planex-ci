/* Keep the sticky table header immediately below its responsive toolbar. */
(() => {
  document.querySelectorAll('.registry-ui-sticky-table > .registry-ui-toolbar').forEach(toolbar => {
    const card = toolbar.parentElement;
    const sync = () => card.style.setProperty('--registry-toolbar-height', `${toolbar.getBoundingClientRect().height}px`);
    sync();
    if (window.ResizeObserver) new ResizeObserver(sync).observe(toolbar);
    else window.addEventListener('resize', sync);
  });
})();
