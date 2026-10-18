// Shared by the sandbox parity CLI and its real browser regression.
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';

const catalog=JSON.parse(readFileSync(new URL('../../docs/ui/approved-components.json',import.meta.url),'utf8'));
const roles = [...new Set([...catalog.components.map(component=>component.selector),'.registry-ui-head .page-title','.registry-ui-head .page-summary','.registry-ui-head .btn','.registry-ui-tabs button','.registry-ui-toolbar .toolbar-select','.registry-ui-toolbar .lab-reference-trigger','.registry-ui-toolbar .lab-reference-list','.registry-ui-toolbar .lab-reference-option','.registry-ui-table thead th','.registry-ui-table tbody tr:first-child td'])];
export async function appearance(page) {
  await page.evaluate(() => document.fonts.ready);
  // Compare settled states, never different intermediate frames of a 90ms hover
  // transition. Finishing transitions preserves the actual final CSS values.
  await page.evaluate(() => document.getAnimations().filter(animation => animation.constructor.name==='CSSTransition').forEach(animation=>animation.finish()));
  return page.evaluate(roles => roles.map(selector => ({selector, elements:[...document.querySelectorAll(selector)].map(element => {
    const style = getComputedStyle(element), rect = element.getBoundingClientRect();
    const properties = ['fontFamily','fontSize','fontWeight','lineHeight','color','backgroundColor','backgroundImage','borderTopColor','borderTopWidth','borderBottomColor','borderBottomWidth','borderLeftColor','borderLeftWidth','borderRightColor','borderRightWidth','borderRadius','paddingTop','paddingRight','paddingBottom','paddingLeft','marginTop','marginRight','marginBottom','marginLeft','gap','alignItems','justifyContent','boxShadow','outlineColor','outlineWidth','outlineStyle'];
    return {x:Math.round(rect.x*100)/100,y:Math.round(rect.y*100)/100,width:Math.round(rect.width*100)/100,height:Math.round(rect.height*100)/100,visible:rect.width>0 && rect.height>0,styles:Object.fromEntries(properties.map(key => [key,style[key]]))};
  })})), roles);
}

export async function assertAppearanceParity(reference, candidate, label) {
  const expected = await appearance(reference);
  assert.ok(expected.some(role => role.elements.length), `${label}: no approved UI roles found`);
  const actual=await appearance(candidate);
  for(let r=0;r<expected.length;r++){
    const context=`${label}: sandbox and implementation differ: ${expected[r].selector}`;
    assert.equal(actual[r].elements.length,expected[r].elements.length,`${context} count`);
    for(let i=0;i<expected[r].elements.length;i++){
      const a=actual[r].elements[i],e=expected[r].elements[i];
      for(const key of ['x','y','width','height','visible'])assert.equal(a[key],e[key],`${context}[${i}] ${key}`);
      for(const key of Object.keys(e.styles))assert.equal(a.styles[key],e.styles[key],`${context}[${i}] ${key}`);
    }
  }
}
