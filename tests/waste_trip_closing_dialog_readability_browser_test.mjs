import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {readFileSync} from 'node:fs';
import http from 'node:http';
import path from 'node:path';
import {createRequire} from 'node:module';
import {fileURLToPath} from 'node:url';

const require=createRequire(import.meta.url),{chromium}=require(process.env.PLANEX_PLAYWRIGHT_MODULE||'playwright');
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const html=execFileSync(process.env.PLANEX_PHP,[path.join(root,'tests/fixtures/trip_closing_design_lab_render.php')],{encoding:'utf8'});
const server=http.createServer((request,response)=>{
  if(request.url.startsWith('/assets/')){
    const file=path.join(root,'public',request.url.split('?')[0]);
    response.setHeader('Content-Type',file.endsWith('.css')?'text/css':'text/javascript');
    response.end(readFileSync(file));return;
  }
  response.setHeader('Content-Type','text/html; charset=utf-8');response.end(html);
});
await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
const browser=await chromium.launch({headless:true,...(process.env.PLANEX_BROWSER_EXECUTABLE?{executablePath:process.env.PLANEX_BROWSER_EXECUTABLE}:{})});
try{
  for(const width of [1280,390]){
    const page=await browser.newPage({viewport:{width,height:800}});
    const errors=[];page.on('pageerror',error=>errors.push(error.message));
    await page.goto(`http://127.0.0.1:${server.address().port}/company/logistics/closing-documents`);
    await page.locator('[data-report-trip-id="1"]').dblclick();
    const dialog=page.locator('[data-closing-doc-dialog]');
    assert.equal(await dialog.evaluate(el=>el.open),true,`${width}: documents open`);
    for(const text of ['Подписанный договор / заявка','Счёт','АКТ/УПД','Путевой лист','Диагностическая карта транспорта']){
      assert.equal(await dialog.getByText(text,{exact:true}).isVisible(),true,`${width}: ${text} is readable`);
    }
    const geometry=await dialog.evaluate(el=>{
      const box=node=>{const r=node.getBoundingClientRect();return {top:r.top,bottom:r.bottom,left:r.left,right:r.right,width:r.width,height:r.height};};
      const head=el.querySelector('.lab-dialog-head'),body=el.querySelector('.lab-dialog-body'),foot=el.querySelector('.lab-dialog-foot');
      return {dialog:box(el),head:box(head),body:box(body),foot:box(foot),viewport:{width:innerWidth,height:innerHeight},scrollable:body.scrollHeight>body.clientHeight,docRows:[...body.querySelectorAll('.closing-doc-row')].map(box)};
    });
    assert.ok(geometry.dialog.left>=0&&geometry.dialog.right<=width+1,`${width}: modal fits viewport`);
    assert.ok(geometry.head.bottom<=geometry.body.top+1&&geometry.body.bottom<=geometry.foot.top+1,`${width}: modal sections do not overlap`);
    assert.ok(geometry.foot.bottom<=geometry.viewport.height+1,`${width}: Close action remains in viewport`);
    if(width===1280){
      assert.ok(geometry.docRows.every(row=>row.width>600),`desktop document rows have readable width`);
      assert.equal(await page.locator('[data-closing-doc-dialog] .closing-doc-row .closing-upload').count(),4,'all standard file actions available');
    }
    assert.deepEqual(errors,[],`${width}: no browser errors`);
    await dialog.locator('[data-closing-doc-close]').last().click();
    assert.equal(await dialog.evaluate(el=>el.open),false,`${width}: Close works`);
    await page.close();
  }
  console.log('waste_trip_closing_dialog_readability_browser: PASS');
}finally{await browser.close();await new Promise(resolve=>server.close(resolve));}
