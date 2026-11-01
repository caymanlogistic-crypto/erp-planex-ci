import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {readFileSync} from 'node:fs';
import http from 'node:http';
import path from 'node:path';
import {createRequire} from 'node:module';
import {fileURLToPath} from 'node:url';

const require=createRequire(import.meta.url), {chromium}=require(process.env.PLANEX_PLAYWRIGHT_MODULE||'playwright');
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const render=role=>execFileSync(process.env.PLANEX_PHP||'php',[path.join(root,'tests/fixtures/logist_contracts_render.php'),role],{encoding:'utf8'});
const html={owner:render('owner'),logist:render('logist')};
const server=http.createServer((req,res)=>{
  const url=new URL(req.url,'http://localhost');
  if(url.pathname.startsWith('/assets/')){
    const file=path.resolve(root,'public','.'+url.pathname);
    if(!file.startsWith(path.join(root,'public','assets')+path.sep)){res.statusCode=403;res.end();return;}
    res.setHeader('Content-Type',file.endsWith('.css')?'text/css':'text/javascript');res.end(readFileSync(file));return;
  }
  res.setHeader('Content-Type','text/html; charset=utf-8');res.end(html[url.searchParams.get('role')==='owner'?'owner':'logist']);
});
await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
const browser=await chromium.launch({headless:true,...(process.env.PLANEX_BROWSER_BINARY?{executablePath:process.env.PLANEX_BROWSER_BINARY}:{})});
const origin=`http://127.0.0.1:${server.address().port}`;
try{
  for(const role of ['logist','owner']) for(const width of [1280,1920,390]){
    const page=await browser.newPage({viewport:{width,height:900}}), errors=[];
    page.on('pageerror',error=>errors.push(error.message));
    await page.route('**/*',route=>route.request().url().startsWith(origin+'/')?route.continue():route.abort());
    await page.route('**/company/logistics/contracts/2',route=>route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,row:{...JSON.parse(html.logist.match(/<script type="application\/json" data-initial-rows>(.*?)<\/script>/s)[1])[0],id:2,contract_type:'lease',version_no:2,review_reason:null,logist_name:'Карина Г.',snapshot:{trip:{comment:'Вывоз отходов: Кемерово — Воскресенск'},executor:{name:'ООО «СибТранс»'},driver:{full_name:'Орлов Алексей Петрович'},parameters:{start_date:'2026-11-03',finish_date:'2026-11-10',price:'185000',vat_mode:'Без НДС'},vehicle:{model:'А123ВС77',plate:'А123ВС77'},requests:[{zayavka_id:'312015'}]}},versions:[{id:1,version_no:1,status:'rejected',review_reason:'Уточнить ставку'}]})}));
    await page.goto(`${origin}/?role=${role}`);
    assert.equal(await page.locator('[data-rows] tr[data-id]').count(),2);
    assert.equal(await page.locator('thead th').count(),role==='owner'?8:7);
    if(width>390){
      const box=await page.locator('.registry-ui-card').boundingBox();
      const table=await page.locator('.logist-contract-table').boundingBox();
      assert.ok(Math.abs(box.width-table.width)<=2,`table does not fill card ${role} ${width}: ${box.width} / ${table.width}`);
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
  console.log('LOGIST_CONTRACTS_BROWSER=PASS');
}finally{await browser.close();server.close();}
