const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const base = 'https://plan-ex.ru/erpv2';
const target = `${base}/company/trips/departures`;
const cookieName = process.env.QA_COOKIE_NAME;
const sessions = {
  company_owner: process.env.QA_OWNER_SESSION_ID,
  senior_logist: process.env.QA_SENIOR_SESSION_ID,
  logist: process.env.QA_LOGIST_SESSION_ID,
};
const tripIds = {
  1: Number(process.env.QA_LEGACY_1_TRIP_ID || 0),
  257: Number(process.env.QA_LEGACY_257_TRIP_ID || 0),
  258: Number(process.env.QA_LEGACY_258_TRIP_ID || 0),
};
const viewports = [
  { width: 1366, height: 900 },
  { width: 1920, height: 1080 },
];

if (!cookieName || Object.values(sessions).some(v => !v)) throw new Error('authenticated QA session env missing');
if (Object.values(tripIds).some(v => !Number.isInteger(v) || v <= 0)) throw new Error(`legacy/current trip mapping env missing: ${JSON.stringify(tripIds)}`);

fs.mkdirSync('artifacts/screenshots', { recursive: true });
fs.mkdirSync('artifacts/dom', { recursive: true });

const cookie = sid => ({
  name: cookieName,
  value: sid,
  domain: 'plan-ex.ru',
  path: '/',
  secure: true,
  httpOnly: true,
  sameSite: 'Lax',
});

const cleanUrl = raw => {
  try {
    const u = new URL(raw);
    return `${u.origin}${u.pathname}${u.search}`;
  } catch {
    return raw;
  }
};

const ensure = (condition, message) => {
  if (!condition) throw new Error(message);
};

async function registryDom(page) {
  return page.evaluate(() => {
    const visible = el => {
      if (!el) return false;
      const style = getComputedStyle(el);
      const box = el.getBoundingClientRect();
      return style.display !== 'none' && style.visibility !== 'hidden' && box.width > 0 && box.height > 0;
    };
    const title = document.querySelector('.page-title');
    const table = document.querySelector('table.waste-trip-table');
    const found = document.querySelector('.found-label');
    const main = document.querySelector('main.content');
    const emptyStates = [...document.querySelectorAll('.empty-state')];
    return {
      titleText: (title?.textContent || '').trim(),
      titleVisible: visible(title),
      emptyStateCount: emptyStates.filter(visible).length,
      emptyStateTotalCount: emptyStates.length,
      tableVisible: visible(table),
      rowCount: table ? table.querySelectorAll('tbody tr').length : 0,
      foundText: (found?.textContent || '').replace(/\s+/g, ' ').trim(),
      horizontalOverflow: document.documentElement.scrollWidth > window.innerWidth + 1,
      documentScrollWidth: document.documentElement.scrollWidth,
      innerWidth: window.innerWidth,
      openOverlayCount: [...document.querySelectorAll('.modal-overlay')].filter(x => x.classList.contains('is-open')).length,
      nestedOverlayCount: document.querySelectorAll('.modal-overlay .modal-overlay').length,
      mainHtml: main?.outerHTML || '',
    };
  });
}

async function modalSnapshot(page) {
  return page.evaluate(() => {
    const root = document.querySelector('#waste-trip-view-modal');
    const rows = [...document.querySelectorAll('#waste-trip-view-body .trip-view-table > tbody > tr')];
    const fields = {};
    for (const tr of rows) {
      const cells = tr.querySelectorAll(':scope > td');
      if (cells.length >= 2) fields[(cells[0].innerText || '').trim()] = (cells[1].innerText || '').replace(/\s+/g, ' ').trim();
    }
    const open = [...document.querySelectorAll('.modal-overlay')].filter(x => x.classList.contains('is-open'));
    const modal = root?.querySelector('.modal');
    const box = modal?.getBoundingClientRect();
    return {
      title: (document.querySelector('#waste-trip-view-title')?.textContent || '').trim(),
      fields,
      openCount: open.length,
      openIds: open.map(x => x.id),
      nestedOverlayCount: document.querySelectorAll('.modal-overlay .modal-overlay').length,
      horizontalOverflow: document.documentElement.scrollWidth > window.innerWidth + 1,
      modalClippedHorizontally: box ? (box.left < -1 || box.right > window.innerWidth + 1) : null,
      bodyHtml: document.querySelector('#waste-trip-view-body')?.outerHTML || '',
    };
  });
}

