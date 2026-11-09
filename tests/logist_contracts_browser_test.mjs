import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {readFileSync} from 'node:fs';
import http from 'node:http';
import path from 'node:path';
import {createRequire} from 'node:module';
import {fileURLToPath} from 'node:url';
import {assertRegistryRows,assertRegistrySearch,assertRegistryScroll} from './helpers/registry_behavior_contract.mjs';
import {assertRegistryVisualContract,assertRegistryDataContract,assertRegistryTableState} from './helpers/registry_visual_contract.mjs';

const require=createRequire(import.meta.url), {chromium}=require(process.env.PLANEX_PLAYWRIGHT_MODULE||'playwright');
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const render=(role,many=false)=>execFileSync(process.env.PLANEX_PHP||'php',[path.join(root,'tests/fixtures/logist_contracts_render.php'),role,...(many?['many']:[])],{encoding:'utf8'});
const html={owner:render('owner'),logist:render('logist'),long:render('logist',true)};
const server=http.createServer((req,res)=>{
  const url=new URL(req.url,'http://localhost');
  if(url.pathname.startsWith('/assets/')){
    const file=path.resolve(root,'public','.'+url.pathname);
    if(!file.startsWith(path.join(root,'public','assets')+path.sep)){res.statusCode=403;res.end();return;}
    res.setHeader('Content-Type',file.endsWith('.css')?'text/css':file.endsWith('.woff2')?'font/woff2':'text/javascript');res.end(readFileSync(file));return;
  }
  res.setHeader('Content-Type','text/html; charset=utf-8');res.end(html[url.searchParams.has('many')?'long':url.searchParams.get('role')==='owner'?'owner':'logist']);
});
await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
const browser=await chromium.launch({headless:true,...(process.env.PLANEX_BROWSER_BINARY?{executablePath:process.env.PLANEX_BROWSER_BINARY}:{})});
const origin=`http://127.0.0.1:${server.address().port}`;
try{
  for(const role of ['logist','owner']) for(const width of [1280,1920,390,3840]){
    const page=await browser.newPage({viewport:{width,height:900}}), errors=[];
    page.on('pageerror',error=>errors.push(error.message));
    await page.route('**/*',route=>route.request().url().startsWith(origin+'/')?route.continue():route.abort());
    await page.route('**/company/logistics/contracts/2',route=>route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,row:{...JSON.parse(html.logist.match(/<script type="application\/json" data-initial-rows>(.*?)<\/script>/s)[1])[0],id:2,contract_type:'lease',version_no:2,review_reason:null,logist_name:'Карина Г.',snapshot:{trip:{comment:'Вывоз отходов: Кемерово — Воскресенск'},executor:{name:'ООО «СибТранс»'},driver:{full_name:'Орлов Алексей Петрович'},parameters:{start_date:'2026-11-03',finish_date:'2026-11-10',price:'185000',vat_mode:'Без НДС'},vehicle:{model:'А123ВС77',plate:'А123ВС77'},requests:[{zayavka_id:'312015'}]}},versions:[{id:1,version_no:1,status:'rejected',review_reason:'Уточнить ставку'}]})}));
    await page.goto(`${origin}/company/logistics/contracts?role=${role}`);
    await page.waitForSelector('[data-registry-ready="true"]');
    for(const selector of ['[data-page-error]','[data-form-error]','[data-detail-error]']){
      assert.equal(await page.locator(selector).isVisible(),false,`hidden alert is visible ${role} ${width} ${selector}`);
    }
    await assertRegistryVisualContract(page,`logist-contracts ${role} ${width}`);
    const shellGap=await page.evaluate(()=>{
      const head=document.querySelector('.registry-ui-head').getBoundingClientRect();
      const card=document.querySelector('.registry-ui-card').getBoundingClientRect();
      return card.top-head.bottom;
    });
    assert.ok(Math.abs(shellGap-12)<=1,`approved 12px header/table separation ${role} ${width}: ${shellGap}`);
    await assertRegistryDataContract(page,'logist-contracts');
    if(role==='logist') await assertRegistryTableState(page,'logist-contracts','initial',{runtime:true});
    const rows=[
      {id:2,cells:[['#463','Версия 2','На согласовании'],['Вывоз отходов: Кемерово — Воск…','Аренда'],['03.11.2026'],['ООО «СибТранс»'],['Орлов Алексей Петрович'],['185000 ₽'],['Без НДС'],...(role==='owner'?[['Карина Г.']]:[])]},
      {id:1,cells:[['#459','Версия 1','Согласован'],['Сборный рейс: Томск — Новосиби…','Аренда'],['29.10.2026'],['ИП Кузнецов'],['Кузнецов Павел Андреевич'],['92000 ₽'],['Без НДС'],...(role==='owner'?[['Карина Г.']]:[])]},
    ];
    await assertRegistryRows(page,{rowSelector:'[data-registry-row-id]',rows},`logist-contracts ${role} ${width}`);
    await assertRegistrySearch(page,{rowSelector:'[data-registry-row-id]',probes:[{query:'СибТранс',ids:[2]},{query:'459',ids:[1]}],resolveActual:async p=>p.locator('[data-registry-row-id]').evaluateAll(items=>items.map(item=>item.dataset.registryRowId).sort())},`logist-contracts ${role} ${width}`);
    assert.equal(await page.locator('[data-rows] tr[data-id]').count(),2);
    assert.equal(await page.locator('thead th').count(),role==='owner'?8:7);
    if(width>390){
      const box=await page.locator('.registry-ui-card').boundingBox();
      const table=await page.locator('.logist-contract-table').boundingBox();
      assert.ok(table.width>=box.width-2 && (role==='owner' && width===1280 || Math.abs(box.width-table.width)<=2),`table does not fill card ${role} ${width}: ${box.width} / ${table.width}`);
    }
    const first=page.locator('[data-rows] tr[data-id="2"]');
    const before=await first.locator('td').first().evaluate(el=>getComputedStyle(el).backgroundColor);
    await first.hover();
    const after=await first.locator('td').first().evaluate(el=>getComputedStyle(el).backgroundColor);
    assert.equal(after,before,`hover changed row ${role} ${width}`);
    await first.dblclick();
    const dialog=page.locator('[data-detail]');await dialog.waitFor({state:'visible'});
    assert.match(await dialog.innerText(),/Прежние версии/i);
    if(width>700) assert.equal(await dialog.evaluate(el=>getComputedStyle(el.querySelector('.registry-ui-modal-grid--reading')).gridTemplateColumns.split(' ').length),2);
    assert.ok(await dialog.evaluate(el=>el.scrollWidth<=el.clientWidth+1),`dialog overflow ${role} ${width}`);
    assert.deepEqual(errors,[]);
    await page.close();
  }
  const scrollPage=await browser.newPage({viewport:{width:1280,height:900}});
  await scrollPage.goto(`${origin}/company/logistics/contracts?many=1`);
  await scrollPage.waitForSelector('[data-registry-ready="true"]');
  await assertRegistryScroll(scrollPage,'logist-contracts populated scroll');
  await scrollPage.close();
  const executorPage=await browser.newPage({viewport:{width:1280,height:900}});
  const duplicateExecutors=[
    {crew_id:11,contractor_name:'ИП Шашкина',driver_full_name:'Шашкин Максим Владимирович',primary_plate:'А111АА77'},
    {crew_id:22,contractor_name:'ИП Шашкина',driver_full_name:'Шашкин Максим Владимирович',primary_plate:'В222ВВ77'},
  ];
  const trip={id:9,business_id:420,comment:'Ставрополь',route_executor_id:22,planned_start_date:'2026-11-10'};
  const submitted=[];
  await executorPage.route('**/company/logistics/contracts/prepare',route=>route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,trips:[trip,{...trip,id:10,business_id:421,route_executor_id:null}],executors:duplicateExecutors})}));
  await executorPage.route('**/company/logistics/contracts/create',route=>{
    submitted.push(route.request().postData().match(/name="route_executor_id"\s+(\d+)/)?.[1]);
    return route.fulfill({status:422,contentType:'application/json',body:JSON.stringify({ok:false,message:'Проверка отправки'})});
  });
  await executorPage.goto(`${origin}/company/logistics/contracts?role=logist`);
  await executorPage.locator('[data-create]').click();
  await executorPage.locator('#lc-trip').fill('#420 — Ставрополь');
  assert.match(await executorPage.locator('#lc-executor').inputValue(),/В222ВВ77.*#22$/);
  await executorPage.locator('#lc-rate').fill('150000');
  await executorPage.locator('[data-form] [type="submit"]').click();
  assert.deepEqual(submitted,['22'],'assigned executor ID must survive duplicate names');
  await executorPage.locator('#lc-trip').fill('#421 — Ставрополь');
  const manualChoice=await executorPage.locator('#lc-executors option').nth(1).getAttribute('value');
  await executorPage.locator('#lc-executor').fill(manualChoice);
  await executorPage.locator('#lc-rate').fill('150000');
  await executorPage.locator('[data-form] [type="submit"]').click();
  assert.deepEqual(submitted,['22','22'],'manual duplicate choice must submit its own ID');
  await executorPage.close();
  console.log('REGISTRY_RUNTIME_PAGE=logist-contracts');
  console.log('LOGIST_CONTRACTS_BROWSER=PASS');
}finally{await browser.close();server.close();}
