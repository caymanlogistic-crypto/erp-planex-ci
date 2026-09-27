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
let senderReady=false,submitted=false;
const server=http.createServer((req,res)=>{
  if(req.url?.endsWith('/review')) {
    res.setHeader('Content-Type','application/json; charset=utf-8');
    if(req.method==='POST'){
      const chunks=[];req.on('data',chunk=>chunks.push(chunk));
      req.on('end',()=>{const sent=Buffer.concat(chunks).toString('latin1');submitted=sent.includes('name="package"')&&sent.includes('name="snapshot_hash"');res.end(JSON.stringify({ok:true,status:'review',version:1,mail_status:'accepted'}));});
      return;
    }
    return res.end(JSON.stringify({ok:true,status:'work',version:0,problems:[{label:'Нет ТН',ids:['271941']}],snapshot_hash:'synthetic',sender_ready:senderReady,recipient_count:2}));
  }
  if(req.url==='/'){res.setHeader('Content-Type','text/html; charset=utf-8');return res.end(html);}
  const file=path.resolve(root,'public','.'+req.url.split('?')[0]);
  if(!file.startsWith(path.join(root,'public')+path.sep)||!fs.existsSync(file)){res.statusCode=404;return res.end();}
  res.setHeader('Content-Type',file.endsWith('.css')?'text/css':file.endsWith('.js')?'application/javascript':'application/octet-stream');
  res.end(fs.readFileSync(file));
});
await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
const browser=await chromium.launch({headless:true,...(process.env.PLANEX_BROWSER_EXECUTABLE?{executablePath:process.env.PLANEX_BROWSER_EXECUTABLE}:{})});
try {
  const page=await browser.newPage({viewport:{width:1440,height:900}}),errors=[],alerts=[];
  page.on('pageerror',error=>errors.push(error.message));
  page.on('dialog',async dialog=>{alerts.push(dialog.message());await dialog.dismiss();});
  await page.goto('http://127.0.0.1:'+server.address().port,{waitUntil:'networkidle'});
  assert.equal(await page.locator('.lab-review-group--work').isVisible(),true);
  assert.equal(await page.locator('.lab-review-group--review').isVisible(),false);
  await page.locator('input[name="show_all_trips"]').check();
  assert.equal(await page.locator('.lab-review-group--review').isVisible(),true);
  await page.locator('input[name="show_all_trips"]').uncheck();
  await page.locator('.logistics-trip').first().locator('summary').click();
  await page.locator('.logistics-trip').first().getByRole('button',{name:'Передать на проверку'}).click();
  const dialog=page.locator('.lab-review-dialog');
  await dialog.waitFor({state:'visible',timeout:5000}).catch(error=>{throw new Error(`${error.message}; alerts=${alerts.join(';')}; pageerrors=${errors.join(';')}`);});
  assert.match(await dialog.innerText(),/Нет ТН.*271941/s);
  assert.equal(await dialog.getByRole('button',{name:'Всё верно, передать'}).isDisabled(),true,'missing sender blocks submission');
  await dialog.getByRole('button',{name:'Отмена'}).click();
  assert.equal(await dialog.isVisible(),false);
  senderReady=true;
  await page.evaluate(()=>{window.TripCompletionReport.buildPackage=async()=>({bytes:new Uint8Array([80,75,3,4]),name:'Рейс_244_комплект.zip'});});
  await page.locator('.logistics-trip').first().getByRole('button',{name:'Передать на проверку'}).click();
  await dialog.waitFor({state:'visible'});
  await dialog.getByRole('button',{name:'Всё верно, передать'}).click();
  await page.waitForFunction(()=>document.querySelector('.lab-review-dialog')?.open===false);
  assert.equal(submitted,true,'package and snapshot are submitted together');
  assert.deepEqual(errors,[],'no browser errors');
  console.log('TRIP_REVIEW_BROWSER=PASS');
} finally { await browser.close(); await new Promise(resolve=>server.close(resolve)); }
