import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {execFileSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';
import path from 'node:path';

import {verifyMatrix,verifyAsyncMatrix} from './registry_matrix.mjs';

const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');

export function verifyVisualCoverage(stdout,expected){
 const actual=new Set([...stdout.matchAll(/^REGISTRY_VISUAL_PAGE=([a-z][a-z0-9.-]*)\r?$/gm)].map(match=>match[1]));
 assert.deepEqual([...actual].sort(),[...expected].sort(),'catalog pages must execute shared visual and exact icon contract');
}

export function verifySurfaceCoverage(stdout,pages){
 verifyMatrix(stdout,pages,'REGISTRY_SURFACE_STATE');
}

export function verifyAsyncCoverage(stdout,pages){
 verifyAsyncMatrix(stdout,pages);
}

export function runRequiredContracts(pages,{directory=root,php=process.env.PLANEX_PHP||'php'}={}) {
 const contracts=pages.flatMap(page=>page.required_contracts||[]);
 assert.ok(Array.isArray(contracts),'required contracts inventory');
 for(const test of new Set(contracts)){
  assert.match(test,/^tests\/[a-zA-Z0-9_/-]+_test\.php$/,'safe required PHP contract');
  process.stdout.write(execFileSync(php,[path.join(directory,test)],{cwd:directory,env:process.env,encoding:'utf8'}));
 }
}

if(process.argv[1]&&path.resolve(process.argv[1])===fileURLToPath(import.meta.url)){
 const catalog=JSON.parse(readFileSync(path.join(root,'docs/ui/approved-components.json'),'utf8'));
 const tests=new Map();
 runRequiredContracts(catalog.pages);
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
