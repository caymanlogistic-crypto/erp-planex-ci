import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {readFileSync} from 'node:fs';
import http from 'node:http';
import path from 'node:path';
import {createRequire} from 'node:module';
import {fileURLToPath} from 'node:url';
import {assertRegistryVisualContract,assertRegistryDataContract,assertRegistryTableState} from './helpers/registry_visual_contract.mjs';
import {assertRegistryRows,assertRegistrySearch,assertRegistryScroll} from './helpers/registry_behavior_contract.mjs';

const require=createRequire(import.meta.url),{chromium}=require(process.env.PLANEX_PLAYWRIGHT_MODULE||'playwright');
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const fixture=(kind,state)=>execFileSync(process.env.PLANEX_PHP||'php',[path.join(root,'tests/fixtures/feo_history_canon_render.php'),kind,state],{encoding:'utf8',maxBuffer:8*1024*1024});
const pages=Object.fromEntries(['transfers','weights'].map(kind=>[kind,Object.fromEntries(['populated','empty','scroll'].map(state=>[state,fixture(kind,state)]))]));
const server=http.createServer((req,res)=>{
 const url=new URL(req.url,'http://localhost');
 if(url.pathname.startsWith('/assets/')){
   const file=path.resolve(root,'public','.'+url.pathname);
   if(!file.startsWith(path.join(root,'public','assets')+path.sep)){res.statusCode=403;res.end();return;}
   res.setHeader('Content-Type',file.endsWith('.css')?'text/css':file.endsWith('.woff2')?'font/woff2':'text/javascript');res.end(readFileSync(file));return;
 }
 const kind=url.pathname.endsWith('/weights')?'weights':'transfers';
 const state=url.searchParams.get('state');
 res.setHeader('Content-Type','text/html; charset=utf-8');res.end(pages[kind][state==='empty'||state==='scroll'?state:'populated']);
});
await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
const browser=await chromium.launch({headless:true});
try{
 for(const [kind,id] of [['transfers','feo-transfers'],['weights','feo-weights']]){
  const base=`http://127.0.0.1:${server.address().port}/company/feo/requests/${kind}`;
  for(const width of [390,1280,1920,3840]){
   const page=await browser.newPage({viewport:{width,height:900}}),errors=[];
   page.on('pageerror',error=>errors.push(error.message));
   await page.goto(base);await page.waitForSelector('[data-registry-ready="true"]');
   await assertRegistryVisualContract(page,`${id} ${width}`);
   await assertRegistryDataContract(page,id);
   if(kind==='transfers'){
     const scroll=await browser.newPage({viewport:{width,height:900}});
     await scroll.goto(base+'?state=scroll');await scroll.waitForSelector('[data-registry-ready="true"]');
     await assertRegistryScroll(scroll,`${id} ${width}`);await scroll.close();
   }else await assertRegistryScroll(page,`${id} ${width}`);
   if(kind==='transfers'){
     assert.equal(await page.locator('[data-batch-row]:visible').count(),4,'returned transfers hidden by default');
     assert.equal(await page.locator('[data-history-count]').innerText(),'4');
     assert.equal(await page.locator('[data-show-returned]').isChecked(),false);
     assert.equal(await page.locator('.registry-ui-reset').innerText(),'Сбросить фильтр');
     const row=page.locator('[data-registry-row-id="118"]');
     assert.match(await row.locator('td').nth(1).innerText(),/^320648,/);
     assert.equal(await row.locator('td').nth(2).innerText(),'12');
     assert.equal(await row.locator('[data-total-weight]').innerText(),'6 737 кг');
     assert.match(await row.locator('td').nth(4).innerText(),/^По адресу заявки 329001\nРоссия,/);
     await page.locator('.registry-ui-toolbar .lab-reference-trigger').click();
     await page.getByRole('option',{name:'Брутто'}).click();
     assert.equal(await row.locator('[data-total-weight]').innerText(),'7 015 кг');
     await page.locator('[data-show-returned]').check();
     assert.equal(await page.locator('[data-batch-row]:visible').count(),6);
     await assertRegistrySearch(page,{rowSelector:'[data-batch-row]',probes:[{query:'317812',ids:['116']},{query:'Таёжная',ids:['118']}],resolveActual:async p=>p.locator('[data-batch-row]:visible').evaluateAll(rows=>rows.map(row=>row.dataset.registryRowId).sort())},id);
     await page.locator('.registry-ui-reset').click();
     assert.equal(await page.locator('[data-batch-row]:visible').count(),4,'reset restores default filter');
     assert.equal(await page.locator('[data-history-count]').innerText(),'4');
     assert.equal(await page.locator('[data-weight-mode]').inputValue(),'net');
   }else{
     assert.equal(await page.locator('[data-show-returned]').isChecked(),false);
     assert.equal(await page.locator('[data-batch-row]:visible').count(),62,'returned weights hidden by default');
     await assertRegistrySearch(page,{rowSelector:'[data-batch-row]',probes:[{query:'#18',ids:['18']},{query:'100001',ids:['18']}],resolveActual:async p=>p.locator('[data-batch-row]:visible').evaluateAll(rows=>rows.map(row=>row.dataset.registryRowId).sort())},id);
     await page.locator('[data-history-search]').fill('#18');
     const row=page.locator('[data-batch-row]:visible');
     assert.equal(await row.count(),1);
     await assertRegistryRows(page,{rowSelector:'[data-batch-row]:visible',rows:[{id:18,cells:[['#18'],[Array.from({length:12},(_,i)=>String(100001+i)).join(', ')],['12'],['—'],['0 кг'],['18.10.2026 15:30'],['Владимир С.']]}]},id);
     await page.locator('.registry-ui-toolbar .lab-reference-trigger').click();
     await page.getByRole('option',{name:'Брутто'}).click();
     assert.equal(await row.locator('[data-total-before]').innerText(),'1 500 кг');
     assert.equal(await row.locator('[data-total-after]').innerText(),'2 400 кг');
     await page.locator('[data-show-returned]').check();
     await assertRegistrySearch(page,{rowSelector:'[data-batch-row]',probes:[{query:'#17',ids:['17']}],resolveActual:async p=>p.locator('[data-batch-row]:visible').evaluateAll(rows=>rows.map(row=>row.dataset.registryRowId).sort())},id);
     await page.locator('.registry-ui-reset').click();
     assert.equal(await page.locator('[data-batch-row]:visible').count(),62,'reset restores default filter');
     assert.equal(await page.locator('[data-weight-mode]').inputValue(),'net');
   }
   assert.deepEqual(errors,[],`${id} page errors`);
   await page.close();
  }
  for(const width of [390,1280,1920,3840]){
   const empty=await browser.newPage({viewport:{width,height:900}});
   await empty.goto(base+'?state=empty');await empty.waitForSelector('[data-registry-ready="true"]');
   await assertRegistryTableState(empty,id,'initial',{fixture:'empty'});
   assert.match(await empty.locator('.transfer-empty').innerText(),/пока нет/);
   await empty.close();
  }
 }
 console.log('FEO_HISTORY_CANON_BROWSER=PASS');
}finally{await browser.close();await new Promise(resolve=>server.close(resolve));}
