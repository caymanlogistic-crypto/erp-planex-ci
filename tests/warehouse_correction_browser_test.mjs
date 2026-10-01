import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {spawnSync} from 'node:child_process';
import {pathToFileURL} from 'node:url';

const root = path.resolve(import.meta.dirname, '..');
const playwright = await import(pathToFileURL(path.join(process.env.NODE_PATH || 'C:/plan-ex/ERP-PLANEX/local/node_modules', 'playwright', 'index.mjs')).href);
const php = process.env.PLANEX_PHP || 'php';
const rendered = spawnSync(php, ['tests/fixtures/warehouse_correction_render.php'], {cwd:root, encoding:'utf8'});
assert.equal(rendered.status, 0, rendered.stderr);
const browser = await playwright.chromium.launch({headless:true});
try {
    for (const width of [1440, 390]) {
        const page = await browser.newPage({viewport:{width,height:900}});
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        let submitted = false;
        let matched = false;
        await page.route('http://warehouse.test/**', async route => {
            const url = new URL(route.request().url());
            if (url.pathname === '/company/warehouses/stock') return route.fulfill({status:200, contentType:'text/html; charset=utf-8',body:rendered.stdout});
            if (url.pathname === '/assets/js/warehouse-stock.js') return route.fulfill({status:200, contentType:'text/javascript',body:fs.readFileSync(path.join(root,'public/assets/js/warehouse-stock.js'),'utf8')});
            if (url.pathname.endsWith('.css')) return route.fulfill({status:200, contentType:'text/css',body:fs.readFileSync(path.join(root,'public/assets/css/warehouse-stock.css'),'utf8')});
            if (url.pathname.endsWith('/correction-preview')) {
                const outgoing = url.searchParams.get('route_type') === 'warehouse_to_utilizer';
                return route.fulfill({status:200, contentType:'application/json',body:JSON.stringify({flight_id:20,name:'Первый рейс',status:'completed',current_route:'generator_to_utilizer',request_count:2,movement_request_count:1,route_type:url.searchParams.get('route_type'),source_warehouse_id:outgoing?1:0,destination_warehouse_id:outgoing?0:1,warehouses:{1:'Иркутск'},rows:[{request_id:120,mass_netto:'1.000',will_move:true},{request_id:121,mass_netto:'0.500',will_move:false}],operation_count:1,errors:[],stock_warnings:outgoing?[{request_id:121,available_netto:0,required_netto:'0.500',absent:true}]:[],ready:true,fingerprint:'a'.repeat(64)})});
            }
            if (url.pathname.endsWith('/request-reconciliation-preview')) {
                return route.fulfill({status:200, contentType:'application/json',body:JSON.stringify({flight_id:21,warehouse_id:2,direction:'out',before_ids:['121'],after_ids:['999'],remove_ids:['121'],add_ids:['999'],change_mass_ids:[],before_kg:1000,after_kg:500,errors:[],ready:true,fingerprint:'b'.repeat(64)})});
            }
            if (url.pathname.endsWith('/request-reconciliation')) {
                matched = route.request().postData()?.includes('fingerprint=' + 'b'.repeat(64)) ?? false;
                return route.fulfill({status:200, contentType:'application/json',body:JSON.stringify({flight_id:21,removed:1,created:1,updated:0})});
            }
            if (url.pathname.endsWith('/correction')) {
                submitted = route.request().postData()?.includes('fingerprint=' + 'a'.repeat(64)) ?? false;
                return route.fulfill({status:200, contentType:'application/json',body:JSON.stringify({flight_id:20,created:1})});
            }
            return route.fulfill({status:404,body:''});
        });
        await page.goto('http://warehouse.test/company/warehouses/stock');
        assert.equal(await page.locator('[data-wh-warehouse]:visible').count(), 1);
        assert.match(await page.locator('[data-wh-warehouse="1"] .wh-current-summary').innerText(), /Заявки на складе: 120/);
        await page.locator('[name=show_all]').check({force:true});
        assert.equal(await page.locator('[data-wh-warehouse]:visible').count(), 2);
        assert.match(await page.locator('[data-wh-warehouse="2"] .wh-current-summary').innerText(), /Заявки на складе: —/);
        const differentRow = page.locator('[data-wh-trip="2:21:out"]');
        assert.match(await differentRow.locator('.wh-request-cell').innerText(), /121\s+В рейсе доп: 999/);
        assert.equal(await differentRow.locator('.wh-request-id--outside-trip').innerText(), '121');
        assert.equal(await differentRow.locator('.wh-request-id--outside-trip').evaluate(el => getComputedStyle(el).textDecorationLine), 'line-through');
        await differentRow.dblclick();
        assert.match(await page.locator('#wh-trip-2-21-out').innerText(), /В рейсе доп: 999/);
        assert.equal(await page.locator('#wh-trip-2-21-out [data-wh-match-trip]').count(), 1);
        await page.locator('#wh-trip-2-21-out [data-wh-close]').first().click();
        await page.locator('[name=q]').fill('999');
        assert.equal(await page.locator('[data-wh-trip]:visible').count(), 1);
        await page.locator('[name=q]').fill('');
        await page.locator('[name=q]').fill('Первый');
        assert.equal(await page.locator('[data-wh-trip]:visible').count(), 1);
        await page.locator('[name=q]').fill('');
        await page.locator('[name=warehouse]').selectOption('2');
        assert.equal(await page.locator('[data-wh-trip]:visible').count(), 1);
        await page.locator('[name=warehouse]').selectOption('0');
        await page.locator('[data-wh-correct]').click();
        await page.locator('[name=flight_id]').fill('20');
        await page.locator('[name=route_type][value=warehouse_to_utilizer]').check();
        await page.locator('[name=source_warehouse_id]').selectOption('1');
        await page.locator('[data-correction-confirm]').waitFor({state:'visible'});
        await page.waitForFunction(() => !document.querySelector('[data-correction-confirm]').disabled);
        assert.match(await page.locator('[data-correction-preview]').innerText(), /заявки, которых нет на складе «Иркутск»: #121/);
        assert.match(await page.locator('[data-correction-preview]').innerText(), /Складское движение: 1 заявок · 1\s?000 кг/);
        await page.locator('[data-correction-confirm]').click();
        await page.waitForFunction(() => document.querySelector('[data-correction-preview]').textContent.includes('Проведено складских движений: 1'));
        assert.equal(submitted, true);
        assert.match(await page.locator('[data-correction-preview]').innerText(), /Проведено складских движений: 1/);
        await page.locator('.wh-correction-modal [data-correction-close]').first().click();
        assert.equal(await page.locator('#warehouse-issues [data-wh-match-trip]').count(), 1);
        await page.locator('#warehouse-issues [data-wh-match-trip]').click();
        await page.waitForFunction(() => !document.querySelector('[data-wh-match-confirm]').disabled);
        assert.match(await page.locator('[data-wh-match-preview]').innerText(), /Масса движения: 1\s?000 → 500 кг/);
        await page.locator('[data-wh-match-confirm]').click();
        await page.waitForFunction(() => document.querySelector('[data-wh-match-preview]').textContent.includes('приведён к составу ERP'));
        assert.equal(matched, true);
        assert.deepEqual(errors, []);
        await page.close();
    }
} finally { await browser.close(); }
console.log('warehouse correction browser OK');
