import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {readFileSync,mkdirSync,writeFileSync} from 'node:fs';
import http from 'node:http';
import path from 'node:path';
import {createRequire} from 'node:module';
import {fileURLToPath} from 'node:url';
import {assertRegistryVisualContract,assertRegistryDataContract} from './helpers/registry_visual_contract.mjs';
const require=createRequire(import.meta.url),{chromium}=require(process.env.PLANEX_PLAYWRIGHT_MODULE||'playwright');
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const render=(kind,state='populated',role='company_owner')=>execFileSync(process.env.PLANEX_PHP||'php',[path.join(root,'tests/fixtures/company_registry_canon_render.php'),kind,state,role],{encoding:'utf8'});
const rendered=new Map(['contractors','vehicle-sets'].flatMap(kind=>['populated','empty','inactive','error'].map(state=>[kind+':'+state,render(kind,state)])));
rendered.set('contractors:logist',render('contractors','populated','logist'));
const server=http.createServer((req,res)=>{
 if(req.url.startsWith('/assets/')){const file=path.join(root,'public',req.url.split('?')[0]);res.setHeader('Content-Type',file.endsWith('.css')?'text/css':file.endsWith('.woff2')?'font/woff2':'text/javascript');res.end(readFileSync(file));return;}
 const url=new URL(req.url,'http://localhost'),kind=url.pathname.includes('vehicle-sets')?'vehicle-sets':'contractors';
 res.setHeader('Content-Type','text/html; charset=utf-8');res.end(rendered.get(kind+':'+(url.searchParams.get('state')||'populated')));
});
await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
const browser=await chromium.launch({headless:true});
const output=process.env.PLANEX_VISUAL_EVIDENCE_DIR,measurements=[];
try{
 for(const kind of ['contractors','vehicle-sets']){
  for(const width of [390,1280,1920,3840]){
   const page=await browser.newPage({viewport:{width,height:900}});
   const errors=[];page.on('pageerror',error=>errors.push(error.message));
   await page.goto(`http://127.0.0.1:${server.address().port}/company/${kind}`);
   await page.waitForSelector('[data-registry-ready="true"]');
   await assertRegistryVisualContract(page,`${kind} ${width}`);
   await assertRegistryDataContract(page,kind);
   const geometry=await page.evaluate(()=>{
    const box=selector=>document.querySelector(selector).getBoundingClientRect().toJSON();
    return {width:innerWidth,head:box('.registry-ui-head'),card:box('.registry-ui-card'),table:box('.registry-ui-table'),xScroll:document.querySelector('.table-scroll').scrollWidth-document.querySelector('.table-scroll').clientWidth};
   });
   assert.ok(Math.abs(geometry.head.left-geometry.card.left)<1&&Math.abs(geometry.head.right-geometry.card.right)<1,'header/card alignment');
   if(width===390)assert.ok(geometry.xScroll>0,'mobile internal horizontal scroll');
   if(width===3840)assert.ok(geometry.card.width<3000,'bounded workspace');
   if(output){mkdirSync(output,{recursive:true});await page.screenshot({path:path.join(output,`${kind}-${width}-normal.png`)});}
   const row=page.locator('tbody tr').first();
   await row.locator('td').first().hover();await page.waitForTimeout(150);
   assert.equal(await row.locator('td').first().evaluate(el=>getComputedStyle(el).backgroundColor),'rgb(236, 233, 226)','approved hover');
   if(output)await page.screenshot({path:path.join(output,`${kind}-${width}-hover.png`)});
   const search=page.locator('.registry-ui-search');await search.focus();
   assert.notEqual(await search.evaluate(el=>getComputedStyle(el).boxShadow),'none','focus visible');
   if(output)await page.screenshot({path:path.join(output,`${kind}-${width}-focus.png`)});
   const keyword=kind==='contractors'?'Восточный':'КамАЗ';
   await search.fill(keyword);assert.ok(Number(await page.locator('.found-label b').textContent())>0,'search finds business data');
   await search.fill('несуществующее значение');assert.equal(await page.locator('.found-label b').textContent(),'0');
   await page.locator('.registry-ui-reset').click();assert.equal(await page.locator('.found-label b').textContent(),'35');
   await page.locator('.lab-reference-trigger').click();await page.getByRole('listbox').press('End');await page.getByRole('listbox').press('Enter');
   assert.equal(await page.locator('select[data-erp-grid-sort]').inputValue(),'alpha');
   await search.fill(keyword);await page.reload();await page.waitForSelector('[data-registry-ready="true"]');
   assert.equal(await search.inputValue(),keyword,'search restored after reload');
   await page.locator('.registry-ui-reset').click();assert.equal(await page.locator('select[data-erp-grid-sort]').inputValue(),'date');
   assert.equal(await page.locator('tbody tr').first().getAttribute('data-erp-sort-date'),'74');
   const create=kind==='contractors'?'Создать перевозчика':'Добавить новый транспорт',modal=kind==='contractors'?'#le-contractor-modal':'#vehicle-set-create-modal';
   await page.getByRole('button',{name:create,exact:true}).first().click();assert.ok(await page.locator(modal).isVisible(),'create action preserved');
   await page.locator(modal+' .modal-close').click();
   const endpoint=kind==='contractors'?'contractors':'vehicle-sets';
   let requested=false;
   await page.route(`**/company/${endpoint}/74/modal-view`,route=>{requested=true;return route.fulfill({status:200,contentType:'text/html',body:'<div class="modal-body"><div class="driver-view-name">Карточка</div></div>'});});
   await page.locator(`.registry-ui-card tbody tr[data-${kind==='contractors'?'contractor':'vehicle-set'}-id="74"]`).first().dblclick();
   assert.ok(requested,'view endpoint preserved');
   assert.deepEqual(errors,[],`no runtime errors ${kind} ${width}`);
   measurements.push({kind,...geometry});await page.close();
  }
  for(const state of ['empty','inactive','error']){
   const page=await browser.newPage();await page.goto(`http://127.0.0.1:${server.address().port}/company/${kind}?state=${state}`);
   assert.equal(await page.locator('.registry-ui-head').count(),1);
   assert.equal(await page.locator('.registry-ui-card').count(),0);
   assert.equal(await page.locator('.registry-ui-head .btn-primary').count(),state==='empty'?1:0);
   await page.close();
  }
 }
 const page=await browser.newPage();
 await page.goto(`http://127.0.0.1:${server.address().port}/company/contractors?state=logist`);
 assert.equal(await page.locator('thead th').count(),4,'creator column stays owner-only');
 assert.equal((await page.locator('.registry-ui-card tbody tr').first().getAttribute('data-registry-search')).includes('Анна Петрова'),false,'creator does not leak into role-limited search index');
 await page.close();
 const blockedStorage=await browser.newPage();
 await blockedStorage.addInitScript(()=>{Storage.prototype.getItem=()=>{throw new Error('storage unavailable')};Storage.prototype.setItem=()=>{throw new Error('storage unavailable')};});
 await blockedStorage.goto(`http://127.0.0.1:${server.address().port}/company/vehicle-sets`);
 await blockedStorage.waitForSelector('[data-registry-ready="true"]');
 await blockedStorage.locator('.registry-ui-search').fill('несуществующее значение');
 await blockedStorage.locator('.registry-ui-reset').click();
 assert.equal(await blockedStorage.locator('.found-label b').textContent(),'35','filter/reset work without local storage');
 await blockedStorage.close();
 if(output)writeFileSync(path.join(output,'browser-metrics.json'),JSON.stringify(measurements,null,2));
 console.log('COMPANY_REGISTRY_CANON_BROWSER=PASS pages=contractors,vehicle-sets widths=390,1280,1920,3840');
}finally{await browser.close();await new Promise(resolve=>server.close(resolve));}
