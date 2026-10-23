import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {readFileSync,mkdirSync,writeFileSync} from 'node:fs';
import http from 'node:http';
import path from 'node:path';
import {createRequire} from 'node:module';
import {fileURLToPath} from 'node:url';
import {assertRegistryVisualContract,assertRegistryDataContract} from './helpers/registry_visual_contract.mjs';

const require=createRequire(import.meta.url),{chromium}=require(process.env.PLANEX_PLAYWRIGHT_MODULE||'playwright');
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const html=execFileSync(process.env.PLANEX_PHP||'php',[path.join(root,'tests/fixtures/company_registry_canon_render.php'),'contractors','populated','company_owner'],{encoding:'utf8'});
const server=http.createServer((req,res)=>{
  if(req.url.startsWith('/assets/')){
    const file=path.join(root,'public',new URL(req.url,'http://localhost').pathname);
    res.setHeader('Content-Type',file.endsWith('.css')?'text/css':file.endsWith('.woff2')?'font/woff2':'text/javascript');
    res.end(readFileSync(file));return;
  }
  res.setHeader('Content-Type','text/html; charset=utf-8');res.end(html);
});
await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
const browser=await chromium.launch({headless:true});
const output=process.env.PLANEX_VISUAL_EVIDENCE_DIR;
const measurements=[];
const snapshot=page=>page.evaluate(()=>{
  const rect=selector=>{
    const box=document.querySelector(selector).getBoundingClientRect();
    return {x:box.x,y:box.y,width:box.width,height:box.height,right:box.right,bottom:box.bottom};
  };
  return {toolbar:rect('.registry-ui-toolbar'),right:rect('.registry-ui-toolbar > .toolbar-right'),reset:rect('.registry-ui-reset'),sort:rect('.toolbar-sort'),search:rect('.registry-ui-search'),pageOverflow:document.documentElement.scrollWidth-innerWidth};
});
const close=(a,b,label)=>assert.ok(Math.abs(a-b)<1,`${label}: ${a} versus ${b}`);
try{
  for(const width of [390,700,701,900,1099,1100,1280,1920,3840]){
    const page=await browser.newPage({viewport:{width,height:900}});
    const errors=[];page.on('pageerror',error=>errors.push(error.message));
    await page.goto(`http://127.0.0.1:${server.address().port}/company/contractors`);
    await page.waitForSelector('[data-registry-ready="true"]');
    await assertRegistryVisualContract(page,`contractors toolbar ${width}`);
    await assertRegistryDataContract(page,'contractors');
    assert.deepEqual(await page.locator('.registry-ui-toolbar > .toolbar-right').locator(':scope > *').evaluateAll(nodes=>nodes.map(node=>node.className)),['registry-ui-reset','toolbar-sort','registry-ui-search-group']);
    assert.equal(await page.locator('.registry-ui-reset').textContent(),'Сбросить фильтр');
    assert.equal(await page.locator('tbody tr[data-contractor-id]').count(),35);
    const before=await snapshot(page);
    assert.equal(await page.locator('.registry-ui-reset').isVisible(),false,'default reset hidden');
    assert.ok(before.pageOverflow<=1,`no page X scroll at ${width}`);
    if(output){mkdirSync(output,{recursive:true});await page.screenshot({path:path.join(output,`contractors-${width}-default.png`)});}
    await page.locator('.registry-ui-search').fill('Восточный');
    assert.equal(await page.locator('.found-label b').textContent(),'12','search retains contractor filtering');
    const filtered=await snapshot(page);
    assert.equal(await page.locator('.registry-ui-reset').isVisible(),true,'reset visible after search');
    for(const name of ['sort','search']){close(before[name].x,filtered[name].x,`${name} x at ${width}`);close(before[name].y,filtered[name].y,`${name} y at ${width}`);}
    close(before.toolbar.height,filtered.toolbar.height,`toolbar height at ${width}`);
    assert.ok(filtered.reset.x>=filtered.right.x-1&&filtered.reset.right<=filtered.right.right+1,`reset inside right group at ${width}: ${JSON.stringify(filtered)}`);
    assert.ok(filtered.pageOverflow<=1,`no page X scroll when reset visible at ${width}`);
    if(output)await page.screenshot({path:path.join(output,`contractors-${width}-filtered.png`)});
    await page.locator('.registry-ui-reset').focus();
    assert.equal(await page.locator('.registry-ui-reset').evaluate(el=>getComputedStyle(el).outlineWidth),'2px','keyboard focus visible');
    await page.locator('.registry-ui-reset').press('Enter');
    assert.equal(await page.locator('.registry-ui-search').inputValue(),'');
    assert.equal(await page.locator('.found-label b').textContent(),'35');
    const reset=await snapshot(page);
    for(const name of ['sort','search']){close(before[name].x,reset[name].x,`${name} reset x at ${width}`);close(before[name].y,reset[name].y,`${name} reset y at ${width}`);}
    await page.locator('.lab-reference-trigger').click();await page.getByRole('listbox').press('End');await page.getByRole('listbox').press('Enter');
    assert.equal(await page.locator('select[data-erp-grid-sort]').inputValue(),'alpha');
    await page.locator('.registry-ui-reset').click();
    assert.equal(await page.locator('select[data-erp-grid-sort]').inputValue(),'date');
    assert.deepEqual(errors,[],`no runtime errors at ${width}`);
    measurements.push({width,before,filtered});await page.close();
  }
  if(output)writeFileSync(path.join(output,'contractors-toolbar-measurements.json'),JSON.stringify(measurements,null,2));
  console.log('CONTRACTORS_TOOLBAR_LAYOUT=PASS widths=390,700,701,900,1099,1100,1280,1920,3840');
}finally{await browser.close();await new Promise(resolve=>server.close(resolve));}
