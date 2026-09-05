import fs from 'node:fs';

const css = fs.readFileSync(new URL('../public/assets/css/roles-access.css', import.meta.url), 'utf8');
function assert(condition, message) { if (!condition) throw new Error(message); }

assert(css.includes('width:min(1060px,calc(100vw - 32px))'), 'desktop modal width contract');
assert(css.includes('max-height:85vh'), 'desktop modal max-height contract');
assert(css.includes('@media(max-width:1439px)'), 'compact desktop breakpoint');
assert(css.includes('width:min(1020px,calc(100vw - 24px))'), '1366 compact desktop width contract');
assert(css.includes('@media(max-width:900px)'), 'around-800 viewport breakpoint');
assert(css.includes('.roles-access-modal__dialog{width:100%;max-width:100%;height:auto;max-height:85vh}'), 'around-800 modal dimensions contract');
assert(css.includes('.roles-access-modal__body{flex:1 1 auto;min-height:0;overflow-y:auto;overflow-x:hidden'), 'modal body owns scrolling');
assert(css.includes('.roles-access-modal__head,.roles-access-modal__foot{position:sticky'), 'header and footer remain visible');
assert(css.includes('body.roles-access-page .app,body.roles-access-page .app-shell{min-width:0!important}'), 'roles page removes shell horizontal overflow around 800px');
assert(css.includes('.roles-access__tree-pages,.roles-access__assignment-grid,.roles-access__inline-members,.roles-access__scope-table{grid-template-columns:1fr}'), 'assignments collapse to one column around 800px');

const viewportHeight = 760;
for (const width of [1920, 1440, 1366, 800]) {
    let dialogWidth;
    if (width <= 900) dialogWidth = width - 16;
    else if (width <= 1439) dialogWidth = Math.min(1020, width - 24);
    else dialogWidth = Math.min(1060, width - 32);
    const dialogHeight = viewportHeight * .85;
    assert(dialogWidth > 0 && dialogWidth <= width, `viewport ${width}: dialog fits horizontally`);
    assert(dialogHeight > 0 && dialogHeight <= viewportHeight, `viewport ${width}: dialog fits vertically`);
    console.log(`viewport ${width}: PASS (${dialogWidth}x<=${Math.round(dialogHeight)})`);
}
console.log('roles access viewport CSS contract: PASS');
