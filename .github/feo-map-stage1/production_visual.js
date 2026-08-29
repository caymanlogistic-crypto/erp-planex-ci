const fs=require('fs');
const {chromium}=require('playwright');
const assert=(v,m)=>{if(!v)throw new Error(m)};
const base='https://plan-ex.ru/erpv2';
const requestsUrl=base+'/company/feo/requests';
const placementUrl=base+'/company/feo/requests/map-placement';
const mapUrl=base+'/company/feo/requests/map';
const utilizerUrl=base+'/company/feo/utilizers';
const cookieName=process.env.QA_COOKIE_NAME;
const logistSid=process.env.QA_LOGIST_SESSION_ID,ownerSid=process.env.QA_OWNER_SESSION_ID,csrf=process.env.QA_LOGIST_CSRF_TOKEN;
if(!cookieName||!logistSid||!ownerSid||!csrf)throw new Error('QA session env missing');
const cookie=sid=>({name:cookieName,value:sid,domain:'plan-ex.ru',path:'/',secure:true,httpOnly:true,sameSite:'Lax'});

async function waitForPositiveText(locator,message,{attempts=60,delayMs=250}={}){
  for(let i=0;i<attempts;i++){
    const n=Number((await locator.innerText()).trim());
    if(n>0)return n;
    if(i<attempts-1)await locator.page().waitForTimeout(delayMs);
  }
  throw new Error(message);
}

async function shellCheck(context,url,label){
  const p=await context.newPage();
  try{
    const r=await p.goto(url,{waitUntil:'domcontentloaded',timeout:60000});assert(r&&r.status()===200,label+' HTTP != 200');
    assert(await p.locator('.feo-domain-tabs').count()===0,label+' redundant FEO tabs');
    assert(((await p.locator('.topbar-crumbs').innerText())||'').includes('Заявки ФЭО'),label+' breadcrumb missing');
    assert(await p.locator('.sidebar .nav-item.is-parent').filter({hasText:'Заявки ФЭО'}).count()===1,label+' left nav missing');
  }finally{await p.close();}
}

