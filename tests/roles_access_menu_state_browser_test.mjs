import assert from 'node:assert/strict';
import fs from 'node:fs'; import http from 'node:http'; import path from 'node:path';
import {createRequire} from 'node:module'; import {execFileSync} from 'node:child_process'; import {fileURLToPath} from 'node:url';
const require=createRequire(import.meta.url),{chromium}=require('playwright');
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const html=execFileSync(process.env.PLANEX_PHP||'php',[path.join(root,'tests/fixtures/roles_access_menu_state_render.php')],{encoding:'utf8'});
const server=http.createServer((req,res)=>{if(req.url==='/'){res.setHeader('Content-Type','text/html; charset=utf-8');return res.end(html);}
 const file=path.resolve(root,'public','.'+req.url.split('?')[0]); if(!file.startsWith(path.join(root,'public')+path.sep)||!fs.existsSync(file)){res.statusCode=404;return res.end();}
 res.setHeader('Content-Type',file.endsWith('.css')?'text/css':file.endsWith('.js')?'text/javascript':'application/octet-stream');res.end(fs.readFileSync(file));});
await new Promise(r=>server.listen(0,'127.0.0.1',r)); const browser=await chromium.launch({headless:true});
try{const page=await browser.newPage({viewport:{width:1366,height:900}}),errors=[];page.on('pageerror',e=>errors.push(e.message));await page.goto('http://127.0.0.1:'+server.address().port);
 await page.locator('[data-role-id="9"]').click(); await page.locator('[data-access-modal-body]').waitFor({state:'visible'});
 for(const label of ['Касса','Операции']){const row=page.locator('.roles-access__tree-page--role',{hasText:label}).filter({has:page.locator('.roles-access__tree-page-note',{hasText:'Скрыто из меню'})});assert.equal(await row.count(),1,label+' hidden marker');}
 const names=await page.locator('.roles-access__tree-page--role .roles-access__tree-page-label').allTextContents();
 assert.equal(names.some(x=>x.includes('Сообщения MAX')),false,'MAX messages must not be grantable');
 const driverTsPermission=page.locator('.roles-access__tree-page--role input[data-page-key="company.driver_ts_data"]');
 assert.equal(await driverTsPermission.count(),1,'Driver and vehicle data must have its own role checkbox');
 assert.equal(await driverTsPermission.isChecked(),true,'Driver and vehicle data checkbox must reflect saved grant');
 const statisticsGroup=page.locator('.roles-access__tree-group',{has:page.locator('.roles-access__tree-group-head',{hasText:'Статистика'})});
 assert.equal(await statisticsGroup.count(),1,'roles-access statistics group missing');
 assert.deepEqual(await statisticsGroup.locator('.roles-access__tree-page--role .roles-access__tree-page-label').allTextContents(),['Работа логистов','Доля компаний в ФЭО','Баланс заявок']);
 assert.deepEqual(await statisticsGroup.locator('[data-page-key]').evaluateAll(nodes=>nodes.filter(node=>node.matches('input')).map(node=>node.dataset.pageKey)),['company.statistics.managers','company.statistics.feo_share','company.statistics.cargo_balance']);
 assert.equal(errors.length,0,'page errors: '+errors.join('; ')); console.log('ROLES_ACCESS_MENU_STATE_BROWSER=PASS');}
finally{await browser.close();await new Promise(r=>server.close(r));}
