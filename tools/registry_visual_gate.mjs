import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {execFileSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';
import path from 'node:path';

const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');

export function verifyVisualCoverage(stdout,expected){
 const actual=new Set([...stdout.matchAll(/^REGISTRY_VISUAL_PAGE=([a-z][a-z0-9.-]*)\r?$/gm)].map(match=>match[1]));
 assert.deepEqual([...actual].sort(),[...expected].sort(),'catalog pages must execute shared visual and exact icon contract');
}

export function verifySurfaceCoverage(stdout,pages){
 const actual=new Set([...stdout.matchAll(/^REGISTRY_SURFACE_STATE=([a-z][a-z0-9.-]*:[a-z][a-z0-9-]*)\r?$/gm)].map(match=>match[1]));
 const expected=pages.flatMap(page=>page.table_states.map(state=>`${page.id}:${state.id}`));
 assert.deepEqual([...actual].sort(),expected.sort(),'every declared registry table surface/state must execute against the visible DOM');
}

export function verifyAsyncCoverage(stdout,pages){
 const actual=new Set([...stdout.matchAll(/^REGISTRY_ASYNC_SEARCH=([a-z][a-z0-9.-]*)\r?$/gm)].map(match=>match[1]));
 const expected=pages.filter(page=>page.async_search?.applicable).map(page=>page.id);
 assert.deepEqual([...actual].sort(),expected.sort(),'every applicable async search must execute the shared delayed-response contract');
}

if(process.argv[1]&&path.resolve(process.argv[1])===fileURLToPath(import.meta.url)){
 const catalog=JSON.parse(readFileSync(path.join(root,'docs/ui/approved-components.json'),'utf8'));
 const tests=new Map();
 const pages=catalog.pages.filter(page=>page.family==='registry');
 for(const page of pages){
  assert.match(page.browser_test,/^tests\/[a-zA-Z0-9_/-]+\.mjs$/,'safe visual test path');
  if(!tests.has(page.browser_test))tests.set(page.browser_test,[]);
  tests.get(page.browser_test).push(page.id);
  if(page.async_search?.applicable){
   assert.match(page.async_search.browser_test,/^tests\/[a-zA-Z0-9_/-]+\.mjs$/,'safe async test path');
   if(!tests.has(page.async_search.browser_test))tests.set(page.async_search.browser_test,[]);
  }
 }
 assert.ok(tests.size,'registered provenance tests required');
 let aggregate='';
 for(const [test,pages] of tests){
  const stdout=execFileSync(process.execPath,[path.join(root,test)],{cwd:root,env:process.env,encoding:'utf8',timeout:180000,maxBuffer:8*1024*1024});
  process.stdout.write(stdout);aggregate+=stdout;
  if(pages.length)verifyVisualCoverage(stdout,pages);
 }
 verifySurfaceCoverage(aggregate,pages);
 verifyAsyncCoverage(aggregate,pages);
 console.log('REGISTRY_VISUAL_GATE=PASS catalog-executed page IDs match');
}