async function verifyMap(page,{abortTiles=false}={}){
  let blockedTileRequests=0;
  if(abortTiles)await page.route('**/company/feo/requests/map/tiles/**',route=>{blockedTileRequests++;return route.abort();});
  const consoleErrors=[],pageErrors=[],externalTileRequests=[],assets={tileStatuses:[]};
  page.on('console',m=>{if(m.type()==='error')consoleErrors.push(m.text());});
  page.on('pageerror',e=>pageErrors.push(String(e)));
  page.on('request',r=>{if(/https:\/\/[^/]*tile\.openstreetmap\.org\//i.test(r.url()))externalTileRequests.push(r.url());});
  page.on('response',r=>{
    const u=r.url();
    if(u.includes('/assets/css/feo-map.css'))assets.mapCss=r.status();
    if(u.includes('/assets/js/feo-map.js'))assets.mapJs=r.status();
    if(u.includes('/assets/third-party/leaflet/1.9.4/leaflet.js'))assets.leafletJs=r.status();
    if(u.includes('/assets/third-party/leaflet/1.9.4/leaflet.css'))assets.leafletCss=r.status();
    if(u.includes('/company/feo/requests/map/tiles/'))assets.tileStatuses.push(r.status());
  });
  const firstTilePromise=abortTiles?null:page.waitForResponse(r=>r.url().includes('/company/feo/requests/map/tiles/'),{timeout:60000});
  const r=await page.goto(mapUrl,{waitUntil:'domcontentloaded',timeout:60000});assert(r&&r.status()===200,'map HTTP != 200');
  await page.locator('#feo-map-page[data-map-ready="1"]').waitFor({state:'attached',timeout:60000});
  assert(assets.mapCss===200,'feo-map.css not loaded 200');assert(assets.mapJs===200,'feo-map.js not loaded 200');
  assert(assets.leafletJs===200,'Leaflet JS not loaded 200');assert(assets.leafletCss===200,'Leaflet CSS not loaded 200');
  assert(externalTileRequests.length===0,'browser must not request OpenStreetMap tiles directly');
  if(firstTilePromise){const tile=await firstTilePromise;assert(tile.status()===200,'same-origin map tile HTTP != 200');}
  const requests=Number((await page.locator('#feo-map-request-count').innerText()).trim());
  const markers=Number((await page.locator('#feo-map-marker-count').innerText()).trim());
  assert(requests>0,'zero map requests');assert(markers>0,'zero map markers');assert(await page.locator('.feo-marker').count()===markers,'DOM marker mismatch');
  assert(await page.locator('#feo-layer-list .feo-layer-row').count()>0,'map layers missing');
  assert(await page.locator('a[href$="/company/feo/utilizers"]').count()===0,'logist sees owner utilizer link');
  const ownerPage=await page.request.get(utilizerUrl);assert(ownerPage.status()===403,'logist utilizer page must be 403');
  const mutation=await page.request.post(utilizerUrl,{headers:{'X-CSRF-TOKEN':csrf},form:{full_name:'QA MUST NOT WRITE',short_name:'QA-NO-WRITE'}});assert(mutation.status()===403,'logist utilizer mutation must be 403');
  await page.locator('.feo-marker').first().click();await page.locator('.leaflet-popup').waitFor({state:'visible'});
  const popup=await page.locator('.leaflet-popup').innerText();assert(/Вес/.test(popup)&&/Утилизатор/.test(popup)&&/Состояние/.test(popup),'popup fields missing');
  const refreshPromise=page.waitForResponse(x=>x.url().includes('/company/feo/requests/map/data')&&x.request().method()==='GET');
  await page.locator('#feo-map-refresh').click();const refresh=await refreshPromise;assert(refresh.status()===200,'map refresh HTTP != 200');
  const refreshJson=await refresh.json();assert(refreshJson.success===true,'map refresh success != true');
  await waitForPositiveText(page.locator('#feo-map-marker-count'),'marker count did not recover after refresh');
  if(abortTiles){
    assert(blockedTileRequests>=3,`resilience QA blocked only ${blockedTileRequests} same-origin tile requests`);
    await page.locator('#feo-map-runtime-warning:not([hidden])').waitFor({state:'visible',timeout:15000});
    assert(await page.locator('.feo-marker').count()>0,'markers disappeared when background tiles failed');
  }
  const relevantConsole=consoleErrors.filter(x=>!(abortTiles&&/requests\/map\/tiles\/|ERR_FAILED/i.test(x)));
  assert(pageErrors.length===0,'map page errors: '+pageErrors.join(' | '));assert(relevantConsole.length===0,'map console errors: '+relevantConsole.join(' | '));
  return{requests,markers,blockedTileRequests,externalTileRequests,assets};
}

async function ownerCheck(browser){
  const c=await browser.newContext({viewport:{width:1440,height:1000}});await c.addCookies([cookie(ownerSid)]);
  for(const [u,l] of [[requestsUrl,'requests'],[placementUrl,'placement'],[mapUrl,'map']])await shellCheck(c,u,l);
  const p=await c.newPage();const errors=[];p.on('pageerror',e=>errors.push(String(e)));
  const r=await p.goto(utilizerUrl,{waitUntil:'networkidle',timeout:60000});assert(r&&r.status()===200,'owner utilizer HTTP != 200');
  await p.locator('[data-feo-utilizer-page-ready]').waitFor({state:'attached'});
  const rows=p.locator('[data-feo-utilizer-grid] tbody tr[data-feo-utilizer-id]');assert(await rows.count()===8,'expected 8 utilizer rows');
  assert(await p.locator('[data-feo-utilizer-rules]').count()===0,'legacy rules registry visible');assert(await p.locator('[data-feo-utilizer-overrides]').count()===0,'legacy overrides registry visible');
  const problem=p.locator('[data-feo-utilizer-problem]');let unresolved=0;
  if(await problem.count()){unresolved=Number(await problem.getAttribute('data-problem-count'));assert(unresolved>0,'problem panel count invalid');}
  const utilizerNav=p.locator('[data-feo-utilizers-nav]');assert(await utilizerNav.count()===1,'owner utilizer nav missing');
  const badge=utilizerNav.locator('[data-feo-utilizer-badge]');
  if(unresolved>0){assert(await badge.count()===1,'unresolved badge missing');const txt=(await badge.innerText()).trim();assert(txt===(unresolved>999?'999+':String(unresolved)),'badge count mismatch');}else assert(await badge.count()===0,'zero-problem badge must be absent');
  const search=p.locator('[data-feo-utilizer-grid] .toolbar-search');const firstName=(await rows.first().locator('.cell-main').innerText()).trim();await search.fill(firstName);await p.waitForTimeout(80);assert(await p.locator('[data-feo-utilizer-grid] tbody tr[data-feo-utilizer-id]:visible').count()===1,'registry search failed');await search.fill('');
  await rows.first().dblclick();const modal=p.locator('#feo-utilizer-view-modal.is-open');await modal.waitFor({state:'visible',timeout:15000});await modal.locator('[data-feo-utilizer-edit-btn]').waitFor({state:'visible',timeout:15000});
  assert(((await modal.textContent())||'').includes('Синонимы'),'view synonyms missing');await modal.locator('[data-feo-utilizer-edit-btn]').click();const edit=modal.locator('[data-feo-utilizer-edit-form]');await edit.waitFor({state:'visible'});assert(await edit.locator('[name="aliases"]').count()===1,'edit aliases missing');await modal.locator('[data-feo-utilizer-cancel-edit-btn]').click();await modal.locator('[data-feo-utilizer-edit-btn]').waitFor({state:'visible'});await modal.locator('[data-feo-utilizer-close-btn]').click();
  await p.getByRole('button',{name:'Добавить утилизатора'}).click();const create=p.locator('#feo-utilizer-create-modal.is-open');await create.waitFor({state:'visible'});assert(await create.locator('[name="aliases"]').count()===1,'create aliases missing');await create.locator('.modal-close').click();
  await rows.first().dblclick();await modal.waitFor({state:'visible'});await modal.locator('[data-feo-utilizer-delete-btn]').click();const confirm=p.locator('#feo-utilizer-delete-confirm-modal.is-open');await confirm.waitFor({state:'visible'});assert(await confirm.locator('[data-confirm-input]').count()===1,'typed delete confirm missing');assert(await confirm.locator('[data-confirm-action]').isDisabled(),'delete must be disabled before confirmation');await confirm.locator('[data-confirm-cancel]').click();await modal.locator('[data-feo-utilizer-close-btn]').click();
  await p.screenshot({path:'artifacts/feo-utilizers-owner-production.png',fullPage:true});assert(errors.length===0,'owner page errors: '+errors.join(' | '));await c.close();return{utilizerRows:8,unresolved,aliases:true,rules:false,overrides:false};
}

(async()=>{
  fs.mkdirSync('artifacts',{recursive:true});const browser=await chromium.launch({headless:true});
  const liveContext=await browser.newContext({viewport:{width:1920,height:1080}});await liveContext.addCookies([cookie(logistSid)]);const livePage=await liveContext.newPage();const live=await verifyMap(livePage);fs.writeFileSync('artifacts/production-dom.html',await livePage.content());await livePage.screenshot({path:'artifacts/feo-map-stage1-production.png',fullPage:true});await liveContext.close();
  const resilienceContext=await browser.newContext({viewport:{width:1920,height:1080}});await resilienceContext.addCookies([cookie(logistSid)]);const resiliencePage=await resilienceContext.newPage();const resilience=await verifyMap(resiliencePage,{abortTiles:true});await resiliencePage.screenshot({path:'artifacts/feo-map-stage1-no-tiles.png',fullPage:true});await resilienceContext.close();
  const owner=await ownerCheck(browser);const summary={sourceSha:process.env.SOURCE_SHA,live,resilience,owner,note:'Authenticated read-only production QA for simplified FEO utilizer matching. Map tile resilience remains covered. No business mutation submitted.'};fs.writeFileSync('artifacts/qa_summary.json',JSON.stringify(summary,null,2));console.log(`FEO_MAP_STAGE1_PRODUCTION_VISUAL=PASS requests=${live.requests} markers=${live.markers} utilizers=${owner.utilizerRows} unresolved=${owner.unresolved}`);await browser.close();
})().catch(e=>{console.error(e&&e.stack||e);process.exit(1);});
