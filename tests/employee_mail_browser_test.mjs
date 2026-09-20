import assert from 'node:assert/strict';
import fs from 'node:fs'; import http from 'node:http'; import path from 'node:path';
import {createRequire} from 'node:module'; import {execFileSync} from 'node:child_process'; import {fileURLToPath} from 'node:url';
const require=createRequire(import.meta.url),{chromium}=require('playwright');
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const html=execFileSync(process.env.PLANEX_PHP||'php',[path.join(root,'tests/fixtures/user_max_id_render.php')],{encoding:'utf8'});
const server=http.createServer((req,res)=>{
 if(req.url==='/'){res.setHeader('Content-Type','text/html; charset=utf-8');return res.end(html);}
 const file=path.resolve(root,'public','.'+req.url.split('?')[0]);
 if(!file.startsWith(path.join(root,'public')+path.sep)||!fs.existsSync(file)){res.statusCode=404;return res.end();}
 res.setHeader('Content-Type',file.endsWith('.css')?'text/css':'text/javascript');res.end(fs.readFileSync(file));
});
await new Promise(r=>server.listen(0,'127.0.0.1',r));
const browser=await chromium.launch({headless:true});
try {for(const width of [1366,390]) {
 const page=await browser.newPage({viewport:{width,height:1000}}), errors=[];page.on('pageerror',e=>errors.push(e.message));
 let saved=null,checks=0;
 const profiles=[{legal_entity_id:1,company_id:25,company_name:'ООО «ПЛАНЭКС»',email:'ivan@plan-ex.ru',has_password:true,connection_status:'connected',version:1,schema_ready:true},{legal_entity_id:2,company_id:25,company_name:'ООО «МГ ЛОГИСТИК»',email:'ivan@mg-log.ru',has_password:false,connection_status:'incomplete',version:1,schema_ready:true}];
 await page.route(/\/work-mail(?:\/(?:save|check))?$/,async route=>{
   const req=route.request(),body=req.method()==='POST'?req.postDataJSON():null;
   let result={ok:true,profiles};
   if(req.url().endsWith('/save')) {saved=body; const p=profiles.find(p=>p.legal_entity_id===body.legal_entity_id);Object.assign(p,{email:body.email,has_password:!!body.password,connection_status:'unverified',version:2});result={ok:true,profile:p};}
   if(req.url().endsWith('/check')) {checks++;assert.equal(body.legal_entity_id,2); const p=profiles[1];Object.assign(p,{connection_status:'connected',version:3});result={ok:true,profile:p,message:'IMAP и SMTP подключены. Письма не отправлялись.'};}
   await route.fulfill({json:result});
 });
 await page.goto('http://127.0.0.1:'+server.address().port);
 await page.locator('[data-access-tab="users"]').click();await page.locator('[data-membership-id="12"]').click();
 await page.locator('[data-mail-email]').nth(1).waitFor();
 assert.equal(await page.locator('[data-mail-password]').first().inputValue(),'');
 assert.equal(await page.locator('[data-mail-password]').first().getAttribute('type'),'password');
 assert.equal(await page.locator('[data-mail-check]').nth(1).isEnabled(),false);
 await page.locator('[data-mail-password]').nth(1).fill('synthetic-ui-only');
 await page.locator('[data-mail-save]').nth(1).click();
 await page.locator('[data-mail-status]').nth(1).getByText(/Почта сохранена/).waitFor();
 assert.equal(saved.legal_entity_id,2);assert.equal(saved.email,'ivan@mg-log.ru');
 assert.equal(await page.locator('[data-mail-password]').nth(1).inputValue(),'');
 await page.locator('[data-mail-check]').nth(1).click();
 await page.getByText('IMAP и SMTP подключены. Письма не отправлялись.',{exact:true}).waitFor();assert.equal(checks,1);
 assert.equal(await page.locator('[data-mail-email]').first().inputValue(),'ivan@plan-ex.ru');
 const box=await page.locator('.roles-access-modal__dialog').boundingBox();assert.ok(box.x>=0&&box.x+box.width<=width+1,'Modal overflows');
 assert.equal(errors.length,0,errors.join(';'));
 const dir=process.env.PLANEX_SCREENSHOT_DIR;if(dir){fs.mkdirSync(dir,{recursive:true});await page.locator('[data-work-mail]').screenshot({path:path.join(dir,'employee-mail-'+width+'.png')});}
 await page.close();console.log('EMPLOYEE_MAIL_BROWSER '+width+'=PASS');
}} finally {await browser.close();await new Promise(r=>server.close(r));}
