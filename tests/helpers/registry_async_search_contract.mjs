import assert from 'node:assert/strict';
import {assertRegistryTableState} from './registry_visual_contract.mjs';

const pause=ms=>new Promise(resolve=>setTimeout(resolve,ms));

// The delayed responses are held by the browser's request router. The page
// still executes its production search code and renders the resulting DOM.
export async function assertRegistryAsyncSearchContract(page,{
  pageId,endpoint,searchSelector,resetSelector,resultsSelector,firstQuery,nextQuery,
  firstResponse,nextResponse,firstText,nextText,
}) {
  const held=[];
  await page.route(endpoint,route=>{
    held.push({route,query:new URL(route.request().url()).searchParams.get('request_ids')});
  });
  const take=async query=>{
    for(let attempt=0;attempt<100;attempt++){
      const index=held.findIndex(item=>item.query===query);
      if(index!==-1)return held.splice(index,1)[0].route;
      await pause(20);
    }
    throw new Error(`${pageId}: search GET was not issued for ${query}`);
  };
  const release=async(route,payload,status=200)=>{
    try{await route.fulfill({status,contentType:'application/json',body:JSON.stringify(payload)});}
    catch(error){
      // A correct client may already have aborted this obsolete browser request.
      if(!/abort|closed|canceled|cancelled|intercept/i.test(String(error)))throw error;
    }
  };
  const search=page.locator(searchSelector),reset=page.locator(resetSelector),results=page.locator(resultsSelector);
  const stored=()=>page.evaluate(id=>{
    const root=document.querySelector(`[data-registry-id="${id}"]`);
    const key=`planex:registry:v1:${location.pathname}:${id}:${root.dataset.registryStateScope}`;
    return JSON.parse(localStorage.getItem(key)||'null');
  },pageId);
  const empty=async label=>{
    assert.equal(await search.inputValue(),'',`${label}: empty input`);
    assert.equal(await reset.isVisible(),false,`${label}: reset button hidden`);
    assert.equal(await results.isVisible(),false,`${label}: old result surface hidden`);
    assert.equal((await stored())?.query,'',`${label}: persisted query empty`);
  };

  await search.fill(firstQuery);
  const first=await take(firstQuery);
  await assertRegistryTableState(page,pageId,'search-pending');
  await reset.click();
  await pause(350); // Let any queued empty-query branch run before the late reply.
  await empty('reset while GET pending');
  await assertRegistryTableState(page,pageId,'reset');
  await release(first,firstResponse);
  await pause(100);
  await empty('late success after reset');
  assert.equal(await results.getByText(firstText,{exact:true}).count(),0,'obsolete successful rows never return');

  await search.fill(firstQuery);
  const failed=await take(firstQuery);
  await reset.click();await pause(350);
  await release(failed,{success:false,message:'Поздняя ошибка поиска'},500);
  await pause(100);
  await empty('late error after reset');
  assert.equal(await results.getByText('Поздняя ошибка поиска',{exact:true}).count(),0,'obsolete error never returns');

  await search.fill(firstQuery);
  const obsolete=await take(firstQuery);
  await search.fill(nextQuery);
  await release(obsolete,firstResponse);await pause(50);
  assert.equal(await results.getByText(firstText,{exact:true}).count(),0,'old query cannot flash during new-query debounce');
  const current=await take(nextQuery);
  await release(current,nextResponse);
  await results.getByText(nextText,{exact:true}).waitFor();
  await assertRegistryTableState(page,pageId,'search-results');
  assert.equal(await results.getByText(firstText,{exact:true}).count(),0,'new query owns result surface');
  assert.equal((await stored())?.query,nextQuery,'new query is persisted');

  await search.fill(firstQuery);
  const emptyResponse=await take(firstQuery);
  await release(emptyResponse,{success:true,results:[],duplicates:[]});
  await results.getByText('Найдено по 0 заявкам.',{exact:false}).waitFor();
  await assertRegistryTableState(page,pageId,'search-empty');

  await search.fill(nextQuery);
  const errorResponse=await take(nextQuery);
  await release(errorResponse,{success:false,message:'Контрольная ошибка поиска'},500);
  await results.getByText('Контрольная ошибка поиска',{exact:false}).waitFor();
  await assertRegistryTableState(page,pageId,'search-error');

  await search.fill('');await pause(350);await empty('manual clear');
  await assertRegistryTableState(page,pageId,'reset');
  await page.reload();await page.waitForSelector('[data-registry-ready="true"]');
  await empty('reload after clear');
  await assertRegistryTableState(page,pageId,'reload');
  await page.unroute(endpoint);
  console.log(`REGISTRY_ASYNC_SEARCH=${pageId}`);
}
