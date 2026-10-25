import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import http from 'node:http';
import { fileURLToPath } from 'node:url';
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
const require = createRequire(import.meta.url);
const {chromium} = require('playwright');
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const render = mode => execFileSync(process.env.PLANEX_PHP || 'php',[path.join(root,'tests/fixtures/feo_transfers_render.php'),mode],{encoding:'utf8'});
const html = render('transfers'), coordinates = render('coordinates'), services=render('services'), emptyServices=render('services-empty');
const server = http.createServer((req,res) => {
  if (['/','/coordinates','/services','/services-empty'].includes(req.url)) { res.setHeader('Content-Type','text/html; charset=utf-8'); return res.end(req.url === '/' ? html : req.url === '/coordinates' ? coordinates : req.url === '/services' ? services : emptyServices); }
  const file = path.resolve(root,'public','.'+req.url.split('?')[0]);
  if (!file.startsWith(path.join(root,'public')+path.sep) || !fs.existsSync(file)) { res.statusCode=404; return res.end(); }
  res.setHeader('Content-Type',file.endsWith('.css')?'text/css':file.endsWith('.js')?'text/javascript':'application/octet-stream');
  res.end(fs.readFileSync(file));
});
await new Promise(resolve => server.listen(0,'127.0.0.1',resolve));
const browser = await chromium.launch({headless:true});
const screenshotDir = process.env.PLANEX_BROWSER_EVIDENCE;
if (screenshotDir) fs.mkdirSync(screenshotDir,{recursive:true});
try {
  const page = await browser.newPage();
  const errors=[]; page.on('pageerror',e=>errors.push(e.message));
  let createCalls=0,previewCalls=0;
  const previewPayloads=[];
  const destination={address:'Новый адрес <img src=x onerror=alert(1)>',latitude:'55.456',longitude:'37.123',quality:'Только улица, дом не определён',precision:'street'};
  const addressDestination={query:'Россия, Ямало-Ненецкий автономный округ, Новый Уренгой, Таёжная улица, 177',address:'Россия, Ямало-Ненецкий автономный округ, Новый Уренгой, Таёжная улица, 177',latitude:'66.071007',longitude:'76.560232',precision:'exact',quality:'Найден запрошенный дом, включая указанный корпус или строение.'};
  const detailFixture=[{zayavka_id:'100001',net_kg:1250,before:{mno_adres_pogruzki:'Короткий адрес',mno_d:'55',mno_sh:'37'},after:{mno_adres_pogruzki:'Россия, Ямало-Ненецкий автономный округ, Новый Уренгой, Таёжная улица, 177',mno_d:'56',mno_sh:'38'}},{zayavka_id:'100002',net_kg:0,before:{mno_adres_pogruzki:'Россия, Ханты-Мансийский автономный округ, Сургут, Промышленная улица, 4',mno_d:'61',mno_sh:'73'},after:{mno_adres_pogruzki:'Короткий итоговый адрес',mno_d:'62',mno_sh:'74'}}];
  let shownDetailItems=detailFixture;
  let shownDetailMode='request';
  let shownTargetId='100003';
  let shownAddressDestination=addressDestination;
  await page.route('**/company/feo/**',async route => {
    const url=route.request().url();
    let result;
    if(url.endsWith('/geocode')) result={success:true,geo_token:'fixture-geo',result:destination};
    else if(url.endsWith('/preview')) {previewCalls++; const payload=route.request().postData()??'';previewPayloads.push(payload);const mode=payload.match(/name="mode"\r?\n\r?\n([^\r\n]*)/)?.[1];const enteredAddress=payload.match(/name="address"\r?\n\r?\n([^\r\n]*)/)?.[1];const previewDestination=mode==='address'?{...destination,address:enteredAddress}:destination; result={success:true,preview_token:'fixture-preview',duplicates:0,preview:{count:2,destination:previewDestination,rows:[{zayavka_id:'100001',net_kg:1250,mno_adres_pogruzki:'Исходный адрес',mno_d:'55',mno_sh:'37'},{zayavka_id:'100002',net_kg:null,mno_adres_pogruzki:'Второй адрес',mno_d:null,mno_sh:null}]}};}
    else if(url.endsWith('/create')) {createCalls++; result={success:true,batch_id:19,notification:'failed'};}
    else if(url.endsWith('/json')) result={success:true,batch:{name:'Перемещение на склад',mode:shownDetailMode,source_request_id:shownDetailMode==='request'?shownTargetId:null,destination_json:JSON.stringify(shownDetailMode==='address'?shownAddressDestination:destination),created_by_name:'Владимир С.',created_at:'18.10.2026 15:30',comment:'',status:'applied',reverted_at:null},items:shownDetailItems};
    else if(url.endsWith('/revert')) result={success:false,message:'Заявка перемещена позже. Возврат всего блока остановлен.'};
    else result={success:true};
    await route.fulfill({status:result.success?200:422,contentType:'application/json',body:JSON.stringify(result)});
  });
  const url='http://127.0.0.1:'+server.address().port;
  for (const width of [1920,1366,800,390]) {
    await page.setViewportSize({width,height:900}); await page.goto(url);
    assert.equal(await page.locator('.page-title').innerText(),'Перемещение заявок');
    assert.equal(await page.getByText('Новый блок перемещения',{exact:true}).count(),1);
    assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false,'Page overflow '+width);
    await page.locator('[data-open-transfer]').first().click();
    assert.equal(await page.getByText('Вставьте один номер или список из столбца Excel. До 1000 заявок; повторы будут объединены.',{exact:true}).count(),0);
    assert.equal(await page.getByText('Заявка назначения останется без изменений.',{exact:true}).count(),0);
    assert.equal(await page.getByText('Номер заявки с нужным адресом',{exact:true}).count(),1);
    assert.equal(await page.locator('[data-create-dialog] .modal-foot [data-close-dialog]').innerText(),'Отменить');
    assert.equal(await page.locator('[data-create-dialog] .modal-foot [data-commit]').innerText(),'Создать блок и переместить заявки');
    const modalGeometry=await page.locator('[data-create-dialog]').evaluate(e=>({radius:parseFloat(getComputedStyle(e).borderRadius),title:parseFloat(getComputedStyle(e.querySelector('.modal-title')).fontSize),titleWeight:getComputedStyle(e.querySelector('.modal-title')).fontWeight,body:getComputedStyle(e.querySelector('.modal-body')).backgroundColor,foot:getComputedStyle(e.querySelector('.modal-foot')).backgroundColor,backdrop:getComputedStyle(e,'::backdrop').backgroundColor}));
    assert.ok(modalGeometry.radius<=2 && modalGeometry.title===19 && modalGeometry.titleWeight==='700','Approved modal geometry '+width);
    assert.equal(modalGeometry.body,'rgb(254, 253, 248)');
    assert.equal(modalGeometry.foot,'rgb(240, 237, 230)');
    assert.equal(modalGeometry.backdrop,'rgba(10, 9, 8, 0.58)');
    if(screenshotDir) await page.screenshot({path:path.join(screenshotDir,`transfer-form-${width}.png`),fullPage:true});
    await page.locator('[name=request_ids]').fill('100001\n100002');
    await page.locator('[name=source_request_id]').fill('100003');
    await page.locator('[data-preview-result]').waitFor({state:'visible'});
    assert.equal(await page.locator('[data-preview-summary]').count(),0,'Create preview omits request count');
    assert.deepEqual((await page.locator('[data-preview-result] thead th').allInnerTexts()).map(x=>x.toLocaleLowerCase('ru')),['заявка','вес нетто','было','станет'],'Future create preview uses «Станет»');
    assert.match(await page.locator('[data-preview-rows] tr').first().innerText(),/1\s*250 кг/);
    assert.match(await page.locator('[data-preview-rows] tr').last().innerText(),/—/);
    assert.equal(await page.locator('[data-preview-result] .registry-ui-compact-table-scroll').evaluate(e=>getComputedStyle(e).borderTopColor),'rgb(168, 161, 150)');
    assert.equal(await page.locator('[data-preview-rows] img').count(),0,'Destination HTML injection');
    assert.equal(await page.locator('[data-create-dialog]').evaluate(e=>e.scrollWidth>e.clientWidth),false,'Dialog overflow '+width);
    if(screenshotDir) await page.screenshot({path:path.join(screenshotDir,`transfer-${width}.png`),fullPage:true});
    await page.locator('[name=source_request_id]').fill('100004');
    assert.equal(await page.locator('[data-preview-result]').isVisible(),false,'Edited destination invalidates preview');
    await page.locator('[data-create-dialog] .modal-close[data-close-dialog]').click();
  }
  await page.goto(url); await page.locator('[data-open-transfer]').first().click();
  await page.locator('[name=request_ids]').fill('100001\n100002');
  await page.locator('[name=mode][value=address]').locator('..').click();
  await page.locator('[name=address]').fill('Москва, улица Примерная');
  await page.locator('[data-geocode]').click(); await page.locator('[data-geo-result]').waitFor({state:'visible'});
  assert.equal(await page.locator('[data-geo-lat]').innerText(),'55.456');
  await page.locator('[data-preview-result]').waitFor({state:'visible'});
  assert.match(previewPayloads.at(-1),/name="address"\r?\n\r?\nМосква, улица Примерная/,'Preview binds current entered address to geocode token');
  assert.match(await page.locator('[data-preview-rows] tr').first().innerText(),/Москва, улица Примерная/,'Future address is entered text');
  assert.equal(await page.locator('[data-commit]').isEnabled(),true,'Imprecise coordinate warning does not block');
  await page.locator('[data-commit]').click();
  await page.waitForFunction(()=>document.querySelector('[data-page-message]')?.textContent.includes('сохранён'));
  assert.match(await page.locator('[data-page-message]').innerText(),/сохранён.*MAX не отправлено/);
  assert.equal(createCalls,1,'One commit call');
  await page.locator('[data-batch-row] [data-detail]').click();
  await page.locator('[data-detail-dialog]').waitFor({state:'visible'});
  assert.match(await page.locator('[data-detail-dialog] .modal-title').innerText(),/^Перенос заявок #\d+$/);
  assert.equal(await page.locator('[data-detail-dialog] .transfer-detail-meta').count(),0);
  assert.equal(await page.locator('[data-detail-dialog] .registry-ui-modal-summary').count(),0,'Read-only history omits request count');
  assert.deepEqual((await page.locator('[data-detail-dialog] .page-eyebrow').allInnerTexts()),['ЗАЯВКИ','ПЕРЕМЕЩЕНЫ ПО АДРЕСУ'],'History headings use common system role');
  assert.match(await page.locator('[data-detail-dialog] [data-detail-body]').innerText(),/Вес нетто/i);
  assert.equal(await page.locator('[data-detail-dialog] .registry-ui-entity-id').first().evaluate(e=>getComputedStyle(e).fontWeight),'700');
  assert.equal(await page.locator('[data-detail-dialog] .registry-ui-entity-id').count(),3,'Request and destination IDs use the shared bold role');
  assert.deepEqual((await page.locator('[data-detail-dialog] thead th').allInnerTexts()).map(x=>x.toLocaleLowerCase('ru')),['заявка','вес нетто','было','стало'],'Completed transfer history uses «Стало»');
  for (const width of [1366,390]) {
    await page.setViewportSize({width,height:900});
    const rows=await page.locator('[data-detail-dialog] tbody tr').evaluateAll(elements=>elements.map(row=>[...row.cells].map(cell=>{
      const range=document.createRange();range.selectNodeContents(cell.firstChild);
      return {top:range.getBoundingClientRect().top,alignment:getComputedStyle(cell).verticalAlign};
    })));
    assert.equal(rows.length,2,'History includes varied address lengths');
    for (const cells of rows) {
      assert.ok(cells.every(cell=>cell.alignment==='top'),'Compact table aligns each cell to top '+width);
      assert.ok(Math.max(...cells.map(cell=>cell.top))-Math.min(...cells.map(cell=>cell.top))<=1,'ID, weight and addresses share one top line '+width);
    }
    assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false,'No document overflow in history '+width);
    if(screenshotDir) await page.locator('[data-detail-dialog]').screenshot({path:path.join(screenshotDir,`transfer-history-${width}.png`)});
  }
  await page.locator('[data-detail-dialog] [data-revert]').click();
  assert.match(await page.locator('[data-detail-body]').innerText(),/На прежние адреса и координаты вернутся заявки: 100001/);
  assert.doesNotMatch(await page.locator('[data-detail-body]').innerText(),/Если какая-либо заявка перемещена более поздним блоком/);
  assert.equal(await page.locator('[data-confirm-revert]').innerText(),'Выполнить возврат');
  await page.locator('[data-confirm-revert]').click();
  await page.locator('[data-revert-error]').waitFor({state:'visible'});
  assert.match(await page.locator('[data-revert-error]').innerText(),/Возврат всего блока остановлен/);
  await page.locator('[data-detail-dialog] .modal-close').click();
  shownDetailItems=detailFixture.slice(0,1);
  for (const [mode,target,address] of [['request',null,addressDestination],['legacy',null,addressDestination],['address',null,{query:'Адрес без результата',address:''}]]) {
    shownDetailMode=mode; shownTargetId=target; shownAddressDestination=address;
    await page.setViewportSize({width:1280,height:900});
    await page.goto(url);
    await page.locator('[data-batch-row] [data-detail]').click();
    assert.equal(await page.locator('[data-detail-dialog] .registry-ui-modal-grid--reading').count(),0,`Missing ${mode} destination does not create empty right group`);
    assert.equal(await page.locator('[data-detail-dialog] .modal-body>.registry-ui-modal-grid').evaluate(e=>e.children.length),2,`Only summary and full-width table remain for ${mode}`);
    assert.equal(await page.locator('[data-detail-dialog] tbody tr').count(),1);
    await page.locator('[data-detail-dialog] .modal-close').click();
  }
  shownTargetId='100003';
  shownAddressDestination=addressDestination;
  for (const mode of ['request','address']) {
  shownDetailMode=mode;
  for (const [variant,items] of [
    ['one',detailFixture.slice(0,1)],
    ['many',Array.from({length:24},(_,index)=>({
      ...detailFixture[index%detailFixture.length],zayavka_id:String(310500+index),
      before:{...detailFixture[index%detailFixture.length].before,mno_adres_pogruzki:`Россия, Ханты-Мансийский автономный округ, Сургут, Промышленная улица, корпус ${index+1}, строение 4, производственный участок`}
    }))]
  ]) {
    shownDetailItems=items;
    for (const width of [390,1280,1920]) {
      await page.setViewportSize({width,height:900});
      await page.goto(url);
      await page.locator('[data-batch-row] [data-detail]').click();
      const detail=page.locator('[data-detail-dialog]');
      await detail.waitFor({state:'visible'});
      const compactTableMetrics=await detail.evaluate(e=>{
        const th=e.querySelector('thead th'),td=e.querySelector('tbody td:nth-child(4)'),scroll=e.querySelector('.registry-ui-compact-table-scroll'),body=e.querySelector('.modal-body'),foot=e.querySelector('.modal-foot');
        const props=node=>{const s=getComputedStyle(node);return {paddingTop:s.paddingTop,paddingBottom:s.paddingBottom,paddingLeft:s.paddingLeft,lineHeight:s.lineHeight,height:s.height,boxSizing:s.boxSizing}};
        const groups=[...scroll.parentElement.parentElement.children].filter(node=>node!==scroll.parentElement);
        return {th:props(th),td:props(td),body:props(body),tableTopGap:scroll.getBoundingClientRect().top-Math.max(...groups.map(node=>node.getBoundingClientRect().bottom)),tableBottomGap:foot.getBoundingClientRect().top-scroll.getBoundingClientRect().bottom};
      });
      for (const cell of [compactTableMetrics.th,compactTableMetrics.td]) {
        assert.equal(cell.paddingTop,'8px',`Compact table top padding ${mode} ${variant} ${width}`);
        assert.equal(cell.paddingBottom,'8px',`Compact table bottom padding ${mode} ${variant} ${width}`);
        assert.equal(cell.paddingLeft,'10px',`Compact table side padding ${mode} ${variant} ${width}`);
        assert.equal(cell.boxSizing,'border-box',`Compact table cell sizing ${mode} ${variant} ${width}`);
      }
      assert.ok(Math.abs(compactTableMetrics.tableTopGap-8)<=1,`Compact table top gap ${mode} ${variant} ${width}`);
      assert.ok(Math.abs(compactTableMetrics.tableBottomGap-12)<=1,`Compact table footer gap ${mode} ${variant} ${width}`);
      assert.match(await detail.locator('.modal-title').innerText(),/^Перенос заявок #\d+$/,`History title ${mode} ${variant} ${width}`);
      const historyWidth=await detail.evaluate(e=>Math.round(e.getBoundingClientRect().width));
      assert.ok(width===390?historyWidth>=350&&historyWidth<=374:historyWidth===900,`History width ${variant} ${width}: ${historyWidth}`);
      assert.equal(await detail.evaluate(e=>e.scrollWidth>e.clientWidth),false,`History dialog overflow ${variant} ${width}`);
      assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false,`History page overflow ${variant} ${width}`);
      const layout=await detail.locator('.registry-ui-modal-grid--reading').evaluate(e=>{
        const left=e.children[0].getBoundingClientRect(),right=e.children[1].getBoundingClientRect(),table=e.children[2].getBoundingClientRect();
        return {left:{x:left.x,y:left.y,bottom:left.bottom},right:{x:right.x,y:right.y,bottom:right.bottom},tableY:table.y};
      });
      if(width===390) assert.ok(Math.abs(layout.left.x-layout.right.x)<=1&&layout.right.y>=layout.left.bottom,`Mobile reading order ${mode} ${variant}`);
      else assert.ok(layout.right.x>layout.left.x&&Math.abs(layout.right.y-layout.left.y)<=1,`Desktop two groups ${mode} ${variant} ${width}`);
      assert.ok(layout.tableY>=Math.max(layout.left.bottom,layout.right.bottom),`Table follows both groups ${mode} ${variant} ${width}`);
      assert.equal(await detail.locator('tbody tr').count(),items.length,`History rows ${variant} ${width}`);
      assert.equal(await detail.locator('.registry-ui-modal-summary').count(),0,`History omits count ${mode} ${variant} ${width}`);
      if(width===390) assert.equal(await detail.locator('.registry-ui-compact-table-scroll').evaluate(e=>e.scrollWidth>e.clientWidth),true,`History table scrolls horizontally ${mode} ${variant}`);
      if (mode==='address') {
        assert.equal(await detail.locator('.registry-ui-modal-geo .registry-ui-modal-text').first().innerText(),addressDestination.address,`Full entered address ${variant} ${width}`);
        assert.match(await detail.locator('.registry-ui-modal-geo').innerText(),/Широта 66\.071007 · Долгота 76\.560232/,`Coordinates ${variant} ${width}`);
        assert.equal(await detail.locator('.registry-ui-modal-geo .badge').innerText(),'Точное',`Precision badge ${variant} ${width}`);
      } else {
        assert.match(await detail.locator('[data-detail-body]').innerText(),/ПЕРЕМЕЩЕНЫ ПО АДРЕСУ\s+100003\s+Новый адрес/,`Target request and address ${variant} ${width}`);
      }
      if (variant==='many') assert.equal(await detail.locator('.registry-ui-compact-table-scroll').evaluate(e=>e.scrollHeight>e.clientHeight),true,`History table scroll ${width}`);
      if(screenshotDir) await detail.screenshot({path:path.join(screenshotDir,`transfer-history-${mode}-${variant}-${width}.png`)});
      await detail.locator('[data-revert]').click();
      assert.match(await detail.locator('.modal-title').innerText(),/^Перенос заявок #\d+$/,`Confirmation title ${mode} ${variant} ${width}`);
      assert.equal(await detail.locator('[data-confirm-revert]').innerText(),'Выполнить возврат');
      const confirmationWidth=await detail.evaluate(e=>Math.round(e.getBoundingClientRect().width));
      assert.ok(width===390?confirmationWidth>=350&&confirmationWidth<=374:confirmationWidth===640,`Confirmation width ${variant} ${width}: ${confirmationWidth}`);
      assert.equal(await detail.evaluate(e=>e.scrollWidth>e.clientWidth),false,`Confirmation dialog overflow ${variant} ${width}`);
      assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false,`Confirmation page overflow ${variant} ${width}`);
      assert.equal(await detail.locator('.registry-ui-entity-id').count(),items.length,`Confirmation lists every request ${variant} ${width}`);
      if(screenshotDir) await detail.screenshot({path:path.join(screenshotDir,`transfer-confirmation-${mode}-${variant}-${width}.png`)});
      await detail.locator('.modal-close').click();
    }
  }
  }
  shownDetailItems=Array.from({length:1000},(_,index)=>({...detailFixture[0],zayavka_id:String(310500+index)}));
  await page.setViewportSize({width:390,height:900});
  await page.goto(url);
  await page.locator('[data-batch-row] [data-detail]').click();
  await page.locator('[data-detail-dialog] [data-revert]').click();
  assert.equal(await page.locator('[data-detail-dialog] .modal-body').evaluate(e=>e.scrollHeight>e.clientHeight),true,'Long confirmation scrolls inside modal body');
  assert.equal(await page.locator('[data-detail-dialog] .registry-ui-entity-id').count(),1000,'Long confirmation preserves every ID');
  assert.equal(await page.locator('[data-detail-dialog] .modal-foot [data-confirm-revert]').isVisible(),true,'Long confirmation keeps final action visible');
  assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false,'Long confirmation has no page overflow');
  if(screenshotDir) await page.locator('[data-detail-dialog]').screenshot({path:path.join(screenshotDir,'transfer-confirmation-1000-390.png')});
  await page.locator('[data-detail-dialog] .modal-close').click();
  shownDetailItems=detailFixture;
  shownDetailMode='request';
  await page.goto(url+'/coordinates'); await page.locator('[name=address]').fill('Москва');
  await page.locator('[data-geocode]').click(); await page.locator('[data-geo-result]').waitFor({state:'visible'});
  assert.equal(await page.locator('[data-geo-lon]').innerText(),'37.123');
  assert.equal(await page.locator('[data-geo-quality-badge]').innerText(),'Низкое');
  destination.address='Россия, Кемеровская область — Кузбасс, посёлок городского типа Инской, микрорайон Технологический, 13';
  destination.precision='exact'; destination.quality='Точное совпадение дома';
  for (const width of [1920,1366,800,390]) {
    await page.setViewportSize({width,height:900}); await page.goto(url+'/coordinates');
    await page.locator('[name=address]').fill('Кемеровская область, пгт Инской, микрорайон Технологический, 13');
    await page.locator('[data-geocode]').click(); await page.locator('[data-geo-result]').waitFor({state:'visible'});
    assert.equal(await page.locator('[data-geo-quality-badge]').innerText(),'Точное');
    assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false,'Coordinates overflow '+width);
    const geometry=await page.locator('[data-geo-lat]').evaluate(e=>({font:parseFloat(getComputedStyle(e).fontSize),radius:parseFloat(getComputedStyle(e).borderRadius)}));
    assert.ok(geometry.font<=13 && geometry.radius<=2,'System field geometry');
    if(screenshotDir) await page.screenshot({path:path.join(screenshotDir,`coordinates-${width}.png`),fullPage:true});
  }

  await page.locator('[name=address]').fill('Новый запрос');
  assert.equal(await page.locator('[data-geo-result]').isVisible(),false,'Edited address invalidates geocoder result');
  const qualityColors=new Set();
  for (const [precision,label,description] of [
    ['exact','Точное','Найден запрошенный дом'],['number','Хорошее','корпус или строение отличаются'],
    ['near','Среднее','другой дом'],['range','Приблизительное','примерное положение'],
    ['street','Низкое','Найдена улица'],['other','Очень низкое','общий объект'],
    ['__proto__','Не определено','не сообщил точность']
  ]) {
    destination.precision=precision;
    await page.setViewportSize({width:1366,height:900}); await page.goto(url+'/coordinates');
    await page.locator('[name=address]').fill('Москва'); await page.locator('[data-geocode]').click();
    await page.locator('[data-geo-result]').waitFor({state:'visible'});
    assert.equal(await page.locator('[data-geo-quality-badge]').innerText(),label);
    assert.ok((await page.locator('[data-geo-quality-description]').innerText()).includes(description));
    if(precision!=='__proto__') qualityColors.add(await page.locator('[data-geo-quality-badge]').evaluate(e=>getComputedStyle(e).backgroundColor));
    if(screenshotDir) await page.screenshot({path:path.join(screenshotDir,`quality-${precision}.png`),fullPage:true});
    await page.setViewportSize({width:390,height:900});
    assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false,'Quality row overflow '+precision);
  }
  assert.equal(qualityColors.size,6,'Standalone coordinate finder retains its six existing precision colors');
  for (const route of ['/', '/coordinates']) {
    await page.goto(url+route);
    assert.equal(await page.locator('[data-geo-settings], [name=api_key]').count(),0,'No settings on FEO page');
  }
  let saveCalls=0,failSave=false;
  await page.route('**/company/settings/services/yandex',async route=>{
    saveCalls++; await route.fulfill({status:failSave?422:200,contentType:'application/json',body:JSON.stringify(failSave?{success:false,message:'Ключ не сохранён'}:{success:true})});
  });
  const recipientSaves=[]; let recipientRequestCount=0;
  const recipientRoute=async route=>{
    recipientRequestCount++;
    if(route.request().method()==='GET') {
      return route.fulfill({status:200,contentType:'application/json',body:JSON.stringify({success:true,people:[{account_id:2,name:'Гладких Карина Артуровна',email:'karina@company.test'}]})});
    }
    recipientSaves.push({url:route.request().url(),body:route.request().postData()||''});
    await route.fulfill({status:200,contentType:'application/json',body:JSON.stringify({success:true,message:'Получатели сохранены.'})});
  };
  await page.route(/\/company\/settings\/services\/(?:report|route-executor-review)-recipients(?:\/suggest)?(?:\?|$)/,recipientRoute);
  for (const width of [1366,800,390]) {
    await page.setViewportSize({width,height:900}); await page.goto(url+'/services');
    assert.equal(await page.locator('[data-recipient-settings]').count(),2,'Both recipient settings cards rendered');
    assert.equal(await page.getByRole('heading',{name:'Получатели отчётов по рейсам',exact:true}).count(),1);
    assert.equal(await page.getByRole('heading',{name:'Получатели проверки исполнителей рейса',exact:true}).count(),1);
    const recipientCardOverflow=await page.locator('[data-recipient-settings]').evaluateAll(cards=>cards.map(card=>card.scrollWidth>card.clientWidth));
    assert.deepEqual(recipientCardOverflow,[false,false],'Recipient cards fit settings grid '+width);
    assert.equal(await page.locator('[data-key-form]').isVisible(),false);
    await page.locator('[data-key-edit]').click();
    assert.equal(await page.locator('[name=api_key]').getAttribute('type'),'password');
    await page.locator('[name=api_key]').fill('synthetic-test-api-key-123');
    assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false,'Settings overflow '+width);
    if(screenshotDir) await page.screenshot({path:path.join(screenshotDir,`service-keys-${width}.png`),fullPage:true});
    await page.locator('[data-key-cancel]').click();
    assert.equal(await page.locator('[name=api_key]').inputValue(),'');
    assert.equal(saveCalls,0,'Cancel never writes');
  }
  await page.setViewportSize({width:1366,height:900}); await page.goto(url+'/services');
  const executorRecipients=page.locator('[data-recipient-kind="route-executor-review"]');
  await executorRecipients.locator('[data-recipient-person]').fill('Карина');
  await page.waitForTimeout(500);
  if (await executorRecipients.locator('.report-email-options button').count()===0) {
    throw new Error('Executor recipient suggestions missing; requests='+recipientRequestCount+'; page errors: '+errors.join(' | '));
  }
  await executorRecipients.locator('.report-email-options button').waitFor({state:'visible'});
  await executorRecipients.locator('.report-email-options button').click();
  assert.equal(await executorRecipients.locator('[data-recipient-address]').inputValue(),'karina@company.test');
  await executorRecipients.locator('.report-email-add').click();
  assert.equal(await executorRecipients.locator('.report-email-list li').count(),1);
  await executorRecipients.locator('[data-recipient-logist]').check();
  await executorRecipients.locator('[data-recipient-form] [type=submit]').click();
  await executorRecipients.locator('.report-email-save-state').getByText('Сохранено',{exact:true}).waitFor();
  assert.equal(recipientSaves.length,1,'Executor recipient settings saved once');
  assert.match(recipientSaves[0].url,/route-executor-review-recipients$/);
  assert.match(recipientSaves[0].body,/karina%40company\.test|karina@company\.test/);
  assert.match(recipientSaves[0].body,/send_responsible_logist/);
  const reportRecipients=page.locator('[data-recipient-kind="trip-report"]');
  assert.equal(await reportRecipients.locator('.report-email-list li').count(),0,'Report recipients stay independent');
  await page.goto(url+'/services-empty');
  assert.equal(await page.locator('[data-key-form]').isVisible(),true);
  await page.locator('[name=api_key]').fill('synthetic-test-api-key-123');
  failSave=true; await page.locator('[data-key-form] [type=submit]').click();
  await page.locator('[data-key-message].alert-error').waitFor({state:'visible'});
  assert.equal(await page.locator('[data-key-form]').isVisible(),true,'Failed save stays editable');
  failSave=false; await page.locator('[data-key-form] [type=submit]').click();
  await page.locator('[data-key-message].alert-success').waitFor({state:'visible'});
  assert.equal(await page.locator('[data-key-status]').innerText(),'Ключ сохранён');
  assert.equal(await page.locator('[name=api_key]').inputValue(),'','Secret cleared after save');
  assert.equal(await page.locator('[data-key-form]').isVisible(),false);
  assert.deepEqual(errors,[]);
  console.log(`FEO_TRANSFER_BROWSER=PASS synthetic API UI fixture; 1920/1366/800/390, preview invalidation, quality, XSS, create, revert conflict, coordinates; previews=${previewCalls}`);
} finally { await browser.close(); await new Promise(resolve=>server.close(resolve)); }
