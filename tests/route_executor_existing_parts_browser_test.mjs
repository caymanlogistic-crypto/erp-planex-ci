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
const fixture = execFileSync(process.env.PLANEX_PHP || 'php', [path.join(root, 'tests/fixtures/route_executor_combined_render.php')], { encoding: 'utf8' });
const html = fixture.replace('</head>', '<link rel="stylesheet" href="/assets/css/route-executor-existing-parts.css"></head>')
  .replace('</body>', '<script src="/assets/js/route-executor-existing-parts.js"></script></body>');
const item = { id: 41, kind: 'contractor', formId: 'lab-contractor-form', label: 'ООО Синтетика',
  values: { name: 'ООО Синтетика', inn: '7701234567', contractor_type: 'legal' },
  files: [{ id: 77, formId: 'lab-contractor-form', field: 'predef_doc[company_card]', original_name: 'Карточка Синтетика.pdf', title: 'Карточка предприятия' }] };
const server = http.createServer((request, response) => {
  if (request.url === '/') { response.setHeader('Content-Type', 'text/html; charset=utf-8'); response.end(html); return; }
  if (request.url.startsWith('/company/route-executors/review/existing?')) {
    const url = new URL(request.url, 'http://localhost');
    response.setHeader('Content-Type', 'application/json; charset=utf-8');
    response.end(JSON.stringify(url.searchParams.has('id') ? { ok: true, item } : { ok: true, items: [{ id: 41, label: item.label }] }));
    return;
  }
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
    await page.goto(`http://127.0.0.1:${server.address().port}/`);
    await page.locator('[data-route-executor-create-btn]').click();
    assert.equal(await page.locator('.lab-picker-trigger').count(), 3);
    await page.locator('#lab-contractor-form .lab-picker-trigger').click();
    await page.locator('#lab-contractor-form .lab-entity-picker [role=option]').first().click();
    await page.waitForFunction(() => document.querySelector('#lab-contractor-form [name=name]')?.value === 'ООО Синтетика');
    assert.equal(await page.locator('#lab-contractor-form [name=name]').inputValue(), 'ООО Синтетика');
    assert.equal(await page.locator('#lab-contractor-form [name=name]').getAttribute('readonly'), '');
    assert.equal(await page.locator('#lab-driver-form [name=full_name]').getAttribute('readonly'), null);
    assert.equal(await page.locator('#lab-contractor-form .lab-file-existing').count(), 1);
    assert.match(await page.locator('#lab-contractor-form .lab-file-state').first().textContent(), /Карточка Синтетика\.pdf/);
    assert.deepEqual(await page.evaluate(() => window.routeExecutorExistingParts.snapshot().selected_entities), { contractor: 41 });
    await page.locator('#lab-contractor-form .lab-file-remove').first().click();
    assert.deepEqual(await page.evaluate(() => window.routeExecutorExistingParts.snapshot().removed_documents), [77]);
    await page.locator('#lab-contractor-form .lab-picker-trigger').click();
    await page.locator('#lab-contractor-form .lab-start-new').click();
    assert.equal(await page.locator('#lab-contractor-form [name=name]').getAttribute('readonly'), null);
    assert.equal(await page.locator('#route-executor-create-modal-inner').evaluate(element => element.scrollWidth > element.clientWidth + 1), false);
    assert.deepEqual(errors, []);
    await page.close();
  }
  console.log('ROUTE_EXECUTOR_EXISTING_PARTS_BROWSER=PASS');
} finally { await browser.close(); server.close(); }
