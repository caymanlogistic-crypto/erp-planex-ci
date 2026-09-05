const fs = require('fs');
const crypto = require('crypto');
const { chromium } = require('playwright');

const base = 'https://plan-ex.ru/erpv2';
const out = process.env.RESULT_DIR || 'roles_access_real_write_result';
const cookie = process.env.QA_COOKIE_NAME || '';
const legacySid = process.env.QA_LEGACY_SESSION_ID || '';
const invalidSid = process.env.QA_INVALID_SESSION_ID || '';
const runId = process.env.GITHUB_RUN_ID || String(Date.now());

fs.mkdirSync(out, { recursive: true });
const evidence = [];
function mark(line) {
  evidence.push(line);
  fs.writeFileSync(`${out}/proof.txt`, evidence.join('\n') + '\n');
  console.log(line);
}
function must(value, message) { if (!value) throw new Error(message); }
function sorted(values) { return [...new Set((values || []).map(Number))].sort((a, b) => a - b); }
function sameSet(a, b) { return JSON.stringify(sorted(a)) === JSON.stringify(sorted(b)); }
function sha(value) { return crypto.createHash('sha256').update(value).digest('hex'); }

async function api(page, method, path, body) {
  return page.evaluate(async x => {
    const options = { method: x.method, headers: { Accept: 'application/json' }, credentials: 'same-origin' };
    if (x.body !== undefined) {
      options.headers['Content-Type'] = 'application/json';
      options.body = JSON.stringify(x.body);
    }
    const response = await fetch(x.url, options);
    let payload = {};
    try { payload = await response.json(); } catch (_) {}
    return { status: response.status, payload };
  }, { method, url: base + path, body });
}

async function snapshot(page) {
  const r = await api(page, 'GET', '/company/roles-access/data');
  must(r.status === 200 && r.payload.ok === true, 'roles snapshot failed');
  return r.payload;
}

async function matrix(page) {
  const state = await snapshot(page);
  const payloads = [];
  for (const membership of state.memberships) {
    const r = await api(page, 'GET', '/company/roles-access/assignments?membership_id=' + Number(membership.id));
    must(r.status === 200 && r.payload.success === true, 'assignment matrix GET failed');
    payloads.push(r.payload);
  }
  return { state, payloads };
}

function assignedSet(mx, type, id) {
  const ids = [];
  for (const payload of mx.payloads) {
    const entity = ((payload.entities || {})[type] || []).find(x => Number(x.id) === Number(id));
    if (entity && entity.assigned) ids.push(Number(payload.selected_membership_id));
  }
  return sorted(ids);
}

function fingerprint(mx) {
  const result = {};
  for (const type of ['client', 'contractor', 'vehicle_unit', 'driver']) {
    const entities = (((mx.payloads[0] || {}).entities || {})[type] || []).map(x => Number(x.id)).sort((a, b) => a - b);
    for (const id of entities) result[`${type}:${id}`] = assignedSet(mx, type, id);
  }
  return JSON.stringify(result);
}

async function backendSet(page, type, id) { return assignedSet(await matrix(page), type, id); }
async function postAssignment(page, type, id, ids) {
  const r = await api(page, 'POST', '/company/responsible-assignments/reassign', {
    entity_type: type, entity_id: Number(id), membership_ids: sorted(ids)
  });
  must(r.status === 200 && r.payload.success === true, `${type}:${id} backend POST ${r.status}`);
  return sorted(r.payload.membership_ids);
}
async function restore(page, type, id, before) {
  for (let i = 0; i < 3; i++) {
    try {
      await postAssignment(page, type, id, before);
      if (sameSet(await backendSet(page, type, id), before)) return;
    } catch (_) {}
    await page.waitForTimeout(150);
  }
  throw new Error(`RESTORE_FAILED ${type}:${id}`);
}
async function waitAssignments(page) {
  await page.locator('[data-access-tab="assignments"]').click();
  for (let i = 0; i < 160; i++) {
    if (await page.locator('[data-assignment-group]').count() === 4) return;
    await page.waitForTimeout(100);
  }
  throw new Error('assignment panels not loaded');
}
async function waitEnabled(locator) {
  for (let i = 0; i < 160; i++) {
    if (!(await locator.isDisabled())) return;
    await new Promise(resolve => setTimeout(resolve, 50));
  }
  throw new Error('checkbox remained disabled');
}

