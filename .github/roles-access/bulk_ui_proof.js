const fs = require('fs');
const { chromium } = require('playwright');

const base = 'https://plan-ex.ru/erpv2';
const out = process.env.RESULT_DIR || 'roles_access_bulk_ui_result';
const cookie = process.env.QA_COOKIE_NAME || '';
const sid = process.env.QA_LEGACY_SESSION_ID || '';
function must(value, message) { if (!value) throw new Error(message); }
async function noOverflow(page, label) {
  const result = await page.evaluate(() => {
    const root = document.querySelector('[data-roles-access-root]');
    return {
      doc: document.documentElement.scrollWidth - document.documentElement.clientWidth,
      body: document.body.scrollWidth - document.body.clientWidth,
      root: root ? root.scrollWidth - root.clientWidth : 0
    };
  });
  must(result.doc <= 1 && result.body <= 1 && result.root <= 1, `${label} horizontal overflow ${JSON.stringify(result)}`);
}
async function waitAssignments(page) {
  await page.locator('[data-access-tab="assignments"]').click();
  for (let i = 0; i < 160; i++) {
    const groups = await page.locator('[data-assignment-group]').count();
    if (groups === 4) return;
    await page.waitForTimeout(100);
  }
  throw new Error('assignment panels did not load');
}
async function waitBulkIdle(group) {
  for (let i = 0; i < 160; i++) {
    const busy = await group.getAttribute('data-assignment-bulk-busy');
    const disabled = await group.locator('[data-assignment-bulk]').evaluateAll(btns => btns.some(b => b.disabled));
    if (busy !== '1' && !disabled) return;
    await new Promise(resolve => setTimeout(resolve, 50));
  }
  throw new Error('bulk operation did not finish');
}

