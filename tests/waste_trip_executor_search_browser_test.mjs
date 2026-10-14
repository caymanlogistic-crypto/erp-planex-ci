import assert from 'node:assert/strict';
import path from 'node:path';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';

const require = createRequire(import.meta.url);
const { chromium } = require('playwright');
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const browser = await chromium.launch({ headless: true, ...(process.env.PLANEX_CHROMIUM_PATH ? { executablePath: process.env.PLANEX_CHROMIUM_PATH } : {}) });
try {
  const page = await browser.newPage();
  await page.setContent(`<form id="create"><div class="waste-form-field"><label>Исполнитель</label><select name="route_executor_id" data-waste-executor-search><option value="">— Пока не назначен —</option><option value="17">ИП Шашикян · Шашикян Мясник</option><option value="28">ООО Север · Иванов Иван</option><option value="39">ООО Юг · Петров Пётр</option></select></div></form><div id="edit"></div>`);
  await page.addScriptTag({ path: path.join(root, 'public/assets/js/waste-trip-executor-search.js') });
  await page.evaluate(() => window.initWasteExecutorSearch(document.querySelector('#create')));
  const input = page.locator('#create [role=combobox]');
  await input.fill('иванов');
  assert.equal(await page.locator('#create [role=listbox] [role=option]').count(), 1, 'driver search filters suggestions');
  await input.press('ArrowDown');
  await input.press('Enter');
  assert.equal(await page.locator('#create select').inputValue(), '28', 'keyboard selection keeps submitted value');
  await input.fill('Шашикян');
  await page.locator('#create [role=listbox] [role=option]').click();
  assert.equal(await page.locator('#create select').inputValue(), '17', 'pointer selection keeps submitted value');
  await input.fill('несуществующий');
  assert.equal(await page.locator('#create .waste-executor-empty').textContent(), 'Ничего не найдено');
  await input.press('Escape');
  assert.equal(await page.locator('#create select').inputValue(), '17', 'unmatched query does not change choice');
  await page.locator('#edit').evaluate(element => {
    element.innerHTML = '<form><div class="waste-form-field"><label>Исполнитель</label><select name="route_executor_id" data-waste-executor-search><option value="">— Пока не назначен —</option><option value="17" selected>ИП Шашикян · Шашикян Мясник</option></select></div></form>';
    window.initWasteExecutorSearch(element);
  });
  assert.match(await page.locator('#edit [role=combobox]').inputValue(), /Шашикян/, 'loaded edit form displays selected executor');
  await page.locator('#edit [role=combobox]').fill('');
  await page.locator('#edit [role=listbox] [role=option]').first().click();
  assert.equal(await page.locator('#edit select').inputValue(), '', 'empty executor can be selected');
  await page.close();
  console.log('WASTE_TRIP_EXECUTOR_SEARCH=PASS');
} finally {
  await browser.close();
}
