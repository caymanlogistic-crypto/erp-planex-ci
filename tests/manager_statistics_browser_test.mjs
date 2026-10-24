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
const weightHtml=execFileSync(process.env.PLANEX_PHP||'php',[path.join(root,'tests/fixtures/manager_statistics_render.php')],{
  encoding:'utf8',env:{...process.env,PLANEX_MANAGER_STATISTICS_METRIC:'weight'},
});
const warehouseHtml=execFileSync(process.env.PLANEX_PHP||'php',[path.join(root,'tests/fixtures/manager_statistics_render.php')],{
  encoding:'utf8',env:{...process.env,PLANEX_MANAGER_STATISTICS_WAREHOUSE:'1'},
});
const server=http.createServer((req,res)=>{
  const pathname=req.url.split('?')[0];
  if(pathname==='/'||pathname==='/company/statistics/managers'){res.setHeader('Content-Type','text/html; charset=utf-8');return res.end(html);}
  if(pathname==='/empty'){res.setHeader('Content-Type','text/html; charset=utf-8');return res.end(emptyHtml);}
  if(pathname==='/weight'){res.setHeader('Content-Type','text/html; charset=utf-8');return res.end(weightHtml);}
  if(pathname==='/warehouse'){res.setHeader('Content-Type','text/html; charset=utf-8');return res.end(warehouseHtml);}
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
  assert.equal(await page.locator('body script[src*="manager-statistics.js"]').count(),1,'chart layering script is loaded');
  assert.equal(await page.locator('.manager-stats__role-note').count(),0,'redundant logist role note is absent');
  assert.equal(await page.locator('.page-eyebrow').count(),0,'company eyebrow is removed');
  assert.equal(await page.getByText('Период и логисты').count(),0,'period heading is removed');
  assert.equal(await page.getByText('Сбросить',{exact:true}).count(),0,'reset action is removed');
  assert.equal(await page.getByRole('button',{name:'Показать'}).count(),0,'confirmation button is removed');
  assert.equal(await page.getByText('Свой период',{exact:true}).count(),0,'custom period mode is removed');
  assert.equal(await page.getByRole('link',{name:'Открыть реестр рейсов'}).count(),0,'registry transition is removed');
  assert.equal(await page.getByRole('link',{name:'Открыть →'}).count(),0,'trip transitions are removed');
  assert.deepEqual(await page.locator('.manager-stats__chart-controls > *').evaluateAll(elements=>elements.map(element=>element.getAttribute('aria-label')||element.querySelector('.manager-stats__field-label')?.textContent.trim())),['Загрузки со склада','Режим отчёта','Показатель графиков'],'controls keep approved order');
  const warehouseSwitch=page.getByRole('navigation',{name:'Загрузки со склада'});
  assert.deepEqual(await warehouseSwitch.locator('a').allTextContents(),['Да','Нет'],'warehouse selector has approved labels');
  assert.equal(await warehouseSwitch.locator('a.is-active').innerText(),'Нет','warehouse loads are excluded by default');
  assert.deepEqual(await page.getByRole('navigation',{name:'Режим отчёта'}).locator('a').allTextContents(),['Недели','Месяцы','Год'],'only three instant modes');
  const modeFit=await page.getByRole('navigation',{name:'Режим отчёта'}).evaluate(element=>{
    const segment=element.getBoundingClientRect(),last=element.lastElementChild.getBoundingClientRect();
    return segment.right-last.right;
  });
  assert.ok(modeFit<=4,'report mode selector ends immediately after Year without an empty tail');
  assert.equal(await page.locator('.manager-stats__filters').count(),0,'mode selector has moved into the chart header');
  assert.equal(await page.locator('.manager-stats__trend .manager-stats__chart-controls').count(),1,'mode and metric switches share the chart header');
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
  assert.equal(await page.locator('.manager-stats__ranking').count(),0,'separate ranking is removed');
  const chartWidth=await page.locator('.manager-stats__trend').evaluate(el=>Math.round(el.getBoundingClientRect().width));
  const periodWidth=await page.locator('.manager-stats__periods').evaluate(el=>Math.round(el.getBoundingClientRect().width));
  assert.ok(Math.abs(chartWidth-periodWidth)<=1,'chart fills the full report width');
  assert.equal(await page.locator('.manager-stats__line-chart').count(),1,'trend chart');
  const chartLines=page.locator('.manager-stats__chart-line');
  assert.equal(await chartLines.count(),5,'company and four manager series');
  assert.ok((await chartLines.first().getAttribute('d')).includes(' C'),'trend paths use smooth cubic curves');
  assert.deepEqual(await page.locator('.manager-stats__chart-line.is-manager').evaluateAll(paths=>paths.map(path=>path.getAttribute('stroke'))),['#4A7A9B','#6A8A50','#B89228','#6D5B8C'],'logist palette skips red');
  assert.equal(await page.locator('.manager-stats__chart-line.is-company').getAttribute('stroke'),'#C07830','company series uses the approved copper');
  assert.equal(await page.locator('.manager-stats__line-chart title').count(),0,'native white SVG tooltips are absent');
  assert.equal(await page.locator('.manager-stats__chart-node').count(),40,'every point of every series has a circular node');
  assert.equal(await page.locator('.manager-stats__chart-node>circle').count(),40,'all graph nodes are circles');
  assert.equal(await page.locator('.manager-stats__node-tooltip').count(),0,'individual node tooltips are removed');
  assert.equal(await page.locator('.manager-stats__chart-area').count(),5,'company and all four managers have gradient fills');
  assert.deepEqual(await page.locator('.manager-stats__line-chart linearGradient').evaluateAll(gradients=>gradients.map(gradient=>gradient.querySelector('stop').getAttribute('stop-color'))),['#C07830','#4A7A9B','#6A8A50','#B89228','#6D5B8C'],'gradient colors follow the line palette');
  assert.match(await chartLines.first().evaluate(path=>getComputedStyle(path).animationName),/manager-stats-line-in/,'line appearance animation is enabled');
  await page.waitForTimeout(1000);
  const firstManagerNode=page.locator('.manager-stats__chart-node.is-manager[data-series-name="Анна Белова"][data-node-index="0"]');
  await firstManagerNode.focus();
  assert.equal(await page.locator('.manager-stats__period-popup').isVisible(),true,'keyboard focus reveals one period summary');
  assert.equal(await page.locator('.manager-stats__chart-node.is-period-active').count(),5,'focus highlights all series for the period');
  const hoverCircle=page.locator('.manager-stats__chart-node.is-company[data-node-index="7"]>circle'),hoverBox=await hoverCircle.boundingBox();
  assert.ok(hoverBox,'graph point is rendered');
  await page.mouse.move(hoverBox.x+hoverBox.width/2,hoverBox.y+hoverBox.height/2);
  assert.equal(await page.locator('.manager-stats__chart-node.is-period-active').count(),5,'plot hover highlights company and all managers');
  assert.equal(await page.locator('.manager-stats__chart-node:hover').count(),0,'points do not receive pointer hover');
  assert.equal(await page.locator('.manager-stats__period-guide.is-visible').count(),1,'plot hover shows the dashed guide');
  assert.equal(await page.locator('.manager-stats__period-popup-item').count(),5,'shared popup contains company and four managers');
  assert.equal(await page.locator('.manager-stats__period-popup-item').evaluateAll(items=>items.every(item=>getComputedStyle(item).color===getComputedStyle(item.querySelector('b')).color)) ,true,'summary values use their line colors');
  const hoverEvidence=process.env.PLANEX_BROWSER_HOVER_EVIDENCE;
  if(hoverEvidence){fs.mkdirSync(path.dirname(hoverEvidence),{recursive:true});await page.screenshot({path:hoverEvidence,fullPage:true});}
  assert.equal((await page.locator('.manager-stats__period-table tbody tr').count()),4,'zero-total periods are hidden from exact values');
  const visiblePeriods=await page.locator('.manager-stats__period-table tbody .manager-stats__period-label strong').allTextContents();
  assert.deepEqual(visiblePeriods,['Нед. 39 · 21.09','Нед. 38 · 14.09','Нед. 37 · 07.09','Нед. 36 · 31.08'],'exact values are ordered newest to oldest');
  assert.deepEqual(await page.locator('.manager-stats__period-table thead tr').first().locator('th').allTextContents(),['Период','Анна Белова','Борис Серов','Вера Тихая','Глеб Романов','Итого'],'total is the rightmost column');
  assert.deepEqual(await page.locator('.manager-stats__period-table thead tr').nth(1).locator('th').allTextContents(),Array(5).fill(['Рейсы','Заявки','Вес, т']).flat(),'every manager and total have all three metrics');
  const totalStyle=await page.locator('.manager-stats__period-table tbody tr').first().locator('td').evaluateAll(cells=>({
    managerBackground:getComputedStyle(cells[cells.length-4]).backgroundColor,
    totalBackground:getComputedStyle(cells[cells.length-1]).backgroundColor,
    managerColor:getComputedStyle(cells[cells.length-4]).color,
    totalColor:getComputedStyle(cells[cells.length-1]).color,
  }));
  assert.equal(totalStyle.totalBackground,totalStyle.managerBackground,'total column has the ordinary table background');
  assert.equal(totalStyle.managerColor,'rgb(109, 91, 140)','last logist values use the violet line color');
  assert.equal(totalStyle.totalColor,'rgb(192, 120, 48)','total values use the company line color');
  const periodViewport=await page.locator('.manager-stats__periods .table-scroll').evaluate(element=>({client:element.clientHeight,scroll:element.scrollHeight}));
  assert.equal(periodViewport.client>=periodViewport.scroll,true,'period values must not be vertically clipped');
  assert.equal(await page.locator('.manager-stats__table-card,.manager-stats__integrated-summary,.manager-stats__details').count(),0,'removed lower sections are absent');
  const periodTable=await page.locator('.manager-stats__period-table').innerText();
  const evidence=process.env.PLANEX_BROWSER_EVIDENCE;
  if(evidence){fs.mkdirSync(path.dirname(evidence),{recursive:true});await page.screenshot({path:evidence,fullPage:true});}
  await page.getByRole('link',{name:'Месяцы'}).click();
  assert.match(page.url(),/\?mode=month&metric=trips$/,'mode click navigates immediately without confirmation');
  await page.goto('http://127.0.0.1:'+server.address().port+'/warehouse',{waitUntil:'networkidle'});
  assert.equal(await page.getByRole('navigation',{name:'Загрузки со склада'}).locator('a.is-active').innerText(),'Да','warehouse fixture selects Yes');
  assert.match(await page.getByRole('link',{name:'Месяцы'}).getAttribute('href'),/warehouse=1$/,'report mode preserves warehouse selection');
  assert.match(await page.getByRole('navigation',{name:'Показатель графиков'}).getByRole('link',{name:'Вес'}).getAttribute('href'),/warehouse=1$/,'metric preserves warehouse selection');
  await page.goto('http://127.0.0.1:'+server.address().port+'/weight',{waitUntil:'networkidle'});
  assert.equal(await page.locator('.manager-stats__period-table').innerText(),periodTable,'chart metric does not change the all-metric period table');
  assert.equal(await page.locator('.manager-stats__metric-tabs a.is-active').innerText(),'Вес','weight fixture selects the graph metric');
  await page.setViewportSize({width:390,height:844});
  assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=document.documentElement.clientWidth),true,'filled mobile report has no page horizontal overflow');
  await page.locator('.manager-stats__line-chart').scrollIntoViewIfNeeded();
  const mobilePoint=await page.locator('.manager-stats__chart-node.is-company[data-node-index="7"]>circle').boundingBox();
  await page.mouse.move(mobilePoint.x+mobilePoint.width/2,mobilePoint.y+mobilePoint.height/2);
  assert.equal(await page.locator('.manager-stats__chart-node.is-period-active').count(),5,'mobile plot hover highlights all five series');
  const popupFit=await page.locator('.manager-stats__period-popup').evaluate(element=>{
    const popup=element.getBoundingClientRect(),body=element.parentElement.getBoundingClientRect();
    return {right:popup.right<=body.right,bottom:popup.bottom<=body.bottom};
  });
  assert.deepEqual(popupFit,{right:true,bottom:true},'mobile summary stays inside the chart card');
  const mobileEvidence=process.env.PLANEX_BROWSER_MOBILE_EVIDENCE;
  if(mobileEvidence){fs.mkdirSync(path.dirname(mobileEvidence),{recursive:true});await page.screenshot({path:mobileEvidence,fullPage:true});}
  assert.equal(errors.length,0,'page errors: '+errors.join('; '));
  await page.setViewportSize({width:1920,height:1100});
  await page.goto('http://127.0.0.1:'+server.address().port+'/empty',{waitUntil:'networkidle'});
  assert.equal(await page.locator('.topbar').count(),1,'empty state keeps standard ERP topbar');
  assert.equal(await page.locator('.sidebar').count(),1,'empty state keeps standard ERP sidebar');
  assert.equal(await page.locator('head link[href*="manager-statistics.css"]').count(),1,'empty state keeps page stylesheet');
  assert.equal(await page.locator('.manager-stats__chart-line.is-manager').count(),0,'empty state has no manager series');
  assert.equal(await page.locator('.manager-stats__periods').count(),1,'empty state renders period section');
  assert.equal(await page.locator('.manager-stats__details,.manager-stats__integrated-summary').count(),0,'removed sections stay absent in empty state');
  assert.match((await page.locator('body').evaluate(el=>getComputedStyle(el).fontFamily)),/IBM Plex Sans/i,'empty state keeps ERP font');
  await page.setViewportSize({width:390,height:844});
  assert.equal(await page.locator('.manager-stats__kpis').evaluate(el=>getComputedStyle(el).gridTemplateColumns.split(' ').length),1,'mobile KPI stack');
  assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=document.documentElement.clientWidth),true,'no page horizontal overflow');
  console.log('MANAGER_STATISTICS_BROWSER=PASS');
}finally{await browser.close();await new Promise(resolve=>server.close(resolve));}
