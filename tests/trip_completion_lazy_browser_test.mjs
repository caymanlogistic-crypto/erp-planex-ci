import assert from 'node:assert/strict';
import fs from 'node:fs';
import http from 'node:http';
import path from 'node:path';
import {createRequire} from 'node:module';
import {execFileSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';

const require=createRequire(import.meta.url),{chromium}=require('playwright');
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const fixture=(mode)=>execFileSync(process.env.PLANEX_PHP||'php',[path.join(root,'tests/fixtures/trip_completion_render.php'),mode],{encoding:'utf8'});
const list=fixture('reviewer-lazy'),body=fixture('reviewer-body');
let bodyRequests=0;
const server=http.createServer((req,res)=>{
  if(req.url==='/reviewer'){res.setHeader('Content-Type','text/html; charset=utf-8');return res.end(list);}
  if(/^\/company\/tn-review\/\d+\/body$/.test(req.url)){bodyRequests++;res.setHeader('Content-Type','text/html; charset=utf-8');return res.end(body);}
  const file=path.resolve(root,'public','.'+req.url.split('?')[0]);
  if(!file.startsWith(path.join(root,'public')+path.sep)||!fs.existsSync(file)){res.statusCode=404;return res.end();}
  res.setHeader('Content-Type',file.endsWith('.css')?'text/css':file.endsWith('.js')?'application/javascript':'application/octet-stream');
  res.end(fs.readFileSync(file));
});
await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
const browser=await chromium.launch({headless:true,...(process.env.PLANEX_BROWSER_EXECUTABLE?{executablePath:process.env.PLANEX_BROWSER_EXECUTABLE}:{})});
try{
  const page=await browser.newPage({viewport:{width:1280,height:900}}),errors=[];
  page.on('pageerror',error=>errors.push(error.message));
  await page.goto('http://127.0.0.1:'+server.address().port+'/reviewer',{waitUntil:'networkidle'});
  assert.equal(bodyRequests,0,'Closed trips do not request full forms');
  assert.equal(await page.locator('[data-request-row]').count(),0,'Initial DOM contains only trip summaries');
  assert.equal(await page.locator('[data-review-found]').innerText(),'3');
  await page.locator('.registry-ui-search').fill('275006');
  assert.equal(await page.locator('[data-review-found]').innerText(),'1','Search finds requests before details load');
  await page.locator('[data-review-reset]').click();
  const trip=page.locator('.logistics-trip[data-trip-id="1"]');
  await trip.locator('summary').click();
  await trip.locator('[data-request-row]').first().waitFor();
  assert.equal(bodyRequests,1,'Opening a trip loads exactly one body');
  assert.equal(await trip.locator('[data-request-row]').count(),3);
  assert.equal(await trip.locator('input[name="pickup_interval"]').first().isEnabled(),true,'Review trip stays editable');
  await trip.locator('summary').click();
  await trip.locator('summary').click();
  assert.equal(bodyRequests,1,'Reopening does not refetch the body');
  assert.deepEqual(errors,[]);
  console.log('TRIP_COMPLETION_LAZY_BROWSER=PASS');
}finally{await browser.close();await new Promise(resolve=>server.close(resolve));}
