import {createRequire} from 'node:module';
import {execFileSync} from 'node:child_process';
import {readFileSync} from 'node:fs';
import {resolve,dirname} from 'node:path';
import {fileURLToPath} from 'node:url';
const require=createRequire(import.meta.url);
const {chromium}=require(process.env.PLANEX_PLAYWRIGHT_MODULE || 'playwright');
const root=resolve(dirname(fileURLToPath(import.meta.url)),'..');
const html=execFileSync(process.env.PLANEX_PHP_BINARY || 'php',[resolve(root,'tests/max_events_ui_fixture.php')],{encoding:'utf8'});
const browser=await chromium.launch({headless:true,...(process.env.PLANEX_CHROMIUM_PATH?{executablePath:process.env.PLANEX_CHROMIUM_PATH}:{})});
try {
    const page=await browser.newPage({viewport:{width:1440,height:1100}});
    const errors=[];page.on('pageerror',e=>errors.push(e.message));
    let previewRequests=0;
    await page.route('https://max-fixture.local/**',async route=>{
        const url=new URL(route.request().url());
        if(url.pathname.startsWith('/assets/')) {
            try {const body=readFileSync(resolve(root,'public'+url.pathname));await route.fulfill({body,contentType:url.pathname.endsWith('.js')?'application/javascript':'text/css'});}catch{await route.fulfill({status:404,body:''});}
        } else if(url.pathname.endsWith('/events/preview')) {
            previewRequests++;await route.fulfill({json:{success:true,text:'Пример <b>без выполнения HTML</b>'}});
        } else await route.fulfill({contentType:'text/html',body:html});
    });
    await page.goto('https://max-fixture.local/company/max/settings');
    await page.locator('[data-max-tab="events"]').click();
    if(!await page.locator('#max-events').isVisible() || await page.locator('#max-overview').isVisible())throw new Error('Event tab not exclusive');
    const template=page.locator('[name="message_template"]');
    await template.fill('Блок: ');
    await page.getByRole('button',{name:'Номер блока',exact:true}).click();
    if(await template.inputValue()!=='Блок: {{block_id}}')throw new Error('Variable insertion failed');
    await page.locator('[data-max-event-preview]').click();
    await page.getByText('Пример <b>без выполнения HTML</b>',{exact:true}).waitFor();
    if(await page.locator('[data-max-event-preview-text] b').count())throw new Error('Preview injected HTML');
    if(previewRequests!==1)throw new Error('Preview transport mismatch');
    await page.locator('[data-max-event-default]').click();
    if(!(await template.inputValue()).includes('{{author}}'))throw new Error('Default template restore failed');
    await page.reload();
    if(!await page.locator('#max-events').isVisible())throw new Error('Event tab not persisted');
    if(process.env.PLANEX_UI_SCREENSHOT)await page.screenshot({path:process.env.PLANEX_UI_SCREENSHOT,fullPage:true});
    await page.setViewportSize({width:390,height:900});
    if(await page.evaluate(()=>document.documentElement.scrollWidth>window.innerWidth+2))throw new Error('Mobile page overflows horizontally');
    if(errors.length)throw new Error(errors.join('\n'));
    console.log('MAX_EVENTS_UI=PASS tabs variables preview safe-text defaults responsive');
} finally {await browser.close();}
