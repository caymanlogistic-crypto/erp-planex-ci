// Explicit post-deploy acceptance, never a development fixture or business write.
import assert from 'node:assert/strict';
import {createRequire} from 'node:module';
import {readFileSync,writeFileSync,mkdirSync} from 'node:fs';
import {createHash} from 'node:crypto';
import path from 'node:path';
assert.equal(process.env.PLANEX_PRODUCTION_READONLY,'1','Explicit production read-only mode required');
const out=process.env.PLANEX_PRODUCTION_EVIDENCE;
assert(out,'Owned private evidence directory required');mkdirSync(out,{recursive:true});
const credential=JSON.parse(process.env.PLANEX_PRODUCTION_REVIEW_CREDENTIAL);
const {chromium}=createRequire(import.meta.url)(process.env.PLANEX_PLAYWRIGHT_MODULE||'playwright');
const browser=await chromium.launch({headless:true}),context=await browser.newContext();
const origin='https://plan-ex.ru',base=origin+'/erpv2';
let loginPosts=0;
const result={started_utc:new Date().toISOString(),widths:[],assets:[],errors:[],blocked:[]};
await context.route('**/*',async route=>{
  const request=route.request(),url=new URL(request.url());
  if(url.origin!==origin){result.blocked.push('external');return route.abort();}
  if(['GET','HEAD'].includes(request.method()))return route.continue();
  if(request.method()==='POST'&&url.pathname==='/erpv2/login'&&loginPosts++===0)return route.continue();
  result.blocked.push('mutation:'+request.method());return route.abort();
});
const page=await context.newPage();page.on('pageerror',error=>result.errors.push(error.name));
try{
  await page.goto(base+'/login',{waitUntil:'domcontentloaded'});
  await page.locator('input[name="login"]').fill(credential.username);
  await page.locator('input[name="password"]').fill(credential.password);
  await Promise.all([page.waitForURL(url=>!url.pathname.endsWith('/login')),page.locator('form.login-form button[type="submit"]').click()]);
  for(const file of ['css/registry-components.css','js/feo-map-placement.js']){
    const response=await context.request.get(base+'/assets/'+file,{maxRedirects:0});assert.equal(response.status(),200);
    const sha=bytes=>createHash('sha256').update(bytes).digest('hex');
    const actual=sha(await response.body()),expected=sha(readFileSync(path.resolve('public/assets',file)));
    assert.equal(actual,expected,'Public asset differs from exact worktree');result.assets.push({file,sha256:actual});
  }
  for(const width of [390,1280,1920,3840]){
    await page.setViewportSize({width,height:1000});await page.goto(base+'/company/feo/requests/map-placement',{waitUntil:'networkidle'});
    await page.waitForSelector('[data-registry-ready="true"]');
    const response=page.waitForResponse(r=>r.request().method()==='GET'&&r.url().includes('/map-placement/search-blocks?'));
    await page.locator('[data-placement-block-search]').fill('300307\n308559');
    const payload=await (await response).json();assert.equal(payload.success,true);
    const table=page.locator('.feo-placement-search-table');await table.waitFor();
    const rows=table.locator('tbody tr');
    assert.equal(await rows.count(),payload.results.reduce((count,item)=>count+Math.max(1,item.blocks.length),0));
    for(const item of payload.results)for(const block of item.blocks){
      const row=table.locator(`tr[data-placement-search-block-id="${Number(block.id)}"]`).filter({has:page.locator('td.registry-ui-id',{hasText:String(item.zayavka_id)})});
      assert.equal(await row.count(),1);assert((await row.innerText()).includes(block.name));
    }
    await page.waitForFunction(()=>[...document.querySelectorAll('.feo-placement-search-table tbody td')].every(td=>['rgb(253, 240, 213)','rgb(255, 241, 189)'].includes(getComputedStyle(td).backgroundColor)),null,{timeout:2000});
    const measure=()=>table.evaluate(el=>({headerPosition:getComputedStyle(el.tHead).position,
      headerOffset:el.tHead.getBoundingClientRect().top-el.getBoundingClientRect().top,
      inline:[...el.querySelectorAll('.registry-ui-inline-identity')].every(group=>{const id=group.querySelector('.registry-ui-primary'),name=group.querySelector('.registry-ui-name');return !name||Math.abs(id.getBoundingClientRect().top-name.getBoundingClientRect().top)<1}),
      documentOverflow:document.documentElement.scrollWidth-document.documentElement.clientWidth,
      mainOverflow:document.querySelector('main.content').scrollWidth-document.querySelector('main.content').clientWidth}));
    let measured=await measure();assert.equal(measured.headerPosition,'static');assert(Math.abs(measured.headerOffset)<=1);assert(measured.inline);assert(measured.documentOverflow<=1&&measured.mainOverflow<=1);
    await page.screenshot({path:path.join(out,`search-${width}.png`)});
    await page.locator('main.content').evaluate(el=>el.scrollTop+=120);
    const scrolled=await measure();assert.equal(scrolled.headerPosition,'static');assert(Math.abs(scrolled.headerOffset)<=1);
    await table.locator('tbody tr[data-placement-search-block-id]').first().dblclick();
    await page.locator('#feo-placement-detail-modal.is-open').waitFor();await page.keyboard.press('Escape');
    await page.locator('.registry-ui-reset').click();await table.waitFor({state:'hidden'});
    result.widths.push({width,rows:await page.locator('.feo-placement-table tbody tr:visible').count(),search_rows:payload.results.reduce((count,item)=>count+Math.max(1,item.blocks.length),0),...measured,scroll_header_offset:scrolled.headerOffset,detail_get:true,reset:true});
  }
  assert.equal(result.errors.length,0);assert.equal(result.blocked.length,0);result.pass=true;
}catch(error){result.pass=false;result.failure={name:error.name,message:String(error.message).slice(0,300)};process.exitCode=1;}
finally{result.finished_utc=new Date().toISOString();writeFileSync(path.join(out,'production-result.json'),JSON.stringify(result,null,2));await context.close();await browser.close();}
console.log(result.pass?'FEO_PLACEMENT_PRODUCTION_READONLY_PASS':'FEO_PLACEMENT_PRODUCTION_READONLY_FAIL');
