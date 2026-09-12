'use strict';

const { chromium } = require('playwright');
const fs = require('fs');
const out = process.env.RESULT_DIR || 'max_messaging_acceptance_result';
const base = 'https://plan-ex.ru/erpv2';
const encoded = String(process.env.MAX_QA_CREDENTIALS_B64 || '');
if (!encoded) throw new Error('MAX_QA_CREDENTIALS_B64 missing');
const credentials = JSON.parse(Buffer.from(encoded, 'base64').toString('utf8'));
fs.mkdirSync(out, { recursive: true });
const must = (value, message) => { if (!value) throw new Error(message); };
const mark = (text) => { console.log(text); fs.appendFileSync(`${out}/evidence.txt`, `${text}\n`); };
const appPath = (url) => { const p = new URL(url).pathname; return p.startsWith('/erpv2') ? (p.slice('/erpv2'.length) || '/') : p; };

async function login(browser, credential) {
  const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, locale: 'ru-RU' });
  const page = await context.newPage();
  let response = await page.goto(base + '/login', { waitUntil: 'domcontentloaded', timeout: 60000 });
  must(response && response.status() === 200, 'login page unavailable');
  await page.locator('input[name="login"]').fill(String(credential.username || ''));
  await page.locator('input[name="password"]').fill(String(credential.password || ''));
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 60000 }).catch(() => null),
    page.locator('button[type="submit"]').click(),
  ]);
  must(appPath(page.url()) !== '/login', `real login failed for ${credential.role}`);
  return { context, page };
}

(async () => {
  const browser = await chromium.launch({ headless: true });
  try {
    const ownerCredential = credentials.find(x => String(x.role).toUpperCase() === 'OWNER');
    const memberCredential = credentials.find(x => String(x.role).toUpperCase() === 'MEMBER');
    must(ownerCredential && memberCredential, 'MAX QA credentials incomplete');

    const owner = await login(browser, ownerCredential);
    mark('MAX_REAL_OWNER_LOGIN=PASS');
    let response = await owner.page.goto(base + '/company/max/settings', { waitUntil: 'domcontentloaded', timeout: 60000 });
    must(response && response.status() === 200, 'owner MAX settings did not return 200');
    must(appPath(owner.page.url()) === '/company/max/settings', `owner MAX settings final path=${appPath(owner.page.url())}`);
    must(await owner.page.locator('h1.page-title').filter({ hasText: 'MAX — интеграция' }).count() === 1, 'MAX settings h1 missing');
    must(await owner.page.locator('h3.panel-head-title').filter({ hasText: 'Тестовая отправка' }).count() >= 1, 'MAX test-send block missing');
    must(await owner.page.locator('input[type="password"][name="token"]').count() >= 1, 'masked MAX token input missing');
    mark('MAX_SETTINGS_OWNER=200_PASS');
    await owner.page.screenshot({ path: `${out}/owner-max-settings.png`, fullPage: true });

    response = await owner.page.goto(base + '/company/max/messages', { waitUntil: 'domcontentloaded', timeout: 60000 });
    must(response && response.status() === 200, 'owner MAX messages did not return 200');
    must(appPath(owner.page.url()) === '/company/max/messages', `owner MAX messages final path=${appPath(owner.page.url())}`);
    must(await owner.page.locator('h1.page-title').filter({ hasText: 'Сообщения MAX' }).count() === 1, 'MAX messages h1 missing');
    mark('MAX_MESSAGES_OWNER=200_PASS');
    await owner.page.screenshot({ path: `${out}/owner-max-messages.png`, fullPage: true });
    await owner.context.close();

    const member = await login(browser, memberCredential);
    mark('MAX_REAL_MEMBER_LOGIN=PASS');
    response = await member.page.goto(base + '/company/max/settings', { waitUntil: 'domcontentloaded', timeout: 60000 });
    must(response && response.status() === 403, `ordinary member MAX settings status=${response ? response.status() : 'none'}`);
    mark('MAX_SETTINGS_MEMBER=403_PASS');
    response = await member.page.goto(base + '/company/max/messages', { waitUntil: 'domcontentloaded', timeout: 60000 });
    must(response && response.status() === 403, `ordinary member MAX messages status=${response ? response.status() : 'none'}`);
    mark('MAX_MESSAGES_MEMBER=403_PASS');
    await member.context.close();

    mark('MAX_AUTHORIZATION_BOUNDARY=PASS');
    mark('MAX_PRODUCTION_FUNCTIONAL_ACCEPTANCE=PASS');
  } finally {
    await browser.close();
  }
})().catch(err => { console.error(err && err.stack ? err.stack : err); process.exit(1); });
