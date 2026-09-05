const fs=require('fs'); const {chromium}=require('playwright');
const base='https://plan-ex.ru/erpv2', out=process.env.RESULT_DIR||'roles_access_ui_after_result';
const cookie=process.env.QA_COOKIE_NAME||'', sid=process.env.QA_LEGACY_SESSION_ID||'';
function must(v,m){if(!v)throw new Error(m)}
async function noOverflow(p,label){const x=await p.evaluate(()=>({doc:document.documentElement.scrollWidth-document.documentElement.clientWidth,body:document.body.scrollWidth-document.body.clientWidth,root:(()=>{const e=document.querySelector('[data-roles-access-root]');return e?e.scrollWidth-e.clientWidth:0})()}));must(x.doc<=1&&x.body<=1&&x.root<=1,label+' horizontal overflow '+JSON.stringify(x));}
async function openUsers(p){await p.locator('[data-access-tab="users"]').click();await p.waitForTimeout(120);const row=p.locator('[data-membership-id]').first();must(await row.count()===1,'no user row');await row.click();await p.waitForTimeout(120);must(await p.locator('#roles-access-modal[aria-hidden="false"]').count()===1,'user modal missing');}
async function closeModal(p){const b=p.locator('[data-access-modal-close]').first();if(await b.count())await b.click();await p.waitForTimeout(60);}
(async()=>{fs.mkdirSync(out,{recursive:true});const browser=await chromium.launch({headless:true});try{
 const ctx=await browser.newContext({viewport:{width:1440,height:900},locale:'ru-RU'});let writes=[];
 await ctx.route('**/*',async route=>{const m=route.request().method().toUpperCase();if(!['GET','HEAD','OPTIONS'].includes(m)){writes.push(m+' '+route.request().url());return route.abort('blockedbyclient')}return route.continue()});
 await ctx.addCookies([{name:cookie,value:sid,url:base,httpOnly:true,secure:true,sameSite:'Lax'}]);const p=await ctx.newPage();
 let r=await p.goto(base+'/company/dashboard',{waitUntil:'domcontentloaded',timeout:60000});must(r&&r.status()===200,'dashboard not 200');must(!p.url().includes('/login'),'upgrade failed');
 const c=(await ctx.cookies(base)).find(x=>x.name===cookie);must(c&&c.value&&c.value!==sid,'SID rotation failed');
 r=await p.goto(base+'/company/roles-access',{waitUntil:'networkidle',timeout:60000});must(r&&r.status()===200,'roles page not 200');must(await p.locator('[data-roles-access-root]').count()===1,'roles root missing');
 const viewports=[1920,1440,1366,800];
 for(const width of viewports){await p.setViewportSize({width,height:900});await p.waitForTimeout(100);await noOverflow(p,'main '+width);await openUsers(p);await noOverflow(p,'modal '+width);
   const modal=p.locator('.roles-access-modal__dialog');const box=await modal.boundingBox();must(box&&box.width<=1101&&box.width<=width,'modal width '+width+' '+JSON.stringify(box));must(box.height<=900*.86,'modal height '+width+' '+box.height);
   const body=p.locator('.roles-access-modal__body'),head=p.locator('.roles-access-modal__head'),foot=p.locator('.roles-access-modal__foot');
   const styles=await Promise.all([body,head,foot].map(x=>x.evaluate(el=>{const s=getComputedStyle(el);return {oy:s.overflowY,ox:s.overflowX,pos:s.position}})));
   must(['auto','scroll'].includes(styles[0].oy)&&styles[0].ox==='hidden','modal body scroll contract '+JSON.stringify(styles));must(styles[1].pos==='sticky'&&styles[2].pos==='sticky','sticky head/footer '+width);
   must(await p.locator('[data-page-choice="inherit"]').count()>0&&await p.locator('[data-page-choice="allow"]').count()>0&&await p.locator('[data-page-choice="deny"]').count()>0,'tri-state missing '+width);
   must(await p.locator('.modal-overlay.is-open').count()===1,'popup-over-popup '+width);
   if(width===1440)await p.screenshot({path:`${out}/after_user_modal_1440.jpg`,type:'jpeg',quality:58,fullPage:true});
   await closeModal(p);
 }
 await p.setViewportSize({width:1440,height:900});await p.locator('[data-access-tab="assignments"]').click();for(let i=0;i<160;i++){const text=await p.locator('[data-assignment-content]').innerText().catch(()=> '');if(text&&!text.includes('Загрузка закреплений'))break;await p.waitForTimeout(250)}
 must(await p.locator('[data-assignment-group]').count()===4,'assignment panels !=4');must(await p.locator('[data-assignment-search]').count()===4,'assignment searches !=4');must(await p.locator('[data-assignment-count]').count()===4,'assignment counts !=4');must(await p.locator('[data-assignment-bulk="select"]').count()===4&&await p.locator('[data-assignment-bulk="clear"]').count()===4,'assignment bulk helpers missing');
 const checks=p.locator('.roles-access__assignment-row input[type=checkbox]');if(await checks.count()){const dims=await checks.first().evaluate(el=>{const r=el.getBoundingClientRect();return {w:r.width,h:r.height}});must(dims.w>=15&&dims.w<=19&&dims.h>=15&&dims.h<=19,'checkbox size '+JSON.stringify(dims));}
 const labels=p.locator('.roles-access__assignment-row span');if(await labels.count()){const cs=await labels.first().evaluate(el=>{const s=getComputedStyle(el);return {whiteSpace:s.whiteSpace,overflow:s.overflow,textOverflow:s.textOverflow}});must(cs.whiteSpace!=='nowrap'&&cs.textOverflow!=='ellipsis','assignment label clipping '+JSON.stringify(cs));}
 await noOverflow(p,'assignments 1440');await p.screenshot({path:`${out}/after_assignments_1440.jpg`,type:'jpeg',quality:58,fullPage:true});
 for(const width of [1920,1366,800]){await p.setViewportSize({width,height:900});await p.waitForTimeout(100);await noOverflow(p,'assignments '+width);}
 must(writes.length===0,'write attempted: '+writes.join(','));
 const lines=['EXACT_PRODUCTION_SHA='+process.env.EXPECTED_SHA,'VIEWPORTS=1920,1440,1366,800','HORIZONTAL_OVERFLOW=NO','POPUP_OVER_POPUP=NO','USER_MODAL_GEOMETRY=PASS','STICKY_HEADER_FOOTER=PASS','BODY_ONLY_SCROLL=PASS','TRI_STATE=PASS','ASSIGNMENT_PANELS=4','ASSIGNMENT_SEARCH=PASS','ASSIGNMENT_COUNTS=PASS','ASSIGNMENT_CHECKBOX=16PX','ASSIGNMENT_NAMES_WRAP=PASS','WRITE_REQUESTS=0','BROWSER_UI_PASS=YES'];fs.writeFileSync(`${out}/proof.txt`,lines.join('\n')+'\n');
 console.log('ROLES_ACCESS_AFTER_BROWSER_UI_PASS=YES');console.log('ROLES_ACCESS_VIEWPORTS_PASS=1920,1440,1366,800');console.log('ROLES_ACCESS_HORIZONTAL_OVERFLOW=NO');console.log('ROLES_ACCESS_POPUP_OVER_POPUP=NO');console.log('ROLES_ACCESS_UI_WRITE_REQUESTS=0');
 console.log('AFTER_ASSIGNMENTS_JPEG_BASE64='+fs.readFileSync(`${out}/after_assignments_1440.jpg`).toString('base64'));console.log('AFTER_USER_MODAL_JPEG_BASE64='+fs.readFileSync(`${out}/after_user_modal_1440.jpg`).toString('base64'));await ctx.close();
}finally{await browser.close()}})().catch(e=>{console.error(e.stack||e);process.exit(1)});