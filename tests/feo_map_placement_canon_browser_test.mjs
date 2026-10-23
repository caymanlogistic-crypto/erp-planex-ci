import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {readFileSync,mkdirSync,writeFileSync} from 'node:fs';
import http from 'node:http';
import path from 'node:path';
import {createRequire} from 'node:module';
import {fileURLToPath} from 'node:url';
import {assertRegistryVisualContract,assertRegistryDataContract,assertRegistryTableState} from './helpers/registry_visual_contract.mjs';
import {assertRegistryRows,assertRegistrySearch,assertRegistryScroll} from './helpers/registry_behavior_contract.mjs';
import {assertPlacementSearchPresentation} from './helpers/feo_placement_search_presentation.mjs';

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
    res.end(JSON.stringify({success:true,results:ids.map(id=>({zayavka_id:id,exists_in_feo:true,blocks:id==='302545'?[{id:74,name:'Площадка Сосновый Бор',block_type:'addition',created_at:'2026-10-17 10:45:00'}]:id==='285664'?[{id:75,name:'Корректировка по улице Заводской',block_type:'exclusion',created_at:'2026-10-18 09:20:00'}]:[]})),duplicates:[]}));return;
  }
  res.setHeader('Content-Type','text/html; charset=utf-8');res.end(pages[url.searchParams.get('state')||'populated']);
});
await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
const browser=await chromium.launch({headless:true});
const evidence=process.env.PLANEX_VISUAL_EVIDENCE_DIR;
const measurements=[];
const toolbarGeometry=page=>page.evaluate(()=>{
  const rect=selector=>{
    const {x,y,width,height}=document.querySelector(selector).getBoundingClientRect();
    return {x,y,width,height};
  };
  const toolbar=document.querySelector('.registry-ui-toolbar');
  const summary=document.querySelector('.registry-ui-toolbar-summary');
  const sep=document.querySelector('.registry-ui-toolbar .filter-sep');
  return {
    toolbar:rect('.registry-ui-toolbar'),summary:rect('.registry-ui-toolbar-summary'),
    sep:rect('.registry-ui-toolbar .filter-sep'),checkbox:rect('.registry-ui-toolbar .registry-ui-checkbox'),
    action:rect('[data-placement-open-current]'),search:rect('[data-placement-block-search]'),
    sepColor:getComputedStyle(sep).backgroundColor,summaryOverflow:summary.scrollWidth-summary.clientWidth,
    pageOverflow:document.documentElement.scrollWidth-document.documentElement.clientWidth,
    toolbarOverflow:toolbar.scrollWidth-toolbar.clientWidth,
  };
});
const assertToolbarLayout=async(page,width)=>{
  const reset=page.getByRole('button',{name:'Сбросить фильтр'});
  assert.equal(await reset.count(),0,`${width}: hidden reset absent from accessibility tree`);
  assert.equal(await page.locator('.toolbar-right > .registry-ui-reset').count(),1,`${width}: reset is first in right group`);
  assert.equal(await page.locator('.toolbar-right').evaluate(el=>el.firstElementChild.classList.contains('registry-ui-reset')),true);
  assert.equal(await page.locator('.registry-ui-checkboxes > .registry-ui-checkboxes .filter-sep + .registry-ui-checkbox').count(),1,`${width}: divider and checkbox stay paired`);
  assert.equal(await page.locator('[data-placement-hide-disabled]').isChecked(),true,`${width}: existing checkbox default`);
  await page.locator('[data-placement-hide-disabled]').focus();
  await page.keyboard.press('Tab');
  assert.equal(await page.locator('[data-placement-open-current]').evaluate(el=>el===document.activeElement),true,`${width}: hidden reset omitted from Tab order`);
  const before=await toolbarGeometry(page);
  assert.equal(before.sep.width,1,`${width}: canonical divider width`);
  assert.equal(before.sep.height,18,`${width}: canonical divider height`);
  assert.equal(before.sepColor,'rgb(222, 218, 208)',`${width}: canonical divider tone`);
  assert.ok(Math.abs(before.sep.y+before.sep.height/2-before.checkbox.y-before.checkbox.height/2)<=1,`${width}: divider remains with checkbox`);
  assert.ok(Math.abs(before.checkbox.x-before.sep.x-before.sep.width-20)<=1,`${width}: canonical divider gap`);
  assert.ok(before.summaryOverflow<=1&&before.pageOverflow<=1&&before.toolbarOverflow<=1,`${width}: summary and toolbar do not clip or overflow`);
  await page.locator('[data-placement-hide-disabled]').uncheck();
  assert.equal(await reset.count(),1,`${width}: changed checkbox reveals reset`);
  const after=await toolbarGeometry(page);
  for(const key of ['action','search'])for(const axis of ['x','y'])
    assert.ok(Math.abs(after[key][axis]-before[key][axis])<=1,`${width}: ${key} ${axis} stable when reset appears (${before[key][axis]} → ${after[key][axis]})`);
  assert.ok(Math.abs(after.toolbar.height-before.toolbar.height)<=1,`${width}: toolbar height stable when reset appears`);
  await page.locator('[data-placement-hide-disabled]').focus();
  await page.keyboard.press('Tab');
  assert.equal(await reset.evaluate(el=>el===document.activeElement),true,`${width}: visible reset follows checkbox in Tab order`);
  await reset.click();
  assert.equal(await page.locator('[data-placement-hide-disabled]').isChecked(),true,`${width}: reset restores existing checkbox default`);
  assert.equal(await reset.count(),0,`${width}: reset hidden again after restore`);
  if(width===900){
    await page.locator('[data-placement-hide-disabled]').uncheck();
    await page.reload();
    await page.waitForSelector('[data-registry-ready="true"]');
    assert.equal(await page.locator('[data-placement-hide-disabled]').isChecked(),false,'changed checkbox persists after reload');
    await page.getByRole('button',{name:'Сбросить фильтр'}).click();
    assert.equal(await page.locator('[data-placement-hide-disabled]').isChecked(),true,'reset clears persisted checkbox change');
  }
};
try{
  for(const width of [390,1280,1920,3840]){
    const page=await browser.newPage({viewport:{width,height:900}}),errors=[];
    page.on('pageerror',error=>errors.push(error.message));
    await page.goto(`http://127.0.0.1:${server.address().port}/erpv2/company/feo/requests/map-placement`);
    await page.waitForSelector('[data-registry-ready="true"]');
    await assertRegistryVisualContract(page,`feo-map-placement ${width}`);
    await assertRegistryDataContract(page,'feo-map-placement');
    const accepted=await page.locator('.feo-placement-table').evaluate(table=>{
      const name=table.querySelector('[data-placement-block-id="77"] .registry-ui-name');
      const source=table.querySelector('[data-placement-block-id="77"] .feo-placement-source-cell');
      const file=table.querySelector('[data-placement-block-id="78"] .feo-placement-source-cell');
      const ids=[...source.querySelectorAll('.registry-ui-source-id')];
      const operation=table.querySelector('[data-placement-block-id="75"] .registry-ui-operation--exclusion');
      const style=getComputedStyle(operation);
      return {headings:[...table.tHead.rows[0].cells].map(cell=>cell.textContent.trim()),columns:[...table.querySelectorAll('col')].map(col=>Math.round(col.getBoundingClientRect().width)),name:name.textContent,fullName:name.title,accessible:name.getAttribute('aria-label'),source:source.querySelector('.registry-ui-primary').textContent,tokens:ids.length,idOverflow:source.scrollWidth-source.clientWidth,file:file.textContent.trim(),fileOverflow:file.scrollWidth-file.clientWidth,typeColor:style.color,typeWeight:style.fontWeight};
    });
    assert.deepEqual(accepted.headings,['ID','Название','Тип','Заявок','Масса, кг','Источник','Дата','Ответств.']);
    assert.deepEqual(accepted.columns.slice(0,5),[64,400,120,84,110]);assert.deepEqual(accepted.columns.slice(6),[150,130]);assert(accepted.columns[5]>=492);
    assert(accepted.name.length<=50&&accepted.name.endsWith('…')&&accepted.fullName.length>50&&accepted.accessible.includes(accepted.fullName));
    assert.equal(accepted.tokens,50);assert.equal(accepted.source.split(', ').length,50);assert(accepted.idOverflow<=1);assert(accepted.file.endsWith('.xlsx')&&accepted.file.length>90&&accepted.fileOverflow<=1);
    assert.equal(accepted.typeColor,'rgb(135, 40, 34)');assert.equal(accepted.typeWeight,'700');
    if(width===390){
      await page.locator('main.content').evaluate(element=>element.style.minWidth='900px');
      await assert.rejects(assertRegistryTableState(page,'feo-map-placement','initial'),/outer horizontal overflow/,'mobile outer overflow must be RED');
      await page.locator('main.content').evaluate(element=>element.style.removeProperty('min-width'));
      await assertRegistryTableState(page,'feo-map-placement','initial');
      const wrap=page.locator('.registry-ui-card > .table-scroll');
      await wrap.evaluate(element=>element.style.setProperty('overflow-x','hidden','important'));
      await assert.rejects(assertRegistryTableState(page,'feo-map-placement','initial'),/must actually scroll inside/,'clipped wide table must be RED');
      await wrap.evaluate(element=>element.style.removeProperty('overflow-x'));
      await assertRegistryTableState(page,'feo-map-placement','initial');
      console.log('REGISTRY_SURFACE_NEGATIVE=feo-map-placement:mobile-outer-overflow,missing-inner-scroll');
    }

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
    await assertToolbarLayout(page,width);
    const rows=page.locator('.feo-placement-table tbody tr[data-placement-block-id]');
    assert.equal(await rows.count(),70,'all blocks preserved');
    assert.equal(await page.locator('.feo-placement-table tbody tr[data-placement-block-id]:visible').count(),59,'disabled blocks initially hidden');
    await page.locator('[data-placement-hide-disabled]').uncheck();
    assert.equal(await page.locator('.feo-placement-table tbody tr[data-placement-block-id]:visible').count(),70,'checkbox exposes disabled blocks');
    assert.equal(await page.locator('.feo-placement-table tbody tr[data-placement-block-id="76"] td').first().evaluate(el=>getComputedStyle(el).backgroundColor),'rgb(240, 237, 231)','disabled row uses accepted neutral tone');
    await page.locator('.registry-ui-reset').click();
    assert.equal(await page.locator('[data-placement-hide-disabled]').isChecked(),true,'reset restores checkbox default');
    await page.getByRole('button',{name:'Создать блок'}).click();
    assert.ok(await page.locator('#feo-placement-create-modal').isVisible(),'create form action preserved');
    await page.locator('#feo-placement-create-modal input[value="file"]').check();
    assert.ok(await page.locator('[data-placement-file]').isVisible(),'file source remains available');
    await page.locator('#feo-placement-create-modal [data-close-modal]').first().click();
    assert.ok(!(await page.locator('#feo-placement-create-modal').getAttribute('class')).includes('is-open'),'existing close action dismisses create form');
    const search=page.locator('[data-placement-block-search]');
    await search.fill('302545\n285664\n281861');
    await page.getByText('Найдено по 3 заявкам.',{exact:false}).waitFor();
    const summary=await page.locator('.feo-placement-search-summary').evaluate(el=>{const c=getComputedStyle(el);return [c.fontFamily,c.fontSize,c.fontWeight,c.lineHeight,c.color]});
    assert.deepEqual(summary,['"IBM Plex Sans", "Segoe UI", Arial, sans-serif','12px','500','16px','rgb(76, 72, 64)']);
    await assertRegistryDataContract(page,'feo-map-placement',{state:'search-results'});
    const searchTable=page.locator('[data-placement-block-search-results] .feo-placement-search-table');
    assert.deepEqual(await searchTable.locator('thead th').allTextContents(),['ID','ТИП','ДАТА ОПЕРАЦИИ','БЛОК']);
    await assertPlacementSearchPresentation(page);
    if(width===1280){
      const head=searchTable.locator('thead');
      await head.evaluate(el=>el.style.position='sticky');
      await assert.rejects(assertRegistryTableState(page,'feo-map-placement','search-results'),/must not inherit main sticky offset/,'sticky secondary header is RED');
      await head.evaluate(el=>el.style.removeProperty('position'));
      const identity=searchTable.locator('.registry-ui-inline-identity').first();
      await identity.evaluate(el=>el.style.display='block');
      await assert.rejects(assertPlacementSearchPresentation(page),/one line/,'stacked block ID and name is RED');
      await identity.evaluate(el=>el.style.removeProperty('display'));
      const warning=searchTable.locator('tbody tr').first();
      await warning.evaluate(el=>el.classList.remove('registry-ui-warning'));
      await assert.rejects(assertPlacementSearchPresentation(page),/yellow attention role/,'missing yellow row is RED');
      await warning.evaluate(el=>el.classList.add('registry-ui-warning'));
      await assertPlacementSearchPresentation(page);
      console.log('FEO_SEARCH_PRESENTATION_CANARIES=PASS sticky-header,stacked-identity,missing-yellow');
    }
    const oldScroll=await page.locator('main.content').evaluate(el=>{const old=el.scrollTop;el.scrollTop+=120;return old;});
    await assertRegistryTableState(page,'feo-map-placement','search-results');
    await assertPlacementSearchPresentation(page);
    await page.locator('main.content').evaluate((el,value)=>el.scrollTop=value,oldScroll);
    if(width===390){
      const body=searchTable.locator('tbody'),saved=await body.innerHTML();
      await body.evaluate(element=>element.replaceChildren());
      await assert.rejects(assertRegistryTableState(page,'feo-map-placement','search-results'),/block-search-results: expected populated business rows/,'main rows cannot cover an empty secondary result fixture');
      await body.evaluate((element,html)=>element.innerHTML=html,saved);
      await assertRegistryTableState(page,'feo-map-placement','search-results');
      console.log('REGISTRY_SURFACE_NEGATIVE=feo-map-placement:missing-secondary-data');
    }

    assert.ok(await searchTable.isVisible(),'second business table is visible after search');
    const searchGeometry=await searchTable.evaluate(table=>({idWidth:table.tBodies[0].rows[0].cells[0].getBoundingClientRect().width,
      idRole:table.tBodies[0].rows[0].cells[0].classList.contains('registry-ui-id'),
      nameRole:!!table.tBodies[0].rows[0].querySelector('.registry-ui-name'),
      primaryRole:!!table.tBodies[0].rows[0].querySelector('.registry-ui-primary')}));
    assert.ok(Math.abs(searchGeometry.idWidth-64)<0.25,`search table ID column follows 64px canon: ${searchGeometry.idWidth}`);
    assert.ok(searchGeometry.idRole&&searchGeometry.nameRole&&searchGeometry.primaryRole,'search result cells carry approved data roles');
    const searchOverflow=await page.evaluate(()=>{
      const main=document.querySelector('main.content'),wrap=document.querySelector('[data-placement-block-search-results] .table-scroll');
      return {page:document.documentElement.scrollWidth-document.documentElement.clientWidth,main:main.scrollWidth-main.clientWidth,internal:wrap.scrollWidth-wrap.clientWidth};
    });
    assert.ok(searchOverflow.page<=1&&searchOverflow.main<=1,`${width}: search table cannot create outer horizontal scroll ${JSON.stringify(searchOverflow)}`);
    if(width===390)assert.ok(searchOverflow.internal>0,'mobile search table keeps its own horizontal scroll');
    if(evidence)await page.screenshot({path:path.join(evidence,`placement-${width}-search-results.png`)});
    if(width===1280){
      const secondaryHtml=await searchTable.evaluate(table=>{const html=table.outerHTML;table.remove();return html;});
      await assert.rejects(assertRegistryTableState(page,'feo-map-placement','search-results'),/declared business-table surfaces/,'missing secondary surface must be RED');
      await page.locator('[data-placement-block-search-results] .table-scroll').evaluate((wrap,html)=>wrap.insertAdjacentHTML('beforeend',html),secondaryHtml);
      await assert.rejects(assertRegistryTableState(page,'feo-map-placement','search-error'),/declared business-table surfaces/,'wrong state cannot certify a visible secondary table');
      await page.locator('[data-placement-block-search-results]').evaluate(element=>element.insertAdjacentHTML('beforeend','<table data-unmapped-probe><thead><tr><th>ЗАЯВКА</th></tr></thead><tbody><tr><td>309552</td></tr></tbody></table>'));
      await assert.rejects(assertRegistryTableState(page,'feo-map-placement','search-results'),/missing or ambiguously mapped/,'unregistered JS-created table is discovered');
      await page.locator('[data-unmapped-probe]').evaluate(element=>element.remove());
      await searchTable.evaluate(table=>table.classList.remove('registry-ui-data'));
      await assert.rejects(assertRegistryTableState(page,'feo-map-placement','search-results'),/lacks data role/,'registered table cannot lose approved role');
      await searchTable.evaluate(table=>table.classList.add('registry-ui-data'));
      await assertRegistryTableState(page,'feo-map-placement','search-results');
      console.log('REGISTRY_SURFACE_NEGATIVE=feo-map-placement:unmapped,missing-role,wrong-state');
    }
    assert.equal(await search.inputValue(),'302545, 285664, 281861','multiline IDs normalize without loss');
    assert.equal(await page.locator('[data-placement-search-block-id="74"]').count(),1,'block search links to original block');
    if(width===1280){
      await assertRegistryRows(page,{rowSelector:'.feo-placement-table tbody tr[data-placement-block-id="74"],.feo-placement-table tbody tr[data-placement-block-id="75"],.feo-placement-table tbody tr[data-placement-block-id="76"]',rows:[
        {id:74,cells:[['#74'],['Площадка Сосновый Бор — южный сектор'],['Добавление'],['2'],['1 230,500'],['302545, 285664'],['17.10.2026 10:45'],['Анна П.']]},
        {id:75,cells:[['#75'],['Корректировка по улице Заводской'],['Исключение'],['1'],['380'],['исключение-заводская.xlsx'],['18.10.2026 09:20'],['Анна П.']]},
        {id:76,cells:[['#76'],['Резервный перечень'],['Добавление'],['1'],['620'],['281861'],['18.10.2026 13:05'],['Иван К.']]},
      ]},'placement independent domain fixture');
    }
    await page.locator('.registry-ui-reset').click();
    assert.equal(await search.inputValue(),'','reset clears search');
    await page.locator('[data-placement-block-search-results]').waitFor({state:'hidden'});
    await assertRegistryTableState(page,'feo-map-placement','reset');
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
  for(const width of [700,701,900,1099,1100]){
    const page=await browser.newPage({viewport:{width,height:900}});
    await page.goto(`http://127.0.0.1:${server.address().port}/erpv2/company/feo/requests/map-placement`);
    await page.waitForSelector('[data-registry-ready="true"]');
    await assertToolbarLayout(page,width);
    await page.close();
  }
  for(const state of ['empty','error'])for(const width of [390,1280,1920,3840]){
    const page=await browser.newPage({viewport:{width,height:900}});await page.goto(`http://127.0.0.1:${server.address().port}/erpv2/company/feo/requests/map-placement?state=${state}`);
    assert.equal(await page.locator('.registry-ui-head').count(),1,`${state}: canonical header`);
    assert.equal(await page.locator('.feo-placement-table tbody tr[data-placement-block-id]').count(),0,`${state}: no phantom data`);
    assert.equal(await page.locator('.registry-ui-card').count(),state==='empty'?1:0,`${state}: correct card state`);
    await assertRegistryTableState(page,'feo-map-placement',state==='error'?'page-error':'initial',state==='empty'?{fixture:'empty'}:{});
    await page.close();
  }
  if(evidence)writeFileSync(path.join(evidence,'measurements.json'),JSON.stringify(measurements,null,2));
  console.log('FEO_MAP_PLACEMENT_CANON_BROWSER=PASS widths=390,700,701,900,1099,1100,1280,1920,3840 states=normal,hover,focus,empty,error');
}finally{await browser.close();await new Promise(resolve=>server.close(resolve));}
