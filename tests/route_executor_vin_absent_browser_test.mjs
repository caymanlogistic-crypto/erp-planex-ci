import assert from 'node:assert/strict';
import fs from 'node:fs';
import http from 'node:http';
import path from 'node:path';
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const require = createRequire(import.meta.url);
const { chromium } = require('playwright');
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const html = execFileSync(process.env.PLANEX_PHP || 'php', [path.join(root, 'tests/fixtures/route_executor_combined_render.php')], { encoding: 'utf8' });
const server = http.createServer((request, response) => {
  if (request.url === '/') { response.setHeader('Content-Type', 'text/html; charset=utf-8'); response.end(html); return; }
  const file = path.resolve(root, 'public', '.' + request.url.split('?')[0]);
  if (!file.startsWith(path.join(root, 'public') + path.sep) || !fs.existsSync(file)) { response.statusCode = 404; response.end(); return; }
  response.setHeader('Content-Type', file.endsWith('.css') ? 'text/css' : 'text/javascript');
  response.end(fs.readFileSync(file));
});
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
const browser = await chromium.launch({ headless: true });
try {
  for (const width of [1280, 390]) {
    const page = await browser.newPage({ viewport: { width, height: 900 } });
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.goto('http://127.0.0.1:' + server.address().port + '/');
    await page.locator('[data-route-executor-create-btn]').click();
    await page.locator('#lab-vehicle-form [name="set_type"]').selectOption('single');
    const vin = page.locator('#lab-vehicle-form [name="units[primary][vin]"]');
    const absent = page.locator('#lab-vehicle-form [name="units[primary][vin_absent]"]');
    const absentLabel = absent.locator('xpath=..');
    const mark = absentLabel.locator('.checkbox-mark');
    assert.equal(await mark.evaluate(element => element.getBoundingClientRect().width), 14);
    assert.equal(await absentLabel.locator('.contact-checkbox-label').evaluate(element => getComputedStyle(element).fontFamily.startsWith('system-ui')), true);
    await vin.fill('XTC43100000000000');
    await absentLabel.click();
    assert.equal(await absent.isChecked(), true);
    assert.equal(await vin.inputValue(), '');
    assert.equal(await vin.isEditable(), false);
    await page.waitForFunction(() => getComputedStyle(document.querySelector('#lab-vehicle-form [name="units[primary][vin_absent]"] + .checkbox-mark')).backgroundColor === 'rgb(124, 71, 24)');
    if (process.env.PLANEX_VIN_VISUAL_EVIDENCE) {
      fs.mkdirSync(process.env.PLANEX_VIN_VISUAL_EVIDENCE, { recursive: true });
      await page.screenshot({ path: path.join(process.env.PLANEX_VIN_VISUAL_EVIDENCE, `vin-${width}.png`) });
    }
    await absentLabel.click();
    assert.equal(await absent.isChecked(), false);
    assert.equal(await vin.isEditable(), true);
    assert.equal(await page.locator('#lab-vehicle-form .source-combined-main').evaluate(element => element.scrollWidth > element.clientWidth + 1), false, `horizontal overflow at ${width}px`);
    assert.deepEqual(errors, []);
    await page.close();
  }
  console.log('route_executor_vin_absent_browser_test: PASS');
} finally {
  await browser.close();
  await new Promise(resolve => server.close(resolve));
}