(async () => {
  const browser = await chromium.launch({ headless: true });
  let context = null;
  let page = null;
  let tempRole = 0;
  let tempCopy = 0;
  let mode = 'real';
  let realWriteRequests = 0;
  let simulatedRequests = 0;
  let unexpectedWrites = 0;
  const touched = [];

  try {
    if (invalidSid) {
      const c = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'ru-RU' });
      await c.addCookies([{ name: cookie, value: invalidSid, url: base, httpOnly: true, secure: true, sameSite: 'Lax' }]);
      const p = await c.newPage();
      const r = await p.goto(base + '/company/dashboard', { waitUntil: 'domcontentloaded', timeout: 60000 });
      must(r && r.status() !== 403, 'invalid legacy session returned 403');
      must(p.url().includes('/login'), 'invalid legacy session did not redirect to login');
      mark('INVALID_LEGACY_RELOGIN=PASS');
      await c.close();
    }

    context = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'ru-RU' });
    context.on('page', p => p.on('dialog', async d => { console.log('DIALOG=' + d.message()); await d.dismiss().catch(() => {}); }));
    await context.route('**/*', async route => {
      const request = route.request();
      const method = request.method().toUpperCase();
      if (method !== 'POST') return route.continue();
      const url = request.url();
      if (mode === 'simulate' && url.includes('/company/responsible-assignments/reassign')) {
        simulatedRequests++;
        return route.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({
          success: false, error: 'assignment_update_failed', message: 'Не удалось сохранить закрепление. Повторите позже.'
        }) });
      }
      const path = new URL(url).pathname.replace('/erpv2', '');
      if (path === '/company/responsible-assignments/reassign' || path.startsWith('/company/roles-access/roles')) {
        realWriteRequests++;
        return route.continue();
      }
      unexpectedWrites++;
      return route.abort('blockedbyclient');
    });

    await context.addCookies([{ name: cookie, value: legacySid, url: base, httpOnly: true, secure: true, sameSite: 'Lax' }]);
    page = await context.newPage();
    page.on('dialog', async d => { console.log('DIALOG=' + d.message()); await d.dismiss().catch(() => {}); });

    let response = await page.goto(base + '/company/dashboard', { waitUntil: 'domcontentloaded', timeout: 60000 });
    must(response && response.status() === 200, 'dashboard not 200');
    const upgraded = (await context.cookies(base)).find(c => c.name === cookie);
    must(upgraded && upgraded.value !== legacySid, 'legacy session did not rotate');
    fs.writeFileSync(`${out}/upgraded_sid.txt`, upgraded.value + '\n');
    mark('LEGACY_SESSION_UPGRADE=PASS');
    mark('DASHBOARD_200=PASS');

    response = await page.goto(base + '/company/roles-access', { waitUntil: 'networkidle', timeout: 60000 });
    must(response && response.status() === 200, 'roles access not 200');
    mark('ROLES_ACCESS_200=PASS');

    let state = await snapshot(page);
    const owner = state.memberships.find(m => m.is_system_owner === true && m.status === 'active');
    must(owner, 'active owner membership missing');
    const ownerId = Number(owner.id);
    const ownerRole = state.roles.find(r => r.system_key === 'company_owner');
    must(ownerRole, 'owner role missing');

    const globalBefore = fingerprint(await matrix(page));
    fs.writeFileSync(`${out}/assignment_matrix_before.sha256.txt`, sha(globalBefore) + '\n');
    mark('ASSIGNMENT_MATRIX_BEFORE=CAPTURED');

    const bad = await api(page, 'POST', '/company/responsible-assignments/reassign', {
      entity_type: 'client', entity_id: 0, membership_ids: []
    });
    must(bad.status === 422, 'invalid target expected 422, got ' + bad.status);
    const badText = JSON.stringify(bad.payload);
    must(!badText.includes('EntityAssignmentService') && !badText.includes('/home/') && !badText.includes('SQLSTATE'), 'unsafe backend detail leaked');
    mark('ASSIGNMENT_SAFE_422=PASS');

    await waitAssignments(page);
    await page.locator('[data-assignment-membership]').selectOption(String(ownerId));
    await page.waitForTimeout(100);

    for (const [type, label] of [['client', 'CLIENT'], ['contractor', 'CONTRACTOR'], ['vehicle_unit', 'VEHICLE'], ['driver', 'DRIVER']]) {
      const group = page.locator(`[data-assignment-group="${type}"]`);
      const boxes = group.locator('[data-assignment-type]');
      let box = null;
      let id = 0;
      for (let i = 0; i < await boxes.count(); i++) {
        const candidate = boxes.nth(i);
        if (!(await candidate.isChecked())) {
          box = candidate;
          id = Number(await candidate.getAttribute('data-assignment-id'));
          break;
        }
      }
      must(box && id > 0, 'no safe owner-unassigned candidate for ' + type);
      const before = await backendSet(page, type, id);
      must(!before.includes(ownerId), 'candidate already assigned to owner ' + type);
      const countBefore = (await group.locator('[data-assignment-count]').innerText()).trim();
      let changed = false;
      try {
        let pending = page.waitForResponse(r => r.request().method() === 'POST' && r.url().includes('/company/responsible-assignments/reassign'));
        await box.click();
        let rr = await pending;
        must(rr.status() === 200, type + ' ON HTTP ' + rr.status());
        await waitEnabled(box);
        changed = true;
        must(sameSet(await backendSet(page, type, id), [...before, ownerId]), type + ' ON server readback failed');

        pending = page.waitForResponse(r => r.request().method() === 'POST' && r.url().includes('/company/responsible-assignments/reassign'));
        await box.click();
        rr = await pending;
        must(rr.status() === 200, type + ' OFF HTTP ' + rr.status());
        await waitEnabled(box);
        must(sameSet(await backendSet(page, type, id), before), type + ' restore readback failed');
        changed = false;
        must((await group.locator('[data-assignment-count]').innerText()).trim() === countBefore, type + ' counter mismatch after restore');
        touched.push({ type, id, before });
        mark(`REAL_ASSIGNMENT_${label}=PASS`);
      } finally {
        if (changed) await restore(page, type, id, before);
      }
    }
    mark('SINGLE_ASSIGNMENTS_4_TYPES=PASS');

    const client = touched.find(x => x.type === 'client');
    must(client, 'client candidate missing');
    response = await page.goto(base + '/company/clients', { waitUntil: 'networkidle', timeout: 60000 });
    must(response && response.status() === 200, 'clients page not 200');

    const modalResult = await page.evaluate(async id => {
      const prefix = document.querySelector('meta[name="erp-base-path"]')?.content || '';
      const r = await fetch(prefix.replace(/\/$/, '') + `/company/clients/${id}/modal-view`, { credentials: 'same-origin' });
      const html = await r.text();
      const host = document.createElement('div');
      host.setAttribute('data-qa-inline-host', '1');
      host.innerHTML = html;
      document.body.appendChild(host);
      return { status: r.status };
    }, client.id);
    must(modalResult.status === 200, 'client modal fetch not 200');

    const block = page.locator(`[data-qa-inline-host] [data-entity-assignment-block][data-entity-type="client"][data-entity-id="${client.id}"]`);
    must(await block.count() === 1, 'inline assignment block missing in normal layout');
    const inlineBox = block.locator(`[data-inline-membership="${ownerId}"]`);
    await inlineBox.waitFor({ state: 'visible', timeout: 30000 });
    must(!(await inlineBox.isChecked()), 'inline owner unexpectedly assigned');

    let inlineChanged = false;
    try {
      let pending = page.waitForResponse(r => r.request().method() === 'POST' && r.url().includes('/company/responsible-assignments/reassign'));
      await inlineBox.click();
      let rr = await pending;
      must(rr.status() === 200, 'inline ON HTTP ' + rr.status());
      await waitEnabled(inlineBox);
      inlineChanged = true;
      must(sameSet(await backendSet(page, 'client', client.id), [...client.before, ownerId]), 'inline ON server readback failed');

      pending = page.waitForResponse(r => r.request().method() === 'POST' && r.url().includes('/company/responsible-assignments/reassign'));
      await inlineBox.click();
      rr = await pending;
      must(rr.status() === 200, 'inline OFF HTTP ' + rr.status());
      await waitEnabled(inlineBox);
      must(sameSet(await backendSet(page, 'client', client.id), client.before), 'inline restore readback failed');
      inlineChanged = false;
      mark('INLINE_ASSIGNMENTS=PASS');
    } finally {
      if (inlineChanged) await restore(page, 'client', client.id, client.before);
    }

    await page.goto(base + '/company/roles-access', { waitUntil: 'networkidle', timeout: 60000 });
    const prefix = ('QA E2E ' + runId).slice(0, 70);
    let result = await api(page, 'POST', '/company/roles-access/roles', { name: prefix });
    must(result.status === 201 && result.payload.ok === true, 'role create failed');
    tempRole = Number(result.payload.role.id);

    const renamed = (prefix + ' Renamed').slice(0, 95);
    const pages = { 'company.dashboard': true, 'company.clients': true, 'company.finance.dashboard': true };
    const scopes = { client: 'own', contractor: 'all', vehicle_unit: 'none', driver: 'own', linear_trip: 'all', waste_trip: 'none' };
    result = await api(page, 'POST', `/company/roles-access/roles/${tempRole}/save`, { name: renamed, pages, data_scopes: scopes });
    must(result.status === 200 && result.payload.ok === true, 'role save failed');
    state = await snapshot(page);
    const role = state.roles.find(r => Number(r.id) === tempRole);
    must(role && role.name === renamed, 'role rename readback failed');
    for (const [key, value] of Object.entries(pages)) must(role.pages[key] === value, 'role page readback ' + key);
    for (const [key, value] of Object.entries(scopes)) must(role.data_scopes[key] === value, 'role scope readback ' + key);

    result = await api(page, 'POST', `/company/roles-access/roles/${tempRole}/copy`, { name: (prefix + ' Copy').slice(0, 95) });
    must(result.status === 201 && result.payload.ok === true, 'role copy failed');
    tempCopy = Number(result.payload.role.id);
    state = await snapshot(page);
    const copy = state.roles.find(r => Number(r.id) === tempCopy);
    must(copy, 'role copy readback missing');
    for (const [key, value] of Object.entries(scopes)) must(copy.data_scopes[key] === value, 'copied scope ' + key);

    const protectedDelete = await api(page, 'POST', `/company/roles-access/roles/${Number(ownerRole.id)}/delete`, {});
    must(protectedDelete.status === 409 && protectedDelete.payload.ok === false, 'protected owner role delete was not blocked');

    result = await api(page, 'POST', `/company/roles-access/roles/${tempCopy}/delete`, {});
    must(result.status === 200 && result.payload.ok === true, 'copy cleanup failed');
    tempCopy = 0;
    result = await api(page, 'POST', `/company/roles-access/roles/${tempRole}/delete`, {});
    must(result.status === 200 && result.payload.ok === true, 'role cleanup failed');
    tempRole = 0;
    state = await snapshot(page);
    must(!state.roles.some(r => String(r.name || '').startsWith(prefix)), 'temporary role residue');
    mark('REAL_ROLE_CRUD=PASS');

    await page.locator('[data-access-tab="users"]').click();
    const memberRow = page.locator('[data-membership-id]').first();
    must(await memberRow.count() === 1, 'membership row missing');
    await memberRow.click();
    await page.locator('#roles-access-modal.is-open').waitFor({ state: 'visible' });
    must(await page.locator('[data-member-role]').count() === 1, 'user role control missing');
    must(await page.locator('[data-member-status]').count() === 1, 'user status control missing');
    must(await page.locator('[data-page-choice="inherit"]').count() > 0, 'personal page override controls missing');
    must(await page.locator('[data-scope-entity]').count() >= 6, 'personal data override controls missing');
    await page.locator('[data-access-modal-close]').first().click();
    mark('USER_SETTINGS_UI=PASS');

    const finance = await api(page, 'GET', '/company/finance/dashboard');
    must(finance.status === 200, 'owner finance access failed');
    mark('FINANCE_OWNER_ACCESS=PASS');

    await page.goto(base + '/company/roles-access', { waitUntil: 'networkidle', timeout: 60000 });
    await waitAssignments(page);
    await page.locator('[data-assignment-membership]').selectOption(String(ownerId));
    const errorBox = page.locator('[data-assignment-group="client"] [data-assignment-type]').first();
    const errorBefore = await errorBox.isChecked();
    mode = 'simulate';
    await errorBox.click();
    await waitEnabled(errorBox);
    must((await errorBox.isChecked()) === errorBefore, 'UI failed to rollback on backend 500');
    const errorText = (await page.locator('[data-access-status]').innerText()).trim();
    must(errorText.includes('ошибки сервера') || errorText.includes('Не удалось сохранить закрепление'), 'safe 500 message missing');
    mode = 'real';
    mark('REAL_ERROR_UI_ROLLBACK=PASS');

    for (const item of touched) must(sameSet(await backendSet(page, item.type, item.id), item.before), 'final object restore ' + item.type);
    const globalAfter = fingerprint(await matrix(page));
    fs.writeFileSync(`${out}/assignment_matrix_after.sha256.txt`, sha(globalAfter) + '\n');
    must(globalAfter === globalBefore, 'full assignment matrix differs from BEFORE');

    state = await snapshot(page);
    must(!state.roles.some(r => String(r.name || '').startsWith(prefix)), 'QA role residue at final check');
    must(unexpectedWrites === 0, 'unexpected production writes ' + unexpectedWrites);

    mark('REAL_BACKEND_WRITES_PASS=YES');
    mark('RESTORE_DIFF=0');
    mark('QA_RESIDUE=0');
    mark(`REAL_WRITE_REQUESTS=${realWriteRequests}`);
    mark(`SIMULATED_FAILURE_REQUESTS=${simulatedRequests}`);
    mark('BROWSER_UI_PASS=YES');
    await page.screenshot({ path: `${out}/roles_access_real_write_final.jpg`, type: 'jpeg', quality: 70, fullPage: true });
  } finally {
    mode = 'real';
    if (page) {
      if (tempCopy) { try { await api(page, 'POST', `/company/roles-access/roles/${tempCopy}/delete`, {}); } catch (_) {} }
      if (tempRole) { try { await api(page, 'POST', `/company/roles-access/roles/${tempRole}/delete`, {}); } catch (_) {} }
    }
    if (context) await context.close().catch(() => {});
    await browser.close();
  }
})().catch(error => {
  console.error(error.stack || error);
  process.exit(1);
});
