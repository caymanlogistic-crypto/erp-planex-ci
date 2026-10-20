import assert from 'node:assert/strict';

export async function assertPlacementSearchPresentation(page){
  const rows=await page.locator('.feo-placement-search-table tbody tr').evaluateAll(items=>items.map(row=>{
    const id=row.querySelector('.registry-ui-inline-identity > .registry-ui-primary');
    const name=row.querySelector('.registry-ui-inline-identity > .registry-ui-name');
    return {linked:row.hasAttribute('data-placement-search-block-id'),identity:!!id,
      sameLine:!name||(!!id&&Math.abs(id.getBoundingClientRect().top-name.getBoundingClientRect().top)<1),
      cells:[...row.cells].map(cell=>({background:getComputedStyle(cell).backgroundColor,color:getComputedStyle(cell).color})),
      warning:row.classList.contains('registry-ui-warning')};
  }));
  assert.ok(rows.length,'populated search fixture required');
  for(const row of rows){
    assert.ok(row.warning,'every search result row uses approved yellow attention role');
    assert.ok(!row.linked||row.identity,'linked block ID and name share the inline identity role');
    assert.ok(row.sameLine,'block ID and name must remain on one line');
  }
  // Scrolling changes hover under the pointer; allow the existing finite color
  // transition to finish, without accepting intermediate or arbitrary colors.
  await page.waitForFunction(()=>[...document.querySelectorAll('.feo-placement-search-table tbody td')].every(cell=>
    ['rgb(253, 240, 213)','rgb(255, 241, 189)'].includes(getComputedStyle(cell).backgroundColor)),null,{timeout:1500});
  for(const color of await page.locator('.feo-placement-search-table tbody td').evaluateAll(cells=>cells.map(cell=>getComputedStyle(cell).color)))
    assert.equal(color,'rgb(19, 18, 16)','yellow row text stays dark');
}
