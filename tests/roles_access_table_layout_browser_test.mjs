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
const html = execFileSync(process.env.PLANEX_PHP || 'php', [path.join(root, 'tests/fixtures/user_max_id_render.php')], { encoding: 'utf8' });
const server = http.createServer((req, res) => {
    if (req.url === '/') {
        res.setHeader('Content-Type', 'text/html; charset=utf-8');
        res.end(html);
        return;
    }
    const file = path.resolve(root, 'public', '.' + req.url.split('?')[0]);
    if (!file.startsWith(path.join(root, 'public') + path.sep) || !fs.existsSync(file)) {
        res.statusCode = 404;
        res.end();
        return;
    }
    res.setHeader('Content-Type', file.endsWith('.css') ? 'text/css' : file.endsWith('.js') ? 'text/javascript' : 'application/octet-stream');
    res.end(fs.readFileSync(file));
});
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
const browser = await chromium.launch({ headless: true });

try {
    for (const width of [1440, 390]) {
        const page = await browser.newPage({ viewport: { width, height: 900 } });
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.goto(`http://127.0.0.1:${server.address().port}/`);

        const checkTable = async panel => {
            const geometry = await page.locator(panel).evaluate(element => {
                const workspace = element.closest('.roles-access__workspace');
                const card = element.querySelector('.roles-access__table-card');
                const scroll = element.querySelector('.table-scroll');
                const table = scroll.querySelector('table');
                const box = node => node.getBoundingClientRect();
                const visibleCells = [...table.querySelectorAll('thead th, tbody td')].filter(node => getComputedStyle(node).display !== 'none');
                return {
                    workspaceWidth: box(workspace).width,
                    panelWidth: box(element).width,
                    cardWidth: box(card).width,
                    tableWidth: box(table).width,
                    scrollWidth: scroll.scrollWidth,
                    clientWidth: scroll.clientWidth,
                    workspaceBorder: getComputedStyle(workspace).borderLeftWidth,
                    workspaceBackground: getComputedStyle(workspace).backgroundColor,
                    workspaceContentWidth: workspace.clientWidth,
                    headBottom: getComputedStyle(workspace.querySelector('.roles-access__head')).borderBottomWidth,
                    panelPadding: getComputedStyle(element).paddingLeft,
                    cardBorder: getComputedStyle(card).borderLeftWidth,
                    clippedCell: visibleCells.some(cell => box(cell).right > box(scroll).right + 1),
                };
            });
            assert.equal(geometry.workspaceBorder, '1px', `${width} ${panel}: standard workspace card border`);
            assert.notEqual(geometry.workspaceBackground, 'rgba(0, 0, 0, 0)', `${width} ${panel}: standard workspace card background`);
            assert.equal(geometry.headBottom, '1px', `${width} ${panel}: heading separator`);
            assert.equal(geometry.panelPadding, '0px', `${width} ${panel}: panel padding`);
            assert.equal(geometry.cardBorder, '0px', `${width} ${panel}: table frame`);
            assert.ok(Math.abs(geometry.panelWidth - geometry.workspaceContentWidth) <= 1, `${width} ${panel}: panel does not fill the card`);
            assert.ok(Math.abs(geometry.tableWidth - geometry.panelWidth) <= 1, `${width} ${panel}: table does not fill panel: ${JSON.stringify(geometry)}`);
            assert.ok(geometry.scrollWidth <= geometry.clientWidth + 1, `${width} ${panel}: horizontal scrolling: ${JSON.stringify(geometry)}`);
            assert.equal(geometry.clippedCell, false, `${width} ${panel}: clipped cell`);
        };

        await checkTable('#roles-access-roles');
        if (process.env.PLANEX_SCREENSHOT_DIR) {
            fs.mkdirSync(process.env.PLANEX_SCREENSHOT_DIR, { recursive: true });
            await page.screenshot({ path: path.join(process.env.PLANEX_SCREENSHOT_DIR, `roles-${width}.png`) });
        }
        await page.locator('[data-access-tab="users"]').click();
        if (width === 390) {
            await page.locator('#roles-access-users .roles-access__name').first().evaluate(element => {
                element.textContent = 'Спугов Владимир Вдамимирович';
            });
            const nameHeight = await page.locator('#roles-access-users .roles-access__name').first().evaluate(element => element.getBoundingClientRect().height);
            assert.ok(nameHeight > 20, 'long user name must wrap without clipping');
        }
        await checkTable('#roles-access-users');
        if (process.env.PLANEX_SCREENSHOT_DIR) {
            await page.screenshot({ path: path.join(process.env.PLANEX_SCREENSHOT_DIR, `users-${width}.png`) });
        }
        await page.locator('[data-membership-create]').click();
        await page.locator('.roles-access-modal.is-open [data-access-modal-form]').waitFor();
        await page.locator('.roles-access-modal.is-open .roles-access-modal__head [data-access-modal-close]').click();
        await page.locator('.roles-access-modal.is-open').waitFor({ state: 'hidden' });
        await page.locator('[data-access-tab="roles"]').click();
        await page.locator('[data-role-create]').click();
        await page.locator('.roles-access-modal.is-open [data-access-modal-form]').waitFor();
        await page.locator('.roles-access-modal.is-open .roles-access-modal__head [data-access-modal-close]').click();
        await page.locator('.roles-access-modal.is-open').waitFor({ state: 'hidden' });
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), true, `${width}: page horizontal scrolling`);
        assert.deepEqual(errors, [], `${width}: browser errors`);
        await page.close();
        console.log(`ROLES_ACCESS_TABLE_LAYOUT ${width}=PASS`);
    }
} finally {
    await browser.close();
    await new Promise(resolve => server.close(resolve));
}
