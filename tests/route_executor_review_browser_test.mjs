import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const require = createRequire(import.meta.url);
const { chromium } = require('playwright');
const root = resolve(import.meta.dirname, '..');
const script = readFileSync(resolve(root, 'public/assets/js/route-executor-review-workflow.js'), 'utf8');
const css = readFileSync(resolve(root, 'public/assets/css/route-executor-review-workflow.css'), 'utf8');
const registryCss = readFileSync(resolve(root, 'public/assets/css/registry-components.css'), 'utf8');
const browser = await chromium.launch({ headless: true });
try {
  const context = await browser.newContext({ viewport: { width: 1366, height: 800 } });
  const page = await context.newPage();
  const errors = [];
  const sends = [];
  let currentCardId = null;
  let currentStage = 'new';
  let contractSent = false;
  let rejectNextFinal = false;
  page.on('pageerror', error => errors.push(error.message));
  await page.route('http://127.0.0.1:59999/**', route => {
    const path = new URL(route.request().url()).pathname;
    if (path.endsWith('/review/status')) return route.fulfill({ json: { ok: true, stage: 'new', contract_status: contractSent ? 'accepted' : 'not_sent', card: null } });
    if (path.endsWith('/review/config') && new URL(route.request().url()).searchParams.has('trip_search')) return route.fulfill({ json: { ok: true, trips: [{ id: 123, label: '#123 Вывоз отходов' }] } });
    if (path.endsWith('/review/config')) return route.fulfill({ json: { ok: true, sender: 'sender@example.test', author: 'Отправитель', recipients: ['info@plan-ex.ru'], send_responsible_logist: false,
      max_events: { 'route_executor.partial': { enabled: true, chat_config_id: 1, template: '🚚 Внесены частичные данные\n**{{trip}}**\n{{vehicle_driver}}\n\n**Не хватает для полного комплекта**\n{{missing}}\nЛогист: {{responsible}}' },
        'route_executor.full': { enabled: true, chat_config_id: 1, template: '🚛 ПОЛНЫЙ КОМПЛЕКТ\n**{{trip}}**\n{{vehicle_driver}}\nЛогист: {{responsible}}' } } } });
    if (path.endsWith('/review/send')) {
      const request = { body: route.request().postData(), csrf: route.request().headers()['x-csrf-token'] };
      sends.push(request);
      const action = request.body.match(/name="action"\r?\n\r?\n([a-z]+)/)?.[1];
      if (action === 'save')
        currentCardId = request.body.match(/name="card_id"\r?\n\r?\n([a-f0-9-]{36})/)?.[1] || currentCardId;
      if (action === 'delete') currentCardId = null;
      if (action === 'contract') contractSent = true;
      if (!action) {
        const requested = request.body.match(/name="stage"\r?\n\r?\n(final|partial)/)?.[1] || 'partial';
        if (requested === 'final' && rejectNextFinal) {
          rejectNextFinal = false;
          return route.fulfill({ status: 422, json: { ok: false, error: 'Для полного комплекта не хватает: пример' } });
        }
        currentStage = requested;
      }
      return route.fulfill({ json: action === 'save'
        ? { ok: true, revision: sends.filter(item => /name="action"\r?\n\r?\nsave/.test(item.body)).length, contract_status: contractSent ? 'accepted' : 'not_sent' }
        : action === 'contract' ? { ok: true, contract_status: 'accepted' }
          : action ? { ok: true } : { ok: true, stage: currentStage, mail_status: 'accepted', max_status: 'sent' } });
    }
    const row = currentCardId ? `<tr class="route-review-row${currentStage === 'final' ? ' route-review-row-final registry-ui-soft-success' : ' registry-ui-warning'}" data-route-review-card-id="${currentCardId}" ${currentStage === 'final' ? '' : 'data-erp-pin="1"'} tabindex="0"><td>ИП Тестовый</td><td><button data-route-review-delete="${currentCardId}">Удалить</button></td></tr>` : '';
    return route.fulfill({ status: 200, contentType: 'text/html', body: `<!doctype html><html><body><div class="route-executors-card" data-route-executors-grid><table class="table registry-ui-data"><tbody>${row}</tbody></table></div></body></html>` });
  });
  await page.goto('http://127.0.0.1:59999/');
  await page.setContent(`<meta name="csrf-token" content="test-csrf-token"><style>${css}</style><style>${registryCss}</style>
    <button data-source-create-open>Открыть</button><div class="route-executors-card" data-route-executors-grid><table class="table registry-ui-data"><tbody></tbody></table></div>
    <div id="route-executor-create-modal" class="is-open" data-workflow-scope="7-11">
      <div id="route-executor-create-modal-inner">
        <div class="modal-head"><span class="modal-title">Создать исполнителя рейса</span>
          <div data-route-readiness>
            <span class="route-readiness-item" data-readiness-key="contract" data-state="pending">Достаточно для подписания договора</span>
            <span class="route-readiness-item" data-readiness-key="training" data-state="pending">Для отправки на обучение</span>
            <span class="route-readiness-item" data-readiness-key="vehicle" data-state="pending">Для регистрации транспорта</span>
          </div>
        </div>
        <div id="route-executor-create-modal-content"><div data-combined-feedback class="is-hidden"></div>
          <select id="route-executor-responsible-logist"><option value="">—</option><option value="12">Иван</option></select>
          <input id="route-executor-trip-search" list="route-executor-trip-options"><datalist id="route-executor-trip-options"></datalist><input id="route-executor-trip-id" type="hidden">
          <form id="lab-contractor-form"><div class="entity-form-main"><div class="field"><label class="field-label">Наименование</label><input name="name"></div><input name="inn"><select name="contractor_type"><option value="">—</option><option value="individual">ИП</option></select><input name="kpp"><input name="ogrn"><input name="director_full_name"><input name="director_position"><textarea name="legal_address"></textarea></div></form>
          <form id="lab-driver-form"><input name="full_name"><input name="snils"><input name="phone"><input name="extra_phones[]" placeholder="+7 900 000-00-00"></form>
          <form id="lab-vehicle-form"><select name="set_type"><option value="">—</option><option value="single">Одиночка</option></select></form>
        </div>
        <div class="modal-required-note"></div>
        <div class="modal-foot-actions"><button data-source-create-submit>Создать исполнителя рейса</button></div>
      </div>
    </div>`);
  await page.addScriptTag({ content: script });
  await page.evaluate(() => {
    window.closeModal = id => document.getElementById(id)?.classList.remove('is-open');
    window.openModal = id => document.getElementById(id)?.classList.add('is-open');
  });
  if (errors.length) throw new Error('Browser script errors: ' + errors.join(' | '));
  const modal = page.locator('#route-executor-create-modal');
  await modal.locator('[data-source-create-submit]').waitFor();
  assert.equal(await modal.locator('[data-source-create-submit]').textContent(), 'Сохранить черновик');
  await page.evaluate(() => {
    window.routeExecutorExistingParts = { snapshot: () => ({ selected_entities: { contractor: 11, driver: 12, vehicle: 13 } }) };
    document.querySelector('#lab-driver-form [name=full_name]').dispatchEvent(new Event('input', { bubbles: true }));
  });
  await page.waitForTimeout(50);
  for (const key of ['contract', 'training', 'vehicle'])
    assert.equal(await modal.locator(`[data-readiness-key="${key}"]`).getAttribute('data-state'), 'ready', `existing ${key} block should be ready without field or file checks`);
  await page.evaluate(() => {
    delete window.routeExecutorExistingParts;
    document.querySelector('#lab-driver-form [name=full_name]').dispatchEvent(new Event('input', { bubbles: true }));
  });
  await modal.locator('[data-readiness-key="contract"]').click();
  assert.match(await modal.locator('#lab-readiness-details').innerText(), /Поля[\s\S]*Обязательные файлы/);
  await modal.locator('#lab-driver-form [name="full_name"]').fill('Тестовый Водитель Тестович');
  await modal.locator('[data-source-create-submit]').click();
  await page.waitForTimeout(300);
  assert.equal(await modal.locator('[data-lab-workflow-status]').count(), 0, 'Old status block is removed');
  assert.equal(await modal.locator('[data-lab-reset]').count(), 0, 'New card button is removed from modal');
  assert.match(await modal.locator('[data-combined-feedback]').innerText(), /Карточка сохранена/);
  assert.equal(await modal.evaluate(node => node.classList.contains('is-open')), false, 'Save closes the main popup');
  assert.equal(sends.filter(item => /name="action"\r?\n\r?\ncontract/.test(item.body)).length, 0, 'Save never sends contract mail');
  assert.equal(await page.locator('[data-route-review-card-id]').count(), 1, 'Shared yellow row appears after saving');
  await page.mouse.move(1300, 750);
  assert.equal(await page.locator('[data-route-review-card-id] td').first().evaluate(node => getComputedStyle(node).backgroundColor), 'rgb(253, 240, 213)');
  await page.evaluate(() => window.openModal('route-executor-create-modal'));
  assert.equal(await modal.locator('[data-lab-send]').isVisible(), false, 'No send without logist and trip');
  await modal.locator('#route-executor-responsible-logist').selectOption('12');
  assert.equal(await modal.locator('[data-lab-send]').isVisible(), false, 'No send without trip');
  await modal.locator('#route-executor-trip-search').focus();
  await page.waitForTimeout(150);
  await modal.locator('#route-executor-trip-search').fill('#123 Вывоз отходов');
  await page.waitForTimeout(250);
  assert.equal(await modal.locator('#route-executor-trip-id').inputValue(), '123');
  await page.evaluate(() => {
    window.routeExecutorExistingParts = { snapshot: () => ({ selected_entities: { contractor: 11 } }) };
    document.querySelector('#route-executor-create-modal').dispatchEvent(new Event('change', { bubbles: true }));
  });
  await page.waitForTimeout(100);
  assert.match(await modal.locator('[data-lab-send]').innerText(), /данные для договора/, 'Contract-only send is explicit');
  await modal.locator('[data-lab-send]').click();
  const dialog = page.locator('#lab-route-send-dialog');
  await dialog.waitFor({ state: 'visible' });
  assert.equal(await dialog.locator('[data-lab-max-heading]').isVisible(), false, 'Contract-only letter has no MAX preview');
  await dialog.locator('[data-lab-review-confirm]').click();
  await dialog.waitFor({ state: 'hidden' });
  assert.equal(await modal.evaluate(node => node.classList.contains('is-open')), false, 'Contract send closes every popup');
  assert.equal(sends.filter(item => /name="action"\r?\n\r?\ncontract/.test(item.body)).length, 1, 'Contract mail uses its own explicit action');
  await page.evaluate(() => {
    delete window.routeExecutorExistingParts;
    window.openModal('route-executor-create-modal');
    document.querySelector('#lab-driver-form [name=full_name]').dispatchEvent(new Event('input', { bubbles: true }));
  });
  await modal.locator('#lab-driver-form [name="snils"]').fill('123-456-789 00');
  await modal.locator('#lab-driver-form [name="extra_phones[]"]').fill('+7 900 000-00-00');
  await page.waitForTimeout(50);
  await modal.locator('[data-lab-send]').click();
  await dialog.waitFor({ state: 'visible' });
  assert.match(await dialog.locator('[data-lab-review-summary]').innerText(), /info@plan-ex\.ru/);
  assert.match(await dialog.locator('[data-lab-email-subject]').innerText(), /#123 Вывоз отходов/);
  assert.match(await dialog.locator('[data-lab-email-fields]').innerText(), /#123 Вывоз отходов/);
  assert.match(await dialog.locator('[data-lab-email-fields]').innerText(), /Водитель: Доп контакты/);
  assert.match(await dialog.locator('[data-lab-max-text]').textContent(), /🚚 Внесены частичные данные/);
  assert.match(await dialog.locator('[data-lab-max-text]').textContent(), /\*\*#123 Вывоз отходов\*\*/);
  assert.match(await dialog.locator('[data-lab-max-text]').textContent(), /Не хватает для полного комплекта/);
  assert.doesNotMatch(await dialog.locator('[data-lab-max-text]').textContent(), /Готово:|Отправил:|Подрядчик: —/);
  await dialog.locator('[data-lab-review-confirm]').click();
  await page.waitForTimeout(300);
  assert.equal(sends.length, 5);
  assert.equal(sends[0].csrf, 'test-csrf-token');
  assert.match(sends[0].body, /name="action"\r?\n\r?\nsave/);
  assert.match(sends[4].body, /name="revision"\r?\n\r?\n3/);
  assert.equal(await modal.locator('[data-lab-workflow-status]').count(), 0);
  assert.equal(await dialog.evaluate(node => node.open), false, 'Partial send closes confirmation');
  assert.equal(await modal.evaluate(node => node.classList.contains('is-open')), false, 'Partial send closes form');
  await page.evaluate(() => window.openModal('route-executor-create-modal'));
  assert.equal(await modal.locator('[data-lab-send]').isVisible(), false);
  await modal.locator('#lab-contractor-form [name="name"]').fill('ИП Тестовый');
  await modal.locator('#lab-contractor-form [name="inn"]').fill('123456789012');
  await modal.locator('#lab-contractor-form [name="contractor_type"]').selectOption('individual');
  await modal.locator('#lab-contractor-form [name="ogrn"]').fill('123456789012345');
  await modal.locator('#lab-contractor-form [name="director_full_name"]').fill('Тестовый Тест Тестович');
  await modal.locator('#lab-contractor-form [name="director_position"]').fill('ИП');
  await modal.locator('#lab-contractor-form [name="legal_address"]').fill('Москва');
  await modal.locator('#lab-driver-form').evaluate(form => form.insertAdjacentHTML('beforeend', '<input name="passport_number"><input name="passport_issued_by"><input name="passport_issue_date"><input name="license_number"><input name="license_issue_date"><input name="email"><input type="file" name="predef_doc[passport][]"><input type="file" name="predef_doc[driver_license][]">'));
  for (const name of ['passport_number', 'passport_issued_by', 'passport_issue_date', 'license_number', 'license_issue_date', 'email'])
    await modal.locator(`#lab-driver-form [name="${name}"]`).fill('Заполнено');
  await modal.locator('#lab-vehicle-form').evaluate(form => form.insertAdjacentHTML('beforeend', '<div class="vehicle-set-unit-panel" data-unit-role="primary"><span data-unit-title>Тягач</span><input name="units[primary][brand]"><input name="units[primary][plate_number]"><input name="units[primary][vin]"><input name="units[primary][capacity_tons]"><input name="units[primary][volume_m3]"></div><input type="file" name="predef_doc[primary][sts][]"><input type="file" name="predef_doc[primary][photo][]">'));
  await modal.locator('#lab-vehicle-form [name="set_type"]').selectOption('single');
  for (const name of ['brand', 'plate_number', 'vin', 'capacity_tons', 'volume_m3'])
    await modal.locator(`#lab-vehicle-form [name="units[primary][${name}]"]`).fill('Заполнено');
  const testFile = { name: 'scan.pdf', mimeType: 'application/pdf', buffer: Buffer.from('scan') };
  for (const name of ['predef_doc[passport][]', 'predef_doc[driver_license][]'])
    await modal.locator(`#lab-driver-form [name="${name}"]`).setInputFiles(testFile);
  for (const name of ['predef_doc[primary][sts][]', 'predef_doc[primary][photo][]'])
    await modal.locator(`#lab-vehicle-form [name="${name}"]`).setInputFiles(testFile);
  assert.equal(await modal.locator('[data-lab-send]').isVisible(), false, 'Full send requires the driver phone');
  await modal.locator('#lab-driver-form [name="phone"]').fill('+7 900 000-00-00');
  await modal.locator('[data-lab-send]').click();
  await dialog.waitFor({ state: 'visible' });
  rejectNextFinal = true;
  await dialog.locator('[data-lab-review-confirm]').click();
  await page.waitForTimeout(100);
  assert.equal(await dialog.evaluate(node => node.open), true, 'Rejected send leaves confirmation visible');
  assert.match(await dialog.locator('.lab-review-foot [data-lab-review-result].is-error').innerText(), /не хватает: пример/, 'Server refusal is shown prominently as an error');
  await dialog.locator('[data-lab-review-confirm]').click();
  await dialog.waitFor({ state: 'hidden' });
  assert.equal(await modal.evaluate(node => node.classList.contains('is-open')), false, 'Full send closes the main popup');
  assert.equal(await page.locator('.route-review-row-final').count(), 1, 'Full card has normal row style');
  assert.equal(await page.locator('.route-review-row-final').evaluate(node => node.hasAttribute('data-erp-pin')), false, 'Full card is no longer pinned');
  assert.equal(await page.locator('.route-review-row-final').evaluate(node => getComputedStyle(node).backgroundColor), 'rgba(0, 0, 0, 0)');
  await page.evaluate(() => { window.openCombinedRouteExecutorCreate = () => { window.__combinedOpened = true; }; });
  await page.locator('[data-route-review-card-id] td').first().dblclick();
  assert.equal(await page.evaluate(() => window.__combinedOpened), true, 'Double click opens combined editor');
  assert.equal(await page.locator('[data-route-review-resend]').count(), 0, 'Repeat email action is removed');
  page.on('dialog', dialog => dialog.accept());
  await page.locator('[data-route-review-delete]').click();
  await page.waitForTimeout(250);
  assert.match(sends.at(-1).body, /name="action"\r?\n\r?\ndelete/);
  assert.deepEqual(errors, [], 'Browser script errors');
  console.log('route_executor_review_browser_test: PASS');
} finally {
  await browser.close();
}
