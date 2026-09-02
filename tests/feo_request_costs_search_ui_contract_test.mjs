import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import {setTimeout as delay} from 'node:timers/promises';

const root=path.resolve(path.dirname(new URL(import.meta.url).pathname),'..');
const jsPath=path.join(root,'public/assets/js/feo-request-costs.js');
const source=fs.readFileSync(jsPath,'utf8');

class FakeClassList {
  constructor(initial=[]){this.values=new Set(initial);}
  add(...names){names.forEach(name=>this.values.add(name));}
  remove(...names){names.forEach(name=>this.values.delete(name));}
  toggle(name,force){
    if(force===undefined){if(this.values.has(name)){this.values.delete(name);return false;}this.values.add(name);return true;}
    if(force)this.values.add(name);else this.values.delete(name);return !!force;
  }
  contains(name){return this.values.has(name);}
}

class FakeEvent {
  constructor(type,options={}){this.type=type;Object.assign(this,options);this.defaultPrevented=false;}
  preventDefault(){this.defaultPrevented=true;}
  stopPropagation(){}
}

class FakeElement {
  constructor(){this.value='';this.innerHTML='';this.textContent='';this.dataset={};this.listeners=new Map();this.classList=new FakeClassList();this.children=[];this.disabled=false;}
  addEventListener(type,fn){if(!this.listeners.has(type))this.listeners.set(type,[]);this.listeners.get(type).push(fn);}
  dispatchEvent(event){event.target??=this;for(const fn of this.listeners.get(event.type)||[])fn(event);return !event.defaultPrevented;}
  fire(type,props={}){const event=new FakeEvent(type,props);event.target=this;this.dispatchEvent(event);return event;}
  querySelector(){return null;}
  querySelectorAll(){return [];}
  replaceChildren(...children){this.children=[...children];this.innerHTML='';this.textContent='';}
  appendChild(child){this.children.push(child);}
  setAttribute(){}
  focus(){}
  reset(){}
}

const searchInput=new FakeElement();
const searchResults=new FakeElement();
searchResults.classList.add('is-hidden');

const documentStub={
  querySelector(selector){
    if(selector==='[data-cost-search]')return searchInput;
    if(selector==='[data-cost-search-results]')return searchResults;
    return null;
  },
  querySelectorAll(){return [];},
  addEventListener(){},
  createElement(){return new FakeElement();},
  createTextNode(text){return {textContent:String(text)};},
};

const fetchCalls=[];
let delayedCall=null;
let fetchMode='immediate';

function idsFromUrl(url){return new URL(String(url),'http://local').searchParams.get('request_ids')||'';}
function resultForId(id){
  if(id==='257419')return {zayavka_id:id,exists_in_feo:true,is_current:true,block:{block_id:17,pricing_type:'agreement',snapshot_request_count:1,snapshot_total_mass_kg:1250,total_cost:111.11}};
  if(id==='257551')return {zayavka_id:id,exists_in_feo:true,is_current:true,block:{block_id:29,pricing_type:'rate_38',snapshot_request_count:2,snapshot_total_mass_kg:800,total_cost:222.22}};
  return {zayavka_id:id,exists_in_feo:id!=='999999',is_current:id!=='999999',block:null};
}
function responseFor(url){
  const ids=idsFromUrl(url).split(/\s+/).filter(Boolean);
  return {success:true,duplicates:[],results:ids.map(resultForId)};
}
function responseObject(payload){return {ok:true,async json(){return payload;}};}
async function fetchStub(url,opts={}){
  const call={url:String(url),opts};
  fetchCalls.push(call);
  if(fetchMode==='delayed'){
    return await new Promise(resolve=>{delayedCall={call,resolve};});
  }
  return responseObject(responseFor(url));
}

const sandbox={
  window:null,
  document:documentStub,
  fetch:fetchStub,
  AbortController,
  URLSearchParams,
  Intl,
  FormData,
  Event:FakeEvent,
  requestAnimationFrame:fn=>setTimeout(fn,0),
  setTimeout,
  clearTimeout,
  console,
  location:{reload(){}},
};
sandbox.window=sandbox;
sandbox.FEO_REQUEST_COST_CONFIG={base:'/erpv2/company/feo/requests/costs',csrf:'csrf-test'};
vm.createContext(sandbox);
vm.runInContext(source,sandbox,{filename:'feo-request-costs.js'});

