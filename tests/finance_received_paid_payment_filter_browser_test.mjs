import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {createRequire} from 'node:module';
import {execFileSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';
import {assertRegistryVisualContract,assertRegistryDataContract,assertRegistryDataCoverage} from './helpers/registry_visual_contract.mjs';
import {assertRegistryTablerIcon} from './helpers/registry_tabler_icon_contract.mjs';

const require=createRequire(import.meta.url),{chromium}=require('playwright');
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const html=execFileSync(process.env.PLANEX_PHP||'php',[path.join(root,'tests/fixtures/finance_received_paid_filter_render.php')],{encoding:'utf8'});
const css=fs.readFileSync(path.join(root,'public/assets/css/finance-received-paid.css'),'utf8');
const ledgerCss=fs.readFileSync(path.join(root,'public/assets/css/erp-ledger-table.css'),'utf8');
const registryCss=fs.readFileSync(path.join(root,'public/assets/css/registry-components.css'),'utf8');
const appCss=fs.readFileSync(path.join(root,'public/assets/css/app.css'),'utf8');
const erpCss=fs.readFileSync(path.join(root,'public/assets/css/erp-ui.css'),'utf8');
const fontCss=[[400,'Regular'],[500,'Medium'],[600,'SemiBold'],[700,'Bold']].map(([weight,name])=>`@font-face{font-family:"IBM Plex Sans";src:url(data:font/woff2;base64,${fs.readFileSync(path.join(root,`public/assets/fonts/IBMPlexSans-${name}.woff2`)).toString('base64')}) format("woff2");font-weight:${weight}}`).join('\n');
const js=fs.readFileSync(path.join(root,'public/assets/js/finance-received-paid.js'),'utf8');
const registryJs=fs.readFileSync(path.join(root,'public/assets/js/registry-components.js'),'utf8');
const referenceSelectJs=fs.readFileSync(path.join(root,'public/assets/js/table-reference-select.js'),'utf8');
const browser=await chromium.launch({headless:true,...(process.env.PLANEX_BROWSER_EXECUTABLE?{executablePath:process.env.PLANEX_BROWSER_EXECUTABLE}:{})});
try{
 const page=await browser.newPage({viewport:{width:1280,height:900}}),errors=[];
 page.on('pageerror',error=>errors.push(error.message));
 await page.route('**/assets/**',route=>{const file=path.join(root,'public',new URL(route.request().url()).pathname.replace(/^\/erpv2/,'') );return route.fulfill({contentType:file.endsWith('.css')?'text/css':'font/woff2',body:fs.readFileSync(file)})});
 await page.route('http://127.0.0.1/company/finance/received-paid',route=>route.fulfill({contentType:'text/html',body:html.replace(/<link\b[^>]*>/g,'').replace(/<script\b[^>]*src=[^>]*>[\s\S]*?<\/script>/g,'')}));
 const mountFixture=async()=>{
   await page.addStyleTag({content:appCss.replace(/@import[^;]+;/g,'')+'\n'+erpCss+'\n'+css+'\n'+ledgerCss+'\n'+registryCss+'\n'+fontCss+'\nbody{margin:0;overflow:auto}.modal-overlay{display:none}.modal-overlay.is-open{display:flex}'});
   await page.evaluate(()=>{window.openModal=id=>document.getElementById(id).classList.add('is-open');window.closeModal=id=>document.getElementById(id).classList.remove('is-open');});
   await page.addScriptTag({content:js});
   await page.addScriptTag({content:referenceSelectJs});
   await page.addScriptTag({content:registryJs});
   await page.locator('[data-registry-id="finance-received-paid"][data-registry-ready="true"]').waitFor();
 };
 await page.goto('http://127.0.0.1/company/finance/received-paid');
 await mountFixture();
 const toolbar=page.locator('.rp-v015-toolbar');
 const reset=toolbar.locator('.registry-ui-reset');
 assert.equal(await toolbar.locator(':scope > .registry-ui-checkboxes > .found-label + .filter-sep + label:has(#rp-hide-complete)').count(),1,'count, divider and original checkbox share the left group');
 assert.equal(await toolbar.locator('label:has(#rp-hide-complete)').innerText(),'Показать завершённые');
 assert.equal(await page.locator('#rp-hide-complete').getAttribute('data-registry-field'),'showComplete','new state field isolates old persisted hideComplete values');
 assert.equal(await page.locator('#rp-hide-complete').getAttribute('data-registry-default'),'false');
 assert.equal(await toolbar.locator(':scope > .toolbar-right > .registry-ui-reset:first-child').count(),1,'reset precedes the finance sort and search');
 assert.equal(await toolbar.locator(':scope > .toolbar-right > .registry-ui-search-group:last-child #rp-search').count(),1,'search remains the last control');
 assert.equal(await reset.innerText(),'Сбросить фильтр');
 for(const width of [390,900,1280,1920,3840]){
   await page.setViewportSize({width,height:900});
   const geometry=()=>toolbar.evaluate(element=>{
     const box=selector=>{const rect=(selector==='self'?element:element.querySelector(selector)).getBoundingClientRect();return {x:Math.round(rect.x),y:Math.round(rect.y),width:Math.round(rect.width),height:Math.round(rect.height)};};
     return {toolbar:box('self'),sort:box('.lab-reference-trigger'),search:box('#rp-search'),count:box('.found-label'),divider:box('.filter-sep'),check:box('#rp-hide-complete'),overflow:document.documentElement.scrollWidth>innerWidth+1};
   });
   assert.equal(await reset.isHidden(),true,`${width}: default reset hidden`);
   assert.equal(await page.locator('#rp-hide-complete').isChecked(),false,`${width}: completed trips hidden by default`);
   const before=await geometry();
   const dividerStyle=await toolbar.locator('.filter-sep').evaluate(element=>({width:getComputedStyle(element).width,height:getComputedStyle(element).height,color:getComputedStyle(element).backgroundColor}));
   assert.deepEqual(dividerStyle,{width:'1px',height:'18px',color:'rgb(222, 218, 208)'},`${width}: existing divider matches canon`);
   assert.equal(before.divider.x-before.count.x-before.count.width>=18,true,`${width}: divider separated from count`);
   assert.equal(before.check.x-before.divider.x-before.divider.width>=18,true,`${width}: checkbox separated from divider`);
   if(process.env.PLANEX_VISUAL_EVIDENCE_DIR){
     fs.mkdirSync(process.env.PLANEX_VISUAL_EVIDENCE_DIR,{recursive:true});
     await toolbar.screenshot({path:path.join(process.env.PLANEX_VISUAL_EVIDENCE_DIR,`received-paid-toolbar-${width}-reset-hidden.png`)});
   }
   await page.locator('#rp-search').fill('103');
   assert.equal(await reset.isVisible(),true,`${width}: dirty search reveals reset`);
   const after=await geometry();
   assert.deepEqual(after.sort,before.sort,`${width}: revealing reset keeps select coordinates`);
   assert.deepEqual(after.search,before.search,`${width}: revealing reset keeps search coordinates`);
   assert.equal(after.toolbar.height,before.toolbar.height,`${width}: revealing reset keeps toolbar height`);
   assert.equal(after.overflow,false,`${width}: no document overflow`);
   if(process.env.PLANEX_VISUAL_EVIDENCE_DIR) await toolbar.screenshot({path:path.join(process.env.PLANEX_VISUAL_EVIDENCE_DIR,`received-paid-toolbar-${width}-reset-visible.png`)});
   await reset.click();
   assert.equal(await reset.isHidden(),true,`${width}: reset hides after use`);
   assert.equal(await page.locator('#rp-search').inputValue(),'');
   assert.equal(await page.locator('#rp-hide-complete').isChecked(),false);
   assert.equal(await page.locator('#rp-sort').inputValue(),'date-new');
   assert.equal(await toolbar.locator('.found-label b').innerText(),'4');
   if(width===390){
     assert.equal(await page.locator('#rp-ledger-table').evaluate(table=>table.classList.contains('registry-ui-data--cards')),true,'mobile finance cards remain active');
     await page.locator('#rp-hide-complete').check();
     assert.equal(await page.locator('#rp-list tr[data-id="101"]').isVisible(),true,'completed mobile card is revealed');
     await reset.click();
     assert.equal(await page.locator('#rp-list tr[data-id="101"]').isVisible(),false,'reset hides completed mobile card');
   }
 }
 await page.setViewportSize({width:1280,height:900});
 await page.locator('#rp-hide-complete').focus();
 await page.keyboard.press('Space');
 assert.equal(await page.locator('#rp-hide-complete').isChecked(),true,'Space includes completed settlements');
 assert.equal(await reset.isVisible(),true,'checkbox change reveals shared reset');
 assert.deepEqual(await page.locator('#rp-list tr:visible').evaluateAll(rows=>rows.map(row=>row.dataset.id)),['105','104','103','102','101']);
 assert.equal(await page.locator('#rp-count').innerText(),'5');
 assert.equal(await page.locator('[data-total="customer-invoices"]').innerText(),'200 ₽','completed row contributes to invoice total');
 assert.equal(await page.locator('[data-total="received"]').innerText(),'240 ₽','completed row contributes to received total');
 await reset.click();
 assert.equal(await page.locator('#rp-hide-complete').isChecked(),false,'reset restores unchecked profile');
 assert.equal(await page.locator('#rp-count').innerText(),'4');
 assert.equal(await page.locator('[data-total="customer-invoices"]').innerText(),'100 ₽','reset excludes completed totals again');
 for(const width of [390,1280,1920,3840]){
  await page.setViewportSize({width,height:900});
  await assertRegistryTablerIcon(page,'finance-received-paid');
  await assertRegistryDataContract(page,'finance-received-paid');
  if(width===390){
    await page.locator('.rp-v010-mobile-group:visible').filter({hasText:'Клиент'}).first().evaluate(element=>element.dataset.captionProbe='true');
    const caption=page.locator('[data-caption-probe=true]');
    const original=await caption.innerHTML();
    await caption.evaluate(element=>element.textContent='Непроверенные бизнес-данные');
    await assert.rejects(assertRegistryDataCoverage(page,'finance-caption-negative'),/untyped visible table data/);
    await caption.evaluate(element=>element.innerHTML='<span>Вложенные бизнес-данные</span>');
    await assert.rejects(assertRegistryDataCoverage(page,'finance-caption-nested-negative'),/untyped visible table data/);
    await caption.evaluate((element,html)=>element.innerHTML=html,original);
    await caption.evaluate(element=>element.style.fontSize='20px');
    await assert.rejects(assertRegistryDataCoverage(page,'finance-caption-font-negative'),/finance caption typography/);
    await caption.evaluate(element=>element.style.removeProperty('font-size'));
    await assertRegistryDataCoverage(page,'finance-caption-restored');
    console.log('REGISTRY_CAPTION_NEGATIVE=unknown,nested,wrong-font');
  }

 }
 await page.setViewportSize({width:1280,height:900});
 await assertRegistryVisualContract(page,'finance-received-paid 1280px');
 await assertRegistryDataContract(page, 'finance-received-paid');
 const sharedStyles=await page.evaluate(()=>{
   const search=document.querySelector('#rp-search');
   const heading=document.querySelector('#rp-ledger-table thead th');
   return {searchWeight:getComputedStyle(search).fontWeight,placeholder:getComputedStyle(search,'::placeholder').color,headingBackground:getComputedStyle(heading).backgroundColor,headingImage:getComputedStyle(heading).backgroundImage};
 });
 assert.deepEqual(sharedStyles,{searchWeight:'500',placeholder:'rgb(129, 123, 114)',headingBackground:'rgb(208, 204, 196)',headingImage:'none'});
 const ids=()=>page.locator('#rp-list tr:visible').evaluateAll(items=>items.map(item=>item.dataset.id));
 assert.deepEqual(await ids(),['105','104','103','102']);
 assert.equal(await page.locator('#rp-count').innerText(),'4');
 assert.equal(await page.locator('#rp-ledger-table thead').innerText().then(s=>s.includes('СЧЁТ')),false,'headers use Е');
 assert.equal(await page.locator('#rp-list tr[data-id="104"] .rp-v015-driver').count(),0,'unknown driver is hidden');
 assert.equal(await page.locator('#rp-list tr[data-id="103"]').getAttribute('data-carrier-due'),'overdue');
 assert.equal(await page.locator('#rp-list tr[data-id="102"] .rp-v010-doc-list').first().locator('li').count(),2,'multiple trip invoices remain separate');
 assert.equal(await page.locator('#rp-list tr[data-id="102"] .rp-v012-doc-date').first().innerText(),'29.09.2026','real invoice_date is below the number');
 assert.equal(await page.locator('[data-total="customer-invoices"]').innerText(),'100 ₽','visible invoice links sum once');
 await page.locator('[data-rp-filter="carrier-due"]').click();
 assert.deepEqual(await ids(),['103'],'real carrier deadline filter');
 await page.locator('[data-rp-filter="all"]').click();
 await page.locator('#rp-search').fill('101');
 assert.deepEqual(await ids(),[],'search composes with completion toggle');
 assert.equal(await page.locator('[data-total="customer-invoices"]').innerText(),'—','empty invoice total is unknown');
 await page.locator('#rp-hide-complete').check();
 assert.deepEqual(await ids(),['101']);
 assert.equal(await page.locator('[data-total="customer-invoices"]').innerText(),'100 ₽');
 await page.locator('#rp-search').clear();
 await page.locator('.rp-standard-box .lab-reference-trigger').click();
 await page.locator('.rp-standard-box [role=option][data-value="id-new"]').click();
 assert.deepEqual(await ids(),['105','104','103','102','101']);
 assert.equal(await page.locator('#rp-list tr[data-id="101"] td').count(),13);
 assert.deepEqual(await page.locator('.rp-v012-wide-head th').allInnerTexts().then(values=>values.slice(0,2)),['ID','ОПИСАНИЕ РЕЙСА']);
 assert.equal(await page.locator('#rp-list tr[data-id="102"] td').nth(0).innerText(),'#102');
 assert.equal(await page.locator('#rp-list tr[data-id="102"] td').nth(1).innerText(),'Рейс 102');
 await page.locator('#rp-list tr[data-id="101"]').dblclick();
 assert.equal(await page.locator('#rp-detail-modal').isVisible(),true);
 assert.equal(await page.locator('#rp-detail-modal .rp-v015-detail-driver').count(),1);
 await page.evaluate(()=>{window.fetch=async url=>{window.__invoiceUrl=url;return {ok:true,text:async()=>'<p>Счёт открыт</p>'};};});
 await page.locator('#rp-detail-modal [data-view-invoice]').first().click();
 assert.equal(await page.locator('#rp-detail-modal').isVisible(),false,'invoice view replaces trip details');
 assert.equal(await page.locator('#invoice-view-modal').isVisible(),true);
 assert.equal(await page.evaluate(()=>window.__invoiceUrl),'/erpv2/company/finance/invoices/1/modal-view','invoice uses the ERP base path');
 await page.evaluate(()=>window.closeModal('invoice-view-modal'));
 assert.equal(await page.locator('#rp-list tr[data-id="101"]').evaluate(row=>document.activeElement===row),true,'focus returns to row');
 await page.locator('#rp-hide-complete').uncheck();
 await page.locator('.rp-standard-box .lab-reference-trigger').click();
 await page.locator('.rp-standard-box [role=option][data-value="date-new"]').click();
 assert.equal(await page.locator('#rp-sort').inputValue(),'date-new','visible listbox keeps the native sort value');
 const tabs=page.locator('#rp-filters [data-rp-filter]');
 assert.equal(await tabs.count(),6,'all six real finance presets remain available');
 for(const width of [390,768,1051,1280,1600,1920,3840]){
   await page.setViewportSize({width,height:900});
   const tabLayout=await page.locator('#rp-filters').evaluate(nav=>({height:Math.round(nav.getBoundingClientRect().height),nowrap:getComputedStyle(nav).flexWrap,overflow:getComputedStyle(nav).overflowX,buttons:[...nav.querySelectorAll('button')].map(button=>({height:Math.round(button.getBoundingClientRect().height),radius:getComputedStyle(button).borderRadius}))}));
   assert.equal(tabLayout.height,38,`flat tab strip height at ${width}`);
   assert.equal(tabLayout.nowrap,'nowrap',`tabs stay on one line at ${width}`);
   assert.equal(tabLayout.overflow,'auto',`tabs scroll locally at ${width}`);
   assert.deepEqual(tabLayout.buttons.map(button=>[button.height,button.radius]),Array(6).fill(0).map(()=>[37,'0px']),`six equal flat tabs at ${width}`);
   assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),true,`no horizontal overflow at ${width}`);
   if(width===390) assert.equal(await page.locator('.rp-v015-toolbar .lab-reference-trigger').evaluate(control=>control.getBoundingClientRect().right<=control.closest('.rp-v015-toolbar').getBoundingClientRect().right-8),true,'mobile sort control stays inside the toolbar');
   assert.deepEqual(await page.locator('.rp-standard-box .lab-reference-trigger, .rp-standard-box #rp-search').evaluateAll(items=>items.map(el=>getComputedStyle(el).borderRadius)),['2px','2px'],`accepted control radius at ${width}`);
   assert.equal(await page.locator('.rp-v010-table-wrap').evaluate(el=>el.scrollWidth<=el.clientWidth+1),true,`ledger wrapper has no horizontal scroll at ${width}`);
   if(width>=1920){
     const fixedColumns=await page.locator('#rp-ledger-table tbody tr:visible').first().evaluate(row=>[row.cells[0].getBoundingClientRect().width,row.cells[1].getBoundingClientRect().width]);
     assert.ok(Math.abs(fixedColumns[0]-64)<=1&&Math.abs(fixedColumns[1]-240)<=1,`${width}: canonical 64px ID and 240px trip widths, got ${fixedColumns}`);
   }
   if(width===390){
     const columns=await page.locator('#rp-list tr[data-id="102"]').evaluate(row=>[4,5,6,7,10,11,12,13].map(index=>Math.round(row.cells[index-1].querySelector('.registry-ui-value')?.getBoundingClientRect().left||0)));
     assert.equal(new Set(columns).size,1,'mobile values share one aligned column');
   }
   if(width===1280){
     const geometry=await page.locator('#rp-list tr[data-id="102"]').evaluate(row=>({rowBottom:row.getBoundingClientRect().bottom,docsBottom:row.querySelector('.rp-v010-doc-list').getBoundingClientRect().bottom}));
     assert.equal(geometry.docsBottom<=geometry.rowBottom+1,true,'two invoices stay inside the compact trip row');
     assert.equal(await page.locator('#rp-ledger-table tfoot tr').evaluate(row=>{const height=Math.round(row.getBoundingClientRect().height);return height>=40&&height<=90;}),true,'compact totals keep label above amount');
   }
   if(width===1920){
     const frame=await page.locator('.rp-standard-box.rp-ledger-v015').evaluate(card=>{
       const toolbar=getComputedStyle(card.querySelector(':scope > .table-toolbar'));
       const table=getComputedStyle(card.querySelector(':scope > .rp-v010-table-wrap'));
       const tabs=getComputedStyle(document.querySelector('#rp-filters'));
       return {cardTop:getComputedStyle(card).borderTopWidth,cardLeft:getComputedStyle(card).borderLeftWidth,cardRight:getComputedStyle(card).borderRightWidth,cardBottom:getComputedStyle(card).borderBottomWidth,top:toolbar.borderTopWidth,left:toolbar.borderLeftWidth,right:toolbar.borderRightWidth,tableLeft:table.borderLeftWidth,tableRight:table.borderRightWidth,bottom:table.borderBottomWidth,tabsTop:tabs.borderTopWidth,tabsLeft:tabs.borderLeftWidth,tabsRight:tabs.borderRightWidth};
     });
     assert.deepEqual(frame,{cardTop:'1px',cardLeft:'1px',cardRight:'1px',cardBottom:'1px',top:'0px',left:'0px',right:'0px',tableLeft:'0px',tableRight:'0px',bottom:'0px',tabsTop:'0px',tabsLeft:'0px',tabsRight:'0px'},'ledger working area has one continuous outer frame while tabs remain frameless');
     const fonts=await page.locator('#rp-ledger-table').evaluate(table=>({td:getComputedStyle(table.tBodies[0].rows[0].cells[0]).fontSize,th:getComputedStyle(table.tHead.rows[0].cells[0]).fontSize}));
     assert.deepEqual(fonts,{td:'12px',th:'10px'},'ERP table typography matches v015');
     const canonicalTripType=await page.locator('#rp-list tr[data-id="102"]').evaluate(row=>{
       const id=getComputedStyle(row.cells[0]),description=getComputedStyle(row.cells[1].querySelector('span'));
       const header=getComputedStyle(document.querySelector('.rp-v012-wide-head th'));
       return {id:{family:id.fontFamily,size:id.fontSize,weight:id.fontWeight,line:id.lineHeight,color:id.color},description:{size:description.fontSize,weight:description.fontWeight,line:description.lineHeight},header:{size:header.fontSize,weight:header.fontWeight,line:header.lineHeight}};
     });
     assert.equal(canonicalTripType.id.family.includes('IBM Plex Sans'),true,'trip ID uses the locally loaded canonical font');
     assert.deepEqual({...canonicalTripType.id,family:'IBM Plex Sans'},{family:'IBM Plex Sans',size:'12px',weight:'700',line:'16.8px',color:'rgb(19, 18, 16)'},'trip ID is canonical black');
     assert.deepEqual(canonicalTripType.description,{size:'12px',weight:'500',line:'16.8px'},'trip description matches departures');
     assert.deepEqual(canonicalTripType.header,{size:'10px',weight:'700',line:'14px'},'table header matches departures');
     const tabFont=await tabs.first().evaluate(button=>({size:getComputedStyle(button).fontSize,line:getComputedStyle(button).lineHeight,countSize:getComputedStyle(button.querySelector('b')).fontSize}));
     assert.deepEqual(tabFont,{size:'12px',line:'16px',countSize:'12px'},'finance tabs use the approved status-tab typography');
   }
   if(process.env.PLANEX_VISUAL_EVIDENCE_DIR&&[390,1280,1920].includes(width)){
     fs.mkdirSync(process.env.PLANEX_VISUAL_EVIDENCE_DIR,{recursive:true});
     await page.screenshot({path:path.join(process.env.PLANEX_VISUAL_EVIDENCE_DIR,`received-paid-v015-${width}.png`),fullPage:true});
     if(width===390){
       await page.locator('#rp-list tr[data-id="102"]').screenshot({path:path.join(process.env.PLANEX_VISUAL_EVIDENCE_DIR,'received-paid-v015-390-multiple-invoices.png')});
       await page.locator('#rp-ledger-table tfoot').screenshot({path:path.join(process.env.PLANEX_VISUAL_EVIDENCE_DIR,'received-paid-v015-390-footer.png')});
       await page.locator('#rp-list tr[data-id="103"]').dblclick();
       assert.equal(await page.locator('#rp-detail-title').innerText(),'Рейс #103 · Рейс 103');
       assert.equal(await page.locator('#rp-detail-modal .rp-details-meta').innerText().then(text=>text.includes('Груз сдан')),true);
       assert.equal(await page.locator('#rp-detail-modal .rp-finance-metrics dt').allInnerTexts().then(labels=>labels.join('|')),'Ставка в рейсе|Получено|Остаток|Ставка в рейсе|Оплачено|Остаток');
       assert.deepEqual(await page.locator('#rp-detail-modal .rp-v011-detail-due small').allInnerTexts(),['Просрочено на 1 день','Просрочено на 1 день']);
       await page.locator('#rp-detail-modal .modal').screenshot({path:path.join(process.env.PLANEX_VISUAL_EVIDENCE_DIR,'received-paid-v015-390-details.png')});
       await page.evaluate(()=>window.closeModal('rp-detail-modal'));
     }
   }
 }
 await page.setViewportSize({width:320,height:900});
 assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),true,'no page overflow at 320');
 assert.equal(await page.locator('.rp-v015-toolbar .lab-reference-trigger').evaluate(control=>control.getBoundingClientRect().right<=control.closest('.rp-v015-toolbar').getBoundingClientRect().right-8),true,'sort control stays inside the toolbar at 320');
 const lastTab=tabs.last();
 await lastTab.click();
 assert.equal(await lastTab.getAttribute('aria-pressed'),'true','last preset remains functional at 320');
 assert.equal(await lastTab.evaluate(button=>{const tab=button.getBoundingClientRect(),strip=button.parentElement.getBoundingClientRect();return tab.left>=strip.left-1&&tab.right<=strip.right+1}),true,'selected tab is visible in the strip');
 await page.keyboard.press('Tab');
 await page.keyboard.press('Shift+Tab');
 assert.equal(await lastTab.evaluate(button=>document.activeElement===button&&getComputedStyle(button).outlineStyle==='solid'),true,'keyboard focus is visible');
 await page.keyboard.press('Space');
 assert.equal(await lastTab.getAttribute('aria-pressed'),'false','keyboard activation toggles the preset');
 assert.equal(await lastTab.evaluate(button=>document.activeElement===button),true,'keyboard focus remains on the tab');
 const storageKey=await page.locator('[data-registry-id="finance-received-paid"]').evaluate(root=>`planex:registry:v1:${location.pathname}:${root.dataset.registryId}:${root.dataset.registryStateScope}`);
 await page.evaluate(key=>localStorage.setItem(key,JSON.stringify({hideComplete:true,query:'101',sort:'id-new',tab:'all'})),storageKey);
 await page.reload();
 await mountFixture();
 assert.equal(await page.locator('#rp-hide-complete').isChecked(),false,'old hideComplete state cannot enable new showComplete flag');
 assert.deepEqual(await page.locator('#rp-list tr:visible').evaluateAll(rows=>rows.map(row=>row.dataset.id)),[],'old state search remains, but completed row stays excluded');
 assert.equal(await page.locator('#rp-sort').inputValue(),'id-new','unchanged persisted sort remains compatible');
 const migrated=await page.evaluate(key=>JSON.parse(localStorage.getItem(key)),storageKey);
 assert.equal(migrated.showComplete,false,'new state field is persisted with unchecked default');
 assert.equal(Object.hasOwn(migrated,'hideComplete'),false,'old inverse field is discarded');
 await page.locator('#rp-hide-complete').check();
 assert.deepEqual(await page.locator('#rp-list tr:visible').evaluateAll(rows=>rows.map(row=>row.dataset.id)),['101'],'checked includes completed result after old-state migration');
 await page.reload();
 await mountFixture();
 assert.equal(await page.locator('#rp-hide-complete').isChecked(),true,'showComplete persists across reload');
 assert.deepEqual(await page.locator('#rp-list tr:visible').evaluateAll(rows=>rows.map(row=>row.dataset.id)),['101']);
 await page.locator('.registry-ui-reset').click();
 assert.equal(await page.locator('#rp-hide-complete').isChecked(),false);
 assert.equal(await page.locator('#rp-search').inputValue(),'');
 assert.equal(await page.locator('#rp-sort').inputValue(),'date-new');
 assert.equal(await page.locator('#rp-count').innerText(),'4');
 assert.deepEqual(errors,[]);
 console.log('FINANCE_RECEIVED_PAID_PAYMENT_FILTER_BROWSER_OK');
}finally{await browser.close();}
