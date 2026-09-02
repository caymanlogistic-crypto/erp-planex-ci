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

function isSearchResponse(response) {
  try {
    const u = new URL(response.url());
    return response.request().method() === 'GET' && u.pathname.endsWith('/company/feo/requests/costs/search');
  } catch (_) { return false; }
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
  assert(await search.evaluate(el => el.tagName.toLowerCase()) === 'textarea', 'search control is not textarea');

  async function performSearch(input) {
    const responsePromise = page.waitForResponse(isSearchResponse, { timeout: 15000 });
    await search.fill(input);
    const response = await responsePromise;
    assert(response.status() === 200, 'real production search HTTP != 200');
    return { response, json: await response.json(), url: new URL(response.url()) };
  }

  const mandatorySearch = await performSearch(exactInput);
  assert(await search.inputValue() === exactInput, 'textarea did not preserve exact multiline input');
  const normalizedRequest = mandatorySearch.url.searchParams.get('request_ids');
  assert(normalizedRequest === expectedNormalized, `normalized network request mismatch: ${normalizedRequest}`);

  const backend = mandatorySearch.json;
  assert(backend && backend.success === true, 'backend search success != true');
  assert(Array.isArray(backend.results), 'backend results missing');
  assert(backend.results.length === 3, `backend must return 3 independent results, got ${backend.results.length}`);
  const backendIds = backend.results.map(r => String(r.zayavka_id));
  assert(backendIds.join(' ') === expectedNormalized, `backend IDs/order mismatch: ${backendIds.join(' ')}`);
  assert(new Set(backendIds).size === 3, 'backend returned glued or duplicate IDs');

  await page.waitForFunction(() => document.querySelectorAll('.feo-cost-search-table tbody tr').length === 3, null, { timeout: 10000 });
  let rows = page.locator('.feo-cost-search-table tbody tr');
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

  let popupRequestId = null;
  let popupBlockId = null;
  let popupCost = null;
  let popupSource = null;
  let modalTitle = null;

  const mandatoryPriced = backend.results.find(item => item.block);
  if (mandatoryPriced) {
    popupRequestId = String(mandatoryPriced.zayavka_id);
    popupBlockId = String(mandatoryPriced.block.block_id);
    popupCost = Number(mandatoryPriced.block.total_cost);
    popupSource = 'mandatory-input';
  } else {
    const mainRows = page.locator('tbody tr[data-cost-block-id]');
    assert(await mainRows.count() > 0, 'production has no priced block row available for modal QA');
    const mainRow = mainRows.first();
    const discoveredBlockId = String(await mainRow.getAttribute('data-cost-block-id'));
    assert(discoveredBlockId && /^\d+$/.test(discoveredBlockId), 'main priced row has invalid block id');

    const discoveryDetailPromise = page.waitForResponse(response => {
      try {
        const u = new URL(response.url());
        return response.request().method() === 'GET' && u.pathname.endsWith(`/company/feo/requests/costs/blocks/${discoveredBlockId}/json`);
      } catch (_) { return false; }
    }, { timeout: 15000 });
    await mainRow.dblclick();
    const discoveryDetail = await discoveryDetailPromise;
    assert(discoveryDetail.status() === 200, 'priced block discovery detail HTTP != 200');
    const discoveryJson = await discoveryDetail.json();
    assert(discoveryJson && discoveryJson.success === true && discoveryJson.block, 'priced block discovery payload invalid');
    assert(String(discoveryJson.block.id) === discoveredBlockId, 'main row opened wrong priced block');
    const firstRequest = (discoveryJson.block.requests || [])[0];
    assert(firstRequest && /^\d{6}$/.test(String(firstRequest.zayavka_id)), 'priced block has no six-digit request for popup QA');
    popupRequestId = String(firstRequest.zayavka_id);
    popupBlockId = discoveredBlockId;
    popupCost = Number(discoveryJson.block.total_cost);
    popupSource = 'discovered-production-priced-block';
    await page.waitForSelector('#feo-cost-detail-modal.is-open', { timeout: 10000 });
    await page.locator('#feo-cost-detail-modal [data-close-modal="feo-cost-detail-modal"]').first().click();

    const pricedSearch = await performSearch(popupRequestId);
    assert(pricedSearch.json && pricedSearch.json.success === true, 'priced request search failed');
    assert(Array.isArray(pricedSearch.json.results) && pricedSearch.json.results.length === 1, 'priced request search must return one result');
    const pricedResult = pricedSearch.json.results[0];
    assert(String(pricedResult.zayavka_id) === popupRequestId, 'priced search returned wrong request');
    assert(pricedResult.block, 'priced request unexpectedly has no block in search');
    assert(String(pricedResult.block.block_id) === popupBlockId, `priced request mapped to wrong block ${pricedResult.block.block_id} != ${popupBlockId}`);
    assert(Math.abs(Number(pricedResult.block.total_cost) - popupCost) < 0.01, 'priced request backend cost differs from block detail cost');
    await page.waitForFunction(() => document.querySelectorAll('.feo-cost-search-table tbody tr').length === 1, null, { timeout: 10000 });
    const pricedSearchRow = page.locator('.feo-cost-search-table tbody tr').first();
    const pricedCells = pricedSearchRow.locator('td');
    assert((await pricedCells.nth(0).innerText()).trim() === popupRequestId, 'priced UI search row has wrong request id');
    assert((await pricedCells.nth(3).innerText()).trim() === `#${popupBlockId}`, 'priced UI search row has wrong block');
    assert(Math.abs(parseMoney(await pricedCells.nth(6).innerText()) - popupCost) < 0.01, 'priced UI search row has wrong cost');
  }

  rows = page.locator('.feo-cost-search-table tbody tr');
  let popupRow = null;
  for (let i = 0; i < await rows.count(); i++) {
    const row = rows.nth(i);
    if ((await row.locator('td').nth(0).innerText()).trim() === popupRequestId) { popupRow = row; break; }
  }
  if (!popupRow) {
    const pricedSearch = await performSearch(popupRequestId);
    assert(pricedSearch.json && pricedSearch.json.results && pricedSearch.json.results.length === 1, 'popup request re-search failed');
    await page.waitForFunction(() => document.querySelectorAll('.feo-cost-search-table tbody tr').length === 1, null, { timeout: 10000 });
    popupRow = page.locator('.feo-cost-search-table tbody tr').first();
  }
  assert(await popupRow.getAttribute('data-cost-search-block-id') === popupBlockId, 'popup search row points to wrong block');

  const detailResponsePromise = page.waitForResponse(response => {
    try {
      const u = new URL(response.url());
      return response.request().method() === 'GET' && u.pathname.endsWith(`/company/feo/requests/costs/blocks/${popupBlockId}/json`);
    } catch (_) { return false; }
  }, { timeout: 15000 });
  await popupRow.dblclick();
  const detailResponse = await detailResponsePromise;
  assert(detailResponse.status() === 200, 'popup block detail HTTP != 200');
  const detailJson = await detailResponse.json();
  assert(detailJson && detailJson.success === true && detailJson.block, 'popup block detail payload invalid');
  assert(String(detailJson.block.id) === popupBlockId, `popup opened wrong block ${detailJson.block.id} != ${popupBlockId}`);
  assert((detailJson.block.requests || []).some(r => String(r.zayavka_id) === popupRequestId), `popup block ${popupBlockId} does not contain request ${popupRequestId}`);
  await page.waitForSelector('#feo-cost-detail-modal.is-open', { timeout: 10000 });
  modalTitle = (await page.locator('[data-cost-detail-title]').innerText()).trim();
  assert(modalTitle.includes(`#${popupBlockId}`), `modal title is wrong: ${modalTitle}`);
  const modalBody = await page.locator('[data-cost-detail-body]').innerText();
  assert(modalBody.includes(popupRequestId), `modal body does not contain selected request ${popupRequestId}`);
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

  const finalMandatory = await performSearch(exactInput);
  assert(finalMandatory.url.searchParams.get('request_ids') === expectedNormalized, 'final exact multiline normalization drifted');
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
    popup: { requestId: popupRequestId, blockId: popupBlockId, totalCost: popupCost, source: popupSource, modalTitle },
    invalidInputSearchDelta: searchCountAfterInvalid - searchCountBeforeInvalid,
    forbiddenWrites,
    consoleErrors,
    pageErrors
  };
  fs.writeFileSync('artifacts/qa_summary.json', JSON.stringify(summary, null, 2));
  fs.writeFileSync('artifacts/network_requests.json', JSON.stringify(costRequests, null, 2));
  console.log('FEO_REQUEST_COSTS_AUTHENTICATED_PRODUCTION_QA=PASS');
  console.log('NORMALIZED_REQUEST=' + normalizedRequest);
  console.log('MANDATORY_BACKEND_RESULTS=' + JSON.stringify(summary.backendResults));
  console.log('POPUP_EVIDENCE=' + JSON.stringify(summary.popup));
  console.log('INVALID_INPUT_SEARCH_DELTA=0');
  console.log('BUSINESS_WRITES=0');
  await browser.close();
})().catch(async e => {
  console.error(e && e.stack || e);
  process.exit(1);
});
