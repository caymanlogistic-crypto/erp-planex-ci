import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {readFileSync} from 'node:fs';
import http from 'node:http';
import path from 'node:path';
import {createRequire} from 'node:module';
import {fileURLToPath} from 'node:url';

const require=createRequire(import.meta.url);
const {chromium}=require(process.env.PLANEX_PLAYWRIGHT_MODULE||'playwright');
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const html=execFileSync(process.env.PLANEX_PHP||'php',[path.join(root,'tests/fixtures/company_registry_canon_render.php'),'vehicle-sets','populated'],{encoding:'utf8'});
const server=http.createServer((request,response)=>{
  if(request.url.startsWith('/assets/')){
    const file=path.join(root,'public',request.url.split('?')[0]);
    response.setHeader('Content-Type',file.endsWith('.css')?'text/css':file.endsWith('.woff2')?'font/woff2':'text/javascript');
    response.end(readFileSync(file));return;
  }
  response.setHeader('Content-Type','text/html; charset=utf-8');response.end(html);
});
await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
const browser=await chromium.launch({headless:true});
const widths=[390,700,701,900,1099,1100,1280,1920,3840];
try{
  for(const width of widths){
    const page=await browser.newPage({viewport:{width,height:900}});
    const errors=[];page.on('pageerror',error=>errors.push(error.message));
    await page.goto(`http://127.0.0.1:${server.address().port}/company/vehicle-sets`);
    await page.waitForSelector('[data-registry-ready="true"]');
    await page.evaluate(async()=>{await document.fonts.ready;await new Promise(requestAnimationFrame)});
    const reset=page.locator('.registry-ui-reset');
    const search=page.getByRole('searchbox',{name:'Поиск транспорта'});
    const geometry=()=>page.evaluate(()=>{
      const pick=selector=>{
        const rect=document.querySelector(selector).getBoundingClientRect();
        return {x:rect.x,y:rect.y,width:rect.width,height:rect.height,right:rect.right,bottom:rect.bottom};
      };
      return {toolbar:pick('.registry-ui-toolbar'),right:pick('.registry-ui-toolbar .toolbar-right'),sort:pick('.registry-ui-toolbar .toolbar-sort'),search:pick('.registry-ui-search'),reset:pick('.registry-ui-reset'),pageOverflow:document.documentElement.scrollWidth-document.documentElement.clientWidth};
    });
    assert.equal(await reset.isVisible(),false,`${width}: reset starts hidden`);
    assert.equal(await reset.count(),1,`${width}: one shared reset`);
    assert.equal(await page.locator('.registry-ui-toolbar .registry-ui-search-group').count(),1,`${width}: approved shared search group`);
    assert.deepEqual(await page.locator('.registry-ui-toolbar .toolbar-right').locator(':scope > *').evaluateAll(elements=>elements.map(element=>element.classList.contains('registry-ui-reset')?'reset':element.classList.contains('toolbar-sort')?'sort':element.classList.contains('registry-ui-search-group')?'search':'unknown')),['reset','sort','search'],`${width}: canonical order`);
    const initial=await geometry();
    await search.fill('КамАЗ');
    assert.equal(await reset.isVisible(),true,`${width}: reset appears with active search`);
    const filtered=await geometry();
    for(const name of ['sort','search'])for(const axis of ['x','y'])assert.ok(Math.abs(initial[name][axis]-filtered[name][axis])<=1,`${width}: ${name}.${axis} moved ${initial[name][axis]} to ${filtered[name][axis]}`);
    assert.ok(Math.abs(initial.toolbar.height-filtered.toolbar.height)<=1,`${width}: toolbar height stable`);
    assert.equal(filtered.pageOverflow<=1,true,`${width}: no page horizontal overflow`);
    assert.ok(filtered.reset.right<=filtered.sort.x+1||filtered.reset.bottom<=filtered.sort.y+1,`${width}: reset does not overlap sort`);
    assert.ok(filtered.sort.right<=filtered.search.x+1||filtered.sort.bottom<=filtered.search.y+1,`${width}: sort does not overlap search`);
    await reset.focus();assert.equal(await reset.getAttribute('class'),'registry-ui-reset',`${width}: shared reset class only`);
    assert.equal(await reset.evaluate(element=>getComputedStyle(element).fontWeight),'500',`${width}: shared reset typography`);
    await page.keyboard.press('Enter');
    assert.equal(await search.inputValue(),'');
    assert.equal(await reset.isVisible(),false);
    assert.deepEqual(errors,[],`${width}: no runtime errors`);
    await page.close();
  }
  console.log(`VEHICLE_SETS_TOOLBAR_BROWSER=PASS widths=${widths.join(',')}`);
}finally{await browser.close();await new Promise(resolve=>server.close(resolve));}