async function search(value){
  const before=fetchCalls.length;
  searchInput.value=value;
  searchInput.fire('keydown',{key:'Enter'});
  await delay(10);
  return fetchCalls.slice(before);
}
function normalized(call){return idsFromUrl(call.url);}

for(const [label,input,expected] of [
  ['single','257419','257419'],
  ['spaces','257419 281469 257551','257419 281469 257551'],
  ['commas','257419, 281469, 257551','257419 281469 257551'],
  ['multiline','257419\n281469\n257551','257419 281469 257551'],
  ['exact multiline','257419\n\n281469\n257551','257419 281469 257551'],
  ['arbitrary text','Заявки: 257419, затем 281469 и отдельно 257551.','257419 281469 257551'],
  ['TSV first column','257419\t999999\n281469\t888888\n257551\t777777','257419 281469 257551'],
  ['Markdown first column','| 257419 | 999999 |\n| 281469 | 888888 |\n| 257551 | 777777 |','257419 281469 257551'],
  ['duplicates','257419 257419 281469 257551 281469','257419 281469 257551'],
]){
  const calls=await search(input);
  assert.equal(calls.length,1,`${label}: exactly one request expected`);
  assert.equal(normalized(calls[0]),expected,`${label}: normalized request_ids mismatch`);
}

const exactCalls=await search('257419\n\n281469\n257551');
assert.equal(normalized(exactCalls[0]),'257419 281469 257551','exact multiline must never concatenate IDs');
assert.match(searchResults.innerHTML,/257419/,'render must include first ID');
assert.match(searchResults.innerHTML,/281469/,'render must include second ID');
assert.match(searchResults.innerHTML,/257551/,'render must include third ID');
assert.match(searchResults.innerHTML,/#17/,'render must map 257419 to its block');
assert.match(searchResults.innerHTML,/111,11 ₽/,'render must map 257419 to its cost');
assert.match(searchResults.innerHTML,/#29/,'render must map 257551 to its block');
assert.match(searchResults.innerHTML,/222,22 ₽/,'render must map 257551 to its cost');
assert.match(searchResults.innerHTML,/Стоимость не назначена/,'unpriced request must render separately');

const beforeInvalid=fetchCalls.length;
searchInput.value='нет валидных номеров 12345 и 1234567';
searchInput.fire('input');
await delay(10);
assert.equal(fetchCalls.length,beforeInvalid,'invalid input must not send a network request');
assert.match(searchResults.innerHTML,/Не найдено самостоятельных шестизначных номеров заявок/,'invalid state must be explicit');

const pasteEvent=new FakeEvent('paste',{clipboardData:{getData:()=> '257419\n\n281469\n257551'}});
searchInput.value='';
searchInput.dispatchEvent(pasteEvent);
assert.equal(pasteEvent.defaultPrevented,true,'valid paste should be normalized by page UX');
assert.equal(searchInput.value,'257419\n281469\n257551','paste UX should preserve visible separators in textarea');

// Required stale-response regression: a valid request is in flight, then the field
// becomes invalid. The invalid input must abort/invalidate the old generation, and
// even a transport that resolves after abort must not overwrite the invalid UI.
fetchMode='delayed';
const staleBefore=fetchCalls.length;
searchInput.value='257419';
searchInput.fire('keydown',{key:'Enter'});
await delay(10);
assert.equal(fetchCalls.length,staleBefore+1,'stale scenario must start one valid request');
assert.ok(delayedCall,'stale scenario must capture delayed request');
const delayed=delayedCall;
searchInput.value='invalid input';
searchInput.fire('input');
assert.equal(delayed.call.opts.signal.aborted,true,'invalid input must abort the in-flight request immediately');
const invalidHtml=searchResults.innerHTML;
assert.match(invalidHtml,/Не найдено самостоятельных шестизначных номеров заявок/,'invalid state must be rendered before stale response resolves');
delayed.resolve(responseObject({success:true,duplicates:[],results:[resultForId('257419')]}));
await delay(10);
assert.equal(searchResults.innerHTML,invalidHtml,'late stale response must not replace invalid state');
assert.equal(fetchCalls.length,staleBefore+1,'invalid state after delayed request must not create another request');
fetchMode='immediate';

afterEachCleanup();
console.log('FEO_REQUEST_COSTS_SEARCH_UI_CONTRACT=PASS');
console.log('FEO_REQUEST_COSTS_STALE_RESPONSE=PASS');

function afterEachCleanup(){
  searchInput.value='';
  searchInput.fire('input');
}
