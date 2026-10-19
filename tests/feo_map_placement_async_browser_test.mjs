import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {readFileSync} from 'node:fs';
import http from 'node:http';
import path from 'node:path';
import {createRequire} from 'node:module';
import {fileURLToPath} from 'node:url';
import {assertRegistryAsyncSearchContract} from './helpers/registry_async_search_contract.mjs';

const require=createRequire(import.meta.url),{chromium}=require(process.env.PLANEX_PLAYWRIGHT_MODULE||'playwright');
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const html=execFileSync(process.env.PLANEX_PHP||'php',[path.join(root,'tests/fixtures/feo_map_placement_canon_render.php'),'populated'],{encoding:'utf8'});
const server=http.createServer((req,res)=>{
  const url=new URL(req.url,'http://localhost');
  if(url.pathname.startsWith('/assets/')){
    const file=path.resolve(root,'public','.'+url.pathname);
    assert.ok(file.startsWith(path.join(root,'public','assets')+path.sep));
    res.setHeader('Content-Type',file.endsWith('.css')?'text/css':file.endsWith('.woff2')?'font/woff2':'text/javascript');
    res.end(readFileSync(file));return;
  }
  if(url.pathname.endsWith('/search-blocks')){
    res.setHeader('Content-Type','application/json');res.end(JSON.stringify({success:true,results:[],duplicates:[]}));return;
  }
  res.setHeader('Content-Type','text/html; charset=utf-8');res.end(html);
});
await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
const browser=await chromium.launch({headless:true});
try{
  for(const width of [390,1280,1920,3840]){
  const page=await browser.newPage({viewport:{width,height:900}});
  await page.goto(`http://127.0.0.1:${server.address().port}/erpv2/company/feo/requests/map-placement`);
  await page.waitForSelector('[data-registry-ready="true"]');
  const firstResponse={success:true,results:[
    {zayavka_id:'309552',exists_in_feo:true,blocks:[{id:109,name:'Вологодская область догруз',block_type:'addition',created_at:'2026-10-19 10:00:00'}]},
    {zayavka_id:'319172',exists_in_feo:true,blocks:[{id:109,name:'Вологодская область догруз',block_type:'addition',created_at:'2026-10-19 10:00:00'}]},
  ],duplicates:[]};
  const nextResponse={success:true,results:[{zayavka_id:'302545',exists_in_feo:true,blocks:[{id:74,name:'Площадка Сосновый Бор',block_type:'addition',created_at:'2026-10-17 10:45:00'}]}],duplicates:[]};
  await assertRegistryAsyncSearchContract(page,{
    pageId:'feo-map-placement',endpoint:'**/search-blocks?*',searchSelector:'[data-placement-block-search]',resetSelector:'.registry-ui-reset',resultsSelector:'[data-placement-block-search-results]',
    firstQuery:'309552, 319172',nextQuery:'302545',firstResponse,nextResponse,firstText:'Вологодская область догруз',nextText:'Площадка Сосновый Бор',
  });
  await page.close();
  }
  console.log('FEO_MAP_PLACEMENT_ASYNC_BROWSER=PASS late success/error reset, query change, clear, reload');
}finally{await browser.close();await new Promise(resolve=>server.close(resolve));}
