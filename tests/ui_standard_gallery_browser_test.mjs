import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {createRequire} from 'node:module';
import {fileURLToPath,pathToFileURL} from 'node:url';
import {assertRegistryVisualContract} from './helpers/registry_visual_contract.mjs';

const require = createRequire(import.meta.url);
const {chromium} = require('playwright');
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const gallery = pathToFileURL(path.join(root,'docs/ui/approved-components.html')).href;
const catalog = JSON.parse(fs.readFileSync(path.join(root,'docs/ui/approved-components.json'),'utf8'));
const browser = await chromium.launch({headless:true});
try {
  for (const width of [390,1280,1920,3840]) {
    const page = await browser.newPage({viewport:{width,height:1080},deviceScaleFactor:1});
    await page.goto(gallery);
    const preview = id => page.frameLocator(`iframe[data-preview="${id}"]`);
    await preview('registry.example').locator('.registry-ui-search').waitFor();
    if(process.env.PLANEX_VISUAL_EVIDENCE_DIR){fs.mkdirSync(process.env.PLANEX_VISUAL_EVIDENCE_DIR,{recursive:true});await page.screenshot({path:path.join(process.env.PLANEX_VISUAL_EVIDENCE_DIR,`gallery-initial-${width}.png`)});}
    assert.equal(await page.locator('.gallery-item').count(),catalog.components.length);
    assert.equal(await page.locator('iframe[data-preview]').count(),catalog.components.length+1);
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth<=innerWidth+1),true,`${width}px gallery overflow`);
    for (const frame of page.frames().filter(frame => frame.parentFrame())) {
      await frame.evaluate(() => document.fonts.ready);
      const overflow=await frame.evaluate(()=>({width:innerWidth,scroll:document.documentElement.scrollWidth,nodes:[...document.querySelectorAll('*')].filter(element=>element.getBoundingClientRect().right>innerWidth+1).map(element=>[element.tagName,element.className,element.getBoundingClientRect().right]).slice(0,8)}));
      assert.equal(overflow.scroll<=overflow.width+1,true,`${width}px specimen overflow ${await (await frame.frameElement()).getAttribute('data-preview')}: ${JSON.stringify(overflow)}`);
    }
    const complete = page.frames().find(frame => frame.parentFrame() && frame.locator);
    await assertRegistryVisualContract(complete,`gallery complete example ${width}px`);
    const search = preview('registry.search').locator('.registry-ui-search');
    await search.focus();
    assert.deepEqual(await search.evaluate(element => {
      const style=getComputedStyle(element);
      return [style.borderColor,style.boxShadow,style.height];
    }),['rgb(124, 71, 24)','rgba(124, 71, 24, 0.1) 0px 0px 0px 2px','32px']);
    const native = preview('registry.toolbar').locator('.toolbar-select');
    await native.hover();
    const nativeHover = await native.evaluate(element => [getComputedStyle(element).backgroundColor,getComputedStyle(element).borderColor]);
    assert.deepEqual(nativeHover,['rgb(234, 227, 216)','rgb(201, 189, 174)']);
    const select = preview('registry.select'), trigger = select.locator('.lab-reference-trigger');
    await trigger.hover();
    assert.deepEqual(await trigger.evaluate(element => [getComputedStyle(element).backgroundColor,getComputedStyle(element).borderColor]),nativeHover);
    await trigger.click();
    const list = select.locator('.lab-reference-list');
    assert.equal(await list.isVisible(),true);
    await page.waitForFunction(() => {
      const frame=document.querySelector('iframe[data-preview="registry.select"]');
      const list=frame.contentDocument.querySelector('.lab-reference-list');
      return frame.clientHeight >= list.getBoundingClientRect().bottom;
    });
    assert.equal(await list.evaluate(element => getComputedStyle(element).backgroundColor),'rgb(255, 255, 255)');
    await page.mouse.move(1,1);
    const selected=select.locator('[aria-selected="true"]');
    assert.equal(await selected.evaluate(element => getComputedStyle(element).backgroundColor),'rgb(242, 232, 220)');
    await selected.hover();
    assert.equal(await selected.evaluate(element => getComputedStyle(element).backgroundColor),'rgb(233, 217, 199)');
    await page.keyboard.press('ArrowDown');await page.keyboard.press('Enter');
    assert.equal(await select.locator('select').inputValue(),'name');
    const single = preview('registry.checkbox').locator('.rp-v014-hide-complete');
    const grouped = preview('registry.checkbox-group').locator('.registry-ui-checkboxes:not(.registry-ui-checkboxes--trip-status) .registry-ui-checkbox').first();
    const labelStyle = element => {const s=getComputedStyle(element),input=getComputedStyle(element.querySelector('input'));return [s.minHeight,s.gap,s.font,s.color,input.width,input.height,input.accentColor]};
    assert.deepEqual(await grouped.evaluate(labelStyle),await single.evaluate(labelStyle));
    const checkbox = preview('registry.checkbox-group').locator('.registry-ui-checkboxes:not(.registry-ui-checkboxes--trip-status) input').nth(1);
    await checkbox.focus();await page.keyboard.press('Space');assert.equal(await checkbox.isChecked(),true);
    assert.deepEqual(await preview('registry.checkbox-group').locator('.registry-ui-checkboxes--trip-status input').evaluateAll(items=>items.map(element=>getComputedStyle(element).accentColor)),['rgb(156, 39, 176)','rgb(23, 162, 184)','rgb(40, 167, 69)','rgb(108, 117, 125)']);
    // Host-page CSS is deliberately hostile: isolated component styles must survive.
    const before=await search.evaluate(element=>getComputedStyle(element).height);
    await page.addStyleTag({content:'.registry-ui-search{height:77px!important;font:30px Arial!important}'});
    assert.equal(await search.evaluate(element=>getComputedStyle(element).height),before);
    if(process.env.PLANEX_VISUAL_EVIDENCE_DIR){
      fs.mkdirSync(process.env.PLANEX_VISUAL_EVIDENCE_DIR,{recursive:true});
      await page.evaluate(()=>window.scrollTo(0,0));
      await page.screenshot({path:path.join(process.env.PLANEX_VISUAL_EVIDENCE_DIR,`gallery-${width}.png`)});
      await page.locator('#complete-example').screenshot({path:path.join(process.env.PLANEX_VISUAL_EVIDENCE_DIR,`gallery-example-${width}.png`)});
    }
    await page.close();
  }
  console.log('UI_STANDARD_GALLERY_BROWSER=PASS');
}finally{await browser.close()}
