import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {createRequire} from 'node:module';
import {execFileSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';

const require=createRequire(import.meta.url),{chromium}=require('playwright');
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const html=execFileSync(process.env.PLANEX_PHP||'php',[path.join(root,'tests/fixtures/finance_received_paid_filter_render.php')],{encoding:'utf8'});
const css=fs.readFileSync(path.join(root,'public/assets/css/finance-received-paid.css'),'utf8');
const browser=await chromium.launch({headless:true,...(process.env.PLANEX_BROWSER_EXECUTABLE?{executablePath:process.env.PLANEX_BROWSER_EXECUTABLE}:{})});
try{
  const page=await browser.newPage({viewport:{width:1280,height:900}}),errors=[];
  page.on('pageerror',error=>errors.push(error.message));
  await page.setContent(html);
  await page.addStyleTag({content:css+'\nbody{margin:0}.modal-overlay{display:none}'});
  const visibleIds=()=>page.locator('.rp-trip-item:visible').evaluateAll(items=>items.map(item=>item.dataset.id));
  assert.deepEqual(await visibleIds(),['105','104','103','102','101'],'all completed trips are visible initially, sorted newest first');
  assert.deepEqual(await page.locator('.rp-trip-item').evaluateAll(items=>items.map(item=>item.dataset.paymentStatus)),['paid','unknown-rate','unpaid','underpaid','paid'],'posted payments are classified against both rates');
  await page.selectOption('#rp-payment-filter','paid');
  assert.deepEqual(await visibleIds(),['105','101'],'both sides are covered, including an explicit zero-rate side');
  await page.selectOption('#rp-payment-filter','underpaid');
  assert.deepEqual(await visibleIds(),['102'],'one side with a shortfall appears');
  await page.selectOption('#rp-payment-filter','unpaid');
  assert.deepEqual(await visibleIds(),['103'],'zero payments on both sides appear only in unpaid');
  await page.selectOption('#rp-payment-filter','unknown-rate');
  assert.deepEqual(await visibleIds(),['104'],'a missing rate is not called fully paid');
  await page.selectOption('#rp-payment-filter','paid');
  await page.selectOption('#rp-filter','closed');
  assert.deepEqual(await visibleIds(),['101'],'payment and existing state filters combine');
  await page.locator('#rp-search').fill('105');
  assert.deepEqual(await visibleIds(),[],'search combines with both filters');
  assert.equal(await page.locator('#rp-count').innerText(),'0');
  assert.equal(await page.locator('#rp-empty').isVisible(),true);
  await page.locator('#rp-search').clear();
  await page.selectOption('#rp-filter','all');
  for(const width of [1280,390]){
    await page.setViewportSize({width,height:900});
    assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),true,`no horizontal overflow at ${width}px`);
  }
  assert.deepEqual(errors,[],'filter has no browser errors');
  console.log('FINANCE_RECEIVED_PAID_PAYMENT_FILTER_BROWSER_OK');
}finally{await browser.close();}
