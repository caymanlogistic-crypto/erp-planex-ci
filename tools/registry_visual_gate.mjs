import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {execFileSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';
import path from 'node:path';

const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const historicalDebt=new Set(['route-executors','trips-departures','finance-received-paid','logistics-closing-documents','drivers']);

export function verifyVisualCoverage(stdout,expected){
 const actual=new Set([...stdout.matchAll(/^REGISTRY_VISUAL_PAGE=([a-z][a-z0-9.-]*)\r?$/gm)].map(match=>match[1]));
 assert.deepEqual([...actual].sort(),[...expected].sort(),'catalog pages must execute shared visual and exact icon contract');
}

if(process.argv[1]&&path.resolve(process.argv[1])===fileURLToPath(import.meta.url)){
 const catalog=JSON.parse(readFileSync(path.join(root,'docs/ui/approved-components.json'),'utf8'));
 const tests=new Map();
 for(const page of catalog.pages.filter(page=>page.family==='registry'&&(page.header_icon||!historicalDebt.has(page.id)))){
  assert.match(page.browser_test,/^tests\/[a-zA-Z0-9_/-]+\.mjs$/,'safe visual test path');
  if(!tests.has(page.browser_test))tests.set(page.browser_test,[]);
  tests.get(page.browser_test).push(page.id);
 }
 assert.ok(tests.size,'registered provenance tests required');
 for(const [test,pages] of tests){
  const stdout=execFileSync(process.execPath,[path.join(root,test)],{cwd:root,env:process.env,encoding:'utf8',timeout:180000,maxBuffer:8*1024*1024});
  process.stdout.write(stdout);verifyVisualCoverage(stdout,pages);
 }
 console.log('REGISTRY_VISUAL_GATE=PASS catalog-executed page IDs match');
}
