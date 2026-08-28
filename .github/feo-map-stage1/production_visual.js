const fs=require('fs');
const {chromium}=require('playwright');
const assert=(cond,msg)=>{if(!cond)throw new Error(msg)};
const mapUrl='https://plan-ex.ru/erpv2/company/feo/requests/map';
const utilizerUrl='https://plan-ex.ru/erpv2/company/feo/utilizers';
const cookieName=process.env.QA_COOKIE_NAME;
const logistSid=process.env.QA_LOGIST_SESSION_ID, ownerSid=process.env.QA_OWNER_SESSION_ID, csrf=process.env.QA_LOGIST_CSRF_TOKEN;
if(!cookieName||!logistSid||!ownerSid||!csrf)throw new Error('QA session env missing');

async function verifyMap(page,{abortTiles=false}={}){
  if(abortTiles) await page.route('https://*.tile.openstreetmap.org/**',route=>route.abort());
  const consoleErrors=[],pageErrors=[],assets={};
  page.on('console',m=>{if(m.type()==='error')consoleErrors.push(m.text());});
  page.on('pageerror',e=>pageErrors.push(String(e)));
  page.on('response',r=>{
    const u=r.url();
    if(u.includes('/assets/css/feo-map.css')) assets.mapCss=r.status();
    if(u.includes('/assets/js/feo-map.js')) assets.mapJs=r.status();
    if(u.includes('leaflet@1.9.4')&&u.includes('leaflet.js')) assets.leafletJs=r.status();
    if(u.includes('leaflet@1.9.4')&&u.includes('leaflet.css')) assets.leafletCss=r.status();
  });
  const response=await page.goto(mapUrl,{waitUntil:'domcontentloaded',timeout:60000});
  assert(response&&response.status()===200,'map page HTTP != 200');
  assert(page.url().includes('/company/feo/requests/map'),'redirected away from map page');
  await page.waitForFunction(()=>document.querySelector('#feo-map-page')?.dataset.mapReady==='1',{timeout:60000});
  assert(await page.locator('.leaflet-container').count()===1,'Leaflet container missing');
  assert(assets.mapCss===200,'feo-map.css not loaded 200');
  assert(assets.mapJs===200,'feo-map.js not loaded 200');
  assert(assets.leafletJs===200,'Leaflet JS not loaded 200');
  assert(assets.leafletCss===200,'Leaflet CSS not loaded 200');
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
  await page.waitForFunction(()=>Number(document.querySelector('#feo-map-marker-count')?.textContent||0)>0);
  if(abortTiles){
    await page.waitForFunction(()=>!document.querySelector('#feo-map-runtime-warning')?.hidden,{timeout:15000});
    assert(await page.locator('.feo-marker').count()>0,'markers disappeared when background tiles failed');
  }
  const bodyText=await page.locator('body').innerText();
  assert(!bodyText.includes('Fatal error'),'PHP fatal visible');
  const roleResponse=await page.request.post('https://plan-ex.ru/erpv2/company/feo/utilizers',{
    headers:{'X-CSRF-TOKEN':csrf},
    form:{full_name:'QA MUST NOT WRITE',short_name:'QA-NO-WRITE',color_hex:'#000000'}
  });
  assert(roleResponse.status()===403,'logist mutation route must return 403');
  assert(pageErrors.length===0,'page errors: '+pageErrors.join(' | '));
  const relevantConsole=consoleErrors.filter(x=>!(abortTiles&&/tile\.openstreetmap\.org|ERR_FAILED/i.test(x)));
  assert(relevantConsole.length===0,'console errors: '+relevantConsole.join(' | '));
  return {requests,markers,domMarkers,assets,consoleErrors,pageErrors,abortTiles,popup};
}

