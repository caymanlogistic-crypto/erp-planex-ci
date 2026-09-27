import assert from 'node:assert/strict';
import http from 'node:http';
import fs from 'node:fs';
import {createRequire} from 'node:module';
import {execFileSync} from 'node:child_process';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
const require=createRequire(import.meta.url), {chromium}=require('playwright');
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const php=process.env.PLANEX_PHP||'php';
const html=execFileSync(php,[path.join(root,'tests/fixtures/menu_navigation_render.php')],{encoding:'utf8'});
const server=http.createServer((req,res)=>{
  if(req.url==='/'){res.setHeader('Content-Type','text/html; charset=utf-8');return res.end(html);}
  const file=path.resolve(root,'public','.'+req.url.split('?')[0]);
  if(!file.startsWith(path.join(root,'public')+path.sep)||!fs.existsSync(file)){res.statusCode=404;return res.end();}
  res.setHeader('Content-Type',file.endsWith('.css')?'text/css':file.endsWith('.js')?'text/javascript':'application/octet-stream');res.end(fs.readFileSync(file));
});
await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
const browser=await chromium.launch({headless:true});
try{
  const page=await browser.newPage({viewport:{width:1366,height:900}});
  const errors=[]; page.on('pageerror',e=>errors.push(e.message));
  await page.goto('http://127.0.0.1:'+server.address().port);
  const labels=await page.locator('.sidebar .nav-label').allTextContents();
  for(const hidden of ['Сообщения MAX','Касса','Операции']) assert.equal(labels.includes(hidden),false,hidden+' must be absent from menu');
  const calendar=page.locator('.sidebar .nav-label',{hasText:'Производственный календарь'}).first();
  assert.equal(await calendar.count(),1,'production calendar missing');
  assert.equal((await calendar.locator('xpath=ancestor::div[contains(@class,"nav-group")][1]//div[contains(@class,"nav-section-label")]').innerText()).trim(),'НАСТРОЙКИ');
  const feoLabels=await page.locator('.nav-item.is-parent',{hasText:'Заявки ФЭО'}).first().evaluate(el=>Array.from(el.nextElementSibling.querySelectorAll('.nav-sub-item span'),node=>(node.textContent||'').trim()));
  assert.equal(feoLabels.indexOf('Стоимость заявок'),feoLabels.indexOf('Размещение на карте')+1,'costs must follow placement');
  const statistics=page.locator('.nav-item.is-parent',{hasText:'Статистика'}).first();
  assert.equal(await statistics.count(),1,'statistics folder missing');
  assert.equal((await statistics.locator('xpath=ancestor::div[contains(@class,"nav-group")][1]//div[contains(@class,"nav-section-label")]').innerText()).trim(),'ОПЕРАЦИИ');
  const statisticsLabels=await statistics.evaluate(el=>Array.from(el.nextElementSibling.querySelectorAll('.nav-sub-item span'),node=>(node.textContent||'').trim()));
  assert.deepEqual(statisticsLabels,['Работа логистов','Доля компаний в ФЭО','Баланс заявок']);
  await statistics.click();
  assert.equal(await statistics.evaluate(el=>el.classList.contains('is-open')&&el.nextElementSibling.classList.contains('is-open')),true,'statistics folder must open');
  await statistics.click();
  assert.equal(await statistics.evaluate(el=>el.classList.contains('is-open')||el.nextElementSibling.classList.contains('is-open')),false,'statistics folder must close');
  assert.equal(errors.length,0,'page errors: '+errors.join('; '));
  console.log('MENU_NAVIGATION_BROWSER=PASS '+feoLabels.join(' | '));
}finally{await browser.close();await new Promise(resolve=>server.close(resolve));}
