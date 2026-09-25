import assert from 'node:assert/strict';
import fs from 'node:fs';
import http from 'node:http';
import path from 'node:path';
import {createRequire} from 'node:module';
import {execFileSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';

const require=createRequire(import.meta.url),{chromium}=require('playwright');
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const html=execFileSync(process.env.PLANEX_PHP||'php',[path.join(root,'tests/fixtures/trip_completion_render.php')],{encoding:'utf8'});
let postCount=0;
let documents=[];
const payload=()=>JSON.stringify({ok:true,request:{id:12,is_complete:false,documents},trip:{complete_count:1,request_count:3}});
const server=http.createServer((req,res)=>{
  if(req.method==='POST'){
    postCount++;
    const body=[];
    req.on('data',chunk=>body.push(chunk));
    req.on('end',()=>{
      if(req.url?.endsWith('/documents')){
        const id=300+documents.length+1;
        documents.push({id,download_name:`Рейс_244_Заявка_275006_ТН_0${documents.length+1}.jpg`,download_url:`/download/${id}`,delete_url:`/delete/${id}`});
      }
      if(req.url?.startsWith('/delete/')){
        const id=Number(req.url.slice('/delete/'.length));
        documents=documents.filter(document=>document.id!==id);
      }
      res.setHeader('Content-Type','application/json; charset=utf-8');res.end(payload());
    });return;
  }
  if(req.url==='/'){res.setHeader('Content-Type','text/html; charset=utf-8');return res.end(html);}
  if(req.url?.startsWith('/download/')){res.setHeader('Content-Type','image/jpeg');return res.end('test');}
  const file=path.resolve(root,'public','.'+req.url.split('?')[0]);
  if(!file.startsWith(path.join(root,'public')+path.sep)||!fs.existsSync(file)){res.statusCode=404;return res.end();}
  res.setHeader('Content-Type',file.endsWith('.css')?'text/css':file.endsWith('.js')?'application/javascript':'application/octet-stream');
  res.end(fs.readFileSync(file));
});

await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
const browser=await chromium.launch({headless:true,...(process.env.PLANEX_BROWSER_EXECUTABLE?{executablePath:process.env.PLANEX_BROWSER_EXECUTABLE}:{})});
try{
  const page=await browser.newPage({viewport:{width:1680,height:1000}}),errors=[];
  page.on('pageerror',error=>errors.push(error.message));
  await page.goto('http://127.0.0.1:'+server.address().port,{waitUntil:'networkidle'});
  assert.equal(await page.locator('.logistics-trip').count(),2,'trip list rendered');
  assert.equal(await page.locator('.logistics-trip-summary .badge-danger').count(),0,'old red issue badge removed');
  assert.match(await page.locator('.logistics-trip').first().locator('summary').innerText(),/Груз сдан.*менеджер.*Карина Г.*\+7 908 221-12-12/is,'completed status and manager shown');
  assert.match(await page.locator('.logistics-trip').last().locator('summary').innerText(),/Идёт вывоз.*Выезд: 03\.09\.2026/s,'in-progress status and actual start shown');
  assert.equal(await page.locator('.lab-trip-status.is-delivered').count(),1,'delivered badge is gray');
  assert.equal(await page.locator('.lab-trip-status.is-progress').count(),1,'in-progress badge is green');
  assert.match(await page.locator('.logistics-trip').first().locator('summary').innerText(),/Без скана 1.*Время 1/s,'structured issue counts shown');
  await page.locator('.logistics-trip').first().locator('summary').click();
  const row=page.locator('[data-request-id="12"]');
  assert.deepEqual(await row.locator('.logistics-request-route > strong, .logistics-request-route > small').allTextContents(),['ООО Экопром','Архангельская область, Приморский район','→ Переработчик'],'pickup address shown between sender and recipient');
  assert.equal((await row.evaluate(element=>getComputedStyle(element).gridTemplateColumns.split(' ').length)),8,'desktop request uses eight aligned blocks');
  assert.equal(await row.locator('.lab-choice--date input[value="no"]').isChecked(),true,'missing time status persisted');
  assert.equal(await row.locator('.lab-choice--recovery input[value="driver"]').isChecked(),true,'driver source persisted');
  assert.equal(await row.locator('.lab-choice--recovery input[value="tracker"]').isEnabled(),true,'recovery choices enabled when needed');
  assert.equal(await row.locator('textarea[name="comment"]').count(),1,'comment is multiline');
  const interval=row.locator('input[name="pickup_interval"]');
  await interval.fill('01092609401000');
  assert.equal(await interval.inputValue(),'01.09.26 09:40 - 10:00','digits formatted as date and two times');
  await interval.press('a');
  assert.equal(await interval.inputValue(),'01.09.26 09:40 - 10:00','letters blocked');
  assert.equal(await row.locator('.logistics-time').getAttribute('data-interval-state'),'valid','complete interval turns green');
  await row.locator('.lab-choice--driver input[value="yes"]').check();
  await row.locator('[data-save-label]').filter({hasText:/Сохранено|Собрано/}).waitFor();
  assert.ok(postCount>=1,'checkbox change saved automatically');
  assert.equal(await row.locator('.lab-choice--driver input[value="no"]').isChecked(),false,'signature choices are exclusive');
  const fileInput=row.locator('.lab-docs-input');
  await fileInput.setInputFiles({name:'first.jpg',mimeType:'image/jpeg',buffer:Buffer.from('test-image')});
  await row.locator('[data-document-count]').filter({hasText:'1/2'}).waitFor({timeout:3000}).catch(async()=>{throw new Error('Upload not reflected: '+JSON.stringify({postCount,documents,label:await row.locator('[data-save-label]').textContent(),message:await row.locator('.lab-docs-message').textContent(),count:await row.locator('[data-document-count]').textContent(),errors}));});
  assert.equal(await row.locator('.lab-docs-view').count(),1,'one scan can be viewed');
  await row.locator('.lab-docs-view').click();
  assert.equal(await page.locator('.lab-scan-preview').evaluate(element=>element.open),true,'preview opens in a dialog');
  await page.locator('.lab-scan-preview-head button').click();
  await fileInput.setInputFiles({name:'second.jpg',mimeType:'image/jpeg',buffer:Buffer.from('test-image')});
  await row.locator('[data-document-count]').filter({hasText:'2/2'}).waitFor();
  assert.equal(await row.locator('.lab-docs-add').isDisabled(),true,'third scan cannot be added');
  assert.equal(await row.locator('.lab-docs-item').count(),2,'two scan limit visible');
  page.once('dialog',dialog=>dialog.accept());
  await row.locator('.lab-docs-remove').first().click();
  await row.locator('[data-document-count]').filter({hasText:'1/2'}).waitFor({timeout:3000}).catch(async()=>{throw new Error('Delete not reflected: '+JSON.stringify({postCount,documents,label:await row.locator('[data-save-label]').textContent(),count:await row.locator('[data-document-count]').textContent(),errors}));});
  await page.locator('input[name="q"]').fill('275006');
  assert.equal(await page.locator('.logistics-trip:not([hidden])').count(),1,'search works without submit button');
  assert.equal(await page.locator('.logistics-trip:not([hidden]) .logistics-request-row:not([hidden])').count(),1,'matching request remains visible');
  assert.equal(await page.locator('.logistics-filter button[type="submit"]').count(),0,'search submit removed');
  await page.locator('input[name="q"]').fill('');
  const evidence=process.env.PLANEX_BROWSER_EVIDENCE;
  if(evidence){fs.mkdirSync(path.dirname(evidence),{recursive:true});await page.screenshot({path:evidence,fullPage:true});}
  for(const width of [1366,1024,390]){
    await page.setViewportSize({width,height:900});
    assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=document.documentElement.clientWidth),true,`${width}px has no horizontal overflow`);
  }
  assert.equal(errors.length,0,'page errors: '+errors.join('; '));
  console.log('TRIP_COMPLETION_BROWSER=PASS');
}finally{await browser.close();await new Promise(resolve=>server.close(resolve));}
