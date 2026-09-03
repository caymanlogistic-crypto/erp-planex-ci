const fs = require('fs');
const assert = require('assert');
const { chromium } = require('playwright');

const BASE = 'https://plan-ex.ru/erpv2';
const cookieName = process.env.QA_COOKIE_NAME;
const roles = [
  ['company_owner', process.env.QA_COMPANY_OWNER_SID],
  ['senior_logist', process.env.QA_SENIOR_LOGIST_SID],
  ['logist', process.env.QA_LOGIST_SID],
];
const viewports = [{ width: 1366, height: 900 }, { width: 1920, height: 1080 }];
assert(cookieName, 'QA cookie name missing');
for (const [role, sid] of roles) assert(sid, `QA SID missing for ${role}`);
fs.mkdirSync('artifacts', { recursive: true });

const registrySource = fs.readFileSync('app/View/pages/company_waste_trips.php', 'utf8');
const headMatch = registrySource.match(/<table class="table waste-registry"><thead><tr>([\s\S]*?)<\/tr><\/thead>/);
assert(headMatch, 'Waste Trips registry header source not found');
const headers = [...headMatch[1].matchAll(/<th>(.*?)<\/th>/g)].map((m) => m[1].replace(/<[^>]+>/g, '').trim().toLowerCase());
assert.deepStrictEqual(headers, ['id','описание рейса','начало рейса','окончание рейса','статус','исполнитель рейса','заявки','вес, кг','менеджер']);
console.log('WASTE_TRIP_9_COLUMN_SOURCE_CONTRACT=PASS');

function captureRuntimeErrors(page, bucket) {
  page.on('pageerror', (e) => bucket.push(`pageerror: ${e.message}`));
  page.on('console', (m) => { if (m.type() === 'error') bucket.push(`console: ${m.text()}`); });
  page.on('response', (r) => { if (r.status() >= 500) bucket.push(`http${r.status()}: ${r.url()}`); });
}
async function assertNoOverflow(page, label) {
  const d = await page.evaluate(() => ({ sw: document.documentElement.scrollWidth, cw: document.documentElement.clientWidth, bsw: document.body ? document.body.scrollWidth : 0 }));
  assert(d.sw <= d.cw + 1, `${label}: document overflow ${JSON.stringify(d)}`);
  assert(d.bsw <= d.cw + 1, `${label}: body overflow ${JSON.stringify(d)}`);
}

(async () => {
  const browser = await chromium.launch({ headless: true });
  const evidence = [];
  try {
    for (const [role, sid] of roles) {
      for (const viewport of viewports) {
        const context = await browser.newContext({ viewport });
        await context.addCookies([{ name: cookieName, value: sid, url: `${BASE}/` }]);
        const page = await context.newPage();
        const errors = [];
        captureRuntimeErrors(page, errors);
        const suffix = `${role}-${viewport.width}x${viewport.height}`;

        let response = await page.goto(`${BASE}/company/trips/departures`, { waitUntil: 'networkidle' });
        assert(response && response.status() === 200, `${suffix}: registry HTTP ${response ? response.status() : 'none'}`);
        assert.strictEqual((await page.locator('h1.page-title').innerText()).trim(), 'Перевозка отходов');
        assert.strictEqual(await page.locator('.notice.warn').count(), 0, `${suffix}: registry warning`);
        assert.strictEqual(await page.getByRole('link', { name: 'Рейсы из старой БД' }).count(), 1, `${suffix}: legacy link missing`);
        await assertNoOverflow(page, `${suffix}: registry`);

        await page.locator('button[onclick="openModal(\'waste-trip-create-modal\')"]').click();
        await page.locator('#waste-trip-create-modal').waitFor({ state: 'visible' });
        assert.strictEqual(await page.locator('.modal-overlay:visible').count(), 1, `${suffix}: create overlay count`);
        await page.screenshot({ path: `artifacts/waste-${suffix}-create.png`, fullPage: true });
        await page.locator('#waste-trip-create-modal .modal-close').click();
        await page.locator('#waste-trip-create-modal').waitFor({ state: 'hidden' });

        response = await page.goto(`${BASE}/company/trips/departures/legacy`, { waitUntil: 'networkidle' });
        assert(response && response.status() === 200, `${suffix}: legacy HTTP ${response ? response.status() : 'none'}`);
        assert.strictEqual((await page.locator('h1.page-title').innerText()).trim(), 'Рейсы из старой БД');
        assert.strictEqual(await page.locator('.notice.warn').count(), 0, `${suffix}: legacy warning`);
        await assertNoOverflow(page, `${suffix}: legacy`);

        const idTexts = await page.locator('table tbody tr td:first-child').allTextContents();
        assert(idTexts.length > 0, `${suffix}: legacy catalog empty`);
        const ids = idTexts.map((x) => Number(String(x).replace(/[^0-9]/g, '')));
        assert(ids.every((id) => Number.isFinite(id) && id > 243), `${suffix}: catalog contains invalid id`);

        const materialize = page.locator('[data-materialize-legacy]').first();
        assert.strictEqual(await materialize.count(), 1, `${suffix}: no materializable legacy row`);
        const legacyId = await materialize.getAttribute('data-materialize-legacy');
        await materialize.click();
        await page.locator('#waste-trip-materialize-modal').waitFor({ state: 'visible' });
        assert.strictEqual(await page.locator('.modal-overlay:visible').count(), 1, `${suffix}: materialize overlay count`);
        const info = (await page.locator('#waste-trip-materialize-modal .notice.info').innerText()).trim();
        assert(info.includes('business ID') && info.includes('старой БД'), `${suffix}: ownership notice missing`);
        const formNames = await page.locator('#waste-materialize-form [name]').evaluateAll((nodes) => nodes.map((n) => n.getAttribute('name')).filter(Boolean));
        const forbidden = ['business_id','comment','planned_start_mode','planned_start_date','planned_start_date_from','planned_start_date_to','planned_finish_date','actual_start_date','actual_end_date','request_ids'];
        assert(forbidden.every((name) => !formNames.includes(name)), `${suffix}: legacy-owned input exposed`);
        const action = await page.locator('#waste-materialize-form').getAttribute('action');
        assert(action && action.endsWith(`/company/trips/departures/legacy/${legacyId}/materialize`), `${suffix}: bad materialize action`);
        await page.screenshot({ path: `artifacts/waste-${suffix}-materialize.png`, fullPage: true });
        await page.locator('#waste-trip-materialize-modal .modal-close').click();

        response = await page.goto(`${BASE}/company/trips/departures/import`, { waitUntil: 'domcontentloaded' });
        assert(response && response.status() !== 200, `${suffix}: obsolete import route still HTTP 200`);
        assert.deepStrictEqual(errors, [], `${suffix}: runtime errors: ${errors.join(' | ')}`);
        evidence.push({ role, viewport, legacyRows: ids.length, oldImportHttp: response.status() });
        await context.close();
      }
    }
  } finally {
    await browser.close();
  }
  fs.writeFileSync('artifacts/waste-trips-production-qa.json', JSON.stringify({ status: 'PASS', evidence }, null, 2));
  console.log('WASTE_TRIPS_PRODUCTION_BROWSER_QA=PASS');
})().catch((error) => {
  fs.writeFileSync('artifacts/waste-trips-production-qa-error.txt', String(error && error.stack ? error.stack : error));
  console.error(error);
  process.exit(1);
});
