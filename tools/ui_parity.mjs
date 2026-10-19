#!/usr/bin/env node
// Compare the accepted sandbox with the implementation, using identical fixtures.
import {createRequire} from 'node:module';
import {mkdirSync,writeFileSync} from 'node:fs';
import path from 'node:path';
import {collectAppearanceDifferences} from '../tests/helpers/ui_appearance.mjs';

const require = createRequire(import.meta.url);
const {chromium} = require('playwright');
const argv=process.argv.slice(2), allowed=new Set(['--reference-url','--candidate-url','--output','--reference-normalization','--widths','--states']);
if(argv.length%2||argv.some((value,index)=>index%2===0&&!allowed.has(value)))throw new Error('Expected known --option value pairs');
const args = Object.fromEntries(argv.reduce((pairs,value,index,all) => index%2 ? pairs : [...pairs,[value,all[index+1]]],[]));
for (const name of ['--reference-url','--candidate-url','--output']) if (!args[name]) throw new Error(`Required: ${name}`);
const fullWidths=[390,1280,1920,3840], fullStates=['normal','hover','focus'];
const widths=[...new Set((args['--widths']||fullWidths.join(',')).split(',').map(Number))];
const states=[...new Set((args['--states']||fullStates.join(',')).split(','))];
if(widths.some(width=>!fullWidths.includes(width))||states.some(state=>!fullStates.includes(state))||!states.includes('normal'))throw new Error('Use approved widths and normal[,hover,focus] states');
const diagnostic=widths.length!==fullWidths.length||states.length!==fullStates.length;
for (const name of ['--reference-url','--candidate-url']) {
  const url = new URL(args[name]);
  if (url.protocol !== 'http:' || !['127.0.0.1','localhost','[::1]'].includes(url.hostname)) throw new Error('UI parity accepts localhost HTTP URLs only');
}
const output = path.resolve(args['--output']);
const financeSearchNormalization=args['--reference-normalization']==='legacy-finance-search-500';
if(args['--reference-normalization']&&!financeSearchNormalization)throw new Error('Unknown reference normalization');
if(financeSearchNormalization&&!new URL(args['--reference-url']).pathname.endsWith('/company/finance/received-paid'))throw new Error('Finance search normalization is restricted to the finance registry');
mkdirSync(output,{recursive:true});
const resultFile=path.join(output,'result.json');
const startedAt=new Date().toISOString(), checks=[];
const metadata={reference:args['--reference-url'],candidate:args['--candidate-url'],widths,states,diagnostic,startedAt};
const save=(result,extra={})=>writeFileSync(resultFile,JSON.stringify({...metadata,result,completedAt:result==='RUNNING'?undefined:new Date().toISOString(),checks,differenceCount:checks.reduce((sum,check)=>sum+check.differenceCount,0),...extra},null,2));
save('RUNNING');
// Publication evidence must compare the frozen accepted version, not mutable
// workspace overrides. The Design Lab refuses this URL until OWNER acceptance.
const referenceUrl = new URL(args['--reference-url']);
referenceUrl.searchParams.set('version','accepted');
args['--reference-url'] = referenceUrl.href;
metadata.reference=referenceUrl.href;
let browser;
try {
  browser=await chromium.launch({headless:true});
  for (const width of widths) {
    const context = await browser.newContext({viewport:{width,height:1080},locale:'ru-RU',timezoneId:'Europe/Moscow',deviceScaleFactor:1});
    try {
    // Never allow external traffic or application writes during visual acceptance.
    await context.route('**/*', route => {
      const url = new URL(route.request().url());
      return ['127.0.0.1','localhost','[::1]'].includes(url.hostname) && ['GET','HEAD'].includes(route.request().method()) ? route.continue() : route.abort();
    });
    const reference = await context.newPage(), candidate = await context.newPage();
    const referenceResponse = await reference.goto(args['--reference-url']);
    const candidateResponse = await candidate.goto(args['--candidate-url']);
    if(!referenceResponse?.ok()||!candidateResponse?.ok())throw new Error('Accepted sandbox and implementation must both return HTTP success');
    // This changes only the in-memory comparison copy. The frozen checkpoint
    // remains untouched; raw parity still reports the historical 400/500 delta.
    if(financeSearchNormalization)await reference.addStyleTag({content:'.rp-ledger-v015 .registry-ui-toolbar input.registry-ui-search,.rp-ledger-v015 .registry-ui-toolbar input.registry-ui-search::placeholder{font-weight:500!important}'});
    const check=async state=>checks.push({width,state,...await collectAppearanceDifferences(reference,candidate,`${width}px ${state}`)});
    await check('normal');
    for (const selector of ['.registry-ui-search','.registry-ui-head .btn-primary','.registry-ui-head .btn-secondary,.registry-ui-head .btn-ghost','.registry-ui-toolbar .lab-reference-trigger','.registry-ui-checkbox input']) {
      if (await reference.locator(selector).count() && await reference.locator(selector).first().isVisible()) {
        // The normal snapshot already records missing, hidden and disabled
        // controls. Avoid a locator timeout while still reporting other states.
        if (!await candidate.locator(selector).count()||!await candidate.locator(selector).first().isVisible()||!await reference.locator(selector).first().isEnabled()||!await candidate.locator(selector).first().isEnabled()) continue;
        for (const state of states.filter(state=>state!=='normal')) {
          await reference.locator(selector).first()[state]();
          await candidate.locator(selector).first()[state]();
          await check(`${selector} ${state}`);
        }
      }
    }
    await reference.screenshot({path:path.join(output,`reference-${width}.png`),fullPage:true});
    await candidate.screenshot({path:path.join(output,`candidate-${width}.png`),fullPage:true});
    } finally {await context.close();}
  }
  const differenceCount=checks.reduce((sum,check)=>sum+check.differenceCount,0);
  const result=differenceCount?'FAIL':diagnostic?'PASS_DIAGNOSTIC':financeSearchNormalization?'PASS_WITH_KNOWN_LEGACY_DELTA':'PASS';
  save(result,{knownLegacyDelta:financeSearchNormalization?'finance search font weight 400 in frozen reference versus approved shared 500':undefined});
  console.log(`UI_PARITY=${result} differences=${differenceCount} report=${resultFile}`);
  if(differenceCount)process.exitCode=1;
} catch(error){
  save('FAIL',{error:error.message});
  throw error;
} finally {await browser?.close();}
