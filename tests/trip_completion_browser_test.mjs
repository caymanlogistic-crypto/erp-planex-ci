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
 request:{id:12,is_complete:false,issues:['Нет подписи водителя'],documents:withDocument?[{id:301,download_name:'Рейс_244_Заявка_275006_ТН_01.jpg',download_url:'/download/301',delete_url:'/delete/301'}]:[]},
 trip:{complete_count:1,request_count:3,missing_summary:['подпись водителя 1']},
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
await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));const browser=await chromium.launch({headless:true});
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
 assert.equal((await page.locator('.logistics-trip').first().locator('.logistics-request-row').first().evaluate(el=>getComputedStyle(el).gridTemplateColumns.split(' ').length)),7,'desktop request is one integrated row');
 assert.equal(await page.locator('[data-autosave-form] button[type="submit"]').count(),0,'no manual save button');
 assert.equal(await page.locator('[data-upload-form] button[type="submit"]').count(),0,'no manual upload button');
 const second=page.locator('.logistics-trip').first().locator('.logistics-request-row').nth(1);
 assert.notEqual(await second.locator('input[type="checkbox"]').first().evaluate(el=>getComputedStyle(el).accentColor),'auto','checkbox uses ERP system accent');
 await second.locator('input[name="driver_signature_present"]').check();
 await second.locator('[data-save-label]').filter({hasText:/Сохранено|Собрано/}).waitFor();
 assert.ok(postCount>=1,'field change saved automatically');
 await second.locator('input[type="file"]').setInputFiles({name:'tn.jpg',mimeType:'image/jpeg',buffer:Buffer.from('test-image')});
 await second.locator('.logistics-document').filter({hasText:'ТН 01'}).waitFor();
 assert.ok(postCount>=2,'file selection uploaded automatically');
 const evidence=process.env.PLANEX_BROWSER_EVIDENCE;if(evidence){fs.mkdirSync(path.dirname(evidence),{recursive:true});await page.screenshot({path:evidence,fullPage:true});}
 await page.setViewportSize({width:1366,height:900});
 assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=document.documentElement.clientWidth),true,'no 1366px overflow');
 assert.equal((await second.evaluate(el=>getComputedStyle(el).gridTemplateColumns.split(' ').length)),7,'1366px keeps integrated request row');
 const compactEvidence=process.env.PLANEX_BROWSER_EVIDENCE_1366;if(compactEvidence)await page.screenshot({path:compactEvidence,fullPage:true});
 await page.setViewportSize({width:1280,height:900});
 await page.locator('.logistics-workspace').evaluate(el=>{el.style.width='1034px';});
 assert.equal(await second.evaluate(el=>el.scrollWidth<=el.parentElement.clientWidth),true,'request row fits ERP workspace at 1280px');
 assert.ok(await second.evaluate(el=>el.getBoundingClientRect().height<=62),'1280px request row stays compact');
 await page.locator('.logistics-workspace').evaluate(el=>{el.style.width='';});
 await page.setViewportSize({width:390,height:844});
 assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=document.documentElement.clientWidth),true,'no mobile overflow');
 assert.equal((await second.evaluate(el=>getComputedStyle(el).gridTemplateColumns.split(' ').length)),1,'mobile request uses one column');
 assert.equal(errors.length,0,'page errors: '+errors.join('; '));
 console.log('TRIP_COMPLETION_BROWSER=PASS');
}finally{await browser.close();await new Promise(resolve=>server.close(resolve));}
