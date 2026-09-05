const fs = require('fs');
const { chromium } = require('playwright');

const base = 'https://plan-ex.ru/erpv2';
const out = process.env.RESULT_DIR || 'roles_access_real_write_result';
const cookie = process.env.QA_COOKIE_NAME || '';
const sid = process.env.QA_LEGACY_SESSION_ID || '';
const invalidSid = process.env.QA_INVALID_SESSION_ID || '';
const runId = process.env.GITHUB_RUN_ID || String(Date.now());

function must(value, message) { if (!value) throw new Error(message); }
function sorted(values) { return [...new Set((values || []).map(Number))].sort((a, b) => a - b); }
function equal(a, b) { return JSON.stringify(sorted(a)) === JSON.stringify(sorted(b)); }

fs.mkdirSync(out, { recursive: true });
const evidenceFile = `${out}/proof.txt`;
const evidence = [];
function mark(line) {
  evidence.push(line);
  fs.writeFileSync(evidenceFile, evidence.join('\n') + '\n');
  console.log(line);
}

async function api(page, method, path, body) {
  return page.evaluate(async input => {
    const options = {
      method: input.method,
      headers: { Accept: 'application/json' },
      credentials: 'same-origin'
    };
    if (input.body !== undefined) {
      options.headers['Content-Type'] = 'application/json';
      options.body = JSON.stringify(input.body);
    }
    const response = await fetch(input.url, options);
    let payload = {};
    try { payload = await response.json(); } catch (_) {}
    return { status: response.status, payload, url: response.url };
  }, { method, url: base + path, body });
}

async function snapshot(page) {
  const result = await api(page, 'GET', '/company/roles-access/data');
  must(result.status === 200 && result.payload.ok === true, 'snapshot failed');
  return result.payload;
}

async function matrix(page) {
  const s = await snapshot(page);
  const payloads = [];
  for (const membership of s.memberships) {
    const result = await api(page, 'GET', '/company/roles-access/assignments?membership_id=' + Number(membership.id));
    must(result.status === 200 && result.payload.success === true, 'matrix failed ' + membership.id);
    payloads.push(result.payload);
  }
  return { s, payloads };
}

function setFrom(mx, type, id) {
  const ids = [];
  for (const payload of mx.payloads) {
    const entity = ((payload.entities || {})[type] || []).find(x => Number(x.id) === Number(id));
    if (entity && entity.assigned) ids.push(Number(payload.selected_membership_id));
  }
  return sorted(ids);
}

function matrixFingerprint(mx) {
  const result = {};
  const types = ['client', 'contractor', 'vehicle_unit', 'driver'];
  for (const type of types) {
    const first = mx.payloads[0] || {};
    const entities = ((first.entities || {})[type] || []).map(e => Number(e.id)).sort((a, b) => a - b);
    for (const id of entities) result[`${type}:${id}`] = setFrom(mx, type, id);
  }
  return JSON.stringify(result);
}

async function backendSet(page, type, id) { return setFrom(await matrix(page), type, id); }

async function assignmentPost(page, type, id, membershipIds) {
  const result = await api(page, 'POST', '/company/responsible-assignments/reassign', {
    entity_type: type,
    entity_id: Number(id),
    membership_ids: sorted(membershipIds)
  });
  must(result.status === 200 && result.payload.success === true, `${type}:${id} POST failed ${result.status}`);
  return sorted(result.payload.membership_ids);
}

async function restore(page, type, id, before) {
  for (let i = 0; i < 3; i++) {
    try {
      await assignmentPost(page, type, id, before);
      if (equal(await backendSet(page, type, id), before)) return;
    } catch (_) {}
    await page.waitForTimeout(150);
  }
  throw new Error(`RESTORE FAILED ${type}:${id}`);
}

async function waitAssignments(page) {
  await page.locator('[data-access-tab="assignments"]').click();
  for (let i = 0; i < 160; i++) {
    if (await page.locator('[data-assignment-group]').count() === 4) return;
    await page.waitForTimeout(100);
  }
  throw new Error('assignment panels did not load');
}

async function waitCheckbox(box) {
  for (let i = 0; i < 160; i++) {
    if (!(await box.isDisabled())) return;
    await new Promise(resolve => setTimeout(resolve, 50));
  }
  throw new Error('checkbox stuck');
}

