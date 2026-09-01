const fs = require('fs');
const { chromium } = require('playwright');

const base = 'https://plan-ex.ru/erpv2';
const target = base + '/company/trips/departures';
const importUrl = target + '/import';
const cookieName = process.env.QA_COOKIE_NAME;
const sessions = {
  company_owner: process.env.QA_OWNER_SESSION_ID,
  senior_logist: process.env.QA_SENIOR_SESSION_ID,
  logist: process.env.QA_LOGIST_SESSION_ID,
};
if (!cookieName || Object.values(sessions).some(v => !v)) throw new Error('QA session env missing');
fs.mkdirSync('artifacts', { recursive: true });
const cookie = sid => ({ name: cookieName, value: sid, domain: 'plan-ex.ru', path: '/', secure: true, httpOnly: true, sameSite: 'Lax' });

function sanitizeUrl(url) {
  try { const u = new URL(url); u.search = ''; return u.toString(); } catch { return url; }
}

async function inspectPage(browser, role, sid, viewport) {
  const context = await browser.newContext({ viewport });
  await context.addCookies([cookie(sid)]);
  const page = await context.newPage();
  const consoleErrors = [], pageErrors = [], requestFailures = [], badResponses = [];
  page.on('console', m => { if (m.type() === 'error') consoleErrors.push(m.text()); });
  page.on('pageerror', e => pageErrors.push(String(e)));
  page.on('requestfailed', r => requestFailures.push({ url: sanitizeUrl(r.url()), error: r.failure()?.errorText || '' }));
  page.on('response', r => {
    if (r.status() >= 400 && !/favicon/i.test(r.url())) {
      badResponses.push({ url: sanitizeUrl(r.url()), status: r.status(), resourceType: r.request().resourceType() });
    }
  });

  const nav = await page.goto(target, { waitUntil: 'networkidle', timeout: 60000 });
  const label = `${role}-${viewport.width}x${viewport.height}`;
  fs.writeFileSync(`artifacts/${label}.html`, await page.content(), 'utf8');

  const dom = await page.evaluate(() => {
    const visible = el => !!el && getComputedStyle(el).display !== 'none' && getComputedStyle(el).visibility !== 'hidden' && el.getBoundingClientRect().width > 0 && el.getBoundingClientRect().height > 0;
    const main = document.querySelector('main.content');
    const title = document.querySelector('.page-title');
    const empty = document.querySelector('.empty-state');
    const table = document.querySelector('.waste-trip-table');
    const createButton = [...document.querySelectorAll('button,a')].find(x => (x.textContent || '').trim().includes('Создать рейс'));
    const importButton = [...document.querySelectorAll('button,a')].find(x => (x.textContent || '').trim().includes('Импорт из старой системы'));
    return {
      mainVisible: visible(main),
      titleText: (title?.textContent || '').trim(),
      titleVisible: visible(title),
      createVisible: visible(createButton),
      importVisible: visible(importButton),
      emptyVisible: visible(empty),
      emptyText: (empty?.innerText || '').trim(),
      tableVisible: visible(table),
      rowCount: table ? table.querySelectorAll('tbody tr').length : 0,
      scrollWidth: document.documentElement.scrollWidth,
      innerWidth: window.innerWidth,
      nestedOverlays: document.querySelectorAll('.modal-overlay .modal-overlay').length,
    };
  });

  if (dom.createVisible) {
    await page.evaluate(() => {
      if (typeof window.openModal !== 'function') throw new Error('openModal unavailable');
      window.openModal('waste-trip-create-modal');
    });
    await page.waitForTimeout(150);
  }
  const modal = await page.evaluate(() => {
    const overlays = [...document.querySelectorAll('.modal-overlay')];
    const open = overlays.filter(el => el.classList.contains('is-open') && getComputedStyle(el).display !== 'none' && getComputedStyle(el).visibility !== 'hidden');
    const box = document.querySelector('#waste-trip-create-modal .modal')?.getBoundingClientRect();
    return {
      openCount: open.length,
      openIds: open.map(x => x.id),
      clipped: box ? (box.left < -1 || box.right > innerWidth + 1 || box.top < -1 || box.bottom > innerHeight + 1) : null,
      horizontalOverflow: document.documentElement.scrollWidth > innerWidth + 2,
    };
  });
  await page.screenshot({ path: `artifacts/${label}.png`, fullPage: true });

  const importPage = await context.newPage();
  const importConsoleErrors = [], importPageErrors = [], importBadResponses = [], importRequestFailures = [];
  importPage.on('console', m => { if (m.type() === 'error') importConsoleErrors.push(m.text()); });
  importPage.on('pageerror', e => importPageErrors.push(String(e)));
  importPage.on('requestfailed', r => importRequestFailures.push({ url: sanitizeUrl(r.url()), error: r.failure()?.errorText || '' }));
  importPage.on('response', r => { if (r.status() >= 400 && !/favicon/i.test(r.url())) importBadResponses.push({url:sanitizeUrl(r.url()),status:r.status()}); });
  const importNav = await importPage.goto(importUrl, { waitUntil: 'networkidle', timeout: 60000 });
  const importState = await importPage.evaluate(() => {
    const main = document.querySelector('main.content');
    const title = document.querySelector('.page-title');
    const visible = el => !!el && getComputedStyle(el).display !== 'none' && getComputedStyle(el).visibility !== 'hidden' && el.getBoundingClientRect().width > 0 && el.getBoundingClientRect().height > 0;
    return {
      mainVisible: visible(main),
      title: (title?.textContent || '').trim(),
      titleVisible: visible(title),
      rows: document.querySelectorAll('table tbody tr').length,
      text: (main?.innerText || '').trim().slice(0, 4000),
    };
  });
  await importPage.screenshot({ path: `artifacts/${label}-import.png`, fullPage: true });
  fs.writeFileSync(`artifacts/${label}-import.html`, await importPage.content(), 'utf8');

  const result = {
    role, viewport,
    status: nav ? nav.status() : null,
    finalUrl: page.url(),
    dom, modal,
    consoleErrors, pageErrors, requestFailures, badResponses,
    import: {
      status: importNav ? importNav.status() : null,
      finalUrl: importPage.url(),
      state: importState,
      consoleErrors: importConsoleErrors,
      pageErrors: importPageErrors,
      requestFailures: importRequestFailures,
      badResponses: importBadResponses,
    },
  };
  await importPage.close();
  await page.close();
  await context.close();
  return result;
}

