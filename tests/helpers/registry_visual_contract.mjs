import assert from 'node:assert/strict';

export async function assertRegistryVisualContract(page, label) {
  await page.evaluate(async () => {
    await Promise.allSettled([400,500,600,700].map(weight=>document.fonts.load(`${weight} 12px "IBM Plex Sans"`)));
    await document.fonts.ready;
  });
  const metrics = await page.evaluate(() => {
    const card = document.querySelector('.registry-ui-card');
    const toolbar = card?.querySelector('.registry-ui-toolbar');
    const table = card?.querySelector('.registry-ui-table');
    const box = element => element?.getBoundingClientRect();
    return {
      viewport: document.documentElement.clientWidth,
      pageOverflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
      card: box(card)?.toJSON(),
      toolbar: box(toolbar)?.toJSON(),
      table: box(table)?.toJSON(),
      toolbarMarginBottom: toolbar ? getComputedStyle(toolbar).marginBottom : null,
    };
  });
  const context = `${label}: ${JSON.stringify(metrics)}`;
  assert.ok(metrics.card && metrics.toolbar && metrics.table, `missing shared registry structure: ${context}`);
  assert.ok(metrics.pageOverflow <= 1, `page horizontal overflow: ${context}`);
  assert.ok(Math.abs(metrics.toolbar.bottom - metrics.table.top) <= 1, `gap or overlap between toolbar and table: ${context}`);
  assert.equal(metrics.toolbarMarginBottom, '0px', `toolbar external margin: ${context}`);
  assert.ok(metrics.toolbar.left >= metrics.card.left - 1 && metrics.toolbar.right <= metrics.card.right + 1, `toolbar outside card: ${context}`);
  const appearance = await page.evaluate(() => {
    const style = selector => {
      const element = document.querySelector(selector);
      if (!element) return null;
      const css = getComputedStyle(element);
      return Object.fromEntries(['fontSize','fontWeight','lineHeight','color','backgroundColor','borderTopColor','borderTopWidth','borderRadius','minHeight','paddingTop','paddingRight','height'].map(key => [key, css[key]]));
    };
    const fontLoaded = [400,500,600,700].every(weight => [...document.fonts].some(face => face.family.replace(/["']/g,'')==='IBM Plex Sans' && face.weight===String(weight) && face.status==='loaded'));
    return {toolbar:style('.registry-ui-toolbar'),search:style('.registry-ui-search'),title:style('.registry-ui-head .page-title'),summary:style('.registry-ui-head .page-summary'),head:style('.registry-ui-head'),fontLoaded};
  });
  assert.equal(appearance.fontLoaded, true, `${label}: real approved font is loaded`);
  assert.equal(appearance.toolbar.backgroundColor, 'rgb(247, 245, 240)', `${label}: toolbar background`);
  assert.equal(appearance.toolbar.minHeight, '49px', `${label}: toolbar min height`);
  assert.deepEqual([appearance.toolbar.paddingTop,appearance.toolbar.paddingRight], ['8px','12px'], `${label}: toolbar padding`);
  assert.deepEqual([appearance.title?.fontSize,appearance.title?.fontWeight,appearance.title?.lineHeight,appearance.title?.color], ['19px','700','22px','rgb(19, 18, 16)'], `${label}: title typography`);
  assert.deepEqual([appearance.summary?.fontSize,appearance.summary?.fontWeight,appearance.summary?.lineHeight], ['12px','500','15px'], `${label}: summary typography`);
  assert.deepEqual([appearance.head?.borderTopColor,appearance.head?.borderTopWidth], ['rgb(168, 161, 150)','1px'], `${label}: header frame`);
  assert.deepEqual([appearance.search?.height,appearance.search?.fontSize,appearance.search?.fontWeight,appearance.search?.borderRadius,appearance.search?.backgroundColor], ['32px','12px','500','2px','rgb(255, 253, 249)'], `${label}: search appearance`);
  const dataRoles = await page.evaluate(() => [...document.querySelectorAll('.registry-ui-data .registry-ui-name,.registry-ui-data .registry-ui-secondary,.registry-ui-data tbody .registry-ui-money,.registry-ui-data tbody .registry-ui-id')].map(element=>{
    const css=getComputedStyle(element);
    return {role:element.classList.contains('registry-ui-id')?'id':element.classList.contains('registry-ui-money')?'money':element.classList.contains('registry-ui-name')?'name':'secondary',size:css.fontSize,weight:css.fontWeight,line:css.lineHeight};
  }));
  for(const actual of dataRoles){
    const expected={id:['12px','700','16.8px'],money:['12px','600','16.8px'],name:['12px','600','16.8px'],secondary:['11px','500','15.4px']}[actual.role];
    assert.deepEqual([actual.size,actual.weight,actual.line],expected,`${label}: canonical ${actual.role}`);
  }
  return metrics;
}
