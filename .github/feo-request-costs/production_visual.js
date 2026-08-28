const fs=require('fs');
const {chromium}=require('playwright');
const assert=(cond,msg)=>{if(!cond)throw new Error(msg)};
const url='https://plan-ex.ru/erpv2/company/feo/requests/costs';
const cookieName=process.env.QA_COOKIE_NAME, sid=process.env.QA_SESSION_ID;
if(!cookieName||!sid)throw new Error('QA session env missing');
(async()=>{
  fs.mkdirSync('artifacts',{recursive:true});
  const browser=await chromium.launch({headless:true});
  const context=await browser.newContext({viewport:{width:1920,height:1080},deviceScaleFactor:1});
  await context.addCookies([{name:cookieName,value:sid,domain:'plan-ex.ru',path:'/',secure:true,httpOnly:true,sameSite:'Lax'}]);
  const page=await context.newPage();
  const consoleErrors=[],pageErrors=[],assets={};
  page.on('console',m=>{if(m.type()==='error')consoleErrors.push(m.text());});
  page.on('pageerror',e=>pageErrors.push(String(e)));
  page.on('response',r=>{const u=r.url();if(u.includes('/assets/css/app.css'))assets.appCss=r.status();if(u.includes('/assets/css/erp-ui.css'))assets.erpCss=r.status();if(u.includes('/assets/js/feo-request-costs.js'))assets.costJs=r.status();});

  await page.route('**/company/feo/requests/costs/validate',async route=>route.fulfill({status:200,contentType:'application/json',body:JSON.stringify({success:true,valid:true,selected_count:3,selected_mass_kg:42560,duplicates:[],missing_request_ids:[],missing_placement_ids:[],already_priced_ids:[],already_priced_blocks:{}})}));
  await page.route('**/company/feo/requests/costs/analyze',async route=>route.fulfill({status:200,contentType:'application/json',body:JSON.stringify({success:true,token:'qa-create-token',preview:{selected_count:3,selected_mass_kg:42560,pricing_type:'rate_34_5',rate_per_kg:34.5,total_cost:1468320}})}));
  await page.route('**/company/feo/requests/costs/search?*',async route=>route.fulfill({status:200,contentType:'application/json',body:JSON.stringify({success:true,duplicates:[],results:[
    {zayavka_id:'302112',exists_in_feo:true,is_current:true,block:{block_id:999999,pricing_type:'rate_34_5',snapshot_request_count:3,snapshot_total_mass_kg:42560,total_cost:1468320,created_at:'2026-08-28 10:00:00'}},
    {zayavka_id:'306645',exists_in_feo:true,is_current:true,block:null},
    {zayavka_id:'308710',exists_in_feo:true,is_current:false,block:{block_id:888888,pricing_type:'agreement',snapshot_request_count:2,snapshot_total_mass_kg:20203,total_cost:800000,created_at:'2026-08-28 10:00:00'}}
  ]})}));
  await page.route('**/company/feo/requests/costs/blocks/999999/json',async route=>route.fulfill({status:200,contentType:'application/json',body:JSON.stringify({success:true,block:{id:999999,pricing_type:'rate_34_5',rate_per_kg:34.5,snapshot_request_count:3,snapshot_total_mass_kg:42560,total_cost:1468320,comment:'Visual QA — без записи в production',created_at:'2026-08-28 10:00:00',has_integrity_issue:false,requests:[
    {zayavka_id:'302112',snapshot_mass_kg:1250,current_mass_kg:1250,mass_changed:false,in_current_placement:true,missing_request:false,mismatch:false},
    {zayavka_id:'306645',snapshot_mass_kg:21000,current_mass_kg:21000,mass_changed:false,in_current_placement:true,missing_request:false,mismatch:false},
    {zayavka_id:'308710',snapshot_mass_kg:20310,current_mass_kg:20310,mass_changed:false,in_current_placement:true,missing_request:false,mismatch:false}
  ]}})}));
  await page.route('**/company/feo/requests/costs/blocks/999999/edit/validate',async route=>route.fulfill({status:200,contentType:'application/json',body:JSON.stringify({success:true,valid:true,selected_count:3,selected_mass_kg:30000,composition_changed:true,added_ids:['111111'],removed_ids:['306645'],duplicates:[],missing_placement_ids:[],already_priced_ids:[],already_priced_blocks:{}})}));
  await page.route('**/company/feo/requests/costs/blocks/999999/edit/analyze',async route=>route.fulfill({status:200,contentType:'application/json',body:JSON.stringify({success:true,token:'qa-edit-token',preview:{block_id:999999,selected_count:3,selected_mass_kg:30000,pricing_type:'rate_38',rate_per_kg:38,total_cost:1140000,old_total_mass_kg:42560,old_total_cost:1468320,composition_changed:true,added_ids:['111111'],removed_ids:['306645'],comment:'Visual QA'}})}));

  const response=await page.goto(url,{waitUntil:'networkidle',timeout:60000});
  assert(response&&response.status()===200,'production cost page HTTP != 200');
  assert(page.url().includes('/company/feo/requests/costs'),'redirected away from cost page');
  await page.waitForSelector('[data-cost-page-ready]',{state:'attached'});
  assert(await page.locator('.app').count()===1,'ERP .app shell missing');
  assert(await page.locator('.topbar').count()===1,'ERP topbar missing');
  assert(await page.locator('.sidebar').count()===1,'ERP sidebar missing');
  const bodyText=await page.locator('body').innerText();
  assert(!bodyText.includes('Fatal error'),'PHP fatal visible');
  const computed=await page.evaluate(()=>({appDisplay:getComputedStyle(document.querySelector('.app')).display,font:getComputedStyle(document.body).fontFamily,bodyWidth:document.body.scrollWidth,viewport:window.innerWidth,modals:Array.from(document.querySelectorAll('.modal-overlay')).map(m=>({id:m.id,opacity:getComputedStyle(m).opacity,pointer:getComputedStyle(m).pointerEvents,open:m.classList.contains('is-open'),hidden:m.getAttribute('aria-hidden')}))}));
  assert(computed.appDisplay==='grid','ERP app layout CSS not applied');
  assert(/IBM Plex Sans/i.test(computed.font),'ERP font CSS not applied: '+computed.font);
  assert(assets.appCss===200,'app.css not loaded 200');assert(assets.erpCss===200,'erp-ui.css not loaded 200');assert(assets.costJs===200,'feo-request-costs.js not loaded 200');
  assert(computed.modals.length>=3,'cost modals missing');
  computed.modals.forEach(m=>{assert(!m.open,`${m.id} unexpectedly open`);assert(m.hidden==='true',`${m.id} aria-hidden must be true`);assert(m.opacity==='0',`${m.id} CSS opacity must be 0`);assert(m.pointer==='none',`${m.id} pointer-events must be none`);});

  const actionsCount=await page.locator('thead th').filter({hasText:'Действия'}).count();
  assert(actionsCount===0,'Actions column must be absent');
  assert((await page.locator('.found-label').innerText()).includes('двойной щелчок'),'main table must explain double-click editing');
  const mainRows=page.locator('tbody tr[data-cost-block-id]');
  const mainRowCount=await mainRows.count();
  let mainRowDblclick='NO_PRODUCTION_ROWS';
  let mainWeightFormat='NO_PRODUCTION_ROWS';
  if(mainRowCount>0){
    const first=mainRows.first();
    const weight=(await first.locator('td').nth(3).innerText()).trim();
    assert(!/[\.,]\d/.test(weight),'main table weight must be whole kg: '+weight);
    mainWeightFormat=weight;
    await first.dblclick();
    await page.waitForSelector('#feo-cost-detail-modal.is-open');
    mainRowDblclick='PASS';
    await page.locator('#feo-cost-detail-modal [data-close-modal="feo-cost-detail-modal"]').click();
  }else{
    assert(bodyText.includes('Стоимость заявок ещё не добавлена.'),'empty state missing');
  }

  fs.writeFileSync('artifacts/production-body.txt',bodyText);
  fs.writeFileSync('artifacts/production-dom.html',await page.content());
  await page.screenshot({path:'artifacts/feo-request-costs-production.png',fullPage:true});

  await page.locator('[data-cost-open-create]').click();
  await page.waitForSelector('#feo-cost-create-modal.is-open');
  const mixed='Нужно забрать заявку 302112\n306645\tООО Ромашка\t1250\nТакже добавить 308710';
  await page.locator('[data-cost-ids]').evaluate((el,text)=>{const dt=new DataTransfer();dt.setData('text/plain',text);el.dispatchEvent(new ClipboardEvent('paste',{clipboardData:dt,bubbles:true,cancelable:true}));},mixed);
  await page.waitForTimeout(650);
  assert((await page.locator('[data-cost-count]').innerText()).trim()==='3','validated request count must be 3');
  const normalized=await page.locator('[data-cost-ids]').inputValue();
  ['302112','306645','308710'].forEach(id=>assert(normalized.includes(id),'mixed paste lost '+id));
  await page.locator('[data-cost-type-choice="rate_34_5"]').click();
  assert((await page.locator('[data-cost-calc-rate]').innerText()).includes('34,50'),'34.5 rate UI failed');
  assert((await page.locator('[data-cost-calc-total]').innerText()).replace(/\s/g,'').includes('1468320'),'34.5 total UI failed');
  await page.locator('[data-cost-analyze]').click();
  await page.waitForSelector('#feo-cost-confirm-modal.is-open');
  assert((await page.locator('[data-cost-confirm-title]').innerText()).includes('Создание стоимости'),'create confirmation missing');
  await page.screenshot({path:'artifacts/feo-request-costs-create-confirm.png',fullPage:true});
  await page.locator('#feo-cost-confirm-modal [data-close-modal="feo-cost-confirm-modal"]').click();

  const search=page.locator('[data-cost-search]');
  await search.fill('302112 306645 308710');
  await search.press('Enter');
  await page.waitForSelector('.feo-cost-search-table tbody tr');
  const searchRows=page.locator('.feo-cost-search-table tbody tr');
  assert(await searchRows.count()===3,'multi-request search must return one row per request');
  const searchText=await page.locator('[data-cost-search-results]').innerText();
  ['302112','306645','308710'].forEach(id=>assert(searchText.includes(id),'search result missing '+id));
  assert(searchText.includes('Стоимость не назначена'),'unpriced request state missing');
  assert(searchText.includes('В актуальном составе'),'placement status missing');
  assert(searchText.includes('Не в актуальном составе'),'non-current placement status missing');
  await page.locator('[data-cost-search-block-id="999999"]').dblclick();
  await page.waitForSelector('#feo-cost-detail-modal.is-open');
  assert((await page.locator('[data-cost-detail-title]').innerText()).includes('#999999'),'detail modal failed from search double-click');
  const editIds=page.locator('[data-cost-edit-ids]');
  assert(await editIds.count()===1,'editable composition textarea missing');
  const initialEditIds=await editIds.inputValue();
  ['302112','306645','308710'].forEach(id=>assert(initialEditIds.includes(id),'detail composition missing '+id));
  await page.screenshot({path:'artifacts/feo-request-costs-detail-edit.png',fullPage:true});

  await editIds.fill('302112\n308710\n111111');
  await page.waitForTimeout(650);
  assert((await page.locator('[data-cost-edit-count]').innerText()).trim()==='3','edited composition count must be 3');
  const editValidation=await page.locator('[data-cost-edit-validation]').innerText();
  assert(editValidation.includes('Добавлено: 1'),'edit add diagnostic missing');
  assert(editValidation.includes('удалено: 1'),'edit remove diagnostic missing');
  await page.locator('[data-cost-edit-type-choice="rate_38"]').click();
  assert((await page.locator('[data-cost-edit-calc-rate]').innerText()).includes('38,00'),'38 rate UI failed after composition edit');
  assert((await page.locator('[data-cost-edit-calc-total]').innerText()).replace(/\s/g,'').includes('1140000'),'38 recalculation after composition edit failed');
  await page.locator('[data-cost-edit-analyze]').click();
  await page.waitForSelector('#feo-cost-confirm-modal.is-open');
  const confirmText=await page.locator('[data-cost-confirm-copy]').innerText();
  assert((await page.locator('[data-cost-confirm-title]').innerText()).includes('Изменение стоимости'),'edit confirmation missing');
  assert(confirmText.includes('111111'),'confirmation must show added request');
  assert(confirmText.includes('306645'),'confirmation must show removed request');
  assert(confirmText.includes('42 560')&&confirmText.includes('30 000'),'confirmation must show old/new weight');
  await page.screenshot({path:'artifacts/feo-request-costs-edit-confirm.png',fullPage:true});

  assert(consoleErrors.length===0,'console errors: '+consoleErrors.join(' | '));
  assert(pageErrors.length===0,'page errors: '+pageErrors.join(' | '));
  const summary={url,sourceSha:'544c1db382df5b9e1e5ca23d1a6a09a82550eb4a',assets,computed:{appDisplay:computed.appDisplay,font:computed.font,bodyWidth:computed.bodyWidth,viewport:computed.viewport},actionsCount,mainRowCount,mainRowDblclick,mainWeightFormat,modalsInitiallyHidden:true,mixedPaste:['302112','306645','308710'],createModal:true,createConfirmation:true,multiSearchRows:3,searchDoubleClick:true,editableComposition:true,compositionAddRemove:true,rate38Recalculation:true,editConfirmation:true,consoleErrors,pageErrors,note:'Real production page/layout/assets were loaded. AJAX create/edit/search/detail flows were intercepted in-browser to avoid production business-data writes; real backend mutations/composition/search are covered by ERP PLANEX CI MySQL regression.'};
  fs.writeFileSync('artifacts/qa_summary.json',JSON.stringify(summary,null,2));
  console.log('FEO_REQUEST_COSTS_PRODUCTION_VISUAL=PASS');
  await browser.close();
})().catch(async e=>{console.error(e&&e.stack||e);process.exit(1);});
