// Independent production acceptance: one login POST, then same-origin GET only.
// Private inputs/output live in a runner temporary directory and are encrypted by workflow.
import assert from 'node:assert/strict';
import {createRequire} from 'node:module';
import {createHash} from 'node:crypto';
import {readFileSync,writeFileSync,mkdirSync} from 'node:fs';
import {join} from 'node:path';
const {chromium}=createRequire(import.meta.url)('playwright');
const privateRoot=process.env.PLANEX_ATTACHMENT_VERIFY_DIR;
assert(privateRoot,'Private verification directory required');
const payload=JSON.parse(readFileSync(join(privateRoot,'recovery.json'),'utf8'));
const receipt=JSON.parse(readFileSync(join(privateRoot,'recovery-receipt.json'),'utf8'));
const credential=JSON.parse(process.env.PLANEX_ATTACHMENT_REVIEW_CREDENTIAL);
assert.equal(receipt.ok,true);
assert.equal(receipt.pending_restores,0);
assert.equal(receipt.pending_labels,0);
assert.equal(receipt.restore_documents.length,37);
const origin='https://plan-ex.ru';
const base=origin+'/erpv2';
const sha=data=>createHash('sha256').update(data).digest('hex');
const result={started_utc:new Date().toISOString(),widths:[390,1280,1920,3840],login:false,downloads:[],pages:[],ivanov:[],blocked_requests:0,errors:[]};
mkdirSync(join(privateRoot,'browser-evidence'),{recursive:true});
const browser=await chromium.launch({headless:true});
const context=await browser.newContext({acceptDownloads:false});
let loginPosts=0;
await context.route('**/*',async route=>{
  const request=route.request();const url=new URL(request.url());
  if(url.origin!==origin){result.blocked_requests++;return route.abort();}
  if(!['GET','HEAD'].includes(request.method())){
    if(request.method()==='POST' && url.pathname==='/erpv2/login' && loginPosts++===0)return route.continue();
    result.blocked_requests++;return route.abort();
  }
  return route.continue();
});
const page=await context.newPage();
page.on('pageerror',e=>result.errors.push(e.name));
const get=async path=>{
  assert(path.startsWith('/erpv2/'),'Only fixed application origin/path allowed');
  const response=await context.request.get(origin+path,{maxRedirects:0});
  assert.equal(response.status(),200,`GET rejected for ${path.split('?')[0]}`);
  return response;
};
try{
  await page.goto(base+'/login',{waitUntil:'domcontentloaded'});
  await page.locator('input[name="login"]').fill(credential.username);
  await page.locator('input[name="password"]').fill(credential.password);
  await Promise.all([page.waitForURL(u=>!u.pathname.endsWith('/login')) ,page.locator('form.login-form button[type="submit"]').click()]);
  assert.equal(new URL(page.url()).origin,origin);result.login=true;
  for(const restored of receipt.restore_documents){
    const expected=payload.restores[restored.position];
    assert.equal(restored.entity_type,expected.entity_type);assert.equal(Number(restored.entity_id),expected.entity_id);
    const response=await get(`/erpv2/company/documents/download?id=${Number(restored.id)}`);
    const bytes=await response.body();assert.equal(sha(bytes),expected.sha256);assert.equal(bytes.length,expected.size);
    result.downloads.push({id:restored.id,sha256:sha(bytes),bytes:bytes.length,pass:true});
  }
  const surfaces=new Map();
  for(const restored of receipt.restore_documents){
    const key=restored.entity_type+':'+restored.entity_id;
    if(!surfaces.has(key))surfaces.set(key,[]);
    surfaces.get(key).push({id:restored.id,label:restored.document_type});
  }
  for(const update of payload.label_updates){
    const doc=payload.documents_before.find(d=>Number(d.id)===Number(update.id));assert(doc);
    const key=doc.entity_type+':'+doc.entity_id;
    if(!surfaces.has(key))surfaces.set(key,[]);
    surfaces.get(key).push({id:update.id,label:update.new_type});
  }
  for(const width of result.widths){
    await page.setViewportSize({width,height:1000});
    for(const [entity,documents] of surfaces){
      const [kind,id]=entity.split(':');
      const response=await page.goto(`${base}/company/documents?entity_type=${kind}&entity_id=${id}`,{waitUntil:'networkidle'});
      assert.equal(response.status(),200);assert(!new URL(page.url()).pathname.endsWith('/login'));
      for(const doc of documents){
        const link=page.locator(`a[href$="/company/documents/download?id=${doc.id}"]`);
        assert.equal(await link.count(),1);
        const row=link.locator('xpath=ancestor::tr[1]');
        assert((await row.innerText()).includes(doc.label),'Russian label missing from document row');
      }
      result.pages.push({entity,width,verified_documents:documents.length,pass:true});
    }
    const response=await page.goto(base+'/company/drivers',{waitUntil:'networkidle'});assert.equal(response.status(),200);
    await page.locator('tr[data-driver-id="66"]').dblclick();
    const modal=page.locator('.driver-modal-docs:visible');await modal.waitFor();
    const titles=await modal.locator('.file-name').allTextContents();
    assert.equal(titles.filter(t=>t.trim()==='Паспорт').length,2);
    assert.equal(titles.filter(t=>t.trim()==='Водительское удостоверение').length,2);
    assert.equal(titles.filter(t=>t.trim()==='СНИЛС').length,1);
    await page.screenshot({path:join(privateRoot,'browser-evidence',`driver66-${width}.png`),fullPage:true});
    result.ivanov.push({width,passport:2,driver_license:2,snils:1,pass:true});
  }
  assert.equal(result.errors.length,0);result.pass=true;
}catch(error){result.pass=false;result.failure={name:error.name,message:String(error.message).slice(0,500)};process.exitCode=1;}
finally{
  result.finished_utc=new Date().toISOString();
  writeFileSync(join(privateRoot,'browser-evidence','independent-result.json'),JSON.stringify(result,null,2));
  await context.close();await browser.close();
}
console.log(result.pass?'INDEPENDENT_DOCUMENT_PRODUCTION_PASS':'INDEPENDENT_DOCUMENT_PRODUCTION_FAIL');
