import assert from 'node:assert/strict';
import fs from 'node:fs';
import http from 'node:http';
import path from 'node:path';
import {createRequire} from 'node:module';
import {execFileSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';

const require=createRequire(import.meta.url), {chromium}=require('playwright');
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const html=execFileSync(process.env.PLANEX_PHP||'php',[path.join(root,'tests/fixtures/trip_completion_render.php')],{encoding:'utf8'});
const png=Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAFklEQVQImWP8//8/AwMDEwMDAwMDAwAkBgMBmjCi+wAAAABJRU5ErkJggg==','base64');
const entries=(buffer)=>{
  const result=new Map(); let offset=0;
  while(offset+30<=buffer.length && buffer.readUInt32LE(offset)===0x04034b50){
    const size=buffer.readUInt32LE(offset+18), length=buffer.readUInt16LE(offset+26), extra=buffer.readUInt16LE(offset+28);
    const name=buffer.subarray(offset+30,offset+30+length).toString('utf8');
    const start=offset+30+length+extra;
    result.set(name,buffer.subarray(start,start+size));
    offset=start+size;
  }
  return result;
};
const server=http.createServer((req,res)=>{
  if(req.url==='/'){res.setHeader('Content-Type','text/html; charset=utf-8');return res.end(html);}
  if(/^\/(?:erpv2\/)?company\/logistics-assistant\/\d+\/documents\/(101|102|103)$/.test(req.url||'')){
    res.setHeader('Content-Type','image/png');return res.end(png);
  }
  const file=path.resolve(root,'public','.'+(req.url||'').split('?')[0]);
  if(!file.startsWith(path.join(root,'public')+path.sep)||!fs.existsSync(file)){res.statusCode=404;return res.end();}
  res.setHeader('Content-Type',file.endsWith('.css')?'text/css':file.endsWith('.js')?'application/javascript':'application/octet-stream');
  res.end(fs.readFileSync(file));
});
await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
const browser=await chromium.launch({headless:true,...(process.env.PLANEX_BROWSER_EXECUTABLE?{executablePath:process.env.PLANEX_BROWSER_EXECUTABLE}:{})});
try{
  const page=await browser.newPage({acceptDownloads:true,viewport:{width:1280,height:900}}),errors=[];
  page.on('pageerror',error=>errors.push(error.message));
  await page.goto('http://127.0.0.1:'+server.address().port,{waitUntil:'networkidle'});
  await page.locator('.logistics-trip').first().locator('summary').click();
  await page.locator('[data-request-id="11"] .lab-docs-list').evaluate(element=>element.insertAdjacentHTML('beforeend',
    '<div class="lab-docs-item" data-document-id="103"><span class="lab-docs-name">scan_02.png</span><a class="lab-docs-download" href="/erpv2/company/logistics-assistant/1/documents/103">Скачать</a></div>'));
  const toolbar=page.locator('.logistics-trip').first().locator('.logistics-trip-toolbar');
  const [reportDownload]=await Promise.all([page.waitForEvent('download',{timeout:5000}),toolbar.getByText('Сводный отчёт').click()]).catch(async error=>{
    throw new Error(`${error.message}; notice=${await page.locator('.lab-report-download-state').allTextContents()}; errors=${errors.join(';')}`);
  });
  const report=entries(fs.readFileSync(await reportDownload.path()));
  const sheet=report.get('xl/worksheets/sheet1.xml')?.toString('utf8')||'';
  assert.match(sheet,/dimension ref="A1:I\d+"/,'report has nine columns');
  for(const header of ['Заявка','ОО','Дата','прибытие','убытие','ПОДПИСЬ ОО','Подпись ВОДИТЕЛЯ','Заполнено Дата/время','Восстановление данных'])
    assert.ok(sheet.includes(header),`report header: ${header}`);
  assert.ok(sheet.includes('275006')&&sheet.includes('274405'),'report uses current request values');
  assert.ok(!sheet.includes('ЛЕГЕНДА'),'legend is absent');
  assert.ok(report.has('xl/styles.xml')&&report.has('xl/theme/theme1.xml'),'sample formatting is present');
  assert.equal(await page.locator('.lab-ready-download, .lab-report-download-state').count(),0,'report creates no extra success UI');
  const [packageDownload]=await Promise.all([page.waitForEvent('download',{timeout:10000}),toolbar.getByText('Скачать комплект').click()]).catch(async error=>{
    throw new Error(`${error.message}; notice=${await page.locator('.lab-report-download-state').allTextContents()}; errors=${errors.join(';')}`);
  });
  const archive=entries(fs.readFileSync(await packageDownload.path()));
  assert.equal([...archive.keys()].filter(name=>name.endsWith('.xlsx')).length,1,'package contains one report');
  const pdfs=[...archive].filter(([name])=>name.endsWith('.pdf'));
  assert.equal(pdfs.length,2,'package contains one PDF per request with scans');
  const pages=await page.evaluate(async files=>Promise.all(files.map(async file=>(await PDFLib.PDFDocument.load(new Uint8Array(file))).getPageCount())),pdfs.map(([,data])=>[...data]));
  assert.deepEqual(pages.sort(),[1,2],'two scans of one request are merged in source order');
  assert.equal(await page.locator('.lab-ready-download, .lab-report-download-state').count(),0,'package creates no extra success UI');
  assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=document.documentElement.clientWidth),true,'no desktop overflow');
  await page.setViewportSize({width:390,height:800});
  assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=document.documentElement.clientWidth),true,'no mobile overflow');
  assert.deepEqual(errors,[],'no page errors');
  console.log('TRIP_COMPLETION_REPORT_BROWSER=PASS');
}finally{
  await browser.close();
  await new Promise(resolve=>server.close(resolve));
}
