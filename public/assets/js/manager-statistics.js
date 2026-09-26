(() => {
  const report = document.querySelector('[data-manager-statistics]');
  const svg = report?.querySelector('.manager-stats__line-chart');
  const table = report?.querySelector('.manager-stats__period-table.is-all-metrics');
  if (!svg || !table) return;

  const grids = [...svg.querySelectorAll('.grid-line')];
  const plotLeft = Number(grids[0]?.getAttribute('x1') || 58);
  const plotRight = Number(grids[0]?.getAttribute('x2') || 862);
  const plotTop = Number(grids[0]?.getAttribute('y1') || 12);
  const plotBottom = Number(grids.at(-1)?.getAttribute('y1') || 144);
  const points = new Map([...svg.querySelectorAll('.manager-stats__chart-node.is-company')].map((node) => [
    node.dataset.periodLabel,
    Number(/^translate\((-?[\d.]+)/.exec(node.getAttribute('transform'))?.[1]),
  ]));
  const periodsByX = [...points].map(([label, x]) => ({label, x})).sort((a, b) => a.x - b.x);
  if (periodsByX.length === 0) return;

  const plot = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
  plot.setAttribute('class', 'manager-stats__plot-hit');
  plot.setAttribute('x', plotLeft);
  plot.setAttribute('y', plotTop);
  plot.setAttribute('width', plotRight - plotLeft);
  plot.setAttribute('height', plotBottom - plotTop);
  const area = [...svg.querySelectorAll('.manager-stats__chart-area')].at(-1);
  area?.before(plot);

  const guide = document.createElementNS('http://www.w3.org/2000/svg', 'line');
  guide.setAttribute('class', 'manager-stats__period-guide');
  guide.setAttribute('aria-hidden', 'true');
  guide.setAttribute('y1', plotTop);
  guide.setAttribute('y2', plotBottom);
  area?.after(guide);

  const chartBody = report.querySelector('.manager-stats__chart-body');
  const popup = document.createElement('aside');
  popup.id = 'manager-stats-period-popup';
  popup.className = 'manager-stats__period-popup';
  popup.setAttribute('role', 'tooltip');
  popup.setAttribute('aria-hidden', 'true');
  chartBody.append(popup);

  const rows = [...table.tBodies[0].rows].filter((row) => row.querySelector('.manager-stats__period-label'));
  const rowByLabel = new Map(rows.map((row) => [
    row.querySelector('.manager-stats__period-label strong').textContent.trim(), row,
  ]));
  const names = [...table.tHead.rows[0].cells].slice(1).map((cell) => cell.textContent.trim());
  popup.classList.toggle('is-dense', names.length > 3);
  const colors = new Map([...svg.querySelectorAll('.manager-stats__chart-line')].map((line) => [
    line.dataset.seriesName || 'Компания', line.getAttribute('stroke'),
  ]));
  const compactName = (name) => {
    if (name === 'Итого') return 'Компания';
    const words = name.split(/\s+/);
    return words.length > 1 ? `${words[0]} ${words.slice(1).map((word) => `${word[0]}.`).join('')}` : name;
  };
  const renderPopup = (label, row) => {
    popup.replaceChildren();
    const title = document.createElement('strong');
    title.className = 'manager-stats__period-popup-title';
    title.textContent = label;
    popup.append(title);
    const order = [names.length - 1, ...names.slice(0, -1).map((_, index) => index)];
    order.forEach((group) => {
      const fullName = group === names.length - 1 ? 'Компания' : names[group];
      const item = document.createElement('div');
      item.className = `manager-stats__period-popup-item${fullName === 'Компания' ? ' is-company' : ''}`;
      item.style.color = colors.get(fullName) || '';
      const name = document.createElement('strong');
      name.className = 'manager-stats__period-popup-name';
      name.textContent = compactName(fullName);
      name.setAttribute('aria-label', fullName);
      const values = document.createElement('div');
      values.className = 'manager-stats__period-popup-values';
      ['Рейсы', 'Заявки', 'Вес'].forEach((measure, index) => {
        const value = document.createElement('span');
        const number = document.createElement('b');
        number.textContent = row?.cells[1 + group * 3 + index]?.textContent.trim()
          ?? (index === 2 ? '0,00 т' : '0');
        const caption = document.createElement('small');
        caption.textContent = measure;
        value.append(number, caption);
        values.append(value);
      });
      item.append(name, values);
      popup.append(item);
    });
  };

  const periodNodes = [...svg.querySelectorAll('.manager-stats__chart-node')];
  let hoveredRow = null;
  let focusedRow = null;
  let hoveredPlot = null;
  let focusedPoint = null;
  const sync = () => {
    const active = hoveredRow || hoveredPlot || focusedRow || focusedPoint;
    const label = active?.label;
    const x = points.get(label);
    rows.forEach((row) => row.classList.toggle('is-period-active', row === active?.row));
    periodNodes.forEach((node) => node.classList.toggle('is-period-active', node.dataset.periodLabel === label));
    guide.classList.toggle('is-visible', Number.isFinite(x));
    if (!Number.isFinite(x)) {
      popup.classList.remove('is-visible');
      popup.setAttribute('aria-hidden', 'true');
      return;
    }
    guide.setAttribute('x1', x);
    guide.setAttribute('x2', x);
    renderPopup(label, active.row);
    const svgRect = svg.getBoundingClientRect();
    const bodyRect = chartBody.getBoundingClientRect();
    const lineX = svgRect.left - bodyRect.left + x / svg.viewBox.baseVal.width * svgRect.width;
    const popupWidth = popup.getBoundingClientRect().width;
    let left = lineX - popupWidth - 12;
    if (left < 4) left = Math.min(lineX + 12, bodyRect.width - popupWidth - 4);
    popup.style.left = `${Math.max(4, left)}px`;
    popup.classList.add('is-visible');
    popup.setAttribute('aria-hidden', 'false');
  };

  rows.forEach((row) => {
    const selection = {label: row.querySelector('.manager-stats__period-label strong').textContent.trim(), row};
    row.tabIndex = 0;
    row.addEventListener('pointerenter', () => { hoveredRow = selection; sync(); });
    row.addEventListener('pointerleave', () => { if (hoveredRow === selection) hoveredRow = null; sync(); });
    row.addEventListener('focus', () => { focusedRow = selection; sync(); });
    row.addEventListener('blur', () => { if (focusedRow === selection) focusedRow = null; sync(); });
  });
  svg.addEventListener('pointermove', (event) => {
    const bounds = svg.getBoundingClientRect();
    const x = (event.clientX - bounds.left) / bounds.width * svg.viewBox.baseVal.width;
    const y = (event.clientY - bounds.top) / bounds.height * svg.viewBox.baseVal.height;
    const nearest = x >= plotLeft - 1 && x <= plotRight + 1 && y >= plotTop - 1 && y <= plotBottom + 1
      ? periodsByX.reduce((best, period) => Math.abs(period.x - x) < Math.abs(best.x - x) ? period : best)
      : null;
    if (hoveredPlot?.label !== nearest?.label) {
      hoveredPlot = nearest ? {label: nearest.label, row: rowByLabel.get(nearest.label) || null} : null;
      sync();
    }
  });
  svg.addEventListener('pointerleave', () => { hoveredPlot = null; sync(); });
  periodNodes.forEach((node) => {
    const selection = {label: node.dataset.periodLabel, row: rowByLabel.get(node.dataset.periodLabel) || null};
    node.setAttribute('aria-describedby', popup.id);
    node.addEventListener('focus', () => { focusedPoint = selection; sync(); });
    node.addEventListener('blur', () => { if (focusedPoint === selection) focusedPoint = null; sync(); });
  });
})();
