import assert from 'node:assert/strict';
import {createHash} from 'node:crypto';
import {readFileSync} from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

// Immutable @tabler/icons 3.48.0 outline files, extracted from the official npm tarball.
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'../..');
const icons={
 contractors:{name:'truck-delivery',sha256:'a774d0b45ef54c002a9ff3fb35510ed824b61c26c197d564dabb8862ca3412a5'},
 'vehicle-sets':{name:'truck',sha256:'e00548eaa071c83635175ba38510b352834672364ef62c00fd5c1de2ec21d81e'},
};

export async function assertRegistryTablerIcon(page,kind){
 const icon=icons[kind];assert.ok(icon,`known registry icon role ${kind}`);
 const source=readFileSync(path.join(root,'app/View/components/tabler/outline',icon.name+'.svg'),'utf8');
 assert.equal(createHash('sha256').update(source).digest('hex'),icon.sha256,`${kind}: official @tabler/icons 3.48.0 source hash`);
 const actual=await page.locator('.registry-ui-head .rp-identity-icon svg').evaluate((svg,{source,name})=>{
  const original=new DOMParser().parseFromString(source,'image/svg+xml').documentElement;
  const signature=node=>({tag:node.localName,attributes:[...node.attributes].filter(attr=>!['class','aria-hidden','focusable'].includes(attr.name)).map(attr=>[attr.name,attr.value]).sort((a,b)=>a[0].localeCompare(b[0])),children:[...node.children].map(signature)});
  const rect=svg.getBoundingClientRect(),style=getComputedStyle(svg);
  return {geometry:signature(svg),official:signature(original),className:svg.getAttribute('class'),expectedClass:'icon-tabler-'+name,size:[rect.width,rect.height],color:style.color,stroke:svg.getAttribute('stroke-width')};
 },{source,name:icon.name});
 assert.deepEqual(actual.geometry,actual.official,`${kind}: exact named Tabler outline geometry`);
 assert.ok(actual.className.includes(actual.expectedClass)&&actual.className.includes('page-head-tabler-icon'),`${kind}: named shared header icon`);
 assert.deepEqual(actual.size,[30,30],`${kind}: approved 30px icon`);
 assert.equal(actual.color,'rgb(124, 71, 24)',`${kind}: approved color`);
 assert.equal(actual.stroke,'2',`${kind}: approved stroke`);
}
