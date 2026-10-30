import assert from 'node:assert/strict';
import http from 'node:http';
import path from 'node:path';
import {createRequire} from 'node:module';
import {fileURLToPath} from 'node:url';

const require=createRequire(import.meta.url),{chromium}=require('playwright');
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const slots=['contract','invoice','act','waybill','extra','diagnostic'];
const files=new Set();
let failNext=false;
const trip=()=>`<details data-closing-trip data-trip-id="7" data-business-id="322" data-state="${files.has('invoice')?'ready':'incomplete'}" data-entity="planex" data-manager-id="71" data-search="322 перевозчик"><summary>Рейс</summary><div class="closing-trip-body"><p class="closing-upload-feedback" role="alert" hidden></p>${slots.map(slot=>`<div data-slot="${slot}"><span>${files.has(slot)?`${slot}.pdf`:'Файл не получен'}</span>${files.has(slot)&&slot!=='diagnostic'?`<form class="closing-remove-form" action="/remove/${slot}" method="post"><button type="submit">Удалить ${slot}</button></form>`:''}${slot==='diagnostic'&&files.has(slot)?'':`<form class="closing-upload-form ${slot==='diagnostic'?'closing-card-form':slot==='extra'?'closing-extra-upload':''}" action="/add/${slot}" method="post"><input type="file" name="document_files[]"><button type="submit">Добавить ${slot}</button></form>`}</div>`).join('')}</div></details>`;
const html=(success=false,error=false)=>`<!doctype html><html><body>${error?'<div class="notice warn" role="alert">Ошибка сохранения</div>':''}<div class="closing-page" data-closing-state-key="fixture-mutations"><input data-closing-search><button data-closing-filter="all" class="is-active" aria-pressed="true">Все рейсы <b data-closing-filter-count></b></button><button data-closing-filter="incomplete" aria-pressed="false">Не хватает документов <b data-closing-filter-count></b></button><button data-closing-filter="ready" aria-pressed="false">Готово к оплате <b data-closing-filter-count></b></button><span data-closing-count></span><div class="lab-grid"><table><tbody><tr data-report-trip-id="7" tabindex="0"><td>322</td><td>${files.has('invoice')?'Есть':'НЕТ'}</td></tr></tbody></table></div><p data-closing-empty hidden></p><div class="closing-source-list">${trip()}</div><dialog data-closing-doc-dialog><strong id="closing-doc-title"></strong><div data-closing-doc-body></div><button data-closing-doc-close>Закрыть</button></dialog></div>${success?'<span data-closing-action-result="success" hidden></span>':''}</body></html>`;
const server=http.createServer((request,response)=>{
  request.resume();request.on('end',()=>{
    if(request.method==='POST'&&!failNext){
      const [,action,slot]=request.url.split('/');
      if(action==='add')files.add(slot);
      if(action==='remove')files.delete(slot);
    }
    response.setHeader('Content-Type','text/html; charset=utf-8');
    response.end(html(request.method==='POST'&&!failNext,request.method==='POST'&&failNext));
    failNext=false;
  });
});
await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
const browser=await chromium.launch({headless:true,...(process.env.PLANEX_BROWSER_EXECUTABLE?{executablePath:process.env.PLANEX_BROWSER_EXECUTABLE}:{})});
try{
  for(const width of [1280,390]){
    files.clear();
    const page=await browser.newPage({viewport:{width,height:700}});
    const errors=[];page.on('pageerror',error=>errors.push(error.message));
    await page.goto(`http://127.0.0.1:${server.address().port}/`);
    await page.addScriptTag({path:path.join(root,'public/assets/js/waste-trip-closing-registry.js')});
    await page.locator('[data-report-trip-id="7"]').dblclick();
    for(const slot of slots){
      const row=page.locator(`[data-closing-doc-dialog] [data-slot="${slot}"]`);
      await row.locator('input[type=file]').setInputFiles({name:`${slot}.pdf`,mimeType:'application/pdf',buffer:Buffer.from('%PDF-1.4')});
      await row.getByText(`${slot}.pdf`).waitFor();
      assert.equal(await page.locator('[data-closing-doc-dialog]').evaluate(el=>el.open),true,`${slot}: dialog stays open after adding`);
      assert.equal(await row.isVisible(),true,`${slot}: updated content stays visible`);
      if(slot==='invoice')await page.locator('[data-report-trip-id="7"] td:nth-child(2)').getByText('Есть').waitFor();
    }
    await page.locator('[data-closing-doc-close]').click();
    await page.locator('[data-closing-filter="ready"]').click();
    await page.locator('[data-report-trip-id="7"]').dblclick();
    for(const slot of [...slots].reverse().filter(slot=>slot!=='diagnostic')){
      const row=page.locator(`[data-closing-doc-dialog] [data-slot="${slot}"]`);
      await row.getByRole('button',{name:`Удалить ${slot}`}).click();
      await row.getByText('Файл не получен').waitFor();
      assert.equal(await page.locator('[data-closing-doc-dialog]').evaluate(el=>el.open),true,`${slot}: dialog stays open after removal`);
      assert.equal(await row.isVisible(),true,`${slot}: removal result stays visible`);
      if(slot==='invoice'){
        assert.equal(await page.locator('[data-closing-filter="all"]').getAttribute('aria-pressed'),'true','changed state moves to all tab');
        await page.locator('[data-report-trip-id="7"] td:nth-child(2)').getByText('НЕТ').waitFor();
      }
    }
    failNext=true;
    await page.locator('[data-slot="contract"] input[type=file]').setInputFiles({name:'bad.pdf',mimeType:'application/pdf',buffer:Buffer.from('%PDF-1.4')});
    await page.locator('.closing-upload-feedback:visible').getByText('Ошибка сохранения').waitFor();
    assert.equal(await page.locator('[data-slot="contract"] span').textContent(),'Файл не получен','failed write does not claim success');
    await page.locator('[data-closing-doc-close]').click();
    assert.equal(await page.locator('[data-report-trip-id="7"] td:nth-child(2)').textContent(),'НЕТ','grid updates before reload');
    assert.deepEqual(errors,[],`${width}px page errors`);
    await page.close();
  }
  console.log('waste_trip_closing_mutations_browser: PASS');
}finally{await browser.close();await new Promise(resolve=>server.close(resolve));}
