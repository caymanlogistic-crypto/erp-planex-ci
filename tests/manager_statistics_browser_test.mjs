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
const emptyHtml=execFileSync(process.env.PLANEX_PHP||'php',[path.join(root,'tests/fixtures/manager_statistics_render.php')],{
  encoding:'utf8',env:{...process.env,PLANEX_MANAGER_STATISTICS_EMPTY:'1'},
});
const server=http.createServer((req,res)=>{
  const pathname=req.url.split('?')[0];
  if(pathname==='/'||pathname==='/company/statistics/managers'){res.setHeader('Content-Type','text/html; charset=utf-8');return res.end(html);}
  if(pathname==='/empty'){res.setHeader('Content-Type','text/html; charset=utf-8');return res.end(emptyHtml);}
  const file=path.resolve(root,'public','.'+pathname);
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
  assert.equal(await page.locator('body script[src*="manager-statistics.js"]').count(),0,'removed filter script is not loaded');
  assert.equal(await page.locator('.manager-stats__role-note').count(),0,'redundant logist role note is absent');
  assert.equal(await page.locator('.page-eyebrow').count(),0,'company eyebrow is removed');
  assert.equal(await page.getByText('Период и логисты').count(),0,'period heading is removed');
  assert.equal(await page.getByText('Сбросить',{exact:true}).count(),0,'reset action is removed');
  assert.equal(await page.getByRole('button',{name:'Показать'}).count(),0,'confirmation button is removed');
  assert.equal(await page.getByText('Свой период',{exact:true}).count(),0,'custom period mode is removed');
  assert.equal(await page.getByRole('link',{name:'Открыть реестр рейсов'}).count(),0,'registry transition is removed');
  assert.equal(await page.getByRole('link',{name:'Открыть →'}).count(),0,'trip transitions are removed');
  assert.deepEqual(await page.locator('.manager-stats__segments a').allTextContents(),['Недели','Месяцы','Год'],'only three instant modes');
  assert.equal(await page.locator('.manager-stats__filters').evaluate(el=>getComputedStyle(el).borderBottomWidth),'0px','removed period header divider does not remain as an empty panel');
  const shellStyle=await page.evaluate(()=>({
    font:getComputedStyle(document.body).fontFamily,
    bodyMargin:getComputedStyle(document.body).margin,
    topbarTop:document.querySelector('.topbar').getBoundingClientRect().top,
    topbar:getComputedStyle(document.querySelector('.topbar')).display,
    sidebar:getComputedStyle(document.querySelector('.sidebar')).display,
  }));
  assert.match(shellStyle.font,/IBM Plex Sans/i,'ERP font is applied');
  assert.equal(shellStyle.bodyMargin,'0px','ERP shell has no browser-page top gap');
  assert.equal(shellStyle.topbarTop,0,'ERP topbar starts at the document top edge');
  assert.equal(shellStyle.topbar,'grid','topbar styles are applied');
  assert.equal(shellStyle.sidebar,'flex','sidebar styles are applied');
  assert.equal(await page.locator('.manager-stats__kpi').count(),4,'four KPI cards');
  assert.equal(await page.locator('.manager-stats__rank').count(),4,'manager ranking');
  assert.equal(await page.locator('.manager-stats__line-chart').count(),1,'trend chart');
  assert.equal((await page.locator('.manager-stats__period-table tbody tr').count()),8,'current week and seven previous weeks');
  assert.deepEqual(await page.locator('.manager-stats__period-table thead th').allTextContents(),['Период','Анна Белова','Борис Серов','Вера Тихая','Глеб Романов','Итого'],'total is the rightmost column');
  const totalStyle=await page.locator('.manager-stats__period-table tbody tr').first().locator('td').evaluateAll(cells=>({
    managerBackground:getComputedStyle(cells[cells.length-2]).backgroundColor,
    totalBackground:getComputedStyle(cells[cells.length-1]).backgroundColor,
    managerWeight:getComputedStyle(cells[cells.length-2]).fontWeight,
    totalWeight:getComputedStyle(cells[cells.length-1]).fontWeight,
  }));
  assert.equal(totalStyle.totalBackground,totalStyle.managerBackground,'total column has the ordinary table background');
  assert.ok(Number(totalStyle.totalWeight)>Number(totalStyle.managerWeight),'total column differs only by bold weight');
  const periodViewport=await page.locator('.manager-stats__periods .table-scroll').evaluate(element=>({client:element.clientHeight,scroll:element.scrollHeight}));
  assert.equal(periodViewport.client>=periodViewport.scroll,true,'period values must not be vertically clipped');
  assert.equal((await page.locator('.manager-stats__table tbody tr').count()),4,'manager comparison rows');
  assert.equal((await page.locator('.manager-stats__detail-table tbody tr').count()),4,'trip drill-down rows');
  const detailViewport=await page.locator('.manager-stats__details .table-scroll').evaluate(element=>({client:element.clientHeight,scroll:element.scrollHeight}));
  assert.equal(detailViewport.client>=detailViewport.scroll,true,'trip drill-down must not be vertically clipped');
  const evidence=process.env.PLANEX_BROWSER_EVIDENCE;
  if(evidence){fs.mkdirSync(path.dirname(evidence),{recursive:true});await page.screenshot({path:evidence,fullPage:true});}
  await page.getByRole('link',{name:'Месяцы'}).click();
  assert.match(page.url(),/\?mode=month&metric=trips$/,'mode click navigates immediately without confirmation');
  assert.equal(errors.length,0,'page errors: '+errors.join('; '));
  await page.setViewportSize({width:1920,height:1100});
  await page.goto('http://127.0.0.1:'+server.address().port+'/empty',{waitUntil:'networkidle'});
  assert.equal(await page.locator('.topbar').count(),1,'empty state keeps standard ERP topbar');
  assert.equal(await page.locator('.sidebar').count(),1,'empty state keeps standard ERP sidebar');
  assert.equal(await page.locator('head link[href*="manager-statistics.css"]').count(),1,'empty state keeps page stylesheet');
  assert.equal(await page.locator('.manager-stats__rank').count(),0,'empty state has no ranking rows');
  assert.equal(await page.getByText('Нет логистов для отображения.').count(),1,'empty ranking message');
  assert.equal(await page.locator('.manager-stats__periods').count(),1,'empty state renders period section after ranking');
  assert.equal(await page.locator('.manager-stats__details').count(),1,'empty state renders details section after ranking');
  assert.match((await page.locator('body').evaluate(el=>getComputedStyle(el).fontFamily)),/IBM Plex Sans/i,'empty state keeps ERP font');
  await page.setViewportSize({width:390,height:844});
  assert.equal(await page.locator('.manager-stats__kpis').evaluate(el=>getComputedStyle(el).gridTemplateColumns.split(' ').length),1,'mobile KPI stack');
  assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=document.documentElement.clientWidth),true,'no page horizontal overflow');
  console.log('MANAGER_STATISTICS_BROWSER=PASS');
}finally{await browser.close();await new Promise(resolve=>server.close(resolve));}
