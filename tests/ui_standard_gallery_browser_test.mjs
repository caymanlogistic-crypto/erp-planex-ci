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
    await page.evaluate(() => window.scrollTo(0, document.documentElement.scrollHeight));
    assert.equal(await page.evaluate(() => window.scrollY > 0), true, `${width}px scroll reaches lower content`);
    const ordinary = page.locator('[id="registry.checkbox-group"] .registry-ui-checkboxes:not(.registry-ui-checkboxes--trip-status) input').nth(1);
    await ordinary.focus();
    await page.keyboard.press('Space');
    assert.equal(await ordinary.isChecked(), true);
    await page.locator('[id="registry.select"] .lab-reference-trigger').click();
    assert.equal(await page.locator('[id="registry.select"] .lab-reference-list').isVisible(), true);
    await page.keyboard.press('ArrowDown');
    await page.keyboard.press('Enter');
    assert.equal(await page.locator('[id="registry.select"] select').inputValue(), 'name');
    await page.close();
  }
  console.log('UI_STANDARD_GALLERY_BROWSER=PASS');
} finally {
  await browser.close();
}
