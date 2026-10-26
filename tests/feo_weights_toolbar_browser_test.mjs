import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {readFileSync} from 'node:fs';
import http from 'node:http';
import path from 'node:path';
import {createRequire} from 'node:module';
import {fileURLToPath} from 'node:url';

const require = createRequire(import.meta.url);
const {chromium} = require(process.env.PLANEX_PLAYWRIGHT_MODULE || 'playwright');
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const html = execFileSync(process.env.PLANEX_PHP || 'php', [path.join(root, 'tests/fixtures/feo_history_canon_render.php'), 'weights', 'populated'], {encoding:'utf8', maxBuffer:8*1024*1024});
const server = http.createServer((req,res) => {
  const url = new URL(req.url,'http://localhost');
  if (url.pathname.startsWith('/assets/')) {
    const file = path.resolve(root,'public','.'+url.pathname);
    if (!file.startsWith(path.join(root,'public','assets')+path.sep)) { res.statusCode=403; return res.end(); }
    res.setHeader('Content-Type',file.endsWith('.css')?'text/css':file.endsWith('.woff2')?'font/woff2':'text/javascript');
    return res.end(readFileSync(file));
  }
  res.setHeader('Content-Type','text/html; charset=utf-8'); res.end(html);
});
await new Promise(resolve => server.listen(0,'127.0.0.1',resolve));
const browser = await chromium.launch({headless:true});
try {
  const base = `http://127.0.0.1:${server.address().port}/company/feo/requests/weights`;
  for (const width of [390,700,701,900,1099,1100,1280,1920,3840]) {
    const page = await browser.newPage({viewport:{width,height:900}}), errors=[];
    page.on('pageerror',error => errors.push(error.message));
    await page.goto(base); await page.waitForSelector('[data-registry-ready="true"]');
    const before = await page.evaluate(() => {
      const toolbar=document.querySelector('.registry-ui-toolbar'), search=document.querySelector('[data-history-search]');
      const t=toolbar.getBoundingClientRect(), s=search.getBoundingClientRect();
      return {toolbar:{x:t.x,y:t.y,width:t.width,height:t.height},search:{x:s.x,y:s.y,width:s.width,height:s.height},viewportOverflow:document.documentElement.scrollWidth>innerWidth,mainOverflow:document.querySelector('main').scrollWidth>document.querySelector('main').clientWidth};
    });
    assert.equal(before.viewportOverflow,false,`${width}: viewport overflow before search`);
    assert.equal(before.mainOverflow,false,`${width}: main overflow before search`);
    assert.equal(await page.locator('.registry-ui-reset').isVisible(),false,`${width}: reset initially hidden`);
    assert.equal(await page.locator('[data-history-count]').innerText(),'62',`${width}: active blocks by default`);
    await page.locator('[data-history-search]').fill('#18');
    assert.equal(await page.locator('[data-batch-row]:visible').count(),1,`${width}: filtered rows`);
    assert.equal(await page.locator('[data-history-count]').innerText(),'1',`${width}: filtered count`);
    assert.equal(await page.locator('.registry-ui-reset').innerText(),'Сбросить фильтр');
    const after = await page.evaluate(() => {
      const toolbar=document.querySelector('.registry-ui-toolbar'), search=document.querySelector('[data-history-search]'),reset=document.querySelector('.registry-ui-reset');
      const t=toolbar.getBoundingClientRect(), s=search.getBoundingClientRect(),r=reset.getBoundingClientRect();
      return {toolbar:{x:t.x,y:t.y,width:t.width,height:t.height},search:{x:s.x,y:s.y,width:s.width,height:s.height},reset:{x:r.x,y:r.y,width:r.width,height:r.height},viewportOverflow:document.documentElement.scrollWidth>innerWidth,mainOverflow:document.querySelector('main').scrollWidth>document.querySelector('main').clientWidth};
    });
    assert.equal(after.viewportOverflow,false,`${width}: viewport overflow with reset`);
    assert.equal(after.mainOverflow,false,`${width}: main overflow with reset`);
    assert.ok(after.reset.y+after.reset.height<=after.search.y+1 || (Math.abs(after.reset.y-after.search.y)<2 && after.reset.x+after.reset.width<=after.search.x+1),`${width}: reset precedes search ${JSON.stringify({before,after})}`);
    assert.ok(Math.abs(after.search.x-before.search.x)<2,`${width}: search X moves ${after.search.x-before.search.x} ${JSON.stringify({before,after})}`);
    assert.ok(Math.abs(after.search.y-before.search.y)<2,`${width}: search Y moves ${after.search.y-before.search.y} ${JSON.stringify({before,after})}`);
    assert.ok(Math.abs(after.toolbar.height-before.toolbar.height)<2,`${width}: toolbar height moves ${after.toolbar.height-before.toolbar.height} ${JSON.stringify({before,after})}`);
    await page.reload(); await page.waitForSelector('[data-registry-ready="true"]');
    assert.equal(await page.locator('[data-history-search]').inputValue(),'#18',`${width}: stored query restored`);
    await page.locator('.registry-ui-reset').focus();
    assert.equal(await page.locator('.registry-ui-reset').evaluate(node => getComputedStyle(node).outlineStyle),'solid',`${width}: reset focus`);
    await page.keyboard.press('Enter');
    assert.equal(await page.locator('[data-history-search]').inputValue(),'');
    assert.equal(await page.locator('[data-history-count]').innerText(),'62');
    assert.equal(await page.locator('.registry-ui-reset').isVisible(),false);
    assert.equal(await page.locator('[data-batch-row]:visible').count(),62);
    assert.deepEqual(errors,[],`${width}: page errors`);
    await page.close();
  }
  console.log('FEO_WEIGHTS_TOOLBAR_BROWSER=PASS');
} finally { await browser.close(); await new Promise(resolve => server.close(resolve)); }
