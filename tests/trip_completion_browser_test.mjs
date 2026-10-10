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
const timeOnlyHtml=execFileSync(process.env.PLANEX_PHP||'php',[path.join(root,'tests/fixtures/trip_completion_render.php'),'time-only'],{encoding:'utf8'});
let postCount=0;
let lastSaveBody='';
let documents=[];
const payload=()=>JSON.stringify({ok:true,request:{id:12,is_complete:false,documents},trip:{complete_count:1,request_count:3}});
const server=http.createServer((req,res)=>{
  if(req.method==='POST'){
    postCount++;
    const body=[];
    req.on('data',chunk=>body.push(chunk));
    req.on('end',()=>{
      if(req.url?.endsWith('/requests/12')) lastSaveBody=Buffer.concat(body).toString('utf8');
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
  if(req.url==='/time-only'){res.setHeader('Content-Type','text/html; charset=utf-8');return res.end(timeOnlyHtml);}
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
  const progress=page.locator('.logistics-trip').first().locator('.lab-trip-progress');
  assert.match(await progress.innerText(),/2 из 3 обработано/,'missing TN counts as a processed request');
  assert.equal(await progress.locator('.lab-trip-progress-no-tn').evaluate(element=>getComputedStyle(element).backgroundColor),'rgb(220, 16, 16)','missing TN has a vivid red segment');
  assert.equal(await progress.locator('.lab-trip-progress-no-tn').evaluate(element=>Math.round(element.getBoundingClientRect().width/element.parentElement.getBoundingClientRect().width*100)),33,'one missing TN occupies one third of the bar');
  const redAtRight=()=>progress.locator('.lab-trip-progress-no-tn').evaluate(element=>Math.abs(element.getBoundingClientRect().right-element.parentElement.getBoundingClientRect().right)<1);
  assert.equal(await redAtRight(),true,'missing TN segment ends at the right edge after the ready segment');
  await progress.locator('.lab-trip-progress-fill').evaluate(element=>{element.style.width='0%';element.style.transition='none';});
  assert.equal(await redAtRight(),true,'missing TN segment stays at the right edge without ready requests');
  await progress.locator('.lab-trip-progress-fill').evaluate(element=>{element.style.width='33.33%';element.style.transition='';});
  assert.match(await page.locator('.logistics-trip').first().locator('summary').innerText(),/Качество заполнения.*Частично заполнены.*Восстановлено.*Со слов вод\.\/мен/is,'compact quality and recovery shown');
  assert.equal(await page.locator('.logistics-trip').first().locator('.lab-trip-email').count(),0,'manager email removed');
  assert.equal(await page.locator('.lab-choice--recovery input[value="invented"]').count(),0,'invented choice removed');
  await page.locator('.logistics-trip').first().locator('summary').click();
  const row=page.locator('[data-request-id="12"]');
  assert.deepEqual(await row.locator('.logistics-request-route > strong, .logistics-request-route > small').allTextContents(),['ООО Экопром','Архангельская область, Приморский район','→ Переработчик'],'pickup address shown between sender and recipient');
  assert.equal((await row.evaluate(element=>getComputedStyle(element).gridTemplateColumns.split(' ').length)),8,'desktop request uses eight aligned blocks');
  assert.equal(await row.locator('.lab-choice--date input[value="no"]').isChecked(),true,'missing time status persisted');
  assert.equal(await row.locator('.lab-choice--recovery input[value="driver"]').isChecked(),true,'driver source persisted');
  assert.match(await row.locator('.lab-choice--recovery').evaluate(element=>getComputedStyle(element).backgroundColor),/rgb\(/,'driver recovery has state color');
  assert.equal(await row.locator('.lab-choice--recovery input[value="tracker"]').isEnabled(),true,'recovery choices enabled when needed');
  assert.equal(await row.locator('textarea[name="comment"]').count(),1,'comment is multiline');
  assert.equal(await row.locator('[data-document-count]').isVisible(),false,'scan count is visually hidden');
  const noTn=row.locator('.lab-docs-no-tn');
  await noTn.check();
  await page.locator('.logistics-trip').first().locator('.lab-trip-breakdown-line--error').waitFor();
  assert.match(await page.locator('.logistics-trip').first().locator('summary').innerText(),/Нет ТН.*275006/s,'missing TN is shown in compact quality summary');
  assert.match(await progress.innerText(),/3 из 3 обработано/,'new missing TN immediately increases processed count');
  await page.waitForFunction(()=>{const segment=document.querySelector('.logistics-trip .lab-trip-progress-no-tn');return segment&&segment.getBoundingClientRect().width/segment.parentElement.getBoundingClientRect().width>.665;});
  assert.equal(await progress.locator('.lab-trip-progress-no-tn').evaluate(element=>Math.round(element.getBoundingClientRect().width/element.parentElement.getBoundingClientRect().width*100)),67,'red segment grows for two missing TN requests');
  assert.equal(await redAtRight(),true,'additional missing TN share grows leftward from the right edge');
  assert.equal(await page.locator('.logistics-trip').first().isVisible(),true,'processed trip with missing TN remains visible under hide-complete filter');
  await row.locator('[data-save-label]').filter({hasText:/Сохранено|Собрано/}).waitFor();
  assert.match(lastSaveBody,/name="tn_absent"[\s\S]*?\r\n\r\n1\r\n/,'external checkbox is submitted with request form');
  await noTn.uncheck();
  assert.match(await progress.innerText(),/2 из 3 обработано/,'clearing missing TN removes its processed share');
  const interval=row.locator('input[name="pickup_interval"]');
  await interval.fill('01092609401000');
  assert.equal(await interval.inputValue(),'01.09.26 09:40 - 10:00','digits formatted as date and two times');
  await interval.press('a');
  assert.equal(await interval.inputValue(),'01.09.26 09:40 - 10:00','letters blocked');
  assert.equal(await row.locator('.logistics-time').getAttribute('data-interval-state'),'valid','complete interval turns green');
  assert.match(await progress.innerText(),/3 из 3 обработано/,'recording both times processes the request without scans or signatures');
  await page.waitForFunction(()=>{const segment=document.querySelector('.logistics-trip .lab-trip-progress-fill');return segment&&segment.getBoundingClientRect().width/segment.parentElement.getBoundingClientRect().width>.665;});
  assert.equal(await progress.locator('.lab-trip-progress-fill').evaluate(element=>Math.round(element.getBoundingClientRect().width/element.parentElement.getBoundingClientRect().width*100)),67,'recorded time contributes to progress without a complete document set');
  await row.locator('.lab-choice--driver input[value="yes"]').check();
  await row.locator('[data-save-label]').filter({hasText:/Сохранено|Собрано/}).waitFor();
  assert.ok(postCount>=1,'checkbox change saved automatically');
  assert.equal(await row.locator('.lab-choice--driver input[value="no"]').isChecked(),false,'signature choices are exclusive');
  const fileInput=row.locator('.lab-docs-input');
  await fileInput.setInputFiles({name:'first.jpg',mimeType:'image/jpeg',buffer:Buffer.from('test-image')});
  await row.locator('[data-document-count]').filter({hasText:'1/2'}).waitFor({state:'attached',timeout:3000}).catch(async()=>{throw new Error('Upload not reflected: '+JSON.stringify({postCount,documents,label:await row.locator('[data-save-label]').textContent(),message:await row.locator('.lab-docs-message').textContent(),count:await row.locator('[data-document-count]').textContent(),errors}));});
  assert.equal(await row.locator('.lab-docs-view').count(),1,'one scan can be viewed');
  assert.equal(await row.locator('.lab-docs-download').count(),1,'one scan can be downloaded');
  assert.equal(await row.locator('.file-type-badge').count(),0,'file type badge removed');
  await row.locator('.lab-docs-view').click();
  assert.equal(await page.locator('.lab-scan-preview').evaluate(element=>element.open),true,'preview opens in a dialog');
  await page.locator('.lab-scan-preview-head button').click();
  await fileInput.setInputFiles({name:'second.jpg',mimeType:'image/jpeg',buffer:Buffer.from('test-image')});
  await row.locator('[data-document-count]').filter({hasText:'2/2'}).waitFor({state:'attached'});
  assert.equal(await row.locator('.lab-docs-add').isDisabled(),true,'third scan cannot be added');
  assert.equal(await row.locator('.lab-docs-item').count(),2,'two scan limit visible');
  page.once('dialog',dialog=>dialog.accept());
  await row.locator('.lab-docs-remove').first().click();
  await row.locator('[data-document-count]').filter({hasText:'1/2'}).waitFor({state:'attached',timeout:3000}).catch(async()=>{throw new Error('Delete not reflected: '+JSON.stringify({postCount,documents,label:await row.locator('[data-save-label]').textContent(),count:await row.locator('[data-document-count]').textContent(),errors}));});
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
  const timeOnlyPage=await browser.newPage();
  await timeOnlyPage.goto('http://127.0.0.1:'+server.address().port+'/time-only',{waitUntil:'networkidle'});
  const timeOnlyProgress=timeOnlyPage.locator('.logistics-trip').first().locator('.lab-trip-progress');
  assert.match(await timeOnlyProgress.innerText(),/2 из 3 обработано/,'a request with recorded times and missing sender signature counts on initial render');
  assert.match(await timeOnlyProgress.locator('.lab-trip-progress-track').getAttribute('aria-label'),/Время внесено: 2; нет ТН: 0/,'time and missing TN remain distinct progress categories');
  await timeOnlyPage.close();
  console.log('TRIP_COMPLETION_BROWSER=PASS');
}finally{await browser.close();await new Promise(resolve=>server.close(resolve));}
