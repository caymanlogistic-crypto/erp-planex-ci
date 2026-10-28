import assert from 'node:assert/strict';
import fs from 'node:fs';
import http from 'node:http';
import path from 'node:path';
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import {assertRegistryVisualContract,assertRegistryDataContract} from './helpers/registry_visual_contract.mjs';
import {assertRegistryRows,assertRegistrySearch,assertRegistryScroll} from './helpers/registry_behavior_contract.mjs';
import {driverTsExpectations} from './fixtures/driver_ts_data_expectations.mjs';

const require=createRequire(import.meta.url);
const {chromium}=require('playwright');
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const render=mode=>execFileSync(process.env.PLANEX_PHP||'php',[path.join(root,'tests/fixtures/driver_ts_data_render.php'),...(mode?[mode]:[])],{encoding:'utf8'});
const html=render(''),detail=render('detail');
const server=http.createServer((request,response)=>{
  const pathname=new URL(request.url,'http://127.0.0.1').pathname;
  if(pathname==='/company/driver-ts-data'){response.setHeader('Content-Type','text/html; charset=utf-8');response.end(html);return;}
  if(pathname.endsWith('/detail')){response.setHeader('Content-Type','text/html; charset=utf-8');response.end(detail);return;}
  const file=path.resolve(root,'public','.'+pathname);
  if(!file.startsWith(path.join(root,'public')+path.sep)||!fs.existsSync(file)){response.statusCode=404;response.end();return;}
  response.setHeader('Content-Type',file.endsWith('.js')?'text/javascript; charset=utf-8':'text/css; charset=utf-8');response.end(fs.readFileSync(file));
});
await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
const browser=await chromium.launch({headless:true});
try{
  for(const [width,height] of [[390,844],[1280,900],[1920,1080],[3840,2160]]){
    const page=await browser.newPage({viewport:{width,height}});
    const errors=[];page.on('pageerror',error=>errors.push(error.message));
    await page.goto(`http://127.0.0.1:${server.address().port}/company/driver-ts-data`);
    await page.waitForSelector('[data-registry-ready="true"]');
    await assertRegistryVisualContract(page,`driver-ts-data ${width}`);
    await assertRegistryDataContract(page,'driver-ts-data');
    assert.equal(await page.locator('.driver-ts-data-table th').first().textContent(),'Участвует в рейсах');
    assert.equal(await page.locator('.driver-ts-data-table th').count(),4);
    assert.equal(await page.locator('.driver-ts-data-table tbody tr').count(),37);
    assert.equal(await page.locator('.route-executors-page-head .page-head-actions').count(),0);
    assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1),false,`${width} outer overflow`);
    await assertRegistryRows(page,driverTsExpectations,'driver-ts-data');
    await assertRegistrySearch(page,{...driverTsExpectations,resolveActual:async page=>page.locator('.driver-ts-data-table tbody > tr:not(.is-hidden)').evaluateAll(items=>items.map(row=>row.dataset.registryRowId).sort())},'driver-ts-data');
    await assertRegistryScroll(page,`driver-ts-data ${width}`);
    await page.locator('[data-driver-ts-search]').fill('#351');
    assert.equal(await page.locator('[data-driver-ts-count]').textContent(),'1');
    await page.locator('[data-driver-ts-reset]').click();
    assert.equal(await page.locator('[data-driver-ts-count]').textContent(),'37');
    await page.locator('[data-driver-ts-id="9"]').dblclick();
    await page.locator('.driver-ts-files-table').waitFor();
    assert.equal(await page.locator('[data-driver-ts-dialog]').evaluate(el=>el.open),true);
    assert.equal(await page.locator('[data-driver-ts-archive]').getAttribute('href'),'/company/driver-ts-data/9/archive');
    assert.equal(await page.locator('.driver-ts-fields').count(),2);
    assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1),false,`${width} modal overflow`);
    if(process.env.PLANEX_VISUAL_EVIDENCE_DIR){fs.mkdirSync(process.env.PLANEX_VISUAL_EVIDENCE_DIR,{recursive:true});await page.screenshot({path:path.join(process.env.PLANEX_VISUAL_EVIDENCE_DIR,`driver-ts-modal-${width}.png`)});}
    await page.keyboard.press('Escape');
    assert.equal(await page.locator('[data-driver-ts-dialog]').evaluate(el=>el.open),false);
    assert.deepEqual(errors,[]);
    if(process.env.PLANEX_VISUAL_EVIDENCE_DIR){fs.mkdirSync(process.env.PLANEX_VISUAL_EVIDENCE_DIR,{recursive:true});await page.screenshot({path:path.join(process.env.PLANEX_VISUAL_EVIDENCE_DIR,`driver-ts-${width}.png`)});}
    await page.close();
  }
  console.log('DRIVER_TS_BROWSER_PASS');
}finally{await browser.close();await new Promise(resolve=>server.close(resolve));}
