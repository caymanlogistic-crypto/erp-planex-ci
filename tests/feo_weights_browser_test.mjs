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
const browser = await chromium.launch({headless:true,...(process.env.PLANEX_BROWSER_BINARY ? {executablePath:process.env.PLANEX_BROWSER_BINARY} : {})});
try {
  const page = await browser.newPage(); const errors = []; page.on('pageerror', error => errors.push(error.message));
  let submitted;
  await page.route('**/company/feo/requests/weights/**', async route => {
    const url = route.request().url(); let result;
    if (url.endsWith('/preview')) result = route.request().postData()?.includes('999999')
      ? {success:false,message:'Заявки не найдены: 999999. Блок не сохранён.'}
      : {success:true,preview_token:'preview-1',duplicates:Math.max(0,(route.request().postData().match(/100001/g)?.length || 0)-1),preview:{rows:[{id:1,zayavka_id:'100001',mass_netto:'1.2500',mass_brutto:'1.5000'},{id:2,zayavka_id:'100002',mass_netto:null,mass_brutto:'0.0000'},{id:3,zayavka_id:'100003',mass_netto:'0.0001',mass_brutto:'0.0002'}],count:3,state_hash:'fixture'}};
    else if (url.endsWith('/create')) { submitted = route.request().postData(); result = {success:true,batch_id:8,notification:'disabled'}; }
    else if (url.endsWith('/revert')) result = {success:false,message:'Вес заявки изменён после блока.'};
    else result = {success:true,batch:{id:7,status:'applied',name:'<img src=x onerror=alert(1)>',comment:'Проверка',created_by_name:'Менеджер',created_at:'2026-10-14',reverted_at:null},items:[{zayavka_id:'100001',before_netto:'1.2500',after_netto:'1.3000',before_brutto:'1.5000',after_brutto:'1.7000'},{zayavka_id:'100002',before_netto:null,after_netto:'0.0000',before_brutto:'0.0000',after_brutto:'2.4000'}]};
    await route.fulfill({status:result.success?200:422,contentType:'application/json',body:JSON.stringify(result)});
  });
  const url = 'http://127.0.0.1:'+server.address().port;
  for (const width of [1366,390]) {
    submitted=undefined;
    await page.setViewportSize({width,height:900}); await page.goto(url);
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth),false,'Page horizontal overflow '+width);
    await page.locator('[data-open-weight]').click();
    assert.equal(await page.locator('.registry-ui-modal[open]').count(),1,'Shared modal class');
    assert.equal(await page.locator('[data-commit]').isDisabled(),true,'Commit disabled before preview');
    await page.locator('[name="request_ids"]').fill('999999');
    await page.locator('[data-ids-error]').getByText('999999',{exact:false}).waitFor();
    assert.match(await page.locator('[data-ids-error]').innerText(),/не найдены в системе/);
    await page.locator('[name="request_ids"]').fill('100001 100002 100003');
    await page.locator('[data-edit-rows] tr').first().waitFor();
    assert.equal(await page.locator('[data-edit-rows] tr').count(),3);
    assert.equal(await page.locator('[data-commit]').isEnabled(),true,'Commit enabled by automatic preview');
    assert.match(await page.locator('[data-edit-rows] tr').nth(1).locator('td').nth(1).innerText(),/—/,'NULL shown as missing');
    assert.match(await page.locator('[data-edit-rows] tr').nth(1).locator('td').nth(3).innerText(),/0 кг/,'Zero distinct from NULL');
    assert.equal(await page.locator('[data-edit-rows] tr').nth(2).locator('[name="netto_kg"]').inputValue(),'0.1','A tenth of a kilogram remains editable at exact precision');
    assert.doesNotMatch(await page.locator('[data-edit-summary]').innerText(),/Повторов/,'No duplicate message for unique IDs');
    await page.locator('[name="request_ids"]').fill('100001, 100002, 100003, 100001, 100001');
    await page.waitForFunction(() => document.querySelector('[data-edit-summary]')?.textContent.includes('Повторов объединено: 2'));
    await page.locator('[data-commit]').click();
    assert.match(await page.locator('[data-table-error]').innerText(),/не изменён/,'No-change error under table');
    await page.locator('[data-edit-rows] tr').first().locator('[name="netto_kg"]').fill('1300');
    await page.locator('[data-edit-rows] tr').first().locator('[name="brutto_kg"]').fill('1700');
    await page.locator('[data-edit-rows] tr').nth(1).locator('[name="brutto_kg"]').fill('2400');
    await page.locator('[data-commit]').click();
    await page.waitForLoadState('load');
    assert.ok(submitted, `Create request missing: ${await page.locator('[data-table-error]').innerText()} / ${errors.join('; ')}`);
    assert.match(submitted, /"100001":\{"netto_kg":"1300","brutto_kg":"1700"\}/);
    assert.match(submitted, /"100002":\{"netto_kg":"","brutto_kg":"2400"\}/);
    assert.match(submitted, /"100003":\{"netto_kg":"0.1","brutto_kg":"0.2"\}/);
    assert.match(submitted, /name="name"\r\n\r\n\r\n/,'Name submitted blank by compact form');
    assert.match(submitted, /name="comment"\r\n\r\n\r\n/,'Comment submitted blank by compact form');
    await page.locator('[data-detail="7"]').click();
    await page.locator('[data-detail-body] .registry-ui-compact-table').waitFor();
    assert.equal(await page.locator('[data-detail-body] img').count(),0,'Detail escapes server data');
    assert.equal(await page.locator('[data-detail-body] .registry-ui-compact-table').count(),1,'Shared detail table');
    const weightTablePadding=await page.locator('[data-detail-body] .registry-ui-compact-table').evaluate(e=>[e.querySelector('th'),e.querySelector('td')].map(cell=>{
      const style=getComputedStyle(cell);
      return [style.paddingTop,style.paddingBottom,style.paddingLeft,style.boxSizing];
    }));
    assert.deepEqual(weightTablePadding,[['8px','8px','10px','border-box'],['8px','8px','10px','border-box']],`Shared compact table padding ${width}`);
    assert.match(await page.locator('[data-detail-body]').innerText(),/Заявок: 2/);
    await page.locator('[data-detail-revert]').click();
    assert.equal(await page.locator('[data-confirm-revert]').isVisible(),true,'Separate final confirmation');
    await page.locator('[data-detail-dialog] [data-close-dialog]').first().click();
    await page.locator('[data-detail="7"]').click();
    await page.locator('[data-detail-body] .registry-ui-compact-table').waitFor();
    assert.equal(await page.locator('[data-detail-revert]').isVisible(),true,'Primary action restored after cancel');
    await page.locator('[data-detail-dialog] [data-close-dialog]').first().click();
    await page.locator('[data-revert="7"]').click();
    await page.locator('[data-confirm-revert]:not([hidden])').waitFor();
    assert.match(await page.locator('[data-detail-body]').innerText(),/100001, 100002 вернутся/);
    await page.locator('[data-confirm-revert]').click();
    await page.waitForFunction(() => document.querySelector('[data-revert-error]')?.textContent.includes('изменён'));
    assert.match(await page.locator('[data-revert-error]').innerText(),/изменён/);
  }
  assert.deepEqual(errors,[]);
  console.log('FEO_WEIGHTS_BROWSER_PASS');
} finally { await browser.close(); await new Promise(resolve => server.close(resolve)); }
