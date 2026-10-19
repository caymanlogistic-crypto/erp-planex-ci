import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {assertRegistryTablerIcon} from './registry_tabler_icon_contract.mjs';

const catalog=JSON.parse(readFileSync(new URL('../../docs/ui/approved-components.json',import.meta.url),'utf8'));
const roleSelectors={primary:'.registry-ui-primary',name:'.registry-ui-name',secondary:'.registry-ui-secondary',plannedDate:'.registry-ui-planned-date',money:'tbody .registry-ui-money',id:'tbody .registry-ui-id',trip:'.registry-ui-trip',missing:'.registry-ui-missing',due:'.registry-ui-due',status:'.registry-ui-status',total:'tfoot td',reason:'.registry-ui-reason'};
const firstFamily=value=>value.split(',')[0].trim().replace(/^["']|["']$/g,'');

// Discover business tables from the registered card, before looking for any
// approved class. An unmarked JS-created table must be visible to this gate.
export async function assertRegistryTableState(page,pageId,state='initial',{runtime=false}={}) {
  const definition=catalog.pages.find(item=>item.id===pageId);
  assert.ok(definition?.table_surfaces?.length,`${pageId}: explicit business-table inventory required`);
  const declared=definition.table_states?.find(item=>item.id===state);
  assert.ok(declared,`${pageId}: undeclared table state ${state}`);
  const observed=await page.evaluate(surfaces=>{
    const visible=element=>{
      const rect=element.getBoundingClientRect(),css=getComputedStyle(element);
      return rect.width>0&&rect.height>0&&css.visibility!=='hidden'&&css.display!=='none';
    };
    const tables=[...document.querySelectorAll('.registry-ui-card table')].filter(visible);
    return tables.map(table=>{
      const owners=surfaces.filter(surface=>table.matches(surface.selector)).map(surface=>surface.id);
      const header=table.tHead?.rows[0]?.cells[0];
      return {owners,classes:table.className,headerBackground:header?getComputedStyle(header).backgroundColor:null,
        rows:[...table.tBodies].flatMap(body=>[...body.rows]).filter(visible).length,
        selector:table.outerHTML.slice(0,180)};
    });
  },definition.table_surfaces);
  for(const table of observed){
    assert.equal(table.owners.length,1,`${pageId}/${state}: visible business table missing or ambiguously mapped in inventory: ${table.selector}`);
    assert.match(table.classes,/\bregistry-ui-table\b/,`${pageId}/${state}: visible business table lacks shared table role`);
    assert.match(table.classes,/\bregistry-ui-data\b/,`${pageId}/${state}: visible business table lacks data role`);
    const approvedHeader=table.classes.includes('registry-ui-table--finance')?'rgb(208, 204, 196)':'rgb(227, 222, 213)';
    assert.equal(table.headerBackground,approvedHeader,`${pageId}/${state}: visible business table header differs from approved canon`);
  }
  assert.deepEqual(observed.map(table=>table.owners[0]).sort(),[...declared.visible].sort(),`${pageId}/${state}: declared business-table surfaces differ from actual visible DOM`);
  console.log(`${runtime?'REGISTRY_RUNTIME_SURFACE':'REGISTRY_SURFACE_STATE'}=${pageId}:${state}`);
  return observed;
}

export async function assertRegistryDataCoverage(page,label){
  const typed=Object.values(roleSelectors).map(selector=>selector.split(' ').at(-1)).filter(selector=>selector!=='td').join(',');
  const uncovered=await page.evaluate(typed=>{
    const result=[];
    for(const body of document.querySelectorAll('.registry-ui-data tbody')){
      const walker=document.createTreeWalker(body,NodeFilter.SHOW_TEXT);
      for(let node=walker.nextNode();node;node=walker.nextNode()){
        if(!node.textContent.trim())continue;
        const element=node.parentElement,range=document.createRange();range.selectNodeContents(node);
        if(!range.getBoundingClientRect().width||!range.getBoundingClientRect().height||getComputedStyle(element).visibility==='hidden')continue;
        // Actions retain their independently approved component contract.
        if(element.closest('button,input,select,textarea,[role=button],[role=menu],.btn,.lab-reference-list,.lab-reference-trigger'))continue;
        if(!element.closest(typed))result.push(node.textContent.trim().slice(0,100));
      }
    }
    return result;
  },typed);
  assert.deepEqual(uncovered,[],`${label}: untyped visible table data; use an approved typography role (a color-only class is insufficient)`);
}

export async function assertRegistryColumnGeometry(page,label){
  const columns=await page.evaluate(()=>[...document.querySelectorAll('.registry-ui-data')].flatMap(table=>{
    if(table.classList.contains('registry-ui-data--cards')||getComputedStyle(table.tBodies[0]?.rows[0]).display==='grid')return [];
    const idWidth=parseFloat(getComputedStyle(table).getPropertyValue('--registry-id-width'))||64;
    return [...table.querySelectorAll('tbody .registry-ui-id,tbody .registry-ui-trip')].flatMap(element=>{
      const cell=element.closest('td'),rect=cell?.getBoundingClientRect();
      if(!rect?.width||!rect.height)return [];
      const role=element.classList.contains('registry-ui-id')?'id':'trip';
      return [{role,width:rect.width,expected:role==='id'?idWidth:240}];
    });
  }));
  for(const column of columns)assert.ok(Math.abs(column.width-column.expected)<0.1,`${label}: canonical ${column.role} column width ${column.width} != ${column.expected}`);
}

// Required coverage is evaluated against actual visible data, never class-name
// strings in a PHP source. Test fixtures must expose every applicable role.
export async function assertRegistryDataRoles(page, requiredRoles, label) {
  const actual=await page.evaluate(selectors=>{
    const visible=element=>{const rect=element.getBoundingClientRect(),css=getComputedStyle(element);return !!(rect.width&&rect.height)&&css.visibility!=='hidden'};
    const read=element=>{const css=getComputedStyle(element);return Object.fromEntries(['fontFamily','fontSize','fontWeight','lineHeight','color'].map(key=>[key,css[key]]))};
    const probe=element=>({style:read(element),sample:element.textContent.trim().slice(0,60),due:element.closest('.registry-ui-due')?.className||'',status:element.closest('.registry-ui-status')?.dataset.status||'',statusClass:element.closest('.registry-ui-status')?.className||'',semantic:element.closest('.registry-ui-error,.registry-ui-success')?.className||'',warning:!!element.closest('tr.registry-ui-warning'),rowTone:element.closest('tr')?.className||'',vehicleMetric:!!element.closest('.route-vehicle-metric'),financeGroup:!!element.closest('.rp-v010-mobile-group'),departuresReason:!!element.closest('.waste-finance-reason'),departuresMobileLabel:!!element.closest('.waste-mobile-label'),moneyCell:!!element.closest('td.registry-ui-money')});
    const typed=Object.values(selectors).filter(selector=>!selector.includes(' ')).join(',');
    return Object.fromEntries(Object.entries(selectors).map(([role,selector])=>[role,[...document.querySelectorAll('.registry-ui-data '+selector)].filter(visible).map(element=>{
      const texts=[],walker=document.createTreeWalker(element,NodeFilter.SHOW_TEXT);
      for(let node=walker.nextNode();node;node=walker.nextNode()){
        if(!node.textContent.trim()||!visible(node.parentElement)||node.parentElement.closest('[aria-hidden="true"]'))continue;
        // Nested typed roles own their typography independently.
        const owner=node.parentElement.closest(typed);
        if(owner&&owner!==element)continue;
        texts.push(probe(node.parentElement));
      }
      return {...probe(element),texts};
    })]));
  },roleSelectors);
  const defaults={primary:['12px','500','16.8px','#131210'],name:['12px','600','16.8px','#131210'],secondary:['11px','500','15.4px','#78726a'],plannedDate:['12px','500','16.8px','#78726a'],money:['12px','600','16.8px','#131210'],id:['12px','700','16.8px','#131210'],trip:['12px','500','16.8px','#131210'],missing:['12px','500','16.8px','#817d75'],due:['12px','500','16.8px','#131210'],status:['12px','500','16.8px','#131210'],total:['11px','700','15.4px','#131210'],reason:['11px','500','15.4px','#675f55']};
  const rgb=hex=>`rgb(${hex.slice(1).match(/../g).map(value=>parseInt(value,16)).join(', ')})`;
  for(const role of requiredRoles){
    assert.ok(role in roleSelectors,`${label}: unknown data role ${role}`);
    assert.ok(actual[role].length,`${label}: no visible coverage for required ${role}`);
  }
  // Verify every visible typed role, including ones omitted from a declaration.
  for(const [role,items] of Object.entries(actual))for(const container of items)for(const item of [container,...container.texts]){
    const expected=[...defaults[role]];
    if(role==='primary'&&item.due.includes('registry-ui-due--overdue')){expected[1]='700';expected[3]='#872822'}
    if(['due','secondary'].includes(role)&&item.due.includes('registry-ui-due--overdue'))expected[3]='#872822';
    if(['primary','secondary','due'].includes(role)&&item.due.includes('registry-ui-due--complete'))expected[3]='#1f6b43';
    if(role==='secondary'&&(item.warning||item.rowTone.includes('registry-ui-soft-')||item.vehicleMetric||item.due.includes('registry-ui-due--today')))expected[3]='#131210';
    if(role==='secondary'&&item.financeGroup){expected[0]='12px';expected[2]='15px';expected[3]='#131210'}
    if(role==='secondary'&&item.departuresMobileLabel){expected[0]='12px';expected[1]=item.moneyCell?'600':'500';expected[2]='16.8px';expected[3]='#4c4840'}
    if(role==='reason'&&item.departuresReason)expected[3]='#78726a';
    if(role==='status')expected[3]=({planned_route:'#9c27b0',executor_assigned:'#17a2b8',in_progress:'#1f6b43',completed:'#6c757d'})[item.status]||expected[3];
    if(role==='status'&&item.statusClass.includes('route-status--neutral'))expected[3]='#4c4840';
    if(item.semantic.includes('registry-ui-error'))expected[3]='#872822';
    if(item.semantic.includes('registry-ui-success'))expected[3]='#1f6b43';
    for(const style of [item.style]){
      assert.equal(firstFamily(style.fontFamily),'IBM Plex Sans',`${label}: ${role} actual font family`);
      assert.deepEqual([style.fontSize,style.fontWeight,style.lineHeight,style.color],[...expected.slice(0,3),rgb(expected[3])],`${label}: canonical ${role} typography/color ${JSON.stringify(item.sample)}`);
    }
  }
}

export async function assertRegistryDataContract(page, pageId, {state='initial'}={}) {
  const definition=catalog.pages.find(item=>item.id===pageId);
  assert.equal(definition?.adaptation_status,'current_20261018',`${pageId}: data contract requires current registered page`);
  assert.ok(Array.isArray(definition.data_roles)&&definition.data_roles.length,`${pageId}: explicit data_roles required`);
  await assertRegistryTableState(page,pageId,state);
  await assertRegistryDataRoles(page,definition.data_roles,pageId);
  await assertRegistryDataCoverage(page,pageId);
  await assertRegistryColumnGeometry(page,pageId);
}

export async function assertRegistryVisualContract(page, label) {
  const pageId=label.split(/\s+/)[0];
  const definition=catalog.pages.find(item=>item.id===pageId);
  if(page.url()==='about:srcdoc'){
    assert.ok(pageId==='gallery'||pageId==='audit','specimen frame requires gallery/audit context');
    const scene=await page.locator('[data-gallery-view]:visible').getAttribute('data-gallery-view');
    const registryId={executors:'route-executors',finance:'finance-received-paid',trips:'trips-departures',documents:'logistics-closing-documents'}[scene];
    assert.ok(registryId,'registered gallery scene required');
    await assertRegistryTablerIcon(page,registryId);
  }else{
    assert.equal(definition?.family,'registry',`${label}: registered registry page required by common visual contract`);
    {
      const actualPath=new URL(page.url()).pathname;
      assert.ok([definition.route,definition.route.replace(/^\/erpv2/,'')].includes(actualPath),`${label}: browser route must match registered page before icon acceptance`);
      await assertRegistryTablerIcon(page,pageId);
    }
  }
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
      return Object.fromEntries(['fontFamily','fontSize','fontWeight','lineHeight','color','backgroundColor','borderTopColor','borderTopWidth','borderRadius','minHeight','paddingTop','paddingRight','height'].map(key => [key, css[key]]));
    };
    const fontLoaded = [400,500,600,700].every(weight => [...document.fonts].some(face => face.family.replace(/["']/g,'')==='IBM Plex Sans' && face.weight===String(weight) && face.status==='loaded'));
    return {toolbar:style('.registry-ui-toolbar'),search:style('.registry-ui-search'),title:style('.registry-ui-head .page-title'),summary:style('.registry-ui-head .page-summary'),head:style('.registry-ui-head'),fontLoaded};
  });
  assert.equal(appearance.fontLoaded, true, `${label}: real approved font is loaded`);
  for(const role of ['toolbar','search','title','summary','head'])assert.equal(firstFamily(appearance[role]?.fontFamily||''),'IBM Plex Sans',`${label}: actual ${role} font family`);
  assert.equal(appearance.toolbar.backgroundColor, 'rgb(247, 245, 240)', `${label}: toolbar background`);
  assert.equal(appearance.toolbar.minHeight, '49px', `${label}: toolbar min height`);
  assert.deepEqual([appearance.toolbar.paddingTop,appearance.toolbar.paddingRight], ['8px','12px'], `${label}: toolbar padding`);
  assert.deepEqual([appearance.title?.fontSize,appearance.title?.fontWeight,appearance.title?.lineHeight,appearance.title?.color], ['19px','700','22px','rgb(19, 18, 16)'], `${label}: title typography`);
  assert.deepEqual([appearance.summary?.fontSize,appearance.summary?.fontWeight,appearance.summary?.lineHeight], ['12px','500','15px'], `${label}: summary typography`);
  assert.deepEqual([appearance.head?.borderTopColor,appearance.head?.borderTopWidth], ['rgb(168, 161, 150)','1px'], `${label}: header frame`);
  assert.deepEqual([appearance.search?.height,appearance.search?.fontSize,appearance.search?.fontWeight,appearance.search?.borderRadius,appearance.search?.backgroundColor], ['32px','12px','500','2px','rgb(255, 253, 249)'], `${label}: search appearance`);
  await assertRegistryDataRoles(page,[],label);
  if(definition)console.log(`REGISTRY_VISUAL_PAGE=${pageId}`);
  return metrics;
}
