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
const rendered=new Map(['populated','empty','inactive','error'].map(state=>[state,execFileSync(process.env.PLANEX_PHP||'php',[path.join(root,'tests/fixtures/drivers_registry_render.php'),state],{encoding:'utf8'})]));
const server=http.createServer((req,res)=>{
 if(req.url.startsWith('/assets/')){const file=path.join(root,'public',req.url.split('?')[0]);res.setHeader('Content-Type',file.endsWith('.css')?'text/css':file.endsWith('.woff2')?'font/woff2':'text/javascript');res.end(readFileSync(file));return;}
 res.setHeader('Content-Type','text/html; charset=utf-8');res.end(rendered.get(new URL(req.url,'http://localhost').searchParams.get('state'))||rendered.get('populated'));
});
await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
const browser=await chromium.launch({headless:true});
const output=process.env.PLANEX_VISUAL_EVIDENCE_DIR;
const measurements=[];
try{
 for(const width of [390,700,701,900,1099,1100,1280,1920,3840]){
  const page=await browser.newPage({viewport:{width,height:900}});
  await page.goto(`http://127.0.0.1:${server.address().port}/company/drivers`);
  await page.waitForSelector('[data-registry-ready="true"]');
  const reset=page.locator('.registry-ui-reset');
  assert.equal(await page.locator('.toolbar-right > :first-child').getAttribute('class'),'registry-ui-reset','reset precedes sort and search');
  assert.equal(await reset.textContent(),'Сбросить фильтр');
  assert.equal(await reset.isHidden(),true);
  assert.equal(await page.getByRole('button',{name:'Сбросить фильтр'}).count(),0,'hidden reset absent from accessibility tree');
  assert.equal(await reset.evaluate(el=>{el.focus();return document.activeElement===el}),false,'hidden reset cannot receive focus');
  const geometry=()=>page.evaluate(()=>{
   const rect=selector=>{const {x,y,width,height,right,bottom}=document.querySelector(selector).getBoundingClientRect();return {x,y,width,height,right,bottom};};
   return {toolbar:rect('.registry-ui-toolbar'),sort:rect('.lab-reference-trigger'),search:rect('.registry-ui-search'),reset:rect('.registry-ui-reset'),right:rect('.toolbar-right'),card:rect('.registry-ui-card'),documentOverflow:document.documentElement.scrollWidth-document.documentElement.clientWidth};
  });
  const before=await geometry();
  await page.locator('.registry-ui-search').fill('несуществующий водитель');
  assert.equal(await page.getByRole('button',{name:'Сбросить фильтр'}).count(),1,'visible reset exposed to accessibility tree');
  const after=await geometry();
  for(const control of ['sort','search'])for(const coordinate of ['x','y'])
   assert.ok(Math.abs(before[control][coordinate]-after[control][coordinate])<1,`${control} ${coordinate} stable at ${width}: ${before[control][coordinate]} -> ${after[control][coordinate]}`);
  assert.ok(Math.abs(before.toolbar.height-after.toolbar.height)<1,`toolbar height stable at ${width}`);
  assert.ok(after.documentOverflow<=1,`no document horizontal overflow at ${width}`);
  for(const [left,right] of [[after.reset,after.sort],[after.reset,after.search],[after.sort,after.search]])
   assert.ok(Math.min(left.right,right.right)-Math.max(left.x,right.x)<=1||Math.min(left.bottom,right.bottom)-Math.max(left.y,right.y)<=1,`toolbar controls do not overlap at ${width}`);
  await reset.focus();
  assert.notEqual(await reset.evaluate(el=>getComputedStyle(el).outlineStyle),'none',`reset focus visible at ${width}`);
  await page.keyboard.press('Enter');
  assert.equal(await page.locator('.registry-ui-search').inputValue(),'');
  assert.equal(await reset.isHidden(),true);
  await page.close();
 }
 for(const width of [390,1280,1920,3840]){
  const page=await browser.newPage({viewport:{width,height:900}});
  const errors=[];page.on('pageerror',error=>errors.push(error.message));
  await page.goto(`http://127.0.0.1:${server.address().port}/company/drivers`);
  await page.waitForSelector('[data-registry-ready="true"]');
  await assertRegistryVisualContract(page,`drivers ${width}`);await assertRegistryDataContract(page,'drivers');
  assert.equal(await page.locator('thead th').count(),8);
  const geometry=await page.evaluate(()=>{
   const box=selector=>document.querySelector(selector).getBoundingClientRect().toJSON();
   return {layout:getComputedStyle(document.querySelector('.registry-ui-table')).tableLayout,width:innerWidth,head:box('.registry-ui-head'),card:box('.registry-ui-card'),toolbar:box('.registry-ui-toolbar'),table:box('.registry-ui-table'),xScroll:document.querySelector('.table-scroll').scrollWidth-document.querySelector('.table-scroll').clientWidth};
  });
  assert.ok(Math.abs(geometry.head.left-geometry.card.left)<1&&Math.abs(geometry.head.right-geometry.card.right)<1,'shared header/card alignment');
  if(width>=1280)assert.equal(geometry.xScroll,0,'business columns fit desktop frame');
  if(width===390)assert.ok(geometry.xScroll>0,'mobile uses internal scroll without losing columns');
  if(width===390){
   assert.equal(await page.locator('.table-scroll').evaluate(el=>getComputedStyle(el).overflowX),'auto','mobile table actually scrolls');
   assert.equal(await page.locator('.table-scroll').getAttribute('tabindex'),'0','horizontal scroll is keyboard accessible');
  }
  if(width===3840)assert.ok(geometry.card.width<3000,'bounded wide workspace');
  assert.equal(geometry.layout,'fixed','shared geometry wins over legacy auto layout');
  if(output){mkdirSync(output,{recursive:true});await page.screenshot({path:path.join(output,`drivers-${width}-normal.png`)});}
  const row=page.locator('tr[data-driver-id="40"]');
  await row.locator('td').first().hover();
  await page.waitForTimeout(150);
  const sticky=await page.evaluate(()=>({bar:document.querySelector('.registry-ui-toolbar').getBoundingClientRect().toJSON(),head:document.querySelector('thead').getBoundingClientRect().toJSON(),topbar:document.querySelector('.topbar').getBoundingClientRect().bottom}));
  assert.ok(Math.abs(sticky.bar.top-sticky.topbar)<1,'toolbar remains directly below ERP topbar');
  assert.ok(Math.abs(sticky.head.top-sticky.bar.bottom)<1,'real thead remains directly below toolbar');
  assert.equal(await row.locator('td').first().evaluate(el=>getComputedStyle(el).backgroundColor),'rgb(236, 233, 226)','approved row hover');
  if(output)await page.screenshot({path:path.join(output,`drivers-${width}-hover.png`)});
  const search=page.locator('.registry-ui-search');await search.focus();
  assert.notEqual(await search.evaluate(el=>getComputedStyle(el).boxShadow),'none','visible keyboard focus');
  if(output){mkdirSync(output,{recursive:true});await page.screenshot({path:path.join(output,`drivers-${width}-focus.png`)});}
  measurements.push(geometry);
  await search.fill('УникальноеОкончание');assert.equal(await page.locator('tbody tr:not(.is-hidden)').count(),34,'search includes full passport tail');
  await search.fill('несуществующий водитель');assert.equal(await page.locator('.found-label b').textContent(),'0');
  await page.locator('.registry-ui-reset').click();assert.equal(await page.locator('tbody tr:not(.is-hidden)').count(),35);assert.equal(await search.inputValue(),'');
  await page.locator('.lab-reference-trigger').click();await page.getByRole('listbox').press('End');await page.getByRole('listbox').press('Enter');
  assert.equal(await page.locator('select[data-erp-grid-sort]').inputValue(),'alpha');
  assert.match(await page.locator('tbody tr').first().textContent(),/Александрова/,'alphabet sort uses FIO, not ID');
  await search.fill('Александрова');await page.reload();await page.waitForSelector('[data-registry-ready="true"]');
  assert.equal(await search.inputValue(),'Александрова','search restored after reload');assert.equal(await page.locator('select[data-erp-grid-sort]').inputValue(),'alpha');
  await page.locator('.registry-ui-reset').click();assert.equal(await page.locator('select[data-erp-grid-sort]').inputValue(),'date');assert.equal(await page.locator('tbody tr').first().getAttribute('data-driver-id'),'74');
  await page.getByRole('button',{name:'Добавить нового водителя',exact:true}).first().click();assert.ok(await page.locator('#driver-create-modal').isVisible(),'create modal remains functional');
  await page.locator('#driver-create-modal .modal-close').click();
  await page.route('**/company/drivers/74/modal-view',route=>route.fulfill({status:200,contentType:'text/html',body:'<div class="modal-body"><div class="driver-view-name">Яковлев Сергей Александрович</div><button data-driver-edit-btn>Редактировать</button><button data-driver-delete-btn>Удалить</button></div>'}));
  const modalRequested=page.waitForRequest('**/company/drivers/74/modal-view');
  await page.locator('tr[data-driver-id="74"]').dblclick();await modalRequested;await page.locator('#driver-view-modal [data-driver-edit-btn]').waitFor();assert.ok(await page.locator('#driver-view-modal').isVisible());
  await page.route('**/company/drivers/74/modal-edit',route=>route.fulfill({status:200,contentType:'text/html',body:'<div class="modal-body"><form id="driver-edit-form"><input name="full_name" value="Яковлев Сергей Александрович"><button data-driver-cancel-edit-btn type="button">Отмена</button></form></div>'}));
  await page.locator('[data-driver-edit-btn]').click();await page.locator('#driver-edit-form').waitFor();
  await page.locator('[data-driver-cancel-edit-btn]').click();await page.locator('[data-driver-delete-btn]').waitFor();
  await page.locator('[data-driver-delete-btn]').click();assert.ok(await page.locator('#driver-delete-confirm-modal').isVisible(),'existing deletion confirmation available without deleting data');
  assert.deepEqual(errors,[],`no runtime errors ${width}`);await page.close();
 }
 for(const state of ['empty','inactive','error']){
  const page=await browser.newPage();await page.goto(`http://127.0.0.1:${server.address().port}/company/drivers?state=${state}`);
  assert.equal(await page.locator('.registry-ui-head').count(),1);assert.equal(await page.locator('.registry-ui-card').count(),0);
  assert.equal(await page.locator('#driver-create-modal').count(),state==='empty'?1:0);await page.close();
 }
 const blockedStorage=await browser.newPage();
 await blockedStorage.addInitScript(()=>{Storage.prototype.getItem=()=>{throw new Error('storage unavailable')};Storage.prototype.setItem=()=>{throw new Error('storage unavailable')};});
 await blockedStorage.goto(`http://127.0.0.1:${server.address().port}/company/drivers`);await blockedStorage.waitForSelector('[data-registry-ready="true"]');
 await blockedStorage.locator('.registry-ui-search').fill('несуществующий водитель');await blockedStorage.locator('.registry-ui-reset').click();assert.equal(await blockedStorage.locator('.found-label b').textContent(),'35','filter/reset work without storage');await blockedStorage.close();
 if(output)writeFileSync(path.join(output,'browser-metrics.json'),JSON.stringify(measurements,null,2));
 console.log('DRIVERS_REGISTRY_BROWSER=PASS widths=390,1280,1920,3840 normal/hover/focus search/sort/reset/reload/create/view/empty/inactive/error');
}finally{await browser.close();await new Promise(resolve=>server.close(resolve));}








