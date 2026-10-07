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
    <div class="field"><input type="search" data-docgen-client-trip-search><select name="trip_id" data-docgen-client-trip>
      <option value="">Выберите рейс</option>
      <option value="1" data-client-id="1" data-contract-date="2026-09-05" data-invoice-date="2026-09-06" data-act-date="2026-09-06">#144 · Ромашка · 05.09–06.09</option>
      <option value="2" data-client-id="1" data-contract-date="2026-10-10" data-invoice-date="2026-10-12" data-act-date="2026-10-12">#145 · Ромашка · 10.10–12.10</option>
      <option value="3" data-client-id="2" data-contract-date="2026-10-14" data-invoice-date="2026-10-15" data-act-date="2026-10-15">#146 · Вектор · 14.10–15.10</option>
    </select></div>
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
  await page.locator('[data-docgen-client]').selectOption('1');
  assert.deepEqual(await trip.locator('option').evaluateAll(options => options.map(o => o.value)), ['', '1', '2']);
  await search.fill('145');
  assert.deepEqual(await trip.locator('option').evaluateAll(options => options.map(o => o.value)), ['', '2']);
  await trip.selectOption('2');
  assert.deepEqual(await page.locator('input[type="date"]').evaluateAll(inputs => inputs.map(i => i.value)), ['2026-10-10','2026-10-12','2026-10-12']);
  await search.fill('нет');
  assert.equal(await trip.inputValue(), '');
  assert.equal(await trip.locator('option').first().textContent(), '— подходящих рейсов нет —');
  assert.deepEqual(await page.locator('input[type="date"]').evaluateAll(inputs => inputs.map(i => i.value)), ['', '', '']);
  await search.fill('144');
  await trip.selectOption('1');
  assert.deepEqual(await page.locator('input[type="date"]').evaluateAll(inputs => inputs.map(i => i.value)), ['2026-09-05','2026-09-06','2026-09-06']);
  await page.locator('[data-docgen-client]').selectOption('2');
  assert.deepEqual(await trip.locator('option').evaluateAll(options => options.map(o => o.value)), ['', '3']);
  assert.equal(await trip.inputValue(), '');
  assert.deepEqual(await page.locator('input[type="date"]').evaluateAll(inputs => inputs.map(i => i.value)), ['', '', '']);
  assert.deepEqual(errors, []);
  console.log('DOCUMENT_GENERATOR_TRIP_SEARCH_BROWSER=PASS');
} finally {
  await browser.close();
}
