import assert from 'node:assert/strict';
import path from 'node:path';
import { createRequire } from 'node:module';
import { fileURLToPath, pathToFileURL } from 'node:url';

const require = createRequire(import.meta.url);
const { chromium } = require('playwright');
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const gallery = pathToFileURL(path.join(root, 'docs/ui/approved-components.html')).href;
const browser = await chromium.launch({ headless: true });
try {
  for (const width of [390, 1280, 1920]) {
    const page = await browser.newPage({ viewport: { width, height: 900 } });
    await page.goto(gallery);
    await page.locator('.gallery-item').first().waitFor();
    assert.equal(await page.locator('.gallery-item').count(), 11);
    const state = await page.evaluate(() => {
      const head = document.querySelector('[id="registry.page-head"] .registry-ui-head');
      const toolbar = document.querySelector('[id="registry.toolbar"] .registry-ui-toolbar');
      const thead = document.querySelector('[id="registry.finance-table"] thead th');
      const select = document.querySelector('[id="registry.select"] .lab-reference-select');
      return {
        headHeight: Math.round(head.getBoundingClientRect().height),
        toolbarMinHeight: getComputedStyle(toolbar).minHeight,
        financeHead: getComputedStyle(thead).backgroundColor,
        selectReady: !!select && select.classList.contains('is-ready'),
        pageOverflow: document.documentElement.scrollWidth > innerWidth + 1,
      };
    });
    assert.equal(state.headHeight >= 58, true, `${width}px head height`);
    assert.equal(state.toolbarMinHeight, '49px');
    assert.equal(state.financeHead, 'rgb(208, 204, 196)');
    assert.equal(state.selectReady, true);
    assert.equal(state.pageOverflow, false, `${width}px horizontal overflow`);
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
