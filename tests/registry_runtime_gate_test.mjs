import assert from 'node:assert/strict';
import {verifyRuntimeCoverage,verifyRuntimeSurfaceCoverage} from '../tools/registry_runtime_gate.mjs';
const output='REGISTRY_RUNTIME_PAGE=contractors\nREGISTRY_RUNTIME_PAGE=vehicle-sets\n';
verifyRuntimeCoverage(output,['contractors','vehicle-sets']);
assert.throws(()=>verifyRuntimeCoverage(output,['contractors','vehicle-sets','copied-page-without-scenario']),/executed runtime scenarios/);
assert.throws(()=>verifyRuntimeCoverage('', ['contractors']),/executed runtime scenarios/);
assert.throws(()=>verifyRuntimeCoverage(output+'REGISTRY_RUNTIME_PAGE=contractors\n',['contractors','vehicle-sets']),/duplicate/);
assert.throws(()=>verifyRuntimeCoverage(output,['contractors']),/executed runtime scenarios/);
const pages=[{id:'contractors',table_states:[{id:'initial'}],async_search:{applicable:false}},
 {id:'feo-map-placement',table_states:[{id:'initial'},{id:'search-results'},{id:'search-error'}],async_search:{applicable:true}}];
const widths=[390,1280,1920,3840];
const states=pages.flatMap(page=>page.table_states.flatMap(state=>widths.map(width=>`REGISTRY_RUNTIME_SURFACE=${page.id}:${state.id}:${width}\n`))).join('');
const asyncOutput=widths.map(width=>`REGISTRY_RUNTIME_ASYNC_SEARCH=feo-map-placement:${width}\n`).join('');
verifyRuntimeSurfaceCoverage(states+asyncOutput,pages);
assert.throws(()=>verifyRuntimeSurfaceCoverage(states.replace('REGISTRY_RUNTIME_SURFACE=feo-map-placement:search-error:390\n','')+asyncOutput,pages),/every declared registry/);
assert.throws(()=>verifyRuntimeSurfaceCoverage(states.split('\n').filter(line=>!line.endsWith(':3840')).join('\n')+'\n'+asyncOutput,pages),/every declared registry/);
assert.throws(()=>verifyRuntimeSurfaceCoverage(states+asyncOutput,[...pages,{id:'copied-page',table_states:[{id:'initial'}]}]),/every declared registry/);
assert.throws(()=>verifyRuntimeSurfaceCoverage(states,pages),/applicable async search/);
console.log('REGISTRY_RUNTIME_GATE_TEST=PASS missing state, width, copied registration and omitted async are RED');
