import assert from 'node:assert/strict';
import {createRequire} from 'node:module';
import {readFileSync} from 'node:fs';
import {dirname, join} from 'node:path';
import {fileURLToPath} from 'node:url';

const require = createRequire(import.meta.url);
const {chromium} = require(process.env.PLANEX_PLAYWRIGHT_MODULE || 'playwright');
const root = dirname(dirname(fileURLToPath(import.meta.url)));
const view = readFileSync(join(root, 'app/View/pages/company_document_generator.php'), 'utf8');
const script = view.match(/<script>\s*(\(function\(\)\{[\s\S]*?\}\)\(\);)\s*<\/script>/)?.[1]?.replace(/<\?php[\s\S]*?\?>/g, '') || '';
assert(script.includes('filterTrips'), 'document generator script must be present');

const html = `
<form data-docgen-form>
  <select data-docgen-mode><option value="client_package" selected>Комплект</option></select>
  <div data-docgen-mode-section="client_package">
    <div class="field"><select name="client_id" data-docgen-client><option value="">Клиент</option><option value="1">Ромашка</option><option value="2">Вектор</option></select></div>
    <div class="field"><input type="search" list="trip-options" data-docgen-client-trip-search><input type="hidden" name="trip_id" data-docgen-client-trip><datalist id="trip-options" data-docgen-client-trip-options>
      <option value="#144 · Ромашка · 05.09–06.09" data-trip-id="1" data-client-id="1" data-contract-date="2026-09-05" data-invoice-date="2026-09-06" data-act-date="2026-09-06"></option>
      <option value="#145 · Ромашка · 10.10–12.10" data-trip-id="2" data-client-id="1" data-contract-date="2026-10-10" data-invoice-date="2026-10-12" data-act-date="2026-10-12"></option>
      <option value="#146 · Вектор · 14.10–15.10" data-trip-id="3" data-client-id="2" data-contract-date="2026-10-14" data-invoice-date="2026-10-15" data-act-date="2026-10-15"></option>
    </datalist></div>
    <div class="field"><input name="client_contract_date" type="date"></div>
    <div class="field"><input name="client_invoice_date" type="date"></div>
    <div class="field"><input name="client_act_date" type="date"></div>
  </div>
  <button data-docgen-check type="submit">Проверить</button>
</form><div data-docgen-check-result></div><script>${script}</script>`;

const browser = await chromium.launch({headless:true, ...(process.env.PLANEX_CHROMIUM_PATH ? {executablePath:process.env.PLANEX_CHROMIUM_PATH} : {})});
try {
  const page = await browser.newPage();
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  await page.setContent(html);
  const trip = page.locator('[data-docgen-client-trip]');
  const search = page.locator('[data-docgen-client-trip-search]');
  const options = page.locator('[data-docgen-client-trip-options] option');
  assert.equal(await search.isVisible(), true);
  assert.equal(await trip.isVisible(), false);
  assert.equal(await page.locator('select[name="trip_id"]').count(), 0);
  await page.locator('[data-docgen-client]').selectOption('1');
  assert.deepEqual(await options.evaluateAll(items => items.map(o => o.dataset.tripId)), ['1', '2']);
  await search.fill('145');
  assert.equal(await trip.inputValue(), '');
  await search.press('ArrowDown');
  await search.press('Enter');
  assert.equal(await trip.inputValue(), '2', 'keyboard selection from native suggestions resolves trip ID');
  await search.fill('Ромашка');
  await search.press('ArrowDown');
  assert.equal(await trip.inputValue(), '1');
  await search.press('ArrowDown');
  assert.equal(await trip.inputValue(), '2', 'arrow keys cycle matching trips in the same field');
  await search.fill('145');
  await search.fill('#145 · Ромашка · 10.10–12.10');
  assert.equal(await trip.inputValue(), '2');
  assert.equal(await page.locator('form').evaluate(form => new FormData(form).get('trip_id')), '2');
  assert.deepEqual(await page.locator('input[type="date"]').evaluateAll(inputs => inputs.map(i => i.value)), ['2026-10-10','2026-10-12','2026-10-12']);
  await search.fill('нет');
  assert.equal(await trip.inputValue(), '');
  assert.deepEqual(await page.locator('input[type="date"]').evaluateAll(inputs => inputs.map(i => i.value)), ['', '', '']);
  await search.fill('#144 · Ромашка · 05.09–06.09');
  assert.equal(await trip.inputValue(), '1');
  assert.deepEqual(await page.locator('input[type="date"]').evaluateAll(inputs => inputs.map(i => i.value)), ['2026-09-05','2026-09-06','2026-09-06']);
  await page.locator('[data-docgen-client]').selectOption('2');
  assert.deepEqual(await options.evaluateAll(items => items.map(o => o.dataset.tripId)), ['3']);
  assert.equal(await trip.inputValue(), '');
  assert.equal(await search.inputValue(), '');
  assert.deepEqual(await page.locator('input[type="date"]').evaluateAll(inputs => inputs.map(i => i.value)), ['', '', '']);
  await search.fill('#144 · Ромашка · 05.09–06.09');
  assert.equal(await trip.inputValue(), '', 'trip from another client cannot be selected');
  assert.deepEqual(errors, []);
  console.log('DOCUMENT_GENERATOR_TRIP_SEARCH_BROWSER=PASS');
} finally {
  await browser.close();
}
