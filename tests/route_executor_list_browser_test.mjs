import assert from 'node:assert/strict';
import fs from 'node:fs';
import http from 'node:http';
import path from 'node:path';
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { assertRegistryVisualContract } from './helpers/registry_visual_contract.mjs';

const require = createRequire(import.meta.url);
const { chromium } = require('playwright');
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const html = execFileSync(process.env.PLANEX_PHP || 'php', [path.join(root, 'tests/fixtures/route_executor_list_render.php')], { encoding: 'utf8' });
const server = http.createServer((request, response) => {
  if (request.url === '/') { response.setHeader('Content-Type', 'text/html; charset=utf-8'); response.end(html); return; }
  const file = path.resolve(root, 'public', '.' + request.url.split('?')[0]);
  if (!file.startsWith(path.join(root, 'public') + path.sep) || !fs.existsSync(file)) { response.statusCode = 404; response.end(); return; }
  response.setHeader('Content-Type', 'text/css; charset=utf-8'); response.end(fs.readFileSync(file));
});
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
const browser = await chromium.launch({ headless: true });
try {
  for (const width of [1440, 1920]) {
    const page = await browser.newPage({ viewport: { width, height: 900 } });
    await page.goto(`http://127.0.0.1:${server.address().port}/`);
    await assertRegistryVisualContract(page, `route-executors ${width}px`);
    assert.deepEqual(await page.locator('[data-route-executors-grid] thead th').allTextContents(), ['Подрядчик', 'Водитель', 'Комплект ТС', 'Логист', 'Статус']);
    assert.equal(await page.locator('[data-route-executors-grid] tbody tr').count(), 3);
    assert.equal(await page.locator('[data-route-executors-grid] .route-review-row').count(), 2);
    assert.equal(await page.locator('[data-route-executors-grid] .route-vehicle-line').first().textContent(), 'SHACMANА 101 ВС 16· 20 т· 86 м³');
    assert.equal(await page.locator('[data-route-executors-grid] .route-vehicle-line').nth(1).textContent(), 'ТОНАРА1213 33· 24 т· 92 м³');
    assert.equal(await page.locator('[data-route-executors-grid] .route-review-delete').first().textContent(), '⋯');
    assert.equal(await page.locator('[data-route-executors-grid] .route-review-delete').first().getAttribute('data-route-review-delete'), 'synthetic-draft');
    const styles = await page.evaluate(() => {
      const card = document.querySelector('[data-route-executors-grid]');
      const line = card.querySelector('.route-vehicle-line');
      const status = card.querySelector('.route-status');
      const review = card.querySelector('.route-review-row td');
      return { overflow: document.documentElement.scrollWidth > window.innerWidth + 1,
        brand: getComputedStyle(line.querySelector('.route-vehicle-brand')).fontWeight,
        plate: getComputedStyle(line.querySelector('.route-vehicle-plate')).fontWeight,
        metric: getComputedStyle(line.querySelector('.route-vehicle-metric')).fontWeight,
        metricColor: getComputedStyle(line.querySelector('.route-vehicle-metric')).color,
        statusBackground: getComputedStyle(status).backgroundColor,
        statusBorder: getComputedStyle(status).borderTopWidth,
        reviewBackground: getComputedStyle(review).backgroundColor };
    });
    assert.equal(styles.overflow, false, `${width}px page overflow`);
    assert.deepEqual([styles.brand, styles.plate, styles.metric], ['500', '600', '700']);
    assert.equal(styles.metricColor, 'rgb(19, 18, 16)');
    assert.equal(styles.statusBackground, 'rgba(0, 0, 0, 0)');
    assert.equal(styles.statusBorder, '0px');
    assert.equal(styles.reviewBackground, 'rgb(255, 241, 189)');
    if (process.env.PLANEX_VISUAL_EVIDENCE_DIR) {
      fs.mkdirSync(process.env.PLANEX_VISUAL_EVIDENCE_DIR, { recursive: true });
      await page.screenshot({ path: path.join(process.env.PLANEX_VISUAL_EVIDENCE_DIR, `route-executors-${width}.png`), fullPage: true });
    }
    await page.close();
  }
  console.log('ROUTE_EXECUTOR_LIST_BROWSER=PASS');
} finally { await browser.close(); server.close(); }
