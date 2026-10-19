import assert from 'node:assert/strict';
import {verifyVisualCoverage} from '../tools/registry_visual_gate.mjs';

const output='REGISTRY_VISUAL_PAGE=contractors\nREGISTRY_VISUAL_PAGE=vehicle-sets\n';
verifyVisualCoverage(output,['contractors','vehicle-sets']);
assert.throws(()=>verifyVisualCoverage(output,['contractors','vehicle-sets','copied-page-without-visual-scenario']),/execute shared visual/);
assert.throws(()=>verifyVisualCoverage('', ['contractors']),/execute shared visual/);
assert.throws(()=>verifyVisualCoverage(output,['contractors']),/execute shared visual/);
console.log('REGISTRY_VISUAL_GATE_TEST=PASS positive and missing/extra coverage rejected');
