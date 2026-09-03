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

    const candidatesPayload = await page.evaluate(async () => {
      const response = await fetch('/erpv2/company/feo/requests/data?offset=0&limit=80', { credentials: 'same-origin' });
      if (!response.ok) throw new Error('FEO data HTTP ' + response.status);
      return response.json();
    });
    const requestIds = Array.from(new Set((candidatesPayload.rows || []).map((row) => String(row.zayavka_id || '')).filter((id) => /^\d{6}$/.test(id))));
    assert(requestIds.length > 0, 'production FEO registry returned no six-digit candidate IDs');

    const templates = await page.locator('[data-docgen-template] option').evaluateAll((options) => options.map((option) => ({ value: option.value, type: option.dataset.documentType || '' })));
    const contractTemplate = templates.find((item) => item.type === 'vehicle_lease_crew');
    const waybillTemplate = templates.find((item) => item.type === 'waybill_driver_assignment');
    assert(contractTemplate && waybillTemplate, 'both canonical document types must be active for QA');
    const executorValues = await page.locator('select[name="crew_id"] option').evaluateAll((options) => options.map((o) => o.value).filter(Boolean));
    assert(executorValues.length > 0, 'no active route executors are available for QA');

    const result = page.locator('[data-docgen-check-result]');
    const checkButton = page.locator('[data-docgen-check]');
    const set = async (name, value) => {
      const el = page.locator(`[name="${name}"]`);
      const tag = await el.evaluate((node) => node.tagName.toLowerCase());
      if (tag === 'select') await el.selectOption(String(value)); else await el.fill(String(value));
    };
    const check = async () => {
      await checkButton.click();
      await page.waitForFunction(() => {
        const node = document.querySelector('[data-docgen-check-result]');
        return node && node.dataset.docgenState && node.dataset.docgenState !== 'checking';
      });
      return result.getAttribute('data-docgen-state');
    };

    await page.locator('[data-docgen-template]').selectOption(contractTemplate.value);
    await set('crew_id', '');
    await set('request_input', '');
    await set('contract_date', '');
    await set('start_date', '');
    await set('finish_date', '');
    await set('price', '');
    await set('vat_mode', '');
    assert(await check() === 'error', 'empty form must fail visibly');
    const emptyText = await result.innerText();
    for (const expected of ['Не выбран исполнитель рейса.', 'Не указаны заявки ФЭО.', 'Не указана дата начала.', 'Не указана стоимость.']) assert(emptyText.includes(expected), `empty form missing message: ${expected}`);
    for (const name of ['crew_id', 'request_input', 'start_date', 'price']) assert(await page.locator(`[name="${name}"]`).evaluate((node) => node.closest('.field')?.classList.contains('is-error') === true), `empty form field not highlighted: ${name}`);
    assert(!(await checkButton.isDisabled()), 'check button remained disabled after client validation');
    mark('EMPTY_FORM');

    await set('crew_id', executorValues[0]);
    await set('request_input', requestIds[0]);
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
    await set('request_input', '999999');
    await page.route('**/company/document-generator/preview', async (route) => { await new Promise((resolve) => setTimeout(resolve, 400)); await route.continue(); }, { times: 1 });
    const loadingClick = checkButton.click();
    await page.waitForTimeout(60);
    assert(await checkButton.isDisabled(), 'check button must be disabled while preview request is active');
    assert((await checkButton.innerText()).includes('Проверяем'), 'check button must show loading label');
    await loadingClick;
    await page.waitForFunction(() => document.querySelector('[data-docgen-check-result]')?.dataset.docgenState !== 'checking');
    assert(!(await checkButton.isDisabled()), 'check button must recover after request completion');
    mark('LOADING_STATE');

    await page.route('**/company/document-generator/preview', (route) => route.abort('failed'), { times: 1 });
    await set('request_input', requestIds[0]);
    await checkButton.click();
    await page.waitForFunction(() => document.querySelector('[data-docgen-check-result]')?.dataset.docgenState === 'error');
    assert((await result.innerText()).includes('Не удалось выполнить проверку'), 'network failure must show standard generic error');
    mark('HTTP_JS_FAILURE');

    let validPair = null;
    outer: for (const crew of executorValues.slice(0, 8)) {
      await set('crew_id', crew);
      for (const requestId of requestIds.slice(0, 40)) {
        await set('request_input', requestId);
        await set('contract_date', ymd(today));
        await set('start_date', ymd(today));
        await set('finish_date', ymd(tomorrow));
        await set('price', '1');
        await set('vat_mode', 'без НДС');
        if (await check() === 'success') { validPair = { crew, requestId }; break outer; }
      }
    }
    assert(validPair, 'no production executor/FEO combination passed canonical contract preview');
    assert(await result.locator('.notice.success').isVisible(), 'successful contract preview lacks standard success notice');
    assert(await result.locator('button[formaction*="/company/document-generator/generate"]').isVisible(), 'successful preview lacks DOCX generation button');
    mark('CORRECT_CONTRACT_PREVIEW');

    await page.locator('[data-docgen-template]').selectOption(waybillTemplate.value);
    await set('crew_id', validPair.crew);
    await set('request_input', validPair.requestId);
    const qaNumber = `QA-UX-${runId}`;
    await set('waybill_number', qaNumber);
    await set('waybill_date', ymd(today));
    assert(await check() === 'success', 'waybill preview did not pass before DOCX generation');
    const generate = result.locator('button[formaction*="/company/document-generator/generate"]');
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
    mark('DOCX_GENERATION', { document_id: Number(documentId), qa_waybill_number: qaNumber, bytes: bytes.length });

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
