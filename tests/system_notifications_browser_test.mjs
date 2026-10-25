import assert from 'node:assert/strict';
import fs from 'node:fs';
import http from 'node:http';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {createRequire} from 'node:module';
const require=createRequire(import.meta.url);
const {chromium}=require('playwright');
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const server=http.createServer((request,response)=>{
  const files={'/assets/css/erp-ui.css':'public/assets/css/erp-ui.css','/assets/js/system-notifications.js':'public/assets/js/system-notifications.js'};
  const file=files[request.url];
  if(file){response.setHeader('Content-Type',file.endsWith('.css')?'text/css':'application/javascript');response.end(fs.readFileSync(path.join(root,file)));return;}
  response.setHeader('Content-Type','text/html; charset=utf-8');
  response.end('<!doctype html><html lang="ru"><head><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/assets/css/erp-ui.css"><script src="/assets/js/system-notifications.js" defer></script></head><body><main><h1>Проверка уведомлений</h1></main></body></html>');
});
await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
const browser=await chromium.launch({headless:true});
try{
  for(const width of [390,1280]){
    const page=await browser.newPage({viewport:{width,height:800}});
    const errors=[];page.on('pageerror',error=>errors.push(error.message));
    await page.goto(`http://127.0.0.1:${server.address().port}/`);
    await page.evaluate(()=>{
      PlanexNotifications.show({tone:'success',message:'Блок #3 возвращён.'});
      PlanexNotifications.show({tone:'warning',message:'Следующее сообщение'});
      PlanexNotifications.show({tone:'danger',message:'Заявки не найдены.'});
    });
    await page.waitForTimeout(260);
    const toast=page.locator('.toast-stack--system .toast--system');
    assert.equal(await toast.count(),1,'Only one visible message');
    assert.equal(await toast.getAttribute('role'),'status');
    const first=await toast.evaluate(element=>({background:getComputedStyle(element).backgroundColor,border:getComputedStyle(element).borderColor,iconBorder:getComputedStyle(element.querySelector('.toast-icon')).borderWidth,right:element.getBoundingClientRect().right,top:element.getBoundingClientRect().top,bodyWidth:document.documentElement.scrollWidth,windowWidth:innerWidth}));
    assert.equal(first.background,'rgb(205, 235, 215)');
    assert.equal(first.border,'rgb(19, 18, 16)');
    assert.equal(first.iconBorder,'0px');
    assert.ok(first.top>=0 && first.right<=width && first.bodyWidth===first.windowWidth,'Top placement without horizontal overflow: '+JSON.stringify(first));
    const close=toast.getByRole('button',{name:'Закрыть уведомление'});
    await close.focus();
    assert.equal(await close.evaluate(element=>getComputedStyle(element).outlineWidth),'2px');
    await close.click();
    assert.equal(await toast.count(),1,'Manual close advances the queue immediately');
    assert.match(await toast.innerText(),/Следующее сообщение/);
    assert.equal(await toast.evaluate(element=>getComputedStyle(element).backgroundColor),'rgb(255, 241, 189)');
    await toast.getByRole('button',{name:'Закрыть уведомление'}).click();
    assert.equal(await toast.getAttribute('role'),'alert');
    assert.equal(await toast.evaluate(element=>getComputedStyle(element).backgroundColor),'rgb(255, 214, 204)');
    await toast.waitFor({state:'detached',timeout:6500});
    assert.equal(await toast.count(),0,'Auto close after five seconds');
    assert.deepEqual(errors,[]);
    await page.close();
  }
}finally{await browser.close();await new Promise(resolve=>server.close(resolve));}
console.log('SYSTEM_NOTIFICATIONS_BROWSER=PASS');
