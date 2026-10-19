import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {execFileSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';
import path from 'node:path';

export function verifyRuntimeCoverage(stdout,expected) {
  const actual=[...stdout.matchAll(/^REGISTRY_RUNTIME_PAGE=([a-z][a-z0-9.-]*)\r?$/gm)].map(match=>match[1]);
  assert.equal(new Set(actual).size,actual.length,'duplicate runtime page coverage');
  assert.deepEqual(actual.sort(),[...expected].sort(),'catalog pages must have executed runtime scenarios');
}

export function verifyRuntimeSurfaceCoverage(stdout,pages) {
  const actual=new Set([...stdout.matchAll(/^REGISTRY_RUNTIME_SURFACE=([a-z][a-z0-9.-]*:[a-z][a-z0-9-]*)\r?$/gm)].map(match=>match[1]));
  const expected=pages.flatMap(page=>[`${page.id}:initial`,...(page.async_search?.applicable?[`${page.id}:search-results`]:[])]);
  assert.deepEqual([...actual].sort(),expected.sort(),'runtime browser must discover main and applicable dynamic business tables');
}

if(process.argv[1]&&path.resolve(process.argv[1])===fileURLToPath(import.meta.url)){
 const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
 const catalog=JSON.parse(readFileSync(path.join(root,'docs/ui/approved-components.json'),'utf8'));
 const tests=new Map(),registered=[];
 for(const page of catalog.pages)if(page.runtime_browser_test){
  registered.push(page);
  const file=page.runtime_browser_test;
  assert.match(file,/^tests\/[a-zA-Z0-9_/-]+\.mjs$/,'safe runtime test path');
  if(!tests.has(file))tests.set(file,[]);tests.get(file).push(page.id);
 }
 assert.ok(tests.size,'registered runtime tests required');
 let aggregate='';
 for(const [test,pages] of tests){
  const stdout=execFileSync(process.execPath,[path.join(root,test),'--registry-pages',...pages],{cwd:root,env:process.env,encoding:'utf8',timeout:180000,maxBuffer:8*1024*1024});
  process.stdout.write(stdout);aggregate+=stdout;verifyRuntimeCoverage(stdout,pages);
 }
 verifyRuntimeSurfaceCoverage(aggregate,registered);
 console.log('REGISTRY_RUNTIME_GATE=PASS catalog-executed page IDs match');
}
