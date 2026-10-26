import assert from 'node:assert/strict';
import fs from 'node:fs';
import http from 'node:http';
import path from 'node:path';
import {createRequire} from 'node:module';
import {execFileSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';

const require=createRequire(import.meta.url),{chromium}=require('playwright');
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const html=execFileSync(process.env.PLANEX_PHP||'php',[path.join(root,'tests/fixtures/trip_completion_render.php')],{encoding:'utf8'});
const reviewerHtml=execFileSync(process.env.PLANEX_PHP||'php',[path.join(root,'tests/fixtures/trip_completion_render.php'),'reviewer'],{encoding:'utf8'});
const emptyReviewerHtml=execFileSync(process.env.PLANEX_PHP||'php',[path.join(root,'tests/fixtures/trip_completion_render.php'),'reviewer-empty'],{encoding:'utf8'});
const readonlyHtml=execFileSync(process.env.PLANEX_PHP||'php',[path.join(root,'tests/fixtures/trip_completion_render.php'),'registration-readonly'],{encoding:'utf8'});
let senderReady=false,submitted=false,stagedBytes=0;const reviewerActions=[];
const server=http.createServer((req,res)=>{
  if(req.url?.endsWith('/package-stage')) {
    res.setHeader('Content-Type','application/json; charset=utf-8');
    if(req.headers['content-type']==='application/octet-stream') {
      const chunks=[];req.on('data',chunk=>chunks.push(chunk));
      req.on('end',()=>{stagedBytes+=Buffer.concat(chunks).length;res.end(JSON.stringify({ok:true,size:stagedBytes}));});
    } else res.end(JSON.stringify({ok:true,upload_token:'a'.repeat(64)}));
    return;
  }
  if(req.url?.endsWith('/review')) {
    res.setHeader('Content-Type','application/json; charset=utf-8');
    const reviewer=req.url.startsWith('/company/tn-review/');
    if(req.method==='POST'){
      const chunks=[];req.on('data',chunk=>chunks.push(chunk));
      req.on('end',()=>{const sent=Buffer.concat(chunks).toString('latin1');if(reviewer){const action=sent.match(/name="action"\r\n\r\n(archive|restore|return_work)/)?.[1];reviewerActions.push(action);res.end(JSON.stringify({ok:true,status:action==='archive'?'archive':action==='return_work'?'work':'review',version:action==='archive'?2:3}));return;}submitted=stagedBytes===4&&sent.includes('name="package_token"')&&sent.includes('name="snapshot_hash"')&&!sent.includes('name="package"');res.end(JSON.stringify({ok:true,status:'review',version:1,mail_status:'accepted'}));});
      return;
    }
    if(reviewer) return res.end(JSON.stringify({ok:true,status:'review',version:1,problems:[{label:'Нет ТН',ids:['271941']}],snapshot_hash:'synthetic'}));
    return res.end(JSON.stringify({ok:true,status:'work',version:0,problems:[{label:'Нет ТН',ids:['271941']}],snapshot_hash:'synthetic',sender_ready:senderReady,sender_email:senderReady?'actor@plan-ex.ru':null,sender_error:senderReady?null:'У вашего пользователя нет подключённого рабочего ящика.',recipient_count:2}));
  }
  if(['/', '/reviewer', '/reviewer-empty', '/readonly'].includes(req.url)){res.setHeader('Content-Type','text/html; charset=utf-8');return res.end(req.url==='/reviewer'?reviewerHtml:req.url==='/reviewer-empty'?emptyReviewerHtml:req.url==='/readonly'?readonlyHtml:html);}
  const file=path.resolve(root,'public','.'+req.url.split('?')[0]);
  if(!file.startsWith(path.join(root,'public')+path.sep)||!fs.existsSync(file)){res.statusCode=404;return res.end();}
  res.setHeader('Content-Type',file.endsWith('.css')?'text/css':file.endsWith('.js')?'application/javascript':'application/octet-stream');
  res.end(fs.readFileSync(file));
});
await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
const browser=await chromium.launch({headless:true,...(process.env.PLANEX_BROWSER_EXECUTABLE?{executablePath:process.env.PLANEX_BROWSER_EXECUTABLE}:{})});
try {
  const page=await browser.newPage({viewport:{width:1440,height:900}}),errors=[],alerts=[];
  page.on('pageerror',error=>errors.push(error.message));
  page.on('dialog',async dialog=>{alerts.push(dialog.message());await dialog.dismiss();});
  await page.goto('http://127.0.0.1:'+server.address().port,{waitUntil:'networkidle'});
  assert.equal(await page.locator('.lab-review-status').count(),0,'Registrar rows must not repeat review status badges');
  assert.equal(await page.locator('.lab-review-group--work').isVisible(),true);
  assert.equal(await page.locator('.lab-review-group--review').isVisible(),false);
  await page.locator('input[name="show_all_trips"]').check();
  assert.equal(await page.locator('.lab-review-group--review').isVisible(),true);
  await page.locator('input[name="show_all_trips"]').uncheck();
  await page.locator('.logistics-trip').first().locator('summary').click();
  await page.locator('.logistics-trip').first().getByRole('button',{name:'Передать на проверку'}).click();
  const dialog=page.locator('.lab-review-dialog');
  await dialog.waitFor({state:'visible',timeout:5000}).catch(error=>{throw new Error(`${error.message}; alerts=${alerts.join(';')}; pageerrors=${errors.join(';')}`);});
  assert.match(await dialog.innerText(),/Нет ТН.*271941/s);
  assert.match(await dialog.innerText(),/У вашего пользователя нет подключённого рабочего ящика/);
  assert.equal(await dialog.getByRole('button',{name:'Всё верно, передать'}).isDisabled(),true,'missing sender blocks submission');
  await dialog.getByRole('button',{name:'Отмена'}).click();
  assert.equal(await dialog.isVisible(),false);
  senderReady=true;
  await page.evaluate(()=>{window.TripCompletionReport.buildPackage=async()=>({bytes:new Uint8Array([80,75,3,4]),name:'Рейс_244_комплект.zip'});});
  await page.locator('.logistics-trip').first().getByRole('button',{name:'Передать на проверку'}).click();
  await dialog.waitFor({state:'visible'});
  assert.match(await dialog.innerText(),/Отправитель: actor@plan-ex\.ru/);
  await dialog.getByRole('button',{name:'Всё верно, передать'}).click();
  await page.waitForFunction(()=>document.querySelector('.lab-review-dialog')?.open===false);
  assert.equal(submitted,true,'package and snapshot are submitted together');
  await page.goto('http://127.0.0.1:'+server.address().port+'/readonly',{waitUntil:'networkidle'});
  await page.locator('input[name="show_all_trips"]').check();
  assert.equal(await page.locator('[data-review-form]').count(),0,'Registrar cannot transition reviewed or archived trips');
  assert.equal(await page.locator('[data-autosave-form] input[name="pickup_interval"]').first().isDisabled(),true,'Registrar cannot edit a trip under review');
  await page.goto('http://127.0.0.1:'+server.address().port+'/reviewer',{waitUntil:'networkidle'});
  assert.equal(await page.locator('.lab-review-status').count(),0,'Reviewer rows must not repeat review status badges');
  const reviewHead=page.locator('.registry-ui-head');
  assert.equal(await reviewHead.getByRole('heading',{name:'Проверка ТН'}).count(),1,'Reviewer uses the approved registry header');
  assert.equal(await reviewHead.locator('svg.icon-tabler-file-text').count(),1,'Reviewer uses the selected named Tabler icon');
  assert.equal(await reviewHead.locator('.page-summary').innerText(),'Рейсы в работе и на проверке. Проверенные рейсы переносятся в архив.');
  const layout=await page.evaluate(()=>{const box=selector=>document.querySelector(selector)?.getBoundingClientRect();const shell=box('.workspace-shell-1690'),head=box('.page-head'),panel=box('.tn-review-shell'),toolbar=box('.registry-ui-toolbar'),workspace=box('.logistics-workspace');return {shellWidth:shell?.width,headLeft:head?.left,panelLeft:panel?.left,panelWidth:panel?.width,toolbarLeft:toolbar?.left,workspaceLeft:workspace?.left,workspaceRight:workspace?.right,panelRight:panel?.right,bodyScroll:document.documentElement.scrollWidth,viewport:innerWidth,panelBorder:getComputedStyle(document.querySelector('.tn-review-shell')).borderLeftWidth};});
  assert.ok(layout.shellWidth<=1690 && layout.shellWidth>=1200,'Reviewer uses centered standard workspace width');
  assert.ok(Math.abs(layout.headLeft-layout.panelLeft)<=1,'Header and panel share one alignment grid');
  assert.ok(layout.toolbarLeft>layout.panelLeft && layout.workspaceLeft>layout.panelLeft && layout.workspaceRight<layout.panelRight,'Toolbar and trip list stay inside standard panel');
  assert.equal(await page.locator('.logistics-metrics').count(),0,'Reviewer statistics are removed');
  assert.equal(await page.locator('[data-review-found]').innerText(),'2','Found count reflects visible work and review trips');
  assert.deepEqual(await page.locator('[data-review-status-filter]').evaluateAll(inputs=>inputs.map(input=>[input.value,input.checked])),[['work',true],['review',true],['archive',false]],'Reviewer starts with work and review selected');
  if(process.env.PLANEX_SCREENSHOT_DIR){fs.mkdirSync(process.env.PLANEX_SCREENSHOT_DIR,{recursive:true});await page.screenshot({path:path.join(process.env.PLANEX_SCREENSHOT_DIR,'reviewer-toolbar-1440.png'),fullPage:true});}
  await page.setViewportSize({width:390,height:844});
  assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'Reviewer toolbar has no mobile horizontal overflow');
  if(process.env.PLANEX_SCREENSHOT_DIR){await page.screenshot({path:path.join(process.env.PLANEX_SCREENSHOT_DIR,'reviewer-toolbar-390.png'),fullPage:true});}
  await page.setViewportSize({width:1440,height:900});
  assert.equal(await page.locator('[data-review-all]').isChecked(),false);
  await page.locator('[data-review-status-filter][value="work"]').uncheck();
  assert.equal(await page.locator('.lab-review-group--work').isVisible(),false);
  assert.equal(await page.locator('[data-review-found]').innerText(),'1');
  await page.locator('[data-review-all]').check();
  assert.equal(await page.locator('.lab-review-group--archive').isVisible(),true);
  assert.equal(await page.locator('[data-review-found]').innerText(),'3');
  await page.locator('[data-review-status-filter][value="archive"]').uncheck();
  assert.equal(await page.locator('[data-review-all]').isChecked(),false,'Master checkbox follows individual filters');
  await page.locator('[data-review-status-filter][value="work"]').uncheck();
  await page.locator('[data-review-reset]').click();
  assert.equal(await page.locator('[data-review-found]').innerText(),'2');
  assert.equal(await page.locator('[data-review-reset]').isHidden(),true);
  await page.locator('.registry-ui-search').fill('245');
  assert.equal(await page.locator('[data-review-found]').innerText(),'1','Search updates canonical count');
  await page.locator('.registry-ui-search').press('Enter');
  assert.equal(new URL(page.url()).pathname,'/reviewer','Enter keeps the live status filter on this page');
  await page.locator('[data-review-reset]').click();
  assert.notEqual(layout.panelBorder,'0px','Reviewer panel retains standard box border');
  assert.ok(layout.bodyScroll<=layout.viewport+1,'Reviewer page has no horizontal overflow');
  await page.setViewportSize({width:1920,height:1080});
  const headStyle=await reviewHead.evaluate(element=>({height:element.getBoundingClientRect().height,titleSize:getComputedStyle(element.querySelector('.page-title')).fontSize,iconWidth:element.querySelector('svg').getBoundingClientRect().width}));
  assert.deepEqual(headStyle,{height:58,titleSize:'19px',iconWidth:30},'Reviewer header keeps approved desktop dimensions');
  const wideLayout=await page.evaluate(()=>{const main=document.querySelector('main').getBoundingClientRect(),shell=document.querySelector('.workspace-shell-1690').getBoundingClientRect();return {shellWidth:shell.width,leftGap:shell.left-main.left,rightGap:main.right-shell.right,scroll:document.documentElement.scrollWidth,viewport:innerWidth};});
  assert.ok(wideLayout.shellWidth<=1690 && Math.abs(wideLayout.leftGap-wideLayout.rightGap)<=1,'Reviewer box is capped and centered like MAX on a wide desktop');
  assert.ok(wideLayout.scroll<=wideLayout.viewport+1,'Wide reviewer page has no horizontal overflow');
  await page.setViewportSize({width:1280,height:900});
  const toolbarStyle=await page.evaluate(()=>{const search=document.querySelector('.registry-ui-search'),checkbox=document.querySelector('[data-review-status-filter]'),toolbar=document.querySelector('.registry-ui-toolbar');return {searchWidth:search.getBoundingClientRect().width,searchHeight:search.getBoundingClientRect().height,checkboxWidth:checkbox.getBoundingClientRect().width,toolbarBackground:getComputedStyle(toolbar).backgroundColor,scroll:document.documentElement.scrollWidth,viewport:innerWidth};});
  assert.deepEqual(toolbarStyle,{searchWidth:230,searchHeight:32,checkboxWidth:16,toolbarBackground:'rgb(247, 245, 240)',scroll:1280,viewport:1280},'Reviewer toolbar uses approved desktop dimensions and surface');
  if(process.env.PLANEX_SCREENSHOT_DIR){fs.mkdirSync(process.env.PLANEX_SCREENSHOT_DIR,{recursive:true});await reviewHead.screenshot({path:path.join(process.env.PLANEX_SCREENSHOT_DIR,'reviewer-header-1920.png')});await reviewHead.locator('.page-head-left').screenshot({path:path.join(process.env.PLANEX_SCREENSHOT_DIR,'reviewer-header-left-1920.png')});}
  assert.equal(await page.locator('.lab-review-group--work').isVisible(),true,'Reviewer sees work trips alongside review trips');
  assert.equal(await page.locator('.lab-review-group--review').isVisible(),true);
  assert.equal(await page.locator('.lab-review-group--archive').isVisible(),false);
  await page.locator('.lab-review-group--work summary').click();
  assert.equal(await page.locator('.lab-review-group--work .lab-docs-name').count(),1,'Reviewer sees scans on work trips');
  assert.equal(await page.locator('.lab-review-group--work .lab-docs-download').count(),1,'Reviewer can download scans on work trips');
  assert.equal(await page.locator('.lab-review-group--work [data-autosave-form] input[name="pickup_interval"]').first().isDisabled(),true,'Work trips are read only for reviewer');
  assert.equal(await page.locator('.lab-review-group--work [data-review-form]').count(),0,'Reviewer cannot move an unfinished trip to archive');
  await page.locator('.lab-review-group--review summary').click();
  assert.equal(await page.locator('.lab-review-group--review [data-autosave-form] input[name="pickup_interval"]').first().isEnabled(),true,'Reviewer must be able to edit a trip under review');
  assert.deepEqual((await page.locator('.lab-review-group--review .logistics-trip-toolbar button[type="submit"]').allTextContents()).map(text=>text.trim()),['Вернуть на доработку','Переместить в архив'],'Return action precedes archive');
  await page.getByRole('button',{name:'Переместить в архив'}).click();
  await dialog.waitFor({state:'visible'});
  assert.match(await dialog.innerText(),/Нет ТН.*271941/s);
  await dialog.getByRole('button',{name:'Переместить в архив'}).click();
  await page.waitForFunction(()=>document.querySelector('.lab-review-dialog')?.open===false);
  assert.deepEqual(reviewerActions,['archive'],'Archive action must be submitted through reviewer route');
  await page.locator('[data-review-status-filter][value="archive"]').check();
  await page.locator('.lab-review-group--archive summary').click();
  assert.equal(await page.locator('.lab-review-group--archive [data-autosave-form] input[name="pickup_interval"]').first().isDisabled(),true,'Archive must be read only');
  await Promise.all([page.waitForEvent('load'),page.getByRole('button',{name:'Вернуть из архива'}).click()]);
  assert.deepEqual(reviewerActions,['archive','restore'],'Only reviewer can restore an archived trip');
  await page.locator('.lab-review-group--review summary').click();
  await Promise.all([page.waitForEvent('load'),page.getByRole('button',{name:'Вернуть на доработку'}).click()]);
  assert.deepEqual(reviewerActions,['archive','restore','return_work'],'Reviewer can return a trip to registrar work');
  await page.setViewportSize({width:390,height:844});
  await page.locator('.lab-review-group--review summary').click();
  const mobile=await page.evaluate(()=>({scroll:document.documentElement.scrollWidth,viewport:innerWidth}));
  assert.ok(mobile.scroll<=mobile.viewport+1,'Reviewer actions must not create mobile horizontal overflow');
  if(process.env.PLANEX_SCREENSHOT_DIR){await reviewHead.screenshot({path:path.join(process.env.PLANEX_SCREENSHOT_DIR,'reviewer-header-390.png')});await reviewHead.locator('.page-head-left').screenshot({path:path.join(process.env.PLANEX_SCREENSHOT_DIR,'reviewer-header-left-390.png')});}
  await page.goto('http://127.0.0.1:'+server.address().port+'/reviewer-empty',{waitUntil:'networkidle'});
  assert.equal(await page.locator('.tn-review-shell').isVisible(),true,'Empty reviewer state keeps the standard panel');
  assert.equal(await page.locator('.lab-review-group--review').isVisible(),true,'Empty reviewer state keeps its group heading');
  assert.equal(await page.locator('.logistics-trip').count(),0,'Empty reviewer state has no trip rows');
  if(process.env.PLANEX_SCREENSHOT_DIR){await page.screenshot({path:path.join(process.env.PLANEX_SCREENSHOT_DIR,'reviewer-empty-1920.png')});}
  assert.deepEqual(errors,[],'no browser errors');
  console.log('TRIP_REVIEW_BROWSER=PASS');
} finally { await browser.close(); await new Promise(resolve=>server.close(resolve)); }
