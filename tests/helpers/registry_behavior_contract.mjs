import assert from 'node:assert/strict';

// Expectations come from the domain fixture contract, never from the rendered DOM.
// cells is an ordered array of arrays: each entry lists the preserved values in a cell.
export async function assertRegistryRows(page, {rowSelector, rows, forbidden = []}, label) {
  const normalize = value => value.replace(/\s+/g, ' ').trim();
  const actual = await page.locator(rowSelector).evaluateAll(items => items.map(row => ({
    id: row.dataset.contractorId || row.dataset.vehicleSetId || row.dataset.driverId || row.dataset.placementBlockId || row.dataset.registryRowId,
    cells: [...row.cells].map(cell => {
      const fields=[...cell.querySelectorAll(':scope > span')];
      return (fields.length?fields:[cell]).map(field=>field.textContent.replace(/\s+/g,' ').trim());
    }),
  })));
  assert.deepEqual(actual.map(row => row.id).sort(), rows.map(row => String(row.id)).sort(), `${label}: authorized row IDs`);
  for (const expected of rows) {
    const row = actual.find(row => row.id === String(expected.id));
    assert.equal(row.cells.length, expected.cells.length, `${label} ${expected.id}: preserved columns`);
    expected.cells.forEach((values, column) => assert.deepEqual(row.cells[column],values.map(normalize),`${label} ${expected.id}: missing or changed field in column ${column}`));
  }
  const html = await page.content();
  for (const value of forbidden) assert.ok(!html.includes(value), `${label}: forbidden value leaked into HTML/index: ${value}`);
}

export async function assertRegistrySearch(page, {rowSelector, probes, resolveActual}, label) {
  for (const {query, ids} of probes) {
    await page.locator('.registry-ui-search').fill(query);
    const actual = resolveActual
      ? await resolveActual(page, query)
      : await page.locator(rowSelector + ':not(.is-hidden)').evaluateAll(items => items.map(row => row.dataset.contractorId || row.dataset.vehicleSetId || row.dataset.driverId).sort());
    assert.deepEqual(actual, ids.map(String).sort(), `${label}: actual filtered IDs for ${query}`);
  }
  await page.locator('.registry-ui-search').fill('');
}

// Fixture must contain enough rows. Never clone DOM rows here: missing data must fail.
export async function assertRegistryScroll(page, label) {
  const measure = () => page.evaluate(() => {
    const main = document.querySelector('main.content'), card = document.querySelector('.registry-ui-card');
    const toolbar = card.querySelector('.registry-ui-toolbar'), table = card.querySelector(':scope > .table-scroll table'), head = table.tHead;
    const box = element => element.getBoundingClientRect();
    // Sidebar navigation has its own unrelated scroll area; inspect the content chain.
    const yOwners = [document.documentElement,document.body,main,...main.querySelectorAll('*')].filter(element => ['auto','scroll'].includes(getComputedStyle(element).overflowY) && element.scrollHeight > element.clientHeight + 1);
    return {max:main.scrollHeight-main.clientHeight, y:main.scrollTop, owners:yOwners.map(element=>element===main?'main':element.className),
      cards:getComputedStyle(table.tBodies[0].rows[0]).display==='grid'||getComputedStyle(head).display==='none',
      mainTop:box(main).top, toolbarTop:box(toolbar).top, toolbarBottom:box(toolbar).bottom, headTop:box(head).top,
      headCells:[...head.rows[0].cells].map(cell=>box(cell).left), rowCells:[...table.tBodies[0].rows[0].cells].map(cell=>box(cell).left),
      pageX:document.documentElement.scrollWidth-innerWidth};
  });
  const initial = await measure();
  assert.ok(initial.max > 200, `${label}: fixture must exercise real vertical scrolling`);
  for (const y of [Math.min(400, initial.max / 2), initial.max]) {
    await page.locator('main.content').evaluate((element,y)=>{element.scrollTop=y}, y);
    await page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));
    const result = await measure();
    assert.ok(result.y > 0, `${label}: main scroll moved`);
    assert.deepEqual(result.owners, ['main'], `${label}: one vertical scroll owner`);
    assert.ok(Math.abs(result.toolbarTop-result.mainTop)<2, `${label}: sticky toolbar`);
    if(!result.cards)assert.ok(Math.abs(result.headTop-result.toolbarBottom)<2, `${label}: sticky column header ${JSON.stringify(result)}`);
    assert.ok(result.pageX<=1, `${label}: no document horizontal overflow`);
  }
  await page.locator('.registry-ui-card > .table-scroll').evaluate(element=>{element.scrollLeft=element.scrollWidth-element.clientWidth});
  const shifted=await measure();
  if(!shifted.cards)shifted.headCells.forEach((left,index)=>assert.ok(Math.abs(left-shifted.rowCells[index])<2, `${label}: header/body X alignment column ${index}`));
  await page.locator('.registry-ui-card > .table-scroll').evaluate(element=>{element.scrollLeft=0});
  await page.locator('main.content').evaluate(element=>{element.scrollTop=0});
}
