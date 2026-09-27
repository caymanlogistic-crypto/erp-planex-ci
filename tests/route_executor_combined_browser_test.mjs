import assert from 'node:assert/strict';
import fs from 'node:fs';
import http from 'node:http';
import path from 'node:path';
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const require = createRequire(import.meta.url);
const { chromium } = require('playwright');
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const html = execFileSync(process.env.PLANEX_PHP || 'php', [path.join(root, 'tests/fixtures/route_executor_combined_render.php')], { encoding: 'utf8' });
const server = http.createServer((request, response) => {
    if (request.url === '/') { response.setHeader('Content-Type', 'text/html; charset=utf-8'); response.end(html); return; }
    const file = path.resolve(root, 'public', '.' + request.url.split('?')[0]);
    if (!file.startsWith(path.join(root, 'public') + path.sep) || !fs.existsSync(file)) { response.statusCode = 404; response.end(); return; }
    response.setHeader('Content-Type', file.endsWith('.css') ? 'text/css' : 'text/javascript');
    response.end(fs.readFileSync(file));
});
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
const browser = await chromium.launch({ headless: true });
try {
    for (const width of [1280, 390]) {
        const page = await browser.newPage({ viewport: { width, height: 900 } });
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.goto('http://127.0.0.1:' + server.address().port + '/');
        await page.locator('[data-route-executor-create-btn]').click();
        if (process.env.PLANEX_ROUTE_EXECUTOR_SCREENSHOT_DIR) {
            fs.mkdirSync(process.env.PLANEX_ROUTE_EXECUTOR_SCREENSHOT_DIR, { recursive: true });
            await page.waitForTimeout(300);
            await page.screenshot({ path: path.join(process.env.PLANEX_ROUTE_EXECUTOR_SCREENSHOT_DIR, 'combined-' + width + '.png') });
        }
        assert.equal(await page.locator('#route-executor-create-modal.is-open').count(), 1);
        assert.equal(await page.locator('.source-combined-main').count(), 3);
        assert.equal(await page.locator('.source-combined-docs').count(), 3);
        if (width === 1280) {
            const rows = await page.locator('.source-combined-form').evaluateAll(forms => forms.map(form => {
                const main = form.querySelector('.source-combined-main').getBoundingClientRect();
                const docs = form.querySelector('.source-combined-docs').getBoundingClientRect();
                return [Math.round(main.y), Math.round(docs.y), main.x < docs.x];
            }));
            assert.ok(rows.every(([mainY, docsY, correctOrder]) => mainY === docsY && correctOrder), 'left/right form rows must align');
        }
        assert.equal(await page.locator('#lab-contractor-form [name="bank_inn"]').count(), 0);
        assert.equal(await page.locator('#lab-driver-form [name="license_issue_date"]').count(), 1);
        assert.equal(await page.locator('[data-readiness-key="training"][data-state="pending"]').count(), 1);
        await page.locator('#lab-driver-form [name="full_name"]').fill('Иванов Иван Иванович');
        await page.locator('#lab-driver-form [name="snils"]').fill('123-456-789 00');
        assert.equal(await page.locator('[data-readiness-key="training"][data-state="ready"]').count(), 1);
        await page.locator('#lab-driver-form [name="snils"]').fill('');
        assert.equal(await page.locator('[data-readiness-key="training"][data-state="pending"]').count(), 1);
        if (width === 1280) {
            let payload = '';
            await page.route('**/company/route-executors/create', async route => {
                payload = route.request().postData() || '';
                await route.fulfill({ status: 422, json: { ok: false, error: 'Синтетическая ошибка сохранения' } });
            });
            await page.locator('#lab-contractor-form [name="name"]').fill('ООО Тест');
            await page.locator('#lab-contractor-form [name="inn"]').fill('7701234567');
            await page.locator('#lab-driver-form [name="phone"]').fill('+7 900 000-00-00');
            await page.locator('#lab-vehicle-form [name="set_type"]').selectOption('single');
            const file = { name: 'synthetic.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4 synthetic') };
            await page.locator('#lab-contractor-form [name="predef_doc[company_card]"]').setInputFiles(file);
            await page.locator('#lab-driver-form [name="predef_doc[passport][]"]').setInputFiles(file);
            await page.locator('#lab-vehicle-form [name="predef_doc[primary][sts][]"]').setInputFiles(file);
            await page.locator('[data-source-create-submit]').click();
            await page.locator('[data-combined-feedback]:not(.is-hidden)').waitFor();
            for (const name of ['contractor[name]', 'contractor[contacts][0][contact_person]', 'driver[phone]', 'vehicle[set_type]', 'contractor_predef_doc[company_card]', 'driver_predef_doc[passport][]', 'vehicle_predef_doc[primary][sts][]']) assert.ok(payload.includes('name="' + name + '"'), 'missing multipart field ' + name);
        }
        const overflows = await page.locator('#route-executor-create-modal-inner').evaluate(element => element.scrollWidth > element.clientWidth + 1);
        assert.equal(overflows, false, 'modal horizontal overflow at ' + width);
        assert.deepEqual(errors, [], 'browser errors at ' + width);
        await page.close();
    }
    console.log('ROUTE_EXECUTOR_COMBINED_BROWSER=PASS');
} finally {
    await browser.close();
    server.close();
}
