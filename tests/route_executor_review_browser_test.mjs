import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const require = createRequire(import.meta.url);
const { chromium } = require('playwright');
const root = resolve(import.meta.dirname, '..');
const script = readFileSync(resolve(root, 'public/assets/js/route-executor-review-workflow.js'), 'utf8');
const css = readFileSync(resolve(root, 'public/assets/css/route-executor-review-workflow.css'), 'utf8');
const browser = await chromium.launch({ headless: true });
try {
  const context = await browser.newContext({ viewport: { width: 1366, height: 800 } });
  const page = await context.newPage();
  const errors = [];
  const sends = [];
  page.on('pageerror', error => errors.push(error.message));
  await page.route('http://127.0.0.1:59999/**', route => {
    const path = new URL(route.request().url()).pathname;
    if (path.endsWith('/review/status')) return route.fulfill({ json: { ok: true, stage: 'new' } });
    if (path.endsWith('/review/config')) return route.fulfill({ json: { ok: true, sender: 'sender@example.test', author: 'Отправитель', recipients: ['info@plan-ex.ru'], send_responsible_logist: false,
      max_events: { 'route_executor.partial': { enabled: true, chat_config_id: 1, template: 'Первичные данные: {{driver}}. Ожидайте полный комплект вторым письмом.' } } } });
    if (path.endsWith('/review/send')) {
      sends.push({ body: route.request().postData(), csrf: route.request().headers()['x-csrf-token'] });
      return route.fulfill({ json: { ok: true, stage: 'partial', mail_status: 'accepted', max_status: 'sent' } });
    }
    return route.fulfill({ status: 200, contentType: 'text/html', body: '<!doctype html><html><body></body></html>' });
  });
  await page.goto('http://127.0.0.1:59999/');
  await page.setContent(`<meta name="csrf-token" content="test-csrf-token"><style>${css}</style>
    <button data-source-create-open>Открыть</button>
    <div id="route-executor-create-modal" data-workflow-scope="7-11">
      <div id="route-executor-create-modal-inner">
        <div class="modal-head"><span class="modal-title">Создать исполнителя рейса</span>
          <div data-route-readiness>
            <span class="route-readiness-item" data-readiness-key="contract" data-state="pending">Достаточно для подписания договора</span>
            <span class="route-readiness-item" data-readiness-key="training" data-state="pending">Для отправки на обучение</span>
            <span class="route-readiness-item" data-readiness-key="vehicle" data-state="pending">Для регистрации транспорта</span>
          </div>
        </div>
        <div id="route-executor-create-modal-content">
          <select id="route-executor-responsible-logist"><option value="">—</option><option value="12">Иван</option></select>
          <form id="lab-contractor-form"><div class="entity-form-main"><div class="field"><label class="field-label">Наименование</label><input name="name"></div><input name="inn"><select name="contractor_type"><option value="">—</option><option value="individual">ИП</option></select><input name="kpp"><input name="ogrn"><input name="director_full_name"><input name="director_position"><textarea name="legal_address"></textarea></div></form>
          <form id="lab-driver-form"><input name="full_name"><input name="snils"><input name="phone"></form>
          <form id="lab-vehicle-form"><select name="set_type"><option value="">—</option><option value="single">Одиночка</option></select></form>
        </div>
        <div class="modal-required-note"></div>
        <div class="modal-foot-actions"><button data-source-create-submit>Создать исполнителя рейса</button></div>
      </div>
    </div>`);
  await page.addScriptTag({ content: script });
  if (errors.length) throw new Error('Browser script errors: ' + errors.join(' | '));
  const modal = page.locator('#route-executor-create-modal');
  await modal.locator('[data-source-create-submit]').waitFor();
  assert.equal(await modal.locator('[data-source-create-submit]').textContent(), 'Сохранить черновик');
  await modal.locator('[data-readiness-key="contract"]').click();
  assert.match(await modal.locator('#lab-readiness-details').innerText(), /Поля[\s\S]*Обязательные файлы/);
  await modal.locator('#lab-driver-form [name="full_name"]').fill('Тестовый Водитель Тестович');
  await modal.locator('[data-source-create-submit]').click();
  await page.waitForTimeout(300);
  assert.match(await modal.locator('[data-lab-workflow-message]').innerText(), /Черновик сохранён/);
  await modal.locator('#lab-driver-form [name="snils"]').fill('123-456-789 00');
  await modal.locator('#route-executor-responsible-logist').selectOption('12');
  await modal.locator('[data-lab-send]').click();
  const dialog = page.locator('#lab-route-send-dialog');
  await dialog.waitFor({ state: 'visible' });
  assert.match(await dialog.locator('[data-lab-review-summary]').innerText(), /info@plan-ex\.ru/);
  assert.doesNotMatch(await dialog.locator('[data-lab-email-subject]').innerText(), /№|LAB-001/);
  assert.match(await dialog.locator('[data-lab-max-text]').textContent(), /Ожидайте полный комплект вторым письмом/);
  await dialog.locator('[data-lab-review-confirm]').click();
  await page.waitForTimeout(300);
  assert.equal(sends.length, 1);
  assert.equal(sends[0].csrf, 'test-csrf-token');
  assert.match(await modal.locator('[data-lab-workflow-message]').innerText(), /Первичные данные отправлены/);
  assert.equal(await modal.locator('[data-lab-send]').isVisible(), false);
  assert.deepEqual(errors, [], 'Browser script errors');
  console.log('route_executor_review_browser_test: PASS');
} finally {
  await browser.close();
}
