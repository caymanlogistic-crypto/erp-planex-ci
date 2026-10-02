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
const html = execFileSync(process.env.PLANEX_PHP || 'php', [path.join(root, 'tests/fixtures/route_executor_list_render.php')], { encoding: 'utf8' });
const server = http.createServer((request, response) => {
  if (request.url === '/') {
    response.setHeader('Content-Type', 'text/html; charset=utf-8');
    response.end(html);
    return;
  }
  const file = path.resolve(root, 'public', '.' + request.url.split('?')[0]);
  if (!file.startsWith(path.join(root, 'public') + path.sep) || !fs.existsSync(file)) {
    response.statusCode = 404;
    response.end();
    return;
  }
  response.setHeader('Content-Type', file.endsWith('.js') ? 'text/javascript; charset=utf-8' : 'text/css; charset=utf-8');
  response.end(fs.readFileSync(file));
});
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));

const browser = await chromium.launch({ headless: true });
try {
  for (const [width, height] of [[768, 850], [1024, 768], [1280, 900], [1440, 900], [1920, 1080], [2560, 1440], [3840, 2160]]) {
    const page = await browser.newPage({ viewport: { width, height }, deviceScaleFactor: 1 });
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.goto(`http://127.0.0.1:${server.address().port}/`);
    await page.addScriptTag({ url: '/assets/js/modal-shell.js' });
    await page.addScriptTag({ url: '/assets/js/app.js' });
    await page.addScriptTag({ url: '/assets/js/route-executor-review-workflow.js' });
    await page.addScriptTag({ url: '/assets/js/route-executor-list-v016.js' });

    const metrics = await page.evaluate(() => {
      const header = document.querySelector('.route-executors-page-head');
      const card = document.querySelector('.route-executors-card');
      const toolbar = card.querySelector('.table-toolbar');
      const heading = card.querySelector('thead th');
      const vehicle = card.querySelector('.route-vehicle-brand');
      const search = card.querySelector('.toolbar-search');
      const rect = element => element.getBoundingClientRect();
      return {
        gap: rect(card).top - rect(header).bottom,
        toolbarPadding: [getComputedStyle(toolbar).paddingLeft, getComputedStyle(toolbar).paddingRight],
        headingTop: getComputedStyle(heading).borderTopWidth,
        headingBottom: getComputedStyle(heading).borderBottomWidth,
        brandTransform: getComputedStyle(vehicle).textTransform,
        searchIcon: getComputedStyle(search).backgroundImage.includes('svg'),
        overflow: document.documentElement.scrollWidth > innerWidth + 1,
      };
    });
    assert.equal(metrics.gap, 8, `${width}px header-to-table gap`);
    assert.deepEqual(metrics.toolbarPadding, ['12px', '12px']);
    assert.equal(metrics.headingTop, '0px');
    assert.equal(metrics.headingBottom, '1px');
    assert.equal(metrics.brandTransform, 'uppercase');
    assert.equal(metrics.searchIcon, true);
    assert.equal(metrics.overflow, false, `${width}px document overflow`);
    const vehicleInput = page.locator('input[name^="units["][name$="[brand]"]').first();
    if (await vehicleInput.count()) {
      await vehicleInput.evaluate(element => { element.value = 'Scania'; });
      assert.equal(await vehicleInput.inputValue(), 'Scania', 'presentation must retain the underlying brand value');
      assert.equal(await vehicleInput.evaluate(element => getComputedStyle(element).textTransform), 'uppercase');
    }

    const sort = page.locator('.route-sort-trigger');
    await sort.click();
    const list = page.locator('.route-sort-list');
    assert.equal(await list.isVisible(), true);
    assert.equal(await list.evaluate(element => getComputedStyle(element).padding), '0px');
    assert.equal(await list.locator('.route-sort-option').first().evaluate(element => getComputedStyle(element).borderLeftWidth), '0px');
    if (process.env.PLANEX_VISUAL_EVIDENCE_DIR && [1920, 3840].includes(width)) {
      fs.mkdirSync(process.env.PLANEX_VISUAL_EVIDENCE_DIR, { recursive: true });
      await page.screenshot({ path: path.join(process.env.PLANEX_VISUAL_EVIDENCE_DIR, `route-executor-v016-sort-${width}.png`), fullPage: false });
    }
    await list.press('ArrowDown');
    await list.press('Enter');
    assert.equal(await page.locator('select[data-erp-grid-sort]').inputValue(), 'alpha');
    assert.equal(await sort.getAttribute('aria-expanded'), 'false');

    const trigger = page.locator('.route-review-delete').first();
    const menu = page.locator('.route-review-action-menu');
    await trigger.hover();
    assert.equal(await trigger.getAttribute('title'), null, 'ellipsis must have no native tooltip');
    assert.equal(await menu.isVisible(), false, 'hover must not open destructive menu');
    await trigger.click();
    assert.equal(await menu.isVisible(), true);
    assert.equal(await menu.evaluate(element => getComputedStyle(element).width), '150px');
    assert.equal(await menu.locator('svg.icon-tabler-trash').count(), 1);
    if (process.env.PLANEX_VISUAL_EVIDENCE_DIR && [1920, 3840].includes(width)) {
      await page.screenshot({ path: path.join(process.env.PLANEX_VISUAL_EVIDENCE_DIR, `route-executor-v016-delete-${width}.png`), fullPage: false });
    }
    await page.evaluate(() => {
      window.__routeDeleteConfirmCount = 0;
      window.confirm = () => { window.__routeDeleteConfirmCount++; return false; };
    });
    await menu.locator('.route-review-action-delete').click();
    assert.equal(await page.evaluate(() => window.__routeDeleteConfirmCount), 1, 'original deletion confirmation is retained');
    assert.equal(await menu.isVisible(), false);
    assert.deepEqual(errors, [], `${width}px page errors`);
    await page.close();
  }
  console.log('ROUTE_EXECUTOR_V016_BROWSER=PASS');
} finally {
  await browser.close();
  server.close();
}
