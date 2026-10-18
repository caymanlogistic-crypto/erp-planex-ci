import assert from 'node:assert/strict';
import {execFileSync,spawn} from 'node:child_process';
import {mkdtempSync,readFileSync,existsSync,rmSync,writeFileSync} from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import http from 'node:http';
import {createRequire} from 'node:module';
import {fileURLToPath,pathToFileURL} from 'node:url';
import {assertAppearanceParity} from './helpers/ui_appearance.mjs';

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
const candidate=http.createServer((request,response)=>{
  if(request.url.startsWith('/assets/')){
    const file=path.resolve(root,'public','.'+request.url.split('?')[0]);
    if(!file.startsWith(path.join(root,'public')+path.sep)||!existsSync(file)){response.writeHead(404).end();return}
    response.setHeader('Content-Type',file.endsWith('.css')?'text/css':file.endsWith('.js')?'text/javascript':file.endsWith('.woff2')?'font/woff2':'application/octet-stream');
    response.end(readFileSync(file));return;
  }
  response.setHeader('Content-Type','text/html; charset=utf-8');response.end(html);
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
  const parity=spawn(process.execPath,[path.join(root,'tools/ui_parity.mjs'),'--reference-url',`${lab.url}/company/trips/departures`,'--candidate-url',`http://127.0.0.1:${candidate.address().port}/company/trips/departures`,'--output',path.join(temporary,'parity')],{cwd:root,windowsHide:true});
  let parityOutput='';parity.stdout.on('data',data=>{parityOutput+=data});parity.stderr.on('data',data=>{parityOutput+=data});
  assert.equal(await new Promise(resolve=>parity.once('exit',resolve)),0,parityOutput);
  assert.equal(JSON.parse(readFileSync(path.join(temporary,'parity/result.json'),'utf8')).result,'PASS');
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
