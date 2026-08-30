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

async function shellCheck(context,url,label){
  const p=await context.newPage();
  try{
    const r=await p.goto(url,{waitUntil:'domcontentloaded',timeout:60000});assert(r&&r.status()===200,label+' HTTP != 200');
    assert(await p.locator('.feo-domain-tabs').count()===0,label+' redundant FEO tabs');
    assert(((await p.locator('.topbar-crumbs').innerText())||'').includes('Заявки ФЭО'),label+' breadcrumb missing');
    assert(await p.locator('.sidebar .nav-item.is-parent').filter({hasText:'Заявки ФЭО'}).count()===1,label+' left nav missing');
  }finally{await p.close();}
}

async function mapSnapshot(page){
  return page.evaluate(()=>window.__FEO_MAP_STAGE1__&&window.__FEO_MAP_STAGE1__.snapshot());
}

async function assertMapFillsContent(page,message){
  const metrics=await page.evaluate(()=>{
    const content=document.querySelector('main.content');
    const shell=content&&content.querySelector(':scope > .workspace-shell-1690');
    const workspace=document.getElementById('feo-map-workspace');
    const canvas=document.getElementById('feo-map');
    if(!content||!shell||!workspace||!canvas)return null;
    const box=el=>{const r=el.getBoundingClientRect();return{x:r.x,y:r.y,width:r.width,height:r.height}};
    const wsStyle=getComputedStyle(workspace),canvasStyle=getComputedStyle(canvas);
    return{content:box(content),shell:box(shell),workspace:box(workspace),canvas:box(canvas),workspaceRadius:wsStyle.borderRadius,canvasRadius:canvasStyle.borderRadius};
  });
  assert(metrics,'map fullscreen metrics missing');
  const same=(a,b)=>Math.abs(a.x-b.x)<=1&&Math.abs(a.y-b.y)<=1&&Math.abs(a.width-b.width)<=1&&Math.abs(a.height-b.height)<=1;
  assert(same(metrics.content,metrics.shell)&&same(metrics.shell,metrics.workspace)&&same(metrics.workspace,metrics.canvas),message+': '+JSON.stringify(metrics));
  assert(parseFloat(metrics.workspaceRadius||'0')===0&&parseFloat(metrics.canvasRadius||'0')===0,'map corners are not square');
}

function legacyMarkerOverlays(page){
  return page.locator('#feo-map ymaps[style*="request-marker-v2-clip"]');
}

async function markerBackgroundSources(locator){
  return locator.evaluateAll(nodes=>nodes.map(node=>{
    const bg=getComputedStyle(node).backgroundImage||'';
    try{return decodeURIComponent(bg);}catch{return bg;}
  }));
}