async function openImportedTrip(page, currentTripId, legacyFlightId, label) {
  const button = page.locator(`[data-waste-view="${currentTripId}"]`);
  ensure(await button.count() === 1, `open button missing for current trip ${currentTripId} / legacy ${legacyFlightId}`);
  const responsePromise = page.waitForResponse(r => r.url().includes(`/company/trips/departures/${currentTripId}/modal-view`), { timeout: 30000 });
  await button.click();
  const modalResponse = await responsePromise;
  ensure(modalResponse.status() === 200, `modal HTTP ${modalResponse.status()} for legacy ${legacyFlightId}`);
  await page.locator('#waste-trip-view-modal.is-open').waitFor({ state: 'visible', timeout: 30000 });
  await page.locator('#waste-trip-view-body .trip-view-table').waitFor({ state: 'visible', timeout: 30000 });
  await page.waitForFunction(expected => {
    const body = document.querySelector('#waste-trip-view-body');
    return body && (body.innerText || '').includes(`Legacy flights #${expected}`);
  }, legacyFlightId, { timeout: 30000 });

  const snap = await modalSnapshot(page);
  ensure(snap.openCount === 1, `popup stack must equal 1 for legacy ${legacyFlightId}, got ${snap.openCount}`);
  ensure(snap.openIds[0] === 'waste-trip-view-modal', `unexpected open popup for legacy ${legacyFlightId}: ${snap.openIds.join(',')}`);
  ensure(snap.nestedOverlayCount === 0, `nested popup found for legacy ${legacyFlightId}`);
  ensure(snap.horizontalOverflow === false, `document horizontal overflow with legacy ${legacyFlightId} modal`);
  ensure(snap.modalClippedHorizontally === false, `modal clipped horizontally for legacy ${legacyFlightId}`);
  ensure(snap.fields['Источник'] === `Legacy flights #${legacyFlightId}`, `source evidence mismatch for legacy ${legacyFlightId}: ${snap.fields['Источник']}`);
  if (legacyFlightId === 257) ensure((snap.fields['Расхождение массы'] || '').includes('-145,000 кг'), `legacy 257 mass evidence mismatch: ${snap.fields['Расхождение массы']}`);
  if (legacyFlightId === 258) ensure((snap.fields['Расхождение массы'] || '').includes('-152,000 кг'), `legacy 258 mass evidence mismatch: ${snap.fields['Расхождение массы']}`);
  if (legacyFlightId === 1) ensure((snap.fields['Исполнитель'] || '').startsWith('Исторический:'), `historical executor evidence missing: ${snap.fields['Исполнитель']}`);

  const domPath = `artifacts/dom/${label}-legacy-${legacyFlightId}.json`;
  fs.writeFileSync(domPath, JSON.stringify(snap, null, 2), 'utf8');

  if (legacyFlightId === 1) {
    const executorCell = page.locator('#waste-trip-view-body .trip-view-table > tbody > tr').filter({ has: page.locator('td:first-child', { hasText: 'Исполнитель' }) }).locator('td').nth(1);
    await executorCell.scrollIntoViewIfNeeded();
    await page.screenshot({ path: `artifacts/screenshots/${label}-legacy-1-executor.png`, fullPage: false });
  }
  const sourceCell = page.locator('#waste-trip-view-body .trip-view-table > tbody > tr').filter({ has: page.locator('td:first-child', { hasText: 'Источник' }) }).locator('td').nth(1);
  await sourceCell.scrollIntoViewIfNeeded();
  await page.screenshot({ path: `artifacts/screenshots/${label}-legacy-${legacyFlightId}-source.png`, fullPage: false });

  await page.evaluate(() => closeModal('waste-trip-view-modal'));
  await page.waitForFunction(() => !document.querySelector('#waste-trip-view-modal')?.classList.contains('is-open'));
  return { currentTripId, legacyFlightId, httpStatus: modalResponse.status(), domPath, snapshot: snap };
}