(async () => {
  const browser = await chromium.launch({ headless: true });
  const results = [];
  try {
    for (const [role, sid] of Object.entries(sessions)) {
      for (const viewport of [{ width: 1366, height: 900 }, { width: 1920, height: 1080 }]) {
        results.push(await inspectPage(browser, role, sid, viewport));
      }
    }
  } finally {
    await browser.close();
  }
  fs.writeFileSync('artifacts/browser-results.json', JSON.stringify(results, null, 2));
  for (const r of results) {
    console.log(`WASTE_REAL_HTTP role=${r.role} viewport=${r.viewport.width} status=${r.status} title=${JSON.stringify(r.dom.titleText)} empty=${r.dom.emptyVisible} rows=${r.dom.rowCount} main=${r.dom.mainVisible} errors=${r.consoleErrors.length+r.pageErrors.length+r.requestFailures.length+r.badResponses.length}`);
    console.log(`WASTE_REAL_MODAL role=${r.role} viewport=${r.viewport.width} open=${r.modal.openCount} ids=${r.modal.openIds.join(',')} clipped=${r.modal.clipped} overflow=${r.modal.horizontalOverflow}`);
    console.log(`WASTE_REAL_IMPORT role=${r.role} viewport=${r.viewport.width} status=${r.import.status} title=${JSON.stringify(r.import.state.title)} main=${r.import.state.mainVisible}`);
  }
})().catch(err => { console.error(err); process.exit(1); });
