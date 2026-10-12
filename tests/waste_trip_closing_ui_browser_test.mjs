import assert from 'node:assert/strict';
import http from 'node:http';
import path from 'node:path';
import {createRequire} from 'node:module';
import {fileURLToPath} from 'node:url';

const require=createRequire(import.meta.url),{chromium}=require('playwright');
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
let documentPresent=false,cardPresent=false,uploadedNames=[];
const trip=()=>`<details data-closing-trip data-trip-id="7" data-business-id="322" data-state="${documentPresent?'partial':'new'}" data-payment-ready="${cardPresent?'yes':'no'}" data-entity="planex" data-manager-id="71" data-search="322 перевозчик николаенко" open>
  <summary>Рейс #322 ${documentPresent?'<span class="closing-missing">НЕ ХВАТАЕТ ДОКУМЕНТОВ</span>':''}</summary>
  <div class="closing-trip-body"><p class="closing-upload-feedback" role="alert" hidden></p>
    <div class="closing-doc-row"><span class="closing-file-name">${documentPresent?uploadedNames.join(', '):'Файл не получен'}</span>
      <form class="closing-upload-form" action="/documents" method="post"><input type="file" name="document_files[]" multiple required></form>
      ${documentPresent?'<form class="closing-remove-form" action="/remove" method="post"><button type="submit">Удалить</button></form>':''}
    </div>
    <div class="closing-doc-row"><strong>Диагностическая карта транспорта</strong><span class="card-file-name">${cardPresent?'card.pdf':'Файл не получен'}</span>
      <form class="closing-upload-form closing-card-form" action="/card" method="post"><input type="hidden" name="vehicle_unit_id" value="9"><input type="file" name="card_files[]" multiple required></form>
    </div>
  </div>
</details>`;
const pageHtml=(success,payment=false)=>`<!doctype html><html><body><div style="height:800px"></div><div class="closing-page" data-closing-state-key="planex:trip-closing:v1:fixture-user">
  <button type="button" data-closing-report-open>Сводный отчёт</button>
  <input data-closing-search><button data-closing-filter="all"></button><button data-closing-filter="open"></button><button data-closing-filter="review"></button><button data-closing-filter="payment"></button>
  <select data-closing-entity><option value="all">Все рейсы</option><option value="planex">ПЛАНЭКС</option><option value="mg_logistic">МГ Логистика</option></select>
  <select data-closing-payment-ready><option value="all">Все</option><option value="yes">Да</option><option value="no">Нет</option></select>
  <select data-closing-manager><option value="">Не выбран</option><option value="71">Анна</option><option value="72">Борис</option><option value="73">Без рейсов</option></select>
  <span data-closing-count>2</span>
  ${success?'<span data-closing-action-result="success" hidden></span>':''}${trip()}
  <details data-closing-trip data-trip-id="8" data-business-id="310" data-state="${payment?'payment':'review'}" data-payment-ready="yes" data-entity="mg_logistic" data-manager-id="72" data-search="310 перевозчик гайнутдинов"><summary>Рейс #310</summary></details>
  <p data-closing-empty hidden></p>
</div><dialog data-closing-report-dialog><button type="button" data-closing-report-close>Закрыть</button><table><tbody><tr data-report-trip-id="7"><td>322</td></tr><tr data-report-trip-id="8"><td>310</td></tr></tbody></table><p data-closing-report-empty hidden></p><span data-closing-report-count></span><a data-closing-report-download href="/company/trips/closing-documents/summary.xlsx">Скачать Excel</a></dialog><div style="height:900px"></div></body></html>`;
const server=http.createServer((request,response)=>{
  const chunks=[];
  request.on('data',chunk=>chunks.push(chunk));
  request.on('end',()=>{
    if(request.method==='POST') {
      if(request.url==='/documents') {
        documentPresent=true;
        uploadedNames=[...Buffer.concat(chunks).toString('utf8').matchAll(/filename="([^"]+)"/g)].map(match=>match[1]);
      } else if(request.url==='/card') cardPresent=true;
      else {documentPresent=false;uploadedNames=[];}
    }
    response.setHeader('Content-Type','text/html; charset=utf-8');
    response.end(pageHtml(request.method==='POST',request.url?.includes('stage=payment')));
  });
});
await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
const browser=await chromium.launch({headless:true});
try {
  const page=await browser.newPage({viewport:{width:1280,height:700}});
  const errors=[];page.on('pageerror',error=>errors.push(error.message));
  const url=`http://127.0.0.1:${server.address().port}/`;
  await page.goto(url);
  await page.addScriptTag({path:path.join(root,'public/assets/js/waste-trip-closing.js')});
  await page.locator('[data-closing-search]').fill('322, 310');
  assert.equal(await page.locator('[data-closing-trip]:visible').count(),2,'comma-separated trip IDs keep requested trips');
  await page.locator('[data-closing-search]').fill('32, 310');
  assert.equal(await page.locator('[data-closing-trip]:visible').count(),1,'trip IDs match exactly, not by substring');
  await page.locator('[data-closing-search]').fill('Рейс #322, №310,');
  assert.equal(await page.locator('[data-closing-trip]:visible').count(),2,'pasted trip labels and a trailing comma work');
  await page.selectOption('[data-closing-entity]','planex');
  assert.equal(await page.locator('[data-closing-trip]:visible').count(),1,'PLANEX selection keeps only its trips');
  await page.locator('[data-closing-report-open]').click();
  assert.equal(await page.locator('[data-report-trip-id]:visible').count(),1,'report uses current trip filters');
  assert.match(await page.locator('[data-closing-report-download]').getAttribute('href'),/ids=7$/,'Excel download selects visible trip');
  await page.locator('[data-closing-report-close]').click();
  await page.selectOption('[data-closing-entity]','mg_logistic');
  assert.equal(await page.locator('[data-trip-id="8"]:visible').count(),1,'MG Logistics selection keeps only its trips');
  await page.selectOption('[data-closing-entity]','all');
  await page.locator('[data-closing-filter="review"]').click();
  assert.equal(await page.locator('[data-closing-trip]:visible').count(),1,'state filter narrows the requested trip list');
  await page.locator('[data-closing-filter="all"]').click();
  await page.selectOption('[data-closing-manager]','71');
  assert.equal(await page.locator('[data-closing-trip]:visible').count(),1,'manager filter narrows the requested trip list');
  await page.selectOption('[data-closing-manager]','');
  await page.selectOption('[data-closing-payment-ready]','yes');
  assert.equal(await page.locator('[data-closing-trip]:visible').count(),1,'payment filter narrows the requested trip list');
  await page.locator('[data-closing-search]').fill('николаенко');
  assert.equal(await page.locator('[data-closing-trip]:visible').count(),0,'carrier text search still combines with payment filter');
  await page.locator('[data-closing-search]').fill('');
  await page.selectOption('[data-closing-payment-ready]','yes');
  assert.equal(await page.locator('[data-closing-trip]:visible').count(),1,'payment readiness narrows trips');
  await page.selectOption('[data-closing-manager]','71');
  assert.equal(await page.locator('[data-closing-trip]:visible').count(),0,'manager combines with payment readiness');
  await page.selectOption('[data-closing-payment-ready]','no');
  assert.equal(await page.locator('[data-closing-trip]:visible').count(),1,'manager and payment readiness combine');
  await page.selectOption('[data-closing-manager]','73');
  assert.equal(await page.locator('[data-closing-trip]:visible').count(),0,'user without trips gives an empty result');
  await page.selectOption('[data-closing-manager]','');
  await page.selectOption('[data-closing-payment-ready]','all');
  await page.goto(`${url}?stage=payment&trip_id=8`);
  await page.addScriptTag({path:path.join(root,'public/assets/js/waste-trip-closing.js')});
  assert.equal(await page.locator('[data-closing-filter="payment"]').getAttribute('aria-pressed'),'true','payment stage opens the payment queue');
  assert.equal(await page.locator('[data-closing-trip]:visible').count(),1,'payment queue shows only queued trips');
  assert.equal(await page.locator('[data-trip-id="8"]').getAttribute('open'),'','queued trip opens after transition');
  await page.goto(url);
  await page.addScriptTag({path:path.join(root,'public/assets/js/waste-trip-closing.js')});
  await page.locator('[data-closing-filter="all"]').click();
  await page.selectOption('[data-closing-payment-ready]','all');
  await page.locator('[data-closing-search]').fill('310');
  await page.locator('[data-closing-filter="review"]').click();
  await page.selectOption('[data-closing-payment-ready]','yes');
  await page.selectOption('[data-closing-entity]','mg_logistic');
  await page.selectOption('[data-closing-manager]','72');
  await page.locator('[data-trip-id="8"] summary').click();
  await page.waitForFunction(()=>JSON.parse(localStorage.getItem('planex:trip-closing:v1:fixture-user')).openTripId==='8');
  await page.reload();
  await page.addScriptTag({path:path.join(root,'public/assets/js/waste-trip-closing.js')});
  assert.equal(await page.locator('[data-closing-search]').inputValue(),'310','search survives page reload');
  assert.equal(await page.locator('[data-closing-filter="review"]').getAttribute('aria-pressed'),'true','state filter survives page reload');
  assert.equal(await page.locator('[data-closing-payment-ready]').inputValue(),'yes','payment filter survives page reload');
  assert.equal(await page.locator('[data-closing-entity]').inputValue(),'mg_logistic','legal entity filter survives page reload');
  assert.equal(await page.locator('[data-closing-manager]').inputValue(),'72','manager filter survives page reload');
  assert.equal(await page.locator('[data-trip-id="8"]').getAttribute('open'),'','expanded trip survives page reload');
  assert.equal(await page.locator('[data-closing-trip]:visible').count(),1,'restored filters are applied');
  await page.goto(`${url}?trip_id=7`);
  await page.addScriptTag({path:path.join(root,'public/assets/js/waste-trip-closing.js')});
  assert.equal(await page.locator('[data-trip-id="7"]').getAttribute('open'),'','explicit trip link overrides saved expansion');
  await page.goto(url);
  await page.addScriptTag({path:path.join(root,'public/assets/js/waste-trip-closing.js')});
  assert.equal(await page.locator('[data-trip-id="7"]').getAttribute('open'),'','explicitly opened trip remains expanded on return');
  await page.locator('[data-closing-search]').fill('');
  await page.locator('[data-closing-filter="all"]').click();
  await page.selectOption('[data-closing-entity]','all');
  await page.selectOption('[data-closing-payment-ready]','all');
  await page.selectOption('[data-closing-manager]','');
  await page.evaluate(()=>window.scrollTo(0,750));
  const before=await page.evaluate(()=>window.scrollY);
  await page.locator('[name="document_files[]"]').setInputFiles([
    {name:'file.pdf',mimeType:'application/pdf',buffer:Buffer.from('%PDF-1.4')},
    {name:'second.pdf',mimeType:'application/pdf',buffer:Buffer.from('%PDF-1.4')},
  ]);
  await page.locator('.closing-remove-form').waitFor();
  assert.equal(await page.evaluate(()=>window.scrollY),before,'upload keeps scroll position');
  assert.equal(page.url(),url,'upload does not navigate');
  assert.deepEqual(uploadedNames,['file.pdf','second.pdf'],'one upload request keeps both files');
  assert.equal(await page.locator('.closing-file-name').textContent(),'file.pdf, second.pdf');
  await page.locator('.closing-remove-form button').click();
  await page.locator('.closing-file-name').getByText('Файл не получен').waitFor();
  assert.equal(await page.evaluate(()=>window.scrollY),before,'removal keeps scroll position');
  assert.equal(page.url(),url,'removal does not navigate');
  assert.equal(await page.locator('.closing-missing').count(),0,'empty trip has no missing-documents warning');
  await page.selectOption('[data-closing-payment-ready]','no');
  await page.locator('[name="card_files[]"]').setInputFiles({name:'card.pdf',mimeType:'application/pdf',buffer:Buffer.from('%PDF-1.4')});
  await page.locator('[data-closing-count]').getByText('0').waitFor();
  assert.equal(await page.locator('[data-closing-trip]:visible').count(),0,'upload reapplies the active payment filter');
  await page.selectOption('[data-closing-payment-ready]','yes');
  assert.equal(await page.locator('.card-file-name').getByText('card.pdf').count(),1);
  assert.equal(page.url(),url,'diagnostic card upload uses the same inline flow');
  assert.deepEqual(errors,[]);
  await page.close();
  console.log('waste_trip_closing_ui_browser: PASS');
} finally {await browser.close();await new Promise(resolve=>server.close(resolve));}