async function runScenario(browser, role, sid, viewport) {
  const label = `${role}-${viewport.width}x${viewport.height}`;
  const context = await browser.newContext({ viewport });
  const nonReadRequests = [];
  await context.route('**/*', async route => {
    const method = route.request().method().toUpperCase();
    if (!['GET', 'HEAD', 'OPTIONS'].includes(method)) {
      nonReadRequests.push({ method, url: cleanUrl(route.request().url()) });
      await route.abort('blockedbyclient');
      return;
    }
    await route.continue();
  });
  await context.addCookies([cookie(sid)]);
  const page = await context.newPage();
  const consoleErrors = [];
  const pageErrors = [];
  const requestFailures = [];
  const badResponses = [];
  const responses = [];
  page.on('console', msg => { if (msg.type() === 'error') consoleErrors.push(msg.text()); });
  page.on('pageerror', err => pageErrors.push(String(err)));
  page.on('requestfailed', req => requestFailures.push({ method: req.method(), url: cleanUrl(req.url()), error: req.failure()?.errorText || '' }));
  page.on('response', res => {
    const item = { method: res.request().method(), url: cleanUrl(res.url()), status: res.status(), resourceType: res.request().resourceType() };
    responses.push(item);
    if (res.status() >= 400) badResponses.push(item);
  });

  const result = {
    role,
    viewport,
    label,
    passed: false,
    error: null,
    httpStatus: null,
    finalUrl: null,
    registry: null,
    importedTrips: [],
    consoleErrors,
    pageErrors,
    requestFailures,
    badResponses,
    nonReadRequests,
    responses,
  };

  try {
    const nav = await page.goto(target, { waitUntil: 'networkidle', timeout: 60000 });
    result.httpStatus = nav?.status() ?? null;
    result.finalUrl = page.url();
    ensure(result.httpStatus === 200, `registry HTTP must be 200, got ${result.httpStatus}`);
    ensure(result.finalUrl === target, `unexpected final registry URL ${result.finalUrl}`);

    const dom = await registryDom(page);
    result.registry = dom;
    ensure(dom.titleVisible && dom.titleText === 'Перевозка отходов', `title not visible/correct: ${dom.titleText}`);
    ensure(dom.emptyStateCount === 0, `visible empty-state present: count=${dom.emptyStateCount} totalHiddenOrVisible=${dom.emptyStateTotalCount}`);
    ensure(dom.tableVisible, 'Waste Trips registry table not visible');
    ensure(dom.rowCount === 75, `registry row count must be 75, got ${dom.rowCount}`);
    ensure(/^Найдено:\s*75\s+рейсов$/u.test(dom.foundText), `found label mismatch: ${dom.foundText}`);
    ensure(dom.horizontalOverflow === false, `registry horizontal overflow scrollWidth=${dom.documentScrollWidth} innerWidth=${dom.innerWidth}`);
    ensure(dom.openOverlayCount === 0, `unexpected popup open on initial registry: ${dom.openOverlayCount}`);
    ensure(dom.nestedOverlayCount === 0, `nested overlay shell exists in registry: ${dom.nestedOverlayCount}`);

    fs.writeFileSync(`artifacts/dom/${label}-registry.json`, JSON.stringify(dom, null, 2), 'utf8');
    await page.screenshot({ path: `artifacts/screenshots/${label}-registry.png`, fullPage: false });

    for (const legacyId of [257, 258, 1]) {
      result.importedTrips.push(await openImportedTrip(page, tripIds[legacyId], legacyId, label));
    }

    const finalDom = await registryDom(page);
    ensure(finalDom.rowCount === 75, `registry changed during modal checks: ${finalDom.rowCount}`);
    ensure(/^Найдено:\s*75\s+рейсов$/u.test(finalDom.foundText), `found label changed: ${finalDom.foundText}`);
    ensure(finalDom.emptyStateCount === 0, `visible empty-state appeared after modal checks: ${finalDom.emptyStateCount}`);
    ensure(finalDom.horizontalOverflow === false, 'horizontal overflow after modal checks');
    ensure(finalDom.openOverlayCount === 0, `popup remained open after checks: ${finalDom.openOverlayCount}`);

    ensure(nonReadRequests.length === 0, `non-read browser requests detected: ${JSON.stringify(nonReadRequests)}`);
    ensure(consoleErrors.length === 0, `consoleErrors=${consoleErrors.length}: ${consoleErrors.join(' | ')}`);
    ensure(pageErrors.length === 0, `pageErrors=${pageErrors.length}: ${pageErrors.join(' | ')}`);
    ensure(requestFailures.length === 0, `requestFailures=${requestFailures.length}: ${JSON.stringify(requestFailures)}`);
    ensure(badResponses.length === 0, `badResponses=${badResponses.length}: ${JSON.stringify(badResponses)}`);
    result.passed = true;
  } catch (err) {
    result.error = String(err && err.stack ? err.stack : err);
    try { await page.screenshot({ path: `artifacts/screenshots/${label}-FAIL.png`, fullPage: false }); } catch {}
  } finally {
    try {
      const finalDiagnostics = await registryDom(page);
      result.finalDiagnostics = finalDiagnostics;
    } catch {}
    await page.close().catch(() => {});
    await context.close().catch(() => {});
  }

  fs.writeFileSync(`artifacts/dom/${label}-result.json`, JSON.stringify(result, null, 2), 'utf8');
  return result;
}

