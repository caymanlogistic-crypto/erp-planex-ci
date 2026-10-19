import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {readFileSync,mkdirSync,writeFileSync} from 'node:fs';
import http from 'node:http';
import path from 'node:path';
import {createRequire} from 'node:module';
import {fileURLToPath} from 'node:url';
import {assertRegistryVisualContract,assertRegistryDataContract} from './helpers/registry_visual_contract.mjs';
import {assertRegistryRows,assertRegistrySearch,assertRegistryScroll} from './helpers/registry_behavior_contract.mjs';
import {companyRegistryExpectations} from './fixtures/company_registry_expectations.mjs';
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
   if(width===1280){
    const shape=page.locator('.registry-ui-head .rp-identity-icon svg path').last();
    const original=await shape.getAttribute('d');
    await shape.evaluate(node=>node.setAttribute('d','M1 1l9 9'));
    await assert.rejects(assertRegistryVisualContract(page,`${kind} ${width}`),/exact named Tabler outline geometry/,`${kind}: arbitrary path under Tabler class rejected by common visual contract`);
    await shape.evaluate((node,d)=>node.setAttribute('d',d),original);
   }
   await assertRegistryDataContract(page,kind);
   await assertRegistryRows(page,companyRegistryExpectations(kind),kind);
   if(kind==='contractors'&&width===1280){
    // Prove the gate rejects omissions, broken filtering and lost stickiness.
    const contact=page.locator('[data-contractor-id="74"] td').nth(2),saved=await contact.innerHTML();
    await contact.evaluate(element=>element.replaceChildren());
    await assert.rejects(assertRegistryRows(page,companyRegistryExpectations(kind),'negative missing contact'),/missing or changed field/);
    await contact.evaluate((element,html)=>{element.innerHTML=html},saved);
    await page.evaluate(()=>{window.blockSearch=event=>event.stopImmediatePropagation();document.addEventListener('input',window.blockSearch,true)});
    await assert.rejects(assertRegistrySearch(page,{...companyRegistryExpectations(kind),probes:[{query:'Неактивен',ids:[41]}]},'negative search'),/actual filtered IDs/);
    await page.evaluate(()=>{document.removeEventListener('input',window.blockSearch,true)});
    await page.locator('.registry-ui-search').fill('');
    await page.locator('.registry-ui-toolbar').evaluate(element=>element.style.setProperty('position','static','important'));
    await assert.rejects(assertRegistryScroll(page,'negative sticky'),/sticky toolbar/);
    await page.locator('.registry-ui-toolbar').evaluate(element=>element.style.removeProperty('position'));
    await page.locator('main.content').evaluate(element=>{element.scrollTop=0});
    console.log('REGISTRY_BEHAVIOR_NEGATIVE=PASS missing-field,wrong-filter,lost-sticky rejected');
   }
   if(kind==='vehicle-sets'&&width===1280){
    const capacity=page.locator('[data-vehicle-set-id="74"] td.col-capacity span').first();
    await capacity.evaluate(element=>{element.textContent='120'});
    await assert.rejects(assertRegistryRows(page,companyRegistryExpectations(kind),'negative numeric corruption'),/missing or changed field/);
    await capacity.evaluate(element=>{element.textContent='20'});
    console.log('REGISTRY_NUMERIC_NEGATIVE=PASS 20-to-120 rejected');
   }
   await assertRegistryScroll(page,`${kind} ${width}`);
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
   await page.locator(modal+' .modal-close').first().click();
   const endpoint=kind==='contractors'?'contractors':'vehicle-sets';
   await page.route(`**/company/${endpoint}/74/modal-view`,route=>route.fulfill({status:200,contentType:'text/html',body:'<div class="modal-body"><div class="driver-view-name">Карточка</div></div>'}));
   const requested=page.waitForRequest(`**/company/${endpoint}/74/modal-view`);
   await page.locator(`.registry-ui-card tbody tr[data-${kind==='contractors'?'contractor':'vehicle-set'}-id="74"]`).first().dblclick();
   await requested;
   await page.getByText('Карточка',{exact:true}).waitFor();
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
 // Search must return the actual rows whose authorized rendered cells contain the query.
 // The explicit index may add complete values behind ellipsis, but cannot replace localized UI text.
 for(const [kind,queries] of [
  ['contractors',['Активен','Неактивен','Юридическое лицо','ИП','Физическое лицо','Восточный маршрут']],
  ['vehicle-sets',['Есть','НЕТ','КамАЗ 5490 А123АА77','Тонар 9888 В456ВВ77']],
 ]){
  const page=await browser.newPage();
  await page.goto(`http://127.0.0.1:${server.address().port}/company/${kind}`);
  await page.waitForSelector('[data-registry-ready="true"]');
  await assertRegistryRows(page,companyRegistryExpectations(kind),kind);
  await assertRegistrySearch(page,companyRegistryExpectations(kind),kind);
  const rows=page.locator('.registry-ui-card tbody tr');
  const baseline=await rows.evaluateAll((items,kind)=>items.map(tr=>({id:tr.getAttribute(kind==='contractors'?'data-contractor-id':'data-vehicle-set-id'),text:tr.textContent})),kind);
  for(const query of queries){
   const expected=baseline.filter(row=>row.text.toLocaleLowerCase('ru').includes(query.toLocaleLowerCase('ru'))).map(row=>row.id).sort();
   assert.ok(expected.length>0,`${kind}: fixture has visible ${query}`);
   await page.locator('.registry-ui-search').fill(query);
   const actual=await page.locator('.registry-ui-card tbody tr:not(.is-hidden)').evaluateAll((items,kind)=>items.map(tr=>tr.getAttribute(kind==='contractors'?'data-contractor-id':'data-vehicle-set-id')).sort(),kind);
   assert.deepEqual(actual,expected,`${kind}: search returns rendered rows for ${query}`);
  }
  const coverage=await page.evaluate(()=>{
   const grid=document.querySelector('.registry-ui-card');
   const input=grid.querySelector('.registry-ui-search');
   const rows=[...grid.querySelectorAll('tbody tr')];
   const probes=new Map(),failures=[];
   rows.forEach(row=>{
    const id=row.getAttribute('data-contractor-id')||row.getAttribute('data-vehicle-set-id');
    const cells=[...row.querySelectorAll('td')];
    for(const cell of cells){
     const leaves=cell.querySelectorAll('span').length?[...cell.querySelectorAll('span')]:[cell];
     for(const leaf of leaves){
      for(const value of [leaf.textContent.trim(),leaf.classList.contains('registry-ui-name')?leaf.getAttribute('title'):null].filter(Boolean)){
       if(!probes.has(value))probes.set(value,new Set());
       probes.get(value).add(id);
      }
     }
    }
    const docCells=[...row.querySelectorAll('td.col-check')];
    if(docCells.length){
     const index=(row.getAttribute('data-registry-search')||'').split(/\s+/);
     for(const state of ['Есть','НЕТ']){
      const rendered=docCells.filter(cell=>cell.textContent.trim()===state).length;
      const indexed=index.filter(token=>token===state).length;
      if(rendered!==indexed)failures.push(`${id}: ${state} in ${rendered} document cells but ${indexed} index tokens`);
     }
    }
   });
   for(const [value,ids] of probes){
    input.value=value;input.dispatchEvent(new Event('input',{bubbles:true}));
    for(const row of rows){
     const id=row.getAttribute('data-contractor-id')||row.getAttribute('data-vehicle-set-id');
     if(ids.has(id)&&row.classList.contains('is-hidden'))failures.push(`${id}: visible value ${value} not searchable`);
    }
   }
   return {probes:probes.size,failures};
  });
  assert.ok(coverage.probes>10,`${kind}: varied visible-value probes`);
  assert.deepEqual(coverage.failures,[],`${kind}: every visible value and all six document states searchable`);
  if(kind==='vehicle-sets'){
   assert.equal((await page.content()).includes('СКРЫТАЯ-МАРКА-ЕДИНИЦЫ'),false,'inaccessible secondary unit absent from HTML');
   await page.locator('.registry-ui-search').fill('СКРЫТАЯ-МАРКА-ЕДИНИЦЫ');
   assert.equal(await page.locator('.registry-ui-card tbody tr:not(.is-hidden)').count(),0,'inaccessible secondary unit absent from search');
  }
  await page.locator('.registry-ui-search').fill('нет такого значения 918273');
  assert.equal(await page.locator('.registry-ui-card tbody tr:not(.is-hidden)').count(),0,`${kind}: no-result rows`);
  await page.close();
 }
 const page=await browser.newPage();
 await page.goto(`http://127.0.0.1:${server.address().port}/company/contractors?state=logist`);
 assert.equal(await page.locator('thead th').count(),4,'creator column stays owner-only');
 assert.equal((await page.locator('.registry-ui-card tbody tr').first().getAttribute('data-registry-search')).includes('Анна Петрова'),false,'creator does not leak into role-limited search index');
 await page.locator('.registry-ui-search').fill('Анна Петрова');
 assert.equal(await page.locator('.registry-ui-card tbody tr:not(.is-hidden)').count(),0,'creator is not searchable for logist');
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
