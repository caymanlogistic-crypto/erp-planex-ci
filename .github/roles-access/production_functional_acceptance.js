const fs = require('fs');
const crypto = require('crypto');
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');

const base = 'https://plan-ex.ru/erpv2';
const out = process.env.RESULT_DIR || 'roles_access_functional_acceptance_result';
const encodedCredentials = process.env.P12_CREDENTIALS_B64 || '';
const credentials = encodedCredentials ? JSON.parse(Buffer.from(encodedCredentials, 'base64').toString('utf8')) : [];
const runId = process.env.GITHUB_RUN_ID || String(Date.now());
const suppliedForeignMembershipId = Number(process.env.QA_FOREIGN_MEMBERSHIP_ID || 0);
const suppliedOwnerMembershipId = Number(process.env.QA_OWNER_MEMBERSHIP_ID || 0);
const suppliedOwnerTenantUserId = Number(process.env.QA_OWNER_TENANT_USER_ID || 0);
const suppliedDynamicMembershipId = Number(process.env.QA_DYNAMIC_MEMBERSHIP_ID || 0);
const suppliedDynamicTenantUserId = Number(process.env.QA_DYNAMIC_TENANT_USER_ID || 0);
const suppliedClientId = Number(process.env.QA_CLIENT_ID || 0);
const suppliedContractorId = Number(process.env.QA_CONTRACTOR_ID || 0);
const suppliedVehicleUnitId = Number(process.env.QA_VEHICLE_UNIT_ID || 0);
const suppliedDriverId = Number(process.env.QA_DRIVER_ID || 0);
const suppliedCompanyAId = Number(process.env.QA_COMPANY_A_ID || 0);
const suppliedCompanyBId = Number(process.env.QA_COMPANY_B_ID || 0);
const suppliedMultiCompanyMembershipId = Number(process.env.QA_MULTI_COMPANY_MEMBERSHIP_ID || 0);
const suppliedMultiCompanyTenantUserId = Number(process.env.QA_MULTI_COMPANY_TENANT_USER_ID || 0);
const suppliedMultiCompanyBClientId = Number(process.env.QA_MULTI_COMPANY_B_CLIENT_ID || 0);
const assignmentFixtureIds = { client:suppliedClientId, contractor:suppliedContractorId, vehicle_unit:suppliedVehicleUnitId, driver:suppliedDriverId };
const assignmentPassMarkers = {
  client:'REAL_ASSIGNMENT_CLIENT=PASS',
  contractor:'REAL_ASSIGNMENT_CONTRACTOR=PASS',
  vehicle_unit:'REAL_ASSIGNMENT_VEHICLE=PASS',
  driver:'REAL_ASSIGNMENT_DRIVER=PASS',
};
const qaSshHost = String(process.env.QA_SSH_HOST || '');
const qaSshPort = String(process.env.QA_SSH_PORT || '');
const qaSshUser = String(process.env.QA_SSH_USER || '');
const qaPlanexRoot = String(process.env.QA_PLANEX_ROOT || '');
fs.mkdirSync(out, { recursive: true });

