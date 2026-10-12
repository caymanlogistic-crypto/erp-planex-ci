import assert from 'node:assert/strict';
import fs from 'node:fs';
import http from 'node:http';
import path from 'node:path';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';

const require = createRequire(import.meta.url);
const { chromium } = require('playwright');
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const variants = [
  ['executors', 'route-executors-page-head', 'route-executor-list-v016.css'],
  ['departures', 'waste-trips-page-head', 'waste-trip-registry.css'],
  ['finance', 'page-head', 'finance-received-paid.css'],
];
const html = (name, headerClass, pageCss) => `<!doctype html><html lang="ru"><head><meta charset="utf-8"><link rel="stylesheet" href="/assets/css/erp-ui.css"><link rel="stylesheet" href="/assets/css/app-base.css"><link rel="stylesheet" href="/assets/css/route-executor-header.css"><link rel="stylesheet" href="/assets/css/${pageCss}"></head><body><main class="workspace-shell-1690"><div class="${name === 'finance' ? 'rp-lab-header-wrap rp-action-design rp-ledger-v015' : ''}"><div class="page-head ${headerClass}"><div class="page-head-left"><h1 class="page-title">${name}</h1></div><div class="page-head-actions"><button class="btn btn-secondary">Дополнительное действие</button><button class="btn btn-primary">Основное действие</button></div></div></div></main></body></html>`;
const server = http.createServer((request, response) => {
  const variant = variants.find(([name]) => request.url === `/${name}`);
  if (variant) {
    response.setHeader('Content-Type', 'text/html; charset=utf-8');
    response.end(html(...variant));
    return;
  }
  const file = path.resolve(root, 'public', '.' + request.url.split('?')[0]);
  if (!file.startsWith(path.join(root, 'public') + path.sep) || !fs.existsSync(file)) {
    response.writeHead(404).end();
    return;
  }
  response.setHeader('Content-Type', file.endsWith('.css') ? 'text/css; charset=utf-8' : 'application/octet-stream');
  fs.createReadStream(file).pipe(response);
});
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
const browser = await chromium.launch({ headless: true });
try {
  const page = await browser.newPage({ viewport: { width: 1920, height: 1080 } });
  const origin = `http://127.0.0.1:${server.address().port}`;
  for (const [name] of variants) {
    await page.goto(`${origin}/${name}`);
    await page.evaluate(() => document.fonts.ready);
    const buttons = page.locator('.page-head-actions .btn');
    const basic = await buttons.evaluateAll(nodes => nodes.map(node => {
      const style = getComputedStyle(node);
      const rect = node.getBoundingClientRect();
      return { height: rect.height, font: style.fontFamily, size: style.fontSize, weight: style.fontWeight, radius: style.borderRadius, shadow: style.boxShadow, color: style.color, background: style.backgroundImage };
    }));
    for (const button of basic) {
      assert.equal(button.height, 30, `${name}: button height`);
      assert.match(button.font, /IBM Plex Sans/, `${name}: font family`);
      assert.equal(button.size, '12px', `${name}: font size`);
      assert.equal(button.weight, '600', `${name}: font weight`);
      assert.equal(button.radius, '2px', `${name}: radius`);
      assert.equal(button.shadow, 'none', `${name}: no shadow`);
    }
    assert.equal(basic[0].color, 'rgb(63, 58, 51)', `${name}: secondary text`);
    assert.equal(basic[0].background, 'none', `${name}: secondary background`);
    assert.equal(basic[1].color, 'rgb(255, 255, 255)', `${name}: primary text`);
    assert.match(basic[1].background, /linear-gradient/, `${name}: primary gradient`);
    await buttons.nth(0).hover();
    assert.equal(await buttons.nth(0).evaluate(node => getComputedStyle(node).backgroundColor), 'rgb(238, 232, 222)', `${name}: secondary hover`);
    await buttons.nth(0).focus();
    assert.equal(await buttons.nth(0).evaluate(node => getComputedStyle(node).outlineWidth), '2px', `${name}: keyboard focus`);
    await page.setViewportSize({ width: 390, height: 844 });
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, `${name}: no page overflow`);
    await page.setViewportSize({ width: 1920, height: 1080 });
  }
  console.log('REGISTRY_HEADER_ACTIONS_BROWSER=PASS');
} finally {
  await browser.close();
  await new Promise(resolve => server.close(resolve));
}
