const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const base = 'https://plan-ex.ru';
const app = '/erpv2';
const cookieName = process.env.QA_COOKIE_NAME || '';
const sid = process.env.QA_OWNER_SESSION_ID || '';
const runId = process.env.GITHUB_RUN_ID || String(Date.now());
if (!cookieName || !sid) throw new Error('temporary QA session is missing');

const today = new Date();
const ymd = (d) => d.toISOString().slice(0, 10);
const tomorrow = new Date(today.getTime() + 86400000);
const evidence = { source_sha: process.env.SOURCE_SHA || '', run_id: runId, steps: {} };
const mark = (name, extra = {}) => {
  evidence.steps[name] = { pass: true, ...extra };
  console.log(`${name}=PASS`);
};
const assert = (condition, message) => { if (!condition) throw new Error(message); };

(async () => {
  fs.mkdirSync('artifacts', { recursive: true });
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({ acceptDownloads: true });
  await context.addCookies([{ name: cookieName, value: sid, domain: 'plan-ex.ru', path: '/', secure: true, httpOnly: true }]);
  const page = await context.newPage();
  page.setDefaultTimeout(15000);

  try {
    await page.goto(base + app + '/company/document-generator', { waitUntil: 'domcontentloaded' });
    assert(page.url().includes('/company/document-generator'), 'document generator did not open with QA session');
    const form = page.locator('[data-docgen-form]');
    await form.waitFor({ state: 'visible' });

    const mode = page.locator('[data-docgen-mode]');
    await mode.selectOption('carrier');
    const templates = await page.locator('[data-docgen-template] option').evaluateAll((options) => options.map((option) => ({ value: option.value, type: option.dataset.documentType || '' })));
    const contractTemplate = templates.find((item) => item.type === 'vehicle_lease_crew');
    const waybillTemplate = templates.find((item) => item.type === 'waybill_driver_assignment');
    assert(contractTemplate && waybillTemplate, 'both canonical carrier document types must be active for QA');
    const tripValues = await page.locator('[data-docgen-carrier-trip] option').evaluateAll((options) => options.map((o) => o.value).filter(Boolean));
    assert(tripValues.length > 0, 'no Waste Trips are available for document-generator QA');

    const result = page.locator('[data-docgen-check-result]');
    const checkButton = page.locator('[data-docgen-check]');
    const waitForResultState = async (predicate, description, timeoutMs = 15000) => {
      const deadline = Date.now() + timeoutMs;
      while (Date.now() < deadline) {
        const state = await result.getAttribute('data-docgen-state');
        if (predicate(state)) return state;
        await page.waitForTimeout(50);
      }
      throw new Error(`Timed out waiting for ${description}`);
    };
    const enabledNamed = (name) => page.locator(`[name="${name}"]:not([disabled])`).first();
    const set = async (name, value) => {
      const el = enabledNamed(name);
      const tag = await el.evaluate((node) => node.tagName.toLowerCase());
      if (tag === 'select') await el.selectOption(String(value)); else await el.fill(String(value));
    };
    const check = async () => {
      await checkButton.click();
      await waitForResultState((state) => Boolean(state) && state !== 'checking', 'preview completion');
      return result.getAttribute('data-docgen-state');
    };

    await page.locator('[data-docgen-template]').selectOption(contractTemplate.value);
    await set('trip_id', '');
    await set('contract_date', '');
    await set('start_date', '');
    await set('finish_date', '');
    await set('price', '');
    await set('vat_mode', '');
    assert(await check() === 'error', 'empty carrier form must fail visibly');
    const emptyText = await result.innerText();
    for (const expected of ['Не выбран рейс.', 'Не указана дата начала.', 'Не указана стоимость.']) assert(emptyText.includes(expected), `empty form missing message: ${expected}`);
    for (const name of ['trip_id', 'start_date', 'price']) assert(await enabledNamed(name).evaluate((node) => node.closest('.field')?.classList.contains('is-error') === true), `empty form field not highlighted: ${name}`);
    assert(!(await checkButton.isDisabled()), 'check button remained disabled after client validation');
    mark('EMPTY_FORM');

    await set('trip_id', tripValues[0]);
    await set('contract_date', ymd(today));
    await set('start_date', '');
    await set('finish_date', '');
    await set('price', '');
    await set('vat_mode', 'без НДС');
    assert(await check() === 'error', 'partial form must fail visibly');
    const partialText = await result.innerText();
    assert(partialText.includes('Не указана дата начала.'), 'partial form did not explain start date');
    assert(partialText.includes('Не указана стоимость.'), 'partial form did not explain price');
    mark('PARTIAL_FORM');

    await set('start_date', ymd(today));
    await set('finish_date', ymd(tomorrow));
    await set('price', '1');
    await page.route('**/company/document-generator/preview', async (route) => { await new Promise((resolve) => setTimeout(resolve, 400)); await route.continue(); }, { times: 1 });
    const loadingClick = checkButton.click();
    await page.waitForTimeout(60);
    assert(await checkButton.isDisabled(), 'check button must be disabled while preview request is active');
    assert((await checkButton.innerText()).includes('Проверяем'), 'check button must show loading label');
    assert((await result.getAttribute('data-docgen-state')) === 'checking', 'result must expose checking state during request');
    await loadingClick;
    await waitForResultState((state) => Boolean(state) && state !== 'checking', 'loading-state completion');
    assert(!(await checkButton.isDisabled()), 'check button must recover after request completion');
    mark('LOADING_STATE');

    await page.route('**/company/document-generator/preview', (route) => route.abort('failed'), { times: 1 });
    await checkButton.click();
    await waitForResultState((state) => state === 'error', 'network failure feedback');
    assert((await result.innerText()).includes('Не удалось выполнить проверку'), 'network failure must show standard generic error');
    mark('HTTP_JS_FAILURE');

    let validTrip = null;
    for (const tripId of tripValues.slice(0, 80)) {
      await set('trip_id', tripId);
      await set('contract_date', ymd(today));
      await set('start_date', ymd(today));
      await set('finish_date', ymd(tomorrow));
      await set('price', '1');
      await set('vat_mode', 'без НДС');
      if (await check() === 'success') { validTrip = tripId; break; }
    }
    assert(validTrip, 'no production Waste Trip passed canonical carrier contract preview');
    assert(await result.locator('.notice.success').isVisible(), 'successful contract preview lacks standard success notice');
    assert(await result.locator('[data-docgen-generate]').isVisible(), 'successful preview lacks DOCX generation button');
    mark('CORRECT_CONTRACT_PREVIEW', { trip_id: Number(validTrip) });

    const packageOption = page.locator('[data-docgen-mode] option[value="client_package"]');
    if (await packageOption.count()) {
      await mode.selectOption('client_package');
      const packageTripOptions = await page.locator('[data-docgen-client-trip] option[data-client-id]').evaluateAll((options) => options.map((o) => ({ value: o.value, clientId: o.dataset.clientId || '' })).filter((x) => x.value && x.clientId));
      let packagePreview = null;
      for (const item of packageTripOptions.slice(0, 80)) {
        await set('client_id', item.clientId);
        await set('trip_id', item.value);
        await set('package_date', ymd(today));
        if (await check() === 'success') { packagePreview = item; break; }
      }
      if (packagePreview) mark('CLIENT_PACKAGE_PREVIEW', { trip_id: Number(packagePreview.value), client_id: Number(packagePreview.clientId) });
      else console.log('CLIENT_PACKAGE_PREVIEW=SKIP no eligible current production trip');
      await mode.selectOption('carrier');
    }

    await page.locator('[data-docgen-template]').selectOption(waybillTemplate.value);
    await set('trip_id', validTrip);
    const qaNumber = `QA-UX-${runId}`;
    await set('waybill_number', qaNumber);
    await set('waybill_date', ymd(today));
    assert(await check() === 'success', 'waybill_driver_assignment preview did not pass before DOCX generation');
    const generate = result.locator('[data-docgen-generate]');
    await Promise.all([
      page.waitForURL(/\/company\/document-generator\/history\?created=\d+/, { waitUntil: 'domcontentloaded' }),
      generate.click()
    ]);
    assert((await page.locator('body').innerText()).includes('Документ сформирован и сохранён в истории.'), 'history lacks successful generation flash');
    const createdMatch = page.url().match(/[?&]created=(\d+)/);
    assert(createdMatch, 'generated document id missing from redirect URL');
    const documentId = createdMatch[1];
    const downloadHref = await page.locator(`a[href*="/company/document-generator/generated/${documentId}/download"]`).first().getAttribute('href');
    assert(downloadHref, 'generated DOCX download link not found');
    const downloadResponse = await context.request.get(new URL(downloadHref, base).href);
    assert(downloadResponse.status() === 200, `generated DOCX download HTTP ${downloadResponse.status()}`);
    const bytes = await downloadResponse.body();
    assert(bytes.length > 1000 && bytes[0] === 0x50 && bytes[1] === 0x4b, 'generated DOCX is not a valid non-empty ZIP/DOCX payload');
    mark('DOCX_GENERATION', { document_id: Number(documentId), qa_waybill_number: qaNumber, trip_id: Number(validTrip), bytes: bytes.length });

    fs.writeFileSync(path.join('artifacts', 'document-generator-production-qa.json'), JSON.stringify(evidence, null, 2));
  } finally {
    await browser.close();
  }
})().catch((error) => {
  evidence.error = String(error && error.message ? error.message : error);
  fs.mkdirSync('artifacts', { recursive: true });
  fs.writeFileSync(path.join('artifacts', 'document-generator-production-qa.json'), JSON.stringify(evidence, null, 2));
  console.error(error);
  process.exit(1);
});
