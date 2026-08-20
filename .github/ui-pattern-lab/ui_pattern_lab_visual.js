const { chromium } = require('playwright');
const fs = require('fs');

const baseURL = process.env.UI_LAB_BASE_URL || 'http://127.0.0.1:8016';
const sessionCookie = process.env.UI_LAB_SESSION_COOKIE || 'ERPUI';
const outDir = 'artifacts';
fs.mkdirSync(outDir, { recursive: true });

const requiredIds = [
  'UI-PAGE-HEADER-01',
  'UI-TABLE-01',
  'UI-SEARCH-01',
  'UI-FILTER-BAR-01',
  'UI-FILTER-PANEL-01',
  'UI-BUTTON-01',
  'UI-FIELD-01',
];
const linearVariants = ['v1', 'v2', 'v3', 'v4'];

async function addSession(context, value) {
  await context.addCookies([{ name: sessionCookie, value, url: baseURL, httpOnly: true, sameSite: 'Lax' }]);
}

async function assertNoUnexpectedOverflow(page, label) {
  const metrics = await page.evaluate(() => ({
    width: window.innerWidth,
    scrollWidth: document.documentElement.scrollWidth,
    contentScrollWidth: document.querySelector('.content')?.scrollWidth || 0,
    contentClientWidth: document.querySelector('.content')?.clientWidth || 0,
  }));
  if (metrics.scrollWidth > metrics.width + 2) {
    throw new Error(`${label}: document horizontal overflow ${metrics.scrollWidth} > ${metrics.width}`);
  }
  return metrics;
}

