const { chromium } = require('playwright');
const fs = require('fs');

const base = 'https://plan-ex.ru/erpv2';
const out = process.env.RESULT_DIR || 'roles_access_session_qa_result';
const cookieName = process.env.QA_COOKIE_NAME;
const legacySid = process.env.QA_LEGACY_SESSION_ID;
const invalidSid = process.env.QA_INVALID_SESSION_ID;
const githubEnv = process.env.GITHUB_ENV || '';
const credentials = JSON.parse(Buffer.from(process.env.P12_CREDENTIALS_B64 || '', 'base64').toString('utf8'));
const owner = credentials.find(x => String(x.role).toUpperCase() === 'OWNER');
if (!owner) throw new Error('OWNER runtime credentials missing');
if (!cookieName || !legacySid || !invalidSid) throw new Error('legacy QA session env missing');
fs.mkdirSync(out, { recursive: true });

function must(condition, message) { if (!condition) throw new Error(message); }
function recordSid(name, value) {
  if (!value) return;
  console.log(`::add-mask::${value}`);
  if (githubEnv) fs.appendFileSync(githubEnv, `${name}=${value}\n`);
}
async function currentSid(context) {
  const cookies = await context.cookies(base);
  return (cookies.find(c => c.name === cookieName) || {}).value || '';
}
async function assertLayout(page, label) {
  const state = await page.evaluate(() => ({ width: document.documentElement.clientWidth, scrollWidth: document.documentElement.scrollWidth, openOverlays: document.querySelectorAll('.modal-overlay.is-open').length }));
  must(state.scrollWidth <= state.width + 2, `${label}: horizontal overflow ${state.scrollWidth}>${state.width}`);
  must(state.openOverlays <= 1, `${label}: popup-over-popup detected (${state.openOverlays})`);
}

(async () => {
  const browser = await chromium.launch({ headless: true });
  try {
    // A. REAL normal login through AuthController -> dashboard -> protected page.
    const real = await browser.newContext({ viewport: { width: 1440, height: 1000 }, locale: 'ru-RU' });
    const page = await real.newPage();
    let response = await page.goto(base + '/login', { waitUntil: 'domcontentloaded', timeout: 60000 });
    must(response && response.status() === 200, 'login page HTTP is not 200');
    await page.locator('input[name="login"]').fill(owner.username);
    await page.locator('input[name="password"]').fill(owner.password);
    await Promise.all([
      page.waitForURL(url => url.pathname.endsWith('/company/dashboard'), { timeout: 60000 }),
      page.locator('button[type="submit"]').click(),
    ]);
    response = await page.goto(base + '/company/dashboard', { waitUntil: 'domcontentloaded', timeout: 60000 });
    must(response && response.status() === 200, 'dashboard HTTP after real login is not 200');
    must(!page.url().includes('/login'), 'real login returned to login');
    const realSid = await currentSid(real); recordSid('QA_REAL_LOGIN_SESSION_ID', realSid);
    response = await page.goto(base + '/company/clients', { waitUntil: 'domcontentloaded', timeout: 60000 });
    must(response && response.status() === 200, 'protected clients page after real login is not 200');
    await assertLayout(page, 'real-login-protected');
    await page.screenshot({ path: `${out}/real-login-protected-1440.png`, fullPage: true });
    console.log('ROLES_ACCESS_REAL_LOGIN_PASS=YES');
    console.log('ROLES_ACCESS_DASHBOARD_HTTP_STATUS=200');
    console.log('ROLES_ACCESS_PROTECTED_PAGE_HTTP_STATUS=200');

    // B. Real pre-activation owner session WITHOUT account_id must upgrade, not 403.
    const legacy = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
    await legacy.addCookies([{ name: cookieName, value: legacySid, domain: 'plan-ex.ru', path: '/', secure: true, httpOnly: true }]);
    const legacyPage = await legacy.newPage();
    response = await legacyPage.goto(base + '/company/dashboard', { waitUntil: 'domcontentloaded', timeout: 60000 });
    must(response && response.status() === 200, `legacy dashboard status ${response && response.status()}`);
    must(!legacyPage.url().includes('/login'), 'valid legacy session was invalidated instead of upgraded');
    const upgradedSid = await currentSid(legacy); recordSid('QA_UPGRADED_SESSION_ID', upgradedSid);
    must(upgradedSid && upgradedSid !== legacySid, 'legacy upgrade did not rotate session id');
    response = await legacyPage.goto(base + '/company/clients', { waitUntil: 'domcontentloaded', timeout: 60000 });
    must(response && response.status() === 200, 'legacy upgraded protected page is not 200');
    console.log('ROLES_ACCESS_LEGACY_SESSION_UPGRADE_PASS=YES');

    // C. Unprovable legacy identity must fail closed to login, never receive company access/403.
    const invalid = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
    await invalid.addCookies([{ name: cookieName, value: invalidSid, domain: 'plan-ex.ru', path: '/', secure: true, httpOnly: true }]);
    const invalidPage = await invalid.newPage();
    response = await invalidPage.goto(base + '/company/dashboard', { waitUntil: 'domcontentloaded', timeout: 60000 });
    must(response && response.status() === 200, 'invalid legacy session should land on login HTTP 200');
    must(new URL(invalidPage.url()).pathname.endsWith('/login'), 'invalid legacy session did not redirect to login');
    console.log('ROLES_ACCESS_INVALID_LEGACY_RELOGIN_PASS=YES');

    fs.writeFileSync(`${out}/browser-pass.txt`, [
      'REAL_LOGIN=PASS','DASHBOARD_HTTP_STATUS=200','PROTECTED_PAGE=PASS','LEGACY_SESSION_UPGRADE=PASS','INVALID_LEGACY_RELOGIN=PASS','POPUP_OVER_POPUP=NO'
    ].join('\n') + '\n');
    console.log('ROLES_ACCESS_BROWSER_UI_PASS=YES');
  } finally { await browser.close(); }
})().catch(error => { console.error(error && error.stack ? error.stack : error); process.exit(1); });
