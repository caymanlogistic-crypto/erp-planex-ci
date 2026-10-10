import assert from 'node:assert/strict';
import {createRequire} from 'node:module';
import {readFileSync} from 'node:fs';
import {dirname, join} from 'node:path';
import {fileURLToPath} from 'node:url';

const require = createRequire(import.meta.url);
const {chromium} = require(process.env.PLANEX_PLAYWRIGHT_MODULE || 'playwright');
const root = dirname(dirname(fileURLToPath(import.meta.url)));
const view = readFileSync(join(root, 'app/View/pages/company_document_generator_history.php'), 'utf8');
const script = view.match(/<script>\s*([\s\S]*?)\s*<\/script>/)?.[1] || '';
const viewStyle = view.match(/<style>\s*([\s\S]*?)\s*<\/style>/)?.[1] || '';
assert(script.includes('data-document-select'), 'history selection script must be present');
const css = ['erp-ui.css', 'app-base.css', 'focus-trace-reset.css']
  .map(file => readFileSync(join(root, 'public/assets/css', file), 'utf8')).join('\n');
const rows = Array.from({length: 100}, (_, index) => `<tr><td class="text-center"><label class="checkbox-item docgen-history-check"><input type="checkbox" name="document_ids[]" value="${index + 1}" data-document-select aria-label="Выбрать документ #${index + 1}"><span class="checkbox-mark" aria-hidden="true"></span></label></td><td>#${index + 1}</td><td>Документ ${index + 1}</td><td>Исполнитель</td><td>1 · 1000 кг</td><td>2026-10-10</td><td>DOCX</td></tr>`).join('');
const html = `<style>${css}\n${viewStyle}</style>
<div class="app"><div class="topbar">ERP PLANEX</div><aside class="sidebar">Ключи и сервисы</aside><main class="content"><div class="workspace-shell-1690">
<div class="page-head"><h1 class="page-title">История сформированных документов</h1></div>
<form method="post" data-document-archive-form><div class="table-card table-card--standard" data-erp-grid><div class="table-toolbar"><span class="found-label">Найдено: <b>100</b></span><div class="toolbar-right"><span data-document-selected-count>Выбрано: 0</span><button type="submit" data-document-archive-button>Скачать выбранные (ZIP)</button></div></div><div class="table-scroll"><table class="table"><thead><tr><th class="col-check-width text-center"><label class="checkbox-item docgen-history-check"><input type="checkbox" data-document-select-all aria-label="Выбрать все показанные документы"><span class="checkbox-mark" aria-hidden="true"></span></label></th><th>ID</th><th>Документ</th><th>Исполнитель</th><th>Заявки / масса</th><th>Создан</th><th></th></tr></thead><tbody>${rows}</tbody></table></div><div class="table-footer">Показано 1–100 из 100</div></div></form></div></main></div><script>${script}</script>`;
const browser = await chromium.launch({headless:true, ...(process.env.PLANEX_CHROMIUM_PATH ? {executablePath:process.env.PLANEX_CHROMIUM_PATH} : {})});
try {
  const page = await browser.newPage({viewport: {width: 1800, height: 800}});
  await page.setContent(html);
  const state = () => page.evaluate(() => ({
    windowY: window.scrollY,
    contentY: document.querySelector('.content').scrollTop,
    tableY: document.querySelector('.table-scroll').scrollTop,
    appBottom: document.querySelector('.app').getBoundingClientRect().bottom,
    selected: document.querySelector('[data-document-selected-count]').textContent,
  }));
  const initial = await state();
  assert.equal(initial.windowY, 0);
  assert.equal(initial.appBottom, 800);
  for (const index of [0, 20, 60, 99]) {
    await page.locator('.docgen-history-check').nth(index + 1).click();
    const current = await state();
    assert.equal(current.windowY, 0, `selecting row ${index + 1} must not scroll the document`);
    assert.equal(current.appBottom, 800, `app shell must remain in the viewport after row ${index + 1}`);
    assert.equal(current.selected, `Выбрано: ${[0, 20, 60, 99].indexOf(index) + 1}`);
  }
  assert.deepEqual(await page.locator('form').evaluate(form => new FormData(form).getAll('document_ids[]')), ['1', '21', '61', '100']);
  await page.locator('.docgen-history-check').first().click();
  assert.equal((await state()).windowY, 0);
  assert.equal((await state()).selected, 'Выбрано: 100');
  assert.equal(await page.locator('[data-document-select]:checked').count(), 100);
  await page.locator('.docgen-history-check').first().click();
  assert.equal((await state()).selected, 'Выбрано: 0');
  assert.equal(await page.locator('[data-document-archive-button]').isDisabled(), true);
  const last = page.locator('[data-document-select]').last();
  await last.focus();
  await last.press('Space');
  assert.equal((await state()).windowY, 0);
  assert.equal((await state()).selected, 'Выбрано: 1');
  assert.equal(await page.locator('[data-document-archive-button]').isEnabled(), true);
  console.log('DOCUMENT_GENERATOR_HISTORY_SELECTION_BROWSER=PASS');
} finally {
  await browser.close();
}
