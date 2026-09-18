import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import http from 'node:http';
import { fileURLToPath } from 'node:url';
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
const require = createRequire(import.meta.url);
const {chromium} = require('playwright');
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const render = mode => execFileSync(process.env.PLANEX_PHP || 'php',[path.join(root,'tests/fixtures/feo_transfers_render.php'),mode],{encoding:'utf8'});
const html = render('transfers'), coordinates = render('coordinates');
const server = http.createServer((req,res) => {
  if (req.url === '/' || req.url === '/coordinates') { res.setHeader('Content-Type','text/html; charset=utf-8'); return res.end(req.url === '/' ? html : coordinates); }
  const file = path.resolve(root,'public','.'+req.url.split('?')[0]);
  if (!file.startsWith(path.join(root,'public')+path.sep) || !fs.existsSync(file)) { res.statusCode=404; return res.end(); }
  res.setHeader('Content-Type',file.endsWith('.css')?'text/css':file.endsWith('.js')?'text/javascript':'application/octet-stream');
  res.end(fs.readFileSync(file));
});
await new Promise(resolve => server.listen(0,'127.0.0.1',resolve));
const browser = await chromium.launch({headless:true});
const screenshotDir = process.env.PLANEX_BROWSER_EVIDENCE;
if (screenshotDir) fs.mkdirSync(screenshotDir,{recursive:true});
try {
  const page = await browser.newPage();
  const errors=[]; page.on('pageerror',e=>errors.push(e.message));
  let createCalls=0,previewCalls=0;
  const destination={address:'Новый адрес <img src=x onerror=alert(1)>',latitude:'55.456',longitude:'37.123',quality:'Только улица, дом не определён',precision:'street'};
  await page.route('**/company/feo/**',async route => {
    const url=route.request().url();
    let result;
    if(url.endsWith('/geocode')) result={success:true,geo_token:'fixture-geo',result:destination};
    else if(url.endsWith('/preview')) {previewCalls++; result={success:true,preview_token:'fixture-preview',duplicates:0,preview:{count:2,destination,rows:[{zayavka_id:'100001',mno_adres_pogruzki:'Исходный адрес',mno_d:'55',mno_sh:'37'},{zayavka_id:'100002',mno_adres_pogruzki:'Второй адрес',mno_d:null,mno_sh:null}]}};}
    else if(url.endsWith('/create')) {createCalls++; result={success:true,batch_id:19,notification:'failed'};}
    else if(url.endsWith('/revert')) result={success:false,message:'Заявка перенесена позже. Возврат всего пакета остановлен.'};
    else result={success:true};
    await route.fulfill({status:result.success?200:422,contentType:'application/json',body:JSON.stringify(result)});
  });
  const url='http://127.0.0.1:'+server.address().port;
  for (const width of [1920,1366,800,390]) {
    await page.setViewportSize({width,height:900}); await page.goto(url);
    assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false,'Page overflow '+width);
    await page.locator('[data-open-transfer]').first().click();
    await page.locator('[name=request_ids]').fill('100001\n100002');
    await page.locator('[name=source_request_id]').fill('100003');
    await page.locator('[data-preview]').click(); await page.locator('[data-preview-result]').waitFor({state:'visible'});
    assert.match(await page.locator('[data-preview-summary]').innerText(),/Только улица/);
    assert.equal(await page.locator('[data-preview-rows] img').count(),0,'Destination HTML injection');
    assert.equal(await page.locator('[data-create-dialog]').evaluate(e=>e.scrollWidth>e.clientWidth),false,'Dialog overflow '+width);
    if(screenshotDir) await page.screenshot({path:path.join(screenshotDir,`transfer-${width}.png`),fullPage:true});
    await page.locator('[name=source_request_id]').fill('100004');
    assert.equal(await page.locator('[data-preview-result]').isVisible(),false,'Edited destination invalidates preview');
    await page.locator('[data-create-dialog] [data-close-dialog]').click();
  }
  await page.goto(url); await page.locator('[data-open-transfer]').first().click();
  await page.locator('[name=request_ids]').fill('100001\n100002');
  await page.locator('[name=mode][value=address]').check();
  await page.locator('[name=address]').fill('Москва, улица Примерная');
  await page.locator('[data-geocode]').click(); await page.locator('[data-geo-result]').waitFor({state:'visible'});
  assert.equal(await page.locator('[data-geo-lat]').innerText(),'55.456');
  await page.locator('[data-preview]').click(); await page.locator('[data-preview-result]').waitFor({state:'visible'});
  assert.equal(await page.locator('[data-commit]').isEnabled(),true,'Imprecise coordinate warning does not block');
  await page.locator('[data-commit]').click(); await page.locator('[data-page-message]').waitFor({state:'visible'});
  assert.match(await page.locator('[data-page-message]').innerText(),/сохранён.*MAX не отправлено/);
  assert.equal(createCalls,1,'One commit call');
  await page.locator('[data-revert]').click(); await page.locator('[data-confirm-revert]').click();
  await page.locator('[data-revert-error]').waitFor({state:'visible'});
  assert.match(await page.locator('[data-revert-error]').innerText(),/Возврат всего пакета остановлен/);
  await page.goto(url+'/coordinates'); await page.locator('[name=address]').fill('Москва');
  await page.locator('[data-geocode]').click(); await page.locator('[data-geo-result]').waitFor({state:'visible'});
  assert.equal(await page.locator('[data-geo-lon]').innerText(),'37.123');
  await page.locator('[name=address]').fill('Новый запрос');
  assert.equal(await page.locator('[data-geo-result]').isVisible(),false,'Edited address invalidates geocoder result');
  assert.deepEqual(errors,[]);
  console.log(`FEO_TRANSFER_BROWSER=PASS synthetic API UI fixture; 1920/1366/800/390, preview invalidation, quality, XSS, create, revert conflict, coordinates; previews=${previewCalls}`);
} finally { await browser.close(); await new Promise(resolve=>server.close(resolve)); }
