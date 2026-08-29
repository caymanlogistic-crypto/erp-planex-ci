const fs=require('fs');
const {chromium}=require('playwright');
const assert=(cond,msg)=>{if(!cond)throw new Error(msg)};
const mapUrl='https://plan-ex.ru/erpv2/company/feo/requests/map';
const utilizerUrl='https://plan-ex.ru/erpv2/company/feo/utilizers';
const cookieName=process.env.QA_COOKIE_NAME;
const logistSid=process.env.QA_LOGIST_SESSION_ID, ownerSid=process.env.QA_OWNER_SESSION_ID, csrf=process.env.QA_LOGIST_CSRF_TOKEN;
if(!cookieName||!logistSid||!ownerSid||!csrf)throw new Error('QA session env missing');

const logistCookie={name:cookieName,value:logistSid,domain:'plan-ex.ru',path:'/',secure:true,httpOnly:true,sameSite:'Lax'};

async function waitForPositiveText(locator,message,{attempts=60,delayMs=250}={}){
  for(let attempt=0;attempt<attempts;attempt++){
    const value=Number((await locator.innerText()).trim());
    if(value>0)return value;
    if(attempt<attempts-1)await locator.page().waitForTimeout(delayMs);
  }
  throw new Error(message);
}

async function verifyMap(page,{abortTiles=false}={}){
  let blockedTileRequests=0;
  if(abortTiles) await page.route('**/company/feo/requests/map/tiles/**',route=>{
    blockedTileRequests+=1;
    return route.abort();
  });
  const consoleErrors=[],pageErrors=[],assets={tileStatuses:[]},externalTileRequests=[];
  page.on('console',m=>{if(m.type()==='error')consoleErrors.push(m.text());});
  page.on('pageerror',e=>pageErrors.push(String(e)));
  page.on('request',r=>{if(/https:\/\/[^/]*tile\.openstreetmap\.org\//i.test(r.url()))externalTileRequests.push(r.url());});
  page.on('response',r=>{
    const u=r.url();
    if(u.includes('/assets/css/feo-map.css')) assets.mapCss=r.status();
    if(u.includes('/assets/js/feo-map.js')) assets.mapJs=r.status();
    if(u.includes('/assets/third-party/leaflet/1.9.4/leaflet.js')) assets.leafletJs=r.status();
    if(u.includes('/assets/third-party/leaflet/1.9.4/leaflet.css')) assets.leafletCss=r.status();
    if(u.includes('/company/feo/requests/map/tiles/')) assets.tileStatuses.push(r.status());
  });
  const firstTilePromise=abortTiles?null:page.waitForResponse(r=>r.url().includes('/company/feo/requests/map/tiles/'),{timeout:60000});
  const response=await page.goto(mapUrl,{waitUntil:'domcontentloaded',timeout:60000});
  assert(response&&response.status()===200,'map page HTTP != 200');
  assert(page.url().includes('/company/feo/requests/map'),'redirected away from map page');
  await page.locator('#feo-map-page[data-map-ready="1"]').waitFor({state:'attached',timeout:60000});
  assert(await page.locator('.leaflet-container').count()===1,'Leaflet container missing');
  assert(assets.mapCss===200,'feo-map.css not loaded 200');
  assert(assets.mapJs===200,'feo-map.js not loaded 200');
  assert(assets.leafletJs===200,'Leaflet JS not loaded 200');
  assert(assets.leafletCss===200,'Leaflet CSS not loaded 200');
  assert(externalTileRequests.length===0,'browser must not request OpenStreetMap tiles directly under production CSP');
  if(firstTilePromise){
    const firstTile=await firstTilePromise;
    assert(firstTile.status()===200,'same-origin map tile HTTP != 200');
  }
  const requests=Number((await page.locator('#feo-map-request-count').innerText()).trim());
  const markers=Number((await page.locator('#feo-map-marker-count').innerText()).trim());
  assert(requests>0,'production map has zero request count');
  assert(markers>0,'production map has zero logical markers');
  const domMarkers=await page.locator('.feo-marker').count();
  assert(domMarkers===markers,`DOM marker count ${domMarkers} != summary ${markers}`);
  assert(await page.locator('#feo-layer-list .feo-layer-row').count()>0,'map layers missing');
  assert(await page.locator('a[href$="/company/feo/utilizers"]').count()===0,'logist must not see Utilizers action');
  await page.locator('.feo-marker').first().click();
  await page.locator('.leaflet-popup').waitFor({state:'visible'});
  const popup=(await page.locator('.leaflet-popup').innerText()).trim();
  assert(/Вес/.test(popup)&&/Утилизатор/.test(popup)&&/Состояние/.test(popup),'marker popup lacks required fields');
  const nonMaster=page.locator('#feo-layer-list input[type=checkbox]').nth(1);
  if(await nonMaster.count()){
    const before=await page.locator('.feo-marker').count();
    await nonMaster.uncheck();
    const afterOff=await page.locator('.feo-marker').count();
    assert(afterOff<before,'utilizer layer toggle did not hide markers');
    await nonMaster.check();
    assert(await page.locator('.feo-marker').count()===before,'utilizer layer toggle did not restore markers');
  }
  const refreshPromise=page.waitForResponse(r=>r.url().includes('/company/feo/requests/map/data')&&r.request().method()==='GET');
  await page.locator('#feo-map-refresh').click();
  const refresh=await refreshPromise;
  assert(refresh.status()===200,'map refresh endpoint HTTP != 200');
  const refreshJson=await refresh.json();
  assert(refreshJson.success===true,'map refresh success != true');
  await waitForPositiveText(page.locator('#feo-map-marker-count'),'marker count did not recover after refresh');
  if(abortTiles){
    assert(blockedTileRequests>=3,`resilience QA blocked only ${blockedTileRequests} same-origin tile requests`);
    await page.locator('#feo-map-runtime-warning:not([hidden])').waitFor({state:'visible',timeout:15000});
    assert(await page.locator('.feo-marker').count()>0,'markers disappeared when background tiles failed');
  }
  const bodyText=await page.locator('body').innerText();
  assert(!bodyText.includes('Fatal error'),'PHP fatal visible');
  const ownerOnlyPage=await page.request.get(utilizerUrl);
  assert(ownerOnlyPage.status()===403,'logist utilizer registry must return 403');
  const roleResponse=await page.request.post('https://plan-ex.ru/erpv2/company/feo/utilizers',{
    headers:{'X-CSRF-TOKEN':csrf},
    form:{full_name:'QA MUST NOT WRITE',short_name:'QA-NO-WRITE',color_hex:'#000000'}
  });
  assert(roleResponse.status()===403,'logist mutation route must return 403');
  assert(pageErrors.length===0,'page errors: '+pageErrors.join(' | '));
  const relevantConsole=consoleErrors.filter(x=>!(abortTiles&&/requests\/map\/tiles\/|ERR_FAILED/i.test(x)));
  assert(relevantConsole.length===0,'console errors: '+relevantConsole.join(' | '));
  return {requests,markers,domMarkers,assets,externalTileRequests,blockedTileRequests,consoleErrors,pageErrors,abortTiles,popup};
}

async function verifyOwnerAdmin(browser){
  const context=await browser.newContext({viewport:{width:1440,height:1000},deviceScaleFactor:1});
  await context.addCookies([{name:cookieName,value:ownerSid,domain:'plan-ex.ru',path:'/',secure:true,httpOnly:true,sameSite:'Lax'}]);
  const page=await context.newPage();
  const consoleErrors=[],pageErrors=[],assets={};
  page.on('console',m=>{if(m.type()==='error')consoleErrors.push(m.text());});
  page.on('pageerror',e=>pageErrors.push(String(e)));
  page.on('response',r=>{
    const u=r.url();
    if(u.includes('/assets/css/feo-utilizers.css'))assets.utilizerCss=r.status();
    if(u.includes('/assets/js/feo-utilizers.js'))assets.utilizerJs=r.status();
  });
  const response=await page.goto(utilizerUrl,{waitUntil:'networkidle',timeout:60000});
  assert(response&&response.status()===200,'owner utilizer page HTTP != 200');
  await page.locator('[data-feo-utilizer-page-ready]').waitFor({state:'attached',timeout:60000});
  assert(assets.utilizerCss===200,'feo-utilizers.css not loaded 200');
  assert(assets.utilizerJs===200,'feo-utilizers.js not loaded 200');
  const body=(await page.locator('body').innerText()).trim();
  ['Master-data','Override имеет','rules/aliases'].forEach(term=>assert(!body.includes(term),'developer term remains visible: '+term));
  const active=await page.locator('.feo-domain-tabs a.is-active').innerText();
  assert(active.trim()==='Утилизаторы','Utilizers tab is not active');
  assert(await page.locator('[data-feo-utilizer-grid].table-card--standard').count()===1,'standard utilizer registry missing');
  assert(await page.locator('.feo-util-item').count()===0,'legacy utilizer cards remain visible');
  const rows=page.locator('[data-feo-utilizer-grid] tbody tr[data-feo-utilizer-id]');
  assert(await rows.count()===8,'expected 8 seeded utilizer rows');

  const search=page.locator('[data-feo-utilizer-grid] .toolbar-search');
  const firstName=(await rows.first().locator('.cell-main').innerText()).trim();
  assert(firstName.length>0,'first utilizer short name missing');
  await search.fill(firstName);
  await page.waitForTimeout(100);
  const filteredRows=page.locator('[data-feo-utilizer-grid] tbody tr[data-feo-utilizer-id]:visible');
  assert(await filteredRows.count()===1,'utilizer registry search did not narrow by unique short name');
  assert((await filteredRows.first().locator('.cell-main').innerText()).trim()===firstName,'utilizer registry search returned a different row');
  await search.fill('');
  await page.waitForTimeout(100);
  assert(await page.locator('[data-feo-utilizer-grid] tbody tr[data-feo-utilizer-id]:visible').count()===8,'utilizer registry search did not restore all rows');

  await rows.first().dblclick();
  const viewModal=page.locator('#feo-utilizer-view-modal.is-open');
  await viewModal.waitFor({state:'visible',timeout:15000});
  await viewModal.locator('[data-feo-utilizer-edit-btn]').waitFor({state:'visible',timeout:15000});
  const viewText=((await viewModal.textContent())||'').trim();
  ['Полное название','Адрес','Координаты','ФККО / классификация','ADR','Цвет','Статус','Порядок','ID'].forEach(label=>assert(viewText.includes(label),'view modal missing field '+label));
  await viewModal.locator('[data-feo-utilizer-edit-btn]').click();
  const editForm=viewModal.locator('[data-feo-utilizer-edit-form]');
  await editForm.waitFor({state:'visible',timeout:15000});
  for(const name of ['full_name','short_name','address','latitude','longitude','fkko_classification','color_hex','adr','is_active','sort_order']){
    assert(await editForm.locator(`[name="${name}"]`).count()===1,'edit modal missing '+name);
  }
  await viewModal.locator('[data-feo-utilizer-cancel-edit-btn]').click();
  await viewModal.locator('[data-feo-utilizer-edit-btn]').waitFor({state:'visible',timeout:15000});
  await viewModal.locator('[data-feo-utilizer-delete-btn]').click();
  const deleteConfirm=page.locator('#feo-utilizer-delete-confirm-modal.is-open');
  await deleteConfirm.waitFor({state:'visible',timeout:5000});
  assert(await deleteConfirm.locator('[data-confirm-input]').count()===1,'typed delete confirmation input missing');
  assert(await deleteConfirm.locator('[data-confirm-action]').isDisabled(),'delete action must stay disabled before typed confirmation');
  await deleteConfirm.locator('[data-confirm-cancel]').click();
  assert(await page.locator('#feo-utilizer-delete-confirm-modal.is-open').count()===0,'delete confirmation did not close without mutation');
  await viewModal.locator('[data-feo-utilizer-close-btn]').click();

  await page.getByRole('button',{name:'Добавить утилизатора'}).click();
  const createModal=page.locator('#feo-utilizer-create-modal.is-open');
  await createModal.waitFor({state:'visible'});
  for(const name of ['full_name','short_name','address','latitude','longitude','fkko_classification','color_hex','adr','is_active','sort_order']){
    assert(await createModal.locator(`[name="${name}"]`).count()===1,'create modal missing '+name);
  }
  await createModal.locator('.modal-close').click();

  assert(await page.locator('[data-feo-utilizer-rules].table-card--standard').count()===1,'rules standard registry missing');
  await page.getByRole('button',{name:'Добавить правило'}).click();
  const ruleModal=page.locator('#feo-utilizer-rule-modal.is-open');
  await ruleModal.waitFor({state:'visible'});
  for(const name of ['utilizer_id','source_field','match_type','pattern','priority','is_active'])assert(await ruleModal.locator(`[name="${name}"]`).count()===1,'rule modal missing '+name);
  await ruleModal.locator('.modal-close').click();

  assert(await page.locator('[data-feo-utilizer-overrides].table-card--standard').count()===1,'overrides standard registry missing');
  await page.getByRole('button',{name:'Добавить назначение'}).click();
  const overrideModal=page.locator('#feo-utilizer-override-modal.is-open');
  await overrideModal.waitFor({state:'visible'});
  assert(await overrideModal.locator('[name="zayavka_id"]').count()===1,'override request field missing');
  assert(await overrideModal.locator('[name="utilizer_id"]').count()===1,'override utilizer field missing');
  await overrideModal.locator('.modal-close').click();

  await page.screenshot({path:'artifacts/feo-utilizers-owner-production.png',fullPage:true});
  await page.setViewportSize({width:800,height:1000});
  const scrollBox=await page.locator('[data-feo-utilizer-grid] .table-scroll').boundingBox();
  assert(scrollBox&&scrollBox.width<=800,'responsive utilizer registry overflows viewport shell');
  await page.screenshot({path:'artifacts/feo-utilizers-owner-800px.png',fullPage:true});
  assert(pageErrors.length===0,'owner admin page errors: '+pageErrors.join(' | '));
  assert(consoleErrors.length===0,'owner admin console errors: '+consoleErrors.join(' | '));
  await context.close();
  return {utilizerRows:8,activeTab:active.trim(),search:true,doubleClick:true,viewEdit:true,createModal:true,rules:true,overrides:true,typedDeleteConfirm:true,assets,consoleErrors,pageErrors};
}

(async()=>{
  fs.mkdirSync('artifacts',{recursive:true});
  const browser=await chromium.launch({headless:true});

  const liveContext=await browser.newContext({viewport:{width:1920,height:1080},deviceScaleFactor:1});
  await liveContext.addCookies([logistCookie]);
  const page=await liveContext.newPage();
  const live=await verifyMap(page);
  fs.writeFileSync('artifacts/production-dom.html',await page.content());
  await page.screenshot({path:'artifacts/feo-map-stage1-production.png',fullPage:true});
  await liveContext.close();

  // Use a fresh browser context so the resilience pass cannot reuse successful
  // basemap responses from Chromium's HTTP cache. Every tile must hit the route
  // interceptor and exercise the real Leaflet tileerror path.
  const resilienceContext=await browser.newContext({viewport:{width:1920,height:1080},deviceScaleFactor:1});
  await resilienceContext.addCookies([logistCookie]);
  const resiliencePage=await resilienceContext.newPage();
  const resilience=await verifyMap(resiliencePage,{abortTiles:true});
  await resiliencePage.screenshot({path:'artifacts/feo-map-stage1-no-tiles.png',fullPage:true});
  await resilienceContext.close();

  const ownerAdmin=await verifyOwnerAdmin(browser);
  const summary={mapUrl,utilizerUrl,sourceSha:process.env.SOURCE_SHA,live,resilience,ownerAdmin,note:'Real authenticated production Stage 1 QA. Map runtime remains read-only and unchanged by the utilizer UX refactor. Logist map access and owner-only utilizer RBAC are checked; owner utilizer registry/modal/search/rules/overrides are exercised without submitting business-data mutations.'};
  fs.writeFileSync('artifacts/qa_summary.json',JSON.stringify(summary,null,2));
  console.log(`FEO_MAP_STAGE1_PRODUCTION_VISUAL=PASS requests=${live.requests} markers=${live.markers} utilizers=${ownerAdmin.utilizerRows}`);
  await browser.close();
})().catch(e=>{console.error(e&&e.stack||e);process.exit(1);});