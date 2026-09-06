const fs = require('fs');
const crypto = require('crypto');
const { chromium } = require('playwright');

const base = 'https://plan-ex.ru/erpv2';
const out = process.env.RESULT_DIR || 'roles_access_functional_acceptance_result';
const encodedCredentials = process.env.P12_CREDENTIALS_B64 || '';
const credentials = encodedCredentials ? JSON.parse(Buffer.from(encodedCredentials, 'base64').toString('utf8')) : [];
const runId = process.env.GITHUB_RUN_ID || String(Date.now());
const suppliedForeignMembershipId = Number(process.env.QA_FOREIGN_MEMBERSHIP_ID || 0);
const suppliedOwnerTenantUserId = Number(process.env.QA_OWNER_TENANT_USER_ID || 0);
const suppliedDynamicMembershipId = Number(process.env.QA_DYNAMIC_MEMBERSHIP_ID || 0);
const suppliedDynamicTenantUserId = Number(process.env.QA_DYNAMIC_TENANT_USER_ID || 0);
const suppliedContractorId = Number(process.env.QA_CONTRACTOR_ID || 0);
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
async function responsibilityCandidate(page, activeMembershipIds, foreignMembershipId, state) {
  must(suppliedOwnerTenantUserId > 0, 'run-scoped owner tenant user id missing');
  must(suppliedContractorId > 0, 'run-scoped contractor id missing');
  const tab = 'contractor';
  const response = await page.goto(base + `/company/responsible-assignments?tab=${tab}`, {waitUntil:'networkidle',timeout:60000});
  must(response && response.status() === 200, `${tab}: responsibility page did not return HTTP 200`);
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
  must(await csrf.count() === 1 && !!(await csrf.inputValue()), `${tab}: exact run-scoped contractor CSRF token missing`);
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
  must(await csrf.count()===1 && !!(await csrf.inputValue()), 'responsibility transfer CSRF token missing');
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
    await optionalSecondaryEffectiveMenu(browser, initialState);

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

    await assignmentTabProof(page, state, memberId);

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
