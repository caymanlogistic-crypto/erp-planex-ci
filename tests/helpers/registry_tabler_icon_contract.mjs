import assert from 'node:assert/strict';
import {createHash} from 'node:crypto';
import {readFileSync} from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'../..');
const catalog=JSON.parse(readFileSync(path.join(root,'docs/ui/approved-components.json'),'utf8'));
const manifestBytes=readFileSync(path.join(root,'docs/ui/tabler-outline-3.48.0.json'));
const manifestSha='effa1ed6d92b1d4fddec70099375fb0105fcd2e9741860b10c26315594e2a079';
const sha256=bytes=>createHash('sha256').update(bytes).digest('hex');
assert.equal(sha256(manifestBytes),manifestSha,'pinned official Tabler outline 3.48.0 manifest');
const manifest=JSON.parse(manifestBytes);
assert.deepEqual([manifest.package,manifest.version,manifest.variant],['@tabler/icons','3.48.0','outline']);

export async function assertRegistryTablerIcon(page,pageId){
 const definition=catalog.pages.find(item=>item.id===pageId);
 assert.equal(definition?.family,'registry',`${pageId}: registered registry page`);
 const icon=definition.header_icon;
 assert.ok(icon&&typeof icon==='object',`${pageId}: required header icon provenance`);
 assert.deepEqual([icon.role,icon.pack,icon.version,icon.variant],['registry.page-head','@tabler/icons','3.48.0','outline'],`${pageId}: approved named outline metadata`);
 assert.equal(icon.source,`app/View/components/tabler/outline/${icon.name}.svg`,`${pageId}: shared named icon source`);
 assert.ok(manifest.icons[icon.name],`${pageId}: official outline name`);
 const sourceBytes=readFileSync(path.join(root,icon.source));
 assert.equal(sha256(sourceBytes),manifest.icons[icon.name],`${pageId}: exact official package source bytes`);
 const actual=await page.locator('.registry-ui-head .rp-identity-icon svg').evaluate((svg,{source,name})=>{
  const original=new DOMParser().parseFromString(source,'image/svg+xml').documentElement;
  const signature=node=>({tag:node.localName,attributes:[...node.attributes].filter(attr=>!['class','aria-hidden','focusable'].includes(attr.name)).map(attr=>[attr.name,attr.value]).sort((a,b)=>a[0].localeCompare(b[0])),children:[...node.children].map(signature)});
  const rect=svg.getBoundingClientRect(),style=getComputedStyle(svg);
  return {geometry:signature(svg),official:signature(original),className:svg.getAttribute('class'),expectedClass:'icon-tabler-'+name,size:[rect.width,rect.height],color:style.color,stroke:svg.getAttribute('stroke-width')};
 },{source:sourceBytes.toString('utf8'),name:icon.name});
 assert.deepEqual(actual.geometry,actual.official,`${pageId}: exact named Tabler outline geometry`);
 assert.ok(actual.className.split(/\s+/).includes(actual.expectedClass)&&actual.className.split(/\s+/).includes('page-head-tabler-icon'),`${pageId}: named shared header icon`);
 assert.deepEqual(actual.size,[30,30],`${pageId}: approved 30px icon`);
 assert.equal(actual.color,'rgb(124, 71, 24)',`${pageId}: approved color`);
 assert.equal(actual.stroke,'2',`${pageId}: approved stroke`);
}
