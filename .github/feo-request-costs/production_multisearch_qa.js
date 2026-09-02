const fs = require('fs');
const { chromium } = require('playwright');

const assert = (cond, msg) => { if (!cond) throw new Error(msg); };
const pageUrl = 'https://plan-ex.ru/erpv2/company/feo/requests/costs';
const expectedIds = ['257419', '281469', '257551'];
const exactInput = '257419\n\n281469\n257551';
const expectedNormalized = expectedIds.join(' ');
const cookieName = process.env.QA_COOKIE_NAME;
const sid = process.env.QA_SESSION_ID;
const appSha = process.env.APPLICATION_SHA;
const canonicalSha = process.env.CANONICAL_SHA;
if (!cookieName || !sid) throw new Error('QA session env missing');

function parseMoney(text) {
  const normalized = String(text || '').replace(/\s/g, '').replace(/[^0-9,.-]/g, '').replace(',', '.');
  const value = Number(normalized);
  return Number.isFinite(value) ? value : NaN;
}

(async () => {
  fs.mkdirSync('artifacts', { recursive: true });
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({ viewport: { width: 1920, height: 1080 }, deviceScaleFactor: 1 });
  await context.addCookies([{ name: cookieName, value: sid, domain: 'plan-ex.ru', path: '/', secure: true, httpOnly: true, sameSite: 'Lax' }]);
  const page = await context.newPage();

  const consoleErrors = [];
  const pageErrors = [];
  const costRequests = [];
  const forbiddenWrites = [];
  page.on('console', m => { if (m.type() === 'error') consoleErrors.push(m.text()); });
  page.on('pageerror', e => pageErrors.push(String(e)));
  page.on('request', req => {
    const u = req.url();
    if (u.includes('/company/feo/requests/costs')) {
      const row = { method: req.method(), url: u };
      costRequests.push(row);
      if (req.method() !== 'GET') forbiddenWrites.push(row);
    }
  });

  await page.route('**/company/feo/requests/costs**', async route => {
    if (route.request().method() !== 'GET') {
      forbiddenWrites.push({ method: route.request().method(), url: route.request().url(), blocked: true });
      return route.abort('blockedbyclient');
    }
    return route.continue();
  });

  const initial = await page.goto(pageUrl, { waitUntil: 'networkidle', timeout: 60000 });
  assert(initial && initial.status() === 200, 'authenticated production page HTTP != 200');
  assert(page.url().includes('/company/feo/requests/costs'), 'authenticated QA redirected away from costs page');
  await page.waitForSelector('[data-cost-page-ready]', { state: 'attached', timeout: 15000 });

  const search = page.locator('[data-cost-search]');
  assert(await search.count() === 1, 'search control missing');
  const tagName = await search.evaluate(el => el.tagName.toLowerCase());
  assert(tagName === 'textarea', 'search control is not textarea');

  const searchResponsePromise = page.waitForResponse(response => {
    try {
      const u = new URL(response.url());
      return response.request().method() === 'GET' && u.pathname.endsWith('/company/feo/requests/costs/search');
    } catch (_) { return false; }
  }, { timeout: 15000 });

  await search.fill(exactInput);
  assert(await search.inputValue() === exactInput, 'textarea did not preserve exact multiline input');

  const searchResponse = await searchResponsePromise;
  assert(searchResponse.status() === 200, 'real production search HTTP != 200');
  const searchUrl = new URL(searchResponse.url());
  const normalizedRequest = searchUrl.searchParams.get('request_ids');
  assert(normalizedRequest === expectedNormalized, `normalized network request mismatch: ${normalizedRequest}`);

  const backend = await searchResponse.json();
  assert(backend && backend.success === true, 'backend search success != true');
  assert(Array.isArray(backend.results), 'backend results missing');
  assert(backend.results.length === 3, `backend must return 3 independent results, got ${backend.results.length}`);
  const backendIds = backend.results.map(r => String(r.zayavka_id));
  assert(backendIds.join(' ') === expectedNormalized, `backend IDs/order mismatch: ${backendIds.join(' ')}`);
  assert(new Set(backendIds).size === 3, 'backend returned glued or duplicate IDs');

  await page.waitForFunction(() => document.querySelectorAll('.feo-cost-search-table tbody tr').length === 3, null, { timeout: 10000 });
  const rows = page.locator('.feo-cost-search-table tbody tr');
  assert(await rows.count() === 3, 'UI must render exactly 3 result rows');

  const uiRows = {};
  for (let i = 0; i < 3; i++) {
    const row = rows.nth(i);
    const cells = row.locator('td');
    const id = (await cells.nth(0).innerText()).trim();
    uiRows[id] = {
      block: (await cells.nth(3).innerText()).trim(),
      cost: (await cells.nth(6).innerText()).trim(),
      text: (await row.innerText()).trim(),
      hasBlockAttribute: await row.getAttribute('data-cost-search-block-id')
    };
  }
  assert(Object.keys(uiRows).sort().join(' ') === [...expectedIds].sort().join(' '), 'UI rows do not contain three independent requested IDs');

  for (const item of backend.results) {
    const id = String(item.zayavka_id);
    const ui = uiRows[id];
    assert(ui, `UI row missing for ${id}`);
    if (item.block) {
      const expectedBlock = String(item.block.block_id);
      assert(ui.block === `#${expectedBlock}`, `request ${id} mapped to wrong block: ${ui.block} != #${expectedBlock}`);
      const uiCost = parseMoney(ui.cost);
      const backendCost = Number(item.block.total_cost);
      assert(Number.isFinite(uiCost), `request ${id} cost is not numeric in UI: ${ui.cost}`);
      assert(Math.abs(uiCost - backendCost) < 0.01, `request ${id} wrong cost: ${uiCost} != ${backendCost}`);
      assert(ui.hasBlockAttribute === expectedBlock, `request ${id} row block attribute mismatch`);
    } else {
      assert(ui.block.includes('Стоимость не назначена'), `request ${id} should be unpriced but UI says ${ui.block}`);
      assert(ui.hasBlockAttribute === null, `unpriced request ${id} unexpectedly has block attribute`);
    }
  }

  const pricedItem = backend.results.find(item => item.block);
  assert(pricedItem, 'none of mandatory QA requests has a cost block; cannot verify popup mapping');
  const pricedId = String(pricedItem.zayavka_id);
  const pricedBlockId = String(pricedItem.block.block_id);
  let pricedRow = null;
  for (let i = 0; i < 3; i++) {
    const row = rows.nth(i);
    if ((await row.locator('td').nth(0).innerText()).trim() === pricedId) { pricedRow = row; break; }
  }
  assert(pricedRow, `priced UI row missing for ${pricedId}`);

  const detailResponsePromise = page.waitForResponse(response => {
    try {
      const u = new URL(response.url());
      return response.request().method() === 'GET' && u.pathname.endsWith(`/company/feo/requests/costs/blocks/${pricedBlockId}/json`);
    } catch (_) { return false; }
  }, { timeout: 15000 });
  await pricedRow.dblclick();
  const detailResponse = await detailResponsePromise;
  assert(detailResponse.status() === 200, 'block detail HTTP != 200');
  const detailJson = await detailResponse.json();
  assert(detailJson && detailJson.success === true && detailJson.block, 'block detail payload invalid');
  assert(String(detailJson.block.id) === pricedBlockId, `popup opened wrong block ${detailJson.block.id} != ${pricedBlockId}`);
  assert((detailJson.block.requests || []).some(r => String(r.zayavka_id) === pricedId), `popup block ${pricedBlockId} does not contain request ${pricedId}`);
  await page.waitForSelector('#feo-cost-detail-modal.is-open', { timeout: 10000 });
  const modalTitle = (await page.locator('[data-cost-detail-title]').innerText()).trim();
  assert(modalTitle.includes(`#${pricedBlockId}`), `modal title is wrong: ${modalTitle}`);
  const modalBody = await page.locator('[data-cost-detail-body]').innerText();
  assert(modalBody.includes(pricedId), `modal body does not contain selected request ${pricedId}`);
  await page.locator('#feo-cost-detail-modal [data-close-modal="feo-cost-detail-modal"]').first().click();

  const searchCountBeforeInvalid = costRequests.filter(r => {
    try { return r.method === 'GET' && new URL(r.url).pathname.endsWith('/company/feo/requests/costs/search'); }
    catch (_) { return false; }
  }).length;
  await search.fill('invalid abc 12345\nno-six-digit-id');
  await page.waitForTimeout(800);
  const searchCountAfterInvalid = costRequests.filter(r => {
    try { return r.method === 'GET' && new URL(r.url).pathname.endsWith('/company/feo/requests/costs/search'); }
    catch (_) { return false; }
  }).length;
  assert(searchCountAfterInvalid === searchCountBeforeInvalid, 'invalid input triggered a false search network request');
  const invalidText = await page.locator('[data-cost-search-results]').innerText();
  assert(invalidText.includes('Не найдено самостоятельных шестизначных номеров заявок'), 'invalid input warning missing');

  assert(forbiddenWrites.length === 0, 'production business write attempt detected: ' + JSON.stringify(forbiddenWrites));
  assert(consoleErrors.length === 0, 'console errors: ' + consoleErrors.join(' | '));
  assert(pageErrors.length === 0, 'page errors: ' + pageErrors.join(' | '));

  await search.fill(exactInput);
  await page.waitForFunction(() => document.querySelectorAll('.feo-cost-search-table tbody tr').length === 3, null, { timeout: 10000 });
  await page.screenshot({ path: 'artifacts/feo-request-costs-multisearch-production.png', fullPage: true });

  const summary = {
    productionQa: 'PASS',
    url: pageUrl,
    applicationSha: appSha,
    canonicalSha,
    exactInput,
    normalizedRequest,
    backendResults: backend.results.map(item => ({
      requestId: String(item.zayavka_id),
      blockId: item.block ? String(item.block.block_id) : null,
      totalCost: item.block ? Number(item.block.total_cost) : null,
      existsInFeo: !!item.exists_in_feo,
      isCurrent: !!item.is_current
    })),
    uiRows,
    popup: { requestId: pricedId, blockId: pricedBlockId, modalTitle },
    invalidInputSearchDelta: searchCountAfterInvalid - searchCountBeforeInvalid,
    forbiddenWrites,
    consoleErrors,
    pageErrors
  };
  fs.writeFileSync('artifacts/qa_summary.json', JSON.stringify(summary, null, 2));
  fs.writeFileSync('artifacts/network_requests.json', JSON.stringify(costRequests, null, 2));
  console.log('FEO_REQUEST_COSTS_AUTHENTICATED_PRODUCTION_QA=PASS');
  console.log('NORMALIZED_REQUEST=' + normalizedRequest);
  console.log('BACKEND_RESULTS=' + JSON.stringify(summary.backendResults));
  console.log('POPUP_REQUEST=' + pricedId + ' BLOCK=' + pricedBlockId);
  console.log('BUSINESS_WRITES=0');
  await browser.close();
})().catch(async e => {
  console.error(e && e.stack || e);
  process.exit(1);
});
