import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {readFileSync} from 'node:fs';
import http from 'node:http';
import path from 'node:path';
import {createRequire} from 'node:module';
import {fileURLToPath} from 'node:url';

const require=createRequire(import.meta.url);
const {chromium}=require(process.env.PLANEX_PLAYWRIGHT_MODULE||'playwright');
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const html=execFileSync(process.env.PLANEX_PHP,[path.join(root,'tests/fixtures/trip_closing_design_lab_render.php')],{encoding:'utf8'});
const server=http.createServer((request,response)=>{
  if(request.url.startsWith('/assets/')){
    const file=path.join(root,'public',request.url.split('?')[0]);
    response.setHeader('Content-Type',file.endsWith('.css')?'text/css':'text/javascript');
    response.end(readFileSync(file));
    return;
  }
  response.setHeader('Content-Type','text/html; charset=utf-8');
  response.end(html);
});
await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
const browser=await chromium.launch({headless:true});
try{
  const page=await browser.newPage({viewport:{width:1419,height:900}});
  const errors=[];page.on('pageerror',error=>errors.push(error.message));
  await page.addInitScript(()=>localStorage.setItem('fixture:closing-registry',JSON.stringify({filter:'paid_complete',sort:'unloading'})));
  await page.goto(`http://127.0.0.1:${server.address().port}/company/logistics/closing-documents`);
  const table=page.locator('.closing-page .lab-table');
  assert.equal(await table.locator('thead th').count(),13);
  assert.equal(await table.locator('tbody tr').count(),35);
  assert.equal(await page.locator('[data-closing-filter="all"]').getAttribute('aria-pressed'),'true');
  assert.equal(await page.locator('[data-closing-count]').textContent(),'35');
  assert.equal(await page.locator('[data-closing-list-label]').textContent(),'РЕЙСЫ В РАБОТЕ И ЗАВЕРШЁННЫЕ');
  assert.equal(await page.locator('[data-closing-entity]').count(),0);
  assert.equal(await page.locator('[data-closing-sort]').inputValue(),'unloading');
  assert.equal(await table.locator('tbody tr').first().locator('td').first().textContent(),'#322');
  assert.equal(await page.locator('.closing-sort-field .lab-reference-trigger').evaluate(el=>getComputedStyle(el).backgroundImage),'none');
  await page.locator('.closing-sort-field .lab-reference-trigger').click();
  await page.locator('.closing-sort-field [role="option"][data-value="id"]').click();
  assert.equal(await table.locator('tbody tr').first().locator('td').first().textContent(),'#322');
  await table.locator('tbody tr').first().dblclick();
  const dialog=page.locator('[data-closing-doc-dialog]');
  assert.equal(await dialog.evaluate(el=>el.open),true);
  assert.equal(await dialog.locator('.closing-body-head h2').count(),0);
  assert.equal(await dialog.locator('.closing-trip-facts > span').count(),3);
  assert.equal(await dialog.locator('.closing-tn-details,.closing-finance-handoff').count(),0);
  await page.setViewportSize({width:390,height:844});
  assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth),390);
  assert.deepEqual(errors,[]);
  console.log('waste_trip_closing_registry_approved_browser: PASS');
}finally{
  await browser.close();
  await new Promise(resolve=>server.close(resolve));
}
