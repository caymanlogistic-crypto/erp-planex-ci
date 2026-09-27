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
  if(/^\/(?:erpv2\/)?company\/logistics-assistant\/\d+\/documents\/(101|102|103|201)$/.test(req.url||'')){
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
  assert.match(sheet,/dimension ref="A1:J\d+"/,'report has ten columns');
  for(const header of ['Заявка','ОО','Дата','прибытие','убытие','Скан ТН','ПОДПИСЬ ОО','Подпись ВОДИТЕЛЯ','Заполнено Дата/время','Восстановление данных'])
    assert.ok(sheet.includes(header),`report header: ${header}`);
  assert.match(sheet,/<c r="F7"[^>]*><is><t>Нет скана<\/t><\/is><\/c>/,'request without scan is marked missing');
  assert.match(sheet,/<c r="F8"[^>]*><is><t>Есть<\/t><\/is><\/c>/,'request with scan is marked present');
  assert.match(sheet,/<c r="F9"[^>]*><is><t>Нет ТН<\/t><\/is><\/c>/,'explicit absent-TN flag takes priority');
  assert.ok(sheet.includes('275006')&&sheet.includes('274405'),'report uses current request values');
  assert.ok(!sheet.includes('ЛЕГЕНДА'),'legend is absent');
  assert.ok(report.has('xl/styles.xml')&&report.has('xl/theme/theme1.xml'),'sample formatting is present');
  assert.equal(await page.locator('.lab-ready-download, .lab-report-download-state').count(),0,'report creates no extra success UI');
  const packageButton=toolbar.getByText('Скачать комплект');
  await page.route('**/documents/101',async route=>{await new Promise(resolve=>setTimeout(resolve,400));await route.continue();});
  await page.route('**/documents/102',async route=>{await new Promise(resolve=>setTimeout(resolve,400));await route.continue();});
  const packageDownloadPromise=page.waitForEvent('download',{timeout:10000});
  await packageButton.click();
  const dialog=page.locator('.lab-package-progress-dialog');
  await dialog.waitFor({state:'visible'});
  assert.equal(await dialog.locator('.modal-head .modal-title, .modal-body, .modal-foot').count(),3,'progress uses the standard ERP modal structure');
  for(const step of ['Формирование отчёта','Создание PDF файлов','Создание архива'])
    assert.ok(await dialog.getByText(step).isVisible(),`package progress shows ${step}`);
  await dialog.locator('[data-step="1"][data-state="active"]').waitFor({state:'visible'});
  assert.equal(await dialog.locator('.lab-package-progress-pdf-count').textContent(),'0 из 2','first trip counts PDF files from its own scanned requests');
  assert.equal(await dialog.getByRole('progressbar',{name:'Создание PDF файлов'}).getAttribute('aria-valuemax'),'2','bar maximum matches first trip');
  if(process.env.PLANEX_POPUP_SCREENSHOT) await page.screenshot({path:process.env.PLANEX_POPUP_SCREENSHOT});
  await page.setViewportSize({width:390,height:800});
  assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=document.documentElement.clientWidth),true,'mobile PDF progress has no overflow');
  if(process.env.PLANEX_POPUP_MOBILE_SCREENSHOT) await page.screenshot({path:process.env.PLANEX_POPUP_MOBILE_SCREENSHOT});
  await page.setViewportSize({width:1280,height:900});
  await page.waitForFunction(()=>document.querySelector('.lab-package-progress-pdf-count')?.textContent==='1 из 2');
  assert.equal(await dialog.getByRole('progressbar',{name:'Создание PDF файлов'}).getAttribute('aria-valuenow'),'1','bar advances after the first PDF');
  assert.equal(await dialog.locator('.lab-package-progress-fill').evaluate(element=>element.style.width),'50%','bar fill follows completed PDF share');
  const packageDownload=await packageDownloadPromise.catch(async error=>{
    throw new Error(`${error.message}; notice=${await page.locator('.lab-report-download-state').allTextContents()}; errors=${errors.join(';')}`);
  });
  const archive=entries(fs.readFileSync(await packageDownload.path()));
  assert.equal([...archive.keys()].filter(name=>name.endsWith('.xlsx')).length,1,'package contains one report');
  const pdfs=[...archive].filter(([name])=>name.endsWith('.pdf'));
  assert.equal(pdfs.length,2,'package contains one PDF per request with scans');
  const pages=await page.evaluate(async files=>Promise.all(files.map(async file=>(await PDFLib.PDFDocument.load(new Uint8Array(file))).getPageCount())),pdfs.map(([,data])=>[...data]));
  assert.deepEqual(pages.sort(),[1,2],'two scans of one request are merged in source order');
  assert.equal(await page.locator('.lab-ready-download, .lab-report-download-state').count(),0,'package creates no extra success UI');
  assert.equal(await page.locator('.lab-package-progress').count(),0,'progress dialog closes when download begins');
  await page.unroute('**/documents/101');
  await page.unroute('**/documents/102');
  await page.locator('input[name="hide_complete"]').uncheck();
  const secondTrip=page.locator('.logistics-trip').nth(1);
  await secondTrip.locator('summary').click();
  await page.route('**/documents/201',async route=>{await new Promise(resolve=>setTimeout(resolve,150));await route.continue();});
  const secondDownloadPromise=page.waitForEvent('download',{timeout:10000});
  await secondTrip.locator('.logistics-trip-toolbar').getByText('Скачать комплект').click();
  await dialog.locator('[data-step="1"][data-state="active"]').waitFor({state:'visible'});
  assert.equal(await dialog.locator('.lab-package-progress-pdf-count').textContent(),'0 из 1','second trip uses its own PDF count');
  assert.equal(await dialog.getByRole('progressbar',{name:'Создание PDF файлов'}).getAttribute('aria-valuemax'),'1','bar maximum changes with selected trip');
  await secondDownloadPromise;
  await page.unroute('**/documents/201');
  await page.locator('.logistics-trip').first().locator('summary').click();
  await page.route('**/documents/101',route=>route.abort());
  await packageButton.click();
  await dialog.getByText(/Не удалось подготовить комплект/).waitFor({state:'visible'});
  assert.match(await dialog.locator('.lab-package-progress-detail').textContent(),/Не удалось загрузить скан ТН/,'network error is explained in Russian');
  assert.equal(await dialog.locator('[data-step="1"][data-state="error"]').count(),1,'PDF error is shown at its stage');
  await page.setViewportSize({width:390,height:800});
  assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=document.documentElement.clientWidth),true,'mobile error dialog has no horizontal overflow');
  assert.equal(await dialog.getByRole('button',{name:'Закрыть'}).isVisible(),true,'mobile error dialog keeps close button visible');
  if(process.env.PLANEX_POPUP_ERROR_SCREENSHOT) await page.screenshot({path:process.env.PLANEX_POPUP_ERROR_SCREENSHOT});
  await dialog.getByRole('button',{name:'Закрыть'}).click();
  assert.equal(await page.locator('.lab-package-progress').count(),0,'error dialog can be closed');
  await page.unroute('**/documents/101');
  await page.locator('.logistics-trip').first().locator('.lab-docs-item').evaluateAll(items=>items.forEach(item=>item.remove()));
  await page.evaluate(()=>{
    window.__pdfLabels=[];
    window.__pdfObserver=new MutationObserver(()=>{
      const label=document.querySelector('.lab-package-progress-pdf-count')?.textContent;
      if(label && !window.__pdfLabels.includes(label)) window.__pdfLabels.push(label);
    });
    window.__pdfObserver.observe(document.body,{subtree:true,childList:true,characterData:true});
  });
  const noScanDownload=page.waitForEvent('download',{timeout:10000});
  await packageButton.click();
  await noScanDownload;
  assert.ok((await page.evaluate(()=>window.__pdfLabels)).includes('Нет сканов'),'trip without scans does not display a fixed PDF total');
  await page.evaluate(()=>window.__pdfObserver.disconnect());
  assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=document.documentElement.clientWidth),true,'no desktop overflow');
  await page.setViewportSize({width:390,height:800});
  assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=document.documentElement.clientWidth),true,'no mobile overflow');
  assert.deepEqual(errors,[],'no page errors');
  console.log('TRIP_COMPLETION_REPORT_BROWSER=PASS');
}finally{
  await browser.close();
  await new Promise(resolve=>server.close(resolve));
}