async function verifyOwnerAdmin(browser){
  const context=await browser.newContext({viewport:{width:1440,height:1000},deviceScaleFactor:1});
  await context.addCookies([{name:cookieName,value:ownerSid,domain:'plan-ex.ru',path:'/',secure:true,httpOnly:true,sameSite:'Lax'}]);
  const page=await context.newPage();
  const consoleErrors=[],pageErrors=[];
  page.on('console',m=>{if(m.type()==='error')consoleErrors.push(m.text());});
  page.on('pageerror',e=>pageErrors.push(String(e)));
  const response=await page.goto(utilizerUrl,{waitUntil:'networkidle',timeout:60000});
  assert(response&&response.status()===200,'owner utilizer page HTTP != 200');
  await page.waitForSelector('[data-feo-utilizer-page-ready]');
  const body=(await page.locator('body').innerText()).trim();
  ['Master-data','Override имеет','rules/aliases'].forEach(term=>assert(!body.includes(term),'developer term remains visible: '+term));
  const active=await page.locator('.feo-domain-tabs a.is-active').innerText();
  assert(active.trim()==='Утилизаторы','Utilizers tab is not active');
  assert(await page.locator('input[type="color"][name="color_hex"]').count()>0,'color picker missing');
  assert(await page.locator('.feo-util-item .feo-field > span').count()>=8,'visible edit labels missing');
  assert(await page.locator('.feo-util-item').count()===8,'expected 8 seeded utilizer cards');
  await page.screenshot({path:'artifacts/feo-utilizers-owner-production.png',fullPage:true});
  await page.setViewportSize({width:800,height:1000});
  const firstLabel=page.locator('.feo-util-item .feo-field > span').first();
  assert(await firstLabel.isVisible(),'responsive edit label not visible');
  const itemBox=await page.locator('.feo-util-item').first().boundingBox();
  assert(itemBox&&itemBox.width<=800,'responsive utilizer card overflows viewport');
  await page.screenshot({path:'artifacts/feo-utilizers-owner-800px.png',fullPage:true});
  assert(pageErrors.length===0,'owner admin page errors: '+pageErrors.join(' | '));
  assert(consoleErrors.length===0,'owner admin console errors: '+consoleErrors.join(' | '));
  await context.close();
  return {utilizerCards:8,activeTab:active.trim(),responsiveLabel:true,colorPicker:true,consoleErrors,pageErrors};
}

(async()=>{
  fs.mkdirSync('artifacts',{recursive:true});
  const browser=await chromium.launch({headless:true});
  const context=await browser.newContext({viewport:{width:1920,height:1080},deviceScaleFactor:1});
  await context.addCookies([{name:cookieName,value:logistSid,domain:'plan-ex.ru',path:'/',secure:true,httpOnly:true,sameSite:'Lax'}]);
  const page=await context.newPage();
  const live=await verifyMap(page);
  fs.writeFileSync('artifacts/production-dom.html',await page.content());
  await page.screenshot({path:'artifacts/feo-map-stage1-production.png',fullPage:true});
  await page.close();

  const resiliencePage=await context.newPage();
  const resilience=await verifyMap(resiliencePage,{abortTiles:true});
  await resiliencePage.screenshot({path:'artifacts/feo-map-stage1-no-tiles.png',fullPage:true});
  await resiliencePage.close();
  await context.close();

  const ownerAdmin=await verifyOwnerAdmin(browser);
  const summary={mapUrl,utilizerUrl,sourceSha:process.env.SOURCE_SHA,live,resilience,ownerAdmin,note:'Real authenticated production Stage 1 QA. Logist map is read-only and mutation route is asserted 403; OSM tiles are blocked in resilience pass; owner utilizer admin is visually checked at desktop and 800px without mutations.'};
  fs.writeFileSync('artifacts/qa_summary.json',JSON.stringify(summary,null,2));
  console.log(`FEO_MAP_STAGE1_PRODUCTION_VISUAL=PASS requests=${live.requests} markers=${live.markers} utilizers=${ownerAdmin.utilizerCards}`);
  await browser.close();
})().catch(e=>{console.error(e&&e.stack||e);process.exit(1);});