(async () => {
  const browser = await chromium.launch({ headless: true });
  let ctx = null;
  let page = null;
  let tempRole = 0;
  let tempCopy = 0;
  let mode = 'real';
  let realWrites = 0;
  let simWrites = 0;
  let unexpectedWrites = 0;
  const touched = [];

  try {
    if (invalidSid) {
      const invalidContext = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'ru-RU' });
      await invalidContext.addCookies([{ name: cookie, value: invalidSid, url: base, httpOnly: true, secure: true, sameSite: 'Lax' }]);
      const invalidPage = await invalidContext.newPage();
      const response = await invalidPage.goto(base + '/company/dashboard', { waitUntil: 'domcontentloaded', timeout: 60000 });
      must(response && response.status() !== 403, 'invalid legacy returned 403');
      must(invalidPage.url().includes('/login'), 'invalid legacy did not relogin');
      mark('INVALID_LEGACY_RELOGIN=PASS');
      await invalidContext.close();
    }

    ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'ru-RU' });
    await ctx.route('**/*', async route => {
      const request = route.request();
      const method = request.method().toUpperCase();
      const url = request.url();
      if (method !== 'POST') return route.continue();

      if (mode === 'simulate' && url.includes('/company/responsible-assignments/reassign')) {
        simWrites++;
        return route.fulfill({
          status: 500,
          contentType: 'application/json',
          body: JSON.stringify({ success: false, error: 'assignment_update_failed', message: 'Не удалось сохранить закрепление. Повторите позже.' })
        });
      }

      const path = new URL(url).pathname.replace('/erpv2', '');
      if (path === '/company/responsible-assignments/reassign' || path.startsWith('/company/roles-access/roles')) {
        realWrites++;
        return route.continue();
      }
      unexpectedWrites++;
      return route.abort('blockedbyclient');
    });

    await ctx.addCookies([{ name: cookie, value: sid, url: base, httpOnly: true, secure: true, sameSite: 'Lax' }]);
    page = await ctx.newPage();

    let response = await page.goto(base + '/company/dashboard', { waitUntil: 'domcontentloaded', timeout: 60000 });
    must(response && response.status() === 200, 'dashboard not 200');
    const upgraded = (await ctx.cookies(base)).find(c => c.name === cookie);
    must(upgraded && upgraded.value !== sid, 'session did not rotate');
    fs.writeFileSync(`${out}/upgraded_sid.txt`, upgraded.value + '\n');
    mark('LEGACY_SESSION_UPGRADE=PASS');
    mark('DASHBOARD_200=PASS');

    response = await page.goto(base + '/company/roles-access', { waitUntil: 'networkidle', timeout: 60000 });
    must(response && response.status() === 200, 'roles access not 200');
    mark('ROLES_ACCESS_200=PASS');

    let state = await snapshot(page);
    const owner = state.memberships.find(m => m.is_system_owner === true && m.status === 'active');
    must(owner, 'owner membership missing');
    const ownerId = Number(owner.id);
    const ownerRole = state.roles.find(r => r.system_key === 'company_owner');
    must(ownerRole, 'owner role missing');

    const globalBefore = matrixFingerprint(await matrix(page));
    fs.writeFileSync(`${out}/assignment_matrix_before.sha256.txt`, require('crypto').createHash('sha256').update(globalBefore).digest('hex') + '\n');
    mark('ASSIGNMENT_MATRIX_BEFORE=CAPTURED');

    const invalidTarget = await api(page, 'POST', '/company/responsible-assignments/reassign', {
      entity_type: 'client', entity_id: 0, membership_ids: []
    });
    must(invalidTarget.status === 422, 'invalid assignment must be 422, got ' + invalidTarget.status);
    must(!JSON.stringify(invalidTarget.payload).includes('EntityAssignmentService'), 'internal class leaked');
    mark('ASSIGNMENT_SAFE_422=PASS');

    await waitAssignments(page);
    await page.locator('[data-assignment-membership]').selectOption(String(ownerId));
    await page.waitForTimeout(100);

    for (const [type, label] of [
      ['client', 'CLIENT'],
      ['contractor', 'CONTRACTOR'],
      ['vehicle_unit', 'VEHICLE'],
      ['driver', 'DRIVER']
    ]) {
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
      must(box && id > 0, 'no safe unassigned ' + type);

      const before = await backendSet(page, type, id);
      must(!before.includes(ownerId), 'unsafe candidate ' + type);
      const countBefore = (await group.locator('[data-assignment-count]').innerText()).trim();
      let changed = false;
      try {
        let pending = page.waitForResponse(r => r.request().method() === 'POST' && r.url().includes('/company/responsible-assignments/reassign'));
        await box.click();
        let rr = await pending;
        must(rr.status() === 200, type + ' ON HTTP ' + rr.status());
        await waitCheckbox(box);
        changed = true;
        must(equal(await backendSet(page, type, id), [...before, ownerId]), type + ' ON readback');

        pending = page.waitForResponse(r => r.request().method() === 'POST' && r.url().includes('/company/responsible-assignments/reassign'));
        await box.click();
        rr = await pending;
        must(rr.status() === 200, type + ' OFF HTTP ' + rr.status());
        await waitCheckbox(box);
        must(equal(await backendSet(page, type, id), before), type + ' restore diff');
        changed = false;
        must((await group.locator('[data-assignment-count]').innerText()).trim() === countBefore, type + ' counter restore');
        touched.push({ type, id, before });
        mark(`REAL_ASSIGNMENT_${label}=PASS`);
      } finally {
        if (changed) await restore(page, type, id, before);
      }
    }
    mark('SINGLE_ASSIGNMENTS_4_TYPES=PASS');

    const client = touched.find(x => x.type === 'client');
    must(client, 'client proof missing');

    response = await page.goto(base + `/company/clients/${client.id}/modal-view`, { waitUntil: 'domcontentloaded', timeout: 60000 });
    must(response && response.status() === 200, 'client modal-view not 200');
    const block = page.locator(`[data-entity-assignment-block][data-entity-type="client"][data-entity-id="${client.id}"]`);
    must(await block.count() === 1, 'inline block missing from modal-view');

    await page.evaluate(() => {
      if (!document.querySelector('meta[name="erp-base-path"]')) {
        const meta = document.createElement('meta');
        meta.name = 'erp-base-path';
        meta.content = '/erpv2';
        document.head.appendChild(meta);
      }
    });
    await page.addScriptTag({ url: base + '/assets/js/entity-assignments.js' });
    const inlineBox = block.locator(`[data-inline-membership="${ownerId}"]`);
    await inlineBox.waitFor({ state: 'visible', timeout: 30000 });
    must(!(await inlineBox.isChecked()), 'inline owner already assigned');

    let inlineChanged = false;
    try {
      let pending = page.waitForResponse(r => r.request().method() === 'POST' && r.url().includes('/company/responsible-assignments/reassign'));
      await inlineBox.click();
      let rr = await pending;
      must(rr.status() === 200, 'inline ON HTTP ' + rr.status());
      inlineChanged = true;
      await waitCheckbox(inlineBox);
      must(equal(await backendSet(page, 'client', client.id), [...client.before, ownerId]), 'inline ON readback');

      pending = page.waitForResponse(r => r.request().method() === 'POST' && r.url().includes('/company/responsible-assignments/reassign'));
      await inlineBox.click();
      rr = await pending;
      must(rr.status() === 200, 'inline OFF HTTP ' + rr.status());
      await waitCheckbox(inlineBox);
      must(equal(await backendSet(page, 'client', client.id), client.before), 'inline restore');
      inlineChanged = false;
      mark('INLINE_ASSIGNMENTS=PASS');
    } finally {
      if (inlineChanged) await restore(page, 'client', client.id, client.before);
    }

    await page.goto(base + '/company/roles-access', { waitUntil: 'networkidle', timeout: 60000 });
    const prefix = ('QA E2E ' + runId).slice(0, 70);
    const created = await api(page, 'POST', '/company/roles-access/roles', { name: prefix });
    must(created.status === 201 && created.payload.ok === true, 'role create');
    tempRole = Number(created.payload.role.id);

    const renamed = (prefix + ' Renamed').slice(0, 95);
    const pages = {
      'company.dashboard': true,
      'company.clients': true,
      'company.finance.dashboard': true
    };
    const scopes = {
      client: 'own', contractor: 'all', vehicle_unit: 'none', driver: 'own', linear_trip: 'all', waste_trip: 'none'
    };
    const saved = await api(page, 'POST', `/company/roles-access/roles/${tempRole}/save`, {
      name: renamed, pages, data_scopes: scopes
    });
    must(saved.status === 200 && saved.payload.ok === true, 'role save');

    state = await snapshot(page);
    let role = state.roles.find(x => Number(x.id) === tempRole);
    must(role && role.name === renamed, 'role readback');
    for (const [key, value] of Object.entries(pages)) must(role.pages[key] === value, 'page ' + key);
    for (const [key, value] of Object.entries(scopes)) must(role.data_scopes[key] === value, 'scope ' + key);

    const copiedResult = await api(page, 'POST', `/company/roles-access/roles/${tempRole}/copy`, {
      name: (prefix + ' Copy').slice(0, 95)
    });
    must(copiedResult.status === 201 && copiedResult.payload.ok === true, 'role copy');
    tempCopy = Number(copiedResult.payload.role.id);
    state = await snapshot(page);
    const copied = state.roles.find(x => Number(x.id) === tempCopy);
    must(copied, 'copy readback');
    for (const [key, value] of Object.entries(scopes)) must(copied.data_scopes[key] === value, 'copy scope ' + key);

    const ownerDelete = await api(page, 'POST', `/company/roles-access/roles/${Number(ownerRole.id)}/delete`, {});
    must(ownerDelete.status === 409 && ownerDelete.payload.ok === false, 'owner role protection');

    let deleted = await api(page, 'POST', `/company/roles-access/roles/${tempCopy}/delete`, {});
    must(deleted.status === 200 && deleted.payload.ok === true, 'copy cleanup');
    tempCopy = 0;
    deleted = await api(page, 'POST', `/company/roles-access/roles/${tempRole}/delete`, {});
    must(deleted.status === 200 && deleted.payload.ok === true, 'role cleanup');
    tempRole = 0;
    state = await snapshot(page);
    must(!state.roles.some(x => String(x.name || '').startsWith(prefix)), 'role residue');
    mark('REAL_ROLE_CRUD=PASS');

    await page.locator('[data-access-tab="users"]').click();
    const row = page.locator('[data-membership-id]').first();
    must(await row.count() === 1, 'membership row missing');
    await row.click();
    await page.locator('#roles-access-modal.is-open').waitFor({ state: 'visible' });
    must(await page.locator('[data-member-role]').count() === 1, 'user role field');
    must(await page.locator('[data-member-status]').count() === 1, 'user status');
    must(await page.locator('[data-page-choice="inherit"]').count() > 0, 'page overrides UI');
    must(await page.locator('[data-scope-entity]').count() >= 6, 'data scopes UI');
    await page.locator('[data-access-modal-close]').first().click();
    mark('USER_MODAL_READBACK=PASS');

    const finance = await api(page, 'GET', '/company/finance/dashboard');
    must(finance.status === 200, 'finance owner access');
    mark('FINANCE_OWNER_ACCESS=PASS');

    await page.goto(base + '/company/roles-access', { waitUntil: 'networkidle', timeout: 60000 });
    await waitAssignments(page);
    await page.locator('[data-assignment-membership]').selectOption(String(ownerId));
    const simulatedBox = page.locator('[data-assignment-group="client"] [data-assignment-type]').first();
    const simulatedBefore = await simulatedBox.isChecked();
    mode = 'simulate';
    await simulatedBox.click();
    await waitCheckbox(simulatedBox);
    must((await simulatedBox.isChecked()) === simulatedBefore, 'true backend-error rollback UI');
    const message = (await page.locator('[data-access-status]').innerText()).trim();
    must(message.includes('ошибки сервера') || message.includes('Не удалось сохранить закрепление'), 'safe 500 message');
    mode = 'real';
    mark('REAL_ERROR_UI_ROLLBACK=PASS');

    for (const item of touched) {
      must(equal(await backendSet(page, item.type, item.id), item.before), 'final restore ' + item.type);
    }

    const globalAfter = matrixFingerprint(await matrix(page));
    fs.writeFileSync(`${out}/assignment_matrix_after.sha256.txt`, require('crypto').createHash('sha256').update(globalAfter).digest('hex') + '\n');
    must(globalAfter === globalBefore, 'global assignment matrix diff');

    state = await snapshot(page);
    must(!state.roles.some(x => String(x.name || '').startsWith(('QA E2E ' + runId).slice(0, 70))), 'final role residue');
    must(unexpectedWrites === 0, 'unexpected writes ' + unexpectedWrites);

    mark('REAL_BACKEND_WRITES_PASS=YES');
    mark('RESTORE_DIFF=0');
    mark('QA_RESIDUE=0');
    mark(`REAL_WRITE_REQUESTS=${realWrites}`);
    mark(`SIMULATED_FAILURE_REQUESTS=${simWrites}`);
    mark('BROWSER_UI_PASS=YES');
    await page.screenshot({ path: `${out}/roles_access_real_write_final.jpg`, type: 'jpeg', quality: 70, fullPage: true });
  } finally {
    mode = 'real';
    if (page) {
      if (tempCopy) { try { await api(page, 'POST', `/company/roles-access/roles/${tempCopy}/delete`, {}); } catch (_) {} }
      if (tempRole) { try { await api(page, 'POST', `/company/roles-access/roles/${tempRole}/delete`, {}); } catch (_) {} }
    }
    if (ctx) await ctx.close().catch(() => {});
    await browser.close();
  }
})().catch(error => {
  console.error(error.stack || error);
  process.exit(1);
});
