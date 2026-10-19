import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {readFileSync} from 'node:fs';
import http from 'node:http';
import path from 'node:path';
import {createRequire} from 'node:module';
import {fileURLToPath} from 'node:url';
import {assertRegistryRows,assertRegistrySearch} from './helpers/registry_behavior_contract.mjs';
import {assertRegistryTableState} from './helpers/registry_visual_contract.mjs';
const require=createRequire(import.meta.url),{chromium}=require(process.env.PLANEX_PLAYWRIGHT_MODULE||'playwright');
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const fixture=(...args)=>execFileSync(process.env.PLANEX_PHP||'php',[path.join(root,'tests/fixtures/company_registry_runtime.php'),...args],{encoding:'utf8',env:{...process.env,APP_ENV:'test'},maxBuffer:8*1024*1024});
assert.equal(fixture('seed'),'SEEDED');
const before=fixture('fingerprint'), cache=new Map(), requests=[];
let actor='owner';
const server=http.createServer((req,res)=>{
 try {
  const url=new URL(req.url,'http://localhost');
  if(req.method!=='GET')throw new Error('Only GET permitted; no fixture business writes');
  if(url.pathname.startsWith('/assets/')){
   const file=path.resolve(root,'public','.'+url.pathname);
   if(!file.startsWith(path.join(root,'public','assets')+path.sep))throw new Error('Invalid asset');
   res.setHeader('Content-Type',file.endsWith('.css')?'text/css':file.endsWith('.woff2')?'font/woff2':file.endsWith('.js')?'text/javascript':'application/octet-stream');res.end(readFileSync(file));return;
  }
  const key=actor+':'+url.pathname;
  if(!cache.has(key))cache.set(key,JSON.parse(fixture('render',url.pathname,actor)));
  const rendered=cache.get(key);requests.push(key);res.statusCode=rendered.status;res.setHeader('Content-Type','text/html; charset=utf-8');res.end(rendered.html);
 }catch(error){res.statusCode=500;res.end(String(error));}
});
let browser;
const contractorName=id=>`Перевозчик ${id} Полное название за многоточием`;
const errors=[];
const covered=new Set();
function assertDenied(status,body,{expectedStatus,message,forbidden}){
 assert.equal(status,expectedStatus,'expected normal access-denial response');
 assert.ok(body.includes(message),'expected domain denial message');
 for(const value of forbidden)assert.ok(!body.includes(value),'denied GET must not disclose '+value);
}
assert.throws(()=>assertDenied(500,'Ошибка загрузки',{expectedStatus:200,message:'Перевозчик не найден.',forbidden:[]}),/normal access-denial/,'500 cannot count as denied-access PASS');
try {
 await new Promise((resolve,reject)=>{server.once('error',reject);server.listen(0,'127.0.0.1',resolve)});
 const origin=`http://127.0.0.1:${server.address().port}`;
 browser=await chromium.launch({headless:true});
 for(actor of ['owner','member']) for(const kind of ['contractors','vehicle-sets']){
  const page=await browser.newPage({viewport:{width:1280,height:900}});
  page.on('pageerror',error=>errors.push(error.message));
  await page.route('**/*',route=>route.request().url().startsWith(origin+'/')?route.continue():route.abort());
  await page.goto(origin+'/company/'+kind);await page.waitForSelector('[data-registry-ready="true"]');
  await assertRegistryTableState(page,kind,'initial',{runtime:true});
  const html=await page.content();
  for(const asset of ['registry-components.css','registry-components.js','table-reference-select.js'])assert.ok(html.includes('/assets/'+(asset.endsWith('css')?'css/':'js/')+asset),`${kind}: controller composes ${asset}`);
  const rowSelector=`.registry-ui-card tbody tr[data-${kind==='contractors'?'contractor':'vehicle-set'}-id]`;
  const ids=kind==='contractors'?(actor==='owner'?[101,102,103,104,105]:[101,102,103]):(actor==='owner'?[301,302,303,304]:[301,302,303]);
  const actual=await page.locator(rowSelector).evaluateAll(rows=>rows.map(row=>Number(row.dataset.contractorId||row.dataset.vehicleSetId)).sort());
  assert.deepEqual(actual,ids,`${actor} ${kind}: real controller/service own-scope IDs`);
  if(kind==='contractors'){
   const rows=ids.map(id=>({id,cells:[[String(id)],[contractorName(id),'ИНН 7700000'+id+' · Юридическое лицо'],id===101?['Контакт 101','+70000000101']:id===102?['Контакт 102','mail102@example.test']:['—','Контакт не указан'],...(actor==='owner'?[['—']]:[]),['Активен']]}));
   await assertRegistryRows(page,{rowSelector,rows,forbidden:[contractorName(106),...(actor==='member'?[contractorName(104),contractorName(105)]:[])]},actor+' real contractors');
   if(actor==='owner')await assert.rejects(assertRegistryRows(page,{rowSelector,rows:rows.filter(row=>row.id<=103)},'negative owner-as-member fixture'),/authorized row IDs/);
   await assertRegistrySearch(page,{rowSelector,probes:[{query:'Юридическое лицо',ids},{query:'mail102@example.test',ids:[102]},{query:contractorName(101),ids:[101]},{query:contractorName(105),ids:actor==='owner'?[105]:[]}]},actor+' real contractors');
  }else{
   const hidden=actor==='member'?['Марка-204','Марка-205']:[];
   for(const value of hidden)assert.ok(!html.includes(value),`${actor}: unit column/index redaction ${value}`);
   await assertRegistrySearch(page,{rowSelector,probes:[{query:'Марка-201',ids:[301,303]},{query:'Марка-204',ids:actor==='owner'?[303,304]:[]},{query:'Есть',ids},{query:'НЕТ',ids:[301,303]}]},actor+' real vehicles');
   assert.deepEqual(await page.locator('[data-vehicle-set-id="301"] td.col-check').allTextContents().then(values=>values.map(value=>value.trim())),['Есть','НЕТ','Есть','—','—','—'],'soft-deleted document excluded from real service flags');
  }
  const scope=await page.locator('.registry-ui-card').getAttribute('data-registry-state-scope');
  assert.ok(scope,'real actor scope');
  cache.set(actor+':'+kind+':scope',scope);
  const isContractor=kind==='contractors',prefix=isContractor?'contractor':'vehicle-set',id=isContractor?101:301;
  const modal=isContractor?'#le-contractor-modal':'#vehicle-set-create-modal';
  await page.getByRole('button',{name:isContractor?'Создать перевозчика':'Добавить новый транспорт',exact:true}).first().click();
  const form=page.locator(isContractor?'#le-create-form':'#vehicle-set-create-form');
  await form.waitFor({state:'visible'});
  assert.match(await form.getAttribute('action'),new RegExp(`/company/${kind}/create$`));
  assert.ok(await form.locator(isContractor?'[name="name"]':'[name="set_type"]').count(),'real create fields');
  await page.locator(modal+' .modal-close').click();
  const row=page.locator(`${rowSelector}[data-${prefix}-id="${id}"]`);
  // Both advertised activation paths must load real server HTML, not a placeholder shell.
  for(const action of ['keyboard','doubleclick']){
   const loaded=page.waitForResponse(response=>response.url()===origin+`/company/${kind}/${id}/modal-view`);
   if(action==='keyboard'){await row.focus();await row.press('Enter')}else await row.dblclick();
   assert.equal((await loaded).status(),200);
   const edit=page.locator(`[data-${prefix}-edit-btn]`);await edit.waitFor({state:'visible'});
   await edit.click();const editForm=page.locator(isContractor?'#contractor-edit-form':'#vehicle-set-edit-form');await editForm.waitFor({state:'visible'});
   const value=await editForm.locator(isContractor?'[name="name"]':'[name="set_type"]').inputValue();
   assert.equal(value,isContractor?contractorName(id):'single','actual edit data survives adaptation');
   await page.locator(`[data-${prefix}-cancel-edit-btn]`).click();await edit.waitFor({state:'visible'});
   await page.locator(isContractor?'[data-contractor-view-close-btn]':'[data-vehicle-set-close-btn]').click();
  }
  if(isContractor&&actor==='owner'){
   await page.getByRole('link',{name:'Создать перевозчика + Водителя + Транспорт',exact:true}).click();
   const fullForm=page.locator('form[action$="/company/contractors/create-full"]');
   await fullForm.waitFor();assert.ok(await fullForm.locator('[name="contractor_mode"]').count());assert.ok(await fullForm.locator('[name="name"]').count(),'full-create link retains real GET form fields');
   await page.goBack();await page.waitForSelector('[data-registry-ready="true"]');
  }
  if(actor==='member'){
   for(const [uri,forbidden] of isContractor
      ? [['/company/contractors/105/modal-view',contractorName(105)],['/company/contractors/105/modal-edit',contractorName(105)]]
      : [['/company/vehicle-sets/303/modal-view','Марка-204'],['/company/vehicle-sets/303/modal-edit','vehicle-set-edit-form'],['/company/vehicle-sets/304/modal-view','Марка-204'],['/company/vehicle-sets/304/modal-edit','vehicle-set-edit-form']]){
    const response=await page.request.get(origin+uri),body=await response.text();assert.ok(!body.includes(forbidden),'restricted real GET does not disclose '+uri);
    if(isContractor)assertDenied(response.status(),body,{expectedStatus:200,message:'Перевозчик не найден.',forbidden:[forbidden]});
    if(!isContractor&& !uri.endsWith('303/modal-view'))assert.equal(response.status(),403,'real vehicle denial status '+uri);
    if(uri.endsWith('303/modal-view')){assert.ok(body.includes('Марка-201'));assert.ok(!body.includes('data-vehicle-set-edit-btn'));assert.ok(!body.includes('data-vehicle-set-delete-btn'));assert.ok(!body.includes('unit-204-doc'));assert.ok(body.includes('unit-201-doc-1'));assert.ok(!body.includes('unit-201-doc-2'));}
   }
  }
  covered.add(kind);await page.close();
 }
 for(const kind of ['contractors','vehicle-sets'])assert.notEqual(cache.get('owner:'+kind+':scope'),cache.get('member:'+kind+':scope'),'state storage separates real identities '+kind);
 assert.deepEqual(errors,[],'real forms/cards have no runtime errors');
 assert.equal(fixture('fingerprint'),before,'all business/ACL tables unchanged after GET interactions');
 assert.ok(requests.some(value=>value.endsWith('/modal-edit')),'actual edit requests executed');
 for(const id of covered)console.log('REGISTRY_RUNTIME_PAGE='+id);
 console.log('COMPANY_REGISTRY_RUNTIME_BROWSER=PASS real bootstrap/routes/controllers/services/layout; bounded own-scope; create/view/edit GET; immutable business/ACL tables');
}finally{try{await browser?.close()}finally{if(server.listening)await new Promise(resolve=>server.close(resolve))}}
