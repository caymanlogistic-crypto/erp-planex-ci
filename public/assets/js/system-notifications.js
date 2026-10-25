(() => {
  'use strict';
  if (window.PlanexNotifications) return;

  const labels = {
    success: {title: 'Готово', icon: '✓'},
    warning: {title: 'Внимание', icon: '!'},
    danger: {title: 'Ошибка', icon: '×'}
  };
  const pending = [];
  let active = false;
  let stack = null;

  function container() {
    if (stack?.isConnected) return stack;
    stack = document.createElement('div');
    stack.className = 'toast-stack toast-stack--system';
    stack.setAttribute('aria-label', 'Всплывающие уведомления');
    document.body.append(stack);
    return stack;
  }

  function next() {
    const entry = pending.shift();
    if (!entry) { active = false; return; }
    active = true;
    const toast = document.createElement('div');
    toast.className = `toast toast--system is-${entry.tone}`;
    toast.setAttribute('role', entry.tone === 'danger' ? 'alert' : 'status');

    const icon = document.createElement('span');
    icon.className = 'toast-icon';
    icon.setAttribute('aria-hidden', 'true');
    icon.textContent = labels[entry.tone].icon;

    const content = document.createElement('div');
    content.className = 'toast-content';
    const title = document.createElement('div');
    title.className = 'toast-title';
    title.textContent = entry.title;
    const description = document.createElement('div');
    description.className = 'toast-desc';
    description.textContent = entry.message;
    content.append(title, description);

    const close = document.createElement('button');
    close.type = 'button';
    close.className = 'toast-close';
    close.setAttribute('aria-label', 'Закрыть уведомление');
    close.textContent = '×';
    toast.append(icon, content, close);
    container().append(toast);

    let closed = false;
    const timer = window.setTimeout(() => dismiss(false), 5000);
    function dismiss(manual) {
      if (closed) return;
      closed = true;
      window.clearTimeout(timer);
      if (manual || window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        toast.remove();
        next();
        return;
      }
      toast.classList.add('is-removing');
      window.setTimeout(() => { toast.remove(); next(); }, 180);
    }
    close.addEventListener('click', () => dismiss(true));
  }

  window.PlanexNotifications = Object.freeze({
    show({tone = 'success', title, message} = {}) {
      if (!Object.hasOwn(labels, tone)) throw new TypeError('Unknown notification tone');
      const body = String(message ?? '').trim();
      if (!body) return;
      pending.push({tone, title: String(title ?? labels[tone].title), message: body});
      if (!active) next();
    }
  });
})();
