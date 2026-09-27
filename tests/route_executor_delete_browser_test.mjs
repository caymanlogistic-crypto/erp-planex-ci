import {createRequire} from 'node:module';
import {readFileSync} from 'node:fs';
import {resolve} from 'node:path';

const require = createRequire(import.meta.url);
const {chromium} = require('playwright');
const root = resolve(import.meta.dirname, '..');
const partial = readFileSync(resolve(root, 'app/View/partials/route_executor_delete_modal.php'), 'utf8');
const script = readFileSync(resolve(root, 'public/assets/js/route-executor-delete.js'), 'utf8');
const erpCss = readFileSync(resolve(root, 'public/assets/css/erp-ui.css'), 'utf8');
const appCss = readFileSync(resolve(root, 'public/assets/css/app-base.css'), 'utf8');
const browser = await chromium.launch({headless: true});
try {
    const page = await browser.newPage();
    await page.setContent(`<meta name="csrf-token" content="test-token"><style>${erpCss}\n${appCss}</style><button data-route-executor-delete-btn="7">Удалить</button>${partial}`);
    await page.evaluate(() => {
        window.getErpBasePath = () => '';
        window.openModal = id => document.getElementById(id).classList.add('is-open');
        window.closeModal = id => document.getElementById(id).classList.remove('is-open');
        window._deleteRequests = [];
        window._usage = {linear_count: 1, waste_count: 0};
        window.fetch = async (url, options) => {
            window._deleteRequests.push({url, fields: Object.fromEntries(options.body.entries())});
            const preview = Object.fromEntries(options.body.entries()).preview === '1';
            const data = preview
                ? {success: true, can_delete: !window._usage.linear_count && !window._usage.waste_count, usage: window._usage, preview_token: 'synthetic-token'}
                : {success: true};
            return {ok: true, json: async () => data};
        };
    });
    await page.addScriptTag({content: script});
    await page.locator('[data-route-executor-delete-btn]').click();
    await page.getByText('Удаление недоступно').waitFor();
    if (await page.locator('[data-route-executor-delete-confirm]').isEnabled()) throw new Error('Linked trip must block deletion.');
    if (await page.locator('[data-route-executor-delete-password-wrap]').isVisible()) throw new Error('Password must stay hidden for linked trip.');
    await page.locator('[data-route-executor-delete-close]').first().click();
    await page.evaluate(() => { window._usage = {linear_count: 0, waste_count: 0}; });
    await page.locator('[data-route-executor-delete-btn]').click();
    await page.locator('#route-executor-delete-password').waitFor({state: 'visible'});
    for (const width of [1440, 360]) {
        await page.setViewportSize({width, height: 900});
        const dimensions = await page.evaluate(() => ({
            modal: document.querySelector('#route-executor-delete-modal .modal').getBoundingClientRect().width,
            scroll: document.documentElement.scrollWidth,
            viewport: window.innerWidth
        }));
        if (dimensions.modal > width || dimensions.scroll > dimensions.viewport) throw new Error(`Deletion modal overflows at ${width}px.`);
    }
    if (await page.locator('[data-route-executor-delete-confirm]').isEnabled()) throw new Error('Password is required.');
    await page.locator('#route-executor-delete-password').fill('synthetic-password');
    if (!(await page.locator('[data-route-executor-delete-confirm]').isEnabled())) throw new Error('Password did not enable confirmation.');
    const requests = await page.evaluate(() => window._deleteRequests);
    if (requests.length !== 2 || requests.some(request => request.fields._csrf_token !== 'test-token')) throw new Error('Preview requests lack CSRF token.');
    console.log('ROUTE_EXECUTOR_DELETE_BROWSER=PASS');
} finally {
    await browser.close();
}
