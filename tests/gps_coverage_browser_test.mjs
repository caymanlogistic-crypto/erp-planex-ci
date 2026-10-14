import assert from 'node:assert/strict';
import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import {createRequire} from 'node:module';
import {execFileSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';
const require=createRequire(import.meta.url),{chromium}=require('playwright');
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const html=execFileSync(process.env.PLANEX_PHP||'php',[path.join(root,'tests/fixtures/gps_coverage_render.php')],{encoding:'utf8'});
const sample={id:245,threshold_minutes:15,assessment:'calculated',pct:25,covered_seconds:900,total_seconds:3600,point_count:1,last_point_utc:'2026-10-13 09:00:00',start_utc:'2026-10-13 08:00:00',end_utc:'2026-10-13 09:00:00',segments:[['2026-10-13 08:00:00','2026-10-13 08:15:00']],gaps:[{start_utc:'2026-10-13 08:15:00',end_utc:'2026-10-13 09:00:00',seconds:2700,kind:'trailing'}]};
const server=http.createServer((req,res)=>{
  if(req.url==='/'){res.setHeader('Content-Type','text/html; charset=utf-8');return res.end(html);}
  if(req.url.includes('/coverage?')){res.setHeader('Content-Type','application/json; charset=utf-8');return res.end(JSON.stringify(sample));}
  const file=path.resolve(root,'public','.'+req.url.split('?')[0]);
  if(!file.startsWith(path.join(root,'public')+path.sep)||!fs.existsSync(file)){res.statusCode=404;return res.end();}
  res.setHeader('Content-Type',file.endsWith('.css')?'text/css':'text/javascript');res.end(fs.readFileSync(file));
});
await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
const browser=await chromium.launch({headless:true});
try{
 for(const width of [1920,390]){
  const page=await browser.newPage({viewport:{width,height:900}});const errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.goto('http://127.0.0.1:'+server.address().port);await page.locator('[data-gps-coverage]').first().getByText('25%').waitFor();
  assert.deepEqual(await page.locator('.gps-status-tab').allTextContents(),['Все рейсы 2','В пути 1','Законченные 1']);
  assert.equal(await page.locator('.gps-status-tab.is-active').count(),1);
  assert.equal(await page.locator('.gps-status-tab').nth(1).getAttribute('href'),'/company/trips/gps-coverage?status=started');
  assert.equal(await page.locator('[data-gps-empty]').isVisible(),false,'false empty state visible');
  await page.locator('[data-gps-expand]').first().click();assert.equal(await page.locator('.gps-gap').count(),1);
  assert.equal(await page.locator('.gps-detail-row').first().isVisible(),true);
  const horizontal=await page.evaluate(()=>document.documentElement.scrollWidth-innerWidth);assert.ok(horizontal<3,'outer horizontal overflow at '+width+': '+horizontal);
  if(process.env.PLANEX_GPS_SCREENSHOT_DIR){fs.mkdirSync(process.env.PLANEX_GPS_SCREENSHOT_DIR,{recursive:true});await page.screenshot({path:path.join(process.env.PLANEX_GPS_SCREENSHOT_DIR,'gps-'+width+'.png'),fullPage:true});}
  assert.deepEqual(errors,[]);await page.close();
 }
 console.log('GPS_COVERAGE_BROWSER_PASS');
}finally{await browser.close();await new Promise(resolve=>server.close(resolve));}
