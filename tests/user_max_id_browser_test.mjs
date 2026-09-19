import assert from 'node:assert/strict';
import fs from 'node:fs'; import http from 'node:http'; import path from 'node:path';
import {createRequire} from 'node:module'; import {execFileSync} from 'node:child_process'; import {fileURLToPath} from 'node:url';
const require=createRequire(import.meta.url),{chromium}=require('playwright');
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const html=execFileSync(process.env.PLANEX_PHP||'php',[path.join(root,'tests/fixtures/user_max_id_render.php')],{encoding:'utf8'});
const server=http.createServer((req,res)=>{if(req.url==='/'){res.setHeader('Content-Type','text/html; charset=utf-8');return res.end(html);}
 const file=path.resolve(root,'public','.'+req.url.split('?')[0]); if(!file.startsWith(path.join(root,'public')+path.sep)||!fs.existsSync(file)){res.statusCode=404;return res.end();}
 res.setHeader('Content-Type',file.endsWith('.css')?'text/css':file.endsWith('.js')?'text/javascript':'application/octet-stream');res.end(fs.readFileSync(file));});
await new Promise(r=>server.listen(0,'127.0.0.1',r)); const browser=await chromium.launch({headless:true});
try{for(const width of [1920,1366,390]){const page=await browser.newPage({viewport:{width,height:900}}),errors=[];page.on('pageerror',e=>errors.push(e.message));await page.goto('http://127.0.0.1:'+server.address().port);
 await page.locator('[data-access-tab="users"]').click(); await page.locator('[data-membership-id="12"]').click();
 const edit=page.locator('[data-member-max-id]');assert.equal(await edit.inputValue(),'123456789','saved MAX ID readback');
 assert.equal(await edit.getAttribute('maxlength'),'19','MAX ID length guard');
 const dialog=page.locator('#roles-access-modal .roles-access-modal__dialog');const box=await dialog.boundingBox();assert.ok(box&&box.x>=0&&box.x+box.width<=width+1,'edit modal fits '+width);
 await page.locator('[data-access-modal-close]').last().click(); await page.locator('[data-membership-create]').click();
 assert.equal(await page.locator('[data-new-max-id]').count(),1,'new user MAX ID field');
 const newBox=await dialog.boundingBox();assert.ok(newBox&&newBox.x>=0&&newBox.x+newBox.width<=width+1,'create modal fits '+width);
 const overflow=await page.evaluate(()=>document.documentElement.scrollWidth-document.documentElement.clientWidth);assert.ok(overflow<=1,'no horizontal overflow '+width);
 assert.equal(errors.length,0,'page errors '+width+': '+errors.join('; '));
 const dir=process.env.PLANEX_SCREENSHOT_DIR;if(dir){fs.mkdirSync(dir,{recursive:true});await page.screenshot({path:path.join(dir,'user-max-id-'+width+'.png'),fullPage:true});}
 await page.close();console.log('USER_MAX_ID_BROWSER '+width+'=PASS');}}
finally{await browser.close();await new Promise(r=>server.close(r));}
