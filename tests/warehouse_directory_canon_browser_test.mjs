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
const render=role=>execFileSync(process.env.PLANEX_PHP||'php',[path.join(root,'tests/fixtures/warehouse_directory_canon_render.php'),role],{encoding:'utf8',maxBuffer:8*1024*1024});
const rendered={owner:render('owner'),logist:render('logist')};
const server=http.createServer((req,res)=>{
  const url=new URL(req.url,'http://localhost');
  if(url.pathname.startsWith('/assets/')){
    const file=path.resolve(root,'public','.'+url.pathname);
    if(!file.startsWith(path.join(root,'public','assets')+path.sep)){res.statusCode=403;res.end();return;}
    res.setHeader('Content-Type',file.endsWith('.css')?'text/css':file.endsWith('.js')?'text/javascript':'font/woff2');
    res.end(readFileSync(file));return;
  }
  res.setHeader('Content-Type','text/html; charset=utf-8');
  res.end(rendered[url.searchParams.get('role')==='logist'?'logist':'owner']);
});
await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
const browser=await chromium.launch({headless:true});
const origin=`http://127.0.0.1:${server.address().port}`;
const rows=[
  {id:7,cells:[['#7'],['Склад Север'],['Москва, Складская улица, 1, корпус 2'],['2 контакта'],['2'],['3 750 кг']]},
  {id:8,cells:[['#8'],['Склад Юг'],['Тула, Южная улица, 2'],['0 контактов'],['0'],['0 кг']]},
  ...Array.from({length:49},(_,index)=>{const id=index+9;return {id,cells:[[`#${id}`],[`Склад ${id}`],[`Регион, Промышленная улица, ${id}`],['0 контактов'],['0'],['0 кг']]};}),
  {id:58,cells:[['#58'],['Склад без адреса'],['—'],['0 контактов'],['0'],['0 кг']]},
];
try {
  for(const role of ['owner','logist']) for(const width of [390,1280,1920,3840]){
    const page=await browser.newPage({viewport:{width,height:900}}),errors=[];
    page.on('pageerror',error=>errors.push(error.message));
    await page.route('**/*',route=>route.request().url().startsWith(origin+'/')?route.continue():route.abort());
    await page.goto(`${origin}/company/warehouses/addresses?role=${role}`);
    await page.waitForSelector('[data-registry-ready="true"]');
    assert.equal(await page.locator('.nav-sub-item[href="/company/warehouses/addresses"]').count(),1);
    if(role==='logist') assert.equal(await page.locator('.nav-sub-item[href="/company/warehouses/stock"]').count(),0);
    const bounds=await page.evaluate(()=>{
      const head=document.querySelector('[data-warehouse-directory] .registry-ui-head').getBoundingClientRect();
      const card=document.querySelector('[data-warehouse-directory] .registry-ui-card').getBoundingClientRect();
      return {headX:head.x,cardX:card.x,headWidth:head.width,cardWidth:card.width};
    });
    assert.ok(bounds.cardWidth<=1691,`warehouse card exceeds canonical 1690px: ${bounds.cardWidth}`);
    assert.ok(Math.abs(bounds.headX-bounds.cardX)<=1 && Math.abs(bounds.headWidth-bounds.cardWidth)<=1,
      `warehouse header and card are not aligned: ${JSON.stringify(bounds)}`);
    await assertRegistryVisualContract(page,`warehouse-addresses ${role} ${width}`);
    await assertRegistryDataContract(page,'warehouse-addresses');
    await assertRegistryTableState(page,'warehouse-addresses','initial');
    await assertRegistryRows(page,{rowSelector:'[data-warehouse-row]',rows},`warehouse-addresses ${role}`);
    await assertRegistryScroll(page,`warehouse-addresses ${role} ${width}`);
    await assertRegistrySearch(page,{rowSelector:'[data-warehouse-row]',probes:[{query:'Петров',ids:[7]},{query:'Тула',ids:[8]},{query:'3 750',ids:[7]}],
      resolveActual:async p=>p.locator('[data-warehouse-row]:visible').evaluateAll(items=>items.map(item=>item.dataset.registryRowId).sort())},`warehouse-addresses ${role}`);
    const dialog=page.locator('[data-warehouse-dialog="7"]');
    await page.locator('[data-warehouse-row][data-registry-row-id="7"] .registry-ui-name').click();
    await page.waitForTimeout(300);
    assert.equal(await dialog.evaluate(element=>element.open),false,'single mouse click must not open warehouse dialog');
    await page.locator('[data-warehouse-row][data-registry-row-id="7"] .registry-ui-name').dblclick();
    await dialog.waitFor({state:'visible'});
    assert.equal(await dialog.evaluate(element=>element.open),true);
    assert.ok(await dialog.evaluate(element=>element.scrollWidth-element.clientWidth)<=1);
    assert.equal(await dialog.locator('form').count(),role==='owner'?1:0);
    if(role==='owner'){
      if(process.env.PLANEX_WAREHOUSE_SCREENSHOT_DIR){
        mkdirSync(process.env.PLANEX_WAREHOUSE_SCREENSHOT_DIR,{recursive:true});
        await dialog.screenshot({path:path.join(process.env.PLANEX_WAREHOUSE_SCREENSHOT_DIR,`warehouse-dialog-${width}.png`)});
      }
      const contactLayout=await dialog.evaluate(element=>{
        const section=element.querySelector('[data-warehouse-contacts]').previousElementSibling.getBoundingClientRect();
        const group=element.querySelector('[data-warehouse-contact]');
        const heading=group.querySelector('legend').getBoundingClientRect();
        const label=group.querySelector('.field-label:not(legend)').getBoundingClientRect();
        const input=group.querySelector('.field-input').getBoundingClientRect();
        return {sectionGap:heading.top-section.bottom,headingGap:label.top-heading.bottom,labelGap:input.top-label.bottom,aligned:Math.abs(label.left-input.left)<=1};
      });
      assert.ok(contactLayout.sectionGap>=7 && contactLayout.headingGap>=7 && contactLayout.labelGap>=2 && contactLayout.aligned,
        `warehouse contact captions overlap or misalign ${width}: ${JSON.stringify(contactLayout)}`);
      assert.equal(await dialog.locator('[name="latitude"]').inputValue(),'55.75');
      assert.equal(await dialog.locator('[name="longitude"]').inputValue(),'37.62');
      await dialog.locator('[data-warehouse-add-contact]').click();
      assert.equal(await dialog.locator('[data-warehouse-contact]').count(),3);
      await dialog.locator('[data-warehouse-add-contact]').click();
      assert.equal(await dialog.locator('[data-warehouse-contact]').count(),4);
      await dialog.locator('[data-warehouse-remove-contact]').nth(1).click();
      assert.equal(await dialog.locator('[data-warehouse-contact]').count(),3);
      assert.deepEqual(await dialog.locator('[data-warehouse-contact] legend').allTextContents(),['Контакт 1','Контакт 2','Контакт 3']);
    }else assert.match(await dialog.innerText(),/Петров Пётр/);
    await dialog.locator('[data-warehouse-close]').first().click();
    await page.locator('[data-warehouse-open="7"]').focus();
    await page.keyboard.press('Enter');
    assert.equal(await dialog.evaluate(element=>element.open),true);
    assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>window.innerWidth+1),false);
    assert.deepEqual(errors,[]);
    await page.close();
  }
  console.log('WAREHOUSE_DIRECTORY_CANON_BROWSER=PASS');
} finally { await browser.close(); await new Promise(resolve=>server.close(resolve)); }