(async () => {
  const browser = await chromium.launch({ headless: true });
  const results = [];
  try {
    for (const [role, sid] of Object.entries(sessions)) {
      for (const viewport of viewports) {
        results.push(await runScenario(browser, role, sid, viewport));
      }
    }
  } finally {
    await browser.close();
  }

  const summary = {
    target,
    expectedRegistryRows: 75,
    expectedFoundLabel: 'Найдено: 75 рейсов',
    roles: Object.keys(sessions),
    viewports,
    legacyTripMappings: tripIds,
    scenarioCount: results.length,
    passedScenarioCount: results.filter(r => r.passed).length,
    results,
  };
  fs.writeFileSync('artifacts/browser-results.json', JSON.stringify(summary, null, 2), 'utf8');

  for (const r of results) {
    console.log(`WASTE_AUTH_BROWSER role=${r.role} viewport=${r.viewport.width}x${r.viewport.height} pass=${r.passed} http=${r.httpStatus} rows=${r.registry?.rowCount ?? 'NA'} found=${JSON.stringify(r.registry?.foundText ?? '')} visibleEmpty=${r.registry?.emptyStateCount ?? 'NA'} consoleErrors=${r.consoleErrors.length} pageErrors=${r.pageErrors.length} requestFailures=${r.requestFailures.length} badResponses=${r.badResponses.length} nonReadRequests=${r.nonReadRequests.length} overflow=${r.registry?.horizontalOverflow ?? 'NA'}`);
    for (const t of r.importedTrips) {
      console.log(`WASTE_AUTH_MODAL role=${r.role} viewport=${r.viewport.width} legacy=${t.legacyFlightId} current=${t.currentTripId} http=${t.httpStatus} popupStack=${t.snapshot.openCount} source=${JSON.stringify(t.snapshot.fields['Источник'] || '')} executor=${JSON.stringify(t.snapshot.fields['Исполнитель'] || '')}`);
    }
    if (!r.passed) console.error(`WASTE_AUTH_FAILURE role=${r.role} viewport=${r.viewport.width} error=${r.error}`);
  }

  ensure(results.length === 6, `expected 6 scenarios, got ${results.length}`);
  ensure(results.every(r => r.passed), `authenticated production acceptance failed in ${results.filter(r => !r.passed).length} scenario(s)`);
  console.log('WASTE_AUTHENTICATED_PLAYWRIGHT_PRODUCTION=PASS');
})().catch(err => {
  console.error(err && err.stack ? err.stack : err);
  process.exit(1);
});
