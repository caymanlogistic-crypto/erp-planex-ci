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
const response=(withDocument=false)=>JSON.stringify({
 ok:true,
 request:{id:12,is_complete:false,arrived_at:'2026-09-01 09:40:00',departed_at:'2026-09-01 10:00:00',driver_signature_present:1,sender_signature_present:1,tn_datetime_missing:1,data_from_tracker:1,data_from_driver:1,data_invented:0,issues:['Нет подписи водителя'],documents:withDocument?[{id:301,download_name:'Рейс_244_Заявка_275006_ТН_01.jpg',download_url:'/download/301',delete_url:'/delete/301'}]:[]},
 trip:{complete_count:1,request_count:3,document_count:withDocument?3:2,missing_summary:['подпись водителя 1']},
});
const server=http.createServer((req,res)=>{
 if(req.method==='POST'){
  postCount++;let body=[];req.on('data',chunk=>body.push(chunk));req.on('end',()=>{res.setHeader('Content-Type','application/json; charset=utf-8');res.end(response(req.url.endsWith('/documents')));});return;
 }
 if(req.url==='/'){res.setHeader('Content-Type','text/html; charset=utf-8');return res.end(html);}
 if(req.url?.startsWith('/download/')){res.setHeader('Content-Type','image/jpeg');return res.end('test');}
 const file=path.resolve(root,'public','.'+req.url.split('?')[0]);
 if(!file.startsWith(path.join(root,'public')+path.sep)||!fs.existsSync(file)){res.statusCode=404;return res.end();}
 res.setHeader('Content-Type',file.endsWith('.css')?'text/css':file.endsWith('.js')?'application/javascript':'application/octet-stream');res.end(fs.readFileSync(file));
});
await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));const browser=await chromium.launch({headless:true,...(process.env.PLANEX_BROWSER_EXECUTABLE?{executablePath:process.env.PLANEX_BROWSER_EXECUTABLE}:{})});
try{
 const page=await browser.newPage({viewport:{width:1680,height:1000}}),errors=[];page.on('pageerror',error=>errors.push(error.message));await page.goto('http://127.0.0.1:'+server.address().port,{waitUntil:'networkidle'});
 assert.equal(await page.locator('.metric-card').count(),4,'four compact metrics');
 assert.equal(await page.locator('.logistics-trip').count(),2,'full-width trip list');
 assert.equal(await page.locator('input[name="hide_complete"]').isChecked(),true,'complete trips hidden by default');
 assert.equal(await page.locator('.logistics-trip[open]').count(),0,'trip list starts collapsed');
 const widths=await page.locator('.logistics-workspace').evaluate(el=>({workspace:el.getBoundingClientRect().width,parent:el.parentElement.getBoundingClientRect().width}));
 assert.ok(widths.workspace>=widths.parent*.96,'trip list uses full workspace width');
 await page.locator('.logistics-trip').first().locator('summary').click();
 assert.equal(await page.locator('.logistics-trip').first().getAttribute('open'),'','clicked trip expands');
 assert.equal(await page.locator('.logistics-trip').first().locator('.logistics-request-row').count(),3,'expanded trip renders request rows');
 assert.equal((await page.locator('.logistics-trip').first().locator('.logistics-request-row').first().evaluate(el=>getComputedStyle(el).gridTemplateColumns.split(' ').length)),6,'desktop request is one integrated row');
 assert.deepEqual(await page.locator('.logistics-trip').first().locator('.logistics-request-head span').allTextContents(),['Заявка и маршрут','Загрузка: дата, приезд — уезд','Подписи','Данные','Сканы ТН','Комментарий'],'status column removed and data column added');
 assert.equal(await page.locator('[data-request-issues]').count(),0,'request errors are not rendered on hover');
 assert.equal(await page.locator('[data-autosave-form] button[type="submit"]').count(),0,'no manual save button');
 assert.equal(await page.locator('[data-upload-form] button[type="submit"]').count(),0,'no manual upload button');
 const requestNumbers=await page.locator('.logistics-trip').first().locator('.logistics-request-route b').allTextContents();
 assert.deepEqual(requestNumbers,['№ 275006','№ 274405','№ 271941'],'requests ordered by descending number');
 const second=page.locator('[data-request-id="12"]');
 assert.equal(await second.locator('.logistics-signatures input[type="checkbox"]').count(),3,'three signature/document checks are in one column');
 assert.equal(await second.locator('.logistics-data-source input[type="checkbox"]').count(),3,'three provenance checks are in data column');
 assert.equal(await second.locator('input[name="tn_datetime_missing"]').isChecked(),true,'missing datetime in TN is persisted');
 assert.equal(await second.locator('input[name="data_from_driver"]').isChecked(),true,'driver-provided data is persisted');
 assert.notEqual(await second.locator('input[type="checkbox"]').first().evaluate(el=>getComputedStyle(el).accentColor),'auto','checkbox uses ERP system accent');
 const warningColor=await second.evaluate(el=>getComputedStyle(el).backgroundColor);
 assert.ok(warningColor!=='rgba(0, 0, 0, 0)'&&warningColor!=='rgb(255, 255, 255)','checkbox-only row has system warning fill');
 await second.hover();
 assert.equal(await second.evaluate(el=>getComputedStyle(el).backgroundColor),warningColor,'hover keeps warning fill and reveals no errors');
 const interval=second.locator('input[name="pickup_interval"]');
 await interval.fill('01092609401000');
 assert.equal(await interval.inputValue(),'01.09.26 09:40 - 10:00','digits are formatted into one pickup interval');
 await interval.press('a');
 assert.equal(await interval.inputValue(),'01.09.26 09:40 - 10:00','non-digit input is blocked');
 await second.locator('[data-save-label]').filter({hasText:/Сохранено|Собрано/}).waitFor();
 assert.equal(await second.evaluate(el=>el.classList.contains('has-time')),true,'filled time gives system success row');
 await second.locator('input[name="driver_signature_present"]').check();
 await second.locator('[data-save-label]').filter({hasText:/Сохранено|Собрано/}).waitFor();
 assert.ok(postCount>=1,'field change saved automatically');
 await second.locator('input[type="file"]').setInputFiles({name:'tn.jpg',mimeType:'image/jpeg',buffer:Buffer.from('test-image')});
 await second.locator('.logistics-document').filter({hasText:'Рейс_244_Заявка_275006_ТН_01.jpg'}).waitFor();
 assert.equal(await page.locator('.logistics-trip').first().locator('[data-trip-document-count]').textContent(),'3','trip file count updates after upload');
 assert.equal(await second.locator('.logistics-document a').innerText(),'Рейс_244_Заявка_275006_ТН_01.jpg','full document filename is visible');
 page.once('dialog',dialog=>dialog.accept());
 await second.locator('.logistics-document-delete').click();
 await page.locator('.logistics-trip').first().locator('[data-trip-document-count]').filter({hasText:'2'}).waitFor();
 assert.equal(await second.locator('.logistics-document').count(),0,'document row updates after delete');
 assert.equal(await page.locator('.logistics-trip').first().locator('[data-trip-document-count]').textContent(),'2','trip file count decreases after delete');
 assert.ok(postCount>=2,'file selection uploaded automatically');
 const evidence=process.env.PLANEX_BROWSER_EVIDENCE;if(evidence){fs.mkdirSync(path.dirname(evidence),{recursive:true});await page.screenshot({path:evidence,fullPage:true});}
 await page.setViewportSize({width:1366,height:900});
 assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=document.documentElement.clientWidth),true,'no 1366px overflow');
 assert.equal((await second.evaluate(el=>getComputedStyle(el).gridTemplateColumns.split(' ').length)),6,'1366px keeps integrated request row');
 const compactEvidence=process.env.PLANEX_BROWSER_EVIDENCE_1366;if(compactEvidence)await page.screenshot({path:compactEvidence,fullPage:true});
 await page.setViewportSize({width:1280,height:900});
 await page.locator('.logistics-workspace').evaluate(el=>{el.style.width='1034px';});
 assert.equal(await second.evaluate(el=>el.scrollWidth<=el.parentElement.clientWidth),true,'request row fits ERP workspace at 1280px');
 const rowHeight=await second.evaluate(el=>el.getBoundingClientRect().height);
 assert.ok(rowHeight<=70,'1280px request row stays compact, actual '+rowHeight);
 const compact1280Evidence=process.env.PLANEX_BROWSER_EVIDENCE_1280;if(compact1280Evidence)await page.screenshot({path:compact1280Evidence,fullPage:true});
 await page.locator('.logistics-workspace').evaluate(el=>{el.style.width='';});
 await page.setViewportSize({width:390,height:844});
 assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=document.documentElement.clientWidth),true,'no mobile overflow');
 assert.equal((await second.evaluate(el=>getComputedStyle(el).gridTemplateColumns.split(' ').length)),1,'mobile request uses one column');
 assert.equal(errors.length,0,'page errors: '+errors.join('; '));
 console.log('TRIP_COMPLETION_BROWSER=PASS');
}finally{await browser.close();await new Promise(resolve=>server.close(resolve));}
