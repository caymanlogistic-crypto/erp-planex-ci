import assert from 'node:assert/strict';
import {verifyVisualCoverage,verifySurfaceCoverage,verifyAsyncCoverage} from '../tools/registry_visual_gate.mjs';

const output='REGISTRY_VISUAL_PAGE=contractors\nREGISTRY_VISUAL_PAGE=vehicle-sets\n';
verifyVisualCoverage(output,['contractors','vehicle-sets']);
assert.throws(()=>verifyVisualCoverage(output,['contractors','vehicle-sets','copied-page-without-visual-scenario']),/execute shared visual/);
assert.throws(()=>verifyVisualCoverage('', ['contractors']),/execute shared visual/);
assert.throws(()=>verifyVisualCoverage(output,['contractors']),/execute shared visual/);
const pages=[
  {id:'contractors',table_states:[{id:'initial'}],async_search:{applicable:false}},
  {id:'feo-map-placement',table_states:[{id:'initial'},{id:'search-results'},{id:'search-error'}],async_search:{applicable:true}},
];
const states='REGISTRY_SURFACE_STATE=contractors:initial\nREGISTRY_SURFACE_STATE=feo-map-placement:initial\nREGISTRY_SURFACE_STATE=feo-map-placement:search-results\nREGISTRY_SURFACE_STATE=feo-map-placement:search-error\n';
verifySurfaceCoverage(states,pages);
assert.throws(()=>verifySurfaceCoverage(states.replace('REGISTRY_SURFACE_STATE=feo-map-placement:search-error\n',''),pages),/every declared registry table surface/);
assert.throws(()=>verifySurfaceCoverage(states,[...pages,{id:'new-page',table_states:[{id:'initial'}],async_search:{applicable:false}}]),/every declared registry table surface/);
verifyAsyncCoverage('REGISTRY_ASYNC_SEARCH=feo-map-placement\n',pages);
assert.throws(()=>verifyAsyncCoverage('',pages),/applicable async search/);
console.log('REGISTRY_VISUAL_GATE_TEST=PASS page, inventory-state and async coverage positive/negative');
