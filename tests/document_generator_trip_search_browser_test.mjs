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
  <select data-docgen-mode><option value="carrier">Перевозчик</option><option value="client_package" selected>Комплект</option></select>
  <div data-docgen-mode-section="carrier">
    <select data-docgen-template><option data-document-type="vehicle_lease_crew">Договор</option><option data-document-type="carrier_sublease_crew">Субаренда</option></select>
    <div class="field"><input type="search" list="carrier-trip-options" data-docgen-carrier-trip-search><input type="hidden" name="trip_id" data-docgen-carrier-trip><datalist id="carrier-trip-options" data-docgen-carrier-trip-options>
      <option value="#144 · Ромашка · Перевозчик · 05.09–06.09" data-trip-id="1" data-contract-date="2026-09-05" data-start-date="2026-09-05" data-finish-date="2026-09-12" data-price="100000.00"></option>
      <option value="#145 · Ромашка · Перевозчик · 10.10–12.10" data-trip-id="2" data-contract-date="2026-10-10" data-start-date="2026-10-10" data-finish-date="2026-10-17" data-price="180000.00"></option>
      <option value="#146 · Вектор · Перевозчик · 14.10–15.10" data-trip-id="3" data-contract-date="2026-10-14" data-start-date="2026-10-14" data-finish-date="2026-10-21" data-price="250000.00"></option>
    </datalist></div>
    <div data-docgen-section="vehicle_lease_crew">
      <div class="field"><input name="contract_date" type="date"></div><div class="field"><input name="start_date" type="date"></div>
      <div class="field"><input name="finish_date" type="date"></div><div class="field"><input name="price" type="text"></div>
    </div>
  </div>
  <div data-docgen-mode-section="client_package">
    <div class="field"><select name="client_id" data-docgen-client><option value="">Клиент</option><option value="1">Ромашка</option><option value="2">Вектор</option></select></div>
    <div class="field"><input type="search" list="trip-options" data-docgen-client-trip-search><input type="hidden" name="trip_id" data-docgen-client-trip><datalist id="trip-options" data-docgen-client-trip-options>
      <option value="#144 · Ромашка · 05.09–06.09" data-trip-id="1" data-client-id="1" data-contract-number="26-144" data-contract-date="2026-09-05" data-invoice-date="2026-09-06" data-act-date="2026-09-06"></option>
      <option value="#145 · Ромашка · 10.10–12.10" data-trip-id="2" data-client-id="1" data-contract-number="26-145" data-contract-date="2026-10-10" data-invoice-date="2026-10-12" data-act-date="2026-10-12"></option>
      <option value="#146 · Вектор · 14.10–15.10" data-trip-id="3" data-client-id="2" data-contract-number="26-146" data-contract-date="2026-10-14" data-invoice-date="2026-10-15" data-act-date="2026-10-15"></option>
    </datalist></div>
    <div class="field"><input name="client_contract_number" type="text"></div>
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
  assert.deepEqual(await page.locator('[data-docgen-mode-section="client_package"] input[type="date"]').evaluateAll(inputs => inputs.map(i => i.value)), ['2026-10-10','2026-10-12','2026-10-12']);
  const contractNumber = page.locator('input[name="client_contract_number"]');
  assert.equal(await contractNumber.inputValue(), '26-145');
  await contractNumber.fill('КЛ-2026/145');
  assert.equal(await page.locator('form').evaluate(form => new FormData(form).get('client_contract_number')), 'КЛ-2026/145');
  await search.fill('нет');
  assert.equal(await trip.inputValue(), '');
  assert.deepEqual(await page.locator('[data-docgen-mode-section="client_package"] input[type="date"]').evaluateAll(inputs => inputs.map(i => i.value)), ['', '', '']);
  await search.fill('#144 · Ромашка · 05.09–06.09');
  assert.equal(await trip.inputValue(), '1');
  assert.deepEqual(await page.locator('[data-docgen-mode-section="client_package"] input[type="date"]').evaluateAll(inputs => inputs.map(i => i.value)), ['2026-09-05','2026-09-06','2026-09-06']);
  await page.locator('[data-docgen-client]').selectOption('2');
  assert.deepEqual(await options.evaluateAll(items => items.map(o => o.dataset.tripId)), ['3']);
  assert.equal(await trip.inputValue(), '');
  assert.equal(await search.inputValue(), '');
  assert.deepEqual(await page.locator('[data-docgen-mode-section="client_package"] input[type="date"]').evaluateAll(inputs => inputs.map(i => i.value)), ['', '', '']);
  await search.fill('#144 · Ромашка · 05.09–06.09');
  assert.equal(await trip.inputValue(), '', 'trip from another client cannot be selected');
  await page.locator('[data-docgen-mode]').selectOption('carrier');
  const carrierTrip = page.locator('[data-docgen-carrier-trip]');
  const carrierSearch = page.locator('[data-docgen-carrier-trip-search]');
  assert.equal(await carrierSearch.isVisible(), true);
  assert.equal(await carrierTrip.isVisible(), false);
  assert.equal(await page.locator('form').evaluate(form => new FormData(form).getAll('trip_id').length), 1);
  await carrierSearch.fill('145');
  assert.equal(await carrierTrip.inputValue(), '');
  await carrierSearch.press('ArrowDown');
  await carrierSearch.press('Enter');
  assert.equal(await carrierTrip.inputValue(), '2');
  const carrierValues = () => page.locator('[data-docgen-section="vehicle_lease_crew"] input').evaluateAll(inputs => inputs.map(i => i.value));
  assert.deepEqual(await carrierValues(), ['2026-10-10','2026-10-10','2026-10-17','180000.00']);
  await page.locator('[name="price"]').fill('190000');
  assert.equal(await page.locator('[name="price"]').inputValue(), '190000', 'suggested amount remains editable');
  await page.locator('[data-docgen-template]').selectOption({label:'Субаренда'});
  assert.deepEqual(await carrierValues(), ['2026-10-10','2026-10-10','2026-10-17','190000'], 'sublease shares editable defaults');
  assert.equal(await page.locator('form').evaluate(form => new FormData(form).get('trip_id')), '2');
  await carrierSearch.fill('нет');
  assert.equal(await carrierTrip.inputValue(), '', 'new text clears the previous trip');
  assert.deepEqual(await carrierValues(), ['', '', '', ''], 'clearing trip clears suggestions');
  await carrierSearch.fill('#146 · Вектор · Перевозчик · 14.10–15.10');
  assert.equal(await carrierTrip.inputValue(), '3', 'exact suggestion resolves the carrier trip');
  assert.deepEqual(await carrierValues(), ['2026-10-14','2026-10-14','2026-10-21','250000.00']);
  await page.locator('[data-docgen-mode]').selectOption('client_package');
  assert.equal(await page.locator('form').evaluate(form => new FormData(form).getAll('trip_id').length), 1);
  assert.equal(await trip.inputValue(), '', 'carrier selection does not select a client trip');
  assert.deepEqual(errors, []);
  console.log('DOCUMENT_GENERATOR_TRIP_SEARCH_BROWSER=PASS');
} finally {
  await browser.close();
}
