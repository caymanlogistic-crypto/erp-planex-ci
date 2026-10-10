import assert from 'node:assert/strict';
import http from 'node:http';
import path from 'node:path';
import {createRequire} from 'node:module';
import {fileURLToPath} from 'node:url';

const require=createRequire(import.meta.url),{chromium}=require('playwright');
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
let documentPresent=false,uploadedNames=[];
const trip=()=>`<details data-closing-trip data-trip-id="7" data-state="${documentPresent?'partial':'new'}" data-search="рейс 7" open>
  <summary>Рейс #7 ${documentPresent?'<span class="closing-missing">НЕ ХВАТАЕТ ДОКУМЕНТОВ</span>':''}</summary>
  <div class="closing-trip-body"><p class="closing-upload-feedback" role="alert" hidden></p>
    <div class="closing-doc-row"><span class="closing-file-name">${documentPresent?uploadedNames.join(', '):'Файл не получен'}</span>
      <form class="closing-upload-form" action="/documents" method="post"><input type="file" name="document_files[]" multiple required></form>
      ${documentPresent?'<form class="closing-remove-form" action="/remove" method="post"><button type="submit">Удалить</button></form>':''}
    </div>
  </div>
</details>`;
const pageHtml=success=>`<!doctype html><html><body><div style="height:800px"></div><div class="closing-page">
  <input data-closing-search><button data-closing-filter="all"></button><span data-closing-count>1</span>
  ${success?'<span data-closing-action-result="success" hidden></span>':''}${trip()}<p data-closing-empty hidden></p>
</div><div style="height:900px"></div></body></html>`;
const server=http.createServer((request,response)=>{
  const chunks=[];
  request.on('data',chunk=>chunks.push(chunk));
  request.on('end',()=>{
    if(request.method==='POST') {
      documentPresent=request.url==='/documents';
      if(documentPresent) uploadedNames=[...Buffer.concat(chunks).toString('utf8').matchAll(/filename="([^"]+)"/g)].map(match=>match[1]);
      else uploadedNames=[];
    }
    response.setHeader('Content-Type','text/html; charset=utf-8');
    response.end(pageHtml(request.method==='POST'));
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
  await page.evaluate(()=>window.scrollTo(0,750));
  const before=await page.evaluate(()=>window.scrollY);
  await page.locator('input[type=file]').setInputFiles([
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
  assert.deepEqual(errors,[]);
  await page.close();
  console.log('waste_trip_closing_ui_browser: PASS');
} finally {await browser.close();await new Promise(resolve=>server.close(resolve));}
