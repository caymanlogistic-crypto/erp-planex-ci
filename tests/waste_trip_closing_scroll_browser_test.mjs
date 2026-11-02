import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {readFileSync} from 'node:fs';
import http from 'node:http';
import path from 'node:path';
import {createRequire} from 'node:module';
import {fileURLToPath} from 'node:url';

const require=createRequire(import.meta.url),{chromium}=require(process.env.PLANEX_PLAYWRIGHT_MODULE||'playwright');
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const html=execFileSync(process.env.PLANEX_PHP,[path.join(root,'tests/fixtures/trip_closing_design_lab_render.php'),'legacy'],{encoding:'utf8'});
const server=http.createServer((request,response)=>{
  if(request.url.startsWith('/assets/')){
    const file=path.join(root,'public',request.url.split('?')[0]);
    response.setHeader('Content-Type',file.endsWith('.css')?'text/css':'text/javascript');
    response.end(readFileSync(file));return;
  }
  response.setHeader('Content-Type','text/html; charset=utf-8');response.end(html);
});
await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
const browser=await chromium.launch({headless:true});
try{
  for(const width of [1280,390]){
    const page=await browser.newPage({viewport:{width,height:800}});
    await page.goto(`http://127.0.0.1:${server.address().port}/company/trips/closing-documents`);
    const content=page.locator('.content');
    await content.evaluate(element=>{element.scrollTop=800;});
    const geometry=await page.evaluate(()=>{
      const content=document.querySelector('.content');
      const toolbar=document.querySelector('.closing-page--legacy .closing-toolbar');
      return {contentTop:content.getBoundingClientRect().top,toolbarTop:toolbar.getBoundingClientRect().top,toolbarBottom:toolbar.getBoundingClientRect().bottom,scrollTop:content.scrollTop,overflow:document.documentElement.scrollWidth-innerWidth};
    });
    assert.ok(geometry.scrollTop>0,`${width}: content scrolled`);
    assert.ok(Math.abs(geometry.toolbarTop-geometry.contentTop)<=1,`${width}: filter bar touches the topbar without a gap: ${JSON.stringify(geometry)}`);
    assert.ok(geometry.toolbarBottom>geometry.toolbarTop,`${width}: filter bar is visible`);
    assert.ok(geometry.overflow<=1,`${width}: no new page overflow`);
    await page.close();
  }
  console.log('waste_trip_closing_scroll_browser: PASS');
}finally{await browser.close();await new Promise(resolve=>server.close(resolve));}