(async () => {
  fs.mkdirSync(out, { recursive: true });
  const browser = await chromium.launch({ headless: true });
  let productionWrites = 0;
  let simulatedWrites = 0;
  let mode = 'success';
  let partialRequests = [];
  let failedKey = '';
  try {
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'ru-RU' });
    await ctx.route('**/*', async route => {
      const req = route.request();
      const method = req.method().toUpperCase();
      const url = req.url();
      if (method === 'POST' && url.includes('/company/responsible-assignments/reassign')) {
        simulatedWrites++;
        const body = JSON.parse(req.postData() || '{}');
        const key = `${body.entity_type}:${Number(body.entity_id)}`;
        if (mode === 'partial') {
          const index = partialRequests.length + 1;
          partialRequests.push({ key, membership_ids: body.membership_ids || [] });
          await new Promise(resolve => setTimeout(resolve, 320));
          if (index === 2) {
            failedKey = key;
            return route.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ ok: false, message: 'Смоделированная ошибка сохранения.' }) });
          }
        }
        return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true, membership_ids: body.membership_ids || [] }) });
      }
      if (!['GET', 'HEAD', 'OPTIONS'].includes(method)) {
        productionWrites++;
        return route.abort('blockedbyclient');
      }
      return route.continue();
    });

    await ctx.addCookies([{ name: cookie, value: sid, url: base, httpOnly: true, secure: true, sameSite: 'Lax' }]);
    const page = await ctx.newPage();
    let response = await page.goto(base + '/company/dashboard', { waitUntil: 'domcontentloaded', timeout: 60000 });
    must(response && response.status() === 200, 'dashboard not 200');
    response = await page.goto(base + '/company/roles-access', { waitUntil: 'networkidle', timeout: 60000 });
    must(response && response.status() === 200, 'roles access not 200');
    must(await page.locator('[data-roles-access-root]').count() === 1, 'roles root missing');

    for (const width of [1920, 1440, 1366, 800]) {
      await page.setViewportSize({ width, height: 900 });
      await page.waitForTimeout(100);
      await noOverflow(page, `roles ${width}`);
      const geom = await page.locator('[data-roles-access-root]').evaluate(el => {
        const r = el.getBoundingClientRect();
        const p = el.parentElement.getBoundingClientRect();
        const s = getComputedStyle(el);
        return { width: r.width, parentWidth: p.width, leftGap: r.left - p.left, rightGap: p.right - r.right, border: s.borderTopWidth, bg: s.backgroundColor };
      });
      must(geom.width <= 1482, `workspace too wide at ${width}: ${JSON.stringify(geom)}`);
      must(Math.abs(geom.leftGap - geom.rightGap) <= 2, `workspace not centered at ${width}: ${JSON.stringify(geom)}`);
      must(geom.border !== '0px' && geom.bg !== 'rgba(0, 0, 0, 0)', `workspace is not standard system box at ${width}`);
    }

    await page.setViewportSize({ width: 1440, height: 900 });
    await waitAssignments(page);
    must(await page.locator('[data-assignment-group]').count() === 4, 'assignment panels != 4');

    const groups = page.locator('[data-assignment-group]');
    let filterGroup = null;
    let filterTargetLabel = '';
    let filterBefore = null;
    for (let i = 0; i < await groups.count(); i++) {
      const group = groups.nth(i);
      const rows = group.locator('.roles-access__assignment-row');
      if (await rows.count() < 2) continue;
      const data = await rows.evaluateAll(nodes => nodes.map(row => ({ label: (row.querySelector('span')?.textContent || '').trim(), checked: !!row.querySelector('input')?.checked, id: row.querySelector('input')?.dataset.assignmentId, type: row.querySelector('input')?.dataset.assignmentType })));
      const distinct = new Set(data.map(x => x.label));
      const target = data.find(x => !x.checked && x.label && distinct.size > 1);
      if (!target) continue;
      filterGroup = group;
      filterTargetLabel = target.label;
      filterBefore = Object.fromEntries(data.map(x => [`${x.type}:${x.id}`, x.checked]));
      break;
    }
    must(filterGroup, 'no suitable assignment group for filtered bulk test');
    const search = filterGroup.locator('[data-assignment-search]');
    await search.fill(filterTargetLabel);
    await page.waitForTimeout(100);
    must((await filterGroup.locator('.roles-access__assignment-row:not(.is-hidden)').count()) >= 1, 'search returned no rows');
    must((await filterGroup.locator('.roles-access__assignment-row.is-hidden').count()) >= 1, 'search did not filter any rows');
    must((await filterGroup.locator('[data-assignment-bulk="select"]').innerText()).trim() === 'Выбрать найденные', 'select filtered label mismatch');
    must((await filterGroup.locator('[data-assignment-bulk="clear"]').innerText()).trim() === 'Снять найденные', 'clear filtered label mismatch');
    await filterGroup.locator('[data-assignment-bulk="select"]').click();
    await waitBulkIdle(filterGroup);
    const filterAfter = await filterGroup.locator('.roles-access__assignment-row').evaluateAll(nodes => Object.fromEntries(nodes.map(row => { const b = row.querySelector('input'); return [`${b.dataset.assignmentType}:${b.dataset.assignmentId}`, { checked: !!b.checked, hidden: row.classList.contains('is-hidden') }]; })));
    for (const [key, state] of Object.entries(filterAfter)) {
      if (state.hidden) must(state.checked === filterBefore[key], `filtered bulk changed hidden row ${key}`);
    }
    must((await page.locator('[data-access-status]').innerText()).includes('Закрепления сохранены.'), 'filtered bulk success message missing');
    await search.fill('');
    await page.waitForTimeout(80);
    must((await filterGroup.locator('[data-assignment-bulk="select"]').innerText()).trim() === 'Выбрать все', 'empty search select label mismatch');
    must((await filterGroup.locator('[data-assignment-bulk="clear"]').innerText()).trim() === 'Снять все', 'empty search clear label mismatch');

    let partialGroup = null;
    let targetChecked = true;
    let partialBefore = null;
    for (let i = 0; i < await groups.count(); i++) {
      const group = groups.nth(i);
      const data = await group.locator('.roles-access__assignment-row input[type=checkbox]').evaluateAll(nodes => nodes.map(b => ({ key: `${b.dataset.assignmentType}:${b.dataset.assignmentId}`, checked: !!b.checked })));
      if (data.length < 3) continue;
      const unchecked = data.filter(x => !x.checked).length;
      const checked = data.filter(x => x.checked).length;
      if (unchecked >= 2) { partialGroup = group; targetChecked = true; partialBefore = Object.fromEntries(data.map(x => [x.key, x.checked])); break; }
      if (checked >= 2) { partialGroup = group; targetChecked = false; partialBefore = Object.fromEntries(data.map(x => [x.key, x.checked])); break; }
    }
    must(partialGroup, 'no suitable group for partial failure test');
    mode = 'partial';
    partialRequests = [];
    failedKey = '';
    const action = partialGroup.locator(`[data-assignment-bulk="${targetChecked ? 'select' : 'clear'}"]`);
    const other = partialGroup.locator(`[data-assignment-bulk="${targetChecked ? 'clear' : 'select'}"]`);
    await action.click();
    await page.waitForTimeout(90);
    const bothDisabled = await partialGroup.locator('[data-assignment-bulk]').evaluateAll(btns => btns.length === 2 && btns.every(b => b.disabled));
    must(bothDisabled, 'both bulk buttons are not disabled during operation');
    const beforeSecondClick = partialRequests.length;
    await other.evaluate(el => el.click());
    await page.waitForTimeout(90);
    must(partialRequests.length === beforeSecondClick, 'second bulk click started parallel operation');
    await waitBulkIdle(partialGroup);
    must(partialRequests.length >= 2, 'partial failure test made fewer than two simulated requests');
    must(!!failedKey, 'simulated failure was not exercised');
    const expectedFailed = 1;
    const expectedSaved = partialRequests.length - expectedFailed;
    const message = (await page.locator('[data-access-status]').innerText()).trim();
    must(message === `Сохранено: ${expectedSaved}. Не удалось: ${expectedFailed}.`, `partial result message mismatch: ${message}`);
    for (const req of partialRequests) {
      const [type, id] = req.key.split(':');
      const box = partialGroup.locator(`[data-assignment-type="${type}"][data-assignment-id="${id}"]`);
      must(await box.count() === 1, `request checkbox missing ${req.key}`);
      const checked = await box.isChecked();
      if (req.key === failedKey) must(checked === partialBefore[req.key], `failed checkbox did not roll back ${req.key}`);
      else must(checked === targetChecked, `successful checkbox did not persist ${req.key}`);
    }

    await page.screenshot({ path: `${out}/roles_access_bulk_1440.jpg`, type: 'jpeg', quality: 65, fullPage: true });
    await page.setViewportSize({ width: 1920, height: 900 });
    await noOverflow(page, 'assignments 1920');
    await page.screenshot({ path: `${out}/roles_access_bulk_1920.jpg`, type: 'jpeg', quality: 65, fullPage: true });
    await page.setViewportSize({ width: 1366, height: 900 }); await noOverflow(page, 'assignments 1366');
    await page.setViewportSize({ width: 800, height: 900 }); await noOverflow(page, 'assignments 800');

    must(productionWrites === 0, `real production writes attempted: ${productionWrites}`);
    const proof = [
      `EXACT_PRODUCTION_SHA=${process.env.EXPECTED_SHA}`,
      'VIEWPORTS=1920,1440,1366,800',
      'SYSTEM_BOX_LAYOUT=PASS',
      'HORIZONTAL_OVERFLOW=NO',
      'BULK_FILTER_LABELS=PASS',
      'PARTIAL_FAILURE_ROLLBACK=PASS',
      'BULK_DOUBLE_CLICK_GUARD=PASS',
      `SIMULATED_WRITE_REQUESTS=${simulatedWrites}`,
      'WRITE_REQUESTS=0',
      'BROWSER_UI_PASS=YES'
    ];
    fs.writeFileSync(`${out}/proof.txt`, proof.join('\n') + '\n');
    proof.slice(2).forEach(line => console.log(line));
    await ctx.close();
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error.stack || error); process.exit(1); });
