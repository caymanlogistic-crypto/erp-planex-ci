import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import http from 'node:http';
import {fileURLToPath} from 'node:url';
import {createRequire} from 'node:module';
import {execFileSync} from 'node:child_process';
const require = createRequire(import.meta.url);
const {chromium} = require('playwright');
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const html = execFileSync(process.env.PLANEX_PHP, [path.join(root,'tests/fixtures/feo_weights_render.php')], {encoding:'utf8'});
const server = http.createServer((req,res) => {
  if (req.url === '/') { res.setHeader('Content-Type','text/html; charset=utf-8'); return res.end(html); }
  const file = path.resolve(root,'public','.'+req.url.split('?')[0]);
  if (!file.startsWith(path.join(root,'public')+path.sep) || !fs.existsSync(file)) { res.statusCode=404; return res.end(); }
  res.setHeader('Content-Type',file.endsWith('.css')?'text/css':'text/javascript'); res.end(fs.readFileSync(file));
});
await new Promise(resolve => server.listen(0,'127.0.0.1',resolve));
const browser = await chromium.launch({headless:true});
try {
  const page = await browser.newPage(); const errors = []; page.on('pageerror', error => errors.push(error.message));
  let submitted;
  await page.route('**/company/feo/requests/weights/**', async route => {
    const url = route.request().url(); let result;
    if (url.endsWith('/preview')) result = {success:true,preview_token:'preview-1',duplicates:0,preview:{rows:[{id:1,zayavka_id:'100001',mass_netto:'1.2500',mass_brutto:'1.5000'},{id:2,zayavka_id:'100002',mass_netto:'2.0000',mass_brutto:'2.3000'}],count:2,state_hash:'fixture'}};
    else if (url.endsWith('/create')) { submitted = route.request().postData(); result = {success:true,batch_id:8,notification:'disabled'}; }
    else if (url.endsWith('/revert')) result = {success:false,message:'Вес заявки изменён после блока.'};
    else result = {success:true,batch:{id:7,name:'<img src=x onerror=alert(1)>',comment:'Проверка',created_by_name:'Менеджер',created_at:'2026-10-14',reverted_at:null},items:[{zayavka_id:'100001',before_netto:'1.2500',after_netto:'1.3000',before_brutto:'1.5000',after_brutto:'1.7000'}]};
    await route.fulfill({status:result.success?200:422,contentType:'application/json',body:JSON.stringify(result)});
  });
  const url = 'http://127.0.0.1:'+server.address().port;
  for (const width of [1366,390]) {
    await page.setViewportSize({width,height:900}); await page.goto(url);
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth),false,'Page horizontal overflow '+width);
    await page.locator('[data-open-weight]').click();
    await page.locator('[name="request_ids"]').fill('100001 100002');
    await page.locator('[data-load]').click();
    await page.locator('[data-edit-rows] tr').first().waitFor();
    assert.equal(await page.locator('[data-edit-rows] tr').count(),2);
    await page.locator('[data-edit-rows] tr').first().locator('[name="netto_kg"]').fill('1300');
    await page.locator('[data-edit-rows] tr').first().locator('[name="brutto_kg"]').fill('1700');
    await page.locator('[data-edit-rows] tr').nth(1).locator('[name="brutto_kg"]').fill('2400');
    await page.locator('[data-commit]').click();
    await page.waitForLoadState('load');
    assert.ok(submitted, `Create request missing: ${await page.locator('[data-form-error]').innerText()} / ${errors.join('; ')}`);
    assert.match(submitted, /"100001":\{"netto_kg":"1300","brutto_kg":"1700"\}/);
    assert.match(submitted, /"100002":\{"netto_kg":"2000","brutto_kg":"2400"\}/);
    await page.locator('[data-detail="7"]').click();
    assert.equal(await page.locator('[data-detail-body] img').count(),0,'Detail escapes server data');
    await page.locator('[data-detail-dialog] [data-close-dialog]').click();
    await page.locator('[data-revert="7"]').click();
    await page.locator('[data-confirm-revert]').click();
    await page.waitForFunction(() => document.querySelector('[data-revert-error]')?.textContent.includes('изменён'));
    assert.match(await page.locator('[data-revert-error]').innerText(),/изменён/);
  }
  assert.deepEqual(errors,[]);
  console.log('FEO_WEIGHTS_BROWSER_PASS');
} finally { await browser.close(); await new Promise(resolve => server.close(resolve)); }
