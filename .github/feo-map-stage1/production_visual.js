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

async function mapCheck(browser){
  const c=await browser.newContext({viewport:{width:1920,height:1080}});await c.addCookies([cookie(logistSid)]);const p=await c.newPage();
  const errors=[];p.on('pageerror',e=>errors.push(String(e)));
  const r=await p.goto(mapUrl,{waitUntil:'domcontentloaded',timeout:60000});assert(r&&r.status()===200,'map HTTP != 200');
  await p.locator('#feo-map-page[data-map-ready="1"]').waitFor({state:'attached',timeout:60000});
  const requests=Number((await p.locator('#feo-map-request-count').innerText()).trim());
  const markers=Number((await p.locator('#feo-map-marker-count').innerText()).trim());
  assert(requests>0,'zero map requests');assert(markers>0,'zero map markers');assert(await p.locator('.feo-marker').count()===markers,'DOM marker mismatch');
  assert(await p.locator('#feo-layer-list .feo-layer-row').count()>0,'map layers missing');
  assert(await p.locator('a[href$="/company/feo/utilizers"]').count()===0,'logist sees owner utilizer link');
  const ownerPage=await p.request.get(utilizerUrl);assert(ownerPage.status()===403,'logist utilizer page must be 403');
  const mutation=await p.request.post(utilizerUrl,{headers:{'X-CSRF-TOKEN':csrf},form:{full_name:'QA MUST NOT WRITE',short_name:'QA-NO-WRITE'}});assert(mutation.status()===403,'logist utilizer mutation must be 403');
  await p.locator('.feo-marker').first().click();await p.locator('.leaflet-popup').waitFor({state:'visible'});
  const popup=await p.locator('.leaflet-popup').innerText();assert(/Вес/.test(popup)&&/Утилизатор/.test(popup)&&/Состояние/.test(popup),'popup fields missing');
  await p.screenshot({path:'artifacts/feo-map-stage1-production.png',fullPage:true});assert(errors.length===0,'map page errors: '+errors.join(' | '));await c.close();return{requests,markers};
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
  const badge=utilizerNav.locator('[data-feo-utilizer-badge]');if(unresolved>0){assert(await badge.count()===1,'unresolved badge missing');const txt=(await badge.innerText()).trim();assert(txt===(unresolved>999?'999+':String(unresolved)),'badge count mismatch');}else assert(await badge.count()===0,'zero-problem badge must be absent');
  const search=p.locator('[data-feo-utilizer-grid] .toolbar-search');const firstName=(await rows.first().locator('.cell-main').innerText()).trim();await search.fill(firstName);await p.waitForTimeout(80);assert(await p.locator('[data-feo-utilizer-grid] tbody tr[data-feo-utilizer-id]:visible').count()===1,'registry search failed');await search.fill('');
  await rows.first().dblclick();const modal=p.locator('#feo-utilizer-view-modal.is-open');await modal.waitFor({state:'visible',timeout:15000});await modal.locator('[data-feo-utilizer-edit-btn]').waitFor({state:'visible',timeout:15000});
  assert(((await modal.textContent())||'').includes('Синонимы'),'view synonyms missing');await modal.locator('[data-feo-utilizer-edit-btn]').click();const edit=modal.locator('[data-feo-utilizer-edit-form]');await edit.waitFor({state:'visible'});assert(await edit.locator('[name="aliases"]').count()===1,'edit aliases missing');await modal.locator('[data-feo-utilizer-cancel-edit-btn]').click();await modal.locator('[data-feo-utilizer-edit-btn]').waitFor({state:'visible'});await modal.locator('[data-feo-utilizer-close-btn]').click();
  await p.getByRole('button',{name:'Добавить утилизатора'}).click();const create=p.locator('#feo-utilizer-create-modal.is-open');await create.waitFor({state:'visible'});assert(await create.locator('[name="aliases"]').count()===1,'create aliases missing');await create.locator('.modal-close').click();
  await rows.first().dblclick();await modal.waitFor({state:'visible'});await modal.locator('[data-feo-utilizer-delete-btn]').click();const confirm=p.locator('#feo-utilizer-delete-confirm-modal.is-open');await confirm.waitFor({state:'visible'});assert(await confirm.locator('[data-confirm-input]').count()===1,'typed delete confirm missing');assert(await confirm.locator('[data-confirm-action]').isDisabled(),'delete must be disabled before confirmation');await confirm.locator('[data-confirm-cancel]').click();await modal.locator('[data-feo-utilizer-close-btn]').click();
  await p.screenshot({path:'artifacts/feo-utilizers-owner-production.png',fullPage:true});assert(errors.length===0,'owner page errors: '+errors.join(' | '));await c.close();return{utilizerRows:8,unresolved,aliases:true,rules:false,overrides:false};
}

(async()=>{fs.mkdirSync('artifacts',{recursive:true});const browser=await chromium.launch({headless:true});const map=await mapCheck(browser);const owner=await ownerCheck(browser);const summary={sourceSha:process.env.SOURCE_SHA,map,owner,note:'Authenticated read-only production QA for simplified FEO utilizer matching. No business mutation submitted.'};fs.writeFileSync('artifacts/qa_summary.json',JSON.stringify(summary,null,2));console.log(`FEO_MAP_STAGE1_PRODUCTION_VISUAL=PASS requests=${map.requests} markers=${map.markers} utilizers=${owner.utilizerRows} unresolved=${owner.unresolved}`);await browser.close();})().catch(e=>{console.error(e&&e.stack||e);process.exit(1);});
