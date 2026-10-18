// Shared by the sandbox parity CLI and its real browser regression.
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';

const catalog=JSON.parse(readFileSync(new URL('../../docs/ui/approved-components.json',import.meta.url),'utf8'));
const stableRoles = ['.registry-ui-head','.registry-ui-card','.registry-ui-toolbar','.registry-ui-table','.registry-ui-head .page-title','.registry-ui-head .page-summary','.registry-ui-head .btn','.registry-ui-tabs button','.registry-ui-toolbar :is(input,select,button,a)','.registry-ui-toolbar .lab-reference-list','.registry-ui-toolbar .lab-reference-option','.registry-ui-table :is(thead th,tbody td,tfoot td)'];
const roles = [...new Set([...catalog.components.map(component=>component.selector),...stableRoles])];
const markers = new Set(catalog.components.map(component=>component.selector).filter(selector=>!stableRoles.includes(selector)));
export async function appearance(page) {
  await page.evaluate(() => document.fonts.ready);
  // Compare settled states, never different intermediate frames of a 90ms hover
  // transition. Finishing transitions preserves the actual final CSS values.
  await page.evaluate(() => document.getAnimations().filter(animation => animation.constructor.name==='CSSTransition').forEach(animation=>animation.finish()));
  return page.evaluate(roles => {
    const result=roles.map(selector => ({selector, elements:[...document.querySelectorAll(selector)].map(element => {
    const style = getComputedStyle(element), rect = element.getBoundingClientRect();
    const properties = ['fontFamily','fontSize','fontWeight','lineHeight','color','backgroundColor','backgroundImage','borderTopColor','borderTopWidth','borderBottomColor','borderBottomWidth','borderLeftColor','borderLeftWidth','borderRightColor','borderRightWidth','borderRadius','paddingTop','paddingRight','paddingBottom','paddingLeft','marginTop','marginRight','marginBottom','marginLeft','gap','alignItems','justifyContent','boxShadow','outlineColor','outlineWidth','outlineStyle'];
    return {x:Math.round(rect.x*100)/100,y:Math.round(rect.y*100)/100,width:Math.round(rect.width*100)/100,height:Math.round(rect.height*100)/100,visible:rect.width>0 && rect.height>0,styles:Object.fromEntries(properties.map(key => [key,style[key]]))};
    })}));
    // Immutable approved prototypes may use older class names. Compare actual
    // glyphs as well as complete cells/controls, independent of value wrappers.
    const elements=[],walker=document.createTreeWalker(document.body,NodeFilter.SHOW_TEXT);
    const textProperties=['fontFamily','fontSize','fontWeight','fontStyle','lineHeight','letterSpacing','fontVariantNumeric','color','textTransform','textDecorationLine'];
    for(let node=walker.nextNode();node;node=walker.nextNode()){
      if(!node.textContent.trim()||!node.parentElement.closest('.registry-ui-head,.registry-ui-tabs,.registry-ui-toolbar,.registry-ui-table'))continue;
      const range=document.createRange();range.selectNodeContents(node);
      const rect=range.getBoundingClientRect(),style=getComputedStyle(node.parentElement);
      if(!rect.width||!rect.height||style.visibility==='hidden')continue;
      elements.push({x:Math.round(rect.x*100)/100,y:Math.round(rect.y*100)/100,width:Math.round(rect.width*100)/100,height:Math.round(rect.height*100)/100,visible:true,styles:{text:node.textContent.trim(),...Object.fromEntries(textProperties.map(key=>[key,style[key]]))}});
    }
    result.push({selector:'@registry-rendered-text',elements});return result;
  }, roles);
}

export async function assertAppearanceParity(reference, candidate, label) {
  const expected = await appearance(reference);
  assert.ok(expected.some(role => role.elements.length), `${label}: no approved UI roles found`);
  const actual=await appearance(candidate);
  for(let r=0;r<expected.length;r++){
    const context=`${label}: sandbox and implementation differ: ${expected[r].selector}`;
    // Semantic markers are not visible requirements. Missing/added marker
    // aliases are covered by mandatory geometry and rendered-text probes.
    if(markers.has(expected[r].selector)&&(!expected[r].elements.length||!actual[r].elements.length))continue;
    assert.equal(actual[r].elements.length,expected[r].elements.length,`${context} count`);
    for(let i=0;i<expected[r].elements.length;i++){
      const a=actual[r].elements[i],e=expected[r].elements[i];
      for(const key of ['x','y','width','height','visible'])assert.equal(a[key],e[key],`${context}[${i}] ${key}`);
      for(const key of Object.keys(e.styles))assert.equal(a.styles[key],e.styles[key],`${context}[${i}] ${key}`);
    }
  }
}
