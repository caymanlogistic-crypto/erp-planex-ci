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
const server=http.createServer((req,res)=>{if(req.url==='/'){res.setHeader('Content-Type','text/html; charset=utf-8');return res.end(html);}const file=path.resolve(root,'public','.'+req.url.split('?')[0]);if(!file.startsWith(path.join(root,'public')+path.sep)||!fs.existsSync(file)){res.statusCode=404;return res.end();}res.setHeader('Content-Type',file.endsWith('.css')?'text/css':'application/octet-stream');res.end(fs.readFileSync(file));});
await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));const browser=await chromium.launch({headless:true});
try{
 const page=await browser.newPage({viewport:{width:1440,height:1100}}),errors=[];page.on('pageerror',error=>errors.push(error.message));await page.goto('http://127.0.0.1:'+server.address().port,{waitUntil:'networkidle'});
 assert.equal(await page.locator('.metric-card').count(),4,'four compact metrics');assert.equal(await page.locator('.logistics-queue-table tbody tr').count(),2,'work queue');assert.equal(await page.locator('input[name="hide_complete"]').isChecked(),true,'complete trips hidden by default');assert.equal(await page.locator('.logistics-request').count(),3,'all trip requests');assert.equal(await page.locator('.logistics-request').first().getAttribute('open'),'','first request expanded');
 await page.locator('.logistics-request').nth(1).locator('summary').click();assert.equal(await page.locator('.logistics-request').nth(1).locator('input[type="datetime-local"]').count(),2,'arrival and departure fields');assert.equal(await page.locator('.logistics-request').nth(1).locator('input[type="checkbox"]').count(),2,'signature checks');
 assert.equal((await page.locator('.logistics-workspace').evaluate(el=>getComputedStyle(el).gridTemplateColumns.split(' ').length)),2,'desktop split workspace');
 const evidence=process.env.PLANEX_BROWSER_EVIDENCE;if(evidence){fs.mkdirSync(path.dirname(evidence),{recursive:true});await page.screenshot({path:evidence,fullPage:true});}
 await page.setViewportSize({width:390,height:844});assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=document.documentElement.clientWidth),true,'no mobile overflow');assert.equal((await page.locator('.logistics-workspace').evaluate(el=>getComputedStyle(el).gridTemplateColumns.split(' ').length)),1,'mobile single column');assert.equal(errors.length,0,'page errors: '+errors.join('; '));console.log('TRIP_COMPLETION_BROWSER=PASS');
}finally{await browser.close();await new Promise(resolve=>server.close(resolve));}
