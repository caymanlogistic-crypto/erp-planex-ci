/* Size the preview viewport; component appearance/behavior belongs to shared assets. */
(() => {
  const root = document.querySelector('[data-specimen-root]');
  if (root && window.parent !== window) {
    let previous = 0;
    const report = () => {
      const floatingBottom = [...root.querySelectorAll('[role="listbox"]')].reduce((bottom, element) => Math.max(bottom, element.getBoundingClientRect().bottom + 12), 0);
      const height = Math.ceil(Math.max(root.getBoundingClientRect().height, floatingBottom));
      if (height !== previous) {
        previous = height;
        window.parent.postMessage({type: 'planex-ui-preview-height', height}, '*');
      }
    };
    new ResizeObserver(report).observe(root);
    new MutationObserver(() => requestAnimationFrame(report)).observe(root,{subtree:true,attributes:true,childList:true});
    document.fonts.ready.then(report);
    window.addEventListener('load', report);
    return;
  }
  window.addEventListener('message', event => {
    if (event.data?.type !== 'planex-ui-preview-height') return;
    const frame = [...document.querySelectorAll('iframe[data-preview]')].find(item => item.contentWindow === event.source);
    const height = event.data.height;
    if (frame && !frame.hasAttribute('data-fixed-preview') && Number.isFinite(height) && height > 0 && height < 10000) frame.style.height = `${Math.max(100, height)}px`;
  });
})();
