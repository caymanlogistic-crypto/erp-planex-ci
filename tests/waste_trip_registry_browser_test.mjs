import assert from 'node:assert/strict';
import fs from 'node:fs';
import http from 'node:http';
import os from 'node:os';
import path from 'node:path';
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { assertRegistryVisualContract, assertRegistryDataContract } from './helpers/registry_visual_contract.mjs';

const require = createRequire(import.meta.url);
const { chromium } = require('playwright');
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const temp = fs.mkdtempSync(path.join(os.tmpdir(), 'planex-waste-registry-'));
const rendered = path.join(temp, 'registry.html');
execFileSync(process.env.PLANEX_PHP || 'php', [path.join(root, 'tests/waste_trip_registry_render_test.php')], {
  cwd: root,
  env: { ...process.env, WASTE_REGISTRY_RENDER_OUTPUT: rendered },
});
// The render contract remains a view fixture; use the actual shell for containment.
const html=execFileSync(process.env.PLANEX_PHP||'php',[path.join(root,'tests/fixtures/registry_layout_render.php')],{input:fs.readFileSync(rendered,'utf8'),encoding:'utf8'});
const filteredHtml = query => {
  const params = new URLSearchParams(query), status = params.get('status') || '';
  let result = html;
  for (const name of ['company', 'status', 'manager', 'sort']) {
    const value = params.get(name) || (name === 'sort' ? 'status' : '');
    result = result.replace(new RegExp(`<select name="${name}"[\\s\\S]*?<\\/select>`), select => select.replace(/(<option value="[^"]*")\s*selected/g, '$1').replace(new RegExp(`(<option value="${value}")`), '$1 selected'));
  }
  result = result.replace(/(<input name="q"[^>]*value=")[^"]*(")/, (_, before, after) => `${before}${(params.get('q') || '').replaceAll('&', '&amp;').replaceAll('"', '&quot;')}${after}`);
  return result
    .replace(/<button type="button" data-status="([^"]*)"([^>]*)aria-pressed="(?:true|false)"/g, (full, value, prefix) => `<button type="button" data-status="${value}"${prefix}aria-pressed="${value === status ? 'true' : 'false'}"`)
    .replace(/<tr[^>]*data-waste-view="[^"]+"[^>]*>[\s\S]*?<\/tr>/g, row => !status || row.includes(`data-status="${status}"`) ? row : '');
};
const server = http.createServer((req, res) => {
  if (new URL(req.url, 'http://127.0.0.1').pathname === '/' || new URL(req.url, 'http://127.0.0.1').pathname === '/company/trips/departures') {
    res.setHeader('Content-Type', 'text/html; charset=utf-8');
    res.end(filteredHtml(req.url.split('?')[1] || ''));
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
  for (const width of [1920, 1280, 390, 360, 3840]) {
    const page = await browser.newPage({ viewport: { width, height: 900 } });
    await page.goto(`http://127.0.0.1:${server.address().port}/company/trips/departures`);
    await page.waitForSelector('[data-registry-ready="true"]');
    assert.deepEqual(await page.locator('select[name=manager] option').evaluateAll(options=>options.map(option=>[option.value,option.textContent])), [['','Все менеджеры'],['1','Владимир С.'],['2','Карина Г.']], `${width}: manager labels and original IDs/order`);
    const managerTrigger=page.locator('.lab-reference-select[data-name=manager] .lab-reference-trigger');
    await managerTrigger.click();
    assert.deepEqual(await page.locator('.lab-reference-select[data-name=manager] [role=option]').allTextContents(), ['Все менеджеры','Владимир С.','Карина Г.'], `${width}: visible option labels`);
    await page.keyboard.press('Escape');
    await assertRegistryVisualContract(page, `trips-departures ${width}px`);
    await assertRegistryDataContract(page, 'trips-departures');
    assert.deepEqual(await page.locator('td.waste-manager .registry-ui-primary').allTextContents(),['Владимир С.','Владимир С.','Владимир С.','Владимир С.','Карина Г.'],`${width}: manager cells and idempotence`);
    assert.equal(await page.locator('td.waste-manager').first().getAttribute('title'),'Сидоров Владимир Петрович',`${width}: full manager tooltip preserved`);
    const metrics = await page.evaluate(() => {
      const table = document.querySelector('.registry-ui-table--trips');
      const first = table.tBodies[0].rows[0];
      const money = [10, 11, 12].map(index => first.cells[index].getBoundingClientRect().width);
      const filter = document.querySelector('[data-waste-filter]');
      const toolbar = document.querySelector('.registry-ui-toolbar').getBoundingClientRect();
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
        searchStartsInside: search.left >= toolbar.left + 8,
        filterStartsBelowCount: filter.getBoundingClientRect().top >= document.querySelector('.found-label').getBoundingClientRect().bottom,
        toolbarHeight: toolbar.height,
        toolbarWrap: getComputedStyle(document.querySelector('.registry-ui-toolbar')).flexWrap,
        searchWeight: getComputedStyle(filter.querySelector('[name=q]')).fontWeight,
        placeholder: getComputedStyle(filter.querySelector('[name=q]'), '::placeholder').color,
        headingBackground: getComputedStyle(table.querySelector('thead th')).backgroundColor,
        headingImage: getComputedStyle(table.querySelector('thead th')).backgroundImage,
        statusIndex: first.cells[3].classList.contains('waste-status'),
        rowCount: table.tBodies[0].rows.length,
      };
    });
    assert.ok(metrics.scroll <= 1 && metrics.tableScroll <= 1, `${width}: registry has no horizontal scroll ${JSON.stringify(metrics)}`);
    assert.equal(metrics.statusIndex, true, `${width}: status column`);
    assert.equal(metrics.rowCount, 5, `${width}: real rows preserved`);
    assert.equal(metrics.searchRightOfSort, true, `${width}: search follows sort`);
    assert.equal(metrics.managerBeforeSort, true, `${width}: manager filter precedes sort`);
    assert.equal(metrics.managerFits, true, `${width}: manager filter stays inside viewport ${JSON.stringify(metrics)}`);
    assert.equal(metrics.searchFits, true, `${width}: search stays inside toolbar ${JSON.stringify(metrics)}`);
    if (width <= 640) {
      assert.equal(metrics.toolbarWrap, 'nowrap', `${width}: shared mobile toolbar stays in one column`);
      assert.equal(metrics.searchStartsInside, true, `${width}: search starts inside toolbar ${JSON.stringify(metrics)}`);
      assert.equal(metrics.filterStartsBelowCount, true, `${width}: filters follow count on a new row ${JSON.stringify(metrics)}`);
    } else {
      assert.ok(metrics.toolbarHeight <= 50, `${width}: shared toolbar stays 49px high ${JSON.stringify(metrics)}`);
    }
    assert.equal(metrics.searchWeight, '500');
    assert.equal(metrics.placeholder, 'rgb(129, 123, 114)');
    assert.equal(metrics.headingBackground, 'rgb(227, 222, 213)');
    assert.equal(metrics.headingImage, 'none');
    if (width === 390 && process.env.PLANEX_VISUAL_EVIDENCE_DIR) {
      fs.mkdirSync(process.env.PLANEX_VISUAL_EVIDENCE_DIR, { recursive: true });
      await page.screenshot({ path: path.join(process.env.PLANEX_VISUAL_EVIDENCE_DIR, 'departures-interactive-390.png') });
    }
    if (width > 640) assert.ok(Math.min(...metrics.money) >= 58, `${width}: financial columns preserve readable widths ${JSON.stringify(metrics.money)}`);
    if (width > 1100) {
      const widths = await page.evaluate(() => {
        const row = document.querySelector('.registry-ui-table--trips tbody tr');
        const tableWidth = row.closest('table').getBoundingClientRect().width;
        return [row.cells[0], row.cells[1], row.cells[2]].map(cell => cell.getBoundingClientRect().width / tableWidth);
      });
      assert.ok(Math.abs(widths[0] - .05) < .005 && Math.abs(widths[1] - .11) < .005 && Math.abs(widths[2] - .08) < .005, `${width}: accepted ID, description and tracker widths ${JSON.stringify(widths)}`);
    }
    assert.ok(await page.locator('script[src*="/assets/js/app.js"]').count(),`${width}: actual generic grid script loaded`);
    const visibleIds=()=>page.locator('.registry-ui-table--trips tbody tr').evaluateAll(rows=>rows.filter(row=>row.getClientRects().length&&getComputedStyle(row).display!=='none').map(row=>Number(row.dataset.wasteView)).sort((a,b)=>a-b));
    for(const query of ['Сидоров Владимир Петрович','Владимир С.']){
      await page.locator('[data-waste-filter] [name=q]').fill(query);
      await page.waitForURL(url=>url.searchParams.get('q')===query);
      assert.deepEqual(await visibleIds(),[1,2,3,4],`${width}: real generic grid retains known full/short manager rows for ${query}`);
      await page.reload();
      assert.deepEqual(await visibleIds(),[1,2,3,4],`${width}: full/short search visible IDs survive reload`);
      assert.deepEqual(await page.locator('td.waste-manager .registry-ui-primary').allTextContents(),['Владимир С.','Владимир С.','Владимир С.','Владимир С.','Карина Г.'],`${width}: search never expands visible manager labels`);
      await page.locator('.registry-ui-reset').click();
      await page.waitForURL(url=>url.searchParams.get('q')==='');
      assert.deepEqual(await visibleIds(),[1,2,3,4,5],`${width}: reset restores all actual visible IDs`);
    }
    await page.close();
  }
  const page = await browser.newPage();
  await page.goto(`http://127.0.0.1:${server.address().port}/`);
  await page.evaluate(() => localStorage.clear());
  await page.reload();
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
  assert.equal(await page.locator('.lab-reference-select[data-name=manager] .lab-reference-trigger').textContent(),'Владимир С.','selected visible label follows manager value');
  const combinedParams = new URL(page.url()).searchParams;
  assert.equal(combinedParams.get('company'), 'mg_logistic', 'manager choice retains company');
  assert.equal(combinedParams.get('status'), 'planned_route', 'manager choice retains status');
  assert.equal(combinedParams.get('q'), 'First', 'manager choice retains search');
  await page.evaluate(() => localStorage.clear());
  await page.goto(`http://127.0.0.1:${server.address().port}/`);
  await page.locator('[data-waste-status-tabs] button[data-status=completed]').click();
  await page.waitForURL(/status=completed/);
  await page.waitForTimeout(600);
  assert.equal(new URL(page.url()).searchParams.get('status'), 'completed', 'status filter submits GET automatically');
  assert.equal(await page.locator('[data-waste-status-tabs] button[data-status=completed]').getAttribute('aria-pressed'), 'true', 'selected status survives server navigation');
  assert.equal(await page.locator('.registry-ui-table--trips tbody tr').count(), 1, 'server renders only completed row');
  await page.reload();
  await page.waitForTimeout(600);
  assert.equal(new URL(page.url()).searchParams.get('status'), 'completed', 'reload does not reverse the filter');
  assert.equal(await page.locator('[data-waste-status-tabs] button[data-status=completed]').getAttribute('aria-pressed'), 'true', 'saved tab matches URL after reload');
  await page.locator('.registry-ui-reset').click();
  await page.waitForURL(url => url.searchParams.get('status') === '');
  await page.waitForTimeout(600);
  assert.equal(new URL(page.url()).searchParams.get('status'), '', 'reset clears server status filter');
  assert.equal(await page.locator('[data-waste-status-tabs] button[data-status=""]').getAttribute('aria-pressed'), 'true', 'reset selects all statuses');
  assert.equal(await page.locator('.registry-ui-table--trips tbody tr').count(), 5, 'reset restores all rows');
  await page.close();
  console.log('WASTE_TRIP_REGISTRY_BROWSER=PASS');
} finally {
  await browser.close();
  await new Promise(resolve => server.close(resolve));
  fs.rmSync(temp, { recursive: true, force: true });
}
