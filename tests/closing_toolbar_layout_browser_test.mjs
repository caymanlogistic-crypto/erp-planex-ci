import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {readFileSync} from 'node:fs';
import http from 'node:http';
import path from 'node:path';
import {createRequire} from 'node:module';
import {fileURLToPath} from 'node:url';

const require=createRequire(import.meta.url);
const {chromium}=require(process.env.PLANEX_PLAYWRIGHT_MODULE || 'playwright');
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const fixture=path.join(root,'tests/fixtures/trip_closing_design_lab_render.php');
const php=process.env.PLANEX_PHP || 'php';
const pages={owner:execFileSync(php,[fixture],{encoding:'utf8'}),manager:execFileSync(php,[fixture,'manager'],{encoding:'utf8'})};
const server=http.createServer((request,response)=>{
  if(request.url.startsWith('/assets/')){
    const file=path.join(root,'public',request.url.split('?')[0]);
    response.setHeader('Content-Type',file.endsWith('.css')?'text/css':'text/javascript');
    response.end(readFileSync(file));
    return;
  }
  response.setHeader('Content-Type','text/html; charset=utf-8');
  response.end(request.url.includes('role=manager')?pages.manager:pages.owner);
});
await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
const browser=await chromium.launch({headless:true});
const metrics=page=>page.evaluate(()=>{
  const box=element=>{const r=element.getBoundingClientRect();return {x:r.x,y:r.y,width:r.width,height:r.height,right:r.right,bottom:r.bottom};};
  const q=selector=>document.querySelector(selector);
  const controls=[...document.querySelectorAll('.closing-toolbar-right :is(.lab-reference-trigger,.registry-ui-search)')];
  return {
    toolbar:box(q('.closing-toolbar')),
    right:box(q('.closing-toolbar-right')),
    card:box(q('.closing-registry-card')),
    main:box(document.querySelector('main')),
    reset:box(q('.registry-ui-reset')),
    controls:controls.map(element=>({name:element.getAttribute('aria-label')||element.getAttribute('placeholder')||element.getAttribute('name'),...box(element)})),
    search:box(q('.registry-ui-search')),
    mainOverflow:document.querySelector('main').scrollWidth-document.querySelector('main').clientWidth,
    mainScrollLeft:document.querySelector('main').scrollLeft,
    documentOverflow:document.documentElement.scrollWidth-document.documentElement.clientWidth,
    resetHidden:q('.registry-ui-reset').hidden,
    resetVisibility:getComputedStyle(q('.registry-ui-reset')).visibility,
  };
});
try{
  for(const role of ['owner','manager'])for(const width of [390,700,701,900,1099,1100,1279,1280,1281,1500,1501,1600,1920,3840]){
    const page=await browser.newPage({viewport:{width,height:900}});
    await page.goto(`http://127.0.0.1:${server.address().port}/company/logistics/closing-documents?role=${role}`);
    const right=page.locator('.closing-toolbar-right');
    const reset=page.locator('.registry-ui-reset');
    assert.equal(await reset.count(),1,`${role}/${width}: exactly one reset`);
    assert.equal(await right.locator(':scope > .registry-ui-reset').count(),1,`${role}/${width}: reset first-level child`);
    assert.equal(await right.locator(':scope > :first-child').getAttribute('class'),'registry-ui-reset',`${role}/${width}: reset first in toolbar`);
    assert.equal(await reset.textContent(),'Сбросить фильтр',`${role}/${width}: approved label`);
    assert.equal(await page.locator('.closing-toolbar input[type=checkbox]').count(),0,`${role}/${width}: no invented checkbox`);
    assert.equal(await page.locator('[data-closing-entity]').count(),role==='owner'?1:0,`${role}/${width}: company role`);
    assert.equal(await page.locator('[data-closing-quarter]').count(),role==='owner'?1:0,`${role}/${width}: quarter role`);
    assert.equal(await page.locator('[data-closing-manager]').count(),role==='owner'?1:0,`${role}/${width}: manager role`);
    assert.equal(await page.getByRole('button',{name:'Сбросить фильтр'}).count(),0,`${role}/${width}: hidden reset absent from accessibility tree`);
    const hidden=await metrics(page);
    await page.locator('.registry-ui-tabs button').last().focus();
    let reachedSearch=false;
    for(let index=0;index<12;index++){
      await page.keyboard.press('Tab');
      const focused=await page.evaluate(()=>({reset:document.activeElement?.classList.contains('registry-ui-reset'),search:document.activeElement?.matches('[data-closing-search]')}));
      assert.equal(focused.reset,false,`${role}/${width}: hidden reset must not be in Tab order`);
      if(focused.search){reachedSearch=true;break;}
    }
    assert.ok(reachedSearch,`${role}/${width}: Tab reaches search from last registry tab`);
    await page.locator('.registry-ui-search').fill('нет такого рейса');
    assert.equal(await page.getByRole('button',{name:'Сбросить фильтр'}).count(),1,`${role}/${width}: visible reset in accessibility tree`);
    const visible=await metrics(page);
    const stable=(a,b)=>Math.abs(a-b)<=1;
    assert.ok(hidden.documentOverflow<=1&&visible.documentOverflow<=1,`${role}/${width}: document overflows: ${JSON.stringify({hidden,visible})}`);
    assert.ok(hidden.mainOverflow<=1&&visible.mainOverflow<=1,`${role}/${width}: main overflows: ${JSON.stringify({hidden,visible})}`);
    assert.ok(stable(hidden.toolbar.height,visible.toolbar.height),`${role}/${width}: toolbar height shifted: ${JSON.stringify({hidden,visible})}`);
    assert.ok(stable(hidden.search.x,visible.search.x)&&stable(hidden.search.y,visible.search.y),`${role}/${width}: search shifted: ${JSON.stringify({hidden,visible})}`);
    for(let index=0;index<hidden.controls.length;index++){
      const a=hidden.controls[index],b=visible.controls[index];
      assert.ok(stable(a.x,b.x)&&stable(a.y,b.y),`${role}/${width}: ${a.name} shifted: ${JSON.stringify({hidden,visible})}`);
    }
    for(const state of [hidden,visible]){
      const items=[{name:'reset',...state.reset},...state.controls];
      for(const item of items)assert.ok(item.x>=state.toolbar.x-1&&item.right<=state.toolbar.right+1,`${role}/${width}: ${item.name} outside toolbar: ${JSON.stringify(state)}`);
      for(let a=0;a<items.length;a++)for(let b=a+1;b<items.length;b++){
        const x=Math.min(items[a].right,items[b].right)-Math.max(items[a].x,items[b].x);
        const y=Math.min(items[a].bottom,items[b].bottom)-Math.max(items[a].y,items[b].y);
        assert.ok(x<=1||y<=1,`${role}/${width}: ${items[a].name} overlaps ${items[b].name}: ${JSON.stringify(state)}`);
      }
    }
    await reset.focus();
    const outline=await reset.evaluate(element=>({style:getComputedStyle(element).outlineStyle,width:getComputedStyle(element).outlineWidth}));
    assert.deepEqual(outline,{style:'solid',width:'2px'},`${role}/${width}: keyboard focus ring`);
    await reset.click();
    assert.equal(await page.locator('.registry-ui-search').inputValue(),'');
    assert.equal(await page.getByRole('button',{name:'Сбросить фильтр'}).count(),0,`${role}/${width}: reset hidden after clearing`);
    await page.close();
  }
  console.log('CLOSING_TOOLBAR_LAYOUT_BROWSER=PASS');
}finally{
  await browser.close();
  await new Promise(resolve=>server.close(resolve));
}