const marks = [];
function mark(line) {
  marks.push(line);
  fs.writeFileSync(`${out}/proof.txt`, marks.join('\n') + '\n');
  console.log(line);
}
function must(value, message) { if (!value) throw new Error(message); }
function uniqInts(values) { return [...new Set((values || []).map(Number).filter(x => x > 0))].sort((a,b) => a-b); }
function sameInts(a,b) { return JSON.stringify(uniqInts(a)) === JSON.stringify(uniqInts(b)); }
function stableObject(value) {
  if (Array.isArray(value)) return value.map(stableObject);
  if (value && typeof value === 'object') {
    const out = {};
    Object.keys(value).sort().forEach(k => { out[k] = stableObject(value[k]); });
    return out;
  }
  return value;
}
function sha(value) { return crypto.createHash('sha256').update(String(value)).digest('hex'); }
function appPath(url) {
  const p = new URL(url).pathname;
  return p.startsWith('/erpv2') ? (p.slice('/erpv2'.length) || '/') : p;
}
function shellQuote(value) { return "'" + String(value).replace(/'/g, "'\\''") + "'"; }
function assignmentSnapshot() {
  must(/^\d+$/.test(runId), 'QA run id is invalid for assignment snapshot');
  must(qaSshHost && qaSshPort && qaSshUser && qaPlanexRoot, 'QA SSH assignment snapshot channel is unavailable');
  const key = `${process.env.HOME || ''}/.ssh/roles_functional`;
  const remote = `/tmp/roles_access_func_identity_${runId}.php`;
  const command = `PLANEX_ROOT=${shellQuote(qaPlanexRoot)} QA_RUN_ID=${shellQuote(runId)} /usr/bin/php8.5 ${shellQuote(remote)} assignment-snapshot`;
  const output = execFileSync('ssh', [
    '-p', qaSshPort, '-i', key, '-o', 'IdentitiesOnly=yes', '-o', 'StrictHostKeyChecking=yes',
    `${qaSshUser}@${qaSshHost}`, command
  ], {encoding:'utf8', maxBuffer:1024*1024});
  const line = String(output).split(/\r?\n/).find(x => x.startsWith('QA_ASSIGNMENT_SNAPSHOT_B64='));
  must(line, 'assignment DB snapshot marker missing');
  const snapshot = JSON.parse(Buffer.from(line.slice('QA_ASSIGNMENT_SNAPSHOT_B64='.length), 'base64').toString('utf8'));
  must(snapshot && snapshot.fixtures && typeof snapshot.fixtures === 'object', 'assignment DB snapshot payload invalid');
  return snapshot;
}
function multiCompanySnapshot() {
  must(/^\d+$/.test(runId), 'QA run id is invalid for multi-company snapshot');
  must(qaSshHost && qaSshPort && qaSshUser && qaPlanexRoot, 'QA SSH multi-company snapshot channel is unavailable');
  const key = `${process.env.HOME || ''}/.ssh/roles_functional`;
  const remote = `/tmp/roles_access_func_identity_${runId}.php`;
  const command = `PLANEX_ROOT=${shellQuote(qaPlanexRoot)} QA_RUN_ID=${shellQuote(runId)} /usr/bin/php8.5 ${shellQuote(remote)} multi-company-snapshot`;
  const output = execFileSync('ssh', [
    '-p', qaSshPort, '-i', key, '-o', 'IdentitiesOnly=yes', '-o', 'StrictHostKeyChecking=yes',
    `${qaSshUser}@${qaSshHost}`, command
  ], {encoding:'utf8', maxBuffer:1024*1024});
  const line = String(output).split(/\r?\n/).find(x => x.startsWith('QA_MULTI_COMPANY_SNAPSHOT_B64='));
  must(line, 'multi-company DB snapshot marker missing');
  const snapshot = JSON.parse(Buffer.from(line.slice('QA_MULTI_COMPANY_SNAPSHOT_B64='.length), 'base64').toString('utf8'));
  must(snapshot && Array.isArray(snapshot.memberships), 'multi-company DB snapshot payload invalid');
  return snapshot;
}
function qaClientAName() { return `QA Functional Client ${runId}`; }
function qaClientBName() { return `QA Functional Switch B Client ${runId}`; }
function exactJson(value) { return JSON.stringify(stableObject(value)); }
function assignmentKindRows(fixture, kind) { return (fixture.assignments || []).filter(row => String(row.assignment_kind) === kind); }
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
  must(r.status === 200 && r.payload && Array.isArray(r.payload.roles) && Array.isArray(r.payload.memberships), 'roles-access snapshot failed');
  return r.payload;
}
function stateFingerprint(state) {
  const roles = (state.roles || []).map(r => ({
    id:Number(r.id), name:r.name, system_key:r.system_key || null, is_system:!!r.is_system,
    default_page_key:r.default_page_key || null, membership_count:Number(r.membership_count||0),
    pages:stableObject(r.pages||{}), data_scopes:stableObject(r.data_scopes||{})
  })).sort((a,b)=>a.id-b.id);
  const memberships = (state.memberships || []).map(m => ({
    id:Number(m.id), account_id:Number(m.account_id||0), login:String(m.login||''), status:String(m.status||''),
    role_id:Number(m.role_id||0), page_overrides:stableObject(m.page_overrides||{}),
    data_scope_overrides:stableObject(m.data_scope_overrides||{}), effective_pages:stableObject(m.effective_pages||{}),
    effective_data_scopes:stableObject(m.effective_data_scopes||{})
  })).sort((a,b)=>a.id-b.id);
  return JSON.stringify({roles,memberships});
}
async function visibleSidebarPaths(page) {
  return page.locator('.sidebar a.nav-item, .sidebar a.nav-sub-item').evaluateAll(nodes => [...new Set(nodes.map(n => {
    const p = new URL(n.href, location.href).pathname;
    return p.startsWith('/erpv2') ? (p.slice('/erpv2'.length) || '/') : p;
  }))].sort());
}
async function navigationBadgeProof(page) {
  await page.goto(base + '/company/dashboard', {waitUntil:'networkidle',timeout:60000});
  const badges = page.locator('.sidebar .nav-count.is-alert');
  const count = await badges.count();
  let evidence = null;
  for (let i=0;i<count;i++) {
    const badge = badges.nth(i);
    if (!(await badge.isVisible())) continue;
    const text = String(await badge.innerText()).trim();
    if (!/^\d+\+?$/.test(text) || Number.parseInt(text,10) <= 0) continue;
    const meta = await badge.evaluate(el => {
      const style = getComputedStyle(el);
      const holder = el.closest('a.nav-item,a.nav-sub-item,.nav-item.is-parent');
      return {
        text: String(el.textContent || '').trim(),
        title: String(el.getAttribute('title') || ''),
        holder: String(holder ? holder.textContent || '' : '').replace(/\s+/g,' ').trim(),
        href: holder && holder.tagName === 'A' ? holder.getAttribute('href') || '' : '',
        display: style.display, visibility: style.visibility, opacity: style.opacity,
      };
    });
    if (meta.display === 'none' || meta.visibility === 'hidden' || Number(meta.opacity) === 0) continue;
    evidence = meta; break;
  }
  must(evidence && Number.parseInt(evidence.text,10) > 0, 'no visible nonzero production navigation badge/counter found');
  mark('NAVIGATION_BADGE_NONZERO=PASS');
  mark(`NAVIGATION_BADGE_VALUE=${String(evidence.text)}`);
  mark(`NAVIGATION_BADGE_CONTEXT=${String(evidence.holder || evidence.title).replace(/[\r\n=]+/g,' ').slice(0,160)}`);
  await page.screenshot({path:`${out}/navigation-badge-nonzero.png`,fullPage:true});
}

async function loginWithCredential(browser, credential) {
  const context = await browser.newContext({ viewport:{width:1440,height:1000}, locale:'ru-RU' });
  const page = await context.newPage();
  let response = await page.goto(base + '/login', { waitUntil:'domcontentloaded', timeout:60000 });
  if (!response || response.status() !== 200) { await context.close(); return null; }
  await page.locator('input[name="login"]').fill(String(credential.username || ''));
  await page.locator('input[name="password"]').fill(String(credential.password || ''));
  await Promise.all([
    page.waitForNavigation({ waitUntil:'domcontentloaded', timeout:60000 }).catch(()=>null),
    page.locator('button[type="submit"]').click()
  ]);
  if (appPath(page.url()) === '/login') { await context.close(); return null; }
  return { context, page, credential };
}
async function realOwnerLogin(browser) {
  const owners = credentials.filter(x => String(x.role || '').toUpperCase() === 'OWNER');
  must(owners.length > 0, 'OWNER runtime credential is absent');
  for (const credential of owners) {
    const result = await loginWithCredential(browser, credential);
    if (!result) continue;
    if (appPath(result.page.url()) !== '/company/dashboard') { await result.context.close(); continue; }
    return result;
  }
  throw new Error('No current OWNER credential completed normal /login authentication');
}
function expectedLanding(state, membership) {
  const role = (state.roles||[]).find(r => Number(r.id) === Number(membership.role_id));
  if (!role) return null;
  const pages = state.pages || [];
  const byKey = new Map(pages.map(p => [String(p.key), p]));
  const preferred = role.default_page_key ? byKey.get(String(role.default_page_key)) : null;
  if (preferred && preferred.landing_eligible !== false && membership.effective_pages?.[role.default_page_key] === true) return String(preferred.path);
  const first = pages.find(p => p.landing_eligible !== false && membership.effective_pages?.[p.key] === true);
  return first ? String(first.path) : '/access-unavailable';
}
async function optionalSecondaryEffectiveMenu(browser, ownerState) {
  const candidates = credentials.filter(x => String(x.role || '').toUpperCase() !== 'OWNER');
  for (const credential of candidates) {
    const membership = (ownerState.memberships||[]).find(m => String(m.login||'').trim().toLowerCase() === String(credential.username||'').trim().toLowerCase() && m.status === 'active');
    if (!membership) continue;
    const result = await loginWithCredential(browser, credential);
    if (!result) continue;
    try {
      const expected = expectedLanding(ownerState, membership);
      must(expected !== null && appPath(result.page.url()) === expected, `secondary default landing mismatch ${appPath(result.page.url())} != ${expected}`);
      const actual = await visibleSidebarPaths(result.page);
      const expectedGrantable = (ownerState.pages||[]).filter(p => p.nav_visible !== false && membership.effective_pages?.[p.key] === true).map(p => String(p.path)).sort();
      const actualGrantable = actual.filter(p => (ownerState.pages||[]).some(x => String(x.path) === p)).sort();
      must(JSON.stringify(actualGrantable) === JSON.stringify(expectedGrantable), 'secondary menu does not match effective page permissions');
      for (const ownerOnly of ['/company/roles-access','/company/responsible-assignments','/company/misc/production-calendar']) {
        must(!actual.includes(ownerOnly), `secondary menu leaked owner-only path ${ownerOnly}`);
      }
      mark('SECONDARY_EFFECTIVE_MENU=PASS');
      return;
    } finally { await result.context.close(); }
  }
  mark('SECONDARY_EFFECTIVE_MENU=NOT_AVAILABLE');
}
async function switchCompany(page, value) {
  const select = page.locator('#active-company-switch');
  must(await select.count() === 1, 'company switch missing');
  await select.selectOption(String(value));
  await page.waitForLoadState('domcontentloaded', { timeout:60000 });
  // onchange submit can race the load-state call; require selected company by reloading roles-access below.
}
async function multiCompanySwitchProof(page, registryState) {
  must(suppliedCompanyAId>0 && suppliedCompanyBId>0 && suppliedCompanyAId!==suppliedCompanyBId, 'multi-company fixture company ids unavailable');
  must(suppliedOwnerMembershipId>0 && suppliedMultiCompanyMembershipId>0, 'multi-company membership ids unavailable');
  must(suppliedMultiCompanyTenantUserId>0 && suppliedMultiCompanyBClientId>0, 'multi-company tenant fixture unavailable');
  const centralBefore = multiCompanySnapshot();
  fs.writeFileSync(`${out}/multi-company-before.json`, JSON.stringify(stableObject(centralBefore), null, 2)+'\n');
  const memberships = centralBefore.memberships || [];
  const a = memberships.find(m=>Number(m.company_id)===suppliedCompanyAId && Number(m.membership_id)===suppliedOwnerMembershipId);
  const b = memberships.find(m=>Number(m.company_id)===suppliedCompanyBId && Number(m.membership_id)===suppliedMultiCompanyMembershipId);
  must(a && b && Number(a.account_id)===Number(b.account_id) && Number(a.account_id)===Number(centralBefore.account_id), 'one global account is not bound to both company memberships');
  must(String(a.status)==='active' && String(b.status)==='active', 'multi-company memberships are not active');
  must(String(a.system_key||'')==='company_owner', 'company A membership is not the expected owner role');
  must(String(b.system_key||'')==='' && String(b.default_page_key||'')==='company.clients', 'company B role/default landing fixture mismatch');
  must(b.role_pages?.['company.dashboard']===true && b.role_pages?.['company.clients']===true && b.role_pages?.['company.contractors']===false, 'company B role page baseline mismatch');
  must(String(b.role_data_scopes?.client||'')==='all' && String(b.role_data_scopes?.contractor||'')==='none', 'company B role data-scope baseline mismatch');
  must(String(b.page_overrides?.['company.contractors']||'')==='allow', 'company B page override missing');
  must(String(b.data_scope_overrides?.contractor||'')==='own', 'company B data override missing');
  must(Object.keys(a.page_overrides||{}).length===0 && Object.keys(a.data_scope_overrides||{}).length===0, 'company B overrides leaked into company A membership');
  mark('MULTI_COMPANY_ACCOUNT=PASS');

  await page.goto(base + '/company/dashboard', {waitUntil:'networkidle',timeout:60000});
  must(appPath(page.url())==='/company/dashboard', 'company A did not start on owner dashboard');
  let switcher=page.locator('#active-company-switch');
  must(await switcher.count()===1, 'multi-company selector missing in company A');
  let options=await switcher.locator('option').evaluateAll(nodes=>nodes.map(n=>({value:Number(n.value),selected:n.selected,text:String(n.textContent||'').trim()})));
  must(options.length>=2, 'multi-company selector has fewer than two companies');
  must(options.some(x=>x.value===suppliedCompanyAId && x.selected), 'company A is not selected before switch');
  must(options.some(x=>x.value===suppliedCompanyBId), 'company B option is absent');
  mark(`COMPANY_SWITCH_OPTIONS=${options.length}`);
  mark('COMPANY_SWITCH_OPTIONS>=2');
  const menuA=(await visibleSidebarPaths(page)).sort();
  must(menuA.includes('/company/roles-access') && menuA.includes('/company/responsible-assignments'), 'company A owner menu baseline missing');

  await Promise.all([
    page.waitForNavigation({waitUntil:'domcontentloaded',timeout:60000}),
    switcher.selectOption(String(suppliedCompanyBId))
  ]);
  must(appPath(page.url())==='/company/clients', `company B default landing mismatch: ${appPath(page.url())}`);
  switcher=page.locator('#active-company-switch');
  must(await switcher.count()===1 && Number(await switcher.inputValue())===suppliedCompanyBId, 'session/current company did not become company B');
  mark('COMPANY_SWITCH_A_TO_B=PASS');
  mark('COMPANY_B_DEFAULT_LANDING=PASS');

  const menuB=(await visibleSidebarPaths(page)).sort();
  const expectedB=(registryState.pages||[]).filter(p=>p.nav_visible!==false && ((b.page_overrides||{})[p.key]==='allow' || (((b.page_overrides||{})[p.key]!=='deny') && b.role_pages?.[p.key]===true))).map(p=>String(p.path)).sort();
  const registryPaths=new Set((registryState.pages||[]).map(p=>String(p.path)));
  const actualB=menuB.filter(p=>registryPaths.has(p)).sort();
  must(JSON.stringify(actualB)===JSON.stringify(expectedB), `company B effective menu differs from role+membership permissions: expected=${JSON.stringify(expectedB)} actual=${JSON.stringify(actualB)}`);
  must(!menuB.includes('/company/roles-access') && !menuB.includes('/company/responsible-assignments') && !menuB.includes('/company/finance/dashboard'), 'company A/owner permissions leaked into company B menu');
  mark('COMPANY_B_EFFECTIVE_MENU=PASS');

  const bodyB=String(await page.locator('body').innerText());
  must(bodyB.includes(qaClientBName()), 'company B run-scoped client is not visible after switch');
  must(!bodyB.includes(qaClientAName()), 'company A run-scoped client leaked into company B data');
  mark('CROSS_COMPANY_DATA_ISOLATION=PASS');

  const denied=await page.goto(base + '/company/roles-access', {waitUntil:'domcontentloaded',timeout:60000});
  must(denied && denied.status()===403, `direct owner-only URL after switch returned ${denied && denied.status()}`);
  mark('DIRECT_DENIED_AFTER_SWITCH=403_PASS');

  await page.goto(base + '/company/dashboard', {waitUntil:'networkidle',timeout:60000});
  switcher=page.locator('#active-company-switch');
  must(await switcher.count()===1 && Number(await switcher.inputValue())===suppliedCompanyBId, 'company B context was lost after denied request');
  const centralDuring=multiCompanySnapshot();
  must(exactJson(centralDuring)===exactJson(centralBefore), 'company switch mutated central role/membership/override state');
  await Promise.all([
    page.waitForNavigation({waitUntil:'domcontentloaded',timeout:60000}),
    switcher.selectOption(String(suppliedCompanyAId))
  ]);
  must(appPath(page.url())==='/company/dashboard', `company A landing did not restore: ${appPath(page.url())}`);
  switcher=page.locator('#active-company-switch');
  must(await switcher.count()===1 && Number(await switcher.inputValue())===suppliedCompanyAId, 'session/current company did not restore to company A');
  mark('COMPANY_SWITCH_B_TO_A=PASS');

  const menuARestored=(await visibleSidebarPaths(page)).sort();
  must(JSON.stringify(menuARestored)===JSON.stringify(menuA), 'company A effective menu did not restore exactly');
  const rolesResponse=await page.goto(base + '/company/roles-access', {waitUntil:'networkidle',timeout:60000});
  must(rolesResponse && rolesResponse.status()===200, 'company A owner-only access did not restore');
  const aState=await snapshot(page);
  must((aState.memberships||[]).some(m=>Number(m.id)===suppliedOwnerMembershipId), 'company A membership absent after restore');
  must(!(aState.memberships||[]).some(m=>Number(m.id)===suppliedMultiCompanyMembershipId), 'company B membership leaked into company A roles snapshot');
  const centralAfter=multiCompanySnapshot();
  fs.writeFileSync(`${out}/multi-company-after.json`, JSON.stringify(stableObject(centralAfter), null, 2)+'\n');
  must(exactJson(centralAfter)===exactJson(centralBefore), 'multi-company role/membership/override state changed after A-B-A switch');
  mark('COMPANY_A_STATE_RESTORED=PASS');
  mark('MEMBERSHIP_OVERRIDES_ISOLATED=PASS');
}

async function crossCompanyProof(page, originalState) {
  const originalIds = uniqInts((originalState.memberships||[]).map(x=>x.id));
  let foreignMembershipId = suppliedForeignMembershipId > 0 ? suppliedForeignMembershipId : 0;
  let switchCount = 0;
  await page.goto(base + '/company/roles-access', { waitUntil:'networkidle', timeout:60000 });
  const switcher = page.locator('#active-company-switch');
  if (await switcher.count() === 1) {
    const options = await switcher.locator('option').evaluateAll(nodes => nodes.map(n => ({value:n.value,selected:n.selected})));
    switchCount = options.length;
    if (options.length > 1) {
      const original = options.find(x=>x.selected)?.value || options[0].value;
      const foreign = options.find(x=>x.value !== original).value;
      await Promise.all([
        page.waitForNavigation({waitUntil:'domcontentloaded',timeout:60000}),
        switcher.selectOption(foreign)
      ]);
      const foreignAccess = await page.goto(base + '/company/roles-access', {waitUntil:'domcontentloaded',timeout:60000});
      if (foreignAccess && foreignAccess.status() === 200) {
        await page.waitForLoadState('networkidle', {timeout:60000});
        const foreignState = await snapshot(page);
        const foreignIds = uniqInts((foreignState.memberships||[]).map(x=>x.id));
        must(foreignIds.length > 0, 'foreign company has no memberships for isolation proof');
        must(foreignIds.every(id => !originalIds.includes(id)), 'membership identifiers overlap across company snapshots');
        if (foreignMembershipId <= 0) foreignMembershipId = foreignIds[0];
      } else {
        must(foreignAccess && foreignAccess.status() === 403, `unexpected foreign roles-access status ${foreignAccess && foreignAccess.status()}`);
        mark('FOREIGN_COMPANY_OWNER_ONLY_DENY=PASS');
        await page.goto(base + '/company/dashboard', {waitUntil:'domcontentloaded',timeout:60000});
      }
      const foreignSwitcher = page.locator('#active-company-switch');
      must(await foreignSwitcher.count() === 1, 'company switch missing while restoring original company');
      await Promise.all([
        page.waitForNavigation({waitUntil:'domcontentloaded',timeout:60000}),
        foreignSwitcher.selectOption(original)
      ]);
      await page.goto(base + '/company/roles-access', {waitUntil:'networkidle',timeout:60000});
      const restoredState = await snapshot(page);
      must(sameInts((restoredState.memberships||[]).map(x=>x.id), originalIds), 'company context did not restore after isolation probe');
    }
  }
  must(foreignMembershipId > 0, 'foreign membership id unavailable for isolation proof');
  const denied = await api(page, 'GET', `/company/roles-access/assignments?membership_id=${foreignMembershipId}`);
  must(denied.status === 404 || denied.status === 422, `foreign membership was readable in original company: ${denied.status}`);
  mark('FOREIGN_MEMBERSHIP_SERVER_REJECTION=PASS');
  mark(`COMPANY_SWITCH_OPTIONS=${switchCount}`);
  return foreignMembershipId;
}
async function assignmentTabProof(page, state, membershipId) {
  await page.goto(base + '/company/roles-access', {waitUntil:'networkidle',timeout:60000});
  await page.locator('[data-access-tab="assignments"]').click();
  const picker = page.locator('[data-assignment-membership]');
  await picker.selectOption(String(membershipId));
  for (let i=0;i<160;i++) {
    if (await page.locator('[data-assignment-group]').count() === 4) break;
    await page.waitForTimeout(100);
  }
  must(await page.locator('[data-assignment-group]').count() === 4, 'Закрепления did not load four entity groups');
  const pickerIds = uniqInts(await picker.locator('option').evaluateAll(nodes=>nodes.map(n=>n.value)));
  must(sameInts(pickerIds, (state.memberships||[]).map(m=>m.id)), 'Закрепления membership picker differs from current-company snapshot');
  const backend = await api(page,'GET',`/company/roles-access/assignments?membership_id=${Number(membershipId)}`);
  must(backend.status===200 && backend.payload.success===true, 'Закрепления backend read failed');
  for (const key of ['client','contractor','vehicle_unit','driver']) must(Array.isArray((backend.payload.entities||{})[key]), `Закрепления missing ${key}`);
  mark('ASSIGNMENTS_TAB=PASS');
}

async function assignmentBackendEntity(page, membershipId, type, entityId) {
  const backend = await api(page,'GET',`/company/roles-access/assignments?membership_id=${Number(membershipId)}`);
  must(backend.status===200 && backend.payload.success===true, `${type}: assignment backend read failed`);
  const entity = ((backend.payload.entities||{})[type]||[]).find(x=>Number(x.id)===Number(entityId));
  must(entity, `${type}: exact run-scoped assignment entity missing from backend readback`);
  return entity;
}

async function realAssignmentCheckboxPost(page, type, entityId, checked) {
  const checkbox = page.locator(`[data-assignment-type="${type}"][data-assignment-id="${Number(entityId)}"]`);
  must(await checkbox.count()===1, `${type}: exact run-scoped assignment checkbox missing`);
  await checkbox.waitFor({state:'visible',timeout:30000});
  const responsePromise = page.waitForResponse(response =>
    response.url() === base + '/company/roles-access/assignments/reassign'
      && response.request().method() === 'POST', {timeout:60000});
  if (checked) await checkbox.check(); else await checkbox.uncheck();
  const response = await responsePromise;
  let payload = {};
  try { payload = await response.json(); } catch (_) {}
  must(response.status()===200 && payload && payload.success===true, `${type}: real assignment POST failed with HTTP ${response.status()}`);
  for (let i=0;i<120;i++) {
    if (!(await checkbox.isDisabled())) break;
    await page.waitForTimeout(50);
  }
  must(!(await checkbox.isDisabled()), `${type}: checkbox remained locked after real POST`);
  must((await checkbox.isChecked())===checked, `${type}: checkbox state differs from server-confirmed write`);
  return payload;
}

function validateManualAssignmentDelta(beforeFixture, afterFixture, type) {
  const protectedCreatorFields = ['created_by_user_id','created_by_membership_id','created_by_role'];
  for (const field of protectedCreatorFields) {
    must(String(beforeFixture.entity?.[field] ?? '')===String(afterFixture.entity?.[field] ?? ''), `${type}: ${field} changed during manual assignment`);
  }
  must(exactJson(beforeFixture.entity)===exactJson(afterFixture.entity), `${type}: entity row changed during manual assignment`);
  must(exactJson(beforeFixture.responsibilities||[])===exactJson(afterFixture.responsibilities||[]), `${type}: entity_responsibilities changed during manual assignment`);
  const beforeCreator=assignmentKindRows(beforeFixture,'creator_auto');
  const afterCreator=assignmentKindRows(afterFixture,'creator_auto');
  const beforeLegacy=assignmentKindRows(beforeFixture,'legacy_unclassified');
  const afterLegacy=assignmentKindRows(afterFixture,'legacy_unclassified');
  const beforeManual=assignmentKindRows(beforeFixture,'manual');
  const afterManual=assignmentKindRows(afterFixture,'manual');
  must(exactJson(beforeCreator)===exactJson(afterCreator), `${type}: creator_auto rows changed`);
  must(exactJson(beforeLegacy)===exactJson(afterLegacy), `${type}: legacy_unclassified rows changed`);
  must(beforeCreator.length===1 && beforeLegacy.length===1 && beforeManual.length===0, `${type}: exact BEFORE provenance baseline invalid`);
  must(afterManual.length===1, `${type}: real POST did not create exactly one manual row`);
  must(Number(afterManual[0].membership_id)===suppliedDynamicMembershipId, `${type}: manual row membership mismatch`);
  must(Number(afterManual[0].assigned_by_membership_id)===suppliedOwnerMembershipId, `${type}: manual row actor mismatch`);
}

async function realManualAssignmentsProof(page, state, membershipId) {
  must(suppliedOwnerMembershipId>0 && suppliedDynamicMembershipId>0, 'assignment proof membership bindings missing');
  for (const [type,id] of Object.entries(assignmentFixtureIds)) must(Number(id)>0, `assignment fixture id missing for ${type}`);
  await assignmentTabProof(page,state,membershipId);
  const allBefore = assignmentSnapshot();
  fs.writeFileSync(`${out}/assignments_before_all.json`, JSON.stringify(allBefore,null,2)+'\n');
  const order=['client','contractor','vehicle_unit','driver'];
  for (const type of order) {
    const entityId=Number(assignmentFixtureIds[type]);
    const before = assignmentSnapshot();
    const beforeFixture = before.fixtures[type];
    must(beforeFixture, `${type}: exact BEFORE DB snapshot missing`);
    fs.writeFileSync(`${out}/assignment_${type}_before.json`, JSON.stringify(beforeFixture,null,2)+'\n');
    const beforeApi = await assignmentBackendEntity(page,membershipId,type,entityId);
    must(beforeApi.explicit_assigned===false, `${type}: exact BEFORE unexpectedly has manual assignment`);
    let changed=false;
    try {
      const post = await realAssignmentCheckboxPost(page,type,entityId,true);
      changed=true;
      must((post.membership_ids||[]).map(Number).includes(membershipId), `${type}: server success payload omitted selected membership`);
      const afterApi = await assignmentBackendEntity(page,membershipId,type,entityId);
      must(afterApi.explicit_assigned===true, `${type}: backend GET did not read back manual assignment`);
      const after = assignmentSnapshot();
      const afterFixture = after.fixtures[type];
      fs.writeFileSync(`${out}/assignment_${type}_manual.json`, JSON.stringify(afterFixture,null,2)+'\n');
      validateManualAssignmentDelta(beforeFixture,afterFixture,type);
      for (const otherType of order) {
        if (otherType === type) continue;
        must(exactJson(after.fixtures[otherType])===exactJson(before.fixtures[otherType]), `${type}: real manual POST mutated unrelated ${otherType} fixture`);
      }

      const restorePost = await realAssignmentCheckboxPost(page,type,entityId,false);
      changed=false;
      must(!(restorePost.membership_ids||[]).map(Number).includes(membershipId), `${type}: restore server payload still contains selected membership`);
      const restoredApi = await assignmentBackendEntity(page,membershipId,type,entityId);
      must(restoredApi.explicit_assigned===false, `${type}: backend GET did not confirm manual restore`);
      const restored = assignmentSnapshot();
      fs.writeFileSync(`${out}/assignment_${type}_restored.json`, JSON.stringify(restored.fixtures[type],null,2)+'\n');
      must(exactJson(restored.fixtures[type])===exactJson(beforeFixture), `${type}: exact DB BEFORE was not restored`);
      must(exactJson(restored)===exactJson(before), `${type}: assignment restore changed another fixture`);
      must(assignmentPassMarkers[type], `${type}: mandatory production proof marker missing`);
      mark(assignmentPassMarkers[type]);
    } finally {
      if (changed) {
        try { await realAssignmentCheckboxPost(page,type,entityId,false); } catch (e) { console.error(`CLEANUP ${type} manual restore failed:`, e.message); }
      }
    }
  }
  const final = assignmentSnapshot();
  fs.writeFileSync(`${out}/assignments_restored_all.json`, JSON.stringify(final,null,2)+'\n');
  must(exactJson(final)===exactJson(allBefore), 'assignment fixture set differs from exact BEFORE after all restores');
  mark('ASSIGNMENT_RESTORE_DIFF=0');
}

async function responsibilityCandidate(page, activeMembershipIds, foreignMembershipId, state) {
  must(suppliedOwnerTenantUserId > 0, 'run-scoped owner tenant user id missing');
  must(suppliedContractorId > 0, 'run-scoped contractor id missing');
  const tab = 'contractor';
  const response = await page.goto(base + `/company/responsible-assignments?tab=${tab}`, {waitUntil:'networkidle',timeout:60000});
  const responseStatus = response ? response.status() : 0;
  if (responseStatus !== 200) {
    const finalPath = appPath(page.url());
    let responseText = '';
    try { responseText = response ? await response.text() : ''; } catch (_) {}
    const safeSnippet = String(responseText)
      .replace(/[A-Za-z0-9+\/_=-]{32,}/g, '[redacted]')
      .replace(/\s+/g, ' ')
      .trim()
      .slice(0, 800);
    mark(`RESPONSIBILITY_PAGE_HTTP_STATUS=${responseStatus}`);
    mark(`RESPONSIBILITY_PAGE_FINAL_PATH=${finalPath}`);
    if (safeSnippet) console.error(`RESPONSIBILITY_PAGE_BODY_SNIPPET=${safeSnippet}`);
    throw new Error(`${tab}: responsibility page did not return HTTP 200; status=${responseStatus}; final_path=${finalPath}`);
  }
  const danger = page.locator('.notice.danger');
  if (await danger.count() > 0) throw new Error(`${tab}: responsibility page error: ${await danger.first().innerText()}`);
  must(await page.locator('#massReassignToolbarForm').count() === 1, `${tab}: responsibility toolbar missing for run-scoped contractor`);
  const recipientRows = await page.locator('#massReassignToolbarForm select[name="new_logist_id"] option[data-membership-id]').evaluateAll(nodes => nodes.map(n=>({user_id:Number(n.value),membership_id:Number(n.dataset.membershipId)})));
  must(recipientRows.length > 0, `${tab}: no responsibility recipients are visible`);
  const dynamicRecipients = recipientRows.filter(r => {
    const m = (state.memberships||[]).find(x => Number(x.id) === Number(r.membership_id));
    const role = m && (state.roles||[]).find(x => Number(x.id) === Number(m.role_id));
    return !!(m && role && !role.is_system && !role.system_key && m.status === 'active'
      && Number(m.id) === suppliedDynamicMembershipId
      && Number(r.user_id) === suppliedDynamicTenantUserId);
  });
  must(dynamicRecipients.length === 1, `${tab}: exact dynamic-role responsibility recipient is not uniquely visible`);
  for (const r of recipientRows) {
    must(activeMembershipIds.includes(r.membership_id), `${tab}: recipient membership ${r.membership_id} is outside current active membership set`);
    if (foreignMembershipId > 0) must(r.membership_id !== foreignMembershipId, `${tab}: foreign membership leaked into recipient list`);
  }
  const hidden = page.locator(`form.inline-actions input[name="entity_ids[]"][value="${suppliedContractorId}"]`);
  must(await hidden.count() === 1, `${tab}: exact run-scoped contractor transfer form is missing`);
  const form = hidden.locator('xpath=..');
  const csrf = form.locator('input[name="_csrf_token"]');
  const csrfValues = await csrf.evaluateAll(nodes => nodes.map(n => String(n.value || '')));
  must(csrfValues.length >= 1 && csrfValues.every(Boolean) && new Set(csrfValues).size === 1, `${tab}: exact run-scoped contractor CSRF token missing or inconsistent`);
  const select = form.locator('select[name="new_logist_id"]');
  const original = Number(await select.inputValue() || 0);
  must(original === suppliedOwnerTenantUserId, `${tab}: run-scoped contractor baseline owner mismatch; expected tenant user ${suppliedOwnerTenantUserId}, got ${original}`);
  const options = await select.locator('option[data-membership-id]').evaluateAll(nodes => nodes.map(n=>({user_id:Number(n.value),membership_id:Number(n.dataset.membershipId)})));
  const target = options.find(x => Number(x.user_id) === suppliedDynamicTenantUserId && Number(x.membership_id) === suppliedDynamicMembershipId);
  must(!!target, `${tab}: exact dynamic-role target missing from run-scoped contractor form`);
  must(String(await form.locator('input[name="entity_type"]').inputValue()) === 'contractor', `${tab}: run-scoped fixture entity type mismatch`);
  mark('DYNAMIC_RESPONSIBILITY_RECIPIENT=PASS');
  return {tab,entityId:suppliedContractorId,entityType:'contractor',original,targetUserId:target.user_id,targetMembershipId:target.membership_id};
}
async function selectedResponsibilityUser(page, candidate) {
  await page.goto(base + `/company/responsible-assignments?tab=${candidate.tab}`, {waitUntil:'networkidle',timeout:60000});
  const form = page.locator(`form.inline-actions input[name="entity_ids[]"][value="${candidate.entityId}"]`).locator('xpath=..');
  must(await form.count()===1, 'responsibility row missing during readback');
  return Number(await form.locator('select[name="new_logist_id"]').inputValue() || 0);
}
async function submitResponsibility(page, candidate, targetUserId) {
  await page.goto(base + `/company/responsible-assignments?tab=${candidate.tab}`, {waitUntil:'networkidle',timeout:60000});
  const hidden = page.locator(`form.inline-actions input[name="entity_ids[]"][value="${candidate.entityId}"]`);
  must(await hidden.count()===1, 'responsibility transfer form missing');
  const form = hidden.locator('xpath=..');
  const csrf = form.locator('input[name="_csrf_token"]');
  const csrfValues = await csrf.evaluateAll(nodes => nodes.map(n => String(n.value || '')));
  must(csrfValues.length >= 1 && csrfValues.every(Boolean) && new Set(csrfValues).size === 1, 'responsibility transfer CSRF token missing or inconsistent');
  await form.locator('select[name="new_logist_id"]').selectOption(String(targetUserId));
  await Promise.all([
    page.waitForNavigation({waitUntil:'domcontentloaded',timeout:60000}),
    form.evaluate(f=>f.requestSubmit())
  ]);
  must(new URL(page.url()).searchParams.get('msg') === 'assigned', `responsibility transfer did not return assigned status: ${page.url()}`);
}

(async () => {
  const browser = await chromium.launch({headless:true});
  let ownerContext = null;
  let page = null;
  let initialState = null;
  let initialFingerprint = '';
  let memberBefore = null;
  let memberId = 0;
  let responsibility = null;
  let responsibilityChanged = false;
  let overridesChanged = false;
  try {
    must(Array.isArray(credentials) && credentials.length > 0, 'runtime credential payload missing');
    const owner = await realOwnerLogin(browser);
    ownerContext = owner.context; page = owner.page;
    mark('REAL_LOGIN=PASS');
    mark('DEFAULT_LANDING=PASS');

    let response = await page.goto(base + '/company/roles-access', {waitUntil:'networkidle',timeout:60000});
    must(response && response.status()===200, '/company/roles-access not 200');
    mark('ROLES_ACCESS=PASS');
    initialState = await snapshot(page);
    initialFingerprint = stateFingerprint(initialState);
    fs.writeFileSync(`${out}/state_before.sha256.txt`, sha(initialFingerprint)+'\n');
    const ownerMembership = (initialState.memberships||[]).find(m=>m.is_system_owner===true && m.status==='active');
    must(ownerMembership, 'active owner membership absent');

    const visible = await visibleSidebarPaths(page);
    const expectedOwnerGrantable = (initialState.pages||[]).filter(p=>p.nav_visible!==false && ownerMembership.effective_pages?.[p.key]===true).map(p=>String(p.path)).sort();
    const actualOwnerGrantable = visible.filter(p=>(initialState.pages||[]).some(x=>String(x.path)===p)).sort();
    must(JSON.stringify(actualOwnerGrantable)===JSON.stringify(expectedOwnerGrantable), 'owner sidebar differs from effective grantable pages');
    for (const ownerOnly of ['/company/roles-access','/company/responsible-assignments','/company/misc/production-calendar']) must(visible.includes(ownerOnly), `owner menu missing ${ownerOnly}`);
    mark('EFFECTIVE_MENU=PASS');
    await navigationBadgeProof(page);
    await optionalSecondaryEffectiveMenu(browser, initialState);
    await multiCompanySwitchProof(page, initialState);

    const foreignMembershipId = await crossCompanyProof(page, initialState);
    initialState = await snapshot(page);
    initialFingerprint = stateFingerprint(initialState);

    const ordinaryMembership = (initialState.memberships||[]).find(m=>m.status==='active' && !m.is_system_owner
      && (suppliedDynamicMembershipId <= 0 || Number(m.id) === suppliedDynamicMembershipId)
      && (initialState.roles||[]).some(r=>Number(r.id)===Number(m.role_id) && !r.is_system && !r.system_key));
    must(ordinaryMembership, 'run-scoped dynamic membership unavailable for role/override acceptance');
    if (suppliedDynamicMembershipId > 0) must(Number(ordinaryMembership.id) === suppliedDynamicMembershipId, 'dynamic membership binding mismatch');
    const ordinaryRole = (initialState.roles||[]).find(r=>Number(r.id)===Number(ordinaryMembership.role_id));
    must(ordinaryRole, 'ordinary role missing');

    // Role write/readback using exact current semantics: no access is changed.
    let roleSave = await api(page,'POST',`/company/roles-access/roles/${Number(ordinaryRole.id)}/save`,{
      name: ordinaryRole.name,
      pages: ordinaryRole.pages || {},
      data_scopes: ordinaryRole.data_scopes || {},
      default_page_key: ordinaryRole.default_page_key || null
    });
    must(roleSave.status===200 && roleSave.payload.ok===true, 'role same-state save failed');
    let state = await snapshot(page);
    let roleAfter = (state.roles||[]).find(r=>Number(r.id)===Number(ordinaryRole.id));
    must(roleAfter && roleAfter.name===ordinaryRole.name && JSON.stringify(stableObject(roleAfter.pages||{}))===JSON.stringify(stableObject(ordinaryRole.pages||{})) && JSON.stringify(stableObject(roleAfter.data_scopes||{}))===JSON.stringify(stableObject(ordinaryRole.data_scopes||{})) && (roleAfter.default_page_key||null)===(ordinaryRole.default_page_key||null), 'role backend readback differs after same-state save');
    mark('ROLES_READBACK=PASS');

    // Personal overrides: create same-effective temporary override rows, then restore exact prior override state.
    memberId = Number(ordinaryMembership.id);
    memberBefore = JSON.parse(JSON.stringify(ordinaryMembership));
    const pageKey = (state.pages||[]).map(p=>String(p.key)).find(k=>!Object.prototype.hasOwnProperty.call(memberBefore.page_overrides||{},k)) || String((state.pages||[])[0]?.key||'');
    const entityType = (state.regulated_entities||[]).map(String).find(k=>!Object.prototype.hasOwnProperty.call(memberBefore.data_scope_overrides||{},k)) || String((state.regulated_entities||[])[0]||'');
    must(pageKey && entityType, 'no page/scope available for personal override proof');
    const pageOverrides = Object.assign({}, memberBefore.page_overrides||{}, {[pageKey]: ordinaryRole.pages?.[pageKey]===true ? 'allow':'deny'});
    const dataOverrides = Object.assign({}, memberBefore.data_scope_overrides||{}, {[entityType]: String(ordinaryRole.data_scopes?.[entityType]||'none')});
    let memberSave = await api(page,'POST',`/company/roles-access/memberships/${memberId}/save`,{
      role_id:Number(memberBefore.role_id), status:String(memberBefore.status), page_overrides:pageOverrides, data_scope_overrides:dataOverrides
    });
    must(memberSave.status===200 && memberSave.payload.ok===true, 'personal override save failed');
    overridesChanged = true;
    state = await snapshot(page);
    let memberAfter = (state.memberships||[]).find(m=>Number(m.id)===memberId);
    must(memberAfter && memberAfter.page_overrides?.[pageKey]===pageOverrides[pageKey] && memberAfter.data_scope_overrides?.[entityType]===dataOverrides[entityType], 'personal override backend readback failed');
    must(memberAfter.effective_pages?.[pageKey]===memberBefore.effective_pages?.[pageKey] && memberAfter.effective_data_scopes?.[entityType]===memberBefore.effective_data_scopes?.[entityType], 'same-effective personal override changed effective access');
    mark('PERSONAL_OVERRIDES_READBACK=PASS');
    memberSave = await api(page,'POST',`/company/roles-access/memberships/${memberId}/save`,{
      role_id:Number(memberBefore.role_id), status:String(memberBefore.status), page_overrides:memberBefore.page_overrides||{}, data_scope_overrides:memberBefore.data_scope_overrides||{}
    });
    must(memberSave.status===200 && memberSave.payload.ok===true, 'personal override restore POST failed');
    state = await snapshot(page);
    memberAfter = (state.memberships||[]).find(m=>Number(m.id)===memberId);
    must(memberAfter && JSON.stringify(stableObject(memberAfter.page_overrides||{}))===JSON.stringify(stableObject(memberBefore.page_overrides||{})) && JSON.stringify(stableObject(memberAfter.data_scope_overrides||{}))===JSON.stringify(stableObject(memberBefore.data_scope_overrides||{})), 'personal override restore readback failed');
    overridesChanged = false;
    mark('PERSONAL_OVERRIDES_RESTORE=PASS');

    await realManualAssignmentsProof(page, state, memberId);

    const activeMembershipIds = uniqInts((state.memberships||[]).filter(m=>m.status==='active').map(m=>m.id));
    responsibility = await responsibilityCandidate(page, activeMembershipIds, foreignMembershipId, state);
    mark('RESPONSIBLE_ASSIGNMENTS=PASS');
    mark('RESPONSIBILITY_RECIPIENT_SCOPE=PASS');
    await submitResponsibility(page, responsibility, responsibility.targetUserId);
    responsibilityChanged = true;
    must(await selectedResponsibilityUser(page,responsibility)===responsibility.targetUserId, 'responsibility transfer backend readback mismatch');
    mark('RESPONSIBILITY_TRANSFER=PASS');
    mark('RESPONSIBILITY_BACKEND_READBACK=PASS');
    await submitResponsibility(page, responsibility, responsibility.original);
    must(await selectedResponsibilityUser(page,responsibility)===responsibility.original, 'responsibility restore backend readback mismatch');
    responsibilityChanged = false;
    mark('RESPONSIBILITY_RESTORE=PASS');

    await page.goto(base + '/company/roles-access', {waitUntil:'networkidle',timeout:60000});
    const finalState = await snapshot(page);
    const finalFingerprint = stateFingerprint(finalState);
    fs.writeFileSync(`${out}/state_after.sha256.txt`, sha(finalFingerprint)+'\n');
    must(finalFingerprint===initialFingerprint, 'roles/membership state did not restore exactly');
    must(!(finalState.roles||[]).some(r=>String(r.name||'').includes(`QA FUNC ${runId}`)), 'QA role residue detected');
    mark('QA_RESIDUE=0');
    mark('CROSS_COMPANY_LEAKAGE=NO');
    mark('PRODUCTION_FUNCTIONAL_ACCEPTANCE=PASS');
    await page.screenshot({path:`${out}/roles-access-final.png`,fullPage:true});
  } finally {
    // Best-effort cleanup before surfacing any failure. The same normal HTTP paths
    // are used; no DB-side QA shortcut is permitted.
    if (page) {
      try {
        if (responsibilityChanged && responsibility) {
          await submitResponsibility(page,responsibility,responsibility.original);
          responsibilityChanged = false;
        }
      } catch (e) { console.error('CLEANUP responsibility restore failed:', e.message); }
      try {
        if (overridesChanged && memberBefore && memberId>0) {
          await page.goto(base + '/company/roles-access',{waitUntil:'networkidle',timeout:60000});
          await api(page,'POST',`/company/roles-access/memberships/${memberId}/save`,{
            role_id:Number(memberBefore.role_id), status:String(memberBefore.status), page_overrides:memberBefore.page_overrides||{}, data_scope_overrides:memberBefore.data_scope_overrides||{}
          });
          overridesChanged = false;
        }
      } catch (e) { console.error('CLEANUP override restore failed:', e.message); }
    }
    if (ownerContext) await ownerContext.close().catch(()=>{});
    await browser.close();
  }
})().catch(error => { console.error(error && error.stack ? error.stack : error); process.exit(1); });
