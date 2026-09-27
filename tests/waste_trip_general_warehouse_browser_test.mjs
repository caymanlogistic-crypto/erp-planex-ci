import {createRequire} from 'node:module';
import {mkdtempSync, readFileSync, rmSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {join, dirname} from 'node:path';
import {fileURLToPath} from 'node:url';
import {spawnSync} from 'node:child_process';

const require = createRequire(import.meta.url);
const {chromium} = require(process.env.PLANEX_PLAYWRIGHT_MODULE || 'playwright');
const root = dirname(dirname(fileURLToPath(import.meta.url)));
const folder = mkdtempSync(join(tmpdir(), 'planex-waste-general-'));
const htmlPath = join(folder, 'registry.html');
const editPath = join(folder, 'edit.html');
let browser;
try {
  const render = spawnSync(process.env.PLANEX_PHP || 'php', ['tests/waste_trip_registry_render_test.php'], {
    cwd: root,
    env: {...process.env, WASTE_REGISTRY_RENDER_OUTPUT: htmlPath, WASTE_WAREHOUSE_EDIT_RENDER_OUTPUT: editPath},
    encoding: 'utf8',
  });
  if (render.status !== 0) throw new Error(render.stderr || render.stdout);
  browser = await chromium.launch({headless: true, ...(process.env.PLANEX_CHROMIUM_PATH ? {executablePath: process.env.PLANEX_CHROMIUM_PATH} : {})});
  const page = await browser.newPage();
  const html = readFileSync(htmlPath, 'utf8');
  const edit = readFileSync(editPath, 'utf8');
  await page.setContent(html.replace('<script>(function(){', `${edit}<script>(function(){`));
  const form = page.locator('#waste-trip-create-modal form');
  const destination = form.locator('[data-waste-destination]');
  const amount = form.locator('[name="general_payments[0][amount]"]');
  const general = form.locator('[data-waste-general-payment-block]');
  const notice = form.locator('[data-waste-general-disabled]');
  if (await amount.isDisabled()) throw new Error('General amount must be enabled for utilizer');
  await destination.selectOption('warehouse');
  if (!await amount.isDisabled() || await general.isVisible() || !await notice.isVisible()) {
    throw new Error('Warehouse destination must disable and hide general payment fields');
  }
  const submitted = await form.evaluate(element => [...new FormData(element).keys()]);
  if (submitted.some(key => key.startsWith('general_payments['))) throw new Error('Disabled general payment must not be submitted');
  await destination.selectOption('utilizer');
  if (await amount.isDisabled() || !await general.isVisible() || await notice.isVisible()) {
    throw new Error('General payment fields must return for utilizer');
  }
  const warehouseEdit = page.locator('#waste-trip-edit-form');
  const editAmount = warehouseEdit.locator('[name="general_payments[0][amount]"]');
  if (!await editAmount.isDisabled() || await warehouseEdit.locator('[data-waste-general-payment-block]').isVisible()
      || !await warehouseEdit.locator('[data-waste-general-disabled]').isVisible()) {
    throw new Error('Existing warehouse trip must keep general payment disabled');
  }
  console.log('WASTE_TRIP_GENERAL_WAREHOUSE_BROWSER=PASS');
} finally {
  if (browser) await browser.close();
  rmSync(folder, {recursive: true, force: true});
}
