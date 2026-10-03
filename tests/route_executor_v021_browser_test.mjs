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
  for (const [width, height] of [[1440, 900], [1920, 1080], [3840, 2160]]) {
    const page = await browser.newPage({ viewport: { width, height }, deviceScaleFactor: 1 });
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.goto(`http://127.0.0.1:${server.address().port}/`);
    await page.evaluate(() => {
      const main = document.querySelector('main.test-workspace');
      main.className = 'content content--wide-workspace';
      const shell = document.createElement('div');
      shell.className = 'workspace-shell-1690';
      while (main.firstChild) shell.appendChild(main.firstChild);
      main.appendChild(shell);
      const topbar = document.createElement('header');
      topbar.className = 'topbar';
      topbar.textContent = 'ERP PLANEX';
      document.body.insertBefore(topbar, main);
      const style = document.createElement('style');
      style.textContent = 'html,body{height:100%;overflow:hidden}main.content{width:calc(100% - 224px);margin-left:224px;height:calc(100vh - 38px);padding:12px 0 0}';
      document.head.appendChild(style);
      const tbody = document.querySelector('.route-executors-card tbody');
      const template = tbody.lastElementChild;
      for (let i = 0; i < 45; i++) tbody.appendChild(template.cloneNode(true));
    });
    const read = () => page.evaluate(() => {
      const rect = element => element.getBoundingClientRect();
      const card = document.querySelector('.route-executors-card');
      const toolbar = card.querySelector('.table-toolbar');
      const headings = [...card.querySelectorAll('thead th')];
      const cells = [...card.querySelectorAll('tbody tr:last-child td')];
      const content = document.querySelector('.content');
      return {
        topbarBottom: rect(document.querySelector('.topbar')).bottom,
        pageHeadBottom: rect(document.querySelector('.page-head')).bottom,
        toolbarTop: rect(toolbar).top,
        toolbarBottom: rect(toolbar).bottom,
        toolbarHeight: rect(toolbar).height,
        headingTop: rect(headings[0]).top,
        headingBottom: rect(headings[0]).bottom,
        headingHeight: rect(headings[0]).height,
        headingWidths: headings.map(element => rect(element).width),
        cellWidths: cells.map(element => rect(element).width),
        footerCount: card.querySelectorAll('.table-footer').length,
        bottomPadding: getComputedStyle(document.querySelector('.workspace-shell-1690')).paddingBottom,
        cardHeight: rect(card).height,
        tableHeight: rect(card.querySelector('table')).height,
        scrollTop: content.scrollTop,
        maxScroll: content.scrollHeight - content.clientHeight,
        pageOverflow: document.documentElement.scrollWidth > innerWidth,
      };
    });
    const initial = await read();
    assert.equal(initial.footerCount, 0);
    assert.equal(initial.bottomPadding, '8px');
    assert.equal(initial.toolbarHeight, 36);
    assert.equal(initial.headingHeight, 30);
    assert.ok(initial.cardHeight >= initial.tableHeight + 36, 'rows keep content height');
    assert.equal(initial.pageOverflow, false);
    if (width === 1920 && process.env.PLANEX_VISUAL_EVIDENCE_DIR) {
      fs.mkdirSync(process.env.PLANEX_VISUAL_EVIDENCE_DIR, { recursive: true });
      await page.screenshot({ path: path.join(process.env.PLANEX_VISUAL_EVIDENCE_DIR, 'route-executor-v021-top.png'), fullPage: false });
    }
    await page.evaluate(() => { document.querySelector('.content').scrollTop = 750; });
    const scrolled = await read();
    assert.ok(scrolled.scrollTop > 100, `${width}px has enough table content to scroll`);
    assert.ok(scrolled.pageHeadBottom < scrolled.topbarBottom, 'Page Header scrolls away');
    assert.equal(scrolled.toolbarTop, scrolled.topbarBottom, 'toolbar sits below the ERP topbar');
    assert.equal(scrolled.toolbarBottom, scrolled.headingTop, 'toolbar and headings form one block');
    assert.equal(scrolled.headingBottom - scrolled.toolbarTop, 66);
    assert.deepEqual(scrolled.headingWidths, initial.headingWidths, 'column widths stay fixed while scrolling');
    assert.deepEqual(scrolled.headingWidths, scrolled.cellWidths, 'headings remain aligned with cells');
    assert.equal(scrolled.pageOverflow, false);
    if (width === 1920 && process.env.PLANEX_VISUAL_EVIDENCE_DIR) {
      await page.screenshot({ path: path.join(process.env.PLANEX_VISUAL_EVIDENCE_DIR, 'route-executor-v021-scrolled.png'), fullPage: false });
    }
    assert.deepEqual(errors, []);
    await page.close();
  }
  console.log('ROUTE_EXECUTOR_V021_BROWSER=PASS');
} finally {
  await browser.close();
  server.close();
}
