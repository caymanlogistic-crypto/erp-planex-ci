import assert from 'node:assert/strict';
import fs from 'node:fs'; import http from 'node:http'; import path from 'node:path';
import {createRequire} from 'node:module'; import {execFileSync} from 'node:child_process'; import {fileURLToPath} from 'node:url';
const require=createRequire(import.meta.url),{chromium}=require('playwright');
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const html=execFileSync(process.env.PLANEX_PHP||'php',[path.join(root,'tests/fixtures/user_max_id_render.php')],{encoding:'utf8'});
const servicesHtml=execFileSync(process.env.PLANEX_PHP||'php',[path.join(root,'tests/fixtures/feo_transfers_render.php'),'services'],{encoding:'utf8'});
const server=http.createServer((req,res)=>{
 if(req.url==='/'||req.url==='/services'){res.setHeader('Content-Type','text/html; charset=utf-8');return res.end(req.url==='/services'?servicesHtml:html);}
 const file=path.resolve(root,'public','.'+req.url.split('?')[0]);
 if(!file.startsWith(path.join(root,'public')+path.sep)||!fs.existsSync(file)){res.statusCode=404;return res.end();}
 res.setHeader('Content-Type',file.endsWith('.css')?'text/css':'text/javascript');res.end(fs.readFileSync(file));
});
await new Promise(r=>server.listen(0,'127.0.0.1',r));
const browser=await chromium.launch({headless:true});
const origin='http://127.0.0.1:'+server.address().port;
try {for(const width of [1366,390]) {
 const page=await browser.newPage({viewport:{width,height:1000}}), errors=[];page.on('pageerror',e=>errors.push(e.message));
 let saved=null,created=null,checks=0,failSave=true,snapshot;
 const profiles=[{legal_entity_id:1,company_id:25,company_name:'ПЛАНЭКС',email_domain:'plan-ex.ru',email:'ivan@plan-ex.ru',has_password:true,connection_status:'connected',version:1,schema_ready:true},{legal_entity_id:2,company_id:25,company_name:'МГ-ЛОГ',email_domain:'mg-log.ru',email:'ivan@mg-log.ru',has_password:false,connection_status:'incomplete',version:1,schema_ready:true}];
 await page.route('**/company/roles-access/**',async route=>{
   const req=route.request(),pathname=new URL(req.url()).pathname,body=req.method()==='POST'?req.postDataJSON():null;
   let result={ok:true,profiles},status=200;
   if(pathname.endsWith('/data')) result={ok:true,...snapshot};
   else if(pathname.endsWith('/template')) result={ok:true,profiles:profiles.map(p=>({...p,email:'',has_password:false,connection_status:'incomplete',version:0}))};
   else if(pathname.endsWith('/memberships')) {created=body;result={ok:true,membership:{account_id:102,membership_id:13}};}
   else if(pathname.endsWith('/save')) {
     if(failSave) {status=422;result={ok:false,error:'Укажите корректный адрес рабочей почты.'};failSave=false;}
     else {saved=body;for(const input of body.work_mail){const p=profiles.find(p=>p.legal_entity_id===input.legal_entity_id);Object.assign(p,{email:input.email,has_password:input.clear_password?false:!!input.password||p.has_password,connection_status:'unverified',version:p.version+1});} result={ok:true};}
   }
   else if(pathname.endsWith('/check')) {checks++;assert.equal(body.legal_entity_id,2); const p=profiles[1];Object.assign(p,{connection_status:'connected',version:p.version+1});result={ok:true,profile:p,message:'IMAP и SMTP подключены. Письма не отправлялись.'};}
   await route.fulfill({status,json:result});
 });
 await page.goto(origin);
 snapshot=await page.locator('[data-roles-access-state]').evaluate(el=>JSON.parse(el.textContent));
 const open=async()=>{await page.locator('[data-access-tab="users"]').click();await page.locator('[data-membership-id="12"]').click();await page.locator('[data-mail-email]').nth(1).waitFor();};
 await open();
 const save=page.locator('[data-access-modal-save]');
 assert.equal(await page.locator('[data-mail-password]').first().inputValue(),'');
 assert.equal(await page.locator('[data-mail-password]').first().getAttribute('type'),'password');
 assert.equal(await page.locator('[data-mail-check]').nth(1).isEnabled(),false);
 await page.locator('[data-mail-password]').nth(1).fill('synthetic-ui-only');
 await save.click();await page.locator('[data-modal-error]').getByText('Укажите корректный адрес рабочей почты.',{exact:true}).waitFor();
 assert.equal(await page.locator('[data-mail-password]').nth(1).inputValue(),'synthetic-ui-only','Failed general save discarded input');
 await save.click();await page.locator('.roles-access-modal.is-open').waitFor({state:'hidden'});
 assert.equal(saved.work_mail.length,2);assert.equal(saved.work_mail[1].password,'synthetic-ui-only');assert.equal(saved.work_mail[0].email,'ivan@plan-ex.ru');
 await open();assert.equal(await page.locator('[data-mail-password]').nth(1).inputValue(),'');
 assert.equal(await page.locator('[data-mail-email]').nth(1).inputValue(),'ivan@mg-log.ru');
 await page.locator('[data-mail-check]').nth(1).click();
 await page.getByText('IMAP и SMTP подключены. Письма не отправлялись.',{exact:true}).waitFor();assert.equal(checks,1);
 const checkbox=await page.locator('[data-mail-clear]').first().boundingBox();assert.ok(checkbox.width===16&&checkbox.height===16,'Checkbox must retain native compact dimensions');
 const box=await page.locator('.roles-access-modal__dialog').boundingBox();assert.ok(box.x>=0&&box.x+box.width<=width+1,'Modal overflows');
 const footer=await save.boundingBox();assert.ok(footer.y>=0&&footer.y+footer.height<=1000,'General Save not visible');
 const dir=process.env.PLANEX_SCREENSHOT_DIR;
 async function shot(name){if(dir){fs.mkdirSync(dir,{recursive:true});await page.screenshot({path:path.join(dir,name+'-'+width+'.png'),fullPage:true});}}
 await page.locator('[data-work-mail]').scrollIntoViewIfNeeded();await shot('employee-mail-editor');
 await page.locator('.roles-access-modal__body').evaluate(el=>el.scrollTop=el.scrollHeight);await shot('employee-mail-editor-bottom');
 await page.locator('.roles-access-modal__head [data-access-modal-close]').click();
 await page.locator('[data-membership-create]').click();await page.locator('[data-mail-email]').nth(1).waitFor();
 await page.locator('[data-new-full-name]').fill('Новый сотрудник');await page.locator('[data-new-login]').fill('newuser');await page.locator('[data-new-password]').fill('synthetic-account-only');await page.locator('[data-new-email]').fill('identity@example.test');
 await page.locator('[data-mail-email]').nth(0).fill('newuser@plan-ex.ru');await page.locator('[data-mail-password]').nth(0).fill('synthetic-mail-only');await page.locator('[data-mail-email]').nth(1).fill('newuser@mg-log.ru');
 await page.locator('[data-work-mail]').scrollIntoViewIfNeeded();await shot('employee-mail-create');
 await save.click();await page.locator('.roles-access-modal.is-open').waitFor({state:'hidden'});
 assert.equal(created.work_mail.length,2);assert.equal(created.work_mail[0].password,'synthetic-mail-only');assert.equal(created.work_mail[1].password,'');assert.equal(created.email,'identity@example.test');assert.equal(created.password,'synthetic-account-only');
 let settingsSaved=null;
 await page.route('**/company/settings/services/mail',async route=>{settingsSaved=route.request().postData();await route.fulfill({json:{success:true,message:'Настройки почты сохранены.'}});});
 await page.goto(origin+'/services');
 await page.locator('[name=smtp_port]').fill('1465');await page.locator('[data-mail-settings-form] button').click();
 await page.getByText('Настройки почты сохранены.',{exact:true}).waitFor();assert.match(settingsSaved,/1465/);assert.match(settingsSaved,/smtp.spaceweb.ru/);
 const settingsBox=await page.locator('[data-mail-settings-form]').boundingBox();assert.ok(settingsBox.x>=0&&settingsBox.x+settingsBox.width<=width+1,'Settings overflow');await shot('employee-mail-settings');
 assert.equal(errors.length,0,errors.join(';'));
 await page.close();console.log('EMPLOYEE_MAIL_BROWSER '+width+'=PASS');
}} finally {await browser.close();await new Promise(r=>server.close(r));}
