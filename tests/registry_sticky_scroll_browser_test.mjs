import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {createRequire} from 'node:module';
import {execFileSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';

const require = createRequire(import.meta.url);
const {chromium} = require('playwright');
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const css = file => fs.readFileSync(path.join(root, 'public/assets/css', file), 'utf8');
const fontCss = [[400,'Regular'],[500,'Medium'],[600,'SemiBold'],[700,'Bold']].map(([weight,name]) => `@font-face{font-family:"IBM Plex Sans";src:url(data:font/woff2;base64,${fs.readFileSync(path.join(root,`public/assets/fonts/IBMPlexSans-${name}.woff2`)).toString('base64')}) format("woff2");font-weight:${weight}}`).join('\n');
const sharedCss = css('app-base.css') + '\n' + css('workspace-benchmark.css') + '\n' + css('app.css') + '\n' + css('erp-ui.css') + '\n' + fontCss + '\n.modal-overlay{display:none}.modal-overlay.is-open{display:flex}';
const cases = [
  {name:'finance', fixture:'finance_received_paid_filter_render.php', stylesheet:'finance-received-paid.css', toolbar:'.rp-ledger-v015 > .table-toolbar', table:'.rp-v010-table', cardAt:1051},
  {name:'departures', fixture:'waste_trips_design_lab_render.php', stylesheet:'waste-trip-registry.css', toolbar:'.table-card[data-erp-grid] > .waste-trip-toolbar', table:'.waste-registry', cardAt:641},
];
const evidenceDir = process.env.PLANEX_VISUAL_EVIDENCE_DIR;
const positions = [];
const browser = await chromium.launch({headless:true, ...(process.env.PLANEX_BROWSER_EXECUTABLE ? {executablePath:process.env.PLANEX_BROWSER_EXECUTABLE} : {})});
try {
  for (const entry of cases) {
    const html = execFileSync(process.env.PLANEX_PHP || 'php', [path.join(root, 'tests/fixtures', entry.fixture)], {encoding:'utf8', maxBuffer:8*1024*1024});
    for (const [width, height] of [[1920,600],[1280,600],[1100,600],[1051,600],[1035,600],[1034,600],[1033,600],[1032,600],[1024,600],[900,600],[701,600],[700,600],[641,600],[390,600],[320,600]]) {
      const page = await browser.newPage({viewport:{width,height}});
      await page.setContent(html);
      await page.addStyleTag({content:sharedCss + '\n' + css(entry.stylesheet) + '\n' + css('registry-components.css')});
      await page.addScriptTag({content:fs.readFileSync(path.join(root, 'public/assets/js/registry-components.js'), 'utf8')});
      await page.waitForFunction(() => !!document.querySelector('.registry-ui-sticky-table')?.style.getPropertyValue('--registry-toolbar-height'));
      if (entry.name === 'finance') {
        await page.evaluate(() => {
          const body = document.querySelector('.rp-v010-table tbody');
          const originals = [...body.children];
          while (body.children.length < 20) for (const row of originals) body.append(row.cloneNode(true));
          document.querySelector('#rp-count').textContent = String(body.children.length);
          document.querySelector('#rp-hide-complete').checked = true;
        });
      }
      if (evidenceDir && [1920,390].includes(width)) {
        fs.mkdirSync(evidenceDir, {recursive:true});
        await page.screenshot({path:path.join(evidenceDir, `${entry.name}-top-${width}.png`)});
      }
      const geometry = await page.evaluate(async ({toolbar, table}) => {
        const main = document.querySelector('main.content');
        const control = document.querySelector(toolbar);
        const grid = document.querySelector(table);
        const header = grid.querySelector('thead');
        const pageHead = document.querySelector('.page-head');
        const before = {toolbar:control.getBoundingClientRect().top, header:header.getBoundingClientRect().top};
        main.scrollTop = 500;
        await new Promise(requestAnimationFrame);
        await new Promise(requestAnimationFrame);
        const rect = element => element.getBoundingClientRect();
        const result = {
          max:main.scrollHeight-main.clientHeight,
          mainTop:rect(main).top,
          topbarBottom:rect(document.querySelector('.topbar')).bottom,
          scroll:main.scrollTop,
          before,
          toolbarTop:rect(control).top,
          toolbarBottom:rect(control).bottom,
          headerTop:rect(header).top,
          headerDisplay:getComputedStyle(header).display,
          headerPosition:getComputedStyle(header).position,
          headerClip:getComputedStyle(header).clip,
          pageHeadTop:rect(pageHead).top,
          footerTop:grid.querySelector('tfoot') ? rect(grid.querySelector('tfoot')).top : null,
          horizontalOverflow:document.documentElement.scrollWidth-innerWidth,
        };
        main.scrollTop = main.scrollHeight-main.clientHeight;
        result.atMax = {scroll:main.scrollTop, toolbarTop:rect(control).top, headerTop:rect(header).top};
        if (grid.tFoot) {
          const lastCell = grid.tBodies[0].lastElementChild.lastElementChild;
          const firstFoot = grid.tFoot.querySelector('td');
          result.footerBoundary = {lastBorder:getComputedStyle(lastCell).borderBottom,lastRowBorder:getComputedStyle(grid.tBodies[0].lastElementChild).borderBottom,footerBorder:getComputedStyle(grid.tFoot).borderTop,firstBorder:getComputedStyle(firstFoot).borderTop,lastBottom:rect(lastCell).bottom,firstTop:rect(firstFoot).top};
        }
        main.scrollTop = 500;
        return result;
      }, entry);
      assert.ok(geometry.max >= 500, `${entry.name} ${width}: fixture must scroll 500px`);
      assert.equal(geometry.scroll, 500, `${entry.name} ${width}: main scrolls`);
      assert.ok(Math.abs(geometry.toolbarTop-38) < 2, `${entry.name} ${width}: toolbar stays below app bar`);
      assert.ok(geometry.pageHeadTop < 0, `${entry.name} ${width}: page header scrolls away`);
      assert.ok(geometry.horizontalOverflow <= 1, `${entry.name} ${width}: no page horizontal scroll`);
      assert.ok(Math.abs(geometry.atMax.toolbarTop-38) < 2, `${entry.name} ${width}: toolbar stays at maximum scroll`);
      if (width >= entry.cardAt) {
        assert.ok(Math.abs(geometry.headerTop-geometry.toolbarBottom) < 2, `${entry.name} ${width}: header touches toolbar`);
      } else if (width < entry.cardAt) {
        assert.ok(geometry.headerDisplay === 'none' || geometry.headerPosition === 'absolute' && geometry.headerClip !== 'auto', `${entry.name} ${width}: card view hides column header`);
      }
      if (entry.name === 'finance') {
        assert.ok(geometry.footerTop > geometry.toolbarBottom, `${entry.name} ${width}: footer stays with table`);
        assert.ok(geometry.footerBoundary.footerBorder.startsWith('1px solid'), `${entry.name} ${width}: one footer separator`);
        assert.ok(geometry.footerBoundary.lastBorder.startsWith('0px'), `${entry.name} ${width}: no duplicate cell border`);
        if (width === 1920) {
          const filteredLastBorder = await page.evaluate(() => {
            const rows = [...document.querySelectorAll('.rp-v010-table tbody tr')];
            rows.slice(-7).forEach(row => { row.hidden = true; });
            const lastVisible = rows.findLast(row => !row.hidden);
            return getComputedStyle(lastVisible.querySelector('td')).borderBottomWidth;
          });
          assert.equal(filteredLastBorder, '0px', 'filtered last visible row has no duplicate border above totals');
        }
      }
      positions.push({page:entry.name, width, ...geometry});
      if (evidenceDir) {
        fs.mkdirSync(evidenceDir, {recursive:true});
        await page.screenshot({path:path.join(evidenceDir, `${entry.name}-${width}.png`)});
        if (entry.name === 'finance' && [1920,1280,390].includes(width)) {
          await page.evaluate(() => {const main=document.querySelector('main.content');main.scrollTop=main.scrollHeight-main.clientHeight;});
          await page.screenshot({path:path.join(evidenceDir, `finance-footer-${width}.png`)});
        }
      }
      await page.close();
    }
  }
  if (evidenceDir) fs.writeFileSync(path.join(evidenceDir, 'positions.json'), JSON.stringify(positions, null, 2));
  console.log('REGISTRY_STICKY_SCROLL_BROWSER=PASS');
} finally {
  await browser.close();
}
