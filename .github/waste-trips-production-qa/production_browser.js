const { chromium } = require('playwright');
const fs = require('fs');

const BASE = 'https://plan-ex.ru/erpv2';
const cookieName = process.env.QA_COOKIE_NAME;
const sid = process.env.QA_OWNER_SESSION_ID;
if (!cookieName || !sid) throw new Error('QA session env missing');

const evidence = { source_sha: process.env.SOURCE_SHA || '', registry: [], legacy: {}, runtime: {} };
const failures = [];
const consoleErrors = [];
const pageErrors = [];
const serverErrors = [];

function ok(value, message) {
  if (!value) failures.push(message);
}

(async () => {
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({ ignoreHTTPSErrors: false });
  await context.addCookies([{ name: cookieName, value: sid, domain: 'plan-ex.ru', path: '/', secure: true, httpOnly: true, sameSite: 'Lax' }]);
  const page = await context.newPage();
  page.on('console', msg => { if (msg.type() === 'error') consoleErrors.push(msg.text()); });
  page.on('pageerror', err => pageErrors.push(String(err && err.stack || err)));
  page.on('response', response => { if (response.status() >= 500) serverErrors.push({ status: response.status(), url: response.url() }); });

  for (const viewport of [{ width: 1366, height: 900 }, { width: 1920, height: 1080 }]) {
    await page.setViewportSize(viewport);
    const response = await page.goto(BASE + '/company/trips/departures', { waitUntil: 'domcontentloaded', timeout: 30000 });
    ok(response && response.status() === 200, `registry HTTP must be 200 at ${viewport.width}x${viewport.height}`);
    ok(!page.url().includes('/login'), `registry redirected to login at ${viewport.width}x${viewport.height}`);
    await page.waitForTimeout(600);
    const result = await page.evaluate(() => {
      const html = document.documentElement;
      const body = document.body;
      const measureCell = cell => {
        const ids = Array.from(cell.querySelectorAll('.waste-request-id'));
        const tops = [...new Set(ids.map(el => Math.round(el.getBoundingClientRect().top)))];
        const style = getComputedStyle(cell);
        const list = cell.querySelector('.waste-request-list');
        const item = ids[0] || null;
        return {
          count: ids.length,
          visual_rows: tops.length,
          cell_white_space: style.whiteSpace,
          cell_text_overflow: style.textOverflow,
          cell_overflow_wrap: style.overflowWrap,
          list_flex_wrap: list ? getComputedStyle(list).flexWrap : null,
          item_white_space: item ? getComputedStyle(item).whiteSpace : null,
          item_overflow_wrap: item ? getComputedStyle(item).overflowWrap : null,
          cell_scroll_width: cell.scrollWidth,
          cell_client_width: cell.clientWidth,
          cell_scroll_height: cell.scrollHeight,
          cell_client_height: cell.clientHeight,
        };
      };

      const requestCells = Array.from(document.querySelectorAll('.waste-registry tbody td:nth-child(7)'));
      let largest = null;
      for (const cell of requestCells) {
        const candidate = measureCell(cell);
        if (!largest || candidate.count > largest.count) largest = candidate;
      }

      const beforeSyntheticHtmlScroll = html.scrollWidth;
      const beforeSyntheticBodyScroll = body.scrollWidth;
      const host = document.createElement('div');
      host.setAttribute('data-waste-qa-synthetic', '1');
      host.style.cssText = 'position:fixed;left:0;top:0;width:min(1100px,calc(100vw - 40px));visibility:hidden;pointer-events:none;z-index:-1;';
      const ids = Array.from({ length: 42 }, (_, i) => String(900000 + i));
      host.innerHTML = `<div class="table-card table-card--standard" data-erp-grid><div class="table-scroll waste-table-scroll"><table class="table waste-registry"><thead><tr><th>ID</th><th>ОПИСАНИЕ РЕЙСА</th><th>НАЧАЛО РЕЙСА</th><th>ОКОНЧАНИЕ РЕЙСА</th><th>СТАТУС</th><th>Исполнитель рейса</th><th>ЗАЯВКИ</th><th>ВЕС, КГ</th><th>МЕНЕДЖЕР</th></tr></thead><tbody><tr><td>#999</td><td>QA synthetic</td><td>—</td><td>—</td><td>QA</td><td>QA</td><td><div class="waste-request-list">${ids.map((id, i) => `<span class="waste-request-id">${id}${i < ids.length - 1 ? ',' : ''}</span>`).join('')}</div></td><td>0</td><td>QA</td></tr></tbody></table></div></div>`;
      body.appendChild(host);
      const syntheticCell = host.querySelector('.waste-registry tbody td:nth-child(7)');
      const syntheticTable = host.querySelector('.waste-registry');
      const synthetic = measureCell(syntheticCell);
      synthetic.table_width = syntheticTable.getBoundingClientRect().width;
      synthetic.host_width = host.getBoundingClientRect().width;
      synthetic.document_overflow_added = html.scrollWidth > beforeSyntheticHtmlScroll || body.scrollWidth > beforeSyntheticBodyScroll;
      host.remove();

      return {
        html_scroll_width: html.scrollWidth,
        html_client_width: html.clientWidth,
        body_scroll_width: body.scrollWidth,
        body_client_width: body.clientWidth,
        overflow: html.scrollWidth > html.clientWidth || body.scrollWidth > body.clientWidth,
        registry_width: document.querySelector('.waste-registry')?.getBoundingClientRect().width || 0,
        live_registry_empty: requestCells.length === 0,
        largest_request_cell: largest,
        request_cell_count: requestCells.length,
        synthetic_request_cell: synthetic,
      };
    });
    result.viewport = viewport;
    evidence.registry.push(result);
    ok(!result.overflow, `document horizontal overflow at ${viewport.width}x${viewport.height}`);

    const c = result.largest_request_cell || result.synthetic_request_cell;
    ok(!!c, `no request-wrap probe at ${viewport.width}x${viewport.height}`);
    if (c) {
      ok(c.cell_white_space !== 'nowrap', `requests cell uses nowrap at ${viewport.width}`);
      ok(c.cell_text_overflow !== 'ellipsis', `requests cell uses ellipsis at ${viewport.width}`);
      ok(c.cell_overflow_wrap === 'anywhere' || c.cell_overflow_wrap === 'break-word', `requests cell cannot wrap at ${viewport.width}`);
      ok(c.list_flex_wrap === 'wrap', `requests list does not flex-wrap at ${viewport.width}`);
      ok(c.item_white_space !== 'nowrap', `request ID uses nowrap at ${viewport.width}`);
    }
    const s = result.synthetic_request_cell;
    ok(s.count === 42, `synthetic request list incomplete at ${viewport.width}`);
    ok(s.visual_rows > 1, `long request list did not grow vertically at ${viewport.width}`);
    ok(s.table_width <= s.host_width + 1, `long request list expanded table beyond container at ${viewport.width}`);
    ok(!s.document_overflow_added, `long request list introduced document overflow at ${viewport.width}`);
    await page.screenshot({ path: `artifacts/waste-trips-registry-${viewport.width}x${viewport.height}.png`, fullPage: true });
  }

  await page.setViewportSize({ width: 1366, height: 900 });
  const legacyResponse = await page.goto(BASE + '/company/trips/departures/legacy', { waitUntil: 'domcontentloaded', timeout: 30000 });
  ok(legacyResponse && legacyResponse.status() === 200, 'legacy catalog HTTP must be 200');
  ok(!page.url().includes('/login'), 'legacy catalog redirected to login');
  await page.waitForTimeout(600);

  const catalogBefore = await page.evaluate(() => {
    const rows = Array.from(document.querySelectorAll('tr.waste-legacy-row'));
    const createable = rows.filter(r => r.classList.contains('is-createable'));
    const transferred = rows.filter(r => Array.from(r.querySelectorAll('.badge')).some(b => b.textContent.includes('Уже перенесён')));
    const missing = createable.filter(r => r.querySelector('.waste-legacy-missing'));
    const withFound = createable.filter(r => r.querySelector('.waste-legacy-requests span'));
    const preferred = missing.find(r => r.querySelector('.waste-legacy-requests span')) || missing[0] || withFound[0] || createable[0] || null;
    return {
      row_count: rows.length,
      createable_count: createable.length,
      transferred_count: transferred.length,
      missing_warning_row_count: missing.length,
      materialization_modal_present: !!document.querySelector('#waste-trip-materialize-modal'),
      materialization_button_count: document.querySelectorAll('[data-materialize-legacy]').length,
      create_modal_count: document.querySelectorAll('#waste-trip-create-modal').length,
      preferred_id: preferred ? Number(preferred.dataset.createLegacy || preferred.querySelector('[data-create-legacy]')?.dataset.createLegacy || 0) : 0,
      preferred_has_missing: !!preferred?.querySelector('.waste-legacy-missing'),
      preferred_missing_text: preferred?.querySelector('.waste-legacy-missing')?.textContent.trim() || '',
      preferred_found_ids: preferred ? Array.from(preferred.querySelectorAll('.waste-legacy-requests span')).map(x => x.textContent.trim()).filter(Boolean) : [],
      preferred_comment: preferred?.querySelector('td:nth-child(2)')?.textContent.trim() || '',
      open_action_count: transferred.filter(r => Array.from(r.querySelectorAll('a')).some(a => a.textContent.includes('Открыть рейс'))).length,
      warning_template_present: Array.from(document.scripts).some(s => (s.textContent || '').includes('из старой базы не найдена в ERP')),
    };
  });
  evidence.legacy.before = catalogBefore;
  ok(catalogBefore.row_count > 0, 'legacy catalog is empty');
  ok(catalogBefore.createable_count > 0, 'legacy catalog has no createable row for read-only modal QA');
  ok(!catalogBefore.materialization_modal_present, 'obsolete materialization modal is present');
  ok(catalogBefore.materialization_button_count === 0, 'obsolete materialization control is present');
  ok(catalogBefore.create_modal_count === 1, 'legacy catalog must contain exactly one create modal');
  ok(catalogBefore.warning_template_present, 'missing-FEO warning UI template is absent from production page');
  if (catalogBefore.transferred_count > 0) ok(catalogBefore.open_action_count === catalogBefore.transferred_count, 'transferred rows do not all expose Open Trip action');

  if (catalogBefore.preferred_id > 0) {
    const selector = `button[data-create-legacy="${catalogBefore.preferred_id}"]`;
    await page.locator(selector).first().click();
    await page.waitForTimeout(250);
    const modal = await page.evaluate(() => {
      const isVisible = el => !!el && getComputedStyle(el).display !== 'none' && getComputedStyle(el).visibility !== 'hidden' && el.getBoundingClientRect().width > 0 && el.getBoundingClientRect().height > 0;
      const overlays = Array.from(document.querySelectorAll('.modal-overlay')).filter(isVisible);
      const form = document.querySelector('#waste-legacy-create-form');
      const field = name => form?.elements.namedItem(name) || null;
      const requestHint = document.querySelector('[data-legacy-hint="request_ids"]')?.textContent.trim() || '';
      const values = {};
      for (const name of ['request_ids','comment','planned_start_mode','planned_start_date','planned_start_date_from','planned_start_date_to','planned_finish_date','actual_start_date','actual_end_date','client_id','responsible_user_id','status','route_executor_id','destination_type','utilizer_id']) {
        const el = field(name); values[name] = el ? { value: el.value, disabled: !!el.disabled, readOnly: !!el.readOnly } : null;
      }
      return {
        visible_overlay_count: overlays.length,
        visible_overlay_ids: overlays.map(x => x.id),
        title: document.querySelector('#waste-trip-create-modal .modal-title')?.textContent.trim() || '',
        identity: document.querySelector('[data-legacy-identity]')?.textContent.trim() || '',
        request_hint: requestHint,
        values,
        submit_text: form?.querySelector('[data-waste-create-submit]')?.textContent.trim() || '',
        cancel_text: Array.from(form?.querySelectorAll('button') || []).find(b => b.textContent.trim() === 'Отмена')?.textContent.trim() || '',
      };
    });
    evidence.legacy.modal = modal;
    ok(modal.visible_overlay_count === 1, `expected exactly one visible modal overlay, got ${modal.visible_overlay_count}`);
    ok(modal.visible_overlay_ids[0] === 'waste-trip-create-modal', 'wrong modal opened from legacy catalog');
    ok(modal.title === 'Создание рейса', `unexpected create modal title: ${modal.title}`);
    ok(modal.identity.includes(`#${catalogBefore.preferred_id}`), 'legacy identity is not shown in create form');
    ok(modal.values.planned_finish_date && modal.values.planned_finish_date.value === '', 'planned finish must be blank on legacy prefill');
    ok(modal.submit_text === 'Создать рейс' && modal.cancel_text === 'Отмена', 'create/cancel actions are not canonical');
    for (const name of ['request_ids','comment','planned_start_date','planned_start_date_from','planned_start_date_to','planned_finish_date','actual_start_date','actual_end_date','client_id','responsible_user_id','status','route_executor_id','destination_type','utilizer_id']) {
      const f = modal.values[name];
      ok(f && !f.disabled && !f.readOnly, `${name} is not editable in legacy prefill form`);
    }
    if (catalogBefore.preferred_found_ids.length > 0) {
      for (const id of catalogBefore.preferred_found_ids) ok(modal.values.request_ids.value.split(/[\s,;]+/).includes(id), `found FEO ${id} was not prefilled`);
    }
    if (catalogBefore.preferred_comment && catalogBefore.preferred_comment !== '—') ok(modal.values.comment.value === catalogBefore.preferred_comment, 'legacy comment was not prefilled');
    if (catalogBefore.preferred_has_missing) ok(modal.request_hint.includes('не найдена в ERP'), 'missing FEO warning is not visible in opened modal');
    await page.screenshot({ path: 'artifacts/waste-trips-legacy-create-1366x900.png', fullPage: true });
  }

  evidence.runtime.console_errors = consoleErrors;
  evidence.runtime.page_errors = pageErrors;
  evidence.runtime.http_5xx = serverErrors;
  ok(consoleErrors.length === 0, `console errors: ${consoleErrors.join(' | ')}`);
  ok(pageErrors.length === 0, `page errors: ${pageErrors.join(' | ')}`);
  ok(serverErrors.length === 0, `HTTP 5xx responses: ${JSON.stringify(serverErrors)}`);

  fs.mkdirSync('artifacts', { recursive: true });
  fs.writeFileSync('artifacts/waste-trips-production-qa.json', JSON.stringify(evidence, null, 2));
  await browser.close();
  if (failures.length) {
    console.error('WASTE_TRIPS_PRODUCTION_BROWSER_QA=FAIL');
    for (const f of failures) console.error('FAIL:', f);
    process.exit(1);
  }
  console.log('WASTE_TRIPS_PRODUCTION_BROWSER_QA=PASS');
  console.log(JSON.stringify({
    registry: evidence.registry.map(x => ({ viewport: x.viewport, overflow: x.overflow, live_registry_empty: x.live_registry_empty, synthetic_request_cell: x.synthetic_request_cell })),
    legacy: evidence.legacy,
    runtime: evidence.runtime,
  }, null, 2));
})().catch(err => {
  console.error(err && err.stack || err);
  process.exit(1);
});
