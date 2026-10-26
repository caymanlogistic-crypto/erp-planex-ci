import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {mkdirSync,readFileSync} from 'node:fs';
import http from 'node:http';
import path from 'node:path';
import {createRequire} from 'node:module';
import {fileURLToPath} from 'node:url';
import {assertRegistryRows,assertRegistrySearch,assertRegistryScroll} from './helpers/registry_behavior_contract.mjs';
import {assertRegistryVisualContract,assertRegistryDataContract,assertRegistryTableState} from './helpers/registry_visual_contract.mjs';

const require=createRequire(import.meta.url),{chromium}=require(process.env.PLANEX_PLAYWRIGHT_MODULE||'playwright');
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const fixture=(...args)=>execFileSync(process.env.PLANEX_PHP||'php',[path.join(root,'tests/fixtures/warehouse_directory_runtime.php'),...args],{encoding:'utf8',env:{...process.env,APP_ENV:'test'},maxBuffer:8*1024*1024});
assert.equal(fixture('seed'),'SEEDED');
const before=fixture('fingerprint');
const rendered=Object.fromEntries(['owner','logist','denied'].map(role=>[role,JSON.parse(fixture('render',role))]));
assert.equal(rendered.denied.status,403);
assert.ok(!rendered.denied.html.includes('Склад Север'));
let actor='owner';
const server=http.createServer((req,res)=>{
  try {
    if(req.method!=='GET')throw new Error('Only GET permitted');
    const url=new URL(req.url,'http://localhost');
    if(url.pathname.startsWith('/assets/')){
      const file=path.resolve(root,'public','.'+url.pathname);
      if(!file.startsWith(path.join(root,'public','assets')+path.sep))throw new Error('Invalid asset');
      res.setHeader('Content-Type',file.endsWith('.css')?'text/css':file.endsWith('.js')?'text/javascript':'font/woff2');
      res.end(readFileSync(file));return;
    }
    res.statusCode=rendered[actor].status;
    res.setHeader('Content-Type','text/html; charset=utf-8');res.end(rendered[actor].html);
  }catch(error){res.statusCode=500;res.end(String(error));}
});
await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
const browser=await chromium.launch({headless:true});
const origin=`http://127.0.0.1:${server.address().port}`;
const expectedRows=[
  {id:7,cells:[['#7'],['Склад Север'],['Москва, Складская улица, 1, корпус 2'],['2 контакта']]},
  {id:8,cells:[['#8'],['Склад Юг'],['Тула, Южная улица, 2'],['0 контактов']]},
  ...Array.from({length:49},(_,index)=>{
    const id=index+9;return {id,cells:[[`#${id}`],[`Склад ${id}`],[`Регион, Промышленная улица, ${id}`],['0 контактов']]};
  }),
  {id:58,cells:[['#58'],['Склад без адреса'],['—'],['0 контактов']]},
];
try {
  for(actor of ['owner','logist']) for(const width of [390,1280,1920,3840]){
    const page=await browser.newPage({viewport:{width,height:900}}),errors=[];
    page.on('pageerror',error=>errors.push(error.message));
    await page.route('**/*',route=>route.request().url().startsWith(origin+'/')?route.continue():route.abort());
    await page.goto(origin+'/company/warehouses/addresses');
    await page.waitForSelector('[data-registry-ready="true"]');
    await assertRegistryVisualContract(page,`warehouse-addresses ${actor} ${width}`);
    await assertRegistryDataContract(page,'warehouse-addresses');
    await assertRegistryTableState(page,'warehouse-addresses','initial',{runtime:true});
    assert.equal(await page.locator('[data-warehouse-row]').count(),52);
    await assertRegistryRows(page,{rowSelector:'[data-warehouse-row]',rows:expectedRows,forbidden:['Чужой склад']},`warehouse-addresses ${actor}`);
    await assertRegistryScroll(page,`warehouse-addresses ${actor} ${width}`);
    await assertRegistrySearch(page,{rowSelector:'[data-warehouse-row]',probes:[
      {query:'Петров',ids:[7]},{query:'Тула',ids:[8]},{query:'Склад 10',ids:[10]},{query:'не существует',ids:[]}
    ],resolveActual:async p=>p.locator('[data-warehouse-row]:visible').evaluateAll(rows=>rows.map(row=>row.dataset.registryRowId).sort())},`warehouse-addresses ${actor}`);
    await page.locator('[data-registry-field="query"]').fill('Петров');
    assert.deepEqual(await page.locator('[data-warehouse-row]:visible').evaluateAll(rows=>rows.map(row=>row.dataset.registryRowId)),['7']);
    await page.locator('.registry-ui-reset').click();
    assert.equal(await page.locator('[data-warehouse-row]:visible').count(),52);
    await page.locator('[data-warehouse-open="7"]').focus();
    await page.keyboard.press('Enter');
    const dialog=page.locator('[data-warehouse-dialog="7"]');
    assert.equal(await dialog.evaluate(el=>el.open),true);
    const dialogOverflow=await dialog.evaluate(el=>el.scrollWidth-el.clientWidth);
    assert.ok(dialogOverflow<=1,`dialog horizontal overflow ${actor} ${width}: ${dialogOverflow}`);
    if(actor==='owner'){
      assert.deepEqual(await dialog.locator('input[name="contact_name[]"]').evaluateAll(inputs=>inputs.map(input=>input.value)),['Иванов Иван','Петров Пётр']);
      assert.equal(await dialog.locator('input[name="address"]').inputValue(),'Москва, Складская улица, 1, корпус 2');
      await dialog.locator('[data-warehouse-add-contact]').click();
      assert.equal(await dialog.locator('[data-warehouse-contact]').count(),3);
      await dialog.locator('[data-warehouse-remove-contact]').last().click();
      assert.equal(await dialog.locator('[data-warehouse-contact]').count(),2);
    }else {
      assert.equal(await dialog.locator('form').count(),0);
      assert.match(await dialog.innerText(),/Иванов Иван/);
      assert.match(await dialog.innerText(),/Петров Пётр/);
    }
    const overflow=await page.evaluate(()=>document.documentElement.scrollWidth>window.innerWidth+1);
    assert.equal(overflow,false,`outer horizontal scroll ${width}`);
    if(process.env.PLANEX_WAREHOUSE_SCREENSHOT_DIR && actor==='owner'){
      mkdirSync(process.env.PLANEX_WAREHOUSE_SCREENSHOT_DIR,{recursive:true});
      await page.screenshot({path:path.join(process.env.PLANEX_WAREHOUSE_SCREENSHOT_DIR,`warehouse-${width}.png`),fullPage:false});
    }
    assert.deepEqual(errors,[]);
    await page.close();
  }
  assert.equal(fixture('fingerprint'),before,'read-only browser test changed business data');
  console.log('REGISTRY_RUNTIME_PAGE=warehouse-addresses');
  console.log('WAREHOUSE_DIRECTORY_RUNTIME_BROWSER=PASS');
} finally { await browser.close(); await new Promise(resolve=>server.close(resolve)); assert.equal(fixture('cleanup'),'CLEANED'); }
