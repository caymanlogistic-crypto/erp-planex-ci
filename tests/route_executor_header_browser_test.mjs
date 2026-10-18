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
  if (request.url === '/') { response.setHeader('Content-Type', 'text/html; charset=utf-8'); response.end(html); return; }
  const file = path.resolve(root, 'public', '.' + request.url.split('?')[0]);
  if (!file.startsWith(path.join(root, 'public') + path.sep) || !fs.existsSync(file)) { response.statusCode = 404; response.end(); return; }
  response.setHeader('Content-Type', 'text/css; charset=utf-8'); response.end(fs.readFileSync(file));
});
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));

const browser = await chromium.launch({ headless: true });
try {
  for (const [width, height] of [[390, 844], [768, 850], [1024, 768], [1280, 900], [1440, 900], [1920, 1080], [3840, 2160]]) {
    const page = await browser.newPage({ viewport: { width, height }, deviceScaleFactor: 1 });
    await page.goto(`http://127.0.0.1:${server.address().port}/`);
    const header = page.locator('.route-executors-page-head');
    const icon = header.locator('svg.page-head-tabler-icon');
    const secondary = header.locator('.page-head-actions .btn-ghost');
    assert.equal(await header.count(), 1);
    assert.equal(await icon.count(), 1);
    assert.equal(await secondary.count(), 2);
    assert.deepEqual(await secondary.allTextContents(), ['Создать документ', 'Выбрать из имеющихся']);
    assert.equal(await header.locator('.btn-primary').textContent(), 'Создать исполнителя рейса');
    assert.equal(await icon.getAttribute('stroke'), 'currentColor');
    assert.equal(await icon.getAttribute('stroke-width'), '2');
    assert.equal(await icon.getAttribute('aria-hidden'), 'true');
    if (process.env.PLANEX_VISUAL_EVIDENCE_DIR && [1920, 3840].includes(width)) {
      fs.mkdirSync(process.env.PLANEX_VISUAL_EVIDENCE_DIR, { recursive: true });
      await page.screenshot({ path: path.join(process.env.PLANEX_VISUAL_EVIDENCE_DIR, `route-executor-header-${width}.png`), fullPage: false });
      await header.screenshot({ path: path.join(process.env.PLANEX_VISUAL_EVIDENCE_DIR, `route-executor-header-crop-${width}.png`) });
    }
    const layout = await page.evaluate(() => {
      const header = document.querySelector('.route-executors-page-head');
      const icon = header.querySelector('.page-head-tabler-icon');
      const left = header.querySelector('.page-head-left');
      const actions = header.querySelector('.page-head-actions');
      const title = header.querySelector('.page-title');
      const summary = header.querySelector('.page-summary');
      const box = element => element.getBoundingClientRect();
      const result = {
        iconSize: [box(icon).width, box(icon).height],
        iconColor: getComputedStyle(icon).color,
        gap: getComputedStyle(left).columnGap,
        headerHeight: box(header).height,
        centerDelta: Math.abs(box(icon).y + box(icon).height / 2 - (box(title).y + box(summary).bottom) / 2),
        overlap: box(left).right > box(actions).left && box(left).left < box(actions).right && box(left).bottom > box(actions).top && box(left).top < box(actions).bottom,
        pageOverflow: document.documentElement.scrollWidth > innerWidth + 1,
      };
      icon.remove();
      result.heightWithoutIcon = box(header).height;
      return result;
    });
    assert.deepEqual(layout.iconSize, [30, 30], `${width}px icon size`);
    assert.equal(layout.iconColor, 'rgb(124, 71, 24)', `${width}px accent`);
    assert.equal(layout.gap, '10px', `${width}px text gap`);
    assert.ok(layout.centerDelta < 1, `${width}px vertical alignment ${JSON.stringify(layout)}`);
    assert.equal(layout.headerHeight, layout.heightWithoutIcon, `${width}px header height`);
    assert.equal(layout.overlap, false, `${width}px overlap`);
    assert.equal(layout.pageOverflow, false, `${width}px page overflow`);
    if (width === 1920) {
      const first = secondary.first();
      await page.evaluate(() => document.addEventListener('click', event => event.preventDefault(), true));
      assert.equal(await first.evaluate(element => getComputedStyle(element).backgroundColor), 'rgb(247, 244, 238)');
      await first.hover();
      await page.waitForFunction(element => getComputedStyle(element).backgroundColor === 'rgb(238, 232, 222)', await first.elementHandle());
      assert.equal(await first.evaluate(element => getComputedStyle(element).backgroundColor), 'rgb(238, 232, 222)');
      await page.mouse.down();
      await page.waitForFunction(element => getComputedStyle(element).backgroundColor === 'rgb(227, 219, 207)', await first.elementHandle());
      assert.equal(await first.evaluate(element => getComputedStyle(element).backgroundColor), 'rgb(227, 219, 207)');
      await page.mouse.up();
      await page.keyboard.press('Tab');
      await page.keyboard.press('Shift+Tab');
      assert.equal(await first.evaluate(element => element === document.activeElement), true, 'keyboard focus reaches secondary action');
      assert.equal(await first.evaluate(element => getComputedStyle(element).outlineWidth), '2px');
    }
    await page.close();
  }
  console.log('ROUTE_EXECUTOR_HEADER_BROWSER=PASS');
} finally { await browser.close(); server.close(); }