(async () => {
  const browser = await chromium.launch({ headless: true });
  const evidence = { route: '/ui-library', synthetic: true, access: {}, viewports: {}, linearTableDesignTest: {}, consoleErrors: [] };
  try {
    const anon = await browser.newContext({ viewport: { width: 1280, height: 800 }, locale: 'ru-RU', timezoneId: 'Europe/Moscow' });
    const anonPage = await anon.newPage();
    await anonPage.goto(`${baseURL}/ui-library`, { waitUntil: 'domcontentloaded' });
    const anonPath = new URL(anonPage.url()).pathname;
    if (anonPath !== '/login') throw new Error(`anonymous access did not redirect to /login: ${anonPath}`);
    await anonPage.goto(`${baseURL}/ui-library/linear-table-design-test/v1`, { waitUntil: 'domcontentloaded' });
    const anonLinearPath = new URL(anonPage.url()).pathname;
    if (anonLinearPath !== '/login') throw new Error(`anonymous linear test access did not redirect to /login: ${anonLinearPath}`);
    evidence.access.anonymous = 'redirect-login';
    evidence.access.linearAnonymous = 'redirect-login';
    await anon.close();

    const denied = await browser.newContext({ viewport: { width: 1280, height: 800 }, locale: 'ru-RU', timezoneId: 'Europe/Moscow' });
    await addSession(denied, 'uilogist');
    const deniedPage = await denied.newPage();
    const deniedResponse = await deniedPage.goto(`${baseURL}/ui-library`, { waitUntil: 'domcontentloaded' });
    if (!deniedResponse || deniedResponse.status() !== 403) throw new Error(`unauthorized role expected 403, got ${deniedResponse?.status()}`);
    const deniedLinearResponse = await deniedPage.goto(`${baseURL}/ui-library/linear-table-design-test/v1`, { waitUntil: 'domcontentloaded' });
    if (!deniedLinearResponse || deniedLinearResponse.status() !== 403) throw new Error(`unauthorized linear test expected 403, got ${deniedLinearResponse?.status()}`);
    evidence.access.unauthorized = 403;
    evidence.access.linearUnauthorized = 403;
    await denied.close();

    const context = await browser.newContext({ viewport: { width: 1920, height: 1080 }, deviceScaleFactor: 1, locale: 'ru-RU', timezoneId: 'Europe/Moscow' });
    await addSession(context, 'uiadmin');
    const page = await context.newPage();
    page.on('pageerror', (error) => evidence.consoleErrors.push(`pageerror: ${error.message}`));
    page.on('console', (msg) => { if (msg.type() === 'error') evidence.consoleErrors.push(`console: ${msg.text()}`); });

    const response = await page.goto(`${baseURL}/ui-library`, { waitUntil: 'networkidle' });
    if (!response || response.status() !== 200) throw new Error(`superadmin route expected 200, got ${response?.status()}`);
    evidence.access.superadmin = 200;

    await page.evaluate(() => document.fonts?.ready);
    const bodyText = await page.locator('body').innerText();
    for (const id of requiredIds) {
      if (!bodyText.includes(id)) throw new Error(`missing visible pattern id: ${id}`);
    }
    if (!bodyText.includes('ООО «Пример»')) throw new Error('synthetic dataset marker missing');
    if (/\bAPPROVED\b|\bCANONICAL\b|\bLOCKED\b/.test(bodyText)) throw new Error('owner-only approval status leaked into V0');

    for (const anchor of ['foundation', 'page-header', 'table', 'search', 'filters', 'buttons', 'fields']) {
      if (await page.locator(`#${anchor}`).count() !== 1) throw new Error(`missing navigable section #${anchor}`);
      if (await page.locator(`.ui-lab-nav a[href="#${anchor}"]`).count() !== 1) throw new Error(`missing nav anchor #${anchor}`);
    }

    evidence.viewports['1920x1080'] = await assertNoUnexpectedOverflow(page, '1920x1080');
    await page.locator('#foundation').scrollIntoViewIfNeeded();
    await page.screenshot({ path: `${outDir}/ui-pattern-lab-v0-1920.png` });

    await page.locator('#table').scrollIntoViewIfNeeded();
    await page.locator('[data-ui-lab-hover-row]').hover();
    await page.screenshot({ path: `${outDir}/ui-pattern-lab-v0-table-1920.png` });

    await page.locator('#buttons').scrollIntoViewIfNeeded();
    await page.locator('#ui-lab-focus-button').focus();
    await page.locator('#ui-lab-hover-button').hover();
    await page.screenshot({ path: `${outDir}/ui-pattern-lab-v0-buttons-1920.png` });

    await page.locator('#fields').scrollIntoViewIfNeeded();
    await page.screenshot({ path: `${outDir}/ui-pattern-lab-v0-fields-1920.png` });

    const linearIndex = await page.goto(`${baseURL}/ui-library/linear-table-design-test`, { waitUntil: 'networkidle' });
    if (!linearIndex || linearIndex.status() !== 200) throw new Error(`linear table design index expected 200, got ${linearIndex?.status()}`);
    const linearIndexText = await page.locator('body').innerText();
    for (const marker of ['V1 — Baseline', 'V2 — Information hierarchy', 'V3 — Maximum operational density', 'V4 — Structured / semantic']) {
      if (!linearIndexText.includes(marker)) throw new Error(`linear table index missing ${marker}`);
    }
    evidence.linearTableDesignTest.index = 200;

    for (const variant of linearVariants) {
      const variantResponse = await page.goto(`${baseURL}/ui-library/linear-table-design-test/${variant}`, { waitUntil: 'domcontentloaded' });
      if (!variantResponse || variantResponse.status() !== 200) throw new Error(`${variant} expected 200, got ${variantResponse?.status()}`);
      const variantText = await page.locator('body').innerText();
      for (const marker of ['#9', '#2', '#1', 'ООО «ПОТОК»', '775 000 ₽', '760 000 ₽']) {
        if (!variantText.includes(marker)) throw new Error(`${variant} missing real-data marker: ${marker}`);
      }
      const metrics = await assertNoUnexpectedOverflow(page, `linear-${variant}-1920x1080`);
      evidence.linearTableDesignTest[variant] = { status: 200, metrics };
    }
    await context.close();

    const compact = await browser.newContext({ viewport: { width: 1440, height: 900 }, deviceScaleFactor: 1, locale: 'ru-RU', timezoneId: 'Europe/Moscow' });
    await addSession(compact, 'uiadmin');
    const compactPage = await compact.newPage();
    const compactResponse = await compactPage.goto(`${baseURL}/ui-library`, { waitUntil: 'networkidle' });
    if (!compactResponse || compactResponse.status() !== 200) throw new Error(`1440 superadmin route expected 200, got ${compactResponse?.status()}`);
    evidence.viewports['1440x900'] = await assertNoUnexpectedOverflow(compactPage, '1440x900');
    await compactPage.locator('#foundation').scrollIntoViewIfNeeded();
    await compactPage.screenshot({ path: `${outDir}/ui-pattern-lab-v0-1440.png` });

    for (const variant of linearVariants) {
      const compactVariant = await compactPage.goto(`${baseURL}/ui-library/linear-table-design-test/${variant}`, { waitUntil: 'domcontentloaded' });
      if (!compactVariant || compactVariant.status() !== 200) throw new Error(`${variant} 1440 expected 200, got ${compactVariant?.status()}`);
      evidence.linearTableDesignTest[`${variant}-1440`] = await assertNoUnexpectedOverflow(compactPage, `linear-${variant}-1440x900`);
    }
    await compact.close();

    if (evidence.consoleErrors.length) throw new Error(evidence.consoleErrors.join('\n'));
    fs.writeFileSync(`${outDir}/UI_PATTERN_LAB_VISUAL.json`, JSON.stringify(evidence, null, 2));
    console.log('UI_PATTERN_LAB_VISUAL=PASS');
  } finally {
    await browser.close();
  }
})().catch((error) => {
  console.error(error.stack || error.message || error);
  process.exit(1);
});
