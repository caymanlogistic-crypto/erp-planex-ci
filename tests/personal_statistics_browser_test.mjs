import assert from 'node:assert/strict';
import fs from 'node:fs';
import http from 'node:http';
import path from 'node:path';
import {createRequire} from 'node:module';
import {execFileSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';

const require=createRequire(import.meta.url),{chromium}=require('playwright');
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const html=execFileSync(process.env.PLANEX_PHP||'php',[
  path.join(root,'tests/fixtures/manager_statistics_render.php')
],{encoding:'utf8',env:{...process.env,PLANEX_MANAGER_STATISTICS_PERSONAL:'1'}});
const server=http.createServer((req,res)=>{
  const pathname=req.url.split('?')[0];
  if(pathname==='/company/statistics/work'){
    res.setHeader('Content-Type','text/html; charset=utf-8');return res.end(html);
  }
  const file=path.resolve(root,'public','.'+pathname);
  if(!file.startsWith(path.join(root,'public')+path.sep)||!fs.existsSync(file)){
    res.statusCode=404;return res.end();
  }
  res.setHeader('Content-Type',file.endsWith('.css')?'text/css':file.endsWith('.js')?'text/javascript':'application/octet-stream');
  res.end(fs.readFileSync(file));
});
await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
const browser=await chromium.launch({headless:true,...(process.env.PLANEX_CHROMIUM_PATH?{executablePath:process.env.PLANEX_CHROMIUM_PATH}:{})});
try{
  for(const width of [390,1280,1920,3840]){
    const page=await browser.newPage({viewport:{width,height:900}}),errors=[];
    page.on('pageerror',error=>errors.push(error.message));
    await page.goto(`http://127.0.0.1:${server.address().port}/company/statistics/work`,{waitUntil:'networkidle'});
    assert.equal(await page.locator('.page-title').innerText(),'Статистика работы');
    assert.equal(await page.locator('.manager-stats__kpis').count(),0,'personal page has no KPI card block');
    assert.deepEqual(await page.locator('.manager-stats__period-table thead tr').first().locator('th').allTextContents(),
      ['Период','Мои показатели','Компания']);
    assert.equal(await page.locator('.manager-stats__period-table tbody tr').count(),6);
    assert.equal((await page.locator('.manager-stats__period-table tbody tr').last().locator('strong').textContent()).trim(),'Нед. 38 · 14.09');
    assert.equal(await page.locator('.manager-stats__chart-line').count(),2);
    assert.equal(await page.locator('.manager-stats__chart-area').count(),2);
    assert.equal(await page.locator('.manager-stats__chart-node').count(),12);
    assert.ok((await page.locator('.manager-stats__chart-line.is-manager').getAttribute('d')).includes(' C'));
    assert.equal(await page.locator('.manager-stats__line-chart .grid-line').count(),5);
    assert.equal(await page.locator('.manager-stats__line-chart linearGradient').count(),2);
    assert.equal(await page.getByRole('navigation',{name:'Загрузки со склада'}).locator('a.is-active').innerText(),'Нет');
    assert.match(await page.getByRole('link',{name:'Месяцы'}).getAttribute('href'),/^\/company\/statistics\/work\?/);
    assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);
    assert.equal((await page.locator('[data-manager-statistics]').innerText()).includes('Борис Серов'),false);
    await page.locator('.manager-stats__period-popup').waitFor({state:'attached'});
    await page.locator('.manager-stats__chart-node.is-manager').first().focus();
    assert.equal(await page.locator('.manager-stats__period-popup.is-visible').count(),1);
    assert.equal(await page.locator('.manager-stats__period-popup-item').count(),2);
    assert.deepEqual(errors,[]);
    if(width===1280 && process.env.PLANEX_PERSONAL_PARITY_REFERENCE){
      const reference=await browser.newPage({viewport:{width,height:900}});
      await reference.goto(process.env.PLANEX_PERSONAL_PARITY_REFERENCE,{waitUntil:'networkidle'});
      const chart=(tab)=>tab.locator('.manager-stats__line-chart').evaluate(svg=>({
        viewBox:svg.getAttribute('viewBox'),
        lines:[...svg.querySelectorAll('.manager-stats__chart-line')].map(line=>({d:line.getAttribute('d'),stroke:line.getAttribute('stroke'),width:line.getAttribute('stroke-width')})),
        areas:[...svg.querySelectorAll('.manager-stats__chart-area')].map(area=>({d:area.getAttribute('d'),fill:area.getAttribute('fill')})),
        grid:[...svg.querySelectorAll('.grid-line')].map(line=>[line.getAttribute('x1'),line.getAttribute('y1'),line.getAttribute('x2'),line.getAttribute('y2')]),
        gradient:[...svg.querySelectorAll('linearGradient')].map(g=>[...g.querySelectorAll('stop')].map(s=>[s.getAttribute('offset'),s.getAttribute('stop-color'),s.getAttribute('stop-opacity')])),
        bounds:svg.getBoundingClientRect().toJSON(),
      }));
      const actual=await chart(page),approved=await chart(reference);
      assert.deepEqual(actual.viewBox,approved.viewBox);
      assert.deepEqual(actual.lines,approved.lines,'chart curves and strokes must match accepted graph');
      assert.deepEqual(actual.areas,approved.areas,'gradient area paths must match accepted graph');
      assert.deepEqual(actual.grid,approved.grid,'chart grid must match accepted graph');
      assert.deepEqual(actual.gradient,approved.gradient,'gradient stops must match accepted graph');
      assert.ok(Math.abs(actual.bounds.width-approved.bounds.width)<=1,'chart width matches accepted graph');
      await reference.close();
    }
    if(width===1280 && process.env.PLANEX_PERSONAL_STATISTICS_SCREENSHOT){
      await page.waitForTimeout(1200);
      await page.screenshot({path:process.env.PLANEX_PERSONAL_STATISTICS_SCREENSHOT,fullPage:true});
    }
    await page.close();
  }
  console.log('PERSONAL_STATISTICS_BROWSER=PASS');
}finally{
  await browser.close();
  await new Promise(resolve=>server.close(resolve));
}
