import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {readFileSync} from 'node:fs';
import http from 'node:http';
import path from 'node:path';
import {createRequire} from 'node:module';
import {fileURLToPath} from 'node:url';
import {assertRegistryRows} from './helpers/registry_behavior_contract.mjs';
import {assertRegistryTableState} from './helpers/registry_visual_contract.mjs';

const require=createRequire(import.meta.url),{chromium}=require(process.env.PLANEX_PLAYWRIGHT_MODULE||'playwright');
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const fixture=(...args)=>execFileSync(process.env.PLANEX_PHP||'php',[path.join(root,'tests/fixtures/feo_map_placement_runtime.php'),...args],{encoding:'utf8',env:{...process.env,APP_ENV:'test',LEGACY_FLIGHTS_DB_HOST:'',LEGACY_FLIGHTS_DB_DATABASE:'',LEGACY_FLIGHTS_DB_USERNAME:'',LEGACY_FLIGHTS_DB_PASSWORD:''},maxBuffer:8*1024*1024});
assert.equal(fixture('seed'),'SEEDED');
const before=fixture('fingerprint'),requests=[],cache=new Map();
let actor='owner';
const server=http.createServer((req,res)=>{
  try{
    const url=new URL(req.url,'http://localhost');
    if(req.method!=='GET')throw new Error('Only GET permitted');
    if(url.pathname.startsWith('/assets/')){
      const file=path.resolve(root,'public','.'+url.pathname);
      if(!file.startsWith(path.join(root,'public','assets')+path.sep))throw new Error('Invalid asset');
      res.setHeader('Content-Type',file.endsWith('.css')?'text/css':file.endsWith('.woff2')?'font/woff2':file.endsWith('.js')?'text/javascript':'application/octet-stream');
      res.end(readFileSync(file));return;
    }
    const key=actor+':'+url.pathname+url.search;
    if(!cache.has(key))cache.set(key,JSON.parse(fixture('render',url.pathname,actor,url.searchParams.get('request_ids')||'')));
    const rendered=cache.get(key);requests.push(key);res.statusCode=rendered.status;
    res.setHeader('Content-Type',url.pathname.endsWith('/search-blocks')||url.pathname.endsWith('/json')||url.pathname.endsWith('/current')?'application/json':'text/html; charset=utf-8');
    res.end(rendered.html);
  }catch(error){res.statusCode=500;res.end(String(error));}
});
let browser;
try{
  await new Promise((resolve,reject)=>{server.once('error',reject);server.listen(0,'127.0.0.1',resolve)});
  const origin=`http://127.0.0.1:${server.address().port}`;
  browser=await chromium.launch({headless:true});
  for(actor of ['owner','member']){
    const page=await browser.newPage({viewport:{width:1280,height:900}}),errors=[];
    page.on('pageerror',error=>errors.push(error.message));
    await page.route('**/*',route=>route.request().url().startsWith(origin+'/')?route.continue():route.abort());
    await page.goto(origin+'/company/feo/requests/map-placement');
    await page.waitForSelector('[data-registry-ready="true"]');
    await assertRegistryTableState(page,'feo-map-placement','initial',{runtime:true});
    const html=await page.content();
    for(const asset of ['registry-components.css','registry-components.js','feo-map-placement.js'])assert.ok(html.includes('/assets/'+(asset.endsWith('.css')?'css/':'js/')+asset),`real controller composes ${asset}`);
    await assertRegistryRows(page,{rowSelector:'.feo-placement-table tbody tr[data-placement-block-id]',rows:[
      {id:75,cells:[['#75'],['Резервный список'],['Добавление'],['1'],['380'],['281861'],['18.10.2026 09:20'],['Анна П.'],['Отключён']]},
      {id:74,cells:[['#74'],['Площадка Сосновый Бор'],['Добавление'],['2'],['1 850,500'],['302545, 285664'],['17.10.2026 10:45'],['Анна П.'],['Применяется']]},
    ]},actor+' actual route data');
    const scope=await page.locator('.registry-ui-card').getAttribute('data-registry-state-scope');
    assert.ok(scope,'server-supplied state scope');cache.set(actor+':scope',scope);
    await page.locator('[data-placement-hide-disabled]').uncheck();
    assert.equal(await page.locator('.feo-placement-table tbody tr[data-placement-block-id]:visible').count(),2,'checkbox reveals real disabled row');
    const search=page.locator('[data-placement-block-search]');
    await search.fill('302545\n281861');
    await page.getByText('Найдено по 2 заявкам.',{exact:false}).waitFor();
    await assertRegistryTableState(page,'feo-map-placement','search-results',{runtime:true});
    assert.equal(await search.inputValue(),'302545, 281861','real search parser preserves two pasted IDs');
    assert.equal(await page.locator('[data-placement-search-block-id="74"]').count(),1,'real endpoint returns saved block');
    await page.locator('.registry-ui-reset').click();
    assert.equal(await search.inputValue(),'','real reset clears query');
    await page.getByRole('button',{name:'Создать блок'}).click();
    assert.ok(await page.locator('#feo-placement-create-modal form[action$="/map-placement/analyze"]').isVisible(),'real create form remains available');
    await page.keyboard.press('Escape');
    assert.deepEqual(errors,[],actor+' real runtime has no page errors');
    await page.close();
  }
  assert.notEqual(cache.get('owner:scope'),cache.get('member:scope'),'state scope separates identities');
  actor='denied';
  const denied=await browser.newPage();const response=await denied.goto(origin+'/company/feo/requests/map-placement');
  assert.equal(response.status(),403,'missing effective page grant is denied before route dispatch');
  assert.ok(!(await denied.content()).includes('Площадка Сосновый Бор'),'denied response contains no business rows');
  await denied.close();
  assert.equal(fixture('fingerprint'),before,'GET interactions leave business and access tables unchanged');
  assert.ok(requests.some(value=>value.includes('/search-blocks?')),'real search endpoint executed');
  console.log('REGISTRY_RUNTIME_PAGE=feo-map-placement');
  console.log('FEO_MAP_PLACEMENT_RUNTIME_BROWSER=PASS real route/controller/service/layout; owner/member GET; denied effective page gate; business fingerprint unchanged');
}finally{try{await browser?.close()}finally{if(server.listening)await new Promise(resolve=>server.close(resolve))}}
