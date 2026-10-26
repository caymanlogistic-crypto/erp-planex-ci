import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {readFileSync} from 'node:fs';
import http from 'node:http';
import path from 'node:path';
import {createRequire} from 'node:module';
import {fileURLToPath} from 'node:url';
import {assertRegistryTableState} from './helpers/registry_visual_contract.mjs';

const require=createRequire(import.meta.url),{chromium}=require(process.env.PLANEX_PLAYWRIGHT_MODULE||'playwright');
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const fixture=(...args)=>execFileSync(process.env.PLANEX_PHP||'php',[path.join(root,'tests/fixtures/feo_history_runtime.php'),...args],{encoding:'utf8',env:{...process.env,APP_ENV:'test',LEGACY_FLIGHTS_DB_HOST:'',LEGACY_FLIGHTS_DB_DATABASE:'',LEGACY_FLIGHTS_DB_USERNAME:'',LEGACY_FLIGHTS_DB_PASSWORD:''},maxBuffer:8*1024*1024});
assert.equal(fixture('seed'),'SEEDED');
const before=fixture('fingerprint'),cache=new Map();
const server=http.createServer((req,res)=>{
 try{
  const url=new URL(req.url,'http://localhost');
  if(req.method!=='GET')throw new Error('Only GET permitted');
  if(url.pathname.startsWith('/assets/')){
   const file=path.resolve(root,'public','.'+url.pathname);
   if(!file.startsWith(path.join(root,'public','assets')+path.sep))throw new Error('Invalid asset');
   res.setHeader('Content-Type',file.endsWith('.css')?'text/css':file.endsWith('.woff2')?'font/woff2':'text/javascript');res.end(readFileSync(file));return;
  }
  const kind=url.pathname.endsWith('/weights')?'weights':'transfers',actor=url.searchParams.get('actor')||'owner',key=kind+':'+actor;
  if(!cache.has(key))cache.set(key,JSON.parse(fixture('render',kind,actor)));
  const rendered=cache.get(key);res.statusCode=rendered.status;res.setHeader('Content-Type','text/html; charset=utf-8');res.end(rendered.html);
 }catch(error){res.statusCode=500;res.end(String(error));}
});
let browser;
try{
 await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
 browser=await chromium.launch({headless:true});
 const origin=`http://127.0.0.1:${server.address().port}`;
 for(const kind of ['transfers','weights']){
  const id='feo-'+kind,url=origin+'/company/feo/requests/'+kind;
  for(const width of [390,1280,1920,3840]){
   const page=await browser.newPage({viewport:{width,height:900}}),errors=[];
   page.on('pageerror',error=>errors.push(error.message));
   await page.route('**/*',route=>route.request().url().startsWith(origin+'/')?route.continue():route.abort());
   const response=await page.goto(url);assert.equal(response.status(),200);
   await page.waitForSelector('[data-registry-ready="true"]');
   await assertRegistryTableState(page,id,'initial',{runtime:true});
   assert.equal(await page.locator('[data-registry-row-id="18"]').count(),1,'real route returns seeded history');
   if(kind==='transfers'){
     assert.equal(await page.locator('[data-registry-row-id="18"] [data-detail][role="button"][tabindex="0"]').count(),1,'ID opens real history');
     assert.match(await page.locator('[data-registry-row-id="18"]').innerText(),/100001.*100012/s,'complete request list from tenant database');
     assert.equal(await page.locator('[data-registry-row-id="18"] [data-total-weight]').innerText(),'15 000 кг','current tenant net mass in kilograms');
     await page.locator('.registry-ui-toolbar .lab-reference-trigger').click();
     await page.getByRole('option',{name:'Брутто'}).click();
     assert.equal(await page.locator('[data-registry-row-id="18"] [data-total-weight]').innerText(),'18 000 кг');
   }else{
     assert.equal(await page.locator('[data-registry-row-id="18"] [data-detail][role="button"][tabindex="0"]').count(),1,'ID opens weight history');
     assert.match(await page.locator('[data-registry-row-id="18"]').innerText(),/100001.*100012/s,'complete request list from weight items');
     assert.equal(await page.locator('[data-registry-row-id="18"] [data-total-before]').innerText(),'15 000 кг');
     assert.equal(await page.locator('[data-registry-row-id="18"] [data-total-after]').innerText(),'15 600 кг');
     await page.locator('.registry-ui-toolbar .lab-reference-trigger').click();
     await page.getByRole('option',{name:'Брутто'}).click();
     assert.equal(await page.locator('[data-registry-row-id="18"] [data-total-before]').innerText(),'18 000 кг');
     assert.equal(await page.locator('[data-registry-row-id="18"] [data-total-after]').innerText(),'20 400 кг');
   }
   await page.locator('[data-history-search]').fill('несуществующий блок');
   assert.equal(await page.locator('[data-batch-row]:visible').count(),0,'real page search filters rows');
   await page.locator('.registry-ui-reset').click();
   assert.equal(await page.locator('[data-batch-row]:visible').count(),1,'reset restores rows');
   assert.deepEqual(errors,[],id+' real route errors');
   await page.close();
  }
  const denied=await browser.newPage();const response=await denied.goto(url+'?actor=denied');
  assert.equal(response.status(),403);assert.ok(!(await denied.content()).includes('Перемещение на склад'));await denied.close();
  const member=await browser.newPage();await member.goto(url+'?actor=member');
  assert.equal(await member.locator('[data-registry-row-id="18"]').count(),1,'member grant reads history');await member.close();
  console.log('REGISTRY_RUNTIME_PAGE='+id);
 }
 assert.equal(fixture('fingerprint'),before,'read-only runtime checks preserve business data');
 console.log('FEO_HISTORY_RUNTIME_BROWSER=PASS real route/controller/service/layout, member access and denied page gate');
}finally{try{await browser?.close()}finally{if(server.listening)await new Promise(resolve=>server.close(resolve))}}
