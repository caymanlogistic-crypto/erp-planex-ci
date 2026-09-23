(() => {
  const nodes = [...document.querySelectorAll('[data-manager-statistics] .manager-stats__chart-node')];
  const clearExcept = (active = null) => {
    nodes.forEach((node) => node.classList.toggle('is-tooltip-active', node === active));
  };

  nodes.forEach((node) => {
    const raise = () => {
      const parent = node.parentNode;
      if (!parent || parent.lastElementChild === node) return;
      parent.appendChild(node);
    };
    const activate = () => {
      raise();
      clearExcept(node);
    };

    node.addEventListener('pointerenter', activate);
    node.addEventListener('pointerleave', () => {
      requestAnimationFrame(() => {
        if (!node.matches(':hover') && document.activeElement !== node) clearExcept();
      });
    });
    node.addEventListener('focusin', activate);
  });

  document.addEventListener('focusin', (event) => {
    if (!event.target.closest?.('.manager-stats__chart-node')) clearExcept();
  });
})();