async function verifyMap(page){
  const consoleErrors=[],pageErrors=[],assets={yandexApiStatuses:[],mapCss:null,mapJs:null},forbidden=[];
  page.on('console',m=>{if(m.type()==='error')consoleErrors.push(m.text());});
  page.on('pageerror',e=>pageErrors.push(String(e)));
  page.on('request',r=>{
    const u=r.url();
    if(/leaflet/i.test(u)||/tile\.openstreetmap\.org/i.test(u)||/\/company\/feo\/requests\/map\/tiles\//i.test(u))forbidden.push(u);
  });
  page.on('response',r=>{
    const u=r.url();
    if(u.includes('/assets/css/feo-map.css'))assets.mapCss=r.status();
    if(u.includes('/assets/js/feo-map.js'))assets.mapJs=r.status();
    if(u.startsWith('https://api-maps.yandex.ru/2.1.77/'))assets.yandexApiStatuses.push(r.status());
  });

  const r=await page.goto(mapUrl,{waitUntil:'domcontentloaded',timeout:60000});assert(r&&r.status()===200,'map HTTP != 200');
  await page.locator('#feo-map-page[data-map-ready="1"][data-map-engine="yandex"]').waitFor({state:'attached',timeout:60000});
  assert(assets.mapCss===200,'feo-map.css not loaded 200');assert(assets.mapJs===200,'feo-map.js not loaded 200');
  assert(assets.yandexApiStatuses.some(x=>x===200),'Yandex Maps API 2.1 not loaded 200');
  assert(forbidden.length===0,'Leaflet/OSM runtime request detected: '+forbidden.join(' | '));
  assert(await page.locator('#feo-map [class*="ymaps-2-1-"]').count()>0,'Yandex map DOM missing');
  assert(await page.locator('.feo-map-head,.feo-map-summary,#feo-map-refresh').count()===0,'removed map header/summary/refresh UI returned');

  const payload=JSON.parse((await page.locator('#feo-map-data').textContent())||'{}');
  const requests=Number(payload.diagnostics&&payload.diagnostics.marker_request_count||0);
  const markers=Number(payload.diagnostics&&payload.diagnostics.marker_count||0);
  assert(requests>0,'zero map requests');assert(markers>0,'zero map markers');
  let snap=await mapSnapshot(page);assert(snap&&snap.engine==='yandex','diagnostic snapshot engine != yandex');
  assert(snap.markerRenderer==='legacy-request-marker-v2','legacy marker renderer not active');
  assert(JSON.stringify(snap.markerBody)==='[50,40]','marker body geometry drifted');
  assert(JSON.stringify(snap.markerImage)==='[66,56]','marker image geometry drifted');
  assert(snap.stackStep===36&&snap.adrExtraGap===6,'stack geometry drifted');
  assert(snap.markers.length===markers,'snapshot marker count mismatch');
  const deployedJsResponse=await page.request.get(base+'/assets/js/feo-map.js');
  assert(deployedJsResponse.status()===200,'deployed feo-map.js contract probe HTTP != 200');
  const deployedJs=await deployedJsResponse.text();
  const liveAdr=snap.markers.filter(x=>x.visible&&x.adr);
  const stacked=snap.markers.filter(x=>x.visible&&x.shiftY>0);
  const adrEvidence=liveAdr.length>0?'live-production-marker':'deployed-runtime-contract';
  const stackEvidence=stacked.length>0?'live-production-stack':'deployed-runtime-contract';
  if(liveAdr.length===0){
    assert(deployedJs.includes('if (markerData.adr)'),'deployed runtime lost ADR rendering branch');
    assert(deployedJs.includes('x="34.5" y="-5" width="14.5" height="8"'),'deployed runtime ADR badge geometry drifted');
    assert(deployedJs.includes('>ADR</text>'),'deployed runtime ADR label missing');
  }
  if(stacked.length===0){
    assert(deployedJs.includes('REQUEST_MARKER_STACK_STEP = 36'),'deployed runtime stack step drifted');
    assert(deployedJs.includes('REQUEST_MARKER_ADR_EXTRA_GAP = 6'),'deployed runtime ADR stack gap drifted');
    assert(deployedJs.includes('visibleByCoordinate'),'deployed runtime coordinate stack grouping missing');
    assert(deployedJs.includes('record.hideTriangle = position > 0'),'deployed runtime upper stack tail rule missing');
    assert(deployedJs.includes('markerOffsetY(record.shiftY)'),'deployed runtime stack offset application missing');
    assert(!deployedJs.includes('Math.cos(')&&!deployedJs.includes('Math.sin('),'deployed runtime circular spiderfy detected');
  }
  for(const item of stacked){assert(item.hideTriangle===true,'upper stack marker kept tail');assert(Array.isArray(item.offset)&&item.offset[0]===-33&&item.offset[1]===-47-item.shiftY,'stack offset drifted');}

  await assertMapFillsContent(page,'map does not fill the full ERP content viewport');
  const panelMetrics=await page.locator('#feo-map-layer-panel').evaluate(el=>{
    const head=el.querySelector('.feo-map-layer-head'),row=el.querySelector('.feo-layer-row');
    const s=getComputedStyle(el);
    return{width:el.getBoundingClientRect().width,headHeight:head?head.getBoundingClientRect().height:999,rowHeight:row?row.getBoundingClientRect().height:999,radius:s.borderRadius};
  });
  assert(panelMetrics.width<=220&&panelMetrics.headHeight<=30&&panelMetrics.rowHeight<=24&&parseFloat(panelMetrics.radius||'0')<=2.1,'layer panel is not ERP-compact: '+JSON.stringify(panelMetrics));

  assert(await page.locator('#feo-layer-list .feo-layer-row').count()>0,'map layers missing');
  assert(await page.locator('#feo-layer-list [data-layer-group="utilizers"] .feo-layer-row').count()>0,'utilizer layer group missing');
  const beforeVisible=snap.markers.filter(x=>x.visible).length;
  const utilizerToggle=page.locator('#feo-layer-list input[data-layer-key^="utilizer:"]').first();
  await utilizerToggle.uncheck();await page.waitForTimeout(120);
  snap=await mapSnapshot(page);assert(snap.markers.filter(x=>x.visible).length<beforeVisible,'utilizer layer hide did not affect visibility');
  await utilizerToggle.check();await page.waitForTimeout(120);

  const markerOverlays=legacyMarkerOverlays(page);
  assert(await markerOverlays.count()>0,'legacy SVG marker overlay missing from Yandex DOM');
  const firstMarker=markerOverlays.first();
  await firstMarker.click({force:true});await page.waitForTimeout(100);
  snap=await mapSnapshot(page);assert(snap.markers.some(x=>x.selected),'marker click did not activate selected state');
  const selectedSources=await markerBackgroundSources(markerOverlays);
  assert(selectedSources.some(src=>src.includes('#1976ff')&&src.includes('stroke-width="8.5"')),'selected blue glow not rendered');
  await page.locator('.feo-yandex-balloon').waitFor({state:'visible',timeout:15000});
  const balloon=await page.locator('.feo-yandex-balloon').innerText();assert(/Вес|Общий вес/.test(balloon)&&/Утилизатор/.test(balloon),'Yandex balloon fields missing');
  const markerSources=await markerBackgroundSources(markerOverlays);
  if(liveAdr.length>0)assert(markerSources.some(src=>src.includes('>ADR<')),'ADR badge not rendered in live legacy SVG marker');

  await page.screenshot({path:'artifacts/feo-map-stage1-selected-balloon.png',fullPage:true});
  await page.locator('#feo-map-layer-toggle').click();assert(await page.locator('#feo-map-layer-panel').evaluate(el=>el.classList.contains('is-collapsed')),'layer panel did not collapse');
  await page.locator('#feo-map-layer-toggle').click();assert(!(await page.locator('#feo-map-layer-panel').evaluate(el=>el.classList.contains('is-collapsed'))),'layer panel did not expand');

  const dataResponse=await page.request.get(base+'/company/feo/requests/map/data',{headers:{Accept:'application/json'}});
  assert(dataResponse.status()===200,'map data GET HTTP != 200');
  const dataJson=await dataResponse.json();assert(dataJson.success===true,'map data GET success != true');

  await page.setViewportSize({width:1440,height:900});await page.waitForTimeout(120);
  await assertMapFillsContent(page,'Yandex map resize drifted from full ERP content viewport');

  assert(await page.locator('a[href$="/company/feo/utilizers"]').count()===0,'logist sees owner utilizer link');
  const ownerPage=await page.request.get(utilizerUrl);assert(ownerPage.status()===403,'logist utilizer page must be 403');
  const mutation=await page.request.post(utilizerUrl,{headers:{'X-CSRF-TOKEN':csrf},form:{full_name:'QA MUST NOT WRITE',short_name:'QA-NO-WRITE'}});assert(mutation.status()===403,'logist utilizer mutation must be 403');

  assert(pageErrors.length===0,'map page errors: '+pageErrors.join(' | '));
  const relevantConsole=consoleErrors.filter(x=>!/favicon|Failed to load resource.*404/i.test(x));
  assert(relevantConsole.length===0,'map console errors: '+relevantConsole.join(' | '));
  return{requests,markers,adr:liveAdr.length,stacked:stacked.length,adrEvidence,stackEvidence,assets,panelMetrics};
}

async function verifyYandexFailure(context){
  const page=await context.newPage();let blocked=0;
  await page.route('https://api-maps.yandex.ru/**',route=>{blocked++;return route.abort();});
  try{
    const r=await page.goto(mapUrl,{waitUntil:'domcontentloaded',timeout:60000});assert(r&&r.status()===200,'Yandex-failure map HTTP != 200');
    await page.locator('#feo-map-page[data-map-ready="error"]').waitFor({state:'attached',timeout:15000});
    const text=await page.locator('#feo-map-runtime-error:not([hidden])').innerText();
    assert(/Яндекс.Карты/.test(text),'missing visible Yandex load failure');assert(blocked>0,'Yandex API failure route was not exercised');
    await page.screenshot({path:'artifacts/feo-map-stage1-yandex-api-failure.png',fullPage:true});
    return{blocked,visibleError:true};
  }finally{await page.close();}
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
  await p.screenshot({path:'artifacts/feo-utilizers-owner-production.png',fullPage:true});assert(errors.length===0,'owner page errors: '+errors.join(' | '));await c.close();return{utilizerRows:8,unresolved,aliases:true,rules:false,overrides:false};
}

(async()=>{
  fs.mkdirSync('artifacts',{recursive:true});const browser=await chromium.launch({headless:true});
  const liveContext=await browser.newContext({viewport:{width:1920,height:1080}});await liveContext.addCookies([cookie(logistSid)]);const livePage=await liveContext.newPage();const live=await verifyMap(livePage);fs.writeFileSync('artifacts/production-dom.html',await livePage.content());await livePage.screenshot({path:'artifacts/feo-map-stage1-production.png',fullPage:true});await liveContext.close();
  const failureContext=await browser.newContext({viewport:{width:1920,height:1080}});await failureContext.addCookies([cookie(logistSid)]);const yandexFailure=await verifyYandexFailure(failureContext);await failureContext.close();
  const owner=await ownerCheck(browser);const summary={sourceSha:process.env.SOURCE_SHA,live,yandexFailure,owner,note:'Authenticated read-only production QA for full-viewport Yandex Maps + legacy request marker/vertical stack. No business mutation submitted.'};fs.writeFileSync('artifacts/qa_summary.json',JSON.stringify(summary,null,2));console.log(`FEO_MAP_STAGE1_PRODUCTION_VISUAL=PASS requests=${live.requests} markers=${live.markers} stacks=${live.stacked} utilizers=${owner.utilizerRows} unresolved=${owner.unresolved}`);await browser.close();
})().catch(e=>{console.error(e&&e.stack||e);process.exit(1);});
