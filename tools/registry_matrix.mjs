import assert from 'node:assert/strict';

// One matrix for LOCAL, normal CI and FAST CI. No page may reduce these widths.
export const registryWidths=Object.freeze([390,1280,1920,3840]);
export function verifyMatrix(stdout,pages,marker) {
 const actual=new Set([...stdout.matchAll(new RegExp(`^${marker}=([a-z][a-z0-9.-]*:[a-z][a-z0-9-]*:[0-9]+)\\r?$`,'gm'))].map(match=>match[1]).filter(value=>registryWidths.includes(Number(value.split(':').at(-1)))));
 const expected=pages.flatMap(page=>page.table_states.flatMap(state=>registryWidths.map(width=>`${page.id}:${state.id}:${width}`)));
 assert.deepEqual([...actual].sort(),expected.sort(),'every declared registry table surface/state/width must execute against the visible DOM');
}
export function verifyAsyncMatrix(stdout,pages,marker='REGISTRY_ASYNC_SEARCH') {
 const actual=new Set([...stdout.matchAll(new RegExp(`^${marker}=([a-z][a-z0-9.-]*:[0-9]+)\\r?$`,'gm'))].map(match=>match[1]));
 const expected=pages.filter(page=>page.async_search?.applicable).flatMap(page=>registryWidths.map(width=>`${page.id}:${width}`));
 assert.deepEqual([...actual].sort(),expected.sort(),'every applicable async search must execute at every width');
}
