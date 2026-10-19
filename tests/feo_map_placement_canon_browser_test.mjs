import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {readFileSync,mkdirSync,writeFileSync} from 'node:fs';
import http from 'node:http';
import path from 'node:path';
import {createRequire} from 'node:module';
import {fileURLToPath} from 'node:url';
import {assertRegistryVisualContract,assertRegistryDataContract} from './helpers/registry_visual_contract.mjs';
import {assertRegistryRows,assertRegistrySearch,assertRegistryScroll} from './helpers/registry_behavior_contract.mjs';

const require=createRequire(import.meta.url),{chromium}=require(process.env.PLANEX_PLAYWRIGHT_MODULE||'playwright');
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const render=state=>execFileSync(process.env.PLANEX_PHP||'php',[path.join(root,'tests/fixtures/feo_map_placement_canon_render.php'),state],{encoding:'utf8'});
const pages=Object.fromEntries(['populated','empty','error'].map(state=>[state,render(state)]));
const server=http.createServer((req,res)=>{
  const url=new URL(req.url,'http://localhost');
  if(url.pathname.startsWith('/assets/')){
    const file=path.resolve(root,'public','.'+url.pathname);
    if(!file.startsWith(path.join(root,'public','assets')+path.sep))throw new Error('Invalid asset');
    res.setHeader('Content-Type',file.endsWith('.css')?'text/css':file.endsWith('.woff2')?'font/woff2':'text/javascript');
    res.end(readFileSync(file));return;
  }
  if(url.pathname.endsWith('/search-blocks')){
    const ids=(url.searchParams.get('request_ids')||'').split(/,\s*/).filter(Boolean);
    res.setHeader('Content-Type','application/json');
    res.end(JSON.stringify({success:true,results:ids.map(id=>({zayavka_id:id,exists_in_feo:true,blocks:id==='302545'?[{id:74,name:'Площадка Сосновый Бор',block_type:'addition',created_at:'2026-10-17 10:45:00'}]:[]})),duplicates:[]}));return;
  }
  res.setHeader('Content-Type','text/html; charset=utf-8');res.end(pages[url.searchParams.get('state')||'populated']);
});
await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
const browser=await chromium.launch({headless:true});
const evidence=process.env.PLANEX_VISUAL_EVIDENCE_DIR;
const measurements=[];
try{
  for(const width of [390,1280,1920,3840]){
    const page=await browser.newPage({viewport:{width,height:900}}),errors=[];
    page.on('pageerror',error=>errors.push(error.message));
    await page.goto(`http://127.0.0.1:${server.address().port}/erpv2/company/feo/requests/map-placement`);
    await page.waitForSelector('[data-registry-ready="true"]');
    await assertRegistryVisualContract(page,`feo-map-placement ${width}`);
    await assertRegistryDataContract(page,'feo-map-placement');
    if(evidence){mkdirSync(evidence,{recursive:true});await page.screenshot({path:path.join(evidence,`placement-${width}-initial.png`)});}
    const geometry=await page.evaluate(()=>{
      const rect=selector=>document.querySelector(selector).getBoundingClientRect().toJSON();
      const wrap=document.querySelector('.registry-ui-card .table-scroll');
      const main=document.querySelector('main.content');
      return {width:innerWidth,head:rect('.registry-ui-head'),title:rect('.registry-ui-head .page-title'),icon:rect('.registry-ui-head svg'),found:rect('.registry-ui-toolbar .found-label'),card:rect('.registry-ui-card'),search:rect('.registry-ui-search'),mainScrollLeft:main.scrollLeft,mainOverflow:main.scrollWidth-main.clientWidth,internalScroll:wrap.scrollWidth-wrap.clientWidth,documentOverflow:document.documentElement.scrollWidth-document.documentElement.clientWidth};
    });
    assert.ok(Math.abs(geometry.head.left-geometry.card.left)<1&&Math.abs(geometry.head.right-geometry.card.right)<1,'header and card share one alignment grid');
    assert.ok(geometry.documentOverflow<=1,'no accidental page horizontal scroll');
    assert.ok(geometry.mainOverflow<=1,'no accidental main horizontal scroll');
    assert.deepEqual(await page.locator('.feo-placement-table thead th').evaluateAll(items=>items.filter(el=>el.scrollWidth>el.clientWidth+1).map(el=>el.textContent.trim())),[],'every column heading remains readable');
    if(width===390)assert.ok(geometry.internalScroll>0,'wide table scrolls within card');
    if(width===3840)assert.ok(geometry.card.width<3000,'workspace remains bounded');
    const rows=page.locator('.feo-placement-table tbody tr[data-placement-block-id]');
    assert.equal(await rows.count(),70,'all blocks preserved');
    assert.equal(await page.locator('.feo-placement-table tbody tr[data-placement-block-id]:visible').count(),59,'disabled blocks initially hidden');
    await page.locator('[data-placement-hide-disabled]').uncheck();
    assert.equal(await page.locator('.feo-placement-table tbody tr[data-placement-block-id]:visible').count(),70,'checkbox exposes disabled blocks');
    await page.locator('.registry-ui-reset').click();
    assert.equal(await page.locator('[data-placement-hide-disabled]').isChecked(),true,'reset restores checkbox default');
    await page.getByRole('button',{name:'Создать блок'}).click();
    assert.ok(await page.locator('#feo-placement-create-modal').isVisible(),'create form action preserved');
    await page.locator('#feo-placement-create-modal input[value="file"]').check();
    assert.ok(await page.locator('[data-placement-file]').isVisible(),'file source remains available');
    await page.locator('#feo-placement-create-modal [data-close-modal]').first().click();
    assert.ok(!(await page.locator('#feo-placement-create-modal').getAttribute('class')).includes('is-open'),'existing close action dismisses create form');
    const search=page.locator('[data-placement-block-search]');
    await search.fill('302545\n285664');
    await page.getByText('Найдено по 2 заявкам.',{exact:false}).waitFor();
    assert.equal(await search.inputValue(),'302545, 285664','multiline IDs normalize without loss');
    assert.equal(await page.locator('[data-placement-search-block-id="74"]').count(),1,'block search links to original block');
    if(width===1280){
      await assertRegistryRows(page,{rowSelector:'.feo-placement-table tbody tr[data-placement-block-id="74"],.feo-placement-table tbody tr[data-placement-block-id="75"],.feo-placement-table tbody tr[data-placement-block-id="76"]',rows:[
        {id:74,cells:[['#74'],['Площадка Сосновый Бор — южный сектор'],['Добавление'],['2'],['1 230,500'],['302545, 285664'],['17.10.2026 10:45'],['Анна П.'],['Применяется']]},
        {id:75,cells:[['#75'],['Корректировка по улице Заводской'],['Исключение'],['1'],['380'],['исключение-заводская.xlsx'],['18.10.2026 09:20'],['Анна П.'],['Применяется']]},
        {id:76,cells:[['#76'],['Резервный перечень'],['Добавление'],['1'],['620'],['281861'],['18.10.2026 13:05'],['Иван К.'],['Отключён']]},
      ]},'placement independent domain fixture');
    }
    await page.locator('.registry-ui-reset').click();
    assert.equal(await search.inputValue(),'','reset clears search');
    await page.locator('[data-placement-block-search-results]').waitFor({state:'hidden'});
    if(width===1280){
      await assertRegistrySearch(page,{rowSelector:'.feo-placement-table tbody tr[data-placement-block-id]',probes:[{query:'302545',ids:[74]}],resolveActual:async target=>{
        await target.getByText('Найдено по 1 заявкам.',{exact:false}).waitFor();
        return target.locator('[data-placement-search-block-id]').evaluateAll(items=>items.map(row=>row.dataset.placementSearchBlockId));
      }},'placement server search');
      await page.locator('[data-placement-block-search-results]').waitFor({state:'hidden'});
      await assertRegistryScroll(page,'placement real scrolling');
    }
    if(evidence){mkdirSync(evidence,{recursive:true});await page.screenshot({path:path.join(evidence,`placement-${width}-normal.png`)});}
    assert.ok(await page.locator('main.content').evaluate(el=>el.scrollLeft===0),'interactions do not shift the page horizontally');
    await page.locator('.feo-placement-table tbody tr[data-placement-block-id="74"] td').first().hover();
    await page.waitForTimeout(150);
    assert.equal(await page.locator('.feo-placement-table tbody tr[data-placement-block-id="74"] td').first().evaluate(el=>getComputedStyle(el).backgroundColor),'rgb(236, 233, 226)','approved row hover');
    if(evidence)await page.screenshot({path:path.join(evidence,`placement-${width}-hover.png`)});
    await search.focus();
    assert.notEqual(await search.evaluate(el=>getComputedStyle(el).boxShadow),'none','approved search focus');
    if(evidence)await page.screenshot({path:path.join(evidence,`placement-${width}-focus.png`)});
    assert.deepEqual(errors,[],`no browser errors at ${width}`);
    measurements.push(geometry);await page.close();
  }
  for(const state of ['empty','error']){
    const page=await browser.newPage();await page.goto(`http://127.0.0.1:${server.address().port}/erpv2/company/feo/requests/map-placement?state=${state}`);
    assert.equal(await page.locator('.registry-ui-head').count(),1,`${state}: canonical header`);
    assert.equal(await page.locator('.feo-placement-table tbody tr[data-placement-block-id]').count(),0,`${state}: no phantom data`);
    assert.equal(await page.locator('.registry-ui-card').count(),state==='empty'?1:0,`${state}: correct card state`);
    await page.close();
  }
  if(evidence)writeFileSync(path.join(evidence,'measurements.json'),JSON.stringify(measurements,null,2));
  console.log('FEO_MAP_PLACEMENT_CANON_BROWSER=PASS widths=390,1280,1920,3840 states=normal,hover,focus,empty,error');
}finally{await browser.close();await new Promise(resolve=>server.close(resolve));}
