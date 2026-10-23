import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {createRequire} from 'node:module';
import {fileURLToPath,pathToFileURL} from 'node:url';
import {assertRegistryVisualContract,assertRegistryDataRoles,assertRegistryDataCoverage,assertRegistryColumnGeometry} from './helpers/registry_visual_contract.mjs';
const require=createRequire(import.meta.url),{chromium}=require('playwright');
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const url=pathToFileURL(path.join(root,'docs/ui/approved-components.html')).href;
const browser=await chromium.launch({headless:true});
const evidence=process.env.PLANEX_VISUAL_EVIDENCE_DIR;
try{
 for(const width of [390,900,1280,1920,3840]){
  const page=await browser.newPage({viewport:{width,height:1080},deviceScaleFactor:1});
  const errors=[];page.on('pageerror',error=>errors.push(error.message));
  await page.goto(url);
  const frame=page.frameLocator('iframe[data-preview="registry.composition"]');
  const view=key=>frame.locator(`[data-gallery-view="${key}"]`);
  await view('executors').locator('[data-registry-field=query]').waitFor();
  await page.waitForFunction(()=>document.querySelector('iframe').contentDocument.querySelector('[data-registry-ready="true"]'));
  assert.equal(await page.locator('iframe[data-preview]').count(),1,'one whole composition, no repeated specimens');
  const actual=page.frames().find(item=>item.parentFrame());
  const settle=()=>actual.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(()=>{
    document.getAnimations().filter(animation=>animation.constructor.name==='CSSTransition').forEach(animation=>animation.finish());resolve();
  }))));
  await assertRegistryVisualContract(actual,`gallery ${width}`);
  assert.deepEqual(await actual.evaluate(()=>{
    const css=getComputedStyle(document.documentElement);return ['--danger','--danger-bg','--warning-bg','--warning-text','--success','--success-bg'].map(key=>css.getPropertyValue(key).trim());
  }),['#872822','#ffe5df','#fdf0d5','#131210','#1f6b43','#dff3e5'],'system tokens match the approved palette');
  for(const key of ['executors','finance','trips','documents']){
   await page.locator(`[data-gallery-scene=${key}]`).click();
   const scene=view(key);await scene.waitFor({state:'visible'});
   const measured=await assertRegistryVisualContract(actual,`gallery ${key} ${width}`);
   const visibleWidth=await scene.locator('.registry-ui-card').evaluate(el=>el.getBoundingClientRect().width);
   assert.ok(visibleWidth>0&&Math.abs(measured.card.width-visibleWidth)<1,'contract measures this visible scene, not the hidden first registry');
   if(width===1280){
    const shape=scene.locator('.rp-identity-icon svg path').last(),original=await shape.getAttribute('d');
    try{
     await shape.evaluate(node=>node.setAttribute('d','M1 1L23 23'));
     await assert.rejects(()=>assertRegistryVisualContract(actual,`gallery forged ${key}`),/exact named Tabler outline geometry/);
    }finally{await shape.evaluate((node,d)=>node.setAttribute('d',d),original);}
   }
   await actual.evaluate(()=>document.fonts.ready);
   await assertRegistryDataRoles(actual,[],`all approved data roles ${key} ${width}`);
   await assertRegistryColumnGeometry(actual,`approved column geometry ${key} ${width}`);
   assert.equal(await frame.locator('[data-gallery-view]:visible').count(),1);
   assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),true,`host ${width}`);
   assert.equal(await actual.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),true,`composition ${key} ${width}`);
   const metrics=await scene.locator('.table-scroll').evaluate(element=>({range:element.scrollHeight-element.clientHeight}));
   assert.equal(metrics.range,0,`no inner vertical scroll ${key} ${width}`);
   if(width>=1920&&key!=='executors')assert.equal(await scene.locator('tbody .registry-ui-id').first().evaluate(element=>element.getBoundingClientRect().width),64,'same ID width');
   if(key==='finance'&&width===1280){
    assert.equal(await scene.locator('.registry-ui-compact-head').isVisible(),true,'accepted finance two-band mode');
   }
   if(key==='finance'&&width>=1920){
    assert.equal(await scene.locator('.registry-ui-wide-head').isVisible(),true,'wide viewport must expose all 13 headers');
    assert.equal(await scene.locator('.registry-ui-compact-head').isVisible(),false,'bounded workspace must not shrink the preview viewport');
   }
   if(width===390){
    assert.equal(await scene.locator('.registry-ui-data--cards').count(),['finance','trips'].includes(key)?1:0,'only approved card variants');
   }
   const search=scene.locator('[data-registry-field=query]'),reset=scene.locator('.registry-ui-reset');
   assert.equal(await reset.isVisible(),false);
   assert.equal(await reset.innerText(),'Сбросить фильтр');
   assert.equal(await scene.locator('.registry-ui-toolbar .toolbar-right > .registry-ui-reset').count(),1,'reset is first in the shared right group');
   const layout=()=>scene.locator('.registry-ui-toolbar').evaluate(toolbar=>({
    height:toolbar.getBoundingClientRect().height,
    controls:[...toolbar.querySelectorAll('.lab-reference-trigger,.registry-ui-search')].map(node=>{
     const box=node.getBoundingClientRect();return [box.x,box.y];
    })
   }));
   const hiddenLayout=await layout();
   await search.fill('zzzz-no-match');await reset.waitFor({state:'visible'});
   assert.deepEqual(await layout(),hiddenLayout,`gallery ${key} ${width}: revealing reset does not move filters/search or toolbar height`);
   assert.equal(await scene.locator('[data-gallery-count]').innerText(),'0');
   assert.equal(await scene.locator('[data-gallery-empty]').isVisible(),true);
   assert.equal(await search.evaluate(element=>getComputedStyle(element).backgroundImage.includes('svg')),true,'focus preserves search icon');
   await reset.click();await reset.waitFor({state:'hidden'});
   assert.equal(await search.inputValue(),'');
   assert.equal(await scene.locator('[data-gallery-empty]').isVisible(),false);
   if(evidence){fs.mkdirSync(evidence,{recursive:true});await page.screenshot({path:path.join(evidence,`canon-${key}-${width}.png`),fullPage:true});}
  }
  await page.locator('[data-gallery-scene=finance]').click();
  const finance=view('finance');
  const styles=await finance.evaluate(element=>{
   const css=selector=>{const s=getComputedStyle(element.querySelector(selector));return [s.fontSize,s.fontWeight,s.lineHeight,s.color]};
   return {primary:css('.registry-ui-due--today .registry-ui-primary'),secondary:css('.registry-ui-secondary'),name:css('.registry-ui-name'),money:css('.registry-ui-money'),today:css('.registry-ui-due--today .registry-ui-secondary'),complete:css('.registry-ui-due--complete .registry-ui-primary'),id:css('tbody .registry-ui-id')};
  });
  assert.deepEqual(styles.primary.slice(0,3),['12px','500','16.8px']);
  assert.deepEqual(styles.secondary,['11px','500','15.4px','rgb(120, 114, 106)']);
  assert.deepEqual(styles.name.slice(0,3),['12px','600','16.8px']);
  assert.deepEqual(styles.money.slice(0,3),['12px','600','16.8px']);
  assert.equal(styles.today[3],'rgb(19, 18, 16)');assert.equal(styles.complete[3],'rgb(31, 107, 67)');
  assert.deepEqual(styles.id.slice(0,3),['12px','700','16.8px']);
  assert.deepEqual(await finance.locator('[data-gallery-row]').evaluateAll(rows=>rows.map(row=>row.children.length)),[13,13,13,13,13]);
  assert.equal(await finance.locator('[data-gallery-total]').count(),6);
  assert.deepEqual(await finance.locator('[data-gallery-total]').first().evaluate(element=>{const s=getComputedStyle(element);return [s.fontSize,s.fontWeight,s.lineHeight]}),['11px','700','15.4px']);
  const last=finance.locator('[data-registry-trip]').last();
  assert.equal(await last.evaluate(element=>[...new Intl.Segmenter('ru',{granularity:'grapheme'}).segment(element.textContent)].length),31);
  assert.equal((await last.getAttribute('title')).endsWith('сборный рейс'),true);
  await finance.locator('[data-registry-field=query]').fill('сборный');
  assert.equal(await finance.locator('[data-gallery-count]').innerText(),'1','search includes truncated tail');
  assert.equal((await finance.locator('[data-gallery-total="3"]').innerText()).replace(/\s/g,''),'146494,50₽','filtered totals');
  await finance.locator('.registry-ui-reset').click();
  const check=finance.locator('[type=checkbox]');await check.focus();await page.keyboard.press('Space');
  assert.equal(await check.isChecked(),true);await finance.locator('.registry-ui-reset').waitFor({state:'visible'});
  await finance.locator('.registry-ui-reset').click();assert.equal(await check.isChecked(),false);
  await page.locator('[data-gallery-scene=executors]').click();
  const executor=view('executors'),trigger=executor.locator('.lab-reference-trigger');
  await trigger.click();const selected=executor.locator('[aria-selected=true]');
  await page.mouse.move(0,0);
  assert.equal(await selected.evaluate(element=>getComputedStyle(element).backgroundColor),'rgb(242, 232, 220)');
  await selected.hover();assert.equal(await selected.evaluate(element=>getComputedStyle(element).backgroundColor),'rgb(233, 217, 199)');
  await page.keyboard.press('ArrowDown');await page.keyboard.press('Enter');
  await executor.locator('.registry-ui-reset').waitFor({state:'visible'});
  await executor.locator('.registry-ui-reset').click();
  await executor.getByRole('button',{name:'Действия с карточкой'}).click();
  const remove=frame.getByRole('menuitem',{name:'Удалить карточку'});
  assert.equal(await remove.isVisible(),true);
  assert.equal(await remove.evaluate(element=>getComputedStyle(element).color),'rgb(135, 40, 34)');
  await page.keyboard.press('Escape');assert.equal(await remove.isVisible(),false);
  for(const [cls,normal,hover] of [['registry-ui-warning','rgb(253, 240, 213)','rgb(255, 241, 189)'],['registry-ui-soft-error','rgb(255, 229, 223)','rgb(255, 214, 204)'],['registry-ui-soft-success','rgb(223, 243, 229)','rgb(205, 235, 215)']]){
   const row=executor.locator('tr.'+cls),cell=row.locator('td').first();
   await page.mouse.move(0,0);await trigger.focus();await settle();
   assert.equal(await cell.evaluate(element=>getComputedStyle(element).backgroundColor),normal);
   await cell.hover();await settle();assert.equal(await cell.evaluate(element=>getComputedStyle(element).backgroundColor),hover);
  }
  // Host decorations cannot restyle the component document.
  await page.addStyleTag({content:'.registry-ui-search{height:77px!important;font:30px Arial!important}'});
  assert.equal(await executor.locator('.registry-ui-search').evaluate(element=>getComputedStyle(element).height),'32px');
  assert.deepEqual(errors,[]);
  await page.close();
 }
 // Persistence, rejected stale options, isolation, storage failure and long IDs.
 const page=await browser.newPage({viewport:{width:1280,height:900}});await page.goto(url);
 const frame=page.frames().find(item=>item.parentFrame());
 await frame.waitForSelector('[data-registry-ready=true]');
 await frame.locator('[data-gallery-view=executors] [data-registry-field=query]').fill('Магистраль');
 await frame.locator('[data-gallery-view=executors] .registry-ui-reset').waitFor({state:'visible'});
 await page.reload();
 const restored=page.frames().find(item=>item.parentFrame());await restored.waitForSelector('[data-registry-ready=true]');
 assert.equal(await restored.locator('[data-gallery-view=executors] [data-registry-field=query]').inputValue(),'Магистраль');
 await restored.evaluate(()=>{
  const key=Object.keys(localStorage).find(key=>key.includes('gallery-executors'));
  localStorage.setItem(key,JSON.stringify({query:'',sort:'removed-option',hideComplete:'bad',tab:'removed-tab'}));
 });
 await page.reload();const clean=page.frames().find(item=>item.parentFrame());await clean.waitForSelector('[data-registry-ready=true]');
 assert.equal(await clean.locator('[data-gallery-view=executors] select').inputValue(),'date');
 await clean.locator('[data-gallery-view=executors] [data-registry-field=query]').fill('private-owner-selection');
 await clean.locator('[data-gallery-view=executors] .registry-ui-reset').waitFor({state:'visible'});
 await clean.evaluate(()=>{
  const original=document.querySelector('[data-gallery-view=executors]');
  const copy=original.cloneNode(true);copy.dataset.registryStateScope='other-account:logist:other-company:v1';copy.dataset.galleryView='isolation';
  copy.querySelector('[data-registry-field=query]').value='';document.querySelector('[data-specimen-root]').append(copy);window.PlanexRegistry.mount(copy);
 });
 assert.equal(await clean.locator('[data-gallery-view=isolation] [data-registry-field=query]').inputValue(),'');
 await clean.evaluate(()=>{
  const copy=document.querySelector('[data-gallery-view=executors]').cloneNode(true);copy.dataset.registryStateScope='broken-json';copy.dataset.galleryView='broken';
  localStorage.setItem(`planex:registry:v1:${location.pathname}:${copy.dataset.registryId}:broken-json`,'{');document.querySelector('[data-specimen-root]').append(copy);window.PlanexRegistry.mount(copy);
 });
 assert.equal(await clean.locator('[data-gallery-view=broken] [data-registry-field=query]').inputValue(),'','corrupt saved JSON returns to the page defaults');
 await clean.evaluate(()=>{
  const root=document.querySelector('[data-gallery-view=finance]');root.querySelector('tbody .registry-ui-id').textContent='#12345678901234567890';window.PlanexRegistry.textRoles(root);
 });
 await page.waitForTimeout(100);
 assert.ok(await clean.locator('[data-gallery-view=finance] .registry-ui-data').evaluate(element=>parseFloat(element.style.getPropertyValue('--registry-id-width')))>64);
 await clean.evaluate(()=>{
  Storage.prototype.setItem=()=>{throw new Error('blocked')};Storage.prototype.getItem=()=>{throw new Error('blocked')};
  const copy=document.querySelector('[data-gallery-view=executors]').cloneNode(true);copy.dataset.galleryView='blocked';document.querySelector('[data-specimen-root]').append(copy);window.PlanexRegistry.mount(copy);
 });
 await clean.locator('[data-gallery-view=blocked] [data-registry-field=query]').fill('test');
 await clean.locator('[data-gallery-view=blocked] .registry-ui-reset').waitFor({state:'visible'});
 await clean.locator('[data-gallery-view=blocked] .registry-ui-reset').click();
 assert.equal(await clean.locator('[data-gallery-view=blocked] [data-registry-field=query]').inputValue(),'');
 // Exercise the real shared sticky logic with one bounded vertical owner and a
 // wide table. The fixture geometry is test-only; application CSS stays shared.
 await clean.evaluate(()=>{
  const original=document.querySelector('[data-gallery-view=executors]');
  const copy=original.cloneNode(true);copy.dataset.galleryView='scroll';copy.dataset.registryStateScope='scroll-fixture';copy.querySelector('[data-registry-field=query]').value='';
  const main=document.createElement('main');main.className='content';main.style.cssText='height:320px;width:850px;overflow:auto;padding:12px;position:relative';
  original.after(main);main.append(copy);
  copy.querySelector('.registry-ui-toolbar').style.minHeight='80px';copy.querySelector('.registry-ui-card').style.removeProperty('--registry-toolbar-height');
  const tbody=copy.querySelector('tbody'),rows=[...tbody.querySelectorAll('[data-gallery-row]')];
  rows.forEach(row=>{row.hidden=false;});copy.querySelector('[data-gallery-empty]').hidden=true;
  for(let i=0;i<12;i++)rows.forEach(row=>tbody.append(row.cloneNode(true)));
  window.PlanexRegistry.mount(copy);main.scrollTop=300;
 });
 await page.waitForTimeout(100);
 const scroll=await clean.locator('[data-gallery-view=scroll]').evaluate(view=>{
  const main=view.parentElement,wrap=view.querySelector('.table-scroll'),toolbar=view.querySelector('.registry-ui-toolbar'),table=view.querySelector('table');
  wrap.scrollLeft=150;
  return {main:main.scrollTop,inner:wrap.scrollHeight-wrap.clientHeight,measured:parseFloat(getComputedStyle(view.querySelector('.registry-ui-card')).getPropertyValue('--registry-toolbar-height')),height:toolbar.getBoundingClientRect().height,y:table.tHead.getBoundingClientRect().top-toolbar.getBoundingClientRect().bottom,x:table.tHead.rows[0].cells[0].getBoundingClientRect().left-table.tBodies[0].rows[0].cells[0].getBoundingClientRect().left};
 });
 assert.equal(scroll.main,300);assert.equal(scroll.inner,0);assert.ok(Math.abs(scroll.y)<1);assert.ok(Math.abs(scroll.x)<1);
 assert.equal(scroll.measured,scroll.height,'dynamically mounted registry measures its own responsive toolbar');
 await clean.locator('[data-gallery-view=scroll]').evaluate(view=>view.querySelectorAll('tbody tr').forEach(row=>row.hidden=true));
 await page.waitForTimeout(100);
 assert.equal(await clean.locator('[data-gallery-view=scroll]').evaluate(view=>view.parentElement.scrollHeight-view.parentElement.clientHeight),0,'empty state has no ghost header range');
 await page.close();
 // Reproduce the independent audit: loaded font files do not prove the actual
 // glyph family, and missing semantic markers must never pass by vacuity.
 const audit=await browser.newPage({viewport:{width:1920,height:1080}});
 await audit.goto(url);
 await audit.locator('[data-gallery-scene=finance]').click();
 const auditFrame=audit.frames().find(frame=>frame.parentFrame());
 await auditFrame.locator('[data-gallery-view=finance] .registry-ui-search').waitFor();
 await assertRegistryDataRoles(auditFrame,['primary','name','secondary'],'audit baseline');
 const hostile=await auditFrame.addStyleTag({content:'.registry-ui-data .registry-ui-name,.registry-ui-data .registry-ui-secondary{font-family:Arial!important}.registry-ui-data .registry-ui-primary{font-size:25px!important;color:blue!important}'});
 await assert.rejects(()=>assertRegistryVisualContract(auditFrame,'audit actual font drift'),/actual font family|typography\/color/);
 await hostile.evaluate(element=>element.remove());
 const fallback=await auditFrame.addStyleTag({content:'.registry-ui-data .registry-ui-name{font-family:Arial,"IBM Plex Sans"!important}'});
 await assert.rejects(()=>assertRegistryDataRoles(auditFrame,['name'],'audit fallback family'),/actual font family/);
 await fallback.evaluate(element=>element.remove());
 const narrow=await auditFrame.addStyleTag({content:'.registry-ui-data .registry-ui-trip-cell{width:228px!important;min-width:228px!important}'});
 await assert.rejects(()=>assertRegistryColumnGeometry(auditFrame,'audit narrow trip column'),/canonical trip column width/);
 await narrow.evaluate(element=>element.remove());
 // A typed status elsewhere must not hide an untyped, incorrectly inherited
 // document state. Color-only spans cannot establish typography coverage.
 await auditFrame.locator('[data-gallery-view=finance] tbody tr').first().locator('td').first().evaluate(element=>{
  const value=document.createElement('span');value.className='registry-ui-error';value.textContent='НЕТ';element.append(value);
  element.classList.remove('registry-ui-id');
 });
 await assert.rejects(()=>assertRegistryDataCoverage(auditFrame,'audit color-only data'),/untyped visible table data/);
 await auditFrame.locator('[data-gallery-view=finance] .registry-ui-name').evaluateAll(elements=>elements.forEach(element=>element.classList.remove('registry-ui-name')));
 await assert.rejects(()=>assertRegistryDataRoles(auditFrame,['name'],'audit missing coverage'),/no visible coverage/);
 await audit.close();
 console.log('UI_STANDARD_GALLERY_BROWSER=PASS');
}finally{await browser.close()}
