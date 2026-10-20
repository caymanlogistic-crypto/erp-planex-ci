import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {mkdirSync,readFileSync} from 'node:fs';
import http from 'node:http';
import path from 'node:path';
import {createRequire} from 'node:module';
import {fileURLToPath} from 'node:url';
import {assertRegistryVisualContract,assertRegistryDataContract} from './helpers/registry_visual_contract.mjs';

const require = createRequire(import.meta.url);
const {chromium} = require(process.env.PLANEX_PLAYWRIGHT_MODULE || 'playwright');
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const html = execFileSync(process.env.PLANEX_PHP || 'php', [path.join(root, 'tests/fixtures/trip_closing_design_lab_render.php')], {encoding:'utf8'});
const server = http.createServer((request,response) => {
  if (request.url.startsWith('/assets/')) {
    const file = path.join(root, 'public', request.url.split('?')[0]);
    response.setHeader('Content-Type', file.endsWith('.css') ? 'text/css' : 'text/javascript');
    response.end(readFileSync(file));
    return;
  }
  response.setHeader('Content-Type', 'text/html; charset=utf-8');
  response.end(html);
});
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
const browser = await chromium.launch({headless:true});
try {
  for (const width of [390, 1280, 1920, 3840]) {
    const page = await browser.newPage({viewport:{width,height:900}});
    await page.goto(`http://127.0.0.1:${server.address().port}/company/logistics/closing-documents`);
    await assertRegistryVisualContract(page, `logistics-closing-documents ${width}px`);
    await assertRegistryDataContract(page, 'logistics-closing-documents');
    const manager=page.locator('[data-closing-manager]');
    assert.deepEqual(await manager.locator('option').evaluateAll(options=>options.map(option=>[option.value,option.textContent])), [['','Все менеджеры'],['71','Анна П.'],['72','Максим О.'],['73','Мария С.'],['74','idle.manager']], `${width}: short manager labels preserve IDs/order and login fallback`);
    const managerSelect=page.locator('.lab-reference-select').filter({has:manager});
    await managerSelect.locator('.lab-reference-trigger').click();
    assert.deepEqual(await managerSelect.locator('[role=option]').allTextContents(), ['Все менеджеры','Анна П.','Максим О.','Мария С.','idle.manager'], `${width}: visible manager labels`);
    await managerSelect.locator('[role=option][data-value="72"]').click();
    assert.equal(await manager.inputValue(),'72',`${width}: original membership selected`);
    assert.equal(await managerSelect.locator('.lab-reference-trigger').textContent(),'Максим О.',`${width}: selected manager label`);
    const visibleIds=()=>page.locator('.registry-ui-table--documents tbody tr:not([hidden])').evaluateAll(rows=>rows.map(row=>Number(row.dataset.reportTripId)).sort((a,b)=>a-b));
    const expectedIds=Array.from({length:35},(_,index)=>index+1).filter(id=>(id-1)%3===1);
    assert.deepEqual(await visibleIds(),expectedIds,`${width}: label change preserves actual manager row filtering`);
    await page.reload();
    assert.equal(await page.locator('[data-closing-manager]').inputValue(),'72',`${width}: manager persists after reload`);
    assert.deepEqual(await visibleIds(),expectedIds,`${width}: persisted manager keeps row IDs`);
    await page.locator('.registry-ui-reset').click();
    assert.equal(await page.locator('[data-closing-manager]').inputValue(),'',`${width}: reset manager`);
    assert.equal((await visibleIds()).length,35,`${width}: reset restores all rows`);
    assert.deepEqual(await page.locator('.registry-ui-table--documents tbody tr').evaluateAll(rows=>[1,2,3].map(id=>rows.find(row=>Number(row.dataset.reportTripId)===id).cells[12].innerText.trim())),['Анна П.','Максим О.','Мария С.'],`${width}: manager table cells`);
    assert.equal(await page.locator('.registry-ui-table--documents tbody tr[data-report-trip-id="1"] td:nth-child(13)').getAttribute('title'),'Петрова Анна Сергеевна',`${width}: raw manager tooltip`);
    for(const [query,ids] of [['Орлов Максим Ильич',expectedIds],['Максим О.',expectedIds],['несуществующий менеджер',[]]]){
      await page.locator('.registry-ui-search').fill(query);
      assert.deepEqual(await visibleIds(),ids,`${width}: actual full/visible-short search IDs for ${query}`);
    }
    await page.locator('.registry-ui-reset').click();
    const cardStates = await page.locator('.registry-ui-table--documents tbody tr').evaluateAll(rows => [1,2,3,4].map(id => [...rows.find(row => row.dataset.reportTripId===String(id)).cells[9].querySelectorAll('.registry-ui-status')].map(item => { const style=getComputedStyle(item); return {text:item.textContent.trim(),type:item.classList.contains('registry-ui-error')?'error':'success',font:[style.fontSize,style.fontWeight,style.lineHeight,style.color]}; })));
    assert.deepEqual(cardStates.map(items => items.map(item => item.type)), [['error'],['success'],['success','error'],['success','success']], `${width}: single and multi diagnostic states use raw semantics`);
    for (const items of cardStates) for (const item of items) assert.deepEqual(item.font, ['12px','500','16.8px',item.type==='error'?'rgb(135, 40, 34)':'rgb(31, 107, 67)'], `${width}: diagnostic typography and semantic color`);
    assert.equal(cardStates[0][0].text, 'НЕТ', `${width}: missing single diagnostic card stays red`);
    const metrics = await page.evaluate(() => {
      const q = selector => document.querySelector(selector);
      const box = element => element.getBoundingClientRect();
      const head = q('.registry-ui-head'), card = q('.registry-ui-card'), toolbar = q('.registry-ui-toolbar');
      const grid = q('.closing-grid'), column = q('.registry-ui-table thead th'), search = q('.registry-ui-search');
      return {
        overflow:document.documentElement.scrollWidth - document.documentElement.clientWidth,
        headLeft:box(head).left, cardLeft:box(card).left, headRight:box(head).right, cardRight:box(card).right,
        cardBackground:getComputedStyle(card).backgroundColor,
        toolbarHeight:box(toolbar).height,
        toolbarTableGap:box(grid).top-box(toolbar).bottom,
        measured:parseFloat(card.style.getPropertyValue('--registry-toolbar-height')),
        columnBackground:getComputedStyle(column).backgroundColor,
        columnFontSize:getComputedStyle(column).fontSize,
        columnWeight:getComputedStyle(column).fontWeight,
        searchHeight:box(search).height,
        gridOverflow:getComputedStyle(grid).overflowX,
        gridScroll:Math.max(0,grid.scrollWidth-grid.clientWidth),
      };
    });
    assert.ok(metrics.overflow <= 1, `${width}: no page horizontal scroll ${JSON.stringify(metrics)}`);
    assert.ok(Math.abs(metrics.headLeft-metrics.cardLeft) <= 1 && Math.abs(metrics.headRight-metrics.cardRight) <= 1, `${width}: header and card align`);
    assert.equal(metrics.cardBackground, 'rgb(245, 243, 238)', `${width}: shared card surface`);
    assert.ok(metrics.toolbarHeight >= 49 && Math.abs(metrics.toolbarHeight-metrics.measured) < 1, `${width}: measured shared toolbar`);
    assert.ok(Math.abs(metrics.toolbarTableGap) <= 1, `${width}: toolbar meets table header without a gap ${JSON.stringify(metrics)}`);
    assert.deepEqual([metrics.columnBackground,metrics.columnFontSize,metrics.columnWeight], ['rgb(227, 222, 213)','10px','700'], `${width}: approved column header`);
    assert.equal(metrics.searchHeight, 32, `${width}: approved search height`);
    assert.equal(metrics.gridOverflow, width <= 1700 ? 'auto' : 'visible', `${width}: approved wide table scroll variant`);
    if (width <= 1700) assert.ok(metrics.gridScroll > 0, `${width}: wide table scrolls inside card`);
    if (process.env.PLANEX_VISUAL_EVIDENCE_DIR) {
      mkdirSync(process.env.PLANEX_VISUAL_EVIDENCE_DIR, {recursive:true});
      await page.screenshot({path:path.join(process.env.PLANEX_VISUAL_EVIDENCE_DIR, `closing-${width}.png`)});
    }
    await page.close();
  }
  console.log('REGISTRY_CLOSING_RESPONSIVE_BROWSER=PASS');
} finally {
  await browser.close();
  await new Promise(resolve => server.close(resolve));
}
