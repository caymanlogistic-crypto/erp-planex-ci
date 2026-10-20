import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { execFileSync, spawn } from 'node:child_process';
import { createServer } from 'node:net';
import { mkdtempSync, writeFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { resolve, join } from 'node:path';

const require=createRequire(import.meta.url);
const { chromium }=require('playwright');
const root=resolve(import.meta.dirname,'..');
const php=process.env.PLANEX_PHP;
assert.ok(php,'Pinned PLANEX_PHP is required');
const fixture=join(root,'tests/document_attachment_pipeline_fixture.php');
const run=mode=>execFileSync(php,[fixture,mode],{cwd:root,encoding:'utf8',maxBuffer:4*1024*1024});
const portProbe=createServer();
await new Promise(r=>portProbe.listen(0,'127.0.0.1',r));
const port=portProbe.address().port;
await new Promise(r=>portProbe.close(r));
const evidence=mkdtempSync(join(tmpdir(),'planex-document-attachment-'));
let server,browser,serverLog='';
const fields={
  contractor:{name:'ИП Полный Комплект',inn:'123456789012',contractor_type:'individual',ogrn:'123456789012345',director_full_name:'Полный Комплект ИП',director_position:'ИП',legal_address:'Тестовый адрес',bank_account:'12345678901234567890',bank_bik:'123456789',bank_name:'Тестовый банк',bank_corr_account:'12345678901234567890','contacts[0][contact_person]':'Контакт','contacts[0][phone]':'+7 900 111-22-33','contacts[0][email]':'contact@example.test'},
  driver:{full_name:'Сидоров Сидор Сидорович',phone:'+7 900 000-00-02',snils:'12345678900',passport_number:'1234567890',passport_issued_by:'Тестовое ОВД',passport_issue_date:'01.02.2020',license_number:'1234567890',license_issue_date:'03.04.2021',email:'driver@example.test','custom_doc_type[7]':'Согласие','custom_doc_type_new[11]':'Допуск'},
  vehicle:{set_type:'coupling','units[primary][plate_number]':'А126ВС77','units[primary][brand]':'КАМАЗ','units[primary][vin]':'XTC43100000000000','units[primary][capacity_tons]':'10','units[primary][volume_m3]':'20','units[secondary][plate_number]':'АА123477','units[secondary][brand]':'Тонар','units[secondary][vin]':'XTC43100000000001','units[secondary][capacity_tons]':'10','units[secondary][volume_m3]':'20','custom_doc_type[secondary][4]':'Страховка'}
};
const uploads=[
  ['contractor','predef_doc[company_card][]',['Карточка предприятия.pdf'],'Карточка предприятия'],
  ['driver','predef_doc[passport][]',['Паспорт 1.pdf','Паспорт 2.pdf','Паспорт 3.pdf'],'Паспорт'],
  ['driver','predef_doc[passport][]',['Паспорт другой контрол 1.pdf','Паспорт другой контрол 2.pdf'],'Паспорт'],
  ['driver','predef_doc[driver_license][]',['ВУ лицо.pdf','ВУ оборот.pdf'],'Водительское удостоверение'],
  ['driver','predef_doc[snils][]',['СНИЛС.pdf'],'СНИЛС'],
  ['driver','custom_doc_file[7]',['Согласие.pdf'],'Согласие'],
  ['driver','custom_doc_file[11][]',['Допуск 1.pdf','Допуск 2.pdf'],'Допуск'],
  ['vehicle','predef_doc[primary][sts][]',['СТС тягач 1.pdf','СТС тягач 2.pdf'],'СТС'],
  ['vehicle','predef_doc[primary][photo][]',['Тягач фото.pdf'],'Фотография'],
  ['vehicle','predef_doc[secondary][sts][]',['СТС прицеп.pdf'],'СТС'],
  ['vehicle','predef_doc[secondary][photo][]',['Прицеп фото.pdf'],'Фотография'],
  ['vehicle','custom_doc_file[secondary][4][]',['Страховка прицеп.pdf'],'Страховка']
];
try {
  assert.match(run('init'),/FIXTURE_READY/);
  const args=['-S',`127.0.0.1:${port}`,fixture];
  server=spawn(php,args,{cwd:root,env:process.env,windowsHide:true,stdio:['ignore','pipe','pipe']});
  server.stdout.on('data',d=>serverLog+=d);server.stderr.on('data',d=>serverLog+=d);
  writeFileSync(join(evidence,'server.json'),JSON.stringify({task_id:process.env.PLANEX_TASK_ID||'document-attachment-test',pid:server.pid,start_time:new Date().toISOString(),executable:php,args,worktree:root,port}));
  const base=`http://127.0.0.1:${port}`;
  for(let i=0;i<60;i++) { try { if((await fetch(base)).ok) break; } catch {} if(i===59) throw new Error(serverLog);await new Promise(r=>setTimeout(r,100)); }
  browser=await chromium.launch({headless:true});
  const page=await browser.newPage();
  const errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.goto(base);
  const formHtml=Object.entries(fields).map(([group,values])=>`<form id="lab-${group}-form">${Object.entries(values).map(([name,value])=>`<input name="${name}" value="${value}">`).join('')}${uploads.filter(([g])=>g===group).map(([,name])=>`<input type="file" name="${name}" ${name.endsWith('[]')?'multiple':''}>`).join('')}<input type="hidden" name="_token" value="sensitive-token"><input type="hidden" name="predef_doc_type[passport]" value="Подмена"></form>`).join('');
  const html=`<meta name="csrf-token" content="fixture-csrf"><table data-route-executors-grid><tbody></tbody></table><div id="route-executor-create-modal" class="is-open" data-workflow-scope="attachment-test"><div class="modal-head"><span class="modal-title">Карточка</span><div data-route-readiness></div></div><div id="route-executor-create-modal-content"><div data-combined-feedback></div><select id="route-executor-responsible-logist"><option value="12">Логист</option></select><input id="route-executor-trip-search" value="#285 Рейс"><datalist id="route-executor-trip-options"></datalist><input id="route-executor-trip-id" value="285">${formHtml}</div><div class="modal-required-note"></div><div class="modal-foot-actions"><button data-source-create-submit>Сохранить</button></div></div>`;
  await page.setContent(html);
  await page.addScriptTag({url:base+'/public/assets/js/route-executor-review-workflow.js'});
  await page.waitForTimeout(150);
  const expected=new Map();
  const occurrences=new Map();
  for (const [group,field,names,label] of uploads) {
    const payloads=names.map(name=>{const buffer=Buffer.from('%PDF-1.4 synthetic '+name);expected.set(name,{buffer,label,group,field});return {name,mimeType:'application/pdf',buffer};});
    const key=group+'|'+field,index=occurrences.get(key)||0;occurrences.set(key,index+1);
    await page.locator(`#lab-${group}-form input[type=file]`).evaluateAll((inputs,{field,index})=>{inputs.filter(input=>input.name===field)[index].dataset.testSelected='1';},{field,index});
    await page.locator('input[data-test-selected]').setInputFiles(payloads);
    await page.locator('input[data-test-selected]').evaluate(input=>delete input.dataset.testSelected);
  }
  const responsePromise=page.waitForResponse(r=>r.url().endsWith('/review/send') && r.request().method()==='POST');
  await page.locator('[data-source-create-submit]').click();
  const response=await responsePromise;
  const saved=await response.json();assert.equal(saved.ok,true,JSON.stringify(saved));
  assert.equal(saved.stored.length,expected.size,'Actual multipart save retains every selected file');
  assert.ok(saved.snapshot.controls.every(c=>c.type!=='hidden'),'Capture does not pull hidden secrets into the snapshot');
  const passports=saved.stored.filter(f=>f.name==='predef_doc[passport][]');
  assert.deepEqual(passports.map(f=>f.original_name),uploads.filter(u=>u[1]==='predef_doc[passport][]').flatMap(u=>u[2]));
  assert.deepEqual(passports.map(f=>f.index),[0,0,0,1,1],'Control occurrence stays stable for draft restore');
  const cardId=saved.stored[0].path.split('/')[3];
  await page.goto(base);
  await page.setContent(html);
  await page.addScriptTag({url:base+'/public/assets/js/route-executor-review-workflow.js'});
  await page.waitForFunction(()=>document.querySelector('[data-source-create-submit]')?.textContent==='Сохранить изменения');
  const restored=await page.locator('input[type=file]').evaluateAll(inputs=>inputs.flatMap(input=>[...input.files].map(file=>file.name)));
  assert.deepEqual(restored,[...expected.keys()],'Reopening a server draft restores every FileList member in source order');
  const restoredPassports=await page.locator('#lab-driver-form input[type=file]').evaluateAll(inputs=>inputs.filter(input=>input.name==='predef_doc[passport][]').map(input=>[...input.files].map(file=>file.name)));
  assert.deepEqual(restoredPassports,uploads.filter(u=>u[1]==='predef_doc[passport][]').map(u=>u[2]),'Separate controls with the same name keep independent FileLists');
  const secondResponse=page.waitForResponse(r=>r.url().endsWith('/review/send') && r.request().method()==='POST');
  await page.locator('[data-source-create-submit]').click();
  const resaved=await (await secondResponse).json();
  assert.equal(resaved.ok,true,JSON.stringify(resaved));
  assert.equal(resaved.stored.length,expected.size,'Saving a reopened draft preserves every attachment');
  const result=await page.evaluate(async ({base,cardId})=>{
    const response=await fetch(base+'/test/materialize',{method:'POST',body:new URLSearchParams({action:'materialize',card_id:cardId})});return response.json();
  },{base,cardId});
  assert.equal(result.ok,true,JSON.stringify(result));
  assert.equal(result.active_cards,0,'Successful materialization closes the accepted card');
  assert.equal(result.documents.length,expected.size,'Materializer registers every saved attachment');
  assert.deepEqual(result.documents.map(d=>d.original_name),[...expected.keys()],'Source order within document fields survives persistence');
  for(const doc of result.documents) {
    const item=expected.get(doc.original_name);assert.ok(item,doc.original_name);
    assert.equal(doc.document_type,item.label);assert.equal(doc.type_name,item.label);
    assert.equal(doc.mime_type,'application/pdf');assert.equal(Number(doc.file_size),item.buffer.length);
    assert.deepEqual(Buffer.from(doc.bytes,'base64'),item.buffer,'Stored bytes stay paired with the original name');
    assert.equal(doc.entity_type,{contractor:'contractor',driver:'driver',vehicle:'vehicle_unit'}[item.group]);
    if(item.group==='vehicle') assert.equal(result.units.find(u=>Number(u.id)===Number(doc.entity_id)).plate_number,item.field.includes('[primary]')?'А126ВС77':'АА123477');
  }
  assert.deepEqual(errors,[]);
  console.log(`DOCUMENT_ATTACHMENT_BROWSER_PHP_MYSQL=PASS files=${expected.size}`);
} finally {
  await browser?.close();
  if(server && server.exitCode===null){server.kill();await new Promise(r=>server.once('exit',r));}
  try { console.log(run('cleanup').trim()); } finally {rmSync(evidence,{recursive:true,force:true});}
}
