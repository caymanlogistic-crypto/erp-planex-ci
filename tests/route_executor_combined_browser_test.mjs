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
const pageSource = fs.readFileSync(path.join(root, 'app/View/pages/company_route_executors.php'), 'utf8');
assert.match(pageSource, /data-route-executor-existing-create/);
assert.equal((pageSource.match(/<\?= \$routeExecutorExistingButton \?>/g) || []).length, 2, 'existing-data button must appear in both list header states');
const html = execFileSync(process.env.PLANEX_PHP || 'php', [path.join(root, 'tests/fixtures/route_executor_combined_render.php')], { encoding: 'utf8' });
const existingHtml = execFileSync(process.env.PLANEX_PHP || 'php', [path.join(root, 'tests/fixtures/route_executor_existing_create_render.php')], { encoding: 'utf8' });
const server = http.createServer((request, response) => {
    if (request.url === '/') { response.setHeader('Content-Type', 'text/html; charset=utf-8'); response.end(html); return; }
    if (request.url === '/company/route-executors/create') { response.setHeader('Content-Type', 'text/html; charset=utf-8'); response.end(existingHtml); return; }
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
        const executorRows = page.locator('[data-route-executors-grid] tbody tr');
        assert.equal(await executorRows.count(), 2);
        await page.locator('body > [data-route-executor-existing-create]').click();
        await page.locator('#route-executor-existing-form').waitFor();
        assert.equal(await page.locator('#route-executor-existing-modal.is-open').count(), 1);
        assert.equal(await page.locator('#route-executor-existing-form [name="contractor_id"]').count(), 1);
        assert.equal(await page.locator('#route-executor-existing-form [name="driver_ids[]"]').count(), 1);
        assert.equal(await page.locator('#route-executor-existing-form [name="vehicle_set_id"]').count(), 1);
        assert.equal(await page.locator('.route-executor-existing-modal-inner').evaluate(element => element.scrollWidth > element.clientWidth + 1), false, 'existing-data popup horizontal overflow at ' + width);
        await page.locator('#route-executor-existing-modal [data-route-executor-existing-close]').first().click();
        assert.equal(await page.locator('#route-executor-existing-modal.is-open').count(), 0);
        assert.equal(await page.locator('[data-route-executors-grid] tbody tr:visible').count(), 2, 'closing existing-data popup must keep executor rows visible');
        await page.locator('[data-route-executor-create-btn]').click();
        await page.locator('#route-executor-create-modal .modal-close').click();
        assert.equal(await page.locator('#route-executor-create-modal.is-open').count(), 0);
        assert.equal(await page.locator('[data-route-executors-grid] tbody tr:visible').count(), 2, 'closing with X must keep executor rows visible');
        await page.locator('[data-route-executor-create-btn]').click();
        await page.locator('#route-executor-create-modal-foot [data-route-executor-create-close]').click();
        assert.equal(await page.locator('#route-executor-create-modal.is-open').count(), 0);
        assert.equal(await page.locator('[data-route-executors-grid] tbody tr:visible').count(), 2, 'Cancel must keep executor rows visible');
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
        assert.equal(await page.locator('#route-executor-responsible-logist[required]').count(), 1);
        assert.equal(await page.locator('#route-executor-responsible-logist option').count(), 3);
        assert.equal(await page.locator('#route-executor-create-modal [data-route-executor-existing-create]').textContent(), 'Выбрать из имеющихся');
        assert.equal(await page.locator('[data-readiness-key="training"][data-state="pending"]').count(), 1);
        await page.locator('#lab-driver-form [name="full_name"]').fill('Иванов Иван Иванович');
        await page.locator('#lab-driver-form [name="snils"]').fill('123-456-789 00');
        assert.equal(await page.locator('[data-readiness-key="training"][data-state="ready"]').count(), 1);
        await page.locator('#lab-driver-form [name="snils"]').fill('');
        assert.equal(await page.locator('[data-readiness-key="training"][data-state="pending"]').count(), 1);
        assert.equal(await page.locator('[data-readiness-key="contract"][data-state="pending"]').count(), 1);
        const contractor = page.locator('#lab-contractor-form');
        await contractor.locator('[name="contractor_type"]').selectOption('individual');
        for (const [name, value] of Object.entries({
            name: 'ИП Кириченко Владимир Анатольевич', inn: '392002231443',
            kpp: '30101810200000000593', ogrn: '326508100388050',
            director_full_name: 'Иванов Иван Иванович', director_position: 'Руководитель',
            legal_address: 'Московская область, Щёлково',
            'contacts[0][contact_person]': 'Контактное лицо',
            'contacts[0][phone]': '+7 333 333-33-33',
            'contacts[0][email]': 'contact@example.test',
            bank_account: '40802810515360001210', bank_bik: '044525593',
            bank_name: 'АО Альфа-Банк', bank_corr_account: '30101810200000000593'
        })) await contractor.locator(`[name="${name}"]`).fill(value);
        assert.equal(await contractor.locator('[name="physical_address"]').inputValue(), '');
        assert.equal(await contractor.locator('[name="comments"]').inputValue(), '');
        assert.equal(await contractor.locator('[data-contact-comment]').inputValue(), '');
        assert.equal(await page.locator('[data-readiness-key="contract"][data-state="ready"]').count(), 1,
            'agreement status must ignore optional addresses, comments and files');
        await contractor.locator('[name="legal_address"]').fill('');
        assert.equal(await page.locator('[data-readiness-key="contract"][data-state="pending"]').count(), 1);
        await contractor.locator('[name="legal_address"]').fill('Московская область, Щёлково');
        assert.equal(await page.locator('[data-readiness-key="contract"][data-state="ready"]').count(), 1);
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
            await page.locator('#route-executor-responsible-logist').selectOption('12');
            const file = { name: 'synthetic.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4 synthetic') };
            await page.locator('#lab-contractor-form [name="predef_doc[company_card]"]').setInputFiles(file);
            await page.locator('#lab-driver-form [name="predef_doc[passport][]"]').setInputFiles(file);
            await page.locator('#lab-vehicle-form [name="predef_doc[primary][sts][]"]').setInputFiles(file);
            await page.locator('[data-source-create-submit]').click();
            await page.locator('[data-combined-feedback]:not(.is-hidden)').waitFor();
            for (const name of ['responsible_logist_membership_id', 'contractor[name]', 'contractor[contacts][0][contact_person]', 'driver[phone]', 'vehicle[set_type]', 'contractor_predef_doc[company_card]', 'driver_predef_doc[passport][]', 'vehicle_predef_doc[primary][sts][]']) assert.ok(payload.includes('name="' + name + '"'), 'missing multipart field ' + name);
            assert.ok(payload.includes('name="responsible_logist_membership_id"\r\n\r\n12'), 'selected logist must be submitted');
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
