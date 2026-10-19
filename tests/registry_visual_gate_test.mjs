import assert from 'node:assert/strict';
import {mkdtempSync,mkdirSync,writeFileSync,rmSync} from 'node:fs';
import {tmpdir} from 'node:os';
import path from 'node:path';
import {verifyVisualCoverage,verifySurfaceCoverage,verifyAsyncCoverage,runRequiredContracts,verifyEmptyFixtureCoverage} from '../tools/registry_visual_gate.mjs';

const output='REGISTRY_VISUAL_PAGE=contractors\nREGISTRY_VISUAL_PAGE=vehicle-sets\n';
verifyVisualCoverage(output,['contractors','vehicle-sets']);
assert.throws(()=>verifyVisualCoverage(output,['contractors','vehicle-sets','copied-page-without-visual-scenario']),/execute shared visual/);
assert.throws(()=>verifyVisualCoverage('', ['contractors']),/execute shared visual/);
assert.throws(()=>verifyVisualCoverage(output,['contractors']),/execute shared visual/);
const pages=[{id:'contractors',table_states:[{id:'initial'}],async_search:{applicable:false}},
 {id:'feo-map-placement',table_states:[{id:'initial'},{id:'search-results'},{id:'search-error'}],async_search:{applicable:true}}];
const widths=[390,1280,1920,3840];
const states=pages.flatMap(page=>page.table_states.flatMap(state=>widths.map(width=>`REGISTRY_SURFACE_STATE=${page.id}:${state.id}:${width}\n`))).join('');
const asyncOutput=widths.map(width=>`REGISTRY_ASYNC_SEARCH=feo-map-placement:${width}\n`).join('');
verifySurfaceCoverage(states+asyncOutput,pages);
assert.throws(()=>verifySurfaceCoverage(states.replace('REGISTRY_SURFACE_STATE=feo-map-placement:search-error:390\n','')+asyncOutput,pages),/every declared registry/);
assert.throws(()=>verifySurfaceCoverage(states.split('\n').filter(line=>!line.endsWith(':3840')).join('\n')+'\n'+asyncOutput,pages),/every declared registry/);
assert.throws(()=>verifySurfaceCoverage(states+asyncOutput,[...pages,{id:'copied-page',table_states:[{id:'initial'}]}]),/every declared registry/);
verifyAsyncCoverage(asyncOutput,pages);
assert.throws(()=>verifyAsyncCoverage('',pages),/applicable async search/);
assert.throws(()=>verifyAsyncCoverage(asyncOutput.replace('REGISTRY_ASYNC_SEARCH=feo-map-placement:390\n',''),pages),/applicable async search/);
console.log('REGISTRY_VISUAL_GATE_TEST=PASS missing state, width, copied registration and omitted async are RED');

const directory=mkdtempSync(path.join(tmpdir(),'planex-required-contract-'));
try{
 mkdirSync(path.join(directory,'tests'));
 const required=[{required_contracts:['tests/probe_test.php']}];
 assert.throws(()=>runRequiredContracts(required,{directory}),/Command failed/,'missing required PHP must fail');
 writeFileSync(path.join(directory,'tests/probe_test.php'),'<?php exit(17);');
 assert.throws(()=>runRequiredContracts(required,{directory}),/Command failed/,'failed required PHP must fail');
 writeFileSync(path.join(directory,'tests/probe_test.php'),'<?php echo "REQUIRED_PHP_EXECUTED=PASS\\n";');
 runRequiredContracts(required,{directory});
}finally{rmSync(directory,{recursive:true,force:true});}

const emptyPages=[{id:'fixture-page',table_states:[{id:'initial',empty_fixture:true}]}];
const emptyOutput=widths.map(width=>`REGISTRY_EMPTY_FIXTURE_SURFACE=fixture-page:initial:${width}\n`).join('');
verifyEmptyFixtureCoverage(emptyOutput,emptyPages);
assert.throws(()=>verifyEmptyFixtureCoverage('',emptyPages),/every declared registry/);
assert.throws(()=>verifyEmptyFixtureCoverage(emptyOutput.replace('REGISTRY_EMPTY_FIXTURE_SURFACE=fixture-page:initial:390\n',''),emptyPages),/every declared registry/);
assert.throws(()=>verifySurfaceCoverage(emptyOutput,emptyPages),/every declared registry/,'supplementary empty fixture cannot certify populated initial');
