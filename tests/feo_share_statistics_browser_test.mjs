import assert from 'node:assert/strict';
import fs from 'node:fs';
import http from 'node:http';
import path from 'node:path';
import {createRequire} from 'node:module';
import {fileURLToPath} from 'node:url';

const require = createRequire(import.meta.url);
const {chromium} = require('playwright');
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const data = fs.readFileSync(path.join(root, 'resources/data/feo_share_baseline.json'), 'utf8').replaceAll('<', '\\u003c');
const html = `<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/assets/css/manager-statistics.css"><link rel="stylesheet" href="/assets/css/feo-share-statistics.css"><style>body{margin:0;font-family:Arial,sans-serif;--surface-strong:#fff;--surface-muted:#eee9e1;--surface-form:#f8f5f0;--line-soft:#d7d0c5;--line-hair:#e4ded5;--text-main:#27231f;--text-faint:#736f69;--font:Arial,sans-serif}</style></head><body><div id="feo-share-root"></div><script type="application/json" id="feo-share-data">${data}</script><script src="/assets/js/feo-share-statistics.js"></script></body></html>`;
const server = http.createServer((req, res) => {
  if (req.url === '/') { res.setHeader('Content-Type', 'text/html; charset=utf-8'); res.end(html); return; }
  const file = path.resolve(root, 'public', '.' + req.url);
  if (!file.startsWith(path.join(root, 'public') + path.sep) || !fs.existsSync(file)) { res.statusCode = 404; res.end(); return; }
  res.setHeader('Content-Type', file.endsWith('.css') ? 'text/css' : 'text/javascript');
  res.end(fs.readFileSync(file));
});
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
const browser = await chromium.launch({headless:true, ...(process.env.PLANEX_CHROMIUM_PATH ? {executablePath:process.env.PLANEX_CHROMIUM_PATH} : {})});
try {
  const page = await browser.newPage({viewport:{width:1440,height:900}});
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  await page.goto(`http://127.0.0.1:${server.address().port}`, {waitUntil:'networkidle'});
  assert.equal(await page.locator('.fs-snapshot-table tbody tr').count(), 3, 'weekly rows use available real snapshots');
  assert.equal(await page.locator('.fs-chart-node').count(), 6, 'two series have a point for each period');
  assert.equal(await page.locator('.manager-stats__node-tooltip').count(), 0, 'per-point popups are removed');
  assert.equal(await page.locator('.fs-chart linearGradient').count(), 2, 'both lines have matching gradients');
  assert.equal(await page.locator('.fs-chart-node circle').first().getAttribute('r'), '4.5', 'visible points match the other statistics charts');
  const margins = await page.locator('.fs-chart').evaluate(svg => {
    const box = svg.viewBox.baseVal, grid = svg.querySelector('.grid-line');
    return [Number(grid.getAttribute('x1')), box.width - Number(grid.getAttribute('x2'))];
  });
  assert.deepEqual(margins, [58,58], 'chart has equal side margins');
  const middle = await page.locator('.fs-chart-node.is-company[data-node-index="1"] circle').boundingBox();
  await page.mouse.move(middle.x + middle.width / 2, middle.y + middle.height / 2);
  assert.equal(await page.locator('.fs-chart-node.is-period-active').count(), 2, 'plot hover immediately highlights both points');
  assert.equal(await page.locator('.fs-chart-node:hover').count(), 0, 'points do not receive pointer hover');
  assert.equal(await page.locator('.fs-snapshot-table tr.is-period-active').getAttribute('data-period'), '1', 'plot hover highlights the matching table row');
  assert.equal(await page.locator('.manager-stats__period-guide.is-visible').count(), 1, 'one vertical guide is visible');
  assert.equal(await page.locator('.fs-period-popup.is-visible').count(), 1, 'one shared summary is visible');
  assert.equal(await page.locator('.fs-period-popup .manager-stats__period-popup-item').count(), 2, 'summary contains both series');
  assert.deepEqual(await page.locator('.fs-period-popup .manager-stats__period-popup-item').evaluateAll(items => items.map(item => item.style.color)), ['rgb(192, 120, 48)','rgb(74, 122, 155)'], 'summary text follows line colors');
  await page.locator('.fs-snapshot-table tbody tr[data-period="2"]').hover();
  assert.equal(await page.locator('.fs-chart-node.is-period-active').count(), 2, 'table hover highlights both graph points');
  assert.equal(await page.locator('.fs-snapshot-table tr.is-period-active').getAttribute('data-period'), '2', 'table hover selects its own period');
  await page.getByRole('button', {name:'Срезы'}).click();
  assert.equal(await page.locator('.fs-snapshot-table tbody tr').count(), 4, 'mode change redraws the full snapshot series');
  const point = await page.locator('.fs-chart-node.is-company[data-node-index="1"] circle').boundingBox();
  await page.mouse.move(point.x + point.width / 2, point.y + point.height / 2);
  assert.equal(await page.locator('.fs-chart-node.is-period-active').count(), 2, 'mode change keeps chart interaction bound');
  await page.setViewportSize({width:390,height:844});
  assert.equal(await page.locator('.fs-chart').evaluate(el => Math.round(el.getBoundingClientRect().height)), 138, 'mobile chart matches statistics height');
  assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth), true, 'mobile page has no horizontal overflow');
  await page.locator('.fs-snapshot-table tbody tr[data-period="0"]').focus();
  assert.equal(await page.locator('.fs-chart-node.is-period-active').count(), 2, 'keyboard focus highlights both chart points');
  const popupFit = await page.locator('.fs-period-popup').evaluate(el => {
    const popup = el.getBoundingClientRect(), wrap = el.parentElement.getBoundingClientRect();
    return popup.left >= wrap.left && popup.right <= wrap.right && popup.bottom <= wrap.bottom;
  });
  assert.equal(popupFit, true, 'mobile summary fits in chart card');
  assert.deepEqual(errors, [], 'no browser errors');
  console.log('FEO_SHARE_STATISTICS_BROWSER=PASS');
} finally {
  await browser.close();
  await new Promise(resolve => server.close(resolve));
}
