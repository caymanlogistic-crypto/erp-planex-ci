import assert from 'node:assert/strict';
import {execFileSync,spawn} from 'node:child_process';
import {mkdtempSync,readFileSync,existsSync,rmSync,writeFileSync} from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import http from 'node:http';
import {createRequire} from 'node:module';
import {fileURLToPath,pathToFileURL} from 'node:url';
import {assertAppearanceParity,collectAppearanceDifferences,compareAppearanceSnapshots,appearance} from './helpers/ui_appearance.mjs';

const require=createRequire(import.meta.url),{chromium}=require('playwright');
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const temporary=mkdtempSync(path.join(os.tmpdir(),'planex-ui-lab-'));
const python=process.env.PLANEX_PYTHON||'python3';
// Windows venv python.exe is a launcher. Spawn its exact underlying interpreter
// so the owned child PID is the PID that actually listens and can be cleaned up.
const serverPython=process.platform==='win32'?execFileSync(python,['-c','import sys; print(sys._base_executable)'],{encoding:'utf8'}).trim():python;
const tool=path.join(root,'tools/design_lab.py');
const processes=[];
const run=(...args)=>execFileSync(python,[tool,...args],{cwd:root,encoding:'utf8'});
const start=async(task,preset)=>{
  run('create','--task-id',task,'--worktree',root,'--local-root',temporary,'--preset',preset);
  const ready=path.join(temporary,`${task}.ready.json`);
  const child=spawn(serverPython,[tool,'serve','--task-id',task,'--local-root',temporary,'--host','127.0.0.1','--port','0','--ready-file',ready],{cwd:root,stdio:['ignore','ignore','pipe'],windowsHide:true});
  let errors='';child.stderr.on('data',data=>{errors+=data});processes.push(child);
  for(let i=0;i<120&&!existsSync(ready);i++){
    assert.equal(child.exitCode,null,errors);await new Promise(resolve=>setTimeout(resolve,50));
  }
  assert.ok(existsSync(ready),errors||'sandbox readiness timeout');
  const state=JSON.parse(readFileSync(ready,'utf8'));
  assert.equal(state.pid,child.pid,'the test owns this exact sandbox process');
  return {url:`http://127.0.0.1:${state.port}`,session:path.join(temporary,'design-lab',task)};
};
const html=execFileSync(process.env.PLANEX_PHP||'php',[path.join(root,'tests/fixtures/waste_trips_design_lab_render.php')],{encoding:'utf8',maxBuffer:32*1024*1024});
let candidateOverride='';
const candidate=http.createServer((request,response)=>{
  if(request.url.startsWith('/assets/')){
    const file=path.resolve(root,'public','.'+request.url.split('?')[0]);
    if(!file.startsWith(path.join(root,'public')+path.sep)||!existsSync(file)){response.writeHead(404).end();return}
    response.setHeader('Content-Type',file.endsWith('.css')?'text/css':file.endsWith('.js')?'text/javascript':file.endsWith('.woff2')?'font/woff2':'application/octet-stream');
    response.end(readFileSync(file));return;
  }
  response.setHeader('Content-Type','text/html; charset=utf-8');response.end(html+candidateOverride);
});
await new Promise(resolve=>candidate.listen(0,'127.0.0.1',resolve));
const browser=await chromium.launch({headless:true});
try{
  const gallery=await start('ui-gallery','ui-standard');
  const lab=await start('ui-page','waste-trips');
  const sourceAsset=readFileSync(path.join(root,'public/assets/css/registry-components.css'));
  assert.deepEqual(Buffer.from(await (await fetch(`${lab.url}/assets/css/registry-components.css`)).arrayBuffer()),sourceAsset,'sandbox serves exact shared CSS bytes');
  for(const width of [390,1280,1920,3840]){
    const context=await browser.newContext({viewport:{width,height:1080},deviceScaleFactor:1,locale:'ru-RU',timezoneId:'Europe/Moscow'});
    const galleryPage=await context.newPage(),offline=await context.newPage();
    await galleryPage.goto(`${gallery.url}/ui-standard`);
    await offline.goto(pathToFileURL(path.join(root,'docs/ui/approved-components.html')).href);
    await galleryPage.frameLocator('iframe[data-preview="registry.composition"]').locator('[data-gallery-view=executors] .registry-ui-search').waitFor();
    await offline.frameLocator('iframe[data-preview="registry.composition"]').locator('[data-gallery-view=executors] .registry-ui-search').waitFor();
    const frame=page=>page.frames().find(item=>item.parentFrame());
    await assertAppearanceParity(frame(galleryPage),frame(offline),`${width}px gallery and sandbox`);
    const reference=await context.newPage(),actual=await context.newPage();
    await reference.goto(`${lab.url}/company/trips/departures`);
    await actual.goto(`http://127.0.0.1:${candidate.address().port}/company/trips/departures`);
    await assertAppearanceParity(reference,actual,`${width}px sandbox and application fixture`);
    // A migration may add semantic markers/value wrappers without changing a
    // single visible pixel. The immutable reference must not need DOM edits.
    await actual.locator('.waste-description-text').first().evaluate(element=>{
      element.classList.add('registry-ui-trip');
      const value=document.createElement('span');value.textContent=element.textContent;
      element.textContent='';element.append(value);
    });
    await assertAppearanceParity(reference,actual,`${width}px semantic marker and value wrapper migration`);
    await actual.locator('.waste-description-text > span').first().evaluate(element=>{element.style.fontWeight='700';});
    await assert.rejects(()=>assertAppearanceParity(reference,actual,`${width}px child typography drift`),/sandbox and implementation differ/);
    await actual.locator('.waste-description-text > span').first().evaluate(element=>element.style.removeProperty('font-weight'));
    await actual.locator('.registry-ui-table').evaluate(element=>{element.style.font='400 13px/18.2px serif';});
    await assert.rejects(()=>assertAppearanceParity(reference,actual,`${width}px structural font drift`),/sandbox and implementation differ/);
    await actual.locator('.registry-ui-table').evaluate(element=>{element.style.removeProperty('font');});
    const pseudoBase='.registry-ui-table tbody td:first-child::before{content:"•";color:#131210;display:inline-block}';
    const referencePseudo=await reference.addStyleTag({content:pseudoBase});
    const actualPseudo=await actual.addStyleTag({content:pseudoBase});
    await assertAppearanceParity(reference,actual,`${width}px matching generated content`);
    const pseudo=await actual.addStyleTag({content:'.registry-ui-table tbody td:first-child::before{color:red!important}'});
    await assert.rejects(()=>assertAppearanceParity(reference,actual,`${width}px generated content color drift`),/sandbox and implementation differ/);
    await pseudo.evaluate(element=>element.remove());
    await referencePseudo.evaluate(element=>element.remove());
    await actualPseudo.evaluate(element=>element.remove());
    const placeholder=await actual.addStyleTag({content:'.registry-ui-search::placeholder{color:red!important}'});
    await assert.rejects(()=>assertAppearanceParity(reference,actual,`${width}px placeholder color drift`),/sandbox and implementation differ/);
    await placeholder.evaluate(element=>element.remove());
    await actual.locator('.registry-ui-search').evaluate(element=>{element.value='Wrong';});
    await assert.rejects(()=>assertAppearanceParity(reference,actual,`${width}px search value drift`),/sandbox and implementation differ/);
    await actual.locator('.registry-ui-search').evaluate(element=>{element.value='';element.disabled=true;});
    await assert.rejects(()=>assertAppearanceParity(reference,actual,`${width}px search enabled state drift`),/sandbox and implementation differ/);
    await actual.locator('.registry-ui-search').evaluate(element=>{element.disabled=false;});
    if(width===390){
      // One review batch must contain independent real rendered differences,
      // and limiting stored examples must never lower the failure count.
      const drift=await actual.addStyleTag({content:'.registry-ui-search::placeholder{color:red!important}'});
      await actual.locator('.registry-ui-search').evaluate(element=>{element.value='Wrong';});
      await actual.locator('.waste-description-text > span').first().evaluate(element=>{element.style.fontWeight='700';});
      const batch=await collectAppearanceDifferences(reference,actual,'batched rendered differences');
      for(const property of ['value','placeholderColor','fontWeight'])assert.ok(batch.differences.some(diff=>diff.property===property),`batch missing ${property}`);
      const capped=compareAppearanceSnapshots(await appearance(reference),await appearance(actual),'bounded examples',1);
      assert.equal(capped.differenceCount,batch.differenceCount);
      assert.equal(capped.differences.length,1);assert.equal(capped.truncated,true);
      assert.deepEqual(capped.summary,batch.summary,'bounded examples must retain every independent property group');
      await drift.evaluate(element=>element.remove());
      await actual.locator('.registry-ui-search').evaluate(element=>{element.value='';});
      await actual.locator('.waste-description-text > span').first().evaluate(element=>element.style.removeProperty('font-weight'));
    }
    for(const page of [reference,actual])await page.locator('.registry-ui-toolbar').evaluate(toolbar=>{const button=document.createElement('button');button.className='parity-hidden-probe';button.hidden=true;button.textContent='Проверка';toolbar.append(button);});
    await actual.locator('.parity-hidden-probe').evaluate(element=>{element.style.border='1px solid red';});
    await assertAppearanceParity(reference,actual,`${width}px hidden control style is not painted`);
    for(const page of [reference,actual])await page.locator('.parity-hidden-probe').evaluate(element=>{element.hidden=false;});
    await assert.rejects(()=>assertAppearanceParity(reference,actual,`${width}px visible control style drift`),/sandbox and implementation differ/);
    for(const page of [reference,actual])await page.locator('.parity-hidden-probe').evaluate(element=>element.remove());
    for(const state of ['hover','focus']){
      await reference.locator('.registry-ui-search')[state]();await actual.locator('.registry-ui-search')[state]();
      await assertAppearanceParity(reference,actual,`${width}px ${state}`);
    }
    const hostile=await actual.addStyleTag({content:'.registry-ui-search{height:37px!important}'});
    await assert.rejects(()=>assertAppearanceParity(reference,actual,`${width}px deliberate local override`),/sandbox and implementation differ/);
    await hostile.evaluate(element=>element.remove());
    await context.close();
  }
  const common=['--task-id','ui-page','--local-root',temporary];
  run('checkpoint',...common,'--note','shared UI parity verified');
  run('accept',...common,'--version','v001');
  const acceptance=JSON.parse(readFileSync(path.join(lab.session,'acceptance.json'),'utf8'));
  assert.ok(acceptance.ui_assets['public/assets/css/registry-components.css']);
  run('verify',...common,'--against-worktree');
  // The real handoff command must compare accepted v001 even after the mutable
  // workspace has moved on to a different experiment.
  writeFileSync(path.join(lab.session,'workspace/overrides.css'),'.registry-ui-search{height:51px!important}');
  const immutable=await fetch(`${lab.url}/__design_lab__/overrides.css?version=accepted`);
  assert.ok(!(await immutable.text()).includes('51px'));
  const runParity=async(name,extra=[])=>{
    const output=path.join(temporary,name);
    const child=spawn(process.execPath,[path.join(root,'tools/ui_parity.mjs'),'--reference-url',`${lab.url}/company/trips/departures`,'--candidate-url',`http://127.0.0.1:${candidate.address().port}/company/trips/departures`,'--output',output,...extra],{cwd:root,windowsHide:true});
    let text='';child.stdout.on('data',data=>{text+=data});child.stderr.on('data',data=>{text+=data});
    const code=await new Promise((resolve,reject)=>{child.once('exit',resolve);child.once('error',reject)});
    return {code,text,report:JSON.parse(readFileSync(path.join(output,'result.json'),'utf8'))};
  };
  const full=await runParity('parity');
  assert.equal(full.code,0,full.text);assert.equal(full.report.result,'PASS');
  assert.equal(full.report.diagnostic,false);assert.deepEqual(full.report.widths,[390,1280,1920,3840]);
  const focused=await runParity('focused',['--widths','390','--states','normal']);
  assert.equal(focused.code,0,focused.text);assert.equal(focused.report.result,'PASS_DIAGNOSTIC');
  assert.equal(focused.report.diagnostic,true);assert.equal(focused.report.checks.length,1);
  candidateOverride='<style>.registry-ui-search{height:37px!important}.registry-ui-search::placeholder{color:red!important}</style>';
  const broken=await runParity('broken',['--widths','390,1280','--states','normal']);
  assert.equal(broken.code,1,broken.text);assert.equal(broken.report.result,'FAIL');
  assert.deepEqual(broken.report.checks.map(check=>check.width),[390,1280],'continue after the first mismatched viewport');
  assert.ok(broken.report.differenceCount>1);
  for(const property of ['height','placeholderColor'])assert.ok(broken.report.checks[0].summary.some(diff=>diff.property===property),`CLI batch missing ${property}`);
  const frozen=path.join(lab.session,'ui-assets/public/assets/css/registry-components.css');
  writeFileSync(frozen,'/* tampered frozen source */');
  assert.throws(()=>run('verify',...common),/UI_ASSET_HASH_MISMATCH/);
  console.log('UI_STANDARD_LAB_BROWSER=PASS');
}finally{
  await browser.close();await new Promise(resolve=>candidate.close(resolve));
  for(const child of processes){if(child.exitCode===null){const done=new Promise(resolve=>child.once('exit',resolve));child.kill();await done}}
  assert.equal(path.dirname(path.resolve(temporary)),path.resolve(os.tmpdir()));
  assert.ok(path.basename(temporary).startsWith('planex-ui-lab-'));
  rmSync(temporary,{recursive:true,force:true});
}
