import assert from 'node:assert/strict';
import {createRequire} from 'node:module';
import {fileURLToPath} from 'node:url';
import path from 'node:path';

const require=createRequire(import.meta.url);
const {chromium}=require(process.env.PLANEX_PLAYWRIGHT_MODULE||'playwright');
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const browser=await chromium.launch({headless:true,...(process.env.PLANEX_CHROMIUM_PATH?{executablePath:process.env.PLANEX_CHROMIUM_PATH}:{})});
try {
  const daily=[];
  for(let i=0;i<42;i++) {
    const day=new Date(Date.UTC(2026,8,9+i)).toISOString().slice(0,10);
    daily.push({date:day,assigned:10,started:3,received:0,withdrawn:0,started_new:0});
  }
  const payload={daily,today:'2026-10-20',forecast:{formed_trip_count:2,projected_new:5}};
  for(const width of [390,1280]) {
    const page=await browser.newPage({viewport:{width,height:800},reducedMotion:'reduce'});
    await page.setContent(`<section class="manager-stats cargo-report" data-cargo-balance></section><script type="application/json" id="cargo-balance-data">${JSON.stringify(payload)}</script>`);
    for(const css of ['public/assets/css/erp-ui.css','public/assets/css/manager-statistics.css','public/assets/css/cargo-balance-statistics.css'])await page.addStyleTag({path:path.join(root,css)});
    await page.addScriptTag({path:path.join(root,'public/assets/js/cargo-balance-statistics.js')});
    assert.equal(await page.locator('tbody tr.cargo-report__plan-row').count(),1);
    assert.equal(await page.locator('tbody tr').first().innerText().then(s=>/план/i.test(s)),true);
    assert.equal(await page.locator('tbody tr').filter({hasText:'Прогноз'}).count(),0);
    assert.equal(await page.locator('tbody tr.cargo-report__plan-row').locator('td').nth(2).innerText(),'5');
    assert.equal(await page.locator('tbody tr.cargo-report__plan-row').locator('td').nth(4).innerText(),'8');
    assert.equal(await page.locator('.cargo-report__forecast-line').count(),2);
    assert.match(await page.locator('.cargo-report__forecast-line').first().evaluate(el=>getComputedStyle(el).strokeDasharray),/^7(?:px)?, 5(?:px)?$/);
    await page.locator('tbody tr.cargo-report__plan-row').focus();
    assert.equal(await page.locator('.cargo-report__popup').textContent().then(s=>s.includes('Прогноз')),true);
    await page.getByRole('tab',{name:'Месяцы'}).click();
    assert.equal(await page.locator('tbody tr.cargo-report__plan-row').count(),0);
    assert.equal(await page.locator('.cargo-report__forecast-line').count(),0);
    await page.getByRole('tab',{name:'Недели'}).click();
    assert.equal(await page.locator('tbody tr.cargo-report__plan-row').count(),1);
    assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);
    await page.close();
  }
  console.log('CARGO_BALANCE_STATISTICS_BROWSER=PASS');
} finally {await browser.close();}
