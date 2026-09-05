const { chromium } = require('playwright');
const fs = require('fs');

const base = 'https://plan-ex.ru/erpv2';
const cookieName = process.env.QA_COOKIE_NAME;
const sid = process.env.QA_OWNER_SESSION_ID;
const out = process.env.RESULT_DIR || 'roles_access_131_result';
if (!cookieName || !sid) throw new Error('QA session env missing');
fs.mkdirSync(out, { recursive: true });

function must(condition, message) {
  if (!condition) throw new Error(message);
}

(async () => {
  const browser = await chromium.launch({ headless: true });
  try {
    const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
    await context.addCookies([{ name: cookieName, value: sid, domain: 'plan-ex.ru', path: '/', secure: true, httpOnly: true }]);
    const page = await context.newPage();
    const response = await page.goto(base + '/company/roles-access', { waitUntil: 'networkidle', timeout: 60000 });
    must(response && response.status() === 200, 'roles page HTTP is not 200');
    await page.getByRole('heading', { name: 'Роли и доступы' }).waitFor({ state: 'visible' });

    async function assertLayout(label) {
      const state = await page.evaluate(() => ({
        width: document.documentElement.clientWidth,
        scrollWidth: document.documentElement.scrollWidth,
        openOverlays: document.querySelectorAll('.modal-overlay.is-open').length,
      }));
      must(state.scrollWidth <= state.width + 2, `${label}: horizontal overflow ${state.scrollWidth}>${state.width}`);
      must(state.openOverlays <= 1, `${label}: popup-over-popup detected (${state.openOverlays})`);
    }

    await assertLayout('roles');
    await page.screenshot({ path: `${out}/roles-access-roles-1440.png`, fullPage: true });

    const firstRole = page.locator('[data-role-id]').first();
    must(await firstRole.count() === 1, 'no role row available for modal review');
    await firstRole.click();
    await page.locator('#roles-access-modal.is-open').waitFor({ state: 'visible' });
    await assertLayout('role-modal');
    await page.screenshot({ path: `${out}/roles-access-role-modal-1440.png`, fullPage: true });
    await page.locator('[data-access-modal-close]').first().click();
    await page.locator('#roles-access-modal.is-open').waitFor({ state: 'detached' }).catch(() => {});

    await page.getByRole('tab', { name: 'Пользователи' }).click();
    await page.locator('[data-access-panel="users"]:not(.is-hidden)').waitFor({ state: 'visible' });
    await assertLayout('users');
    await page.screenshot({ path: `${out}/roles-access-users-1440.png`, fullPage: true });
    const firstMember = page.locator('[data-membership-id]').first();
    must(await firstMember.count() === 1, 'no membership row available for user modal review');
    await firstMember.click();
    await page.locator('#roles-access-modal.is-open').waitFor({ state: 'visible' });
    await assertLayout('user-modal');
    await page.screenshot({ path: `${out}/roles-access-user-modal-1440.png`, fullPage: true });
    await page.locator('[data-access-modal-close]').first().click();

    await page.getByRole('tab', { name: 'Закрепления' }).click();
    await page.locator('[data-access-panel="assignments"]:not(.is-hidden)').waitFor({ state: 'visible' });
    await page.waitForTimeout(1200);
    const assignmentError = await page.locator('[data-assignment-content] .notice.warn').count();
    must(assignmentError === 0, 'assignments tab returned an error');
    await assertLayout('assignments');
    await page.screenshot({ path: `${out}/roles-access-assignments-1440.png`, fullPage: true });

    await page.setViewportSize({ width: 1920, height: 1080 });
    await page.getByRole('tab', { name: 'Роли' }).click();
    await assertLayout('roles-1920');
    await page.screenshot({ path: `${out}/roles-access-roles-1920.png`, fullPage: true });

    fs.writeFileSync(`${out}/browser-pass.txt`, 'BROWSER_UI_PASS=YES\nPOPUP_OVER_POPUP=NO\nDESKTOP_1440=PASS\nDESKTOP_1920=PASS\n');
    console.log('ROLES_ACCESS_BROWSER_UI_PASS=YES');
  } finally {
    await browser.close();
  }
})().catch((error) => {
  console.error(error && error.stack ? error.stack : error);
  process.exit(1);
});
