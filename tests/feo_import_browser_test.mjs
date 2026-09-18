import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import http from 'node:http';
import { fileURLToPath } from 'node:url';
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
const require = createRequire(import.meta.url);
const { chromium } = require('playwright');
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const html = execFileSync(process.env.PLANEX_PHP || 'php', [path.join(root, 'tests/fixtures/feo_import_render.php')], {encoding:'utf8'});
const server = http.createServer((req,res) => {
  if (req.url === '/') { res.setHeader('Content-Type','text/html; charset=utf-8'); return res.end(html); }
  const file = path.resolve(root,'public','.'+req.url.split('?')[0]);
  if (!file.startsWith(path.join(root,'public')+path.sep) || !fs.existsSync(file)) {res.statusCode=404; return res.end();}
  res.setHeader('Content-Type',file.endsWith('.css')?'text/css':'text/javascript'); res.end(fs.readFileSync(file));
});
await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
const browser = await chromium.launch({headless:true});
try {
  const page = await browser.newPage();
  const errors=[]; page.on('pageerror',e=>errors.push(e.message));
  const calls=[];
  await page.route('**/company/feo/requests/**',async route=>{
    if(route.request().url().endsWith('/analyze')) {
      const body=route.request().postData(); calls.push(body);
      await new Promise(resolve=>setTimeout(resolve,100));
      return route.fulfill({contentType:'application/json',body:JSON.stringify({success:true,token:'preview-'+calls.length,stats:{unique_requests:1,new_count:1}})});
    }
    return route.fulfill({contentType:'application/json',body:JSON.stringify({success:true,rows:[],total:0,total_mass_kg:0,has_more:false})});
  });
  const url='http://127.0.0.1:'+server.address().port;
  for (const width of [1366,390]) {
    await page.setViewportSize({width,height:900}); await page.goto(url);
    await page.locator('[data-open-feo-import]').click();
    assert.equal(await page.locator('[name=coordinate_format][value=new]').isChecked(),true);
    assert.equal(await page.locator('[name=overwrite_existing]').isChecked(),false);
    assert.equal(await page.locator('#feo-commit-form').isVisible(),false);
    await page.locator('[name=feo_xlsx]').setInputFiles({name:'synthetic.xlsx',mimeType:'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',buffer:Buffer.from('synthetic browser multipart fixture')});
    await page.locator('#feo-commit-form').waitFor({state:'visible'});
    assert.match(calls.at(-1),/name="coordinate_format"\r\n\r\nnew/);
    assert.match(calls.at(-1),/filename="synthetic.xlsx"/);
    const token=await page.locator('#feo-preview-token').inputValue();
    await page.locator('[name=coordinate_format][value=old]').check();
    await page.waitForFunction(()=>document.querySelector('#feo-preview-token').value !== '' && !document.querySelector('[name=feo_xlsx]').disabled);
    assert.notEqual(await page.locator('#feo-preview-token').inputValue(),token,'Changing format creates another preview');
    assert.match(calls.at(-1),/name="coordinate_format"\r\n\r\nold/);
    assert.match(calls.at(-1),/filename="synthetic.xlsx"/,'Reanalysis keeps file');
    assert.equal(await page.locator('.feo-import-modal').evaluate(e=>e.scrollWidth>e.clientWidth),false,'Modal overflow '+width);
    if(process.env.PLANEX_BROWSER_EVIDENCE) {
      fs.mkdirSync(process.env.PLANEX_BROWSER_EVIDENCE,{recursive:true});
      await page.screenshot({path:path.join(process.env.PLANEX_BROWSER_EVIDENCE,'feo-import-'+width+'.png'),fullPage:true});
    }
  }
  assert.deepEqual(errors,[]);
  console.log('FEO_IMPORT_BROWSER=PASS default-format multipart reanalysis desktop mobile');
} finally { await browser.close(); await new Promise(resolve=>server.close(resolve)); }
