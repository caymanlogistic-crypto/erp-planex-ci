import assert from 'node:assert/strict';

export async function assertRegistryVisualContract(page, label) {
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
  return metrics;
}
