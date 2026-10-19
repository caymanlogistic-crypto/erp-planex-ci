import assert from 'node:assert/strict';
import {verifyRuntimeCoverage} from '../tools/registry_runtime_gate.mjs';
const output='REGISTRY_RUNTIME_PAGE=contractors\nREGISTRY_RUNTIME_PAGE=vehicle-sets\n';
verifyRuntimeCoverage(output,['contractors','vehicle-sets']);
assert.throws(()=>verifyRuntimeCoverage(output,['contractors','vehicle-sets','copied-page-without-scenario']),/executed runtime scenarios/);
assert.throws(()=>verifyRuntimeCoverage('', ['contractors']),/executed runtime scenarios/);
assert.throws(()=>verifyRuntimeCoverage(output+'REGISTRY_RUNTIME_PAGE=contractors\n',['contractors','vehicle-sets']),/duplicate/);
assert.throws(()=>verifyRuntimeCoverage(output,['contractors']),/executed runtime scenarios/);
console.log('REGISTRY_RUNTIME_GATE_TEST=PASS positive and missing/extra/duplicate page coverage rejected');
