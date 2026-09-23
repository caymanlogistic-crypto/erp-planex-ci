import assert from 'node:assert/strict';
import fs from 'node:fs';
import http from 'node:http';
import path from 'node:path';
import {createRequire} from 'node:module';
import {execFileSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';

const require=createRequire(import.meta.url),{chromium}=require('playwright');
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const html=execFileSync(process.env.PLANEX_PHP||'php',[path.join(root,'tests/fixtures/manager_statistics_render.php')],{encoding:'utf8'});
const server=http.createServer((req,res)=>{
  if(req.url==='/'){res.setHeader('Content-Type','text/html; charset=utf-8');return res.end(html);}
  const file=path.resolve(root,'public','.'+req.url.split('?')[0]);
  if(!file.startsWith(path.join(root,'public')+path.sep)||!fs.existsSync(file)){res.statusCode=404;return res.end();}
  res.setHeader('Content-Type',file.endsWith('.css')?'text/css':file.endsWith('.js')?'text/javascript':'application/octet-stream');res.end(fs.readFileSync(file));
});
await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
const browser=await chromium.launch({
  headless:true,
  ...(process.env.PLANEX_CHROMIUM_PATH?{executablePath:process.env.PLANEX_CHROMIUM_PATH}:{}),
});
try{
  const page=await browser.newPage({viewport:{width:1920,height:1100}}),errors=[];
  page.on('pageerror',error=>errors.push(error.message));
  await page.goto('http://127.0.0.1:'+server.address().port,{waitUntil:'networkidle'});
  assert.equal(await page.locator('.topbar').count(),1,'standard ERP topbar');
  assert.equal(await page.locator('.sidebar').count(),1,'standard ERP sidebar');
  assert.equal(await page.locator('main.content.content--wide-workspace').count(),1,'standard wide workspace');
  assert.equal(await page.locator('head link[href*="manager-statistics.css"]').count(),1,'page stylesheet is loaded from head');
  assert.equal(await page.locator('body script[src*="manager-statistics.js"]').count(),1,'page script is loaded once from layout');
  assert.equal(await page.locator('.manager-stats__role-note').count(),0,'redundant logist role note is absent');
  const shellStyle=await page.evaluate(()=>({
    font:getComputedStyle(document.body).fontFamily,
    topbar:getComputedStyle(document.querySelector('.topbar')).display,
    sidebar:getComputedStyle(document.querySelector('.sidebar')).display,
  }));
  assert.match(shellStyle.font,/IBM Plex Sans/i,'ERP font is applied');
  assert.equal(shellStyle.topbar,'grid','topbar styles are applied');
  assert.equal(shellStyle.sidebar,'flex','sidebar styles are applied');
  assert.equal(await page.locator('.manager-stats__kpi').count(),4,'four KPI cards');
  assert.equal(await page.locator('.manager-stats__rank').count(),4,'manager ranking');
  assert.equal(await page.locator('.manager-stats__line-chart').count(),1,'trend chart');
  assert.equal((await page.locator('.manager-stats__period-table tbody tr').count()),12,'exact weekly values');
  const periodViewport=await page.locator('.manager-stats__periods .table-scroll').evaluate(element=>({client:element.clientHeight,scroll:element.scrollHeight}));
  assert.equal(periodViewport.client>=periodViewport.scroll,true,'period values must not be vertically clipped');
  assert.equal((await page.locator('.manager-stats__table tbody tr').count()),4,'manager comparison rows');
  assert.equal((await page.locator('.manager-stats__detail-table tbody tr').count()),4,'trip drill-down rows');
  const detailViewport=await page.locator('.manager-stats__details .table-scroll').evaluate(element=>({client:element.clientHeight,scroll:element.scrollHeight}));
  assert.equal(detailViewport.client>=detailViewport.scroll,true,'trip drill-down must not be vertically clipped');
  const evidence=process.env.PLANEX_BROWSER_EVIDENCE;
  if(evidence){fs.mkdirSync(path.dirname(evidence),{recursive:true});await page.screenshot({path:evidence,fullPage:true});}
  await page.locator('[data-manager-picker] summary').click();
  await page.locator('[data-manager-search]').fill('Вера');
  assert.equal(await page.locator('[data-manager-row]:visible').count(),1,'manager search');
  await page.locator('[data-manager-picker] summary').click();
  await page.locator('.manager-stats__segments label',{has:page.locator('input[value="custom"]')}).click();
  assert.equal(await page.locator('[data-mode-fields="custom"]').isVisible(),true,'custom period fields');
  assert.equal(await page.locator('[data-mode-fields="year"] select').first().isDisabled(),true,'inactive period controls are disabled');
  assert.equal(errors.length,0,'page errors: '+errors.join('; '));
  await page.setViewportSize({width:390,height:844});
  assert.equal(await page.locator('.manager-stats__kpis').evaluate(el=>getComputedStyle(el).gridTemplateColumns.split(' ').length),1,'mobile KPI stack');
  assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=document.documentElement.clientWidth),true,'no page horizontal overflow');
  console.log('MANAGER_STATISTICS_BROWSER=PASS');
}finally{await browser.close();await new Promise(resolve=>server.close(resolve));}
