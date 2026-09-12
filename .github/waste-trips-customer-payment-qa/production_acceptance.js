const { chromium } = require('playwright');
const fs = require('fs');

const BASE = 'https://plan-ex.ru/erpv2';
const cookieName = process.env.QA_COOKIE_NAME;
const sid = process.env.QA_OWNER_SESSION_ID;
const sourceSha = process.env.QA_LIVE_SHA || process.env.MIGRATION_SHA || '';
if (!cookieName || !sid) throw new Error('QA session env missing');

fs.mkdirSync('artifacts', { recursive: true });
const failures = [];
const evidence = { source_sha: sourceSha, screen: {}, registry: {}, create: {}, view: {}, edit: {}, runtime: {} };
const consoleErrors = [];
const pageErrors = [];
const serverErrors = [];
const ok = (v, m) => { if (!v) failures.push(m); };
const hasText = (s, x) => String(s || '').includes(x);
const saveEvidence = () => fs.writeFileSync('artifacts/waste-trips-customer-payment-production-qa.json', JSON.stringify(evidence, null, 2));

(async () => {
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({ ignoreHTTPSErrors: false, viewport: { width: 1440, height: 1000 } });
  await context.addCookies([{ name: cookieName, value: sid, domain: 'plan-ex.ru', path: '/', secure: true, httpOnly: true, sameSite: 'Lax' }]);
  const page = await context.newPage();
  page.on('console', m => { if (m.type() === 'error') consoleErrors.push(m.text()); });
  page.on('pageerror', e => pageErrors.push(String(e && e.stack || e)));
  page.on('response', r => { if (r.status() >= 500) serverErrors.push({ status: r.status(), url: r.url() }); });

  const response = await page.goto(BASE + '/company/trips/departures', { waitUntil: 'domcontentloaded', timeout: 60000 });
  await page.waitForTimeout(700);
  const bodyText = (await page.locator('body').innerText()).replace(/\s+/g, ' ').trim();
  const pageTitle = (await page.locator('.page-title').first().textContent().catch(() => '') || '').trim();
  const createTrigger = page.locator('.page-head-actions > button[onclick="openModal(\'waste-trip-create-modal\')"]');
  evidence.screen = {
    http_status: response ? response.status() : null,
    final_url: page.url(),
    page_title: pageTitle,
    create_trigger_count: await createTrigger.count(),
    body_excerpt: bodyText.slice(0, 1200),
  };
  ok(response && response.status() === 200, 'departures registry HTTP != 200');
  ok(!page.url().includes('/login'), 'departures registry redirected to login');
  ok(pageTitle === 'Перевозка отходов', `unexpected page title: ${pageTitle || '(empty)'}`);
  ok(evidence.screen.create_trigger_count === 1, `create trigger count=${evidence.screen.create_trigger_count}`);
  if (failures.length) {
    evidence.runtime = { console_errors: consoleErrors, page_errors: pageErrors, http_5xx: serverErrors };
    saveEvidence();
    await page.screenshot({ path: 'artifacts/waste-trips-screen-diagnostic.png', fullPage: true });
    throw new Error('screen precondition failed: ' + failures.join(' | ') + ' | body=' + bodyText.slice(0, 700));
  }

  const headers = await page.locator('.waste-registry thead th').allTextContents();
  const rows = page.locator('.waste-registry tbody tr[data-waste-view]');
  const rowCount = await rows.count();
  evidence.registry = { headers: headers.map(x => x.trim()), row_count: rowCount };
  ok(headers.map(x => x.trim()).includes('ЗАКАЗЧИК'), 'ЗАКАЗЧИК column missing');
  ok(headers.map(x => x.trim()).includes('СТАВКА ЗАКАЗЧИКА'), 'СТАВКА ЗАКАЗЧИКА column missing');
  ok(headers.length === 11, `registry expected 11 columns, got ${headers.length}`);
  ok(rowCount > 0, 'company 25 has no Waste Trip row for modal acceptance');
  ok(await page.locator('.waste-registry tbody td:nth-child(6)').count() > 0, 'customer cells missing');
  ok(await page.locator('.waste-registry tbody td:nth-child(7)').count() > 0, 'customer rate cells missing');

  await createTrigger.click({ timeout: 10000 });
  await page.locator('#waste-trip-create-modal').waitFor({ state: 'visible', timeout: 10000 });
  const create = page.locator('#waste-trip-create-modal');
  const paymentRows = create.locator('[data-payment-container="customer_payments"] [data-payment-row]');
  evidence.create = {
    title: (await create.locator('.modal-title').textContent() || '').trim(),
    client_present: await create.locator('select[name="client_id"]').count(),
    payment_block_present: await create.locator('.waste-customer-payment-block').count(),
    payment_title: (await create.locator('.waste-customer-payment-block .linear-trip-block-title').textContent() || '').trim(),
    initial_payment_rows: await paymentRows.count(),
    amount_fields: await create.locator('input[name^="customer_payments"][name$="[amount]"]').count(),
    method_fields: await create.locator('select[name^="customer_payments"][name$="[payment_method]"]').count(),
    vat_fields: await create.locator('select[name^="customer_payments"][name$="[vat_rate]"]').count(),
    condition_fields: await create.locator('select[name^="customer_payments"][name$="[condition_type]"]').count(),
  };
  ok(evidence.create.client_present === 1, 'create customer selector missing');
  ok(evidence.create.payment_block_present === 1, 'create customer payment block missing');
  ok(evidence.create.payment_title === 'Оплата заказчика', 'create payment title mismatch');
  ok(evidence.create.initial_payment_rows === 1, 'create must start with exactly one payment row');
  ok(evidence.create.amount_fields === 1 && evidence.create.method_fields === 1 && evidence.create.vat_fields === 1 && evidence.create.condition_fields === 1, 'create payment vocabulary incomplete');
  const due = create.locator('select[name^="customer_payments"][name$="[condition_type]"]').first();
  ok(await due.locator('option').count() > 1, 'payment due vocabulary is empty');
  await create.locator('[data-add-payment-row="customer_payments"]').click();
  await page.waitForTimeout(100);
  ok(await paymentRows.count() === 2, 'add payment row UI failed');
  await paymentRows.nth(1).locator('[data-remove-payment-row]').click();
  await page.waitForTimeout(100);
  ok(await paymentRows.count() === 1, 'remove payment row UI failed');
  await page.screenshot({ path: 'artifacts/waste-trips-create-payment.png', fullPage: true });
  await page.evaluate(() => window.closeModal && window.closeModal('waste-trip-create-modal'));

  let editableRow = null;
  let editableStatus = '';
  for (let i = 0; i < rowCount; i++) {
    const candidate = rows.nth(i);
    const statusText = ((await candidate.locator('td').nth(4).textContent()) || '').replace(/\s+/g, ' ').trim();
    if (statusText !== 'Отменён') {
      editableRow = candidate;
      editableStatus = statusText;
      break;
    }
  }
  ok(!!editableRow, 'no editable Waste Trip row available for edit-flow acceptance');
  const tripId = editableRow ? await editableRow.getAttribute('data-waste-view') : null;
  ok(!!tripId, 'editable trip internal id missing');
  evidence.registry.editable_trip_id = tripId;
  evidence.registry.editable_trip_status = editableStatus;
  if (tripId && editableRow) {
    await editableRow.click();
    await page.locator('#waste-trip-view-modal').waitFor({ state: 'visible', timeout: 10000 });
    await page.waitForTimeout(600);
    const viewBody = page.locator('#waste-trip-view-body');
    const viewText = (await viewBody.textContent() || '').replace(/\s+/g, ' ').trim();
    evidence.view = {
      trip_id: tripId,
      has_customer: hasText(viewText, 'Заказчик'),
      has_payment_section: hasText(viewText, 'Условия оплаты заказчика'),
      has_rate_state: hasText(viewText, 'Ставка заказчика не заполнена.') || hasText(viewText, 'Итого:'),
    };
    ok(evidence.view.has_customer, 'view popup customer row missing');
    ok(evidence.view.has_payment_section, 'view popup customer payment section missing');
    ok(evidence.view.has_rate_state, 'view popup customer rate state missing');
    await page.screenshot({ path: 'artifacts/waste-trips-view-payment.png', fullPage: true });

    const editButton = page.locator('#waste-trip-view-modal [data-waste-open-edit]').first();
    ok(await editButton.count() === 1, 'standard Редактировать action missing in view footer');
    ok((await editButton.textContent() || '').trim() === 'Редактировать', 'view edit action label must be Редактировать');
    ok(await editButton.evaluate(el => el.classList.contains('btn') && el.classList.contains('btn-primary')), 'view edit action must use btn btn-primary design-system classes');
    await editButton.click();
    await page.locator('#waste-trip-view-modal #waste-trip-edit-form').waitFor({ state: 'visible', timeout: 10000 });
    await page.waitForTimeout(300);
    const sharedModal = page.locator('#waste-trip-view-modal');
    const editForm = sharedModal.locator('#waste-trip-edit-form');
    const editTitle = (await sharedModal.locator('#waste-trip-view-title').textContent() || '').trim();
    evidence.edit = {
      same_modal_visible: await sharedModal.isVisible(),
      separate_edit_modal_count: await page.locator('#waste-trip-edit-modal').count(),
      title: editTitle,
      has_customer: await editForm.locator('select[name="client_id"]').count() === 1,
      has_payment_title: hasText(await editForm.textContent(), 'Оплата заказчика'),
      has_amount: await editForm.locator('input[name^="customer_payments"][name$="[amount]"]').count() > 0,
      has_method: await editForm.locator('select[name^="customer_payments"][name$="[payment_method]"]').count() > 0,
      has_vat: await editForm.locator('select[name^="customer_payments"][name$="[vat_rate]"]').count() > 0,
      has_condition: await editForm.locator('select[name^="customer_payments"][name$="[condition_type]"]').count() > 0,
      cancel_label: (await editForm.locator('[data-waste-cancel-edit]').textContent() || '').trim(),
      cancel_is_ghost: await editForm.locator('[data-waste-cancel-edit]').evaluate(el => el.classList.contains('btn') && el.classList.contains('btn-ghost')),
      save_label: (await editForm.locator('button[type="submit"]').textContent() || '').trim(),
      save_is_primary: await editForm.locator('button[type="submit"]').evaluate(el => el.classList.contains('btn') && el.classList.contains('btn-primary')),
    };
    ok(evidence.edit.same_modal_visible, 'edit mode left the standard Waste Trip modal');
    ok(evidence.edit.separate_edit_modal_count === 0, 'separate invented edit modal exists');
    ok(/^Редактирование рейса #/.test(evidence.edit.title), `unexpected edit title: ${evidence.edit.title}`);
    ok(evidence.edit.has_customer, 'edit customer selector missing');
    ok(evidence.edit.has_payment_title && evidence.edit.has_amount && evidence.edit.has_method && evidence.edit.has_vat && evidence.edit.has_condition, 'edit customer payment controls incomplete');
    ok(evidence.edit.cancel_label === 'Отмена' && evidence.edit.cancel_is_ghost, 'edit cancel must be standard btn btn-ghost Отмена');
    ok(evidence.edit.save_label === 'Сохранить' && evidence.edit.save_is_primary, 'edit save must be standard btn btn-primary Сохранить');
    await editForm.locator('[data-waste-cancel-edit]').click();
    await page.locator('#waste-trip-view-modal [data-waste-open-edit]').waitFor({ state: 'visible', timeout: 10000 });
    await page.waitForTimeout(250);
    evidence.edit.cancel_returned_to_view = /^Рейс #/.test((await page.locator('#waste-trip-view-title').textContent() || '').trim());
    ok(evidence.edit.cancel_returned_to_view, 'Отмена did not return to trip view in the same modal');
  }

  evidence.runtime = { console_errors: consoleErrors, page_errors: pageErrors, http_5xx: serverErrors };
  ok(pageErrors.length === 0, `page errors: ${pageErrors.join(' | ')}`);
  ok(serverErrors.length === 0, `HTTP 5xx: ${JSON.stringify(serverErrors)}`);
  saveEvidence();
  await browser.close();

  if (failures.length) {
    console.error('WASTE_TRIPS_CUSTOMER_PAYMENT_PROD_QA=FAIL');
    failures.forEach(x => console.error('FAIL:', x));
    process.exit(1);
  }
  console.log('WASTE_TRIPS_CUSTOMER_PAYMENT_PROD_QA=PASS');
  console.log('WASTE_TRIPS_PRODUCTION_READ_ONLY=YES');
  console.log(JSON.stringify(evidence));
})().catch(async err => {
  evidence.runtime = { console_errors: consoleErrors, page_errors: pageErrors, http_5xx: serverErrors };
  try { saveEvidence(); } catch (_) {}
  console.error(err && err.stack || err);
  process.exit(1);
});
