#!/usr/bin/env node
// Compare the accepted sandbox with the implementation, using identical fixtures.
import {createRequire} from 'node:module';
import {mkdirSync,writeFileSync} from 'node:fs';
import path from 'node:path';
import {assertAppearanceParity} from '../tests/helpers/ui_appearance.mjs';

const require = createRequire(import.meta.url);
const {chromium} = require('playwright');
const args = Object.fromEntries(process.argv.slice(2).reduce((pairs,value,index,all) => index%2 ? pairs : [...pairs,[value,all[index+1]]],[]));
for (const name of ['--reference-url','--candidate-url','--output']) if (!args[name]) throw new Error(`Required: ${name}`);
for (const name of ['--reference-url','--candidate-url']) {
  const url = new URL(args[name]);
  if (url.protocol !== 'http:' || !['127.0.0.1','localhost','[::1]'].includes(url.hostname)) throw new Error('UI parity accepts localhost HTTP URLs only');
}
const output = path.resolve(args['--output']);
mkdirSync(output,{recursive:true});
const resultFile=path.join(output,'result.json');
writeFileSync(resultFile,JSON.stringify({result:'RUNNING'}));
// Publication evidence must compare the frozen accepted version, not mutable
// workspace overrides. The Design Lab refuses this URL until OWNER acceptance.
const referenceUrl = new URL(args['--reference-url']);
referenceUrl.searchParams.set('version','accepted');
args['--reference-url'] = referenceUrl.href;
const browser = await chromium.launch({headless:true});
try {
  for (const width of [390,1280,1920,3840]) {
    const context = await browser.newContext({viewport:{width,height:1080},locale:'ru-RU',timezoneId:'Europe/Moscow',deviceScaleFactor:1});
    // Never allow external traffic or application writes during visual acceptance.
    await context.route('**/*', route => {
      const url = new URL(route.request().url());
      return ['127.0.0.1','localhost','[::1]'].includes(url.hostname) && ['GET','HEAD'].includes(route.request().method()) ? route.continue() : route.abort();
    });
    const reference = await context.newPage(), candidate = await context.newPage();
    const referenceResponse = await reference.goto(args['--reference-url']);
    const candidateResponse = await candidate.goto(args['--candidate-url']);
    if(!referenceResponse?.ok()||!candidateResponse?.ok())throw new Error('Accepted sandbox and implementation must both return HTTP success');
    await assertAppearanceParity(reference,candidate,`${width}px normal`);
    for (const selector of ['.registry-ui-search','.registry-ui-head .btn-primary','.registry-ui-head .btn-secondary,.registry-ui-head .btn-ghost','.registry-ui-toolbar .lab-reference-trigger','.registry-ui-checkbox input']) {
      if (await reference.locator(selector).count() && await reference.locator(selector).first().isVisible()) {
        for (const state of ['hover','focus']) {
          await reference.locator(selector).first()[state]();
          await candidate.locator(selector).first()[state]();
          await assertAppearanceParity(reference,candidate,`${width}px ${selector} ${state}`);
        }
      }
    }
    await reference.screenshot({path:path.join(output,`reference-${width}.png`),fullPage:true});
    await candidate.screenshot({path:path.join(output,`candidate-${width}.png`),fullPage:true});
    await context.close();
  }
  writeFileSync(resultFile,JSON.stringify({result:'PASS',reference:args['--reference-url'],candidate:args['--candidate-url'],widths:[390,1280,1920,3840]},null,2));
  console.log('UI_PARITY=PASS');
} catch(error){
  writeFileSync(resultFile,JSON.stringify({result:'FAIL',error:error.message,reference:args['--reference-url'],candidate:args['--candidate-url']},null,2));
  throw error;
} finally {await browser.close();}
