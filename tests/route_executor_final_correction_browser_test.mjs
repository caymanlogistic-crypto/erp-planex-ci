import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const require = createRequire(import.meta.url);
const { chromium } = require('playwright');
const root = resolve(import.meta.dirname, '..');
const script = readFileSync(resolve(root, 'public/assets/js/route-executor-review-workflow.js'), 'utf8');
const browser = await chromium.launch({ headless: true });
try {
  const page = await browser.newPage();
  const errors = [];
  const actions = [];
  const cardId = '33333333-3333-4333-8333-333333333333';
  let stage = 'final';
  let revision = 1;
  page.on('pageerror', error => errors.push(error.message));
  await page.route('http://127.0.0.1:59998/**', async route => {
    const url = new URL(route.request().url());
    if (url.pathname.endsWith('/review/status')) return route.fulfill({ json: { ok: true, stage, correction_allowed: true, contract_status: 'accepted', card: {
      revision, updated_at: '2026-10-12T10:00:00Z', files: [], snapshot: { controls: [
        { formId: 'lab-contractor-form', name: 'name', index: 0, value: 'ИП Тестовый', type: 'text' },
        { formId: 'lab-contractor-form', name: 'bank_corr_account', index: 0, value: '30101810300000000601 ', type: 'text' },
        { formId: 'lab-driver-form', name: 'full_name', index: 0, value: 'Тестовый Водитель Тестович', type: 'text' },
        { formId: 'lab-driver-form', name: 'phone', index: 0, value: '', type: 'text' },
        { formId: 'lab-vehicle-form', name: 'units[primary][plate_number]', index: 0, value: 'А123АА32', type: 'text' }
      ], logist: '12', trip_id: '123', trip_label: '#123 Рейс', selected_entities: {}, removed_documents: [] }
    } } });
    if (url.pathname.endsWith('/review/send')) {
      const body = route.request().postData() || '';
      const action = body.match(/name="action"\r?\n\r?\n([a-z_]+)/)?.[1];
      actions.push({ action, body });
      if (action === 'open_final_correction') { stage = 'final_edit'; return route.fulfill({ json: { ok: true, stage } }); }
      if (action === 'save') { revision++; return route.fulfill({ json: { ok: true, revision, contract_status: 'accepted' } }); }
      if (action === 'finish_final_correction') { stage = 'final'; return route.fulfill({ json: { ok: true, stage, crew_id: 42, verified: true } }); }
      throw new Error('Unexpected action: ' + action);
    }
    if (url.pathname.endsWith('/review/config')) return route.fulfill({ json: { ok: true, trips: [] } });
    return route.fulfill({ contentType: 'text/html', body: '<table data-route-executors-grid><tbody></tbody></table>' });
  });
  await page.goto('http://127.0.0.1:59998/');
  await page.evaluate(id => localStorage.setItem('planex-route-executor-active-test-correction', id), cardId);
  await page.setContent(`<meta name="csrf-token" content="test-token"><table data-route-executors-grid><tbody></tbody></table>
    <div id="route-executor-create-modal" class="is-open" data-workflow-scope="test-correction">
      <div class="modal-head"><span class="modal-title">Карточка</span><div data-route-readiness></div></div>
      <div id="route-executor-create-modal-content"><div data-combined-feedback class="is-hidden"></div>
        <select id="route-executor-responsible-logist"><option value="12">Логист</option></select>
        <input id="route-executor-trip-search"><datalist id="route-executor-trip-options"></datalist><input id="route-executor-trip-id">
        <form id="lab-contractor-form"><input name="name"><input name="bank_corr_account"></form>
        <form id="lab-driver-form"><input name="full_name"><input name="phone"></form>
        <form id="lab-vehicle-form"><input name="units[primary][plate_number]"></form>
      </div><div class="modal-required-note"></div><div class="modal-foot-actions"><button data-source-create-submit>Сохранить</button></div>
    </div>`);
  await page.addScriptTag({ content: script });
  const correction = page.locator('[data-lab-final-correction]');
  await correction.waitFor({ state: 'visible' });
  assert.equal(await correction.textContent(), 'Открыть исправление');
  assert.equal(await page.locator('[data-source-create-submit]').isDisabled(), true);
  await correction.click();
  await page.waitForFunction(() => document.querySelector('[data-lab-final-correction]')?.textContent === 'Сохранить и завершить исправление');
  assert.equal(await correction.textContent(), 'Сохранить и завершить исправление');
  assert.equal(await page.locator('#lab-contractor-form [name="name"]').isDisabled(), true);
  assert.equal(await page.locator('#lab-vehicle-form [name="units[primary][plate_number]"]').isDisabled(), true);
  assert.equal(await page.locator('#lab-contractor-form [name="bank_corr_account"]').isEnabled(), true);
  assert.equal(await page.locator('#lab-driver-form [name="phone"]').isEnabled(), true);
  assert.equal(await page.locator('[data-lab-send]').isVisible(), false, 'Correction never offers an email send');
  await page.locator('#lab-contractor-form [name="bank_corr_account"]').fill('30101810300000000601');
  await page.locator('#lab-driver-form [name="phone"]').fill('+7 920 855-66-89');
  await correction.click();
  await page.waitForTimeout(100);
  assert.deepEqual(actions.map(item => item.action), ['open_final_correction', 'save', 'finish_final_correction']);
  assert.doesNotMatch(actions[1].body, /name="attachments\[\]"/, 'Correction does not reupload existing documents');
  assert.deepEqual(errors, [], 'No browser script errors');
  console.log('route_executor_final_correction_browser_test: PASS');
} finally {
  await browser.close();
}
