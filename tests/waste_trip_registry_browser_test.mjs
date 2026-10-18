import assert from 'node:assert/strict';
import fs from 'node:fs';
import http from 'node:http';
import os from 'node:os';
import path from 'node:path';
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const require = createRequire(import.meta.url);
const { chromium } = require('playwright');
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const temp = fs.mkdtempSync(path.join(os.tmpdir(), 'planex-waste-registry-'));
const rendered = path.join(temp, 'registry.html');
execFileSync(process.env.PLANEX_PHP || 'php', [path.join(root, 'tests/waste_trip_registry_render_test.php')], {
  cwd: root,
  env: { ...process.env, WASTE_REGISTRY_RENDER_OUTPUT: rendered },
});
const html = fs.readFileSync(rendered, 'utf8');
const server = http.createServer((req, res) => {
  if (req.url === '/' || req.url?.startsWith('/?')) {
    res.setHeader('Content-Type', 'text/html; charset=utf-8');
    res.end(html);
    return;
  }
  const file = path.resolve(root, 'public', '.' + req.url.split('?')[0]);
  if (!file.startsWith(path.join(root, 'public') + path.sep) || !fs.existsSync(file)) {
    res.statusCode = 404; res.end(); return;
  }
  res.setHeader('Content-Type', file.endsWith('.css') ? 'text/css' : 'text/javascript');
  res.end(fs.readFileSync(file));
});
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
const browser = await chromium.launch({ headless: true, ...(process.env.PLANEX_CHROMIUM_PATH ? { executablePath: process.env.PLANEX_CHROMIUM_PATH } : {}) });
try {
  for (const width of [1920, 1280, 390, 360]) {
    const page = await browser.newPage({ viewport: { width, height: 900 } });
    await page.goto(`http://127.0.0.1:${server.address().port}/`);
    const metrics = await page.evaluate(() => {
      const table = document.querySelector('.waste-registry');
      const first = table.tBodies[0].rows[0];
      const money = [9, 10, 11].map(index => first.cells[index].getBoundingClientRect().width);
      const filter = document.querySelector('[data-waste-filter]');
      const search = filter.querySelector('[name=q]').getBoundingClientRect();
      const sort = filter.querySelector('.lab-reference-select[data-name=sort] .lab-reference-trigger').getBoundingClientRect();
      const manager = filter.querySelector('.lab-reference-select[data-name=manager] .lab-reference-trigger').getBoundingClientRect();
      return {
        scroll: document.documentElement.scrollWidth - document.documentElement.clientWidth,
        tableScroll: document.querySelector('.waste-table-scroll').scrollWidth - document.querySelector('.waste-table-scroll').clientWidth,
        money,
        searchRightOfSort: search.left >= sort.left,
        managerBeforeSort: manager.top < sort.top || (Math.abs(manager.top - sort.top) < 1 && manager.left < sort.left),
        managerFits: manager.right <= document.documentElement.clientWidth + 1,
        searchFits: search.right <= document.querySelector('.registry-ui-toolbar').getBoundingClientRect().right - 8,
        searchWeight: getComputedStyle(filter.querySelector('[name=q]')).fontWeight,
        placeholder: getComputedStyle(filter.querySelector('[name=q]'), '::placeholder').color,
        headingBackground: getComputedStyle(table.querySelector('thead th')).backgroundColor,
        headingImage: getComputedStyle(table.querySelector('thead th')).backgroundImage,
        statusIndex: first.cells[2].classList.contains('waste-status'),
        rowCount: table.tBodies[0].rows.length,
      };
    });
    assert.ok(metrics.scroll <= 1 && metrics.tableScroll <= 4, `${width}: horizontal scroll ${JSON.stringify(metrics)}`);
    assert.equal(metrics.statusIndex, true, `${width}: status column`);
    assert.equal(metrics.rowCount, 5, `${width}: real rows preserved`);
    assert.equal(metrics.searchRightOfSort, true, `${width}: search follows sort`);
    assert.equal(metrics.managerBeforeSort, true, `${width}: manager filter precedes sort`);
    assert.equal(metrics.managerFits, true, `${width}: manager filter stays inside viewport ${JSON.stringify(metrics)}`);
    assert.equal(metrics.searchFits, true, `${width}: search stays inside toolbar ${JSON.stringify(metrics)}`);
    assert.equal(metrics.searchWeight, '400');
    assert.equal(metrics.placeholder, 'rgb(129, 123, 114)');
    assert.equal(metrics.headingBackground, 'rgb(227, 222, 213)');
    assert.equal(metrics.headingImage, 'none');
    if (width === 390 && process.env.PLANEX_VISUAL_EVIDENCE_DIR) {
      fs.mkdirSync(process.env.PLANEX_VISUAL_EVIDENCE_DIR, { recursive: true });
      await page.screenshot({ path: path.join(process.env.PLANEX_VISUAL_EVIDENCE_DIR, 'departures-interactive-390.png') });
    }
    if (width > 640) assert.ok(Math.max(...metrics.money) - Math.min(...metrics.money) <= 1, `${width}: equal financial widths ${JSON.stringify(metrics.money)}`);
    if (width > 640) {
      const amountFits = await page.evaluate(() => {
        const cell = document.querySelector('.waste-registry tbody tr td:nth-child(11)');
        const amount = cell.querySelector('.registry-rate-total');
        amount.textContent = '99 000 000 ₽';
        return amount.scrollWidth <= cell.clientWidth - parseFloat(getComputedStyle(cell).paddingLeft) - parseFloat(getComputedStyle(cell).paddingRight) + 1;
      });
      assert.equal(amountFits, true, `${width}: 99 million rubles fits financial column`);
    }
    await page.close();
  }
  const page = await browser.newPage();
  await page.goto(`http://127.0.0.1:${server.address().port}/`);
  assert.equal(await page.locator('[data-waste-status-tabs] button').count(), 5, 'five flat status choices');
  assert.equal(await page.locator('[data-waste-filter] select[name=status]').isHidden(), true, 'native status value remains in GET form');
  await page.evaluate(() => {
    const form = document.querySelector('[data-waste-filter]');
    form.querySelector('[name=company]').value = 'mg_logistic';
    form.querySelector('[name=status]').value = 'planned_route';
    form.querySelector('[name=q]').value = 'First';
  });
  await page.locator('.lab-reference-select[data-name=manager] .lab-reference-trigger').click();
  await page.locator('.lab-reference-select[data-name=manager] .lab-reference-option[data-value="1"]').click();
  await page.waitForURL(/manager=1/);
  const combinedParams = new URL(page.url()).searchParams;
  assert.equal(combinedParams.get('company'), 'mg_logistic', 'manager choice retains company');
  assert.equal(combinedParams.get('status'), 'planned_route', 'manager choice retains status');
  assert.equal(combinedParams.get('q'), 'First', 'manager choice retains search');
  await page.goto(`http://127.0.0.1:${server.address().port}/`);
  await page.locator('[data-waste-status-tabs] button[data-status=completed]').click();
  await page.waitForURL(/status=completed/);
  assert.equal(new URL(page.url()).searchParams.get('status'), 'completed', 'status filter submits GET automatically');
  await page.close();
  console.log('WASTE_TRIP_REGISTRY_BROWSER=PASS');
} finally {
  await browser.close();
  await new Promise(resolve => server.close(resolve));
  fs.rmSync(temp, { recursive: true, force: true });
}
