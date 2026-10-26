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
  let submitted, deleteSubmitted, previewRequests = 0;
  await page.route('**/company/feo/requests/weights/**', async route => {
    const url = route.request().url(); let result;
    if (url.endsWith('/preview')) {
      previewRequests++;
      const raw = route.request().postData() || '';
      const id = raw.includes('100003') ? '100003' : raw.includes('100004') ? '100004' : '100001';
      const row = id === '100003' ? {id:3,zayavka_id:id,mass_netto:null,mass_brutto:'0.0000'}
        : id === '100004' ? {id:4,zayavka_id:id,mass_netto:'0.0001',mass_brutto:'0.0002'}
        : {id:1,zayavka_id:id,mass_netto:'1.2500',mass_brutto:'1.5000'};
      result = raw.includes('999999')
        ? {success:false,message:'Заявки не найдены: 999999. Блок не сохранён.'}
        : {success:true,preview_token:'preview-1',preview:{rows:[row],count:1,state_hash:'fixture'}};
    }
    else if (url.endsWith('/create')) { submitted = route.request().postData(); result = {success:true,batch_id:8,notification:'disabled'}; }
    else if (url.endsWith('/revert')) result = {success:false,message:'Вес заявки изменён после блока.'};
    else if (url.endsWith('/delete')) { deleteSubmitted = url; result = {success:true,batch_id:9,status:'deleted'}; }
    else {
      const historical = url.includes('/6/json');
      const returned = url.includes('/9/json');
      result = {success:true,batch:{id:returned?9:historical?6:7,status:returned?'reverted':'applied',name:'<img src=x onerror=alert(1)>',comment:'Проверка',created_by_name:'Менеджер',created_at:'2026-10-14',reverted_at:returned?'2026-10-15':null},items:historical
        ? [{zayavka_id:'100001',before_netto:'1.2500',after_netto:'1.3000',before_brutto:'1.5000',after_brutto:'1.7000'},{zayavka_id:'100002',before_netto:null,after_netto:'0.0000',before_brutto:'0.0000',after_brutto:'2.4000'}]
        : [{zayavka_id:'100001',before_netto:'1.2500',after_netto:'1.3000',before_brutto:'1.5000',after_brutto:'1.7000'}]};
    }
    await route.fulfill({status:result.success?200:422,contentType:'application/json',body:JSON.stringify(result)});
  });
  const url = 'http://127.0.0.1:'+server.address().port;
  for (const width of [1280,390]) {
    submitted=undefined; deleteSubmitted=undefined; previewRequests=0;
    await page.setViewportSize({width,height:900}); await page.goto(url);
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth),false,'Page horizontal overflow '+width);
    await page.locator('[data-open-weight]').click();
    assert.equal(await page.locator('.registry-ui-modal[open]').count(),1,'Shared modal class');
    assert.equal(await page.locator('[data-commit]').isDisabled(),true,'Commit disabled before preview');
    const requestInput = page.locator('[name="request_ids"]');
    assert.equal(await requestInput.getAttribute('type'),'text','Leading zero safe text input');
    assert.equal(await requestInput.getAttribute('maxlength'),null,'Pasted IDs are never silently truncated');
    assert.equal(await requestInput.evaluate(node => document.activeElement === node),true,'Keyboard focus starts on request');
    assert.equal(await requestInput.evaluate(node => Math.round(node.getBoundingClientRect().height)),32,'Approved 32px field');
    await requestInput.fill('100001, 100002');
    await page.locator('[data-ids-error]').getByText('Укажите одну заявку.').waitFor();
    assert.equal(previewRequests,0,'Multiple IDs do not request preview');
    await requestInput.fill('999999');
    await page.locator('[data-ids-error]').getByText('999999',{exact:false}).waitFor();
    assert.match(await page.locator('[data-ids-error]').innerText(),/не найдены в системе/);
    await requestInput.fill('100003');
    await page.locator('[data-edit-rows] tr').first().waitFor();
    assert.match(await page.locator('[data-edit-rows] tr').first().locator('td').nth(1).innerText(),/—/,'NULL shown as missing');
    assert.match(await page.locator('[data-edit-rows] tr').nth(1).locator('td').nth(1).innerText(),/0 кг/,'Zero distinct from NULL');
    await requestInput.fill('100004');
    await page.waitForFunction(() => document.querySelector('[name="netto_kg"]')?.value === '0.1');
    assert.equal(await page.locator('[name="netto_kg"]').inputValue(),'0.1','A tenth of a kilogram remains editable');
    await requestInput.fill('100001');
    await page.waitForFunction(() => document.querySelector('[name="netto_kg"]')?.value === '1250');
    assert.equal(await page.locator('[data-ids-error]').isVisible(),false,'Corrected input clears error');
    assert.equal(await page.locator('[data-edit-rows] tr').count(),2,'Net and gross use two rows');
    assert.equal(await page.locator('[data-edit-rows] tr').first().locator('td').count(),3,'Three aligned columns');
    assert.equal(await page.locator('[data-edit-summary]').count(),0,'No count above preview');
    assert.equal(await page.locator('[data-commit]').isDisabled(),true,'Unchanged values keep save disabled');
    const aligned = await page.locator('[data-edit-rows]').evaluate(body => [...body.rows].map(row => [...row.cells].map(cell => {
      const box = cell.getBoundingClientRect(), style = getComputedStyle(cell);
      return [Math.round(box.x),Math.round(box.width),Math.round(box.height),style.paddingTop,style.paddingBottom,style.paddingLeft];
    })));
    assert.deepEqual(aligned[0],aligned[1],`All weight cells align at ${width}px`);
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth),false,`No page overflow at ${width}px`);
    if (process.env.PLANEX_WEIGHT_SCREENSHOT_DIR) {
      fs.mkdirSync(process.env.PLANEX_WEIGHT_SCREENSHOT_DIR,{recursive:true});
      await page.screenshot({path:path.join(process.env.PLANEX_WEIGHT_SCREENSHOT_DIR,`weight-create-${width}.png`)});
    }
    await page.locator('[name="netto_kg"]').fill('1300');
    assert.equal(await page.locator('[data-commit]').isEnabled(),true,'Editing net enables save');
    await page.locator('[name="netto_kg"]').fill('1250');
    assert.equal(await page.locator('[data-commit]').isDisabled(),true,'Restoring original net disables save');
    await page.locator('[name="netto_kg"]').fill('1300');
    await page.locator('[name="brutto_kg"]').fill('1700');
    await page.locator('[data-commit]').click();
    await page.waitForLoadState('load');
    assert.ok(submitted, `Create request missing: ${await page.locator('[data-table-error]').innerText()} / ${errors.join('; ')}`);
    assert.match(submitted, /"100001":\{"netto_kg":"1300","brutto_kg":"1700"\}/);
    assert.doesNotMatch(submitted, /"100002":/,'Exactly one target submitted');
    assert.match(submitted, /name="name"\r\n\r\n\r\n/,'Name submitted blank by compact form');
    assert.match(submitted, /name="comment"\r\n\r\n\r\n/,'Comment submitted blank by compact form');
    await page.locator('.toast--system').waitFor();
    assert.match(await page.locator('.toast--system').innerText(),/Блок #8/,'Shared notification');
    await page.locator('[data-detail="7"]').click();
    await page.locator('[data-detail-body] .registry-ui-compact-table--weight-single').waitFor();
    assert.equal(await page.locator('[data-detail-body] img').count(),0,'Detail escapes server data');
    assert.equal(await page.locator('[data-detail-body] .registry-ui-compact-table').count(),1,'Shared detail table');
    const weightTablePadding=await page.locator('[data-detail-body] .registry-ui-compact-table').evaluate(e=>[e.querySelector('th'),e.querySelector('td')].map(cell=>{
      const style=getComputedStyle(cell);
      return [style.paddingTop,style.paddingBottom,style.paddingLeft,style.boxSizing];
    }));
    assert.deepEqual(weightTablePadding,[['8px','8px','10px','border-box'],['8px','8px','10px','border-box']],`Shared compact table padding ${width}`);
    assert.equal(await page.locator('[data-detail-body] .registry-ui-modal-summary').count(),0,'No count above history table');
    assert.equal(await page.locator('[data-detail-dialog]').evaluate(node=>node.classList.contains('registry-ui-modal--weight-single')),true,'Single detail uses shared compact width');
    assert.equal(await page.locator('[data-detail-body] .registry-ui-compact-table tbody tr').count(),2,'Single detail uses two-row matrix');
    assert.equal(await page.locator('[data-detail-body] .registry-ui-compact-table-scroll').evaluate(node=>node.scrollWidth>node.clientWidth),false,'Single detail has no inner X-scroll');
    if (process.env.PLANEX_WEIGHT_SCREENSHOT_DIR) await page.screenshot({path:path.join(process.env.PLANEX_WEIGHT_SCREENSHOT_DIR,`weight-detail-${width}.png`)});
    await page.locator('[data-detail-revert]').click();
    assert.equal(await page.locator('[data-confirm-revert]').isVisible(),true,'Separate final confirmation');
    assert.equal(await page.locator('[data-confirm-revert]').innerText(),'Выполнить возврат');
    assert.equal(await page.locator('[data-detail-dialog]').evaluate(node=>node.classList.contains('registry-ui-modal--confirmation')),true,'Shared confirmation width');
    const confirmationWidth = await page.locator('[data-detail-dialog]').evaluate(node=>Math.round(node.getBoundingClientRect().width));
    assert.ok(confirmationWidth <= 640 && confirmationWidth < width,'Confirmation uses compact shared width');
    await page.locator('[data-detail-dialog] [data-close-dialog]').first().click();
    await page.locator('[data-detail="7"]').click();
    await page.locator('[data-detail-body] .registry-ui-compact-table').waitFor();
    assert.equal(await page.locator('[data-detail-revert]').isVisible(),true,'Primary action restored after cancel');
    await page.locator('[data-detail-dialog] [data-close-dialog]').first().click();
    await page.locator('[data-detail="7"]').evaluate(node=>node.dataset.detail='6');
    await page.locator('[data-detail="6"]').click();
    await page.locator('[data-detail-body] .registry-ui-compact-table').waitFor();
    assert.equal(await page.locator('[data-detail-dialog]').evaluate(node=>node.classList.contains('registry-ui-modal--reading')),true,'Historical multi uses reading width');
    assert.equal(await page.locator('[data-detail-body] .registry-ui-compact-table tbody tr').count(),2,'Historical multi keeps both rows');
    assert.equal(await page.locator('[data-detail-body] .registry-ui-compact-table tbody tr').nth(1).locator('td').nth(1).innerText(),'—');
    assert.equal(await page.locator('[data-detail-body] .registry-ui-compact-table tbody tr').nth(1).locator('td').nth(2).innerText(),'0 кг');
    await page.locator('[data-detail-revert]').click();
    await page.locator('[data-confirm-revert]:not([hidden])').waitFor();
    assert.match(await page.locator('[data-detail-body]').innerText(),/вернутся заявки: 100001, 100002/);
    await page.locator('[data-confirm-revert]').click();
    await page.waitForFunction(() => document.querySelector('[data-revert-error]')?.textContent.includes('изменён'));
    assert.match(await page.locator('[data-revert-error]').innerText(),/изменён/);
    await page.locator('[data-detail-dialog] [data-close-dialog]').first().click();
    await page.locator('[data-detail="6"]').evaluate(node=>node.dataset.detail='9');
    await page.locator('[data-detail="9"]').click();
    await page.locator('[data-delete-returned]:not([hidden])').waitFor();
    if (process.env.PLANEX_WEIGHT_SCREENSHOT_DIR) await page.screenshot({path:path.join(process.env.PLANEX_WEIGHT_SCREENSHOT_DIR,`weight-returned-${width}.png`)});
    assert.equal(await page.locator('[data-detail-revert]').isVisible(),false,'Returned block cannot be reverted again');
    const footerOrder=await page.locator('[data-delete-returned]').evaluate(node=>node.getBoundingClientRect().left < node.closest('.modal-foot').querySelector('[data-close-dialog]').getBoundingClientRect().left);
    assert.equal(footerOrder,true,'Approved red delete action precedes Cancel');
    await page.locator('[data-delete-returned]').click();
    assert.equal(await page.locator('[data-confirm-delete-returned]').isVisible(),true,'Returned block has separate delete confirmation');
    assert.match(await page.locator('[data-detail-body]').innerText(),/Вес заявок уже возвращён; текущий вес не изменится/);
    assert.equal(await page.locator('[data-detail-dialog]').evaluate(node=>node.classList.contains('registry-ui-modal--confirmation')),true,'Delete uses shared compact confirmation');
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth),false,`Delete confirmation has no overflow at ${width}px`);
    if (process.env.PLANEX_WEIGHT_SCREENSHOT_DIR) await page.screenshot({path:path.join(process.env.PLANEX_WEIGHT_SCREENSHOT_DIR,`weight-delete-confirm-${width}.png`)});
    await page.locator('[data-confirm-delete-returned]').click();
    await page.waitForLoadState('load');
    assert.match(deleteSubmitted||'',/\/9\/delete$/,'Returned archive is sent only after confirmation');
  }
  assert.deepEqual(errors,[]);
  console.log('FEO_WEIGHTS_BROWSER_PASS');
} finally { await browser.close(); await new Promise(resolve => server.close(resolve)); }
