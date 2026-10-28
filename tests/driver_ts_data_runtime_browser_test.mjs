import assert from 'node:assert/strict';
import fs from 'node:fs';
import http from 'node:http';
import path from 'node:path';
import {createRequire} from 'node:module';
import {execFileSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';
import {assertRegistryTableState} from './helpers/registry_visual_contract.mjs';
const require=createRequire(import.meta.url),{chromium}=require(process.env.PLANEX_PLAYWRIGHT_MODULE||'playwright');
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const fixture=(...args)=>execFileSync(process.env.PLANEX_PHP||'php',[path.join(root,'tests/fixtures/driver_ts_data_runtime.php'),...args],{encoding:'utf8',env:{...process.env,APP_ENV:'test'}});
assert.equal(fixture('seed'),'SEEDED');
const before=fixture('fingerprint');
const server=http.createServer((request,response)=>{
 try{
  const pathname=new URL(request.url,'http://localhost').pathname;
  if(pathname.startsWith('/assets/')){
   const file=path.resolve(root,'public','.'+pathname);
   if(!file.startsWith(path.join(root,'public','assets')+path.sep)||!fs.existsSync(file))throw new Error('Invalid asset');
   response.setHeader('Content-Type',file.endsWith('.js')?'text/javascript; charset=utf-8':file.endsWith('.css')?'text/css; charset=utf-8':'application/octet-stream');response.end(fs.readFileSync(file));return;
  }
  response.setHeader('Content-Type','text/html; charset=utf-8');
  response.end(fixture(pathname.endsWith('/detail')?'detail':'render',...(pathname.endsWith('/detail')?['1']:[])));
 }catch(error){response.statusCode=500;response.end(String(error));}
});
await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
let browser;
try{
 browser=await chromium.launch({headless:true});
 for(const width of [390,1280,1920,3840]){
  const page=await browser.newPage({viewport:{width,height:900}});
  const errors=[];page.on('pageerror',error=>errors.push(error.message));
  await page.goto(`http://127.0.0.1:${server.address().port}/company/driver-ts-data`);
  await page.waitForSelector('[data-registry-ready="true"]');
  await assertRegistryTableState(page,'driver-ts-data','initial',{runtime:true});
  assert.equal(await page.locator('[data-driver-ts-id]').count(),1);
  assert.match(await page.locator('[data-driver-ts-id="1"]').textContent(),/#345, #346/);
  assert.equal(await page.getByText('Петров Пётр').count(),0);
  await page.locator('[data-driver-ts-id="1"]').dblclick();
  await page.getByText('123-456-789 00').waitFor();
  assert.deepEqual(errors,[]);await page.close();
 }
 assert.equal(fixture('fingerprint'),before,'Runtime GETs never mutate business data');
 console.log('REGISTRY_RUNTIME_PAGE=driver-ts-data');
 console.log('DRIVER_TS_RUNTIME_BROWSER_PASS');
}finally{if(browser)await browser.close();await new Promise(resolve=>server.close(resolve));fixture('clean');}
