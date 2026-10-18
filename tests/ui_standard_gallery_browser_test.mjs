import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';
import { fileURLToPath, pathToFileURL } from 'node:url';

const require = createRequire(import.meta.url);
const { chromium } = require('playwright');
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const gallery = pathToFileURL(path.join(root, 'docs/ui/approved-components.html')).href;
const expectedComponents = JSON.parse(fs.readFileSync(path.join(root, 'docs/ui/approved-components.json'), 'utf8')).components.length;
const browser = await chromium.launch({ headless: true });
try {
  for (const width of [390, 1280, 1920]) {
    const page = await browser.newPage({ viewport: { width, height: 900 } });
    await page.goto(gallery);
    await page.locator('.gallery-item').first().waitFor();
    assert.equal(await page.locator('.gallery-item').count(), expectedComponents);
    const state = await page.evaluate(() => {
      const head = document.querySelector('[id="registry.page-head"] .registry-ui-head');
      const toolbar = document.querySelector('[id="registry.toolbar"] .registry-ui-toolbar');
      const thead = document.querySelector('[id="registry.finance-table"] thead th');
      const select = document.querySelector('[id="registry.select"] .lab-reference-select');
      const checkboxes = document.querySelector('[id="registry.checkbox-group"]');
      const accent = selector => getComputedStyle(checkboxes.querySelector(selector)).accentColor;
      return {
        headHeight: Math.round(head.getBoundingClientRect().height),
        toolbarMinHeight: getComputedStyle(toolbar).minHeight,
        financeHead: getComputedStyle(thead).backgroundColor,
        selectReady: !!select && select.classList.contains('is-ready'),
        pageOverflow: document.documentElement.scrollWidth > innerWidth + 1,
        pageCanScroll: document.documentElement.scrollHeight > innerHeight && getComputedStyle(document.body).overflowY !== 'hidden',
        standardAccent: accent('.registry-ui-checkboxes:not(.registry-ui-checkboxes--trip-status) input:checked'),
        plannedAccent: accent('[data-status="planned_route"] input'),
        formedAccent: accent('[data-status="executor_assigned"] input'),
        progressAccent: accent('[data-status="in_progress"] input'),
        completedAccent: accent('[data-status="completed"] input'),
      };
    });
    assert.equal(state.headHeight >= 58, true, `${width}px head height`);
    assert.equal(state.toolbarMinHeight, '49px');
    assert.equal(state.financeHead, 'rgb(208, 204, 196)');
    assert.equal(state.selectReady, true);
    assert.equal(state.pageOverflow, false, `${width}px horizontal overflow`);
    assert.equal(state.pageCanScroll, true, `${width}px vertical scrolling`);
    assert.equal(state.standardAccent, 'rgb(124, 71, 24)');
    assert.equal(state.plannedAccent, 'rgb(156, 39, 176)');
    assert.equal(state.formedAccent, 'rgb(23, 162, 184)');
    assert.equal(state.progressAccent, 'rgb(40, 167, 69)');
    assert.equal(state.completedAccent, 'rgb(108, 117, 125)');
    const sameCheckboxStyle = await page.evaluate(() => {
      const single = document.querySelector('[id="registry.checkbox"] .rp-v014-hide-complete');
      const grouped = document.querySelector('[id="registry.checkbox-group"] .registry-ui-checkbox');
      const labelStyle = element => {
        const style = getComputedStyle(element);
        return [style.minHeight, style.gap, style.color, style.font, style.backgroundColor, style.borderWidth];
      };
      const inputStyle = element => {
        const style = getComputedStyle(element.querySelector('input'));
        return [style.width, style.height, style.accentColor, style.margin];
      };
      return { singleLabel: labelStyle(single), groupedLabel: labelStyle(grouped), singleInput: inputStyle(single), groupedInput: inputStyle(grouped) };
    });
    assert.deepEqual(sameCheckboxStyle.groupedLabel, sameCheckboxStyle.singleLabel, `${width}px checkbox label parity`);
    assert.deepEqual(sameCheckboxStyle.groupedInput, sameCheckboxStyle.singleInput, `${width}px checkbox input parity`);
    const search = page.locator('[id="registry.search"] .registry-ui-search');
    await search.focus();
    const focusedSearch = await search.evaluate(element => {
      const style = getComputedStyle(element);
      return { border: style.borderColor, shadow: style.boxShadow, outline: style.outlineStyle };
    });
    assert.equal(focusedSearch.border, 'rgb(124, 71, 24)', `${width}px focused search border`);
    assert.equal(focusedSearch.shadow, 'rgba(124, 71, 24, 0.1) 0px 0px 0px 2px', `${width}px focused search ring`);
    assert.equal(focusedSearch.outline, 'none', `${width}px focused search outline`);
    const nativeSelect = page.locator('[id="registry.toolbar"] .toolbar-select');
    await nativeSelect.hover();
    const nativeHover = await nativeSelect.evaluate(element => {
      const style = getComputedStyle(element);
      return { background: style.backgroundColor, border: style.borderColor };
    });
    assert.deepEqual(nativeHover, { background: 'rgb(234, 227, 216)', border: 'rgb(201, 189, 174)' }, `${width}px native select hover`);
    const referenceTrigger = page.locator('[id="registry.select"] .lab-reference-trigger');
    await referenceTrigger.hover();
    const referenceHover = await referenceTrigger.evaluate(element => {
      const style = getComputedStyle(element);
      return { background: style.backgroundColor, border: style.borderColor };
    });
    assert.deepEqual(referenceHover, nativeHover, `${width}px select variant hover parity`);
    await page.evaluate(() => window.scrollTo(0, document.documentElement.scrollHeight));
    assert.equal(await page.evaluate(() => window.scrollY > 0), true, `${width}px scroll reaches lower content`);
    const ordinary = page.locator('[id="registry.checkbox-group"] .registry-ui-checkboxes:not(.registry-ui-checkboxes--trip-status) input').nth(1);
    await ordinary.focus();
    await page.keyboard.press('Space');
    assert.equal(await ordinary.isChecked(), true);
    await referenceTrigger.click();
    assert.equal(await page.locator('[id="registry.select"] .lab-reference-list').isVisible(), true);
    const openList = await page.locator('[id="registry.select"] .lab-reference-list').evaluate(element => {
      const style = getComputedStyle(element);
      return { background: style.backgroundColor, border: style.borderColor };
    });
    assert.deepEqual(openList, { background: 'rgb(255, 255, 255)', border: 'rgb(200, 191, 178)' }, `${width}px open select list`);
    const selectedOption = page.locator('[id="registry.select"] .lab-reference-option[aria-selected="true"]');
    assert.equal(await selectedOption.evaluate(element => getComputedStyle(element).backgroundColor), 'rgb(242, 232, 220)', `${width}px selected option`);
    await selectedOption.hover();
    assert.equal(await selectedOption.evaluate(element => getComputedStyle(element).backgroundColor), 'rgb(233, 217, 199)', `${width}px selected option hover`);
    await page.keyboard.press('ArrowDown');
    await page.keyboard.press('Enter');
    assert.equal(await page.locator('[id="registry.select"] select').inputValue(), 'name');
    await page.close();
  }
  console.log('UI_STANDARD_GALLERY_BROWSER=PASS');
} finally {
  await browser.close();
}
