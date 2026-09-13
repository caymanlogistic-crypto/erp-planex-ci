const { chromium } = require('playwright');
const fs = require('fs');

const BASE = 'https://plan-ex.ru/erpv2';
const cookieName = process.env.QA_COOKIE_NAME;
const sid = process.env.QA_OWNER_SESSION_ID;
const sourceSha = process.env.QA_LIVE_SHA || '';
if (!cookieName || !sid) throw new Error('QA session env missing');

fs.mkdirSync('artifacts', { recursive: true });
const failures = [];
const consoleErrors = [];
const pageErrors = [];
const serverErrors = [];
const evidence = { source_sha: sourceSha, registry: {}, create: {}, view: {}, edit: {}, finance: {}, runtime: {} };
const ok = (v, m) => { if (!v) failures.push(m); };
const has = (s, x) => String(s || '').includes(x);
const save = () => fs.writeFileSync('artifacts/waste-trips-carrier-finance-production-qa.json', JSON.stringify(evidence, null, 2));

(async () => {
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
  await context.addCookies([{ name: cookieName, value: sid, domain: 'plan-ex.ru', path: '/', secure: true, httpOnly: true, sameSite: 'Lax' }]);
  const page = await context.newPage();
  page.on('console', m => { if (m.type() === 'error') consoleErrors.push(m.text()); });
  page.on('pageerror', e => pageErrors.push(String(e && e.stack || e)));
  page.on('response', r => { if (r.status() >= 500) serverErrors.push({ status: r.status(), url: r.url() }); });

  const response = await page.goto(BASE + '/company/trips/departures', { waitUntil: 'domcontentloaded', timeout: 60000 });
  await page.waitForTimeout(600);
  ok(response && response.status() === 200, 'departures HTTP != 200');
  ok(!page.url().includes('/login'), 'departures redirected to login');
  ok((await page.locator('.page-title').first().textContent().catch(() => '') || '').trim() === 'Перевозка отходов', 'unexpected departures title');

  const headers = (await page.locator('.waste-registry thead th').allTextContents()).map(x => x.trim());
  const rows = page.locator('.waste-registry tbody tr[data-waste-view]');
  const rowCount = await rows.count();
  evidence.registry = { headers, row_count: rowCount };
  ok(headers.length === 12, `registry expected 12 columns, got ${headers.length}`);
  for (const h of ['ЗАКАЗЧИК','СТАВКА ЗАКАЗЧИКА','ИСПОЛНИТЕЛЬ РЕЙСА','СТАВКА ПЕРЕВОЗЧИКА']) ok(headers.includes(h), `registry column missing: ${h}`);
  ok(rowCount > 0, 'no Waste Trip rows for acceptance');

  const createTrigger = page.locator('.page-head-actions > button[onclick="openModal(\'waste-trip-create-modal\')"]');
  ok(await createTrigger.count() === 1, 'create trigger missing');
  await createTrigger.click();
  const create = page.locator('#waste-trip-create-modal');
  await create.waitFor({ state: 'visible', timeout: 10000 });
  const createText = (await create.textContent() || '').replace(/\s+/g, ' ').trim();
  evidence.create = {
    customer_block: await create.locator('[data-payment-container="customer_payments"]').count(),
    carrier_block: await create.locator('[data-payment-container="carrier_payments"]').count(),
    customer_title: has(createText, 'Оплата заказчика'),
    carrier_title: has(createText, 'Оплата перевозчику'),
    carrier_amount: await create.locator('input[name^="carrier_payments"][name$="[amount]"]').count(),
    carrier_method: await create.locator('select[name^="carrier_payments"][name$="[payment_method]"]').count(),
    carrier_vat: await create.locator('select[name^="carrier_payments"][name$="[vat_rate]"]').count(),
    carrier_condition: await create.locator('select[name^="carrier_payments"][name$="[condition_type]"]').count(),
  };
  ok(evidence.create.customer_block === 1 && evidence.create.carrier_block === 1, 'create finance blocks incomplete');
  ok(evidence.create.customer_title && evidence.create.carrier_title, 'create finance titles incomplete');
  ok(evidence.create.carrier_amount > 0 && evidence.create.carrier_method > 0 && evidence.create.carrier_vat > 0 && evidence.create.carrier_condition > 0, 'carrier payment controls incomplete');
  await page.evaluate(() => window.closeModal && window.closeModal('waste-trip-create-modal'));

  let editable = null;
  for (let i = 0; i < rowCount; i++) {
    const candidate = rows.nth(i);
    const status = ((await candidate.locator('td').nth(4).textContent()) || '').replace(/\s+/g, ' ').trim();
    if (status !== 'Отменён') { editable = candidate; break; }
  }
  ok(!!editable, 'no editable Waste Trip row');
  if (editable) {
    const tripId = await editable.getAttribute('data-waste-view');
    evidence.registry.trip_id = tripId;
    await editable.click();
    await page.locator('#waste-trip-view-modal').waitFor({ state: 'visible', timeout: 10000 });
    await page.waitForTimeout(500);
    const viewText = ((await page.locator('#waste-trip-view-body').textContent()) || '').replace(/\s+/g, ' ').trim();
    evidence.view = {
      customer_terms: has(viewText, 'Условия оплаты заказчика'),
      carrier_terms: has(viewText, 'Условия оплаты перевозчику'),
      carrier_state: has(viewText, 'Ставка перевозчика не заполнена.') || has(viewText, 'Итого:'),
    };
    ok(evidence.view.customer_terms, 'customer payment section missing in view');
    ok(evidence.view.carrier_terms, 'carrier payment section missing in view');
    ok(evidence.view.carrier_state, 'carrier payment state missing in view');

    const editButton = page.locator('#waste-trip-view-modal [data-waste-open-edit]').first();
    ok(await editButton.count() === 1, 'standard edit action missing');
    if (await editButton.count()) {
      await editButton.click();
      const edit = page.locator('#waste-trip-view-modal #waste-trip-edit-form');
      await edit.waitFor({ state: 'visible', timeout: 10000 });
      const editText = ((await edit.textContent()) || '').replace(/\s+/g, ' ').trim();
      evidence.edit = {
        customer_terms: has(editText, 'Оплата заказчика'),
        carrier_terms: has(editText, 'Оплата перевозчику'),
        carrier_amount: await edit.locator('input[name^="carrier_payments"][name$="[amount]"]').count(),
        carrier_condition: await edit.locator('select[name^="carrier_payments"][name$="[condition_type]"]').count(),
        save: (await edit.locator('button[type="submit"]').textContent() || '').trim(),
      };
      ok(evidence.edit.customer_terms && evidence.edit.carrier_terms, 'edit finance blocks incomplete');
      ok(evidence.edit.carrier_amount > 0 && evidence.edit.carrier_condition > 0, 'edit carrier controls incomplete');
      ok(evidence.edit.save === 'Сохранить', 'standard save action missing');
      await edit.locator('[data-waste-cancel-edit]').click();
      await page.locator('#waste-trip-view-modal [data-waste-open-edit]').waitFor({ state: 'visible', timeout: 10000 });
    }
  }

  const financePages = [
    ['/company/finance/receivables','receivables','Дебиторская задолженность'],
    ['/company/finance/payables','payables','Кредиторская задолженность'],
    ['/company/finance/payment-calendar','calendar','Платёж'],
    ['/company/finance/reports/payment-plan-fact','plan_fact','План'],
    ['/company/finance/reports/management-balance','management_balance','Управлен'],
    ['/company/finance/dashboard','dashboard','Финанс'],
    ['/company/finance/invoices','invoices','Сч'],
  ];
  for (const [path,key,titleToken] of financePages) {
    const r = await page.goto(BASE + path, { waitUntil: 'domcontentloaded', timeout: 60000 });
    await page.waitForTimeout(250);
    const text = ((await page.locator('body').innerText()) || '').replace(/\s+/g, ' ').trim();
    evidence.finance[key] = { http_status: r ? r.status() : null, final_url: page.url(), title_token: has(text, titleToken), waste_label: has(text, 'Перевозка отходов #') };
    ok(r && r.status() === 200, `${key} HTTP != 200`);
    ok(!page.url().includes('/login'), `${key} redirected to login`);
  }
  ok(evidence.finance.receivables.waste_label, 'existing Waste customer rate is not visible in production receivables');

  evidence.runtime = { console_errors: consoleErrors, page_errors: pageErrors, http_5xx: serverErrors };
  ok(pageErrors.length === 0, `page errors: ${pageErrors.join(' | ')}`);
  ok(serverErrors.length === 0, `HTTP 5xx: ${JSON.stringify(serverErrors)}`);
  save();
  await browser.close();

  if (failures.length) {
    console.error('WASTE_TRIPS_CARRIER_FINANCE_PROD_QA=FAIL');
    failures.forEach(x => console.error('FAIL:', x));
    process.exit(1);
  }
  console.log('WASTE_TRIPS_CARRIER_FINANCE_PROD_QA=PASS');
  console.log('WASTE_TRIPS_PRODUCTION_READ_ONLY=YES');
  console.log(JSON.stringify(evidence));
})().catch(err => {
  evidence.runtime = { console_errors: consoleErrors, page_errors: pageErrors, http_5xx: serverErrors };
  try { save(); } catch (_) {}
  console.error(err && err.stack || err);
  process.exit(1);
});
