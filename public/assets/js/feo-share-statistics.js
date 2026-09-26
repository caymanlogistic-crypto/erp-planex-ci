(() => {
  'use strict';
  const DATA = JSON.parse(document.getElementById('feo-share-data').textContent);
  const root = document.querySelector('#feo-share-root');
  if (!root) return;
  const nf = new Intl.NumberFormat('ru-RU');
  const dec = new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 1 });
  const pct = new Intl.NumberFormat('ru-RU', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
  const share = (a, b) => b > 0 ? a / b * 100 : null;
  const percentage = v => v == null ? '—' : pct.format(v) + '%';
  const mass = kg => dec.format(kg / 1000) + ' т';
  const sign = v => (v > 0 ? '+' : '') + nf.format(v);
  const shortDate = date => date.slice(8, 10) + '.' + date.slice(5, 7);
  const fullDate = date => new Intl.DateTimeFormat('ru-RU', { day:'numeric', month:'long', year:'numeric', timeZone:'UTC' }).format(new Date(date + 'T12:00:00Z'));
  const monthLabel = date => new Intl.DateTimeFormat('ru-RU', { month:'long', year:'numeric', timeZone:'UTC' }).format(new Date(date + 'T12:00:00Z'));
  const esc = value => String(value).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const weekKey = date => {
    const d = new Date(date + 'T12:00:00Z');
    const weekday = (d.getUTCDay() + 6) % 7;
    d.setUTCDate(d.getUTCDate() - weekday + 3);
    const year = d.getUTCFullYear();
    const jan4 = new Date(Date.UTC(year, 0, 4));
    const jan4weekday = (jan4.getUTCDay() + 6) % 7;
    jan4.setUTCDate(jan4.getUTCDate() - jan4weekday + 3);
    return year + '-W' + String(1 + Math.round((d - jan4) / 604800000)).padStart(2, '0');
  };
  const weekLabel = date => 'Нед. ' + weekKey(date).slice(-2);
  const modeLabels = { cuts: 'Срезы', week: 'Недели', month: 'Месяцы' };
  const modeText = {
    cuts: 'Каждая точка — отдельная полная выгрузка ФЭО.',
    week: 'Последний доступный срез каждой недели. Дней без выгрузки на графике нет.',
    month: 'Последний доступный срез каждого месяца.'
  };
  const districtNames = {
    'ЦФО': 'Центральный', 'СЗФО': 'Северо-Западный', 'ЮФО': 'Южный',
    'СКФО': 'Северо-Кавказский', 'ПФО': 'Приволжский', 'УФО': 'Уральский',
    'СФО': 'Сибирский', 'ДФО': 'Дальневосточный'
  };
  const state = { mode: 'week', metric: 'count', district: null, districtSort: 'volume' };
  const latest = DATA.snapshots.at(-1);
  const first = DATA.snapshots[0];
  const weekRows = () => {
    const m = new Map();
    for (const s of DATA.snapshots) m.set(weekKey(s.date), s);
    return [...m.values()];
  };
  const monthRows = () => {
    const m = new Map();
    for (const s of DATA.snapshots) m.set(s.date.slice(0, 7), s);
    return [...m.values()];
  };
  const visibleRows = () => state.mode === 'week' ? weekRows() : state.mode === 'month' ? monthRows() : DATA.snapshots;
  const metricFields = () => state.metric === 'count' ? ['own_count', 'feo_count'] : ['own_kg', 'feo_kg'];
  const metricValue = value => state.metric === 'count' ? nf.format(value) : mass(value);

  const svgChart = rows => {
    const wrap = root.querySelector('.fs-chart-wrap');
    const width = Math.max(330, Math.round(wrap ? wrap.clientWidth - 20 : root.clientWidth - 32));
    const height = 174;
    const left = 58, right = 58, top = 12, bottom = 30;
    const plotW = width - left - right, plotH = height - top - bottom;
    const max = Math.max(30, Math.ceil(Math.max(...rows.map(r => Math.max(share(r.own_count,r.feo_count)||0, share(r.own_kg,r.feo_kg)||0))) / 10) * 10), baseline = top + plotH;
    const format = value => Number(value).toFixed(1);
    const px = i => rows.length === 1 ? left + plotW / 2 : left + plotW * i / (rows.length - 1);
    const py = value => top + plotH - value / max * plotH;
    const points = (owner, total) => rows.map((r, i) => ({ x:px(i), y:py(share(r[owner], r[total])), value:share(r[owner], r[total]), date:r.date }));
    const countPoints = points('own_count', 'feo_count');
    const massPoints = points('own_kg', 'feo_kg');
    // Same clamped cubic interpolation and .82 smoothing as the ERP manager chart.
    const path = pts => {
      if (!pts.length) return '';
      let d = `M${format(pts[0].x)} ${format(pts[0].y)}`;
      for (let i = 0; i < pts.length - 1; i++) {
        const previous = pts[Math.max(0, i - 1)], current = pts[i], next = pts[i + 1], after = pts[Math.min(pts.length - 1, i + 2)];
        const clamp = y => Math.max(top, Math.min(baseline, y));
        const c1x = current.x + (next.x - previous.x) / 6 * .82;
        const c1y = clamp(current.y + (next.y - previous.y) / 6 * .82);
        const c2x = next.x - (after.x - current.x) / 6 * .82;
        const c2y = clamp(next.y - (after.y - current.y) / 6 * .82);
        d += ` C${format(c1x)} ${format(c1y)} ${format(c2x)} ${format(c2y)} ${format(next.x)} ${format(next.y)}`;
      }
      return d;
    };
    const area = pts => pts.length > 1 ? `${path(pts)} L${format(pts.at(-1).x)} ${format(baseline)} L${format(pts[0].x)} ${format(baseline)} Z` : '';
    const ticks = [max, max*.75, max*.5, max*.25, 0].map(value => {
      const y = py(value);
      return `<line x1="${left}" y1="${format(y)}" x2="${width-right}" y2="${format(y)}" class="grid-line"/><text x="${left-8}" y="${format(y+4)}" text-anchor="end" class="axis-label">${dec.format(value)}%</text>`;
    }).join('');
    const nodes = (pts, name, color, seriesIndex) => pts.map((point, i) => {
      const period = state.mode === 'week' ? weekLabel(point.date) : state.mode === 'month' ? monthLabel(point.date) : shortDate(point.date);
      return `<g class="manager-stats__chart-node fs-chart-node ${seriesIndex === 0 ? 'is-company' : 'is-manager'}" data-node-index="${i}" tabindex="0" role="img" aria-label="${name} · ${period} — ${percentage(point.value)}" transform="translate(${format(point.x)} ${format(point.y)})" style="--series-index:${seriesIndex};--node-index:${i};--node-color:${color}"><circle r="${seriesIndex === 0 ? 4 : 3.5}"/></g>`;
    }).join('');
    const labels = rows.map((r, i) => `<text x="${format(px(i))}" y="${height-7}" text-anchor="middle" class="axis-label">${state.mode==='week' ? weekLabel(r.date) : state.mode==='month' ? monthLabel(r.date) : shortDate(r.date)}</text>`).join('');
    return `<svg class="fs-chart manager-stats__line-chart" viewBox="0 0 ${width} ${height}" role="img" aria-label="Изменение доли компаний в общем объёме ФЭО"><defs><linearGradient id="fs-company-area" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#C07830" stop-opacity=".22"/><stop offset=".72" stop-color="#C07830" stop-opacity=".05"/><stop offset="1" stop-color="#C07830" stop-opacity="0"/></linearGradient><linearGradient id="fs-mass-area" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#4A7A9B" stop-opacity=".22"/><stop offset=".72" stop-color="#4A7A9B" stop-opacity=".05"/><stop offset="1" stop-color="#4A7A9B" stop-opacity="0"/></linearGradient></defs>${ticks}<rect class="manager-stats__plot-hit" x="${left}" y="${top}" width="${plotW}" height="${plotH}"/>${area(countPoints) ? `<path class="manager-stats__chart-area" d="${area(countPoints)}" fill="url(#fs-company-area)"/>` : ''}${area(massPoints) ? `<path class="manager-stats__chart-area" d="${area(massPoints)}" fill="url(#fs-mass-area)"/>` : ''}<line class="manager-stats__period-guide" x1="0" x2="0" y1="${top}" y2="${baseline}" aria-hidden="true"/><path class="manager-stats__chart-line is-manager" pathLength="1" d="${path(massPoints)}" fill="none" stroke="#4A7A9B" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="--series-index:1"/><path class="manager-stats__chart-line is-company" pathLength="1" d="${path(countPoints)}" fill="none" stroke="#C07830" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="--series-index:0"/>${nodes(massPoints,'По массе','#4A7A9B',1)}${nodes(countPoints,'По заявкам','#C07830',0)}${labels}</svg>`;
  };

  const snapshotRows = rows => rows.slice().reverse().map(r => {
    const prior = rows[rows.indexOf(r)-1];
    const delta = prior ? share(r.own_count, r.feo_count) - share(prior.own_count, prior.feo_count) : null;
    return `<tr data-period="${rows.indexOf(r)}" tabindex="0"><td><strong>${shortDate(r.date)}.${r.date.slice(0,4)}</strong><small>Импорт ${r.imported_at}</small></td><td class="num">${nf.format(r.feo_count)}<small>${mass(r.feo_kg)}</small></td><td class="num">${nf.format(r.own_count)}<small>${mass(r.own_kg)}</small></td><td class="num"><strong>${percentage(share(r.own_count,r.feo_count))}</strong><small>${percentage(share(r.own_kg,r.feo_kg))} по массе</small></td><td class="num ${delta>0?'positive':delta<0?'negative':''}">${delta==null?'—':(delta>0?'+':'')+pct.format(delta)+' п.п.'}</td></tr>`;
  }).join('');

  const intervalRows = DATA.snapshots.slice(1).map((r, i) => {
    if (r.added_count == null) return '';
    const p = DATA.snapshots[i];
    return `<tr><td><strong>${shortDate(p.date)} → ${shortDate(r.date)}</strong><small>Между двумя выгрузками</small></td><td class="num positive">+${nf.format(r.added_count)}<small>${mass(r.added_kg)}</small></td><td class="num negative">−${nf.format(r.gone_count)}<small>${mass(r.gone_kg)}</small></td><td class="num">${sign(r.feo_count-p.feo_count)}<small>${sign(r.feo_kg-p.feo_kg)} кг</small></td></tr>`;
  }).reverse().join('');

  const blockWeeks = () => {
    const m = new Map();
    for (const [date, add, addKg, out, outKg] of DATA.block_daily) {
      const key = weekKey(date);
      const row = m.get(key) || { key, add: 0, addKg: 0, out: 0, outKg: 0 };
      row.add += add; row.addKg += addKg; row.out += out; row.outKg += outKg;
      m.set(key, row);
    }
    return [...m.values()].reverse();
  };
  const blockRows = blockWeeks().map(r => `<tr><td><strong>Нед. ${r.key.slice(-2)}</strong><small>${r.key.slice(0,4)}</small></td><td class="num positive">+${nf.format(r.add)}<small>${mass(r.addKg)}</small></td><td class="num negative">−${nf.format(r.out)}<small>${mass(r.outKg)}</small></td><td class="num">${sign(r.add-r.out)}<small>${sign(r.addKg-r.outKg)} кг</small></td></tr>`).join('');

  const districtRows = () => {
    const [ownKey, feoKey] = metricFields();
    const rows = DATA.districts.slice().sort((a, b) => {
      if (state.districtSort === 'share') return (share(b[ownKey],b[feoKey])||0)-(share(a[ownKey],a[feoKey])||0);
      return b[ownKey] - a[ownKey];
    });
    return rows.map(d => {
      const nShare = share(d.own_count,d.feo_count), mShare = share(d.own_kg,d.feo_kg);
      const active = state.district===d.district;
      return `<tr class="${active?'is-selected':''}"><td><button class="fs-district-link" type="button" data-district="${d.district}" aria-expanded="${active}"><strong>${esc(d.district)}</strong><span>${esc(districtNames[d.district] || d.district)}</span></button></td><td class="num">${nf.format(d.feo_count)}<small>${mass(d.feo_kg)}</small></td><td class="num">${nf.format(d.own_count)}<small>${mass(d.own_kg)}</small></td><td class="num fs-share-cell"><strong>${percentage(nShare)}</strong><span class="fs-mini-track"><i style="width:${nShare||0}%"></i></span></td><td class="num fs-share-cell"><strong>${percentage(mShare)}</strong><span class="fs-mini-track fs-mini-track--mass"><i style="width:${mShare||0}%"></i></span></td></tr>`;
    }).join('');
  };
  const regionDetail = () => {
    if (!state.district) return `<div class="fs-region-empty">Выберите федеральный округ в таблице, чтобы увидеть составляющие его регионы.</div>`;
    const rows = DATA.regions.filter(r => r.district===state.district).sort((a,b)=>b.own_kg-a.own_kg || b.own_count-a.own_count);
    if (!rows.length) return `<div class="fs-region-empty">В доступных файлах нет заявок по округу ${state.district}.</div>`;
    return `<div class="fs-region-title"><div><h3>${esc(districtNames[state.district] || state.district)} федеральный округ</h3><p>ФЭО — ${shortDate(latest.date)}, компании — текущий состав. Значение выше 100% указывает на несинхронность срезов.</p></div><button type="button" data-close-district aria-label="Закрыть разрез по регионам">×</button></div><div class="fs-table-wrap"><table class="fs-table fs-region-table"><thead><tr><th>Регион</th><th class="num">ФЭО · заявки / масса</th><th class="num">Компании · заявки / масса</th><th class="num">Доля · шт / масса</th></tr></thead><tbody>${rows.map(r=>`<tr><td>${esc(r.region)}</td><td class="num">${nf.format(r.feo_count)}<small>${mass(r.feo_kg)}</small></td><td class="num">${nf.format(r.own_count)}<small>${mass(r.own_kg)}</small></td><td class="num">${percentage(share(r.own_count,r.feo_count))}<small>${percentage(share(r.own_kg,r.feo_kg))} по массе${share(r.own_count,r.feo_count)>100||share(r.own_kg,r.feo_kg)>100?' · разные даты':''}</small></td></tr>`).join('')}</tbody></table></div>`;
  };

  function render() {
    const rows = visibleRows();
    const firstShareN = share(first.own_count,first.feo_count);
    const lastShareN = share(latest.own_count,latest.feo_count);
    const lastShareM = share(latest.own_kg,latest.feo_kg);
    root.innerHTML = `<div class="fs-page">
      <div class="fs-title"><div><div class="fs-eyebrow">СТАТИСТИКА · ЗАЯВКИ ФЭО</div><h1>Доля компаний в объёме ФЭО</h1><p>Какую часть заявок и массы ФЭО составляют заявки компаний — и как меняется их доля.</p></div><div class="fs-title-asof"><span>Последняя полная выгрузка ФЭО</span><strong>${fullDate(latest.date)}</strong><small>Импорт ${esc(latest.imported_at)}</small></div></div>
      <div class="fs-caveat" role="note"><span class="fs-caveat-icon">i</span><div>Для динамики доли компаний использованы парные срезы на время импорта ФЭО. Последний: <b>${nf.format(latest.own_count)} из ${nf.format(latest.feo_count)} заявок</b>. Текущий состав: <b>${nf.format(DATA.current.own_count)} заявок · ${mass(DATA.current.own_kg)}</b>. География сопоставляет текущий состав компаний с последним файлом ФЭО и поэтому ориентировочна.</div></div>
      <section class="fs-kpis" aria-label="Ключевые показатели">
        <article class="fs-kpi fs-kpi--primary"><span>ДОЛЯ КОМПАНИЙ · ЗАЯВКИ</span><strong>${percentage(lastShareN)}</strong><p>${nf.format(latest.own_count)} из ${nf.format(latest.feo_count)} заявок</p><em>${sign(Math.round((lastShareN-firstShareN)*10)/10)} п.п. с ${shortDate(first.date)}</em></article>
        <article class="fs-kpi fs-kpi--teal"><span>ДОЛЯ КОМПАНИЙ · МАССА</span><strong>${percentage(lastShareM)}</strong><p>${mass(latest.own_kg)} из ${mass(latest.feo_kg)}</p><em>На время импорта ${esc(latest.imported_at)}</em></article>
        <article class="fs-kpi"><span>ТЕКУЩИЙ ОБЪЁМ КОМПАНИЙ</span><strong>${nf.format(DATA.current.own_count)}</strong><p>${mass(DATA.current.own_kg)}</p><em>Актуальный состав на ${esc(DATA.current.as_of)}</em></article>
        <article class="fs-kpi"><span>ВСЕ ЗАЯВКИ ФЭО</span><strong>${nf.format(latest.feo_count)}</strong><p>${mass(latest.feo_kg)}</p><em>Файл от ${shortDate(latest.date)}</em></article>
      </section>
      <section class="fs-card fs-history"><div class="fs-card-head"><div><span class="fs-section-kicker">01 · ДИНАМИКА</span><h2>Динамика доли компаний</h2><p>${modeText[state.mode]}</p></div><div class="fs-controls" aria-label="Период отчёта">${Object.entries(modeLabels).map(([key,label])=>`<button type="button" data-mode="${key}" aria-pressed="${state.mode===key}" class="${state.mode===key?'is-active':''}">${label}</button>`).join('')}</div></div><div class="fs-chart-wrap manager-stats__chart-body">${svgChart(rows)}<aside class="manager-stats__period-popup fs-period-popup" role="tooltip" aria-hidden="true"></aside><div class="fs-chart-legend"><span><i class="fs-legend-count"></i>По заявкам</span><span><i class="fs-legend-mass"></i>По массе</span></div></div><div class="fs-card-subhead"><h3>Точные значения по срезам</h3><span>Штуки и масса показаны вместе · изменение к предыдущей строке</span></div><div class="fs-table-wrap"><table class="fs-table fs-snapshot-table"><thead><tr><th>Файл ФЭО</th><th class="num">Все ФЭО</th><th class="num">Компании</th><th class="num">Доля компаний</th><th class="num">Изм. доли, шт.</th></tr></thead><tbody>${snapshotRows(rows)}</tbody></table></div></section>
      <section class="fs-flow-grid" aria-label="Движение заявок"><article class="fs-card"><div class="fs-card-head"><div><span class="fs-section-kicker">02 · ФЭО</span><h2>Появились и исчезли</h2><p>Сравнение ID между соседними полными файлами.</p></div></div><div class="fs-table-wrap"><table class="fs-table"><thead><tr><th>Интервал</th><th class="num">Появились</th><th class="num">Исчезли</th><th class="num">Изменение остатка</th></tr></thead><tbody>${intervalRows}</tbody></table></div></article><article class="fs-card"><div class="fs-card-head"><div><span class="fs-section-kicker">03 · КОМПАНИИ</span><h2>Изменение распределения</h2><p>Операции по применяемым блокам, сгруппированные по неделям.</p></div></div><div class="fs-table-wrap"><table class="fs-table"><thead><tr><th>Неделя</th><th class="num">Передано</th><th class="num">Снято</th><th class="num">Изменение</th></tr></thead><tbody>${blockRows}</tbody></table></div></article></section>
      <section class="fs-card fs-geography"><div class="fs-card-head"><div><span class="fs-section-kicker">04 · ГЕОГРАФИЯ</span><h2>Доля компаний по федеральным округам</h2><p>ФЭО — полный файл от ${shortDate(latest.date)}; компании — актуальный состав на ${esc(DATA.current.as_of)}. Выберите округ для списка регионов.</p></div><div class="fs-controls" aria-label="Сортировка федеральных округов"><button type="button" data-sort="volume" aria-pressed="${state.districtSort==='volume'}" class="${state.districtSort==='volume'?'is-active':''}">По объёму компаний</button><button type="button" data-sort="share" aria-pressed="${state.districtSort==='share'}" class="${state.districtSort==='share'?'is-active':''}">По доле</button></div></div><div class="fs-table-wrap"><table class="fs-table fs-district-table"><thead><tr><th>Федеральный округ</th><th class="num">Все ФЭО</th><th class="num">Компании</th><th class="num">Доля · штуки</th><th class="num">Доля · масса</th></tr></thead><tbody>${districtRows()}</tbody><tfoot><tr><th>Итого по файлу</th><th class="num">${nf.format(latest.feo_count)}<small>${mass(latest.feo_kg)}</small></th><th class="num">${nf.format(DATA.current.own_count)}<small>${mass(DATA.current.own_kg)}</small></th><th class="num">${percentage(share(DATA.current.own_count,latest.feo_count))}</th><th class="num">${percentage(share(DATA.current.own_kg,latest.feo_kg))}</th></tr></tfoot></table></div><div class="fs-region-detail" aria-live="polite">${regionDetail()}</div></section>
      <details class="fs-method"><summary>Источники и правила расчёта</summary><div><p><b>ФЭО:</b> полные файлы по датам в таблице; каждый ID учитывается один раз, масса — нетто в килограммах. Исчезновение означает отсутствие ID в следующем полном файле. Разность общей массы также учитывает изменение массы у сохранившихся заявок. ${esc(DATA.historical_note)}</p><p><b>Компании:</b> применяемые блоки «Размещения на карте». История до внедрения отчёта восстановлена по датам действующих блоков; статусы ранее отключённых блоков отдельно не сохранены.</p><p><b>Федеральные округа:</b> регион файла ФЭО и текущего состава компаний сопоставлен с округом. Даты географических источников могут различаться. Файл «FEO_недостающие_заявки_для_штатного_импорта.xlsx» исключён.</p></div></details>
      <div class="fs-footer">Данные обновляются при импорте полных файлов ФЭО и изменении распределения заявок</div>
    </div>`;
    const initialChart = root.querySelector('.fs-chart');
    if (initialChart) initialChart.outerHTML = svgChart(rows);
    bindChart(rows);
  }
  function bindChart(rows) {
    const chart = root.querySelector('.fs-chart');
    const wrap = root.querySelector('.fs-chart-wrap');
    const popup = wrap.querySelector('.fs-period-popup');
    const guide = chart.querySelector('.manager-stats__period-guide');
    const tableRows = [...root.querySelectorAll('.fs-snapshot-table tbody tr[data-period]')];
    const nodes = [...chart.querySelectorAll('.fs-chart-node')];
    const countNodes = [...chart.querySelectorAll('.fs-chart-node.is-company')];
    const positions = countNodes.map(node => Number(/^translate\((-?[\d.]+)/.exec(node.getAttribute('transform'))?.[1]));
    let hoveredRow = null, focusedRow = null, hoveredPlot = null, focusedPoint = null;
    const renderPopup = index => {
      const row = rows[index];
      popup.replaceChildren();
      const title = document.createElement('strong');
      title.className = 'manager-stats__period-popup-title';
      title.textContent = `${shortDate(row.date)}.${row.date.slice(0,4)} · импорт ${row.imported_at}`;
      popup.append(title);
      for (const [name, value, detail, color] of [
        ['По заявкам', percentage(share(row.own_count, row.feo_count)), `${nf.format(row.own_count)} из ${nf.format(row.feo_count)}`, '#C07830'],
        ['По массе', percentage(share(row.own_kg, row.feo_kg)), `${mass(row.own_kg)} из ${mass(row.feo_kg)}`, '#4A7A9B'],
      ]) {
        const item = document.createElement('div');
        item.className = 'manager-stats__period-popup-item';
        item.style.color = color;
        const label = document.createElement('strong');
        label.textContent = name;
        const result = document.createElement('span');
        result.textContent = `${value} · ${detail}`;
        item.append(label, result);
        popup.append(item);
      }
    };
    const sync = () => {
      const index = hoveredRow ?? hoveredPlot ?? focusedRow ?? focusedPoint;
      const active = Number.isInteger(index);
      tableRows.forEach(row => row.classList.toggle('is-period-active', Number(row.dataset.period) === index));
      nodes.forEach(node => node.classList.toggle('is-period-active', Number(node.dataset.nodeIndex) === index));
      guide.classList.toggle('is-visible', active);
      popup.classList.toggle('is-visible', active);
      popup.setAttribute('aria-hidden', String(!active));
      if (!active) return;
      const x = positions[index];
      guide.setAttribute('x1', x);
      guide.setAttribute('x2', x);
      renderPopup(index);
      const chartRect = chart.getBoundingClientRect();
      const wrapRect = wrap.getBoundingClientRect();
      const lineX = chartRect.left - wrapRect.left + x / chart.viewBox.baseVal.width * chartRect.width;
      const popupWidth = popup.getBoundingClientRect().width;
      let left = lineX - popupWidth - 12;
      if (left < 4) left = Math.min(lineX + 12, wrapRect.width - popupWidth - 4);
      popup.style.left = `${Math.max(4, left)}px`;
    };
    tableRows.forEach(row => {
      const index = Number(row.dataset.period);
      row.addEventListener('pointerenter', () => { hoveredRow = index; sync(); });
      row.addEventListener('pointerleave', () => { hoveredRow = null; sync(); });
      row.addEventListener('focus', () => { focusedRow = index; sync(); });
      row.addEventListener('blur', () => { focusedRow = null; sync(); });
    });
    chart.addEventListener('pointermove', event => {
      const bounds = chart.getBoundingClientRect();
      const x = (event.clientX - bounds.left) / bounds.width * chart.viewBox.baseVal.width;
      const y = (event.clientY - bounds.top) / bounds.height * chart.viewBox.baseVal.height;
      const nearest = x >= 58 && x <= chart.viewBox.baseVal.width - 58 && y >= 12 && y <= 144
        ? positions.reduce((best, pointX, index) => Math.abs(pointX - x) < Math.abs(positions[best] - x) ? index : best, 0)
        : null;
      if (hoveredPlot !== nearest) { hoveredPlot = nearest; sync(); }
    });
    chart.addEventListener('pointerleave', () => { hoveredPlot = null; sync(); });
    nodes.forEach(node => {
      const index = Number(node.dataset.nodeIndex);
      node.addEventListener('focus', () => { focusedPoint = index; sync(); });
      node.addEventListener('blur', () => { focusedPoint = null; sync(); });
    });
  }
  let chartResizeFrame = 0;
  window.addEventListener('resize', () => {
    cancelAnimationFrame(chartResizeFrame);
    chartResizeFrame = requestAnimationFrame(() => {
      const chart = root.querySelector('.fs-chart');
      if (chart) { chart.outerHTML = svgChart(visibleRows()); bindChart(visibleRows()); }
    });
  });
  root.addEventListener('click', event => {
    const mode = event.target.closest('[data-mode]');
    const sort = event.target.closest('[data-sort]');
    const district = event.target.closest('[data-district]');
    const close = event.target.closest('[data-close-district]');
    if (mode) { state.mode = mode.dataset.mode; render(); }
    if (sort) { state.districtSort = sort.dataset.sort; render(); }
    if (district) { state.district = district.dataset.district === state.district ? null : district.dataset.district; render(); root.querySelector('.fs-region-detail')?.scrollIntoView({ block: 'nearest' }); }
    if (close) { state.district = null; render(); }
  });
  render();
})();
